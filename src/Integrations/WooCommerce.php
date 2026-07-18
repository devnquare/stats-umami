<?php
/**
 * WooCommerce integration: fires a single "purchase" Umami event, carrying
 * revenue + currency + modest product info, on the order-received (thank
 * you) page - idempotent via the order CRUD API so it stays correct under
 * High-Performance Order Storage (HPOS).
 *
 * @package StatsUmami
 */

namespace StatsUmami\Integrations;

use StatsUmami\Frontend\Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Idempotency: `_stats_umami_woo_tracked` order meta, read/written via the
 * order CRUD API (`$order->get_meta()`/`update_meta_data()`/`save()`), NOT
 * `*_post_meta()` - under HPOS an order is not a `wp_posts` row, so raw post
 * meta silently misses it (docs/DECISIONS.md [D4]).
 *
 * JS-dependency tradeoff (OLD-PLUGIN-INVENTORY §12 defect #5, carried over
 * deliberately): the flag is set only once the purchase event has actually
 * been printed to a request that passed Tracker::should_output() (see the
 * FLAG TIMING note below) - it does NOT confirm the buyer's browser actually
 * executed the tracking call (JS disabled, an ad-blocker). If the buyer's JS
 * never runs, the purchase is under-counted and this does NOT retry. The
 * alternative (an AJAX callback confirming the event actually fired) would
 * need a REST/AJAX route this client-side-only plugin deliberately doesn't
 * have. We accept this bounded under-count in exchange for a guaranteed
 * no-double-count on refresh.
 *
 * PAID-ONLY (DECISIONS 2026-07-04 "WooCommerce
 * purchase event fires on PAID statuses only"): the event only ever fires
 * for an order that is actually paid, per WooCommerce's own
 * `$order->is_paid()` (processing/completed + any custom paid statuses via
 * `woocommerce_order_is_paid_statuses`). Non-paid states at thank-you time
 * (pending/on-hold/failed/cancelled/refunded) are skipped, and - because the
 * flag is only written once output actually happens (see FLAG TIMING) - an
 * order that later becomes paid and whose order-received page is revisited
 * can still fire once. Offline-payment orders (BACS/cheque/COD) that never
 * get a revisit after paying are an accepted, honest limitation of
 * client-side thank-you-page tracking.
 *
 * HOOK REGISTRATION (LIVE-INVESTIGATED): registered on BOTH
 * `woocommerce_before_thankyou` and `woocommerce_thankyou`, not just the
 * latter. Empirically confirmed on this project's WP 6.9.4 + WooCommerce
 * 10.9.3 + a full-site-editing block theme (Twenty Twenty-Five) with a
 * default (uncustomized) block-based Checkout page and the default
 * WooCommerce-registered `order-confirmation` block template:
 * - The modern, TRUE blockified Order Confirmation template (matched via
 *   WooCommerce's `page_template_hierarchy` injection ahead of the classic
 *   Checkout page content - `Blocks\Templates\OrderConfirmationTemplate`)
 *   renders the `woocommerce/order-confirmation-status` block, which itself
 *   calls `do_action_ref_array( 'woocommerce_before_thankyou', [$order_id] )`
 *   (`Blocks\BlockTypes\OrderConfirmation\Status::render_content()`), AND the
 *   `woocommerce/order-confirmation-additional-information` block, which
 *   calls `do_action_ref_array( 'woocommerce_thankyou', [$order_id] )`
 *   (`Blocks\BlockTypes\OrderConfirmation\AdditionalInformation::render_content()`).
 *   Both fired, live, with the real order id, confirmed via a temporary
 *   hook-firing probe against a real created order - the rendered markup was
 *   the genuine blockified confirmation (`wc-block-order-confirmation-status`
 *   etc. classes), not a classic-template fallback.
 * - The classic/shortcode path (`templates/checkout/thankyou.php`, reached
 *   either via the literal `[woocommerce_checkout]` shortcode or via the
 *   Checkout BLOCK's own `is_checkout_endpoint()` fallback to that shortcode
 *   for themes/setups without a matching block template) calls BOTH hooks
 *   unconditionally and in the same order (`woocommerce_before_thankyou` then
 *   `woocommerce_thankyou`) - already live-verified in Phase 3.8.
 * Registering on both hooks is deliberately redundant: it is the union of
 * every path that has been observed or can be reasoned about from source,
 * including the one plausible drift case (a site owner customizes the block
 * template and removes the "Additional information" block, which is the
 * only thing that fires `woocommerce_thankyou` on that template - the
 * "Status" block firing `woocommerce_before_thankyou` is virtually always
 * present, since it IS the confirmation message itself). A double fire in
 * the same request is harmless by construction - see FLAG TIMING.
 *
 * FLAG TIMING (restructured from an earlier
 * design): `maybe_track()` (hooked on both hooks above, fired during content
 * rendering) never writes the idempotency flag and never assumes output will
 * happen - it only decides WHETHER an event is due and stashes the order id
 * + built event data in a static pending slot for `print_pending_event()`
 * (hooked on `wp_footer`, priority 21, see OUTPUT TIMING below) to actually
 * print - and it is print_pending_event() that writes the flag, and ONLY
 * once it has actually printed. This means:
 * - A request that reaches `maybe_track()` but where `should_output()` later
 *   evaluates false at print time (master switch off, host/website not
 *   configured, or a role-excluded viewer) never burns the flag, so a later
 *   request where output legitimately happens can still fire once.
 * - `maybe_track()` re-checks `get_meta()` once at the top (skip an
 *   already-tracked order outright) and `print_pending_event()` re-checks it
 *   again right before `save()` (a second, narrower check closer to the
 *   write) - narrowing, not eliminating, the read-modify-write race between
 *   two overlapping renders of the same order-received page;
 *   full elimination would need a locking primitive this plugin doesn't
 *   otherwise need. Double-registration on two hooks firing in the same
 *   request is safe under this design too: the first call builds and stashes
 *   the pending event (an idempotent rebuild if it fires again in the same
 *   request, since `save()` hasn't happened yet), and `print_pending_event()`
 *   still only prints and flags once, at `wp_footer`.
 * - A prefetch/speculative request (`Sec-Purpose: prefetch`, or the older
 *   `Purpose`/`X-Purpose: prefetch` headers) is skipped in
 *   `maybe_track()` before anything is built or stashed, so a browser
 *   prefetching the order-received URL can never consume the one-shot flag
 *   ahead of the visitor's real navigation.
 *
 * OUTPUT TIMING (two real bugs found + fixed via live E2E on WP 6.9.4's
 * default block theme; the flag-timing restructuring above changed
 * WHEN the flag is written but not this section's reasoning about WHERE/HOW
 * the event is printed):
 *
 * 1. HOOK-VS-ENQUEUE ORDER. The thank-you hooks fire from WITHIN content
 *    rendering, but WHEN that happens relative to `wp_enqueue_scripts`/
 *    `wp_head` is NOT the same on every theme. On a classic theme, content
 *    renders after `wp_head`, so `wp_add_inline_script()` on the frontend.js
 *    handle at hook-fire time works fine. On a WordPress BLOCK theme (the
 *    modern default - e.g. Twenty Twenty-Five), the WHOLE page's block
 *    content - including the order-confirmation blocks' output - is rendered
 *    into a string BEFORE `wp_head`/`wp_enqueue_scripts` ever fires (block
 *    themes assemble the full HTML document via `template-canvas.php`, which
 *    calls `wp_head()` then echoes the ALREADY-rendered body, then calls
 *    `wp_footer()`). Confirmed live: `did_action('wp_enqueue_scripts')` was
 *    `0` inside `maybe_track()` on this theme, so `wp_add_inline_script()`
 *    against a not-yet-registered handle silently no-op'd. FIX: stash the
 *    built event data (now: + the order id) in a static property and print it
 *    directly in `wp_footer` (priority 21, strictly after WordPress core's own
 *    `wp_print_footer_scripts` at priority 20 - see
 *    wp-includes/default-filters.php - so it textually follows frontend.js's
 *    `<script src>` tag). `wp_footer` always fires last, in both classic and
 *    block themes, regardless of when the content string was computed - the
 *    one ordering guarantee this design can actually rely on.
 *    `should_output()` is checked at print time rather than at
 *    maybe_track() time, since that is the point that actually decides
 *    whether browser-facing output happens (and, per FLAG TIMING above, also
 *    the point that decides whether the idempotency flag gets written).
 *
 * 2. SCRIPT-READINESS RACE (supersedes an earlier
 *    DOMContentLoaded-deferral fix; the poll predicate was corrected twice
 *    since). That earlier
 *    fix wrapped the track() call in a DOMContentLoaded deferral, which is
 *    correct only when the Umami tracker `<script>` uses `defer` (this
 *    plugin's default): deferred scripts execute in order right before
 *    `DOMContentLoaded`, so waiting for that event guarantees `window.umami`
 *    is defined by the time our call runs. But `script_loading=async` is a
 *    supported, non-default option, and `async` scripts do NOT participate in
 *    that ordering guarantee at all - nor does a delay-JS/optimizer plugin
 *    deferring the tracker script further. In either case `window.umami` can
 *    still be undefined at DOMContentLoaded, and the purchase is lost even
 *    though the flag gets written. FIX: replace the one-shot DOMContentLoaded
 *    wait with a bounded READINESS POLL - check every ~200ms for up to ~10s
 *    that `window.umami.track` is ready, firing `window.umami.track(...)`
 *    the moment it is (wrapped in a local try/catch to keep the never-throw
 *    contract) and giving up silently if the timeout elapses.
 *
 *    An intermediate revision changed this to poll for BOTH `window.umami.track` AND
 *    `window.statsUmami.track` (this plugin's OWN frontend.js) and call
 *    THROUGH `window.statsUmami.track()`, reasoning that frontend.js itself
 *    is not guaranteed to have already run by the time this footer script
 *    starts polling. **That was later reversed**: the purchase call is entirely
 *    server-built (a fixed `'purchase'` event + already-sanitized order
 *    data, no request input) and needs none of `window.statsUmami.track()`'s
 *    wrapping (a 50-char name clamp + guard that exist for arbitrary
 *    caller-supplied event names, not this fixed one), so requiring it added
 *    a dependency on `frontend.js` without adding any real safety - and that
 *    dependency was exactly backwards for the intent behind it: a
 *    script-blocker that blocks a file literally named "frontend.js" while a
 *    RENAMED Umami tracker loads fine leaves `window.statsUmami` forever
 *    undefined, so the poll exhausts its budget and the purchase is lost
 *    even though the flag is already burned - the precise failure that
 *    intermediate revision set out to describe. Calling `window.umami.track` directly and polling only for
 *    it is strictly more resilient for that renamed-tracker/blocked-
 *    frontend.js audience, while keeping the real invariant ("test the same
 *    object the call invokes").
 *
 *    HONEST BOUND (correcting an earlier overclaim in
 *    this docblock): the ~10s/50-attempt ceiling covers `defer` (ready
 *    almost immediately) and `async` (ready whenever that script happens to
 *    finish) well. It does NOT reliably cover delay-JS/optimizer plugins,
 *    which typically gate script execution on the visitor's first
 *    INTERACTION (click/scroll/mousemove), not a fixed timer - a visitor who
 *    reads the order-received page for more than ~10 seconds before
 *    interacting loses the purchase event even though the flag is already
 *    burned. There is no fix for this within a client-side-only design
 *    (raising the ceiling only narrows, never closes, the window); it is a
 *    documented, accepted limitation, not something this poll claims to
 *    solve.
 */
class WooCommerce {

	/**
	 * Order-meta key for the purchase idempotency flag.
	 *
	 * @var string
	 */
	const META_TRACKED = '_stats_umami_woo_tracked';

	/**
	 * Bounded readiness-poll tuning for the footer script's wait on
	 * window.umami (see the class docblock's OUTPUT TIMING point 2):
	 * interval in milliseconds and max attempts, i.e. an ~10s ceiling at the
	 * default 200ms interval.
	 *
	 * @var int
	 */
	const POLL_INTERVAL_MS = 200;

	/**
	 * Max poll attempts before giving up silently - see POLL_INTERVAL_MS.
	 *
	 * @var int
	 */
	const POLL_MAX_ATTEMPTS = 50;

	/**
	 * The order id awaiting output at wp_footer, or null when there is
	 * nothing pending this request. Paired with $pending_event_data - see
	 * the class docblock's FLAG TIMING note for why both are needed (the
	 * flag write at print time re-loads and re-checks the order by id).
	 *
	 * @var int|null
	 */
	private static $pending_order_id = null;

	/**
	 * The built "purchase" event payload awaiting output at wp_footer, or
	 * null when there is nothing to print this request. Static because
	 * maybe_track() (hooked on the thank-you hooks, fired during content
	 * rendering) and print_pending_event() (hooked on wp_footer) are two
	 * separate calls within the same request - see the OUTPUT TIMING note
	 * above for why they can't be merged into one immediate call.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $pending_event_data = null;

	/**
	 * Register this integration's hooks. Called by Integrations\Manager only
	 * when the master switch + enable_woocommerce + the
	 * class_exists('WooCommerce') dependency predicate all pass.
	 *
	 * Registered on BOTH order-confirmation hooks - see the class docblock's
	 * HOOK REGISTRATION note for the live investigation behind this.
	 * Multiple registrations firing in the same request are safe by
	 * construction (see FLAG TIMING).
	 */
	public static function register() {
		add_action( 'woocommerce_before_thankyou', array( __CLASS__, 'maybe_track' ) );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'maybe_track' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_pending_event' ), 21 );
	}

	/**
	 * Hooked on woocommerce_before_thankyou AND woocommerce_thankyou (both
	 * pass the order id): skip prefetch/speculative requests, load the order
	 * via wc_get_order() (HPOS-safe on both storage backends), bail on an
	 * invalid order, a non-paid order, or one already tracked, else build the
	 * purchase event data and stash it (with the order id) for wp_footer
	 * output by print_pending_event(). Never writes the idempotency flag -
	 * see the class docblock's FLAG TIMING note.
	 *
	 * @param int $order_id The order's ID.
	 */
	public static function maybe_track( $order_id ) {
		if ( self::is_prefetch_request() ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! $order->is_paid() ) {
			return;
		}

		if ( $order->get_meta( self::META_TRACKED ) ) {
			return;
		}

		$items = array();

		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
			);
		}

		self::$pending_order_id   = $order_id;
		self::$pending_event_data = self::build_event_data( $order->get_total(), $order->get_currency(), $items );
	}

	/**
	 * Whether the current request looks like a prefetch/speculative
	 * navigation rather than a real visit, per the `Sec-Purpose` header (the
	 * current standard) and the older `Purpose`/`X-Purpose` headers some
	 * browsers/proxies still send. Such a request must not build
	 * or stash a pending event, so it can never consume the one-shot
	 * idempotency flag ahead of the visitor's real navigation.
	 *
	 * @return bool
	 */
	private static function is_prefetch_request() {
		$headers = array( 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE' );

		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- validated by the stripos()/prefetch-substring check immediately below; read-only, no further use.

			if ( false !== stripos( $value, 'prefetch' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the "purchase" event's data payload from plain scalar/array
	 * inputs - deliberately decoupled from WC_Order/WC_Order_Item so this is
	 * unit-testable without a live order. `revenue` is cast to float (a JSON
	 * NUMBER, not a quoted string) so Umami's revenue table accepts it;
	 * `currency` is the order's ISO 4217 code. `product_count`/
	 * `quantity_total`/`product_names` are modest product info alongside the
	 * revenue+currency headline (shape mirrors the old plugin's
	 * `woo:purchase` payload - OLD-PLUGIN-INVENTORY §7.5 - renamed to the new
	 * `purchase` event name per DECISIONS).
	 *
	 * @param mixed                                           $total    Order total.
	 * @param mixed                                           $currency ISO 4217 currency code.
	 * @param array<int, array{name: mixed, quantity: mixed}> $items   Line items (name + quantity).
	 * @return array<string, mixed>
	 */
	public static function build_event_data( $total, $currency, array $items ) {
		$names          = array();
		$quantity_total = 0;

		foreach ( $items as $item ) {
			$name = isset( $item['name'] ) ? trim( (string) $item['name'] ) : '';

			if ( '' !== $name ) {
				$names[] = $name;
			}

			$quantity_total += isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
		}

		return array(
			'revenue'        => (float) $total,
			'currency'       => (string) $currency,
			'product_count'  => count( $items ),
			'quantity_total' => $quantity_total,
			'product_names'  => implode( ', ', $names ),
		);
	}

	/**
	 * Hooked on wp_footer (priority 21, see the class docblock's OUTPUT
	 * TIMING note): print the pending purchase event built by maybe_track()
	 * this request, if any - gated by the same Tracker::should_output()
	 * contract as the tracker script and every other output in this plugin
	 * (master enabled, host/website configured, not a role-excluded visitor).
	 * The idempotency flag is written HERE, only after should_output() passes
	 * and the order is re-confirmed not-yet-tracked (see FLAG TIMING) - never
	 * in maybe_track(). The track() call polls for window.umami's readiness
	 * (superseding an earlier dual-object poll - see OUTPUT
	 * TIMING point 2) rather than assuming a single deferral point, so it
	 * survives `defer`/`async` loading of the Umami tracker script alike -
	 * within its bounded ~10s ceiling (NOT a reliable bound against
	 * interaction-gated delay-JS optimizers). Server-built entirely from
	 * already-sanitized order data via wp_json_encode(); no request input.
	 */
	public static function print_pending_event() {
		if ( null === self::$pending_order_id ) {
			return;
		}

		$order_id   = self::$pending_order_id;
		$event_data = self::$pending_event_data;

		self::$pending_order_id   = null;
		self::$pending_event_data = null;

		if ( ! Tracker::should_output() ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || $order->get_meta( self::META_TRACKED ) ) {
			return;
		}

		// JSON_HEX_TAG|AMP|APOS|QUOT hardens against markup breakout from
		// admin-authored order/product data (an order note or product name
		// containing e.g. "<!--<script") reaching this inline script -
		// defense-in-depth; kses already limits what non-admins can
		// author into these fields.
		$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

		// The
		// purchase call is server-built (a fixed 'purchase' event + already-
		// sanitized order data, no request input) and needs none of
		// window.statsUmami.track()'s wrapping (50-char name clamp + guard),
		// so it calls window.umami.track() directly - wrapped in a local
		// try/catch to keep the never-throw contract. An earlier
		// dual-object poll required window.statsUmami (THIS plugin's OWN
		// frontend.js) to be ready too, but that reintroduced exactly the
		// failure this design is meant to avoid: a script-blocker blocking a
		// file literally named "frontend.js" while a renamed Umami tracker
		// loads fine - the poll would exhaust its budget and the purchase
		// would be lost even though the idempotency flag was already burned.
		// Testing only window.umami and calling it directly is strictly more
		// resilient for that renamed-tracker/blocked-frontend.js audience,
		// while keeping the real invariant
		// ("test the object you actually call").
		$call = sprintf(
			'try{window.umami.track(%s,%s);}catch(e){}',
			wp_json_encode( 'purchase', $json_flags ),
			wp_json_encode( $event_data, $json_flags )
		);

		$script = sprintf(
			'(function(){var n=0;function p(){if(window.umami&&"function"===typeof window.umami.track){%1$s return;}if(++n>=%2$d){return;}setTimeout(p,%3$d);}p();}());',
			$call,
			self::POLL_MAX_ATTEMPTS,
			self::POLL_INTERVAL_MS
		);

		echo '<script>' . $script . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- server-built entirely from wp_json_encode()'d order data (JSON_HEX_*-hardened above) + literal guard/poll code, no request input.

		$order->update_meta_data( self::META_TRACKED, '1' );
		$order->save();
	}
}
