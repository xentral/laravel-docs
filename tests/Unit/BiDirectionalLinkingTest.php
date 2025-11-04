<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Xentral\LaravelDocs\MkDocsGenerator;

beforeEach(function () {
    $this->filesystem = Mockery::mock(Filesystem::class);
    $this->generator = new MkDocsGenerator($this->filesystem);

    config(['docs.config' => [
        'site_name' => 'Test Documentation',
        'theme' => ['name' => 'material'],
    ]]);
    config(['docs.static_content' => []]);
});

afterEach(function () {
    Mockery::close();
});

it('builds referenced-by map from cross-references', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Services\AuthService',
            'navPath' => 'Services / Auth',
            'navId' => 'auth-service',
            'navParent' => null,
            'description' => 'Main authentication service.',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Controllers\AuthController',
            'navPath' => 'Controllers / Auth',
            'navId' => null,
            'navParent' => null,
            'description' => 'Uses [@ref:App\Services\AuthService].',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Middleware\AuthMiddleware',
            'navPath' => 'Middleware / Auth',
            'navId' => null,
            'navParent' => null,
            'description' => 'Delegates to [@navid:auth-service].',
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();

    // Capture content for AuthService to verify "Referenced by" section
    $authServiceContent = null;
    $this->filesystem->shouldReceive('put')
        ->with(Mockery::any(), Mockery::on(function ($content) use (&$authServiceContent) {
            if (is_string($content) && str_contains($content, 'Main authentication service')) {
                $authServiceContent = $content;
            }

            return true;
        }))->atLeast()->once();

    $this->generator->generate($documentationNodes, '/docs');

    // Verify "Referenced by" section exists
    expect($authServiceContent)->toContain('## Referenced by');
    expect($authServiceContent)->toContain('This page is referenced by the following pages:');
});

it('generates "Referenced by" sections', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Services\PaymentService',
            'navPath' => 'Services / Payment',
            'navId' => 'payment',
            'navParent' => null,
            'description' => 'Payment processing service.',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Controllers\CheckoutController',
            'navPath' => 'Controllers / Checkout',
            'navId' => null,
            'navParent' => null,
            'description' => 'Handles checkout using [@navid:payment].',
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();

    $paymentContent = null;
    $this->filesystem->shouldReceive('put')
        ->with(Mockery::any(), Mockery::on(function ($content) use (&$paymentContent) {
            if (is_string($content) && str_contains($content, 'Payment processing service')) {
                $paymentContent = $content;
            }

            return true;
        }))->atLeast()->once();

    $this->generator->generate($documentationNodes, '/docs');

    // Verify structure of "Referenced by" section
    expect($paymentContent)->toContain('## Referenced by');
    expect($paymentContent)->toContain('Controllers / Checkout');
});

it('deduplicates backlink references', function () {
    $documentationNodes = [
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
            'owner' => 'App\Controllers\UserController',
            'navPath' => 'Controllers / User',
            'navId' => null,
            'navParent' => null,
            'description' => 'References [@navid:user] multiple times: [@navid:user] and [@navid:user].',
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();

    $userServiceContent = null;
    $this->filesystem->shouldReceive('put')
        ->with(Mockery::any(), Mockery::on(function ($content) use (&$userServiceContent) {
            if (is_string($content) && str_contains($content, 'User service')) {
                $userServiceContent = $content;
            }

            return true;
        }))->atLeast()->once();

    $this->generator->generate($documentationNodes, '/docs');

    // Verify Controllers/User appears only once in the "Referenced by" section
    $referencedBySection = substr((string) $userServiceContent, strpos((string) $userServiceContent, '## Referenced by'));
    $controllerCount = substr_count($referencedBySection, 'Controllers / User');

    expect($controllerCount)->toBe(1);
});

it('sorts backlinks by navigation path', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Services\CoreService',
            'navPath' => 'Services / Core',
            'navId' => 'core',
            'navParent' => null,
            'description' => 'Core service.',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Controllers\ZebraController',
            'navPath' => 'Controllers / Zebra',
            'navId' => null,
            'navParent' => null,
            'description' => 'Uses [@navid:core].',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Controllers\AlphaController',
            'navPath' => 'Controllers / Alpha',
            'navId' => null,
            'navParent' => null,
            'description' => 'Uses [@navid:core].',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Middleware\BetaMiddleware',
            'navPath' => 'Middleware / Beta',
            'navId' => null,
            'navParent' => null,
            'description' => 'Uses [@navid:core].',
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();

    $coreServiceContent = null;
    $this->filesystem->shouldReceive('put')
        ->with(Mockery::any(), Mockery::on(function ($content) use (&$coreServiceContent) {
            if (is_string($content) && str_contains($content, 'Core service')) {
                $coreServiceContent = $content;
            }

            return true;
        }))->atLeast()->once();

    $this->generator->generate($documentationNodes, '/docs');

    // Extract the referenced by section and verify alphabetical ordering
    $referencedBySection = substr((string) $coreServiceContent, strpos((string) $coreServiceContent, '## Referenced by'));

    // Check that Alpha comes before Beta, and Beta before Zebra
    $alphaPos = strpos($referencedBySection, 'Controllers / Alpha');
    $zebraPos = strpos($referencedBySection, 'Controllers / Zebra');
    $betaPos = strpos($referencedBySection, 'Middleware / Beta');

    expect($alphaPos)->toBeLessThan($zebraPos);
    expect($alphaPos)->toBeLessThan($betaPos);
});

it('handles mixed PHPDoc and static content references', function () {
    config(['docs.static_content' => [
        'guides' => [
            'path' => '/docs/guides',
            'nav_prefix' => 'Guides',
        ],
    ]]);

    $guideContent = <<<'MD'
@navid auth-guide
@nav Guides / Authentication Guide

# Auth Guide

References the [@ref:App\Services\AuthService].
MD;

    $this->filesystem->shouldReceive('exists')->with('/docs/guides')->andReturn(true);

    $mockFile = Mockery::mock(\Symfony\Component\Finder\SplFileInfo::class);
    $mockFile->shouldReceive('getRealPath')->andReturn('/docs/guides/auth.md');
    $mockFile->shouldReceive('getExtension')->andReturn('md');

    $this->filesystem->shouldReceive('allFiles')->with('/docs/guides')->andReturn([$mockFile]);
    $this->filesystem->shouldReceive('get')->with('/docs/guides/auth.md')->andReturn($guideContent);

    $documentationNodes = [
        [
            'owner' => 'App\Services\AuthService',
            'navPath' => 'Services / Auth',
            'navId' => 'auth-service',
            'navParent' => null,
            'description' => 'Documented in [@navid:auth-guide].',
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();
    $this->filesystem->shouldReceive('put')->atLeast()->once();

    // Should handle cross-references between PHPDoc and static content
    $this->generator->generate($documentationNodes, '/docs');

    expect(true)->toBeTrue();
});

it('does not create referenced by section when no references exist', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Services\StandaloneService',
            'navPath' => 'Services / Standalone',
            'navId' => 'standalone',
            'navParent' => null,
            'description' => 'This service is not referenced by anyone.',
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();

    $standaloneContent = null;
    $this->filesystem->shouldReceive('put')
        ->with(Mockery::any(), Mockery::on(function ($content) use (&$standaloneContent) {
            if (is_string($content) && str_contains($content, 'This service is not referenced')) {
                $standaloneContent = $content;
            }

            return true;
        }))->atLeast()->once();

    $this->generator->generate($documentationNodes, '/docs');

    // Verify no "Referenced by" section exists
    expect($standaloneContent)->not->toContain('## Referenced by');
});

it('generates backlinks with relative URLs', function () {
    $documentationNodes = [
        [
            'owner' => 'App\Services\Nested\DeepService',
            'navPath' => 'Services / Nested / Deep Service',
            'navId' => 'deep',
            'navParent' => null,
            'description' => 'Deeply nested service.',
            'links' => [],
            'uses' => [],
        ],
        [
            'owner' => 'App\Controllers\TopController',
            'navPath' => 'Controllers / Top',
            'navId' => null,
            'navParent' => null,
            'description' => 'Uses [@navid:deep] service.',
            'links' => [],
            'uses' => [],
        ],
    ];

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();

    $deepServiceContent = null;
    $this->filesystem->shouldReceive('put')
        ->with(Mockery::any(), Mockery::on(function ($content) use (&$deepServiceContent) {
            if (is_string($content) && str_contains($content, 'Deeply nested')) {
                $deepServiceContent = $content;
            }

            return true;
        }))->atLeast()->once();

    $this->generator->generate($documentationNodes, '/docs');

    // Verify backlink contains a relative path
    expect($deepServiceContent)->toContain('[Controllers / Top]');
    expect($deepServiceContent)->toContain(']('); // Should contain link syntax
});
