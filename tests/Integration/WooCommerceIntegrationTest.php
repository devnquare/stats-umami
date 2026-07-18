<?php
/**
 * DB-backed integration tests for the WooCommerce integration:
 * maybe_track()'s idempotency decision + build_event_data() plumbing,
 * print_pending_event()'s wp_footer output (gated by the real
 * Tracker::should_output()), Integrations\Manager's registration gating, and
 * Plugin::boot()'s unconditional HPOS-compatibility-hook registration,
 * against a real WP core bootstrap + test database. WooCommerce itself isn't
 * installed in this bootstrap - see woocommerce-class-stub.php /
 * woocommerce-functions-stub.php / wc-order-stub.php.
 *
 * A true persisted order-CRUD round-trip (writing/reading the idempotency
 * flag through a real WC_Order data store) isn't feasible in this bootstrap
 * since WooCommerce's data stores aren't loadable here - per the phase spec,
 * that persisted case (and the HPOS-specific storage proof) is covered by
 * the live browser E2E instead. What IS covered here is the DECISION logic
 * (already-tracked -> skip) in isolation, using a double that implements the
 * same get_meta()/update_meta_data()/save() contract the real order-CRUD API
 * exposes.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

use StatsUmami\Integrations\Manager;
use StatsUmami\Integrations\WooCommerce;
use StatsUmami\Plugin;
use StatsUmami\Settings\Options;
use Yoast\WPTestUtils\WPIntegration\TestCase;

require_once __DIR__ . '/woocommerce-functions-stub.php';
require_once __DIR__ . '/FakeOrder.php';
require_once __DIR__ . '/FakeOrderItem.php';

/**
 * @covers \StatsUmami\Integrations\WooCommerce
 * @covers \StatsUmami\Integrations\Manager
 * @covers \StatsUmami\Plugin
 */
final class WooCommerceIntegrationTest extends TestCase {

	public function set_up() {
		parent::set_up();

		delete_option( Options::OPTION_KEY );
		wp_set_current_user( 0 );

		$GLOBALS['stats_umami_test_wc_orders'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test-only fixture map for the wc_get_order() stub, not a plugin global.
	}

	public function tear_down() {
		$GLOBALS['stats_umami_test_wc_orders'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- same as set_up().

		unset( $_SERVER['HTTP_SEC_PURPOSE'], $_SERVER['HTTP_PURPOSE'], $_SERVER['HTTP_X_PURPOSE'] );

		parent::tear_down();
	}

	/**
	 * A fully-configured, trackable + WooCommerce-enabled options array
	 * (including host_url/website_id, so Tracker::should_output() - which
	 * print_pending_event() gates on - evaluates true by default), with the
	 * given overrides layered on top.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function trackable_options( array $overrides = array() ) {
		$options                       = Options::defaults();
		$options['enabled']            = true;
		$options['enable_woocommerce'] = true;
		$options['host_url']           = 'https://analytics.example.com';
		$options['website_id']         = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
		$options['schema_version']     = Options::SCHEMA_VERSION;

		return array_merge( $options, $overrides );
	}

	/**
	 * @param int    $order_id Fixture key.
	 * @param object $order    Order double.
	 */
	private function register_order( $order_id, $order ) {
		$GLOBALS['stats_umami_test_wc_orders'][ $order_id ] = $order; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test-only fixture map, not a plugin global.
	}

	/**
	 * Call print_pending_event() and capture whatever it echoes.
	 *
	 * @return string
	 */
	private function captured_footer_output() {
		ob_start();
		WooCommerce::print_pending_event();
		return ob_get_clean();
	}

	// ---------------------------------------------------------------
	// maybe_track() + print_pending_event(): idempotency decision +
	// wp_footer output, gated by the real Tracker::should_output().
	// ---------------------------------------------------------------

	public function test_tracks_an_untracked_paid_order_and_prints_the_event_at_wp_footer() {
		Options::update( $this->trackable_options() );

		$order = new FakeOrder(
			49.99,
			'EUR',
			array(
				new FakeOrderItem( 'Widget', 2 ),
				new FakeOrderItem( 'Gadget', 1 ),
			)
		);
		$this->register_order( 101, $order );

		WooCommerce::maybe_track( 101 );

		// The flag is NOT written by maybe_track() - only
		// print_pending_event() writes it, and only after
		// should_output() passes (see the class docblock's FLAG TIMING note).
		$this->assertFalse( $order->saved );
		$this->assertSame( '', $order->get_meta( '_stats_umami_woo_tracked' ) );

		$output = $this->captured_footer_output();

		$this->assertTrue( $order->saved );
		$this->assertSame( '1', $order->get_meta( '_stats_umami_woo_tracked' ) );

		$this->assertSame( 1, substr_count( $output, 'window.umami.track(' ) );
		// The readiness poll wraps the call, waiting for window.umami
		// rather than a one-shot DOMContentLoaded deferral.
		$this->assertStringContainsString( 'window.umami&&"function"===typeof window.umami.track', $output );
		$this->assertStringContainsString( 'setTimeout(p,200)', $output );
		$this->assertStringContainsString( '"purchase"', $output );
		$this->assertStringContainsString( '"revenue":49.99', $output );
		$this->assertStringContainsString( '"currency":"EUR"', $output );
		$this->assertStringContainsString( '"product_count":2', $output );
		$this->assertStringContainsString( '"quantity_total":3', $output );
		$this->assertStringContainsString( '"product_names":"Widget, Gadget"', $output );

		// The call must invoke window.umami.track DIRECTLY (wrapped in
		// a local try/catch), and the readiness guard must NOT require
		// window.statsUmami (this plugin's OWN frontend.js) at all - an
		// earlier dual-object predicate defeated its own stated purpose (resilience
		// against a blocked frontend.js) by making the purchase call depend
		// on the very object that can be missing in that scenario. Both
		// assertions must fail against the pre-fix code, which emits
		// "window.statsUmami.track(" as the call and requires
		// "&&window.statsUmami&&...typeof window.statsUmami.track" in the guard.
		$this->assertStringContainsString( 'try{window.umami.track(', $output );
		$this->assertStringNotContainsString( 'window.statsUmami', $output );
	}

	/**
	 * JSON_HEX_TAG|AMP|APOS|QUOT was added to the
	 * inline-JSON wp_json_encode() call in print_pending_event() to prevent
	 * a `</script>` breakout from admin-authored order/product data (an
	 * order note or product name containing e.g. "<!--<script"), but no
	 * test ever guarded it (`grep JSON_HEX tests/` found nothing; deleting
	 * the flags left the whole suite green). Must fail if the flags are
	 * removed - a product name carrying all five hardened characters must
	 * come out hex-escaped, never raw, in the printed script.
	 */
	public function test_print_pending_event_hex_escapes_markup_breakout_characters_in_product_names() {
		Options::update( $this->trackable_options() );

		$order = new FakeOrder(
			10,
			'EUR',
			array( new FakeOrderItem( '</script><script>alert(1)</script>&\'"', 1 ) )
		);
		$this->register_order( 109, $order );

		WooCommerce::maybe_track( 109 );
		$output = $this->captured_footer_output();

		// The RAW, un-hex-escaped markup must never appear (a literal
		// breakout would look like this in the printed script tag).
		$raw_breakout = chr( 60 ) . 'script' . chr( 62 ) . 'alert(1)' . chr( 60 ) . '/script' . chr( 62 ); // "<script>alert(1)</script>", built from chr() so this literal breakout string can't itself be mistaken for markup by tooling.
		$this->assertStringNotContainsString( $raw_breakout, $output );

		// Every hardened character must instead appear as its backslash-u
		// hex-escaped form.
		$hex_tag  = chr( 92 ) . 'u003C'; // backslash + u003C, i.e. escaped "<".
		$hex_amp  = chr( 92 ) . 'u0026'; // escaped "&".
		$hex_apos = chr( 92 ) . 'u0027'; // escaped "'".
		$hex_quot = chr( 92 ) . 'u0022'; // escaped '"'.

		$this->assertStringContainsString( $hex_tag, $output );
		$this->assertStringContainsString( $hex_amp, $output );
		$this->assertStringContainsString( $hex_apos, $output );
		$this->assertStringContainsString( $hex_quot, $output );
	}

	public function test_does_not_track_a_non_paid_order() {
		// Per DECISIONS 2026-07-04: pending/on-hold/failed/
		// cancelled/refunded orders at thank-you time must not be tracked -
		// only WC_Order::is_paid() decides.
		Options::update( $this->trackable_options() );

		$order = new FakeOrder( 10, 'EUR', array(), array(), false );
		$this->register_order( 105, $order );

		WooCommerce::maybe_track( 105 );

		$this->assertFalse( $order->saved );
		$this->assertSame( '', $order->get_meta( '_stats_umami_woo_tracked' ) );
		$this->assertSame( '', $this->captured_footer_output() );
	}

	public function test_skips_a_prefetch_request_without_building_or_flagging() {
		// A browser/proxy prefetching the order-received URL
		// must not consume the one-shot idempotency flag ahead of the
		// visitor's real navigation.
		Options::update( $this->trackable_options() );

		$order = new FakeOrder( 10, 'EUR' );
		$this->register_order( 106, $order );

		$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch;prerender';

		WooCommerce::maybe_track( 106 );

		$this->assertFalse( $order->saved );
		$this->assertSame( '', $order->get_meta( '_stats_umami_woo_tracked' ) );
		$this->assertSame( '', $this->captured_footer_output() );
	}

	public function test_both_thank_you_hooks_firing_in_the_same_request_print_exactly_one_event() {
		// Registered on BOTH woocommerce_before_thankyou and
		// woocommerce_thankyou (see the class docblock's HOOK REGISTRATION
		// note) - a real page render can fire both for the same order.
		// Double-registration must still print exactly once and flag once.
		Options::update( $this->trackable_options() );

		$order = new FakeOrder( 10, 'EUR' );
		$this->register_order( 107, $order );

		WooCommerce::maybe_track( 107 ); // Simulates woocommerce_before_thankyou.
		WooCommerce::maybe_track( 107 ); // Simulates woocommerce_thankyou.

		$output = $this->captured_footer_output();

		$this->assertSame( 1, substr_count( $output, 'window.umami.track(' ) );
		$this->assertTrue( $order->saved );
		$this->assertSame( '1', $order->get_meta( '_stats_umami_woo_tracked' ) );
	}

	public function test_prints_nothing_a_second_time_in_the_same_request() {
		Options::update( $this->trackable_options() );

		$order = new FakeOrder( 10, 'EUR' );
		$this->register_order( 104, $order );

		WooCommerce::maybe_track( 104 );

		$this->captured_footer_output(); // First call consumes the pending event.

		$this->assertSame( '', $this->captured_footer_output() );
	}

	public function test_does_not_track_an_already_tracked_order_again() {
		Options::update( $this->trackable_options() );

		$order = new FakeOrder( 10, 'EUR', array(), array( '_stats_umami_woo_tracked' => '1' ) );
		$this->register_order( 102, $order );

		WooCommerce::maybe_track( 102 );

		$this->assertFalse( $order->saved );
		$this->assertSame( '', $this->captured_footer_output() );
	}

	public function test_does_nothing_for_an_invalid_order_id() {
		Options::update( $this->trackable_options() );

		// No order registered under this id - wc_get_order() stub returns false.
		WooCommerce::maybe_track( 999 );

		$this->assertSame( '', $this->captured_footer_output() );
	}

	public function test_flag_is_not_burned_when_tracking_is_off_so_a_later_eligible_render_can_retry() {
		// Master off: Tracker::should_output() (which
		// print_pending_event() gates on) evaluates false. The flag must NOT
		// be burned in this case - see the class docblock's FLAG TIMING
		// note - so a later request where output legitimately happens can
		// still fire once.
		Options::update( $this->trackable_options( array( 'enabled' => false ) ) );

		$order = new FakeOrder( 10, 'EUR' );
		$this->register_order( 103, $order );

		WooCommerce::maybe_track( 103 );

		$this->assertSame( '', $this->captured_footer_output() );
		$this->assertFalse( $order->saved );
		$this->assertSame( '', $order->get_meta( '_stats_umami_woo_tracked' ) );

		// Simulate a later revisit of the order-received page once tracking
		// is turned back on: the SAME order, still unflagged, is eligible to
		// fire exactly once now.
		Options::update( $this->trackable_options() );

		WooCommerce::maybe_track( 103 );
		$output = $this->captured_footer_output();

		$this->assertSame( 1, substr_count( $output, 'window.umami.track(' ) );
		$this->assertTrue( $order->saved );
		$this->assertSame( '1', $order->get_meta( '_stats_umami_woo_tracked' ) );
	}

	public function test_print_pending_event_re_checks_meta_before_saving_as_a_race_guard() {
		// Narrows (does not eliminate) the read-modify-write race
		// between two overlapping renders of the same order-received page -
		// if the order was flagged tracked by another process between
		// maybe_track() stashing the pending event and wp_footer printing
		// it, print_pending_event() must not print or save again.
		Options::update( $this->trackable_options() );

		$order = new FakeOrder( 10, 'EUR' );
		$this->register_order( 108, $order );

		WooCommerce::maybe_track( 108 );

		// Simulate a concurrent request having already flagged + printed
		// this order in the gap before this request's wp_footer runs.
		$order->update_meta_data( '_stats_umami_woo_tracked', '1' );

		$this->assertSame( '', $this->captured_footer_output() );
		$this->assertFalse( $order->saved );
	}

	// ---------------------------------------------------------------
	// Order-status LIFECYCLES across multiple visits
	// of the order-received page - proving maybe_track()'s existing
	// is_paid()-then-idempotency-flag gate already handles offline
	// payments arriving late, and refunds before/after a purchase was
	// tracked, with no production code change.
	// ---------------------------------------------------------------

	public function test_offline_payment_order_fires_exactly_once_once_it_becomes_paid_on_a_later_visit() {
		// The single most important Woo lifecycle: an on-hold
		// (BACS/cheque, not-yet-paid) order's order-received page fires
		// nothing and leaves the flag unset; once the order is later marked
		// processing (paid), a revisit fires exactly one purchase and burns
		// the flag; a third visit fires nothing further.
		Options::update( $this->trackable_options() );

		$order = new FakeOrder( 10, 'EUR', array( new FakeOrderItem( 'Widget', 1 ) ), array(), false );
		$this->register_order( 201, $order );

		// Visit 1: still on-hold (not paid).
		WooCommerce::maybe_track( 201 );
		$this->assertSame( '', $this->captured_footer_output() );
		$this->assertFalse( $order->saved );
		$this->assertSame( '', $order->get_meta( '_stats_umami_woo_tracked' ) );

		// The order is marked processing (payment confirmed).
		$order->set_paid( true );

		// Visit 2: now paid, never tracked - fires exactly once.
		WooCommerce::maybe_track( 201 );
		$output = $this->captured_footer_output();

		$this->assertSame( 1, substr_count( $output, 'window.umami.track(' ) );
		$this->assertStringContainsString( '"purchase"', $output );
		$this->assertTrue( $order->saved );
		$this->assertSame( '1', $order->get_meta( '_stats_umami_woo_tracked' ) );

		// Visit 3: already tracked - fires nothing further.
		WooCommerce::maybe_track( 201 );
		$this->assertSame( '', $this->captured_footer_output() );
	}

	public function test_refund_after_a_tracked_purchase_fires_nothing_on_a_later_visit() {
		// A paid order fires once as normal; once refunded (no
		// longer paid), a revisit fires nothing - guarded redundantly (by
		// design, see the class docblock's FLAG TIMING note) by BOTH
		// is_paid() (false for refunded) AND the already-burned flag.
		Options::update( $this->trackable_options() );

		$order = new FakeOrder( 10, 'EUR' );
		$this->register_order( 202, $order );

		WooCommerce::maybe_track( 202 );
		$first_output = $this->captured_footer_output();

		$this->assertSame( 1, substr_count( $first_output, 'window.umami.track(' ) );
		$this->assertSame( '1', $order->get_meta( '_stats_umami_woo_tracked' ) );

		// The order is refunded.
		$order->set_paid( false );

		WooCommerce::maybe_track( 202 );
		$this->assertSame( '', $this->captured_footer_output() );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( '1', $order->get_meta( '_stats_umami_woo_tracked' ) );
	}

	public function test_partial_refund_stays_paid_but_the_burned_flag_still_prevents_a_second_purchase() {
		// A partial refund leaves the order "processing"
		// (is_paid() stays true throughout) - only the already-burned flag
		// stops a second event.
		Options::update( $this->trackable_options() );

		$order = new FakeOrder( 10, 'EUR' );
		$this->register_order( 203, $order );

		WooCommerce::maybe_track( 203 );
		$first_output = $this->captured_footer_output();

		$this->assertSame( 1, substr_count( $first_output, 'window.umami.track(' ) );

		// Partial refund: status stays "processing" - this double models
		// that by simply leaving $paid untouched (still true).
		$this->assertTrue( $order->is_paid() );

		WooCommerce::maybe_track( 203 );
		$second_output = $this->captured_footer_output();

		$this->assertSame( '', $second_output );
		$this->assertSame( 1, substr_count( $first_output . $second_output, 'window.umami.track(' ) );
	}

	public function test_refund_of_a_never_tracked_order_never_fires() {
		// An order refunded straight from on-hold (never paid, never
		// tracked) must never fire.
		Options::update( $this->trackable_options() );

		$order = new FakeOrder( 10, 'EUR', array(), array(), false );
		$this->register_order( 204, $order );

		WooCommerce::maybe_track( 204 );
		$this->assertSame( '', $this->captured_footer_output() );
		$this->assertFalse( $order->saved );
		$this->assertSame( '', $order->get_meta( '_stats_umami_woo_tracked' ) );
	}

	// ---------------------------------------------------------------
	// Integrations\Manager gating.
	// ---------------------------------------------------------------

	public function test_manager_gates_woocommerce_registration_on_master_toggle_and_dependency() {
		$before_thankyou_callback = array( WooCommerce::class, 'maybe_track' );
		$thankyou_callback        = array( WooCommerce::class, 'maybe_track' );
		$footer_callback          = array( WooCommerce::class, 'print_pending_event' );

		// 1. Master switch off (dependency currently undefined, matching
		// this bootstrap's default - WooCommerce is never installed here).
		Options::update( $this->trackable_options( array( 'enabled' => false ) ) );
		Manager::register();
		$this->assertFalse( has_action( 'woocommerce_before_thankyou', $before_thankyou_callback ) );
		$this->assertFalse( has_action( 'woocommerce_thankyou', $thankyou_callback ) );
		$this->assertFalse( has_action( 'wp_footer', $footer_callback ) );

		// 2. Master on, enable_woocommerce off, dependency still undefined.
		Options::update( $this->trackable_options( array( 'enable_woocommerce' => false ) ) );
		Manager::register();
		$this->assertFalse( has_action( 'woocommerce_thankyou', $thankyou_callback ) );

		// 3. Master + toggle on, dependency STILL undefined - not registered.
		Options::update( $this->trackable_options() );
		Manager::register();
		$this->assertFalse( has_action( 'woocommerce_thankyou', $thankyou_callback ) );

		// 4. Declare the dependency (mirrors WooCommerce actually being
		// active) - master + toggle already on from step 3, so this alone
		// flips the gate to registered.
		require_once __DIR__ . '/woocommerce-class-stub.php';

		Manager::register();
		$this->assertNotFalse( has_action( 'woocommerce_before_thankyou', $before_thankyou_callback ) );
		$this->assertNotFalse( has_action( 'woocommerce_thankyou', $thankyou_callback ) );
		$this->assertNotFalse( has_action( 'wp_footer', $footer_callback ) );
	}

	// ---------------------------------------------------------------
	// Plugin::boot(): the HPOS compatibility declaration is unconditional.
	// ---------------------------------------------------------------

	public function test_boot_registers_the_hpos_compatibility_hook_even_when_tracking_is_off() {
		// Master off (and, implicitly, enable_woocommerce whatever the
		// defaults hold) - the HPOS declaration must still register: it is a
		// statement about the plugin's code, not about whether tracking is
		// configured.
		Options::update( $this->trackable_options( array( 'enabled' => false ) ) );

		Plugin::boot();

		$this->assertNotFalse( has_action( 'before_woocommerce_init', array( Plugin::class, 'declare_hpos_compatibility' ) ) );
	}
}
