<?php declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Xentral\LaravelDocs\FunctionalDocBlockExtractor;

it('can extract functional documentation from class docblocks', function () {
    $extractor = new FunctionalDocBlockExtractor;
    $extractor->setCurrentFilePath('/test/path/TestClass.php');

    $phpCode = <<<'PHP'
<?php
namespace App\Services;

/**
 * Test service class
 * 
 * @functional
 * This service handles user authentication and authorization.
 * 
 * # Main Features
 * - User login validation
 * - Permission checking
 * 
 * @nav Authentication / User Service
 * @uses \App\Models\User
 * @link https://example.com/docs
 */
class UserService
{
    public function authenticate() {}
}
PHP;

    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $traverser = new NodeTraverser;
    $traverser->addVisitor($extractor);

    $ast = $parser->parse($phpCode);
    $traverser->traverse($ast);

    expect($extractor->foundDocs)->toHaveCount(1);

    $doc = $extractor->foundDocs[0];
    expect($doc['owner'])->toBe('App\Services\UserService');
    expect($doc['navPath'])->toBe('Authentication / User Service');
    expect($doc['description'])->toContain('This service handles user authentication');
    expect($doc['uses'])->toContain('\App\Models\User');
    expect($doc['links'])->toContain('https://example.com/docs');
    expect($doc['sourceFile'])->toBe('/test/path/TestClass.php');
});

it('can extract functional documentation from method docblocks', function () {
    $extractor = new FunctionalDocBlockExtractor;
    $extractor->setCurrentFilePath('/test/path/TestClass.php');

    $phpCode = <<<'PHP'
<?php
namespace App\Services;

class UserService
{
    /**
     * Authenticate user method
     * 
     * @functional
     * This method validates user credentials and returns authentication status.
     * 
     * @nav Authentication / Login Process
     * @uses \App\Models\User
     */
    public function authenticate($credentials) {}
}
PHP;

    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $traverser = new NodeTraverser;
    $traverser->addVisitor($extractor);

    $ast = $parser->parse($phpCode);
    $traverser->traverse($ast);

    expect($extractor->foundDocs)->toHaveCount(1);

    $doc = $extractor->foundDocs[0];
    expect($doc['owner'])->toBe('App\Services\UserService::authenticate');
    expect($doc['navPath'])->toBe('Authentication / Login Process');
    expect($doc['description'])->toContain('This method validates user credentials');
});

it('can extract functional documentation from function docblocks', function () {
    $extractor = new FunctionalDocBlockExtractor;
    $extractor->setCurrentFilePath('/test/path/helpers.php');

    $phpCode = <<<'PHP'
<?php

/**
 * Helper function for formatting
 * 
 * @functional
 * This function formats user names for display purposes.
 * 
 * @nav Helpers / String Formatting
 */
function format_name($firstName, $lastName) {}
PHP;

    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $traverser = new NodeTraverser;
    $traverser->addVisitor($extractor);

    $ast = $parser->parse($phpCode);
    $traverser->traverse($ast);

    expect($extractor->foundDocs)->toHaveCount(1);

    $doc = $extractor->foundDocs[0];
    expect($doc['owner'])->toBe('format_name');
    expect($doc['navPath'])->toBe('Helpers / String Formatting');
});

it('ignores docblocks without @functional tag', function () {
    $extractor = new FunctionalDocBlockExtractor;
    $extractor->setCurrentFilePath('/test/path/TestClass.php');

    $phpCode = <<<'PHP'
<?php
namespace App\Services;

/**
 * Regular docblock without @functional
 * This should be ignored by the extractor.
 */
class UserService
{
    public function authenticate() {}
}
PHP;

    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $traverser = new NodeTraverser;
    $traverser->addVisitor($extractor);

    $ast = $parser->parse($phpCode);
    $traverser->traverse($ast);

    expect($extractor->foundDocs)->toHaveCount(0);
});

it('creates default nav path when none provided', function () {
    $extractor = new FunctionalDocBlockExtractor;
    $extractor->setCurrentFilePath('/test/path/TestClass.php');

    $phpCode = <<<'PHP'
<?php
namespace App\Services;

/**
 * Test service
 * 
 * @functional
 * This service does something useful.
 */
class UserService
{
    public function authenticate() {}
}
PHP;

    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $traverser = new NodeTraverser;
    $traverser->addVisitor($extractor);

    $ast = $parser->parse($phpCode);
    $traverser->traverse($ast);

    expect($extractor->foundDocs)->toHaveCount(1);
    expect($extractor->foundDocs[0]['navPath'])->toBe('Uncategorised / UserService');
});

it('properly handles markdown headings by demoting them', function () {
    $extractor = new FunctionalDocBlockExtractor;
    $extractor->setCurrentFilePath('/test/path/TestClass.php');

    $phpCode = <<<'PHP'
<?php
namespace App\Services;

/**
 * @functional
 * This is the main description.
 * 
 * # Main Section
 * Content under main section.
 * 
 * ## Subsection
 * Content under subsection.
 */
class UserService {}
PHP;

    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $traverser = new NodeTraverser;
    $traverser->addVisitor($extractor);

    $ast = $parser->parse($phpCode);
    $traverser->traverse($ast);

    $description = $extractor->foundDocs[0]['description'];
    expect($description)->toContain('## Main Section');
    expect($description)->toContain('### Subsection');
});

it('handles multiple @uses and @links annotations', function () {
    $extractor = new FunctionalDocBlockExtractor;
    $extractor->setCurrentFilePath('/test/path/TestClass.php');

    $phpCode = <<<'PHP'
<?php
namespace App\Services;

/**
 * @functional
 * This service uses multiple dependencies.
 * 
 * @uses \App\Models\User
 * @uses \App\Services\EmailService
 * @link https://example.com/docs
 * @links [Another link](https://example.com/other)
 */
class UserService {}
PHP;

    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $traverser = new NodeTraverser;
    $traverser->addVisitor($extractor);

    $ast = $parser->parse($phpCode);
    $traverser->traverse($ast);

    $doc = $extractor->foundDocs[0];
    expect($doc['uses'])->toHaveCount(2);
    expect($doc['uses'])->toContain('\App\Models\User');
    expect($doc['uses'])->toContain('\App\Services\EmailService');
    expect($doc['links'])->toHaveCount(2);
    expect($doc['links'])->toContain('https://example.com/docs');
    expect($doc['links'])->toContain('[Another link](https://example.com/other)');
});
