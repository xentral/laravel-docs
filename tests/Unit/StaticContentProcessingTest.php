<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\SplFileInfo;
use Xentral\LaravelDocs\MkDocsGenerator;

beforeEach(function () {
    $this->filesystem = Mockery::mock(Filesystem::class);
    $this->generator = new MkDocsGenerator($this->filesystem);

    // Set up default config
    config(['docs.config' => [
        'site_name' => 'Test Documentation',
        'theme' => ['name' => 'material'],
    ]]);
});

afterEach(function () {
    Mockery::close();
});

it('parses static markdown files with annotations', function () {
    // Configure static content paths
    config(['docs.static_content' => [
        'guides' => [
            'path' => '/docs/guides',
            'nav_prefix' => 'Guides',
        ],
    ]]);

    $staticContent = <<<'MD'
@navid getting-started
@nav Guides / Getting Started

# Getting Started Guide

This guide helps you get started.
MD;

    // Mock file reading
    $this->filesystem->shouldReceive('exists')->with('/docs/guides')->andReturn(true);

    $mockFile = Mockery::mock(SplFileInfo::class);
    $mockFile->shouldReceive('getRealPath')->andReturn('/docs/guides/getting-started.md');
    $mockFile->shouldReceive('getExtension')->andReturn('md');

    $this->filesystem->shouldReceive('allFiles')->with('/docs/guides')->andReturn([$mockFile]);
    $this->filesystem->shouldReceive('get')->with('/docs/guides/getting-started.md')->andReturn($staticContent);

    // Set up output expectations
    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();
    $this->filesystem->shouldReceive('put')->atLeast()->once();

    $this->generator->generate([], '/docs');

    expect(true)->toBeTrue();
});

it('extracts @nav, @navid, @navparent from static content', function () {
    config(['docs.static_content' => [
        'guides' => [
            'path' => '/docs/guides',
            'nav_prefix' => 'Guides',
        ],
    ]]);

    $staticContent = <<<'MD'
@navid child-guide
@navparent parent-guide
@nav Guides / Child Guide

# Child Guide Title

Content here.
MD;

    $this->filesystem->shouldReceive('exists')->with('/docs/guides')->andReturn(true);

    $mockFile = Mockery::mock(SplFileInfo::class);
    $mockFile->shouldReceive('getRealPath')->andReturn('/docs/guides/child.md');
    $mockFile->shouldReceive('getExtension')->andReturn('md');

    $this->filesystem->shouldReceive('allFiles')->with('/docs/guides')->andReturn([$mockFile]);
    $this->filesystem->shouldReceive('get')->with('/docs/guides/child.md')->andReturn($staticContent);

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();
    $this->filesystem->shouldReceive('put')->atLeast()->once();

    // Should process without errors
    $this->generator->generate([], '/docs');

    expect(true)->toBeTrue();
});

it('extracts @uses and @link from static content', function () {
    config(['docs.static_content' => [
        'guides' => [
            'path' => '/docs/guides',
            'nav_prefix' => 'Guides',
        ],
    ]]);

    $staticContent = <<<'MD'
@nav Guides / API Guide
@uses \App\Services\ApiService
@link https://api.example.com

# API Guide

This guide documents the API.
MD;

    $this->filesystem->shouldReceive('exists')->with('/docs/guides')->andReturn(true);

    $mockFile = Mockery::mock(SplFileInfo::class);
    $mockFile->shouldReceive('getRealPath')->andReturn('/docs/guides/api-guide.md');
    $mockFile->shouldReceive('getExtension')->andReturn('md');

    $this->filesystem->shouldReceive('allFiles')->with('/docs/guides')->andReturn([$mockFile]);
    $this->filesystem->shouldReceive('get')->with('/docs/guides/api-guide.md')->andReturn($staticContent);

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();
    $this->filesystem->shouldReceive('put')->atLeast()->once();

    $this->generator->generate([], '/docs');

    expect(true)->toBeTrue();
});

it('processes cross-references in static content', function () {
    config(['docs.static_content' => [
        'guides' => [
            'path' => '/docs/guides',
            'nav_prefix' => 'Guides',
        ],
    ]]);

    $guideWithRefs = <<<'MD'
@navid main-guide
@nav Guides / Main Guide

# Main Guide

See [@navid:other-guide] for more information.
MD;

    $otherGuide = <<<'MD'
@navid other-guide
@nav Guides / Other Guide

# Other Guide

Additional information here.
MD;

    $this->filesystem->shouldReceive('exists')->with('/docs/guides')->andReturn(true);

    $mockFile1 = Mockery::mock(SplFileInfo::class);
    $mockFile1->shouldReceive('getRealPath')->andReturn('/docs/guides/main.md');
    $mockFile1->shouldReceive('getExtension')->andReturn('md');

    $mockFile2 = Mockery::mock(SplFileInfo::class);
    $mockFile2->shouldReceive('getRealPath')->andReturn('/docs/guides/other.md');
    $mockFile2->shouldReceive('getExtension')->andReturn('md');

    $this->filesystem->shouldReceive('allFiles')->with('/docs/guides')->andReturn([$mockFile1, $mockFile2]);
    $this->filesystem->shouldReceive('get')->with('/docs/guides/main.md')->andReturn($guideWithRefs);
    $this->filesystem->shouldReceive('get')->with('/docs/guides/other.md')->andReturn($otherGuide);

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();
    $this->filesystem->shouldReceive('put')->atLeast()->once();

    // Should process cross-references successfully
    $this->generator->generate([], '/docs');

    expect(true)->toBeTrue();
});

it('handles YAML frontmatter correctly', function () {
    config(['docs.static_content' => [
        'guides' => [
            'path' => '/docs/guides',
            'nav_prefix' => 'Guides',
        ],
    ]]);

    $contentWithFrontmatter = <<<'MD'
---
title: "Guide Title"
author: "John Doe"
---

@navid guide-with-fm
@nav Guides / Guide With Frontmatter

# Actual Content

This is the real content after frontmatter.
MD;

    $this->filesystem->shouldReceive('exists')->with('/docs/guides')->andReturn(true);

    $mockFile = Mockery::mock(SplFileInfo::class);
    $mockFile->shouldReceive('getRealPath')->andReturn('/docs/guides/with-frontmatter.md');
    $mockFile->shouldReceive('getExtension')->andReturn('md');

    $this->filesystem->shouldReceive('allFiles')->with('/docs/guides')->andReturn([$mockFile]);
    $this->filesystem->shouldReceive('get')->with('/docs/guides/with-frontmatter.md')->andReturn($contentWithFrontmatter);

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();

    // Capture generated content to verify frontmatter was stripped
    $capturedContent = null;
    $this->filesystem->shouldReceive('put')
        ->with(Mockery::pattern('/with-frontmatter\.md$/'), Mockery::on(function($content) use (&$capturedContent) {
            $capturedContent = $content;
            return true;
        }));

    $this->filesystem->shouldReceive('put')->with(Mockery::any(), Mockery::any())->atLeast()->once();

    $this->generator->generate([], '/docs');

    // Verify frontmatter was removed but content remains
    expect($capturedContent)->not->toContain('title: "Guide Title"');
    expect($capturedContent)->not->toContain('author: "John Doe"');
    expect($capturedContent)->toContain('Actual Content');
});

it('extracts titles from H1 headings', function () {
    config(['docs.static_content' => [
        'guides' => [
            'path' => '/docs/guides',
            'nav_prefix' => 'Guides',
        ],
    ]]);

    $contentWithH1 = <<<'MD'
@navid guide-h1

# Custom H1 Title

This guide has a custom H1 title that should be extracted.
MD;

    $this->filesystem->shouldReceive('exists')->with('/docs/guides')->andReturn(true);

    $mockFile = Mockery::mock(SplFileInfo::class);
    $mockFile->shouldReceive('getRealPath')->andReturn('/docs/guides/custom.md');
    $mockFile->shouldReceive('getExtension')->andReturn('md');

    $this->filesystem->shouldReceive('allFiles')->with('/docs/guides')->andReturn([$mockFile]);
    $this->filesystem->shouldReceive('get')->with('/docs/guides/custom.md')->andReturn($contentWithH1);

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();

    // Capture YAML to verify title extraction
    $yamlContent = null;
    $this->filesystem->shouldReceive('put')
        ->with('/docs/mkdocs.yml', Mockery::on(function($content) use (&$yamlContent) {
            $yamlContent = $content;
            return true;
        }));

    $this->filesystem->shouldReceive('put')->with(Mockery::any(), Mockery::any())->atLeast()->once();

    $this->generator->generate([], '/docs');

    // Verify the H1 title appears in navigation
    expect($yamlContent)->toContain('Custom H1 Title');
});

it('handles multiple static content sources', function () {
    config(['docs.static_content' => [
        'guides' => [
            'path' => '/docs/guides',
            'nav_prefix' => 'Guides',
        ],
        'specs' => [
            'path' => '/docs/specs',
            'nav_prefix' => 'Specifications',
        ],
    ]]);

    $guideContent = <<<'MD'
@nav Guides / Getting Started

# Getting Started
MD;

    $specContent = <<<'MD'
@nav Specifications / API Spec

# API Specification
MD;

    $this->filesystem->shouldReceive('exists')->with('/docs/guides')->andReturn(true);
    $this->filesystem->shouldReceive('exists')->with('/docs/specs')->andReturn(true);

    $mockGuideFile = Mockery::mock(SplFileInfo::class);
    $mockGuideFile->shouldReceive('getRealPath')->andReturn('/docs/guides/start.md');
    $mockGuideFile->shouldReceive('getExtension')->andReturn('md');

    $mockSpecFile = Mockery::mock(SplFileInfo::class);
    $mockSpecFile->shouldReceive('getRealPath')->andReturn('/docs/specs/api.md');
    $mockSpecFile->shouldReceive('getExtension')->andReturn('md');

    $this->filesystem->shouldReceive('allFiles')->with('/docs/guides')->andReturn([$mockGuideFile]);
    $this->filesystem->shouldReceive('allFiles')->with('/docs/specs')->andReturn([$mockSpecFile]);
    $this->filesystem->shouldReceive('get')->with('/docs/guides/start.md')->andReturn($guideContent);
    $this->filesystem->shouldReceive('get')->with('/docs/specs/api.md')->andReturn($specContent);

    $this->filesystem->shouldReceive('deleteDirectory')->once();
    $this->filesystem->shouldReceive('makeDirectory')->atLeast()->once();
    $this->filesystem->shouldReceive('put')->atLeast()->once();

    $this->generator->generate([], '/docs');

    expect(true)->toBeTrue();
});