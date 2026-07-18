# Changelog

All notable changes to Stats Umami are documented here. This project follows
[Semantic Versioning](https://semver.org/). The canonical changelog also ships in `readme.txt`.

## [1.1.0] — 2026-07-17

### Added
- **Elementor integration** — detected automatically, on by default. When on, Elementor Button
  widgets are tracked as link events (with their destination URL) even when the general
  "Link clicks" option is off. The tracker holds no Elementor-specific CSS/DOM knowledge; a
  generic marker attribute drives the behavior.

## [1.0.0] — 2026-07-14

### Added
- First public release.
- Core tracker with per-role exclusion, performance/Web Vitals tracking, and advanced `data-*` options.
- Automatic tracking of links, outbound links, buttons, forms, and comments (each toggleable).
- JavaScript developer API: `window.statsUmami.track(name, data)`.
- Gutenberg core/Button block event tracking.
- Contact Form 7 and WPForms submit-event tracking.
- WooCommerce purchase/revenue tracking with HPOS support.
- WordPress dashboard status widget with a link to your Umami share URL.

[1.1.0]: https://github.com/devnquare/stats-umami/releases/tag/v1.1.0
[1.0.0]: https://github.com/devnquare/stats-umami/releases/tag/v1.0.0
