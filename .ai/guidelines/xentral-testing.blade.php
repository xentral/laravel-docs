# Testing conventions

- Check the project conventions to use whether PHPUnit or Pest
- Prefer database backed integration tests over unit tests.
- Each Eloquent model has to have a fully configured DatabaseFactory for a easier way of testing implementations.
- Enrich factories with modifiers to enable easy customisations.
- Each endpoint needs to have a PHPUnit test class with at least the following tests:
- Request without token
- Request with token but missing scopes (scopes are defined in config/token_scopes.php)
- Includes
- Filters on list endpoints
- Validation Rules on endpoints with request body

- Each endpoint test class needs to have a Behat feature file with at least one test
- Each Endpoint test class needs to have OpenAPI check on by default via the ValidatesOpenApiSpec trait.
- Each Endpoint test class needs to have OpenAPI schema file defined via the SchemaFile attribute.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When asked to write tests, check the diff of how code was changed, then update the tests accordingly and write new tests if needed to cover new functionality.
- Use #[DataProvider] attributes for data-driven tests.
- Define array keys in data providers instead of inline comments.
