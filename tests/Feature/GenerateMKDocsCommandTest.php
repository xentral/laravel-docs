<?php declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('can execute mkdocs:generate command successfully', function () {
    // Create a test fixture file with functional documentation
    $fixtureDir = __DIR__.'/../fixtures';
    if (! is_dir($fixtureDir)) {
        mkdir($fixtureDir, 0755, true);
    }

    file_put_contents($fixtureDir.'/TestService.php', <<<'PHP'
<?php

namespace Test;

/**
 * Test service for documentation
 * 
 * @functional
 * This is a test service that demonstrates functional documentation.
 * 
 * # Features
 * - Feature 1: Does something
 * - Feature 2: Does something else
 * 
 * @nav Test / Test Service
 * @uses \Test\Dependency
 */
class TestService
{
    public function doSomething() {}
}
PHP);

    // Set up configuration
    config([
        'docs.paths' => [$fixtureDir],
        'docs.output' => sys_get_temp_dir().'/test-docs',
        'docs.commands.build' => 'echo "Mock mkdocs build"',
    ]);

    // Mock the process to avoid actual Docker execution
    Process::fake([
        'echo "Mock mkdocs build"' => Process::result(output: 'Mock build success'),
    ]);

    $this->artisan('mkdocs:generate')
        ->assertExitCode(0);

    // Clean up
    unlink($fixtureDir.'/TestService.php');
    rmdir($fixtureDir);
});

it('handles no documentation nodes gracefully', function () {
    // Create empty fixture directory
    $fixtureDir = __DIR__.'/../empty-fixtures';
    if (! is_dir($fixtureDir)) {
        mkdir($fixtureDir, 0755, true);
    }

    // Create a PHP file without @functional documentation
    file_put_contents($fixtureDir.'/RegularClass.php', <<<'PHP'
<?php

namespace Test;

/**
 * Regular class without functional documentation
 */
class RegularClass
{
    public function doSomething() {}
}
PHP);

    config([
        'docs.paths' => [$fixtureDir],
        'docs.output' => sys_get_temp_dir().'/test-docs',
    ]);

    $this->artisan('mkdocs:generate')
        ->assertExitCode(0);

    // Clean up
    unlink($fixtureDir.'/RegularClass.php');
    rmdir($fixtureDir);
});

it('accepts custom path option', function () {
    $customPath = sys_get_temp_dir().'/custom-docs';
    $emptyFixtureDir = __DIR__.'/../empty-path-fixtures';

    if (! is_dir($emptyFixtureDir)) {
        mkdir($emptyFixtureDir, 0755, true);
    }

    config([
        'docs.paths' => [$emptyFixtureDir], // Need at least one path
        'docs.commands.build' => 'echo "Mock build"',
    ]);

    Process::fake([
        'echo "Mock build"' => Process::result(output: 'Mock build success'),
    ]);

    $this->artisan('mkdocs:generate', ['--path' => $customPath])
        ->assertExitCode(0);

    // Clean up
    rmdir($emptyFixtureDir);
});

it('handles parse errors gracefully', function () {
    // Create fixture directory with broken PHP file
    $fixtureDir = __DIR__.'/../broken-fixtures';
    if (! is_dir($fixtureDir)) {
        mkdir($fixtureDir, 0755, true);
    }

    file_put_contents($fixtureDir.'/BrokenClass.php', <<<'PHP'
<?php

namespace Test;

/**
 * @functional
 * This class has broken syntax
 */
class BrokenClass {
    // Missing closing brace intentionally
PHP);

    config([
        'docs.paths' => [$fixtureDir],
        'docs.output' => sys_get_temp_dir().'/test-docs',
    ]);

    $this->artisan('mkdocs:generate')
        ->assertExitCode(0);

    // Clean up
    unlink($fixtureDir.'/BrokenClass.php');
    rmdir($fixtureDir);
});
