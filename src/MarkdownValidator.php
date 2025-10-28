<?php declare(strict_types=1);

namespace Xentral\LaravelDocs;

class MarkdownValidator
{
    /**
     * Validate markdown content for common formatting issues
     *
     * @param  string  $content  The markdown content to validate
     * @param  string  $filePath  The file path for error reporting
     * @return array Array of validation warnings
     */
    public function validate(string $content, string $filePath = 'unknown'): array
    {
        $warnings = [];

        // Check for missing blank lines before lists
        $warnings = array_merge($warnings, $this->checkBlankLinesBeforeLists($content, $filePath));

        // Check for absolute file path links
        $warnings = array_merge($warnings, $this->checkAbsoluteFileLinks($content, $filePath));

        // Check for misuse of @ref syntax with file paths
        $warnings = array_merge($warnings, $this->checkRefSyntaxMisuse($content, $filePath));

        return $warnings;
    }

    /**
     * Check for missing blank lines before bulleted or numbered lists
     *
     * @param  string  $content  The markdown content
     * @param  string  $filePath  The file path for error reporting
     * @return array Array of warnings
     */
    private function checkBlankLinesBeforeLists(string $content, string $filePath): array
    {
        $warnings = [];
        $lines = explode("\n", $content);
        $lineCount = count($lines);

        for ($i = 1; $i < $lineCount; $i++) {
            $currentLine = $lines[$i];
            $previousLine = $lines[$i - 1];

            // Check if current line is a list item (bulleted or numbered)
            if ($this->isListItem($currentLine)) {
                // Check if previous line is not blank and not a list item
                if (trim($previousLine) !== '' && ! $this->isListItem($previousLine)) {
                    $hasMissingBlankLine = true;

                    // Allow lists after headings (they start with #)
                    if (preg_match('/^#+\s+/', $previousLine)) {
                        $hasMissingBlankLine = false;
                    }

                    // Allow lists inside blockquotes
                    if (preg_match('/^\s*>/', $currentLine)) {
                        $hasMissingBlankLine = false;
                    }

                    // Allow lists immediately after TOC markers
                    if (preg_match('/<!--\s*TOC\s*-->/i', $previousLine)) {
                        $hasMissingBlankLine = false;
                    }

                    if ($hasMissingBlankLine) {
                        $warnings[] = [
                            'type' => 'missing_blank_line_before_list',
                            'severity' => 'warning',
                            'file' => $filePath,
                            'line' => $i + 1, // 1-based line numbers
                            'message' => sprintf(
                                'Missing blank line before list item. Previous line: "%s"',
                                $this->truncate($previousLine, 50)
                            ),
                            'context' => [
                                'previous_line' => $previousLine,
                                'current_line' => $currentLine,
                            ],
                        ];
                    }
                }
            }
        }

        return $warnings;
    }

    /**
     * Check for absolute file path links that won't work in web documentation
     *
     * @param  string  $content  The markdown content
     * @param  string  $filePath  The file path for error reporting
     * @return array Array of warnings
     */
    private function checkAbsoluteFileLinks(string $content, string $filePath): array
    {
        $warnings = [];
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            // Pattern: [text](/absolute/path)
            // Match markdown links with absolute paths starting with /
            if (preg_match_all('/\[([^\]]+)\]\((\\/[^)]+)\)/', $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $linkText = $match[1];
                    $linkPath = $match[2];

                    // Skip if it looks like a web URL (has protocol or domain)
                    if (preg_match('/^\/\/(www\.|[a-z0-9-]+\.)/', $linkPath)) {
                        continue;
                    }

                    $warnings[] = [
                        'type' => 'absolute_file_link',
                        'severity' => 'warning',
                        'file' => $filePath,
                        'line' => $lineNumber + 1, // 1-based line numbers
                        'message' => sprintf(
                            'Absolute file path link "%s" will not work in web documentation',
                            $this->truncate($linkPath, 60)
                        ),
                        'context' => [
                            'current_line' => $line,
                            'link_text' => $linkText,
                            'link_path' => $linkPath,
                        ],
                        'suggestion' => $this->suggestLinkFix($linkPath, $linkText),
                    ];
                }
            }
        }

        return $warnings;
    }

    /**
     * Suggest how to fix an absolute file link
     *
     * @param  string  $linkPath  The absolute link path
     * @param  string  $linkText  The link text
     * @return string Suggestion
     */
    private function suggestLinkFix(string $linkPath, string $linkText): string
    {
        // Check if it's a PHP file that might be a documented class
        if (str_ends_with($linkPath, '.php')) {
            // Try to extract class name from path
            $pathParts = explode('/', trim($linkPath, '/'));
            $fileName = array_pop($pathParts);
            $className = str_replace('.php', '', $fileName);

            return sprintf(
                "Options:\n".
                "      1. Use code block (no link): `%s`\n".
                "      2. If class is documented, use: [@ref:...\\%s]\n".
                '      3. Use relative path if file exists in docs',
                $linkPath,
                $className
            );
        }

        return sprintf(
            "Options:\n".
            "      1. Use code block (no link): `%s`\n".
            '      2. Use relative path if file exists in docs',
            $linkPath
        );
    }

    /**
     * Check for misuse of @ref syntax with file paths instead of class names
     *
     * @param  string  $content  The markdown content
     * @param  string  $filePath  The file path for error reporting
     * @return array Array of warnings
     */
    private function checkRefSyntaxMisuse(string $content, string $filePath): array
    {
        $warnings = [];
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            // Pattern: @ref: followed by something that looks like a file path
            // We need to detect:
            // - @ref:/absolute/path
            // - @ref:./relative/path
            // - @ref:path/to/file.php
            // But NOT:
            // - @ref:App\Namespace\ClassName (valid class reference)

            // Find all @ref: and @navid: references
            if (preg_match_all('/@(ref|navid):([^\s\])]+)/', $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $refType = $match[1];
                    $refTarget = $match[2];

                    // Only check @ref (not @navid, which uses different identifiers)
                    if ($refType !== 'ref') {
                        continue;
                    }

                    $isFilePath = false;
                    $reason = '';

                    // Check if it starts with / or ./ (file path indicators)
                    if (preg_match('/^(\.|\/|\.\.\/|\.\\/)/', $refTarget)) {
                        $isFilePath = true;
                        $reason = 'starts with a file path indicator (/, ./, etc.)';
                    }
                    // Check if it contains forward slashes (likely a file path)
                    elseif (str_contains($refTarget, '/')) {
                        $isFilePath = true;
                        $reason = 'contains forward slashes (/)';
                    }
                    // Check if it ends with .php
                    elseif (str_ends_with($refTarget, '.php')) {
                        $isFilePath = true;
                        $reason = 'ends with .php extension';
                    }

                    if ($isFilePath) {
                        $warnings[] = [
                            'type' => 'ref_syntax_misuse',
                            'severity' => 'error',
                            'file' => $filePath,
                            'line' => $lineNumber + 1,
                            'message' => sprintf(
                                '@ref syntax used with file path instead of class name (%s)',
                                $reason
                            ),
                            'context' => [
                                'current_line' => $line,
                                'ref_target' => $refTarget,
                                'ref_type' => $refType,
                            ],
                            'suggestion' => $this->suggestRefFix($refTarget),
                        ];
                    }
                }
            }
        }

        return $warnings;
    }

    /**
     * Suggest how to fix a @ref syntax misuse
     *
     * @param  string  $refTarget  The incorrect reference target
     * @return string Suggestion
     */
    private function suggestRefFix(string $refTarget): string
    {
        $suggestions = "@ref syntax expects a fully-qualified class name, not a file path.\n\n";

        // Try to extract a potential class name from the path
        if (str_ends_with($refTarget, '.php')) {
            $pathParts = explode('/', trim($refTarget, '/'));
            $fileName = array_pop($pathParts);
            $className = str_replace('.php', '', $fileName);

            // Try to construct namespace from path
            // Look for common Laravel/PSR-4 patterns
            $namespace = '';
            if (in_array('app', $pathParts)) {
                $appIndex = array_search('app', $pathParts);
                $namespaceParts = array_slice($pathParts, $appIndex + 1);
                if (! empty($namespaceParts)) {
                    $namespace = 'App\\'.implode('\\', $namespaceParts).'\\';
                }
            }

            $suggestions .= "Correct syntax:\n";
            $suggestions .= "      [@ref:{$namespace}{$className}]\n\n";
            $suggestions .= "Alternative:\n";
            $suggestions .= "      If you don't want a link, use a code block: `{$className}`";
        } else {
            $suggestions .= "Example of correct syntax:\n";
            $suggestions .= "      [@ref:App\\Services\\MyService]\n\n";
            $suggestions .= "Alternative:\n";
            $suggestions .= "      Use a code block if you don't need a link: `{$refTarget}`";
        }

        return $suggestions;
    }

    /**
     * Check if a line is a list item (bulleted or numbered)
     *
     * @param  string  $line  The line to check
     */
    private function isListItem(string $line): bool
    {
        // Trim leading spaces but preserve the structure
        $trimmed = ltrim($line);

        // Check for bulleted lists: -, *, +
        if (preg_match('/^[-*+]\s+/', $trimmed)) {
            return true;
        }

        // Check for numbered lists: 1., 2., etc.
        if (preg_match('/^\d+\.\s+/', $trimmed)) {
            return true;
        }

        return false;
    }

    /**
     * Truncate a string for display
     *
     * @param  string  $text  The text to truncate
     * @param  int  $length  Maximum length
     */
    private function truncate(string $text, int $length = 50): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length).'...';
    }

    /**
     * Format validation warnings for display
     *
     * @param  array  $warnings  Array of warnings
     * @return string Formatted warning messages
     */
    public function formatWarnings(array $warnings): string
    {
        if (empty($warnings)) {
            return '';
        }

        $output = "\n".str_repeat('=', 80)."\n";
        $output .= 'Markdown Validation Warnings ('.count($warnings)." issues found)\n";
        $output .= str_repeat('=', 80)."\n\n";

        foreach ($warnings as $warning) {
            $output .= sprintf(
                "[%s] %s:%d\n",
                strtoupper((string) $warning['severity']),
                basename((string) $warning['file']),
                $warning['line']
            );
            $output .= '  '.$warning['message']."\n";

            if (isset($warning['context'])) {
                $output .= "  Context:\n";

                // Show previous line if available
                if (isset($warning['context']['previous_line'])) {
                    $output .= '    Line '.($warning['line'] - 1).': '.trim($warning['context']['previous_line'])."\n";
                }

                // Show current line
                if (isset($warning['context']['current_line'])) {
                    $output .= '    Line '.$warning['line'].': '.trim($warning['context']['current_line'])."\n";
                }

                // Show additional context for absolute links
                if (isset($warning['context']['link_text']) && isset($warning['context']['link_path'])) {
                    $output .= '    Link text: '.$warning['context']['link_text']."\n";
                    $output .= '    Link path: '.$warning['context']['link_path']."\n";
                }
            }

            // Show suggestion if available
            if (isset($warning['suggestion'])) {
                $output .= "  Suggestion:\n";
                $output .= '    '.str_replace("\n", "\n    ", $warning['suggestion'])."\n";
            }

            $output .= "\n";
        }

        $output .= str_repeat('=', 80)."\n";

        return $output;
    }
}
