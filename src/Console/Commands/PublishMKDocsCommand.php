<?php declare(strict_types=1);

namespace Xentral\LaravelDocs\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class PublishMKDocsCommand extends Command
{
    protected $signature = 'docs:publish';

    public function handle(): int
    {
        $result = Process::path(config('docs.output'))
            ->run(config('docs.commands.publish'), function ($type, $output) {
                $this->components->info($output);
            });
        if (! $result->successful()) {
            $this->components->error('MKDocs build failed. Please check the output for details.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
