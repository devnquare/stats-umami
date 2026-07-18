<?php
/**
 * Minimal global-namespace WooCommerce class stand-in, required on-demand by
 * WooCommerceIntegrationTest to drive Integrations\Manager's
 * class_exists('WooCommerce') dependency predicate to true - mirrors
 * wpforms-class-stub.php's exact role for WPForms. WooCommerce itself isn't
 * installed in the phpunit integration bootstrap. Deliberately NOT required
 * eagerly by any file loaded at test-class-load time (see wc-order-stub.php,
 * which holds the separate `WC_Order` stub every test needs regardless of
 * this dependency-gating concern) - only required inside the gating test
 * itself, once its "dependency currently undefined" steps have already run.
 * Test-only: never required outside tests/.
 *
 * @package StatsUmami
 */

if ( ! class_exists( 'WooCommerce', false ) ) {
	class WooCommerce {
	}
}
