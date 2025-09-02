# Laravel Docs - Functional Documentation Guidelines

This project uses the `xentral/laravel-docs` package to automatically generate documentation from PHPDoc comments using the `@functional` annotation.

## How to Write Functional Documentation

### Basic Structure

```php
/**
 * Brief summary of the class/method
 * 
 * @functional
 * Detailed description of the functionality. This can span multiple lines
 * and supports full Markdown formatting.
 * 
 * # Features
 * - Feature 1: Description
 * - Feature 2: Description
 * 
 * @nav Navigation / Path / For This Doc
 * @uses \App\Models\User
 * @uses \App\Services\EmailService
 * @link https://example.com/related-docs
 */
```

### Required Annotations

- **`@functional`**: Must be present to include the documentation in generation
- All other annotations are optional but recommended

### Optional Annotations

- **`@nav Path / To / Documentation`**: Defines where this appears in the navigation
- **`@uses \Fully\Qualified\ClassName`**: Links to dependencies for relationship mapping
- **`@link URL`** or **`@links [Title](URL)`**: External references

### Best Practices

1. **Be Descriptive**: Explain the "why" and "how", not just the "what"
2. **Use Markdown**: Headers, lists, code blocks, and links are all supported
3. **Group Related Items**: Use consistent navigation paths for related functionality
4. **Document Dependencies**: Use `@uses` to show relationships between components
5. **Add Examples**: Include code examples where helpful

### Navigation Structure

Organize your documentation using consistent navigation paths:

```
Authentication/
├── Login Process
├── Password Reset
└── Session Management

User Management/
├── User Registration
├── Profile Updates
└── Account Deletion
```

### Markdown Features

Full Markdown support includes:

- **Headers** (automatically demoted by one level)
- **Lists** and nested lists
- **Code blocks** with syntax highlighting
- **Mermaid diagrams** for flowcharts
- **Links** and images
- **Tables** and formatting

### Example Documentation

```php
/**
 * Payment processing service
 * 
 * @functional
 * This service handles all payment operations including processing payments,
 * refunds, and subscription management. It integrates with multiple payment
 * gateways and provides a unified interface for payment operations.
 * 
 * # Key Features
 * - **Multi-gateway Support**: Stripe, PayPal, Square
 * - **Subscription Management**: Recurring billing and plan changes
 * - **Fraud Protection**: Built-in fraud detection and prevention
 * 
 * ## Usage Example
 * 
 * ```php
 * $payment = $paymentService->processPayment([
 *     'amount' => 2999, // $29.99
 *     'currency' => 'USD',
 *     'customer_id' => $user->id,
 * ]);
 * ```
 * 
 * ## Error Handling
 * 
 * The service throws specific exceptions for different failure modes:
 * - `PaymentDeclinedException` for declined cards
 * - `InsufficientFundsException` for NSF situations
 * - `InvalidPaymentMethodException` for invalid payment methods
 * 
 * @nav Payments / Payment Processing
 * @uses \App\Models\User
 * @uses \App\Models\PaymentMethod
 * @uses \App\Services\FraudDetectionService
 * @link https://stripe.com/docs/api
 */
```

## Generating Documentation

```bash
# Generate documentation
php artisan mkdocs:generate

# Serve documentation locally
php artisan mkdocs:serve

# Generate with custom output path
php artisan mkdocs:generate --path=/custom/docs/path
```

## Configuration

Configure paths to scan in `config/docs.php`:

```php
'paths' => [
    app_path(),
    base_path('packages/'),
    // Add other directories to scan
],
```

---

For more information, see the package documentation at https://github.com/xentral/laravel-docs
