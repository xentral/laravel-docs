<?php declare(strict_types=1);

it('can execute docs:publish command successfully', function () {
    config([
        'docs.output' => sys_get_temp_dir().'/test-docs',
        'docs.commands.publish' => 'echo "Mock mkdocs gh-deploy"',
    ]);

    $this->artisan('docs:publish')
        ->assertExitCode(0)
        ->expectsOutputToContain('Mock mkdocs gh-deploy');
});

it('handles publish command failure', function () {
    config([
        'docs.output' => sys_get_temp_dir().'/test-docs',
        'docs.commands.publish' => 'echo "Mock failed deploy" && exit 1',
    ]);

    $this->artisan('docs:publish')
        ->assertExitCode(1)
        ->expectsOutputToContain('Mock failed deploy');
});
