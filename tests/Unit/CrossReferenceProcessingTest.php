<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Xentral\LaravelDocs\MkDocsGenerator;

beforeEach(function () {
    $this->filesystem = Mockery::mock(Filesystem::class);
    $this->generator = new MkDocsGenerator($this->filesystem);

    // Set up default config
    config(['docs.config' => [
        'site_name' => 'Test Documentation',
        'theme' => ['name' => 'material'],
    ]]);
    config(['docs.static_content' => []]);
});

afterEach(function () {
    Mockery::close();
});

it('processes @ref syntax with auto-generated titles', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Services\AuthService',
            'navPath' => 'Services / Authentication',
            'navId' => null,
            'navParent' => null,
            'description' => 'Main authentication service.',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Controllers\AuthController',
            'navPath' => 'Controllers / Auth Controller',
            'navId' => null,
            'navParent' => null,
            'description' => 'Uses the [@ref:App\Services\AuthService] for authentication.',
            'links' => [],
            'uses' => [],
        ],
    ];

    // Set up filesystem mock
    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();

    // Capture the auth controller content to verify cross-reference was processed
    $controllerContent = null;
    $this->filesystem->shouldReceive('put')
        ->with(Mockery::pattern('/auth-controller\.md$/'), Mockery::on(function ($content) use (&$controllerContent) {
            $controllerContent = $content;

            return true;
        }));

    $this->filesystem->shouldReceive('put')->with(Mockery::any(), Mockery::any())->atLeast()->once();

    $this->generator->generate($documentationNodes, '/docs');

    // Verify the cross-reference was processed into a markdown link
    expect($controllerContent)->toContain('[');
    expect($controllerContent)->toContain('](');
});

it('processes @navid syntax with auto-generated titles', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Services\AuthService',
            'navPath' => 'Services / Authentication',
            'navId' => 'auth-service',
            'navParent' => null,
            'description' => '# Complete Authentication System',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Controllers\AuthController',
            'navPath' => 'Controllers / Auth Controller',
            'navId' => null,
            'navParent' => null,
            'description' => 'Delegates to [@navid:auth-service] for processing.',
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();
    $this->filesystem->shouldReceive('put')->atLeast()->once();

    // Should not throw any exceptions
    $this->generator->generate($documentationNodes, '/docs');

    expect(true)->toBeTrue();
});

it('handles custom link text with @ref and @navid', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Services\AuthService',
            'navPath' => 'Services / Authentication',
            'navId' => 'auth-service',
            'navParent' => null,
            'description' => 'Authentication service.',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Controllers\AuthController',
            'navPath' => 'Controllers / Auth',
            'navId' => null,
            'navParent' => null,
            'description' => 'See [custom auth text](@ref:App\Services\AuthService) and [another custom](@navid:auth-service).',
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();

    // Capture the content
    $capturedContent = null;
    $this->filesystem->shouldReceive('put')
        ->with(Mockery::pattern('/auth\.md$/'), Mockery::on(function ($content) use (&$capturedContent) {
            $capturedContent = $content;

            return true;
        }));

    $this->filesystem->shouldReceive('put')->with(Mockery::any(), Mockery::any())->atLeast()->once();

    $this->generator->generate($documentationNodes, '/docs');

    // Verify custom link text is used
    expect($capturedContent)->toContain('[custom auth text]');
    expect($capturedContent)->toContain('[another custom]');
});

it('throws exception for broken @ref references', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Controllers\AuthController',
            'navPath' => 'Controllers / Auth',
            'navId' => null,
            'navParent' => null,
            'description' => 'References [@ref:App\NonExistent\Class] which does not exist.',
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->atMost()->once();
    $this->filesystem->shouldReceive('makeDirectory')->atMost()->once();
    $this->filesystem->shouldReceive('put')->atMost()->once();

    // Should throw RuntimeException for broken reference
    expect(fn () => $this->generator->generate($documentationNodes, '/docs'))
        ->toThrow(RuntimeException::class, 'Broken reference: @ref:App\NonExistent\Class');
});

it('throws exception for broken @navid references', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Controllers\AuthController',
            'navPath' => 'Controllers / Auth',
            'navId' => null,
            'navParent' => null,
            'description' => 'References [@navid:nonexistent-id] which does not exist.',
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->atMost()->once();
    $this->filesystem->shouldReceive('makeDirectory')->atMost()->once();
    $this->filesystem->shouldReceive('put')->atMost()->once();

    // Should throw RuntimeException for broken reference
    expect(fn () => $this->generator->generate($documentationNodes, '/docs'))
        ->toThrow(RuntimeException::class, 'Broken reference: @navid:nonexistent-id');
});

it('generates smart titles with H1 fallback chain', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Services\ServiceWithH1',
            'navPath' => 'Services / Service With H1',
            'navId' => 'service-h1',
            'navParent' => null,
            'description' => '# Custom H1 Title',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Services\ServiceWithoutH1',
            'navPath' => 'Services / Service Without H1',
            'navId' => 'service-no-h1',
            'navParent' => null,
            'description' => 'Just plain text without H1.',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Controllers\TestController',
            'navPath' => 'Controllers / Test',
            'navId' => null,
            'navParent' => null,
            'description' => 'Links to [@navid:service-h1] and [@navid:service-no-h1].',
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();
    $this->filesystem->shouldReceive('put')->atLeast()->once();

    // Should successfully generate without errors
    $this->generator->generate($documentationNodes, '/docs');

    expect(true)->toBeTrue();
});

it('resolves relative URLs correctly', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Services\AuthService',
            'navPath' => 'Services / Authentication / Auth Service',
            'navId' => 'auth-service',
            'navParent' => null,
            'description' => 'Authentication service.',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Controllers\AuthController',
            'navPath' => 'Controllers / Auth Controller',
            'navId' => null,
            'navParent' => null,
            'description' => 'References [@ref:App\Services\AuthService].',
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();

    // Capture the controller content to verify relative URL
    $controllerContent = null;
    $this->filesystem->shouldReceive('put')
        ->with(Mockery::pattern('/auth-controller\.md$/'), Mockery::on(function ($content) use (&$controllerContent) {
            $controllerContent = $content;

            return true;
        }));

    $this->filesystem->shouldReceive('put')->with(Mockery::any(), Mockery::any())->atLeast()->once();

    $this->generator->generate($documentationNodes, '/docs');

    // Verify relative URL is generated
    expect($controllerContent)->toContain('](');
});

it('processes multiple cross-references in same content', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Services\AuthService',
            'navPath' => 'Services / Auth',
            'navId' => 'auth',
            'navParent' => null,
            'description' => 'Auth service.',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Services\UserService',
            'navPath' => 'Services / User',
            'navId' => 'user',
            'navParent' => null,
            'description' => 'User service.',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Controllers\MainController',
            'navPath' => 'Controllers / Main',
            'navId' => null,
            'navParent' => null,
            'description' => 'Uses [@ref:App\Services\AuthService] and [@navid:user] for processing.',
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();
    $this->filesystem->shouldReceive('put')->atLeast()->once();

    // Should process multiple references successfully
    $this->generator->generate($documentationNodes, '/docs');

    expect(true)->toBeTrue();
});
