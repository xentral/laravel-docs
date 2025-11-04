<?php declare(strict_types=1);

namespace Xentral\LaravelDocs;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

class MkDocsGenerator
{
    private array $validationWarnings = [];

    private readonly MarkdownValidator $validator;

    public function __construct(private readonly Filesystem $filesystem)
    {
        $this->validator = new MarkdownValidator;
    }

    public function generate(array $documentationNodes, string $docsBaseDir): void
    {
        $docsOutputDir = $docsBaseDir.'/generated';

        // Parse static content files from configured paths
        $staticContentNodes = $this->parseStaticContentFiles($docsBaseDir);

        // Check for error-level validation warnings and fail early
        $errors = array_filter($this->validationWarnings, fn ($w) => $w['severity'] === 'error');
        if (! empty($errors)) {
            echo $this->validator->formatWarnings($this->validationWarnings);
            throw new \RuntimeException(
                sprintf(
                    'Documentation generation failed due to %d validation error(s). Please fix the errors above.',
                    count($errors)
                )
            );
        }

        // Merge documentation nodes with static content nodes
        $allNodes = array_merge($documentationNodes, $staticContentNodes);

        // Two-pass processing for hierarchical navigation
        // Pass 1: Process standalone nodes (no @navparent)
        $standaloneNodes = [];
        $childNodes = [];

        foreach ($allNodes as $node) {
            if (empty($node['navParent'])) {
                $standaloneNodes[] = $node;
            } else {
                $childNodes[] = $node;
            }
        }

        // Pass 2: Resolve parent-child relationships and build hierarchical structure
        $processedNodes = $this->resolveHierarchicalNavigation($standaloneNodes, $childNodes);

        // Build documentation registry and maps with processed nodes
        $registry = $this->buildRegistry($processedNodes);
        $navPathMap = $this->buildNavPathMap($processedNodes);
        $navIdMap = $this->buildNavIdMap($processedNodes);
        $usedBy = $this->buildUsedByMap($processedNodes);

        // Build reverse registry for file path -> owner lookup (needed for navigation hierarchy)
        $reverseRegistry = $this->buildReverseRegistry($registry);

        // Build cross-reference maps for bi-directional linking
        $referencedBy = $this->buildReferencedByMap($processedNodes, $registry, $navPathMap, $navIdMap);

        // Generate the document tree
        $docTree = $this->generateDocTree($processedNodes, $registry, $navPathMap, $navIdMap, $usedBy, $referencedBy);

        // Prepare output directory
        $this->filesystem->deleteDirectory($docsOutputDir);
        $this->filesystem->makeDirectory($docsOutputDir, recursive: true);

        // Create welcome page
        $welcomeContent = "# Welcome\n\nThis is the automatically generated functional documentation for the project. \n\nUse the navigation on the left to explore the documented processes.";
        $this->filesystem->put($docsOutputDir.'/index.md', $welcomeContent);

        // Generate files
        $this->generateFiles($docTree, $docsOutputDir);

        // Generate navigation structure with title mapping
        $navStructure = $this->generateNavStructure($docTree, '', $navPathMap, $processedNodes, $reverseRegistry);
        array_unshift($navStructure, ['Home' => 'index.md']);

        // Generate config
        $config = config('docs.config', []);
        $config['nav'] = $navStructure;
        $this->dumpAsYaml($config, $docsBaseDir.'/mkdocs.yml');

        // Display validation warnings if any were found
        if (! empty($this->validationWarnings)) {
            echo $this->validator->formatWarnings($this->validationWarnings);
        }
    }

    private function parseStaticContentFiles(string $docsBaseDir): array
    {
        $staticContentNodes = [];
        $staticContentConfig = config('docs.static_content', []);

        foreach ($staticContentConfig as $contentType => $config) {
            $contentPath = $config['path'] ?? null;
            $navPrefix = $config['nav_prefix'] ?? ucfirst((string) $contentType);

            if (! $contentPath || ! $this->filesystem->exists($contentPath)) {
                continue;
            }

            $files = $this->filesystem->allFiles($contentPath);

            foreach ($files as $file) {
                if ($file->getExtension() === 'md') {
                    $staticContentNodes[] = $this->parseStaticContentFile(
                        $file->getRealPath(),
                        $contentPath,
                        $contentType,
                        $navPrefix
                    );
                }
            }
        }

        return $staticContentNodes;
    }

    private function resolveHierarchicalNavigation(array $standaloneNodes, array $childNodes): array
    {
        $processedNodes = $standaloneNodes;

        // Process child nodes and track parent-child relationships
        foreach ($childNodes as $childNode) {
            $parentRef = $childNode['navParent'];
            $parentNode = $this->findParentNode($parentRef, $standaloneNodes);

            if ($parentNode) {
                // Store parent information for navigation sorting
                // Keep the original navPath, don't modify it
                $childNode['parentNavId'] = $parentNode['navId'] ?? $parentNode['owner'];
                $childNode['parentNavPath'] = $parentNode['navPath'];
                $childNode['isChildPage'] = true;

                $processedNodes[] = $childNode;
            } else {
                // Parent not found - treat as standalone but log the issue
                // For now, just add to processed nodes with original nav path
                $processedNodes[] = $childNode;
            }
        }

        return $processedNodes;
    }

    private function findParentNode(string $parentRef, array $nodes): ?array
    {
        foreach ($nodes as $node) {
            // Try different resolution strategies
            // 1. Check for exact navId match
            if (isset($node['navId']) && $node['navId'] === $parentRef) {
                return $node;
            }

            // 2. Check for display title match (fallback)
            if (isset($node['displayTitle']) && strtolower($node['displayTitle']) === strtolower($parentRef)) {
                return $node;
            }

            // 3. Check owner/class name match (for PHPDoc content)
            if ($node['owner'] === $parentRef) {
                return $node;
            }

            // 4. Check nav path last segment match (fallback)
            $navPathSegments = array_map(trim(...), explode('/', (string) $node['navPath']));
            $lastSegment = array_pop($navPathSegments);
            if (strtolower($lastSegment) === strtolower($parentRef)) {
                return $node;
            }
        }

        return null;
    }

    private function parseStaticContentFile(string $filePath, string $contentBasePath, string $contentType, string $navPrefix): array
    {
        $content = $this->filesystem->get($filePath);
        $relativePath = str_replace($contentBasePath.'/', '', $filePath);

        // Validate markdown content
        $warnings = $this->validator->validate($content, $filePath);
        if (! empty($warnings)) {
            $this->validationWarnings = array_merge($this->validationWarnings, $warnings);
        }

        // Extract @nav lines and clean content
        [$navPath, $cleanedContent, $navId, $navParent, $uses, $links] = $this->extractNavFromContent($content, $relativePath, $navPrefix);

        // Fix PHP code blocks for proper syntax highlighting
        $cleanedContent = $this->fixPhpCodeBlocks($cleanedContent);

        // Always try to extract display title from markdown content first
        $lines = explode("\n", $cleanedContent);
        $displayTitle = $this->extractTitleFromContent($lines);

        // If no markdown title found, fall back to navigation path (last segment)
        if (! $displayTitle) {
            $pathSegments = array_map(trim(...), explode('/', (string) $navPath));
            $displayTitle = array_pop($pathSegments);
        }

        return [
            'owner' => $contentType.':'.$relativePath,
            'navPath' => $navPath,
            'displayTitle' => $displayTitle, // Store display title directly
            'description' => $cleanedContent,
            'uses' => $uses,
            'links' => $links,
            'type' => 'static_content',
            'content_type' => $contentType,
            'navId' => $navId, // Custom identifier for parent referencing
            'navParent' => $navParent, // Reference to parent node
        ];
    }

    private function extractNavFromContent(string $content, string $relativePath, string $navPrefix): array
    {
        $lines = explode("\n", $content);
        $navPath = null;
        $navId = null;
        $navParent = null;
        $uses = [];
        $links = [];
        $cleanedLines = [];
        $inFrontMatter = false;
        $frontMatterEnded = false;
        $navFound = false;
        $navIdFound = false;
        $navParentFound = false;

        foreach ($lines as $lineIndex => $line) {
            $trimmedLine = trim($line);

            // Handle YAML frontmatter (only if --- is at the beginning of the file)
            if ($trimmedLine === '---' && ! $frontMatterEnded) {
                if (! $inFrontMatter) {
                    // Only treat as frontmatter if this is the first line or only whitespace before
                    $isFirstContent = true;
                    for ($i = 0; $i < $lineIndex; $i++) {
                        if (trim($lines[$i]) !== '') {
                            $isFirstContent = false;
                            break;
                        }
                    }

                    if ($isFirstContent) {
                        $inFrontMatter = true;

                        continue;
                    }
                    // Otherwise, it's just a horizontal rule, keep it
                } else {
                    $inFrontMatter = false;
                    $frontMatterEnded = true;

                    continue;
                }
            }

            // Skip frontmatter content
            if ($inFrontMatter) {
                continue;
            }

            // Check for @navid lines (only at beginning of trimmed line, only first occurrence)
            if (! $navIdFound && str_starts_with($trimmedLine, '@navid ')) {
                $navId = trim(substr($trimmedLine, strlen('@navid')));
                $navIdFound = true;

                continue; // Exclude @navid line from content
            }

            // Check for @navparent lines (only at beginning of trimmed line, only first occurrence)
            if (! $navParentFound && str_starts_with($trimmedLine, '@navparent ')) {
                $navParent = trim(substr($trimmedLine, strlen('@navparent')));
                $navParentFound = true;

                continue; // Exclude @navparent line from content
            }

            // Check for @nav lines (only at beginning of trimmed line, only first occurrence)
            if (! $navFound && str_starts_with($trimmedLine, '@nav ')) {
                $navPath = trim(substr($trimmedLine, strlen('@nav')));
                $navFound = true;

                continue; // Exclude @nav line from content
            }

            // Check for @uses lines
            if (str_starts_with($trimmedLine, '@uses ')) {
                $uses[] = trim(substr($trimmedLine, strlen('@uses')));

                continue; // Exclude @uses line from content
            }

            // Check for @link lines
            if (str_starts_with($trimmedLine, '@link ')) {
                $links[] = trim(substr($trimmedLine, strlen('@link')));

                continue; // Exclude @link line from content
            }

            // Check for @links lines
            if (str_starts_with($trimmedLine, '@links ')) {
                $links[] = trim(substr($trimmedLine, strlen('@links')));

                continue; // Exclude @links line from content
            }

            // Collect all lines except navigation directives, uses, links, and frontmatter
            $cleanedLines[] = $line;
        }

        // If no @nav found, generate default nav path from file structure
        if ($navPath === null) {
            $pathParts = explode('/', str_replace('.md', '', $relativePath));

            // Extract title from markdown content if available
            $title = $this->extractTitleFromContent($cleanedLines);

            if ($title) {
                // Use extracted title for the page name
                $pathParts[count($pathParts) - 1] = $title;
            } else {
                // Fallback: use filename with underscores replaced
                $pathParts[count($pathParts) - 1] = ucwords(str_replace('_', ' ', $pathParts[count($pathParts) - 1]));
            }

            // Process directory names (all but the last part)
            for ($i = 0; $i < count($pathParts) - 1; $i++) {
                $pathParts[$i] = ucwords(str_replace('_', ' ', $pathParts[$i]));
            }

            $navPath = $navPrefix.' / '.implode(' / ', $pathParts);
        }

        return [$navPath, implode("\n", $cleanedLines), $navId, $navParent, $uses, $links];
    }

    private function extractTitleFromContent(array $lines): ?string
    {
        foreach ($lines as $line) {
            $trimmedLine = trim((string) $line);

            // Skip empty lines
            if (empty($trimmedLine)) {
                continue;
            }

            // Check if this is a markdown title (starts with # )
            if (preg_match('/^#\s+(.+)$/', $trimmedLine, $matches)) {
                return trim($matches[1]);
            }

            // If we encounter any non-empty, non-title content, stop looking
            // (title should be at the beginning of the content)
            break;
        }

        return null;
    }

    private function buildRegistry(array $documentationNodes): array
    {
        $registry = [];
        foreach ($documentationNodes as $node) {
            // Build path based on where files are actually placed in the generated directory
            // Both static and PHPDoc content use the navPath structure for file placement
            $pathSegments = array_map(trim(...), explode('/', (string) $node['navPath']));
            $pageTitle = array_pop($pathSegments);

            if (isset($node['type']) && $node['type'] === 'static_content') {
                // For static content, preserve original filename from owner
                $ownerParts = explode(':', (string) $node['owner'], 2);
                if (count($ownerParts) === 2) {
                    $fileName = basename($ownerParts[1]); // e.g., "SHADOW_MODE_SPECIFICATION.md"
                    $urlParts = $pathSegments; // Use navPath segments as-is (no slugging for static content dirs)
                    $urlParts[] = $fileName;
                    $registry[$node['owner']] = implode('/', $urlParts);
                }
            } else {
                // For PHPDoc content, preserve directory names (with spaces) but slug the filename
                // This matches how files are actually generated in setInNestedArray()
                $urlParts = $pathSegments; // Keep directory names as-is
                $urlParts[] = $this->slug($pageTitle).'.md'; // Only slug the filename
                $registry[$node['owner']] = implode('/', $urlParts);
            }
        }

        return $registry;
    }

    private function buildReverseRegistry(array $registry): array
    {
        // Build reverse mapping: file path -> owner
        // This allows us to look up which node generated a specific file path
        $reverseRegistry = [];
        foreach ($registry as $owner => $filePath) {
            $reverseRegistry[$filePath] = $owner;
        }

        return $reverseRegistry;
    }

    private function buildNavPathMap(array $documentationNodes): array
    {
        $navPathMap = [];
        foreach ($documentationNodes as $node) {
            $navPathMap[$node['owner']] = $node['navPath'];
        }

        return $navPathMap;
    }

    private function buildNavIdMap(array $documentationNodes): array
    {
        $navIdMap = [];
        foreach ($documentationNodes as $node) {
            if (! empty($node['navId'])) {
                $navIdMap[$node['navId']] = $node['owner'];
            }
        }

        return $navIdMap;
    }

    private function buildUsedByMap(array $documentationNodes): array
    {
        $usedBy = [];
        foreach ($documentationNodes as $node) {
            foreach ($node['uses'] as $used) {
                $lookupKey = ltrim(trim((string) $used), '\\');
                if (! isset($usedBy[$lookupKey])) {
                    $usedBy[$lookupKey] = [];
                }
                $usedBy[$lookupKey][] = $node['owner'];
            }
        }

        return $usedBy;
    }

    private function buildReferencedByMap(array $documentationNodes, array $registry, array $navPathMap, array $navIdMap): array
    {
        $referencedBy = [];

        // Scan all nodes for cross-references
        foreach ($documentationNodes as $node) {
            $sourceOwner = $node['owner'];
            $content = $node['description'] ?? '';

            // Find all [@ref:...] and [@navid:...] references in this content
            $pattern = '/(?:\[([^\]]+)\]\()?@(ref|navid):([^)\]\s]+)[\])]?/';
            preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $refType = $match[2]; // 'ref' or 'navid'
                $refTarget = $match[3]; // The actual reference target

                // Resolve the target owner
                $targetOwner = null;
                if ($refType === 'ref') {
                    $cleanTarget = ltrim($refTarget, '\\');
                    if (isset($registry[$cleanTarget])) {
                        $targetOwner = $cleanTarget;
                    }
                } elseif ($refType === 'navid') {
                    if (isset($navIdMap[$refTarget])) {
                        $targetOwner = $navIdMap[$refTarget];
                    }
                }

                // Track the reference
                if ($targetOwner) {
                    if (! isset($referencedBy[$targetOwner])) {
                        $referencedBy[$targetOwner] = [];
                    }
                    if (! in_array($sourceOwner, $referencedBy[$targetOwner])) {
                        $referencedBy[$targetOwner][] = $sourceOwner;
                    }
                }
            }
        }

        return $referencedBy;
    }

    private function generateDocTree(array $documentationNodes, array $registry, array $navPathMap, array $navIdMap, array $usedBy, array $referencedBy): array
    {
        $docTree = [];
        $pathRegistry = [];

        foreach ($documentationNodes as $node) {
            $pathSegments = array_map(trim(...), explode('/', (string) $node['navPath']));
            $originalPageTitle = array_pop($pathSegments);
            $pageTitle = $originalPageTitle;

            // For static content, preserve everything exactly as-is
            if (isset($node['type']) && $node['type'] === 'static_content') {
                // Extract original filename from the owner (format: "contentType:relative/path.md")
                $ownerParts = explode(':', (string) $node['owner'], 2);
                if (count($ownerParts) === 2) {
                    $originalPath = $ownerParts[1];
                    $pageFileName = basename($originalPath); // Keep original filename exactly
                } else {
                    $pageFileName = $originalPageTitle.'.md'; // Fallback
                }
            } else {
                // For PHPDoc content, use existing conflict resolution with slugging
                $baseFileName = $this->slug($pageTitle);
                $pathForConflictCheck = implode('/', array_map([$this, 'slug'], $pathSegments)).'/'.$baseFileName.'.md';

                // Determine the final page filename and title based on conflicts
                [$pageFileName, $pageTitle] = $this->resolveFileNameConflict(
                    $pathRegistry,
                    $pathForConflictCheck,
                    $baseFileName,
                    $pageTitle,
                    $node
                );
            }

            // Generate the markdown content
            $markdownContent = $this->generateMarkdownContent($node, $pageTitle, $registry, $navPathMap, $navIdMap, $usedBy, $referencedBy);

            // Build the path in the document tree
            $docTree = $this->addToDocTree($docTree, $pathSegments, $originalPageTitle, $pageFileName, $markdownContent);
        }

        return $docTree;
    }

    private function resolveFileNameConflict(
        array &$pathRegistry,
        string $pathForConflictCheck,
        string $baseFileName,
        string $pageTitle,
        array $node
    ): array {
        if (isset($pathRegistry[$pathForConflictCheck])) {
            $pathRegistry[$pathForConflictCheck]['count']++;
            $count = $pathRegistry[$pathForConflictCheck]['count'];
            $updatedPageTitle = $pageTitle." ({$count})"; // Update display title
            $updatedFileName = $baseFileName."-({$count}).md"; // Update file name

            $originalNodeInfo = $pathRegistry[$pathForConflictCheck]['nodes'][0];
            // Handle conflict information - would need a logger here

            $pathRegistry[$pathForConflictCheck]['nodes'][] = $node;

            return [$updatedFileName, $updatedPageTitle];
        } else {
            $pathRegistry[$pathForConflictCheck] = [
                'count' => 1,
                'nodes' => [$node],
            ];

            return [$baseFileName.'.md', $pageTitle];
        }
    }

    private function addToDocTree(array $docTree, array $pathSegments, string $originalPageTitle, string $pageFileName, string $markdownContent): array
    {
        // Create a copy to avoid modifying the original
        $result = $docTree;
        $path = [];

        // Build the full path to the target location
        foreach ($pathSegments as $segment) {
            $path[] = $segment;
        }

        // Update the tree with the new content
        $result = $this->setInNestedArray($result, $path, $originalPageTitle, $pageFileName, $markdownContent);

        return $result;
    }

    private function setInNestedArray(array $array, array $path, string $originalPageTitle, string $pageFileName, string $markdownContent): array
    {
        if (empty($path)) {
            // We've reached the target level, add the content here
            if (isset($array[$originalPageTitle]) && is_array($array[$originalPageTitle])) {
                // This is a directory, so the original becomes the index
                if ($pageFileName === $this->slug($originalPageTitle).'.md') {
                    $array[$originalPageTitle]['index.md'] = $markdownContent;
                } else {
                    $array[$pageFileName] = $markdownContent;
                }
            } else {
                $array[$pageFileName] = $markdownContent;
            }

            return $array;
        }

        $segment = array_shift($path);
        $fileKey = $this->slug($segment).'.md';

        // Initialize segment if it doesn't exist
        if (! isset($array[$segment])) {
            $array[$segment] = [];
        } elseif (isset($array[$fileKey]) && is_string($array[$fileKey])) {
            // If we have a file with the same name as a directory we need to create
            $fileContent = $array[$fileKey];
            unset($array[$fileKey]);
            $array[$segment] = ['index.md' => $fileContent];
        }

        // Recurse into the next level
        $array[$segment] = $this->setInNestedArray($array[$segment], $path, $originalPageTitle, $pageFileName, $markdownContent);

        return $array;
    }

    private function generateMarkdownContent(array $node, string $pageTitle, array $registry, array $navPathMap, array $navIdMap, array $usedBy, array $referencedBy): string
    {
        // Handle static content nodes differently
        if (isset($node['type']) && $node['type'] === 'static_content') {
            return $this->generateStaticContent($node, $pageTitle, $registry, $navPathMap, $navIdMap, $usedBy, $referencedBy);
        }

        $markdownContent = "# {$pageTitle}\n\n";
        $markdownContent .= "Source: `{$node['owner']}`\n{:.page-subtitle}\n\n";

        // Process inline references in the description
        $processedDescription = $this->processInlineReferences($node['description'], $registry, $navPathMap, $navIdMap, $node['owner']);
        $markdownContent .= $processedDescription;

        // Add "Building Blocks Used" section
        if (! empty($node['uses'])) {
            $markdownContent .= $this->generateUsedComponentsSection($node, $registry, $navPathMap);
        }

        // Add "Used By Building Blocks" section
        $ownerKey = $node['owner'];
        if (isset($usedBy[$ownerKey])) {
            $markdownContent .= $this->generateUsedBySection($ownerKey, $usedBy, $registry, $navPathMap);
        }

        // Add "Referenced by" section
        if (isset($referencedBy[$ownerKey])) {
            $markdownContent .= $this->generateReferencedBySection($ownerKey, $referencedBy, $registry, $navPathMap);
        }

        // Add "Further reading" section
        if (! empty($node['links'])) {
            $markdownContent .= $this->generateLinksSection($node['links']);
        }

        return $markdownContent;
    }

    private function generateStaticContent(array $node, string $pageTitle, array $registry = [], array $navPathMap = [], array $navIdMap = [], array $usedBy = [], array $referencedBy = []): string
    {
        // For static content, we don't add the title since it might already be in the content
        // We also don't add the source subtitle
        $content = $node['description'];

        // If the content doesn't start with a title, add one
        if (! preg_match('/^#\s+/', trim((string) $content))) {
            $content = "# {$pageTitle}\n\n".$content;
        }

        // Process inline references in the content
        $content = $this->processInlineReferences($content, $registry, $navPathMap, $navIdMap, $node['owner']);

        // Add "Building Blocks Used" section if uses are defined
        if (! empty($node['uses'])) {
            $content .= $this->generateUsedComponentsSection($node, $registry, $navPathMap);
        }

        // Add "Used By Building Blocks" section
        $ownerKey = $node['owner'];
        if (isset($usedBy[$ownerKey])) {
            $content .= $this->generateUsedBySection($ownerKey, $usedBy, $registry, $navPathMap);
        }

        // Add "Referenced by" section
        if (isset($referencedBy[$ownerKey])) {
            $content .= $this->generateReferencedBySection($ownerKey, $referencedBy, $registry, $navPathMap);
        }

        // Add "Further reading" section if links are defined
        if (! empty($node['links'])) {
            $content .= $this->generateLinksSection($node['links']);
        }

        return $content;
    }

    private function processMermaidReferences(string $content, array $registry, array $navPathMap, array $navIdMap, string $sourceOwner): string
    {
        // Process @navid references within Mermaid code blocks
        // Pattern to match Mermaid code blocks: ```mermaid ... ```
        return preg_replace_callback(
            '/^```mermaid\n((?:(?!^```)[\s\S])*?)^```/m',
            function ($matches) use ($registry, $navPathMap, $navIdMap, $sourceOwner) {
                $mermaidContent = $matches[1];

                // Process click events with @navid references within the Mermaid content
                // Pattern: click element "@navid:target" "tooltip" or click element '@navid:target' 'tooltip'
                // Also supports: click element "@navid:target#fragment" "tooltip"
                // Element names can contain letters, numbers, underscores, hyphens, and dots
                // Tooltip is optional
                $clickPattern = '/click\s+([a-zA-Z0-9_.-]+)\s+(["\'])@(ref|navid):([^#"\']+)(?:#([^"\']+))?\2(?:\s+(["\'])([^"\']+)\6)?/';

                $processedMermaidContent = preg_replace_callback(
                    $clickPattern,
                    function ($clickMatches) use ($registry, $navPathMap, $navIdMap, $sourceOwner) {
                        $element = $clickMatches[1]; // The Mermaid element to click
                        $refType = $clickMatches[3]; // 'ref' or 'navid' (adjusted index due to quote capture)
                        $refTarget = $clickMatches[4]; // The reference target (adjusted index)
                        $fragment = $clickMatches[5] ?? null; // Optional fragment (adjusted index)
                        $tooltip = $clickMatches[7] ?? null; // The tooltip text (optional, adjusted index)

                        // Resolve the reference
                        $resolvedLink = $this->resolveReference($refType, $refTarget, $registry, $navPathMap, $navIdMap, $sourceOwner);

                        if ($resolvedLink === null) {
                            // Reference couldn't be resolved - throw build error with helpful context
                            $sourceInfo = $sourceOwner ? " in {$sourceOwner}" : '';
                            $fragmentInfo = $fragment ? "#{$fragment}" : '';

                            throw new \RuntimeException("Broken Mermaid reference: @{$refType}:{$refTarget}{$fragmentInfo} in Mermaid diagram{$sourceInfo}");
                        }

                        $linkUrl = $resolvedLink['url'];

                        // Append fragment identifier if provided
                        if ($fragment) {
                            $linkUrl .= '#'.$fragment;
                        }

                        // Return the processed click event with resolved URL
                        // Include tooltip only if it was provided
                        if ($tooltip !== null) {
                            return "click {$element} \"{$linkUrl}\" \"{$tooltip}\"";
                        } else {
                            return "click {$element} \"{$linkUrl}\"";
                        }
                    },
                    $mermaidContent
                );

                // Return the processed Mermaid block
                return "```mermaid\n{$processedMermaidContent}```";
            },
            $content
        );
    }

    private function processInlineReferences(string $content, array $registry, array $navPathMap, array $navIdMap, string $sourceOwner): string
    {
        // First, process Mermaid code blocks to handle @navid references within them
        $content = $this->processMermaidReferences($content, $registry, $navPathMap, $navIdMap, $sourceOwner);

        // Process [@ref:...] and [@navid:...] syntax with optional fragment support
        // Pattern explanation:
        // \[                     - Opening bracket (always consumed)
        // (?:([^\]]+)\]\()?      - Optional custom link text in [text]( format
        // @(ref|navid):          - The @ref: or @navid: syntax
        // ([^#)\]\s]+)           - The reference target (no #, spaces, closing parens, or brackets)
        // (?:#([^)\]\s]+))?      - Optional fragment identifier after #
        // [\])]                  - Closing bracket or paren

        $pattern = '/\[(?:([^\]]+)\]\()?@(ref|navid):([^#)\]\s]+)(?:#([^)\]\s]+))?[\])]/';

        return preg_replace_callback($pattern, function ($matches) use ($registry, $navPathMap, $navIdMap, $sourceOwner) {
            $customText = $matches[1] !== '' ? $matches[1] : null; // Custom link text if provided
            $refType = $matches[2]; // 'ref' or 'navid'
            $refTarget = $matches[3]; // The actual reference target
            $fragment = $matches[4] ?? null; // Optional fragment identifier

            // Resolve the reference based on type
            $resolvedLink = $this->resolveReference($refType, $refTarget, $registry, $navPathMap, $navIdMap, $sourceOwner);

            if ($resolvedLink === null) {
                // Reference couldn't be resolved - throw build error with helpful context
                $sourceInfo = $sourceOwner ? " in {$sourceOwner}" : '';
                $fragmentInfo = $fragment ? "#{$fragment}" : '';
                $suggestion = '';

                if ($refType === 'ref') {
                    $suggestion = "\n\nThe class '{$refTarget}' is not documented. To fix this:\n".
                                  "1. Add @docs annotation to the class PHPDoc comment\n".
                                  "2. Re-run docs generation\n".
                                  '3. Or use a code block instead: `'.basename(str_replace('\\', '/', $refTarget)).'`';
                } elseif ($refType === 'navid') {
                    $suggestion = "\n\nThe navigation ID '{$refTarget}' doesn't exist. Check:\n".
                                  "1. @navid annotation exists in target document\n".
                                  "2. No typos in the navigation ID\n".
                                  "3. Or use a code block instead: `{$refTarget}`";
                }

                throw new \RuntimeException("Broken reference: @{$refType}:{$refTarget}{$fragmentInfo}{$sourceInfo}{$suggestion}");
            }

            $linkText = $customText ?: $resolvedLink['title'];
            $linkUrl = $resolvedLink['url'];

            // Append fragment identifier if provided
            if ($fragment) {
                $linkUrl .= '#'.$fragment;
            }

            return "[{$linkText}]({$linkUrl})";
        }, $content);
    }

    private function resolveReference(string $refType, string $refTarget, array $registry, array $navPathMap, array $navIdMap, string $sourceOwner): ?array
    {
        if ($refType === 'ref') {
            // Reference by class/owner
            return $this->resolveRefByOwner($refTarget, $registry, $navPathMap, $sourceOwner);
        } elseif ($refType === 'navid') {
            // Reference by navigation ID
            return $this->resolveRefByNavId($refTarget, $navIdMap, $registry, $navPathMap, $sourceOwner);
        }

        return null;
    }

    private function resolveRefByOwner(string $ownerTarget, array $registry, array $navPathMap, string $sourceOwner): ?array
    {
        // Clean the target (remove leading backslash if present)
        $cleanTarget = ltrim($ownerTarget, '\\');

        // Check if this owner exists in our registry
        if (! isset($registry[$cleanTarget])) {
            return null;
        }

        $targetPath = $registry[$cleanTarget];
        $sourcePath = $registry[$sourceOwner] ?? '';

        // Generate relative URL
        $relativeFilePath = $this->makeRelativePath($targetPath, $sourcePath);
        $relativeUrl = $this->toCleanUrl($relativeFilePath);

        // Generate smart title with fallback chain
        $title = $this->generateSmartTitle($cleanTarget, $navPathMap);

        return [
            'title' => $title,
            'url' => $relativeUrl,
        ];
    }

    private function resolveRefByNavId(string $navIdTarget, array $navIdMap, array $registry, array $navPathMap, string $sourceOwner): ?array
    {
        // Check if this navId exists in our map
        if (! isset($navIdMap[$navIdTarget])) {
            return null;
        }

        // Get the owner for this navId
        $targetOwner = $navIdMap[$navIdTarget];

        // Check if this owner exists in our registry
        if (! isset($registry[$targetOwner])) {
            return null;
        }

        $targetPath = $registry[$targetOwner];
        $sourcePath = $registry[$sourceOwner] ?? '';

        // Generate relative URL
        $relativeFilePath = $this->makeRelativePath($targetPath, $sourcePath);
        $relativeUrl = $this->toCleanUrl($relativeFilePath);

        // Generate smart title with fallback chain
        $title = $this->generateSmartTitle($targetOwner, $navPathMap);

        return [
            'title' => $title,
            'url' => $relativeUrl,
        ];
    }

    private function generateSmartTitle(string $ownerTarget, array $navPathMap, array $allNodes = []): string
    {
        // Smart fallback chain: H1 title → nav path last segment → class name

        // Try to extract H1 title from the target node's content
        $h1Title = $this->extractH1TitleFromTarget($ownerTarget, $allNodes);
        if ($h1Title) {
            return $h1Title;
        }

        // Fallback to nav path last segment
        if (isset($navPathMap[$ownerTarget])) {
            $navPath = $navPathMap[$ownerTarget];
            $pathSegments = array_map(trim(...), explode('/', $navPath));
            $lastSegment = array_pop($pathSegments);
            if ($lastSegment) {
                return $lastSegment;
            }
        }

        // Final fallback to class name (extract class name from full path)
        $classParts = explode('\\', $ownerTarget);

        return array_pop($classParts) ?: $ownerTarget;
    }

    private function extractH1TitleFromTarget(string $ownerTarget, array $allNodes): ?string
    {
        // Find the node with this owner
        foreach ($allNodes as $node) {
            if ($node['owner'] === $ownerTarget) {
                // Extract H1 from the description content
                $content = $node['description'] ?? '';
                $lines = explode("\n", $content);

                foreach ($lines as $line) {
                    $trimmedLine = trim($line);

                    // Skip empty lines
                    if (empty($trimmedLine)) {
                        continue;
                    }

                    // Check if this is a markdown title (starts with # )
                    if (preg_match('/^#\s+(.+)$/', $trimmedLine, $matches)) {
                        return trim($matches[1]);
                    }

                    // If we encounter any non-empty, non-title content, stop looking
                    break;
                }
            }
        }

        return null;
    }

    private function generateReferencedBySection(string $ownerKey, array $referencedBy, array $registry, array $navPathMap): string
    {
        $content = "\n\n## Referenced by\n\n";
        $content .= "This page is referenced by the following pages:\n\n";

        $sourcePath = $registry[$ownerKey] ?? '';

        // Deduplicate and sort references
        $uniqueReferences = array_unique($referencedBy[$ownerKey]);

        // Sort references by navigation path for meaningful ordering
        usort($uniqueReferences, function ($a, $b) use ($navPathMap) {
            $navPathA = $navPathMap[$a] ?? $a;
            $navPathB = $navPathMap[$b] ?? $b;

            return strcasecmp($navPathA, $navPathB);
        });

        foreach ($uniqueReferences as $referencingOwner) {
            $referencingOwnerKey = ltrim(trim((string) $referencingOwner), '\\');
            $referencingNavPath = $navPathMap[$referencingOwnerKey] ?? $referencingOwnerKey;

            if (isset($registry[$referencingOwnerKey])) {
                $targetPath = $registry[$referencingOwnerKey];
                $relativeFilePath = $this->makeRelativePath($targetPath, $sourcePath);
                $relativeUrl = $this->toCleanUrl($relativeFilePath);

                $content .= "* [{$referencingNavPath}]({$relativeUrl})\n";
            } else {
                $content .= "* {$referencingNavPath} (Not documented)\n";
            }
        }

        return $content;
    }

    private function generateUsedComponentsSection(array $node, array $registry, array $navPathMap): string
    {
        $content = "\n\n## Building Blocks Used\n\n";
        $content .= "This functionality is composed of the following reusable components:\n\n";

        $mermaidLinks = [];
        $mermaidContent = "graph LR\n";
        $ownerId = $this->slug($node['owner']);
        $ownerNavPath = $navPathMap[$node['owner']] ?? '';
        $mermaidContent .= "    {$ownerId}[\"{$ownerNavPath}\"];\n";

        $sourcePath = $registry[$node['owner']] ?? '';

        foreach ($node['uses'] as $used) {
            $usedRaw = trim((string) $used);
            $lookupKey = ltrim($usedRaw, '\\');
            $usedId = $this->slug($usedRaw);
            $usedNavPath = $navPathMap[$lookupKey] ?? $usedRaw;

            if (isset($registry[$lookupKey])) {
                $targetPath = $registry[$lookupKey];
                $relativeFilePath = $this->makeRelativePath($targetPath, $sourcePath);
                $relativeUrl = $this->toCleanUrl($relativeFilePath);

                $content .= "* [{$usedNavPath}]({$relativeUrl})\n";
                $mermaidContent .= "    {$ownerId} --> {$usedId}[\"{$usedNavPath}\"];\n";
                $mermaidLinks[] = "click {$usedId} \"{$relativeUrl}\" \"View documentation for {$usedRaw}\"";
            } else {
                $content .= "* {$usedNavPath} (Not documented)\n";
                $mermaidContent .= "    {$ownerId} --> {$usedId}[\"{$usedNavPath}\"];\n";
            }
        }

        $content .= "\n\n### Composition Graph\n\n";
        $content .= "```mermaid\n";
        $content .= $mermaidContent;
        $content .= "    style {$ownerId} fill:#ffe7cd,stroke:#b38000,stroke-width:4px\n";
        if (! empty($mermaidLinks)) {
            $content .= '    '.implode("\n    ", $mermaidLinks)."\n";
        }
        $content .= "```\n";

        return $content;
    }

    private function generateUsedBySection(string $ownerKey, array $usedBy, array $registry, array $navPathMap): string
    {
        $content = "\n\n## Used By Building Blocks\n\n";
        $content .= "This component is a building block for the following functionalities:\n\n";

        $mermaidLinks = [];
        $mermaidContent = "graph LR\n";
        $ownerId = $this->slug($ownerKey);
        $ownerNavPath = $navPathMap[$ownerKey] ?? $ownerKey;
        $mermaidContent .= "    {$ownerId}[\"{$ownerNavPath}\"];\n";

        $sourcePath = $registry[$ownerKey] ?? '';

        foreach ($usedBy[$ownerKey] as $user) {
            $userKey = ltrim(trim((string) $user), '\\');
            $userId = $this->slug($userKey);
            $userNavPath = $navPathMap[$userKey] ?? $userKey;

            if (isset($registry[$userKey])) {
                $targetPath = $registry[$userKey];
                $relativeFilePath = $this->makeRelativePath($targetPath, $sourcePath);
                $relativeUrl = $this->toCleanUrl($relativeFilePath);

                $content .= "* [{$userNavPath}]({$relativeUrl})\n";
                $mermaidContent .= "    {$userId}[\"{$userNavPath}\"] --> {$ownerId};\n";
                $mermaidLinks[] = "click {$userId} \"{$relativeUrl}\" \"View documentation for {$user}\"";
            } else {
                $content .= "* {$userNavPath} (Not documented)\n";
                $mermaidContent .= "    {$userId}[\"{$userNavPath}\"] --> {$ownerId};\n";
            }
        }

        $content .= "\n\n### Dependency Graph\n\n";
        $content .= "```mermaid\n";
        $content .= $mermaidContent;
        $content .= "    style {$ownerId} fill:#ffe7cd,stroke:#b38000,stroke-width:4px\n";
        if (! empty($mermaidLinks)) {
            $content .= '    '.implode("\n    ", $mermaidLinks)."\n";
        }
        $content .= "```\n";

        return $content;
    }

    private function generateLinksSection(array $links): string
    {
        $content = "\n\n## Further reading\n\n";
        foreach ($links as $link) {
            $trimmedLink = trim((string) $link);
            if (preg_match('/^\[.*\]\s*\(.*\)$/', $trimmedLink)) {
                $content .= "* {$trimmedLink}\n";
            } elseif (preg_match('/^(\S+)\s+(.*)$/', $trimmedLink, $matches)) {
                $content .= "* [{$matches[2]}]({$matches[1]})\n";
            } else {
                $content .= "* [{$trimmedLink}]({$trimmedLink})\n";
            }
        }

        return $content;
    }

    private function generateFiles(array $tree, string $currentPath): void
    {
        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                // For directories, preserve original naming for static content
                $newPath = $currentPath.'/'.$key;
                $this->filesystem->makeDirectory($newPath);
                $this->generateFiles($value, $newPath);
            } else {
                // key is already the correct filename.md
                $this->filesystem->put($currentPath.'/'.$key, $value);
            }
        }
    }

    private function generateNavStructure(array $tree, string $pathPrefix = '', array $navPathMap = [], array $allNodes = [], array $reverseRegistry = []): array
    {
        $navItems = [];

        // First, collect all nav items
        foreach ($tree as $key => $value) {
            if ($key === 'index.md') {
                continue;
            }

            $filePath = $pathPrefix.$key;

            if (is_array($value)) {
                // For directories, use cleaned directory names
                $dirName = ucwords(str_replace(['_', '-'], ' ', $key));
                $navItems[] = [
                    'title' => $dirName,
                    'content' => $this->generateNavStructure($value, $pathPrefix.$key.'/', $navPathMap, $allNodes, $reverseRegistry),
                    'type' => $this->getNavItemType($dirName),
                    'sortKey' => strtolower($dirName),
                    'isChild' => false,
                    'parentKey' => null,
                ];
            } else {
                // For files, find the display title and node metadata
                $displayTitle = $this->findDisplayTitleForFile($filePath, $allNodes);
                $nodeMetadata = $this->findNodeMetadataForFile($filePath, $allNodes, $reverseRegistry);

                if ($displayTitle) {
                    $title = $displayTitle;
                } else {
                    // Fallback to filename with underscores replaced
                    $title = ucwords(str_replace(['_', '-', '-(', ')'], [' ', ' ', ' (', ')'], pathinfo((string) $key, PATHINFO_FILENAME)));
                }

                // Check if this is a child page and add Unicode prefix if so
                $isChild = $nodeMetadata && isset($nodeMetadata['isChildPage']) && $nodeMetadata['isChildPage'];
                $parentKey = $nodeMetadata['parentNavId'] ?? null;

                if ($isChild) {
                    // Add Unicode downward arrow with tip rightwards (↘) as prefix
                    $title = '↳ '.$title;
                }

                $navItems[] = [
                    'title' => $title,
                    'content' => $filePath,
                    'type' => $this->getNavItemType($title),
                    'sortKey' => strtolower($displayTitle ?? pathinfo((string) $key, PATHINFO_FILENAME)),
                    'isChild' => $isChild,
                    'parentKey' => $parentKey,
                ];
            }
        }

        // Sort the nav items with new parent-child logic
        usort($navItems, function ($a, $b) use ($allNodes, $reverseRegistry) {
            // Apply type priority first: regular -> static -> uncategorised
            if ($a['type'] !== $b['type']) {
                $typePriority = ['regular' => 1, 'static' => 2, 'uncategorised' => 3];

                return $typePriority[$a['type']] <=> $typePriority[$b['type']];
            }

            // Within same type, handle parent-child relationships
            // If one is child and the other is parent, parent comes first
            if ($a['isChild'] && ! $b['isChild'] && $a['parentKey'] === $this->findParentIdentifier($b, $allNodes, $reverseRegistry)) {
                return 1; // a (child) comes after b (parent)
            }
            if ($b['isChild'] && ! $a['isChild'] && $b['parentKey'] === $this->findParentIdentifier($a, $allNodes, $reverseRegistry)) {
                return -1; // a (parent) comes before b (child)
            }

            // If both are children of the same parent, sort alphabetically
            if ($a['isChild'] && $b['isChild'] && $a['parentKey'] === $b['parentKey']) {
                return $a['sortKey'] <=> $b['sortKey'];
            }

            // Default alphabetical sorting
            return $a['sortKey'] <=> $b['sortKey'];
        });

        // Convert back to the expected nav structure
        $nav = [];
        foreach ($navItems as $item) {
            $nav[] = [$item['title'] => $item['content']];
        }

        return $nav;
    }

    private function getNavItemType(string $dirName): string
    {
        // Check for special categories
        if (strtolower($dirName) === 'uncategorised') {
            return 'uncategorised';
        }

        // Check if this is a static content section
        $staticContentConfig = config('docs.static_content', []);
        foreach ($staticContentConfig as $contentType => $config) {
            $navPrefix = $config['nav_prefix'] ?? ucfirst((string) $contentType);
            if (strtolower($dirName) === strtolower($navPrefix)) {
                return 'static';
            }
        }

        // Default to regular PHPDoc content
        return 'regular';
    }

    private function findDisplayTitleForFile(string $filePath, array $allNodes): ?string
    {
        // Normalize function to handle case and space/underscore differences
        $normalize = (fn ($path) => strtolower(str_replace(' ', '_', $path)));

        // Simple approach: find the node that generated this file path
        foreach ($allNodes as $node) {
            // For static content, check if the registry path matches
            if (isset($node['type']) && $node['type'] === 'static_content') {
                $ownerParts = explode(':', (string) $node['owner'], 2);
                if (count($ownerParts) === 2) {
                    $contentType = $ownerParts[0]; // e.g., "specifications"
                    $relativePath = $ownerParts[1]; // e.g., "fulfillment/warehouse_refactoring/file.md"

                    // Build the full expected path: contentType/relativePath
                    $expectedFullPath = $contentType.'/'.$relativePath;

                    // Normalize both paths for comparison
                    $normalizedFilePath = $normalize($filePath);
                    $normalizedExpectedPath = $normalize($expectedFullPath);

                    // Check if this file path matches the expected path from the node
                    if ($normalizedFilePath === $normalizedExpectedPath) {
                        return $node['displayTitle'] ?? null;
                    }
                }
            } else {
                // For PHPDoc content, we could check if the generated path matches
                // For now, this is handled by the existing slug-based system
            }
        }

        return null;
    }

    private function findNodeMetadataForFile(string $filePath, array $allNodes, array $reverseRegistry = []): ?array
    {
        // Normalize function to handle case and space/underscore differences
        $normalize = (fn ($path) => strtolower(str_replace(' ', '_', $path)));

        // Find the node that generated this file path
        foreach ($allNodes as $node) {
            // For static content, check if the registry path matches
            if (isset($node['type']) && $node['type'] === 'static_content') {
                $ownerParts = explode(':', (string) $node['owner'], 2);
                if (count($ownerParts) === 2) {
                    $contentType = $ownerParts[0]; // e.g., "specifications"
                    $relativePath = $ownerParts[1]; // e.g., "fulfillment/warehouse_refactoring/file.md"

                    // Build the full expected path: contentType/relativePath
                    $expectedFullPath = $contentType.'/'.$relativePath;

                    // Normalize both paths for comparison
                    $normalizedFilePath = $normalize($filePath);
                    $normalizedExpectedPath = $normalize($expectedFullPath);

                    // Check if this file path matches the expected path from the node
                    if ($normalizedFilePath === $normalizedExpectedPath) {
                        return $node; // Return the entire node as metadata
                    }
                }
            }
        }

        // For PHPDoc content, use the reverse registry to find the owner
        if (! empty($reverseRegistry) && isset($reverseRegistry[$filePath])) {
            $owner = $reverseRegistry[$filePath];

            // Find the node with this owner
            foreach ($allNodes as $node) {
                if ($node['owner'] === $owner) {
                    return $node; // Return the entire node as metadata
                }
            }
        }

        return null;
    }

    private function findParentIdentifier(array $navItem, array $allNodes, array $reverseRegistry = []): ?string
    {
        // For navigation items that are files, we need to find their corresponding node
        // and return the parent identifier (navId or owner)
        if (isset($navItem['content']) && is_string($navItem['content'])) {
            $filePath = $navItem['content'];
            $nodeMetadata = $this->findNodeMetadataForFile($filePath, $allNodes, $reverseRegistry);

            if ($nodeMetadata) {
                return $nodeMetadata['navId'] ?? $nodeMetadata['owner'] ?? null;
            }
        }

        return null;
    }

    private function dumpAsYaml(array $data, string $outputPath): void
    {
        $yamlString = Yaml::dump($data, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $yamlString = preg_replace("/'(!!python[^']*)'/", '$1', $yamlString);
        $this->filesystem->put($outputPath, $yamlString);
    }

    private function slug(string $seg): string
    {
        return str_replace(['::', ' '], ['-', '-'], $seg);
    }

    private function makeRelativePath(string $path, string $base): string
    {
        // Calculate proper relative path between two locations in the docs tree
        // This handles cross-references between different directory structures
        // using proper ../ notation that MkDocs expects
        //
        // Strip .md extension from both paths before calculating relative path
        // because MkDocs serves each .md file as a directory (e.g., main-process.md -> /main-process/)
        $path = preg_replace('/\.md$/', '', $path);
        $base = preg_replace('/\.md$/', '', $base);

        $pathParts = explode('/', (string) $path);
        $baseParts = explode('/', (string) $base);

        // Remove common path prefix
        while (count($pathParts) > 0 && count($baseParts) > 0 && $pathParts[0] === $baseParts[0]) {
            array_shift($pathParts);
            array_shift($baseParts);
        }

        // Add ../ for each remaining directory in base path
        $relativePrefix = str_repeat('../', count($baseParts));

        // Combine with remaining target path
        return $relativePrefix.implode('/', $pathParts);
    }

    private function toCleanUrl(string $path): string
    {
        // Check if this path contains spaces or uppercase letters (indicating non-slugified static content)
        // MkDocs requires .md extension for non-slugified paths
        if (preg_match('/[\sA-Z]/', $path)) {
            // Keep the .md extension for paths with spaces or capitals
            return ($path === '.' || $path === '') ? '' : $path;
        }

        // For slugified paths, strip .md and use directory-style URLs
        $url = preg_replace('/\.md$/', '', $path);
        if (basename((string) $url) === 'index') {
            $url = dirname((string) $url);
        }

        return ($url === '.' || $url === '') ? '' : rtrim((string) $url, '/').'/';
    }

    /**
     * Fix PHP code blocks by prepending <?php for proper syntax highlighting
     *
     * @param  string  $content  The markdown content
     * @return string The fixed content
     */
    private function fixPhpCodeBlocks(string $content): string
    {
        // Pattern to match PHP code blocks
        return preg_replace_callback(
            '/^```php\n((?:(?!```)[\s\S])*?)^```/m',
            function ($matches) {
                $codeContent = $matches[1];

                // Check if it already starts with <?php
                if (! preg_match('/^\s*<\?php/', $codeContent)) {
                    // Prepend <?php\n
                    $codeContent = "<?php\n".$codeContent;
                }

                return "```php\n".$codeContent.'```';
            },
            $content
        );
    }
}
