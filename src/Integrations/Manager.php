<?php
/**
 * Integration registrar: gates each integration on the master enabled
 * switch AND its own toggle AND a dependency predicate before registering
 * its hooks.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Integrations;

use StatsUmami\Settings\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixes OLD-PLUGIN-INVENTORY §12 defect #4, where the old plugin's
 * integrations emitted markup/side-effects regardless of the master
 * `enabled` switch - only their own per-integration option and a
 * function_exists()/class_exists() dependency check gated them.
 *
 * Called unconditionally from Plugin::boot() (NOT wrapped in an is_admin()
 * branch): an integration's OWN hooks are context-specific (e.g. Gutenberg's
 * editor-assets hook only ever fires in wp-admin, its render_block filter
 * only ever fires on the front end), so the registrar itself must be
 * reachable in both contexts for those hooks to ever get the chance to
 * attach.
 */
class Manager {

	/**
	 * Register every integration whose gate passes: master `enabled` is
	 * true, its own toggle option is true, and its dependency predicate
	 * returns true.
	 */
	public static function register() {
		$options = Options::get();

		if ( empty( $options['enabled'] ) ) {
			return;
		}

		foreach ( self::integrations() as $integration ) {
			if ( empty( $options[ $integration['toggle'] ] ) ) {
				continue;
			}

			if ( ! call_user_func( $integration['available'] ) ) {
				continue;
			}

			call_user_func( $integration['register'] );
		}
	}

	/**
	 * The integration map: each entry names its settings toggle key, a
	 * dependency predicate, and the register callback to invoke when both
	 * gates (plus the master switch) pass. Phase 3.6 populated Gutenberg;
	 * 3.7 adds CF7 + WPForms (predicates matching Admin\SettingsPage's
	 * availability checks exactly); 3.8 adds WooCommerce using the same
	 * shape. The 1.1.0 Elementor feature round adds a fifth entry, gated on
	 * defined('ELEMENTOR_VERSION') exactly like Admin\SettingsPage's own
	 * availability check.
	 *
	 * @return array<int, array{toggle: string, available: callable, register: callable}>
	 */
	private static function integrations() {
		return array(
			array(
				'toggle'    => 'enable_gutenberg',
				'available' => static function () {
					// The block editor is core since WP 5.0, predating our
					// 6.0 floor - this is always true in practice. Kept as
					// a real predicate (rather than a hardcoded true) so its
					// shape matches the class_exists() checks below.
					return function_exists( 'register_block_type' );
				},
				'register'  => array( Gutenberg::class, 'register' ),
			),
			array(
				'toggle'    => 'enable_cf7',
				'available' => static function () {
					return defined( 'WPCF7_VERSION' );
				},
				'register'  => array( ContactForm7::class, 'register' ),
			),
			array(
				'toggle'    => 'enable_wpforms',
				'available' => static function () {
					return class_exists( 'WPForms' );
				},
				'register'  => array( WPForms::class, 'register' ),
			),
			array(
				'toggle'    => 'enable_woocommerce',
				'available' => static function () {
					return class_exists( 'WooCommerce' );
				},
				'register'  => array( WooCommerce::class, 'register' ),
			),
			array(
				'toggle'    => 'enable_elementor',
				'available' => static function () {
					return defined( 'ELEMENTOR_VERSION' );
				},
				'register'  => array( Elementor::class, 'register' ),
			),
		);
	}
}
