# Contributing to Stats Umami

Thanks for your interest! This repository is the full source of the Stats Umami WordPress plugin.

## Reporting issues

- **Bugs / questions:** [open an issue](../../issues) with clear steps to reproduce, your WordPress,
  PHP, and Umami versions, and any relevant integration plugin (WooCommerce / Elementor / CF7 / WPForms).
- **Security vulnerabilities:** do **not** open a public issue — follow [SECURITY.md](SECURITY.md).

## Development setup

Requirements: PHP 7.4+, Composer, Node 18+/npm, and (for integration tests) a local WordPress + MySQL.

```bash
composer install      # PHP dev dependencies (PHPUnit, PHPCS, PHPStan, Plugin Check helpers)
npm install           # JS dev dependencies (@wordpress/scripts / Jest)
npm run build         # build the Gutenberg block (blocks/build/) from blocks/src/
```

## Quality gates (all must pass)

```bash
composer lint             # WordPress Coding Standards (PHPCS)
composer compat           # PHP cross-version compatibility sniff
composer stan             # PHPStan static analysis
composer test             # PHPUnit unit tests
composer test-integration # PHPUnit integration tests (needs a WP test DB)
npm run test-unit-js      # JavaScript unit tests
```

Please also run WordPress **Plugin Check** against the built zip (not the dev tree) — it must report
zero rows including warnings. Build the zip with `bin/build-zip.sh`.

## Conventions

- **WordPress floor 6.0, PHP floor 7.4** — use no API newer than these.
- Security first: nonces, capability checks, input sanitization, output escaping.
- Fully translatable (text domain `stats-umami`); regenerate `languages/stats-umami.pot` if you change
  a translatable string.
- Prefer simple, readable, idiomatic WordPress over clever abstractions.
- **Every bug fix ships with a regression test** that fails on the pre-fix code and passes after it.

## Pull requests

Branch off `main`, keep changes focused, ensure all gates above are green, and describe what you
changed and how you verified it. Thank you!
