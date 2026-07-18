<?php
/**
 * Minimal global-namespace wc_get_order() stand-in, used by
 * WooCommerceIntegrationTest to drive Integrations\WooCommerce::maybe_track()
 * without a real WooCommerce install. Returns whatever order double the test
 * put into $GLOBALS['stats_umami_test_wc_orders'] keyed by order id, or
 * false for an unknown id - mirroring wc_get_order()'s own
 * false-on-invalid-id contract. Test-only: never required outside tests/.
 *
 * @package StatsUmami
 */

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * @param mixed $the_order Order id (or any key present in the test fixture map).
	 * @return mixed The test double registered under this id, or false.
	 */
	function wc_get_order( $the_order = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- stubbing WooCommerce's own real function name (see file docblock).
		if ( isset( $GLOBALS['stats_umami_test_wc_orders'][ $the_order ] ) ) {
			return $GLOBALS['stats_umami_test_wc_orders'][ $the_order ];
		}

		return false;
	}
}
