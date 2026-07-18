<?php
/**
 * Test-only stand-in for WooCommerce's real
 * Automattic\WooCommerce\Utilities\OrderUtil, reporting HPOS as the
 * CURRENTLY INACTIVE storage backend - required on-demand by
 * UninstallHposMetaTest to reproduce the exact "a store that ran HPOS
 * (writing rows into wc_orders_meta), then switched back to legacy storage"
 * scenario the uninstall cleanup handles: the table (and its rows) can still exist
 * even when this reports false. Test-only: never required outside tests/.
 *
 * @package StatsUmami
 */

namespace Automattic\WooCommerce\Utilities;

if ( ! class_exists( __NAMESPACE__ . '\\OrderUtil', false ) ) {
	class OrderUtil {
		/**
		 * @return bool Always false - HPOS reported inactive.
		 */
		public static function custom_orders_table_usage_is_enabled() {
			return false;
		}
	}
}
