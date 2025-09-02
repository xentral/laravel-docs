# Laravel Docs

[![Latest Version on Packagist](https://img.shields.io/packagist/v/xentral/laravel-docs.svg?style=flat-square)](https://packagist.org/packages/xentral/laravel-docs)
[![Total Downloads](https://img.shields.io/packagist/dt/xentral/laravel-docs.svg?style=flat-square)](https://packagist.org/packages/xentral/laravel-docs)
![GitHub Actions](https://github.com/xentral/laravel-docs/actions/workflows/main.yml/badge.svg)

A Laravel package that automatically generates beautiful documentation from your PHPDoc comments using the `@functional` annotation. It extracts functional documentation from your codebase and generates a modern, searchable MkDocs website.

## Features

- 🔍 **Automated extraction** of functional documentation from PHPDoc comments
- 📚 **MkDocs integration** with Material Design theme
- 🎯 **Selective documentation** using `@functional` annotation
- 🔗 **Dependency tracking** and visualization with Mermaid diagrams
- 📱 **Responsive documentation** with navigation and search
- 🐳 **Docker support** for building and serving documentation
- ⚡ **Laravel commands** for easy integration

## Installation

Install the package via Composer:

```bash
composer require --dev xentral/laravel-docs
```

The package will automatically register its service provider and commands.

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
    'commands' => [
        'build' => 'docker run --rm -v {path}:/docs squidfunk/mkdocs-material build',
        'serve' => [
            'docker', 'run', '--rm', '-it',
            '-p', '{port}:{port}',
            '-v', '{path}:/docs',
            'polinux/mkdocs',
        ],
    ],
    'config' => [
        'site_name' => 'Your Project Documentation',
        'theme' => ['name' => 'material'],
        // ... additional MkDocs configuration
    ],
];
```

## Usage

### Writing Functional Documentation

Add the `@functional` annotation to your PHPDoc comments to mark them for extraction:

```php
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
     * @nav Authentication / Login Process
     * @uses \App\Models\User
     */
    public function authenticate(array $credentials): bool
    {
        // Implementation...
    }
}
```

### Available Annotations

- **`@functional`**: Marks the documentation block for extraction (required)
- **`@nav`**: Sets the navigation path (e.g., "Authentication / User Service")
- **`@uses`**: Links to dependencies that will be cross-referenced
- **`@link`**: Adds external links to the documentation
- **`@links`**: Alternative syntax for links

### Generate Documentation

Generate your documentation using the Artisan command:

```bash
# Generate documentation in the default output directory
php artisan mkdocs:generate

# Generate documentation in a custom directory
php artisan mkdocs:generate --path=/path/to/docs
```

### Serve Documentation Locally

Start a local development server to preview your documentation:

```bash
# Serve on default port (9090)
php artisan mkdocs:serve

# Serve on custom port
php artisan mkdocs:serve --port=8080

# Serve from custom path
php artisan mkdocs:serve --path=/path/to/docs --port=3000
```

The documentation will be available at `http://localhost:9090`

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

### Docker Integration

The package comes with pre-configured Docker commands for MkDocs:

- **Build**: `docker run --rm -v {path}:/docs squidfunk/mkdocs-material build`
- **Serve**: Uses `polinux/mkdocs` with live reload

## Requirements

- PHP 8.2 or higher
- Laravel 10.0, 11.0, or 12.0
- Docker (for building and serving documentation)

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

**Docker build fails**
- Ensure Docker is running
- Check that the configured Docker image is available
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
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
