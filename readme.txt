=== Stats Umami ===
Contributors: nquare
Tags: analytics, umami, privacy, statistics, web-analytics
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to your self-hosted Umami v3.x for cookie-free, privacy-friendly analytics with events for forms, blocks, and WooCommerce.

== Description ==

Stats Umami connects your WordPress site to your own self-hosted [Umami](https://umami.is) analytics instance. It injects the official Umami tracker script into your site's front end so that **your** Umami installation records the analytics - not us. No data is ever sent to nquare or umamiwp.com.

Umami is open-source, cookie-free, and privacy-friendly by design. This plugin is purely client-side: your browser talks directly to your Umami server, there are no server-to-server calls, no Umami credentials are stored on your site, and no custom database tables are created.

= Features =

* Simple connection panel: enter your Umami host URL and Website ID, turn on tracking, and you're live.
* Per-role exclusion - choose which roles (e.g. Administrator) are never tracked.
* Automatic tracking of link clicks, outbound links, buttons, form submissions, and comment submissions - each individually toggleable.
* Performance / Web Vitals tracking (opt-in) - LCP, INP, CLS, FCP, TTFB via Umami 3.x.
* A JavaScript developer API, `window.statsUmami.track(name, data)`, for tracking your own custom events.
* Gutenberg integration - track clicks on core Button blocks with a custom event name and data straight from the block editor.
* Contact Form 7 and WPForms integration - fire a custom event whenever a form is submitted successfully.
* WooCommerce integration - a single `purchase` event with revenue, currency, and product info feeds Umami's revenue reports; fully compatible with High-Performance Order Storage (HPOS).
* Elementor integration - track clicks on Elementor buttons as events, even when general link tracking is off.
* A WordPress dashboard widget showing your connection status at a glance, with a direct link to your Umami share URL.
* Advanced options: custom script loading (defer/async), a global event tag, URL query/hash stripping, a domains allowlist, Do Not Track honouring, and more - each mapped to a native Umami tracker attribute.

Requires a working self-hosted [Umami](https://umami.is) v3.x instance - Stats Umami does not include or bundle Umami itself.

= Credits =

With thanks to the [Umami](https://umami.is) team for the open-source analytics software this plugin connects to - Stats Umami would not exist without their work. Stats Umami is an independent plugin, not affiliated with or endorsed by Umami. Built and maintained by [nquare](https://nquare.pt) at [umamiwp.com](https://umamiwp.com).

== Installation ==

1. Install and activate the plugin (via Plugins → Add New, or by uploading the zip).
2. In your WordPress admin menu, go to **Stats Umami**.
3. On the General tab, enter your Umami host URL (e.g. `https://analytics.example.com`) and your Website ID (a UUID, found in your Umami dashboard under the website's settings).
4. Turn on **tracking**, then click Save changes.
5. To check it's working, open your site while logged out or in a private/incognito window - administrators are excluded from tracking by default. In your browser's dev tools you should see the Umami script load and a request to your Umami server succeed.

You will need a self-hosted Umami instance before you can connect - Stats Umami is a bridge to Umami, not an analytics platform in itself.

== Frequently Asked Questions ==

= Do I need my own Umami server? =

Yes. Stats Umami connects to a self-hosted Umami instance that you control - it does not include or host analytics itself. See [umami.is](https://umami.is) to get started.

= Does any data go to nquare or umamiwp.com? =

No. Stats Umami is entirely client-side: the tracker script in your visitors' browsers sends data directly to **your own** Umami instance. Nothing passes through, or is stored on, any server operated by nquare or umamiwp.com.

= My Umami is installed in a subfolder, or I renamed the tracker script - what do I enter for Host URL? =

Enter the full script URL instead of just the host. Umami's tracker script name does not need to end in `.js` and can be any path you choose - for example `https://example.com/analytics/app` or `https://example.com/stats/script.js`. If your Umami installation lives at a nested base path and you have not renamed the script, make sure the Host URL ends with a trailing slash (e.g. `https://example.com/analytics/`) so Stats Umami knows it's a folder, not a script name.

= The status says "Connected & tracking" but I don't see any data in Umami =

A few common causes: (1) your own account's role may be excluded from tracking by default - Administrator, Editor, and Shop Manager are excluded out of the box on the Advanced tab, so test as a logged-out visitor or in a private/incognito window; (2) an ad blocker or privacy extension in your browser may be blocking the tracker script or its requests; (3) double-check the Host URL - if Umami is in a subfolder or the tracker script was renamed, see the FAQ above.

= Does it work with WooCommerce High-Performance Order Storage (HPOS)? =

Yes. Stats Umami declares full compatibility with HPOS and uses WooCommerce's order API (not raw post meta) to track purchases, so it works correctly whether HPOS is enabled or not.

= When exactly is a purchase counted? =

When WooCommerce considers the order paid - that is, once its status is Processing or Completed. Orders awaiting an offline payment (bank transfer, cheque, and similar "pay later" methods) are not counted at checkout time, since they aren't paid yet; they are counted if and when the customer returns to the order-received page after the order has been marked paid. Marking an order Completed is a fulfilment step, not what triggers the event - an order already sitting in Processing is already paid and already counted.

= What happens if I refund an order? =

The purchase event was already sent to Umami at the time of the sale, and Stats Umami is purely client-side - it has no way to retract an event once sent, and Umami's tracker doesn't offer one either. A refunded order's revenue therefore stays in your Umami revenue report. Refunding an order does not send a second event.

= Does full-page caching affect WooCommerce purchase tracking? =

If your site uses a full-page caching plugin, make sure your order-received (thank you) page is excluded from the cache. A cached copy of that page would replay the same purchase event to every later visitor who happens to land on it. Most caching plugins already exclude WooCommerce cart, checkout, and account pages by default - just double-check the order-received page specifically.

= Does WordPress's speculative loading (Speculation Rules, WP 6.8+) affect WooCommerce purchase tracking? =

Rarely, and only in one specific way. Stats Umami already ignores prefetch requests for the order-received page, so a prefetch alone never consumes the one-shot tracking flag. But if the browser later reuses that prefetched response to serve the actual page view (no second request is made), the visitor sees a page with no purchase event - the flag stays unburned, so a reload of the same page still tracks it correctly. This is a bounded, self-healing edge case: the order-received page is reached via a checkout redirect, which is rarely a prefetchable link in the first place.

= Which form plugins are supported? =

Contact Form 7 and WPForms. A successful submission of either fires a custom Umami event that you name and configure from the form's own editor - a validation error or a failed submission never fires it.

= Does automatic click tracking work with page builders? =

Yes - the tracker script and page-view tracking work with any theme or page builder. Elementor is a detected integration (Events & integrations tab, on by default when Elementor is active): Elementor buttons are tracked automatically as link events with their destination URL, even when "Link clicks" is off. For other builders:

* Page builders usually render their "button" elements as links rather than native HTML buttons. To record clicks on them, turn on "Link clicks" (and, for buttons that point to another site, "Outbound links") on the Events & integrations tab - they are recorded as link events with the destination URL. The "Button clicks" option covers native HTML buttons and form controls.
* Interactive widgets that only change what's shown on the page without navigating - accordions, tabs, toggles, sliders - are not recorded as events. (Some image lightboxes are built as a plain link to the image file; those are recorded as a link click, with the image URL, when "Link clicks" is on.) To track a widget that fires nothing, send your own event with the developer API (window.statsUmami.track).

= How do I use the JavaScript developer API? =

Call window.statsUmami.track( 'event_name', { key: 'value' } ) from your own JavaScript to send a custom event - for example, to track a widget that fires nothing on its own. A few things to know:

* It runs on the front end for visitors who are actually tracked. Excluded roles (for example administrators) get no tracker and no API, so test while logged out or in a private/incognito window.
* Call it after the page has loaded, or on a user interaction. Events fired before the Umami tracker has loaded are not queued, so a call at the very top of the page can be missed.
* The event name is capped at 50 characters. Your data object is passed to Umami as-is and is subject to Umami's own data limits.

= Is it GDPR- and cookie-friendly? =

Umami itself is cookie-free by design, and you control your own Umami instance and its data retention. Stats Umami adds no cookies of its own. As with any analytics tool, you remain responsible for your own privacy notice and compliance.

== Screenshots ==

1. The Stats Umami settings screen - General, Events & integrations, Advanced, and Tools & Support tabs.
2. The WordPress dashboard status widget, showing connection status at a glance.
3. An event landing in your own Umami dashboard.

== Changelog ==

= 1.1.0 =
* Elementor integration - detected automatically, on by default. When on, Elementor Button widgets are tracked as link events (with their destination URL) even when the general "Link clicks" option is off.

= 1.0.0 =
* First public release.
* Core tracker with per-role exclusion, performance/Web Vitals tracking, and advanced `data-*` options.
* Automatic tracking of links, outbound links, buttons, forms, and comments.
* JavaScript developer API: `window.statsUmami.track(name, data)`.
* Gutenberg core/Button block event tracking.
* Contact Form 7 and WPForms submit-event tracking.
* WooCommerce purchase/revenue tracking with HPOS support.
* WordPress dashboard status widget with a link to your Umami share URL.

== Upgrade Notice ==

= 1.1.0 =
On an Elementor site, Elementor button clicks begin tracking automatically as link events - turn off the new Elementor switch on the Events & integrations tab if you don't want this.

= 1.0.0 =
First public release.
