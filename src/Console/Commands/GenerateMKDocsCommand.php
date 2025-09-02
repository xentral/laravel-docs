<?php declare(strict_types=1);

namespace Xentral\LaravelDocs\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;
use Xentral\LaravelDocs\FunctionalDocBlockExtractor;
use Xentral\LaravelDocs\MkDocsGenerator;

/**
 * A brief summary of what this class or method does.
 *
 * @functional
 * This is the main description of the functionality. It can span multiple
 * lines, and paragraphs are created automatically.
 *
 * You can use standard Markdown formatting like **bold** and `inline code`.
 *
 * # Section Headings
 *
 * Use Markdown headings (starting with `#`) to structure your document. The
 * script will automatically "demote" them to fit the page structure, so you'll
 * see this render as an `<h2>` on the page.
 *
 * Here is a list of key features:
 * - **Feature One:** Does something important.
 *     - You can have nested bullets.
 *     - The script correctly preserves the indentation.
 * - **Feature Two:** Handles another case.
 *
 * ## Mermaid Diagrams
 *
 * You can embed Mermaid charts for diagrams and flowcharts. The script is
 * smart enough to handle pasted code with different indentation.
 *
 * ```mermaid
 * graph TD
 * A[Start] --> B{Is it valid?};
 * B -->|Yes| C[Process Data];
 * B -->|No| D[Log Error];
 * C --> E[End];
 * D --> E;
 * ```
 *
 * The script will correctly render this as a visual diagram.
 *
 * * @nav Main Section / Sub Section / My Documentation Page
 *
 * @link [https://link-to-relevant-docs.com](https://link-to-relevant-docs.com)
 *
 * @links [A pre-formatted link](https://another-link.com)
 *
 * @uses \Xentral\LaravelDocs\FunctionalDocBlockExtractor
 *
 * @throws \Exception When something goes wrong.
 */
class GenerateMKDocsCommand extends Command
{
    protected $signature = 'mkdocs:generate {--path : The base path for the docs output directory}';

    public function handle(MkDocsGenerator $generator): int
    {
        $docsBaseDir = $this->option('path') ?: config('docs.output');

        $documentationNodes = $this->extractDocumentationNodes();

        if (empty($documentationNodes)) {
            $this->components->warn('No documentation nodes found. Skipping MKDocs generation.');

            return self::SUCCESS;
        }

        $generator->generate($documentationNodes, $docsBaseDir);

        $this->components->info('MKDocs configuration generated successfully.');
        $this->components->info('Documentation files generated successfully.');

        $cmd = config('docs.commands.build', 'docker run --rm -v {path}:/docs squidfunk/mkdocs-material build');
        if (is_array($cmd)) {
            $cmd = array_map(fn (string $part) => str_replace('{path}', $docsBaseDir, $part), $cmd);
        }
        $cmd = is_array($cmd)
            ? array_map(fn (string $part) => str_replace('{path}', $docsBaseDir, $part), $cmd)
            : str_replace('{path}', $docsBaseDir, $cmd);

        // Build using Docker
        $result = Process::run($cmd, function ($type, $output) {
            $this->components->info($output);
        });
        if (! $result->successful()) {
            $this->components->error('MKDocs build failed. Please check the output for details.');

            return self::FAILURE;
        }
        $this->components->info('MKDocs files generated successfully.');

        return self::SUCCESS;
    }

    private function extractDocumentationNodes(): array
    {
        dd(config('docs'));
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $files = Finder::create()
            ->files()
            ->in(config('docs.paths', []))
            ->name('*.php');

        $functionalExtractor = new FunctionalDocBlockExtractor;
        $traverser = new NodeTraverser;
        $traverser->addVisitor($functionalExtractor);

        foreach ($files as $file) {
            try {
                $functionalExtractor->setCurrentFilePath($file->getRealPath());
                $code = $file->getContents();
                $ast = $parser->parse($code);
                $traverser->traverse($ast);
            } catch (\Throwable $e) {
                $this->components->error("Error parsing file: {$file->getRealPath()}\n{$e->getMessage()}");

                return [];
            }
        }

        $documentationNodes = $functionalExtractor->foundDocs;
        $this->components->info('Found '.count($documentationNodes).' documentation nodes.');

        return $documentationNodes;
    }
}
