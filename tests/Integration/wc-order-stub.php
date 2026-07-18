<?php
/**
 * Minimal global-namespace WC_Order stand-in, used by FakeOrder so
 * Integrations\WooCommerce::maybe_track()'s `$order instanceof \WC_Order`
 * guard holds for the test double. WooCommerce isn't installed in the
 * phpunit integration bootstrap. Deliberately separate from
 * woocommerce-class-stub.php (the global `WooCommerce` class used only for
 * Integrations\Manager's class_exists('WooCommerce') dependency predicate):
 * this file is required unconditionally by every test in this suite (an
 * order double is needed regardless of the Manager-gating scenario under
 * test), so it must NOT also define `WooCommerce`, or the gating test's
 * "dependency currently undefined" steps would be false from the start.
 * Test-only: never required outside tests/.
 *
 * @package StatsUmami
 */

if ( ! class_exists( 'WC_Order', false ) ) {
	class WC_Order {
	}
}
