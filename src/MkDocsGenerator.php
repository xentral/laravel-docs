<?php declare(strict_types=1);

namespace Xentral\LaravelDocs;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class MkDocsGenerator
{
    public function __construct(private readonly Filesystem $filesystem) {}

    public function generate(array $documentationNodes, string $docsBaseDir): void
    {
        $docsOutputDir = $docsBaseDir.'/generated';

        // Parse static content files from configured paths
        $staticContentNodes = $this->parseStaticContentFiles($docsBaseDir);

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
        $usedBy = $this->buildUsedByMap($processedNodes);

        // Generate the document tree
        $docTree = $this->generateDocTree($processedNodes, $registry, $navPathMap, $usedBy);

        // Prepare output directory
        $this->filesystem->deleteDirectory($docsOutputDir);
        $this->filesystem->makeDirectory($docsOutputDir, recursive: true);

        // Create welcome page
        $welcomeContent = "# Welcome\n\nThis is the automatically generated functional documentation for the project. \n\nUse the navigation on the left to explore the documented processes.";
        $this->filesystem->put($docsOutputDir.'/index.md', $welcomeContent);

        // Generate files
        $this->generateFiles($docTree, $docsOutputDir);

        // Generate navigation structure with title mapping
        $navStructure = $this->generateNavStructure($docTree, '', $navPathMap, $processedNodes);
        array_unshift($navStructure, ['Home' => 'index.md']);

        // Generate config
        $config = config('docs.config', []);
        $config['nav'] = $navStructure;
        $this->dumpAsYaml($config, $docsBaseDir.'/mkdocs.yml');
    }

    private function parseStaticContentFiles(string $docsBaseDir): array
    {
        $staticContentNodes = [];
        $staticContentConfig = config('docs.static_content', []);

        foreach ($staticContentConfig as $contentType => $config) {
            $contentPath = $config['path'] ?? null;
            $navPrefix = $config['nav_prefix'] ?? ucfirst($contentType);

            if (!$contentPath || !$this->filesystem->exists($contentPath)) {
                continue;
            }

            $files = $this->filesystem->allFiles($contentPath);

            foreach ($files as $file) {
                if ($file->getExtension() === 'md') {
                    $staticContentNode = $this->parseStaticContentFile(
                        $file->getRealPath(),
                        $contentPath,
                        $contentType,
                        $navPrefix
                    );
                    if ($staticContentNode !== null) {
                        $staticContentNodes[] = $staticContentNode;
                    }
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
            $navPathSegments = array_map('trim', explode('/', $node['navPath']));
            $lastSegment = array_pop($navPathSegments);
            if (strtolower($lastSegment) === strtolower($parentRef)) {
                return $node;
            }
        }

        return null;
    }

    private function parseStaticContentFile(string $filePath, string $contentBasePath, string $contentType, string $navPrefix): ?array
    {
        $content = $this->filesystem->get($filePath);
        $relativePath = str_replace($contentBasePath.'/', '', $filePath);

        // Extract @nav lines and clean content
        [$navPath, $cleanedContent, $navId, $navParent] = $this->extractNavFromContent($content, $relativePath, $navPrefix);

        // Always try to extract display title from markdown content first
        $lines = explode("\n", $cleanedContent);
        $displayTitle = $this->extractTitleFromContent($lines);

        // If no markdown title found, fall back to navigation path (last segment)
        if (!$displayTitle) {
            $pathSegments = array_map('trim', explode('/', $navPath));
            $displayTitle = array_pop($pathSegments);
        }

        return [
            'owner' => $contentType.':'.$relativePath,
            'navPath' => $navPath,
            'displayTitle' => $displayTitle, // Store display title directly
            'description' => $cleanedContent,
            'uses' => [],
            'links' => [],
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
        $cleanedLines = [];
        $inFrontMatter = false;
        $frontMatterEnded = false;
        $navFound = false;
        $navIdFound = false;
        $navParentFound = false;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // Handle YAML frontmatter
            if ($trimmedLine === '---' && !$frontMatterEnded) {
                if (!$inFrontMatter) {
                    $inFrontMatter = true;
                    continue;
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
            if (!$navIdFound && str_starts_with($trimmedLine, '@navid ')) {
                $navId = trim(substr($trimmedLine, strlen('@navid')));
                $navIdFound = true;
                continue; // Exclude @navid line from content
            }

            // Check for @navparent lines (only at beginning of trimmed line, only first occurrence)
            if (!$navParentFound && str_starts_with($trimmedLine, '@navparent ')) {
                $navParent = trim(substr($trimmedLine, strlen('@navparent')));
                $navParentFound = true;
                continue; // Exclude @navparent line from content
            }

            // Check for @nav lines (only at beginning of trimmed line, only first occurrence)
            if (!$navFound && str_starts_with($trimmedLine, '@nav ')) {
                $navPath = trim(substr($trimmedLine, strlen('@nav')));
                $navFound = true;
                continue; // Exclude @nav line from content
            }

            // Collect all lines except navigation directives and frontmatter
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

            $navPath = $navPrefix . ' / ' . implode(' / ', $pathParts);
        }

        return [$navPath, implode("\n", $cleanedLines), $navId, $navParent];
    }

    private function extractTitleFromContent(array $lines): ?string
    {
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
            // (title should be at the beginning of the content)
            break;
        }

        return null;
    }

    private function buildRegistry(array $documentationNodes): array
    {
        $registry = [];
        foreach ($documentationNodes as $node) {
            // For static content, use exact original path from owner
            if (isset($node['type']) && $node['type'] === 'static_content') {
                $ownerParts = explode(':', $node['owner'], 2);
                if (count($ownerParts) === 2) {
                    $registry[$node['owner']] = $ownerParts[1]; // e.g., "fulfillment/warehouse_refactoring/file.md"
                }
            } else {
                // For PHPDoc content, use slugged paths (existing behavior)
                $pathSegments = array_map('trim', explode('/', (string) $node['navPath']));
                $pageTitle = array_pop($pathSegments);
                $urlParts = array_map([$this, 'slug'], $pathSegments);
                $urlParts[] = $this->slug($pageTitle).'.md';
                $registry[$node['owner']] = implode('/', $urlParts);
            }
        }

        return $registry;
    }

    private function buildNavPathMap(array $documentationNodes): array
    {
        $navPathMap = [];
        foreach ($documentationNodes as $node) {
            $navPathMap[$node['owner']] = $node['navPath'];
        }

        return $navPathMap;
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

    private function generateDocTree(array $documentationNodes, array $registry, array $navPathMap, array $usedBy): array
    {
        $docTree = [];
        $pathRegistry = [];

        foreach ($documentationNodes as $node) {
            $pathSegments = array_map('trim', explode('/', (string) $node['navPath']));
            $originalPageTitle = array_pop($pathSegments);
            $pageTitle = $originalPageTitle;

            // For static content, preserve everything exactly as-is
            if (isset($node['type']) && $node['type'] === 'static_content') {
                // Extract original filename from the owner (format: "contentType:relative/path.md")
                $ownerParts = explode(':', $node['owner'], 2);
                if (count($ownerParts) === 2) {
                    $originalPath = $ownerParts[1];
                    $pageFileName = basename($originalPath); // Keep original filename exactly
                } else {
                    $pageFileName = $originalPageTitle . '.md'; // Fallback
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
            $markdownContent = $this->generateMarkdownContent($node, $pageTitle, $registry, $navPathMap, $usedBy);

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

    private function generateMarkdownContent(array $node, string $pageTitle, array $registry, array $navPathMap, array $usedBy): string
    {
        // Handle static content nodes differently
        if (isset($node['type']) && $node['type'] === 'static_content') {
            return $this->generateStaticContent($node, $pageTitle);
        }

        $markdownContent = "# {$pageTitle}\n\n";
        $markdownContent .= "Source: `{$node['owner']}`\n{:.page-subtitle}\n\n";
        $markdownContent .= $node['description'];

        // Add "Building Blocks Used" section
        if (! empty($node['uses'])) {
            $markdownContent .= $this->generateUsedComponentsSection($node, $registry, $navPathMap);
        }

        // Add "Used By Building Blocks" section
        $ownerKey = $node['owner'];
        if (isset($usedBy[$ownerKey])) {
            $markdownContent .= $this->generateUsedBySection($ownerKey, $usedBy, $registry, $navPathMap);
        }

        // Add "Further reading" section
        if (! empty($node['links'])) {
            $markdownContent .= $this->generateLinksSection($node['links']);
        }

        return $markdownContent;
    }

    private function generateStaticContent(array $node, string $pageTitle): string
    {
        // For static content, we don't add the title since it might already be in the content
        // We also don't add the source subtitle
        $content = $node['description'];

        // If the content doesn't start with a title, add one
        if (! preg_match('/^#\s+/', trim($content))) {
            $content = "# {$pageTitle}\n\n" . $content;
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

    private function generateNavStructure(array $tree, string $pathPrefix = '', array $navPathMap = [], array $allNodes = []): array
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
                    'content' => $this->generateNavStructure($value, $pathPrefix.$key.'/', $navPathMap, $allNodes),
                    'type' => $this->getNavItemType($dirName),
                    'sortKey' => strtolower($dirName),
                    'isChild' => false,
                    'parentKey' => null
                ];
            } else {
                // For files, find the display title and node metadata
                $displayTitle = $this->findDisplayTitleForFile($filePath, $allNodes);
                $nodeMetadata = $this->findNodeMetadataForFile($filePath, $allNodes);

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
                    $title = '↳ ' . $title;
                }

                $navItems[] = [
                    'title' => $title,
                    'content' => $filePath,
                    'type' => $this->getNavItemType($title),
                    'sortKey' => strtolower($displayTitle ?? pathinfo((string) $key, PATHINFO_FILENAME)),
                    'isChild' => $isChild,
                    'parentKey' => $parentKey
                ];
            }
        }

        // Sort the nav items with new parent-child logic
        usort($navItems, function($a, $b) use ($allNodes) {
            // Apply type priority first: regular -> static -> uncategorised
            if ($a['type'] !== $b['type']) {
                $typePriority = ['regular' => 1, 'static' => 2, 'uncategorised' => 3];
                return $typePriority[$a['type']] <=> $typePriority[$b['type']];
            }

            // Within same type, handle parent-child relationships
            // If one is child and the other is parent, parent comes first
            if ($a['isChild'] && !$b['isChild'] && $a['parentKey'] === $this->findParentIdentifier($b, $allNodes)) {
                return 1; // a (child) comes after b (parent)
            }
            if ($b['isChild'] && !$a['isChild'] && $b['parentKey'] === $this->findParentIdentifier($a, $allNodes)) {
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
            $navPrefix = $config['nav_prefix'] ?? ucfirst($contentType);
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
        $normalize = function($path) {
            return strtolower(str_replace(' ', '_', $path));
        };

        // Simple approach: find the node that generated this file path
        foreach ($allNodes as $node) {
            // For static content, check if the registry path matches
            if (isset($node['type']) && $node['type'] === 'static_content') {
                $ownerParts = explode(':', $node['owner'], 2);
                if (count($ownerParts) === 2) {
                    $contentType = $ownerParts[0]; // e.g., "specifications"
                    $relativePath = $ownerParts[1]; // e.g., "fulfillment/warehouse_refactoring/file.md"

                    // Build the full expected path: contentType/relativePath
                    $expectedFullPath = $contentType . '/' . $relativePath;

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

    private function findNodeMetadataForFile(string $filePath, array $allNodes): ?array
    {
        // Normalize function to handle case and space/underscore differences
        $normalize = function($path) {
            return strtolower(str_replace(' ', '_', $path));
        };

        // Find the node that generated this file path
        foreach ($allNodes as $node) {
            // For static content, check if the registry path matches
            if (isset($node['type']) && $node['type'] === 'static_content') {
                $ownerParts = explode(':', $node['owner'], 2);
                if (count($ownerParts) === 2) {
                    $contentType = $ownerParts[0]; // e.g., "specifications"
                    $relativePath = $ownerParts[1]; // e.g., "fulfillment/warehouse_refactoring/file.md"

                    // Build the full expected path: contentType/relativePath
                    $expectedFullPath = $contentType . '/' . $relativePath;

                    // Normalize both paths for comparison
                    $normalizedFilePath = $normalize($filePath);
                    $normalizedExpectedPath = $normalize($expectedFullPath);

                    // Check if this file path matches the expected path from the node
                    if ($normalizedFilePath === $normalizedExpectedPath) {
                        return $node; // Return the entire node as metadata
                    }
                }
            } else {
                // For PHPDoc content, we could match based on generated paths
                // This would require more complex path matching logic
                // For now, we'll skip this and handle only static content
            }
        }

        return null;
    }

    private function findParentIdentifier(array $navItem, array $allNodes): ?string
    {
        // For navigation items that are files, we need to find their corresponding node
        // and return the parent identifier (navId or owner)
        if (isset($navItem['content']) && is_string($navItem['content'])) {
            $filePath = $navItem['content'];
            $nodeMetadata = $this->findNodeMetadataForFile($filePath, $allNodes);

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
        return Str::slug($seg, dictionary: ['::' => '-']);
    }

    private function makeRelativePath(string $path, string $base): string
    {
        if (str_starts_with($path, dirname($base))) {
            return './'.substr($path, strlen(dirname($base).'/'));
        }

        return $path;
    }

    private function toCleanUrl(string $path): string
    {
        $url = preg_replace('/\.md$/', '', $path);
        if (basename((string) $url) === 'index') {
            $url = dirname((string) $url);
        }

        return ($url === '.' || $url === '') ? '' : rtrim((string) $url, '/').'/';
    }
}
