# Stats Umami

**Connect WordPress to your self-hosted [Umami](https://umami.is) analytics — cookie-free, privacy-friendly, and entirely client-side.**

Stats Umami injects the official Umami tracker into your site's front end so that **your own** Umami instance records the analytics. No data is ever sent to nquare or umamiwp.com: your visitors' browsers talk directly to your Umami server. There are no server-to-server calls, no Umami credentials stored on your site, and no custom database tables.

- 🔒 **Private by design** — cookie-free, no personal data leaves your site to us.
- 🧩 **Integrations out of the box** — Gutenberg, Contact Form 7, WPForms, WooCommerce, Elementor.
- ⚡ **Performance tracking** — Core Web Vitals (LCP, INP, CLS, FCP, TTFB) via Umami 3.x.
- 🛠️ **Developer API** — `window.statsUmami.track(name, data)` for your own custom events.

> Umami is a third-party open-source project. Stats Umami credits and thanks the Umami team and is **not** affiliated with or endorsed by Umami. Built and maintained by [nquare](https://nquare.pt) at [umamiwp.com](https://umamiwp.com).

## Features

- Simple connection panel — enter your Umami host URL and Website ID, turn on tracking, and you're live.
- Per-role exclusion — choose which roles (e.g. Administrator) are never tracked.
- Automatic tracking of link clicks, outbound links, buttons, form submissions, and comment submissions — each individually toggleable.
- Performance / Web Vitals tracking (opt-in).
- A JavaScript developer API, `window.statsUmami.track(name, data)`, for custom events.
- **Gutenberg** — track clicks on core Button blocks with a custom event name and data from the block editor.
- **Contact Form 7** and **WPForms** — fire a custom event on successful form submission.
- **WooCommerce** — a single `purchase` event with revenue, currency, and product info for Umami's revenue reports; fully compatible with High-Performance Order Storage (HPOS).
- **Elementor** — track clicks on Elementor buttons as events, even when general link tracking is off.
- A WordPress dashboard widget showing connection status at a glance, with a link to your Umami share URL.
- Advanced options — script loading (defer/async), a global event tag, URL query/hash stripping, a domains allowlist, Do Not Track honouring, and more, each mapped to a native Umami tracker attribute.

## Requirements

- WordPress **6.0+** (tested up to 7.0)
- PHP **7.4+**
- A working self-hosted **Umami v3.x** instance. Stats Umami is a bridge to Umami — it does not include or bundle Umami itself.

## Installation

1. Download the latest `stats-umami.zip` from [umamiwp.com](https://umamiwp.com) (or from the [Releases](../../releases) here).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip, install, and activate.
3. Open **Stats Umami** in the admin menu. On the **General** tab, enter your Umami **host URL** (e.g. `https://analytics.example.com`) and your **Website ID** (a UUID from your Umami dashboard).
4. Turn on **tracking** and save.
5. Verify while logged out or in a private window (administrators are excluded by default): your browser's dev tools should show the Umami script load and a successful request to your Umami server.

## Developer API

Track your own events from JavaScript:

```js
window.statsUmami.track( 'newsletter_signup', { plan: 'pro' } );
```

Call it after the page has loaded (e.g. on a user interaction) — it does not queue events fired before the Umami tracker is ready. See the plugin's **Tools** tab and the FAQ in `readme.txt` for details and data limits.

## Building from source

This repository is the full plugin source. To build a distributable zip or run the tests, see [CONTRIBUTING.md](CONTRIBUTING.md). In short: `composer install && npm install`, then `composer test` / `npm run test-unit-js`, and `bin/build-zip.sh` to produce the release zip.

## Support

Found a bug or have a question? Please [open an issue](../../issues). For **security** reports, do **not** open a public issue — see [SECURITY.md](SECURITY.md).

## Credits & license

With thanks to the [Umami](https://umami.is) team for the open-source analytics software this plugin connects to. Stats Umami is an independent plugin, not affiliated with Umami.

Licensed under **GPLv2 or later**. See [LICENSE](LICENSE).
