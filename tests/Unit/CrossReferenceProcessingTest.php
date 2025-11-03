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

it('processes @navid references in Mermaid charts', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Services\DataService',
            'navPath' => 'Services / Data',
            'navId' => 'data-service',
            'navParent' => null,
            'description' => 'Data service for processing.',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Services\CacheService',
            'navPath' => 'Services / Cache',
            'navId' => 'cache-service',
            'navParent' => null,
            'description' => 'Cache service for optimization.',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Services\FlowService',
            'navPath' => 'Services / Flow',
            'navId' => null,
            'navParent' => null,
            'description' => <<<'MD'
## Service Flow

```mermaid
graph LR
    A[Start] --> B[Process Data]
    B --> C[Cache Result]
    click B "@navid:data-service" "View Data Service"
    click C '@navid:cache-service' 'View Cache Service'
```

The diagram shows the service flow.
MD,
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();

    // Capture the FlowService content to verify Mermaid references were processed
    $flowContent = null;
    $this->filesystem->shouldReceive('put')
        ->with(Mockery::pattern('/flow\.md$/'), Mockery::on(function ($content) use (&$flowContent) {
            $flowContent = $content;

            return true;
        }));

    $this->filesystem->shouldReceive('put')->with(Mockery::any(), Mockery::any())->atLeast()->once();

    $this->generator->generate($documentationNodes, '/docs');

    // Verify Mermaid references were processed to relative URLs
    expect($flowContent)->toContain('```mermaid');
    expect($flowContent)->toContain('click B "../data/" "View Data Service"'); // Processed with tooltip
    expect($flowContent)->toContain('click C "../cache/" "View Cache Service"'); // Processed with tooltip (quotes normalized to double)
    expect($flowContent)->not->toContain('@navid:'); // References should be resolved
});

it('supports fragment identifiers in @navid and @ref links', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Services\AuthService',
            'navPath' => 'Services / Auth',
            'navId' => 'auth-service',
            'navParent' => null,
            'description' => <<<'MD'
# Authentication Service

## Overview
General authentication overview.

## Login Process
Detailed login process.

## Security Features
Security implementation details.
MD,
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Controllers\UserController',
            'navPath' => 'Controllers / User',
            'navId' => null,
            'navParent' => null,
            'description' => <<<'MD'
## User Controller

See the [@navid:auth-service#login-process] for authentication details.

Also check [@ref:App\Services\AuthService#security-features] for security info.

With custom text: [login details](@navid:auth-service#login-process).

## Mermaid with Fragments

```mermaid
graph TD
    A[User Login] --> B[Auth Check]
    click A "@navid:auth-service#login-process" "View Login Process"
    click B "@ref:App\Services\AuthService#security-features"
```
MD,
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();

    // Capture the UserController content to verify fragment links
    $userContent = null;
    $this->filesystem->shouldReceive('put')
        ->with(Mockery::pattern('/user\.md$/'), Mockery::on(function ($content) use (&$userContent) {
            $userContent = $content;

            return true;
        }));

    $this->filesystem->shouldReceive('put')->with(Mockery::any(), Mockery::any())->atLeast()->once();

    $this->generator->generate($documentationNodes, '/docs');

    // Verify inline fragment links
    expect($userContent)->toContain('#login-process)'); // @navid with fragment
    expect($userContent)->toContain('#security-features)'); // @ref with fragment
    expect($userContent)->toContain('[login details]('); // Custom text preserved

    // Verify Mermaid fragment links
    expect($userContent)->toContain('click A '); // Mermaid element preserved
    expect($userContent)->toContain('#login-process"'); // Fragment in Mermaid link
    expect($userContent)->toContain('click B '); // Mermaid element preserved
    expect($userContent)->toContain('#security-features"'); // Fragment in Mermaid link

    // Ensure references are resolved
    expect($userContent)->not->toContain('@navid:');
    expect($userContent)->not->toContain('@ref:');
});

it('throws exception for broken Mermaid references', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Services\DiagramService',
            'navPath' => 'Services / Diagram',
            'navId' => null,
            'navParent' => null,
            'description' => <<<'MD'
## Service Diagram

```mermaid
graph LR
    A[Start] --> B[End]
    click A "@navid:nonexistent-service" "This should fail"
```
MD,
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->atMost()->once();
    $this->filesystem->shouldReceive('makeDirectory')->atMost()->once();
    $this->filesystem->shouldReceive('put')->atMost()->once();

    // Should throw RuntimeException for broken Mermaid reference
    expect(fn () => $this->generator->generate($documentationNodes, '/docs'))
        ->toThrow(RuntimeException::class, 'Broken Mermaid reference: @navid:nonexistent-service');
});
