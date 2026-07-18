# Contributing to Photolab

Thanks for helping build Photolab for WooCommerce. All contributions — code, docs, bug reports, feature ideas — are welcome.

## Reporting Bugs

1. Search [existing issues](https://github.com/todotge/photolab/issues) first.
2. Open a bug report using the template. Include:
   - WordPress version
   - WooCommerce version
   - PHP version
   - Steps to reproduce
   - Expected vs actual behavior

## Feature Requests

Open a feature request using the template. Describe the problem and proposed solution.

## Pull Requests

1. Fork the repository.
2. Create a feature branch: `git checkout -b feature/your-feature`.
3. Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/).
4. Run quality checks before committing:
   ```bash
   composer run phpcs
   composer run phpstan
   composer run test
   npm run build:css
   ```
5. Commit with clear messages following [Conventional Commits](https://www.conventionalcommits.org/).
6. Push and open a PR against `main`.

## Code Standards

- Namespace: `Photolab`
- PHPDoc on all classes, methods, and properties
- Use `$wpdb->prepare()` for all SQL queries
- No direct `wp_posts` access for WooCommerce products — use `wc_get_product()`
- Tailwind CSS: local build only, never CDN

## Development Setup

```bash
composer install
npm ci
npm run build:css
```

## Testing

```bash
# Unit tests (no WP/WC required)
composer run test -- --testsuite unit

# Integration tests (requires MySQL + WP + WC)
composer run test -- --testsuite integration
```

## License

By contributing, you agree that your contributions will be licensed under the [GPLv2](LICENSE).
