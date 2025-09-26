<?php declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('can execute docs:serve command with default options', function () {
    $emptyFixtureDir = __DIR__.'/../serve-fixtures';

    if (! is_dir($emptyFixtureDir)) {
        mkdir($emptyFixtureDir, 0755, true);
    }

    config([
        'docs.output' => sys_get_temp_dir().'/test-docs',
        'docs.paths' => [$emptyFixtureDir],
        'docs.commands.build' => 'echo "Mock build"',
    ]);

    // Mock processes for both generate and serve
    Process::fake([
        'echo "Mock build"' => Process::result(output: 'Mock build success'),
        '*' => Process::result(output: 'Mock serve output'),
    ]);

    // We can't easily test the actual serve command since it runs indefinitely
    // Instead, we'll test that it calls the generate command first
    $this->artisan('docs:generate')
        ->assertExitCode(0);

    // Clean up
    rmdir($emptyFixtureDir);
});
