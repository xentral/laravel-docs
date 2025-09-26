<?php declare(strict_types=1);

namespace Xentral\LaravelDocs\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class ServeMKDocsCommand extends Command
{
    protected $signature = 'docs:serve';

    protected bool $shouldKeepRunning = true;

    protected ?int $receivedSignal = null;

    public function handle(): int
    {
        $this->trap([SIGTERM, SIGQUIT], function (int $signal) {
            $this->receivedSignal = $signal;
            $this->shouldKeepRunning = false;
            $this->info('Stopping MKDocs server...');
        });

        $this->call('docs:generate');

        $process = Process::tty()
            ->path(config('docs.output'))
            ->timeout(0)
            ->idleTimeout(0)
            ->start(config('docs.commands.serve'));

        while ($process->running() && $this->shouldKeepRunning) {
            if ($output = trim($process->latestOutput())) {
                $this->output->write($output);
            }
            if ($errorOutput = trim($process->latestErrorOutput())) {
                $this->output->error($errorOutput);
            }
            sleep(1);
        }
        if ($process->running()) {
            $process->stop();
        }

        return self::SUCCESS;
    }
}
