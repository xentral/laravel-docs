<?php declare(strict_types=1);

namespace Xentral\LaravelDocs;

use Illuminate\Support\ServiceProvider;
use Xentral\LaravelDocs\Console\Commands\GenerateMKDocsCommand;
use Xentral\LaravelDocs\Console\Commands\ServeMKDocsCommand;

class DocsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/docs.php', 'xentral-docs');
        $this->publishes([
            dirname(__DIR__).'/.ai/guidelines/documentation.blade.php' => base_path('.ai/guidelines/documentation.blade.php'),
        ], 'xentral-docs');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateMKDocsCommand::class,
                ServeMKDocsCommand::class,
            ]);
        }
    }

    public function register(): void {}
}
