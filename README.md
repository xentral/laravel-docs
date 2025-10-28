# Laravel Docs

[![Latest Version on Packagist](https://img.shields.io/packagist/v/xentral/laravel-docs.svg?style=flat-square)](https://packagist.org/packages/xentral/laravel-docs)
[![Total Downloads](https://img.shields.io/packagist/dt/xentral/laravel-docs.svg?style=flat-square)](https://packagist.org/packages/xentral/laravel-docs)
![GitHub Actions](https://github.com/xentral/laravel-docs/actions/workflows/main.yml/badge.svg)

A Laravel package that automatically generates beautiful documentation from your PHPDoc comments using the `@functional` annotation. It extracts functional documentation from your codebase and generates a modern, searchable MkDocs website.

## Features

- 🔍 **Automated extraction** of functional documentation from PHPDoc comments
- 📄 **Static content support** for including existing markdown files
- 🏗️ **Hierarchical navigation** with parent-child page relationships
- 🔗 **Smart cross-reference linking** with auto-generated titles and bi-directional discovery
- 📚 **MkDocs integration** with Material Design theme
- 🎯 **Selective documentation** using `@functional` annotation
- 📊 **Dependency tracking** and visualization with Mermaid diagrams
- 📱 **Responsive documentation** with navigation and search
- 🐍 **Python dependencies** managed automatically via uv
- ⚡ **Laravel commands** for easy integration

## Installation

Install the package via Composer:

```bash
composer require --dev xentral/laravel-docs
```

The package will automatically register its service provider and commands.

### Prerequisites

This package requires [uv](https://github.com/astral-sh/uv) to be installed on your system. uv is a fast Python package installer and resolver that's used to manage the MkDocs dependencies.

To install uv:

```bash
# On macOS and Linux:
curl -LsSf https://astral.sh/uv/install.sh | sh

# Or using pip:
pip install uv

# Or using Homebrew:
brew install uv
```

## Configuration

Publish the configuration file (optional):

```bash
php artisan vendor:publish --provider="Xentral\LaravelDocs\DocsServiceProvider"
```

Configure your documentation paths in `config/docs.php`:

```php
return [
    'paths' => [
        app_path(), // Include your app directory
        // Add other directories to scan
    ],
    'output' => base_path('docs'),
    'static_content' => [
        'specifications' => [
            'path' => base_path('docs/specifications'),
            'nav_prefix' => 'Specifications',
        ],
        'guides' => [
            'path' => base_path('docs/guides'),
            'nav_prefix' => 'Guides',
        ],
        // Add more static content types as needed
    ],
    'commands' => [
        'build' => 'uvx -w mkdocs-material -w pymdown-extensions mkdocs build',
        'publish' => 'uvx -w mkdocs-material -w pymdown-extensions mkdocs gh-deploy',
        'serve' => 'uvx -w mkdocs-material -w pymdown-extensions mkdocs serve',
    ],
    'config' => [
        'site_name' => 'Your Project Documentation',
        'theme' => ['name' => 'material'],
        // ... additional MkDocs configuration
    ],
];
```

The package uses `uv` (via `uvx`) to automatically manage the Python dependencies (MkDocs and its extensions) without requiring a separate Python environment setup.

## Usage

### Writing Functional Documentation

Add the `@functional` annotation to your PHPDoc comments to mark them for extraction:

````php
<?php

namespace App\Services;

/**
 * User authentication service
 *
 * @functional
 * This service handles all user authentication processes including login,
 * logout, password resets, and session management.
 *
 * # Key Features
 * - **Secure Login**: Multi-factor authentication support
 * - **Session Management**: Automatic session cleanup
 * - **Password Security**: Bcrypt hashing with salt
 *
 * ## Usage Example
 *
 * ```php
 * $auth = new AuthService();
 * $result = $auth->authenticate($credentials);
 * ```
 *
 * @nav Authentication / User Service
 * @uses \App\Models\User
 * @uses \App\Services\EmailService
 * @link https://laravel.com/docs/authentication
 */
class AuthService
{
    /**
     * Authenticate user credentials
     *
     * @functional
     * This method validates user credentials against the database and
     * creates a secure session if authentication is successful.
     *
     * This process appears as a child page under the main User Service
     * in the navigation, demonstrating hierarchical organization.
     *
     * @nav Authentication / Login Process
     * @uses \App\Models\User
     */
    public function authenticate(array $credentials): bool
    {
        // Implementation...
    }

    /**
     * Multi-factor authentication verification
     *
     * @functional
     * Handles the second factor of authentication using TOTP tokens,
     * SMS codes, or backup codes for enhanced security.
     *
     * This also appears under the User Service section, showing how
     * multiple related methods are grouped together.
     *
     * @nav Authentication / MFA Verification
     * @uses \App\Models\User
     * @uses \App\Services\TotpService
     */
    public function verifyMfaToken(string $token): bool
    {
        // Implementation...
    }
}
````

### Available Annotations

The following annotations work in **both** PHPDoc comments and static markdown files:

##### Navigation Annotations
- **`@nav`**: Sets the navigation path (e.g., "Authentication / User Service")
- **`@navid`**: Sets a unique identifier for referencing this page as a parent
- **`@navparent`**: References a parent page by its `@navid` for explicit hierarchical navigation

##### Content Annotations
- **`@uses`**: Links to dependencies that will be cross-referenced
- **`@link`**: Adds external links to the documentation
- **`@links`**: Alternative syntax for links

##### PHPDoc-Specific Annotations
- **`@functional`**: Marks the documentation block for extraction (required)

> **Note**: Hierarchical navigation works in two ways:
> - **Automatic grouping**: Pages with shared path prefixes in `@nav` paths (like "Authentication / User Service" and "Authentication / Login Process") are automatically grouped under "Authentication"
> - **Explicit parent-child**: Use `@navid` and `@navparent` for explicit parent-child relationships across any content type

### Working with Static Content Files

In addition to PHPDoc comments, you can include existing markdown files in your documentation. This is useful for specifications, guides, tutorials, or any content that exists outside your code.

#### Setting up Static Content

1. **Configure paths** in your `config/docs.php`:

```php
'static_content' => [
    'specifications' => [
        'path' => base_path('docs/specifications'),
        'nav_prefix' => 'Specifications',
    ],
    'guides' => [
        'path' => base_path('docs/guides'),
        'nav_prefix' => 'User Guides',
    ],
],
```

2. **Create your markdown files** with cross-references:

```markdown
<!-- docs-content/specifications/api-overview.md -->
@navid api
@nav API / Overview

# API Overview

This document describes our REST API architecture and design principles.

## Related Documentation

- [Authentication Guide](../guides/authentication.md) - Detailed authentication setup
- [API Endpoints Reference](./endpoints/rest-api.md) - Complete endpoint documentation
- [Error Handling](../guides/troubleshooting.md) - Common error scenarios

For implementation examples, see our [Getting Started Guide](../guides/getting-started.md).
```

#### Hierarchical Navigation

Create parent-child relationships between pages:

```markdown
<!-- docs-content/specifications/api-overview.md -->
@navid api
# API Overview

This is the main API documentation page.

Related pages:
- [Authentication Details](../guides/auth-setup.md)
- [Rate Limiting](./rate-limits.md)
```

```markdown
<!-- docs-content/specifications/api-endpoints.md -->
@navparent api
# API Endpoints

This page describes individual endpoints and inherits from the API Overview page.

See also: [Database Schema](../specifications/database.md) for data structure details.
```

#### Features of Static Content

- **Automatic processing**: Files are automatically discovered and processed
- **Flexible navigation**: Use `@nav` to customize navigation paths
- **Hierarchical structure**: Use `@navid` and `@navparent` for parent-child relationships
- **Cross-references**: Use standard markdown links to reference other files
- **YAML frontmatter support**: Standard markdown frontmatter is automatically stripped
- **Title extraction**: Page titles are extracted from the first `# heading` in the file
- **Directory structure**: File organization is preserved in the navigation structure

### Cross-Reference Linking

The package includes a powerful cross-reference linking system that allows you to create links between documentation pages using special syntax. The system automatically generates link text, validates references, and creates bi-directional links.

#### Basic Syntax

There are two ways to create cross-references:

**1. Reference by Class/Owner (`@ref`)**

Use `@ref:` to reference a page by its fully qualified class name or owner identifier:

```markdown
For authentication, see [@ref:App\Services\AuthService].
```

This automatically generates a link with smart title extraction from the target page.

**2. Reference by Navigation ID (`@navid`)**

Use `@navid:` to reference a page by its custom navigation identifier:

```markdown
More details in the [@navid:api] documentation.
```

#### Auto-Generated Titles

The cross-reference system automatically generates meaningful link text using a smart fallback chain:

1. **H1 Title**: First, it extracts the first H1 heading from the target page's content
2. **Navigation Path**: If no H1 is found, it uses the last segment of the `@nav` path
3. **Class Name**: Finally, it falls back to the class name from the owner identifier

**Example:**

```php
/**
 * User authentication service
 *
 * @functional
 * This service handles authentication.
 *
 * # Complete Authentication System
 * ...
 *
 * @nav Services / Auth
 */
class AuthService {}
```

When referenced with `[@ref:App\Services\AuthService]`, the generated link text will be:
- "Complete Authentication System" (from H1 title)
- If no H1: "Auth" (from nav path)
- If no nav path: "AuthService" (from class name)

#### Custom Link Text

You can override the auto-generated title by providing custom link text:

```markdown
See the [authentication setup guide](@ref:App\Services\AuthService).
Read more about [our API](@navid:api).
```

#### Bi-Directional Discovery

The system automatically tracks all cross-references and generates "Referenced by" sections on target pages. This creates bidirectional linking without any manual maintenance.

**Example:**

If you reference `App\Services\AuthService` from multiple pages:

```markdown
<!-- In guards.md -->
The guard system uses [@ref:App\Services\AuthService].

<!-- In middleware.md -->
Middleware delegates to [@ref:App\Services\AuthService].
```

The `AuthService` documentation page will automatically include:

```markdown
## Referenced by

This page is referenced by the following pages:

* [Guards / Authentication Guards](./guards/)
* [Middleware / Auth Middleware](./middleware/)
```

#### Error Handling and Validation

The build process validates all cross-references and **fails with informative errors** if broken references are found:

```
RuntimeException: Broken reference: @ref:App\NonExistent\Class in App\Services\AuthService
```

This ensures your documentation links stay accurate as your codebase evolves.

#### Cross-Referencing Between PHPDoc and Static Content

Cross-references work seamlessly across both PHPDoc comments and static markdown files:

**PHPDoc referencing static content:**
```php
/**
 * @functional
 * Implementation details in [@navid:api-spec].
 */
class ApiController {}
```

**Static content referencing PHPDoc:**
```markdown
<!-- docs/guides/api-guide.md -->
@navid api-spec

# API Specification

The implementation is in [@ref:App\Controllers\ApiController].
```

#### Best Practices

**When to use `@ref` vs `@navid`:**

- **Use `@ref`** for referencing specific classes, methods, or PHPDoc-documented code
- **Use `@navid`** for referencing conceptual pages, guides, or when you want a stable reference that won't change with refactoring

**Setting up navigation IDs:**

```php
/**
 * @functional
 * Main authentication service documentation
 *
 * @navid auth-service  // Stable identifier for references
 * @nav Services / Authentication
 */
class AuthService {}
```

```markdown
<!-- docs/guides/authentication.md -->
@navid auth-guide
@nav Guides / Authentication Setup

# Authentication Guide

This guide complements the [@navid:auth-service] implementation.
```

### Generate Documentation

Generate your documentation using the Artisan command:

```bash
php artisan docs:generate
```

The documentation will be generated in the directory specified in your `config/docs.php` file (default: `base_path('docs')`).

### Serve Documentation Locally

Start a local development server to preview your documentation:

```bash
php artisan docs:serve
```

The documentation will be available at `http://localhost:8000` (default MkDocs port)

### Publish Documentation to GitHub Pages

Deploy your documentation to GitHub Pages:

```bash
php artisan docs:publish
```

This will build and deploy your documentation to the `gh-pages` branch of your repository.

## Documentation Structure

The generated documentation includes:

- **Automatic Navigation**: Organized by your `@nav` paths
- **Building Blocks**: Shows dependencies and relationships
- **Mermaid Diagrams**: Visual representation of component dependencies
- **Cross-references**: Links between related components
- **External Links**: Links to additional resources

## Advanced Features

### Dependency Tracking

The package automatically tracks dependencies declared with `@uses`:

```php
/**
 * @functional
 * This service processes user payments.
 * 
 * @uses \App\Models\User
 * @uses \App\Services\PaymentGateway
 * @uses \App\Services\EmailService
 */
class PaymentService
{
    // Implementation...
}
```

This creates visual dependency graphs and "Used By" sections in your documentation.

### Markdown Support

Full Markdown support in your functional descriptions:

- Headers (automatically demoted by one level)
- Lists and nested lists
- Code blocks with syntax highlighting
- Mermaid diagrams
- Links and images

### Python Dependencies Management

The package uses `uv` to automatically manage Python dependencies. The following MkDocs packages are automatically installed when needed:

- **mkdocs-material**: The Material theme for MkDocs
- **pymdown-extensions**: Python Markdown extensions for advanced features

No manual Python environment setup is required - `uvx` handles everything automatically.

## Requirements

- PHP 8.2 or higher
- Laravel 10.0, 11.0, or 12.0
- uv (for managing Python dependencies)

## Testing

Run the test suite:

```bash
composer test

# Or run with coverage
./vendor/bin/pest --coverage

# Run specific test suites
./vendor/bin/pest tests/Unit
./vendor/bin/pest tests/Feature
```

## Troubleshooting

### Common Issues

**No documentation nodes found**
- Ensure your PHPDoc comments include the `@functional` annotation
- Check that your paths are correctly configured in `config/docs.php`
- Verify that your PHP files are syntactically correct

**Build fails**
- Ensure uv is installed and accessible in your PATH
- Check that you have internet access for downloading Python packages
- Verify that the output directory has proper permissions

**Empty documentation pages**
- Make sure your `@functional` blocks contain actual content
- Check that your markdown formatting is correct

## Examples

See the `src/Console/Commands/GenerateMKDocsCommand.php` file itself for a real example of functional documentation.

## Contributing

We welcome contributions! Please feel free to submit a Pull Request.

### Development Setup

1. Clone the repository
2. Install dependencies: `composer install`
3. Run tests: `composer test`
4. Follow PSR-12 coding standards

### Ideas/Roadmap

- [ ] Custom themes and styling options
- [ ] API documentation extraction
- [ ] Automated deployment to GitHub Pages

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

### Security

If you discover any security related issues, please email engineering@xentral.com instead of using the issue tracker.

## Credits

- [Manuel Christlieb](https://github.com/bambamboole)
- [Fabian Koenig](https://github.com/fabian-xentral)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
