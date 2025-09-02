<?php declare(strict_types=1);

namespace Xentral\LaravelDocs\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Http\Controller\TestController;
use Xentral\LaravelDocs\DocsServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }

    protected function defineWebRoutes($router): void
    {
        $router->get('/api/v1/test-models', [TestController::class, 'index']);
    }

    protected function getPackageProviders($app): array
    {
        return [
            DocsServiceProvider::class,
        ];
    }
}
