<?php
/**
 * Settings storage: schema, defaults, get/update, and boot-time schema migration.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One autoloaded wp_options row holding the entire settings array, plus a
 * schema_version key used for forward migrations.
 */
class Options {

	/**
	 * The wp_options row name.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'stats_umami_options';

	/**
	 * Current settings schema version. Bump this and add a branch in
	 * migrate() whenever the stored shape needs to change.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 2;

	/**
	 * Default values for every setting, keyed by field name (schema_version
	 * is stored alongside these but is not itself a "setting").
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			// General tab.
			'enabled'                  => false,
			'host_url'                 => '',
			'website_id'               => '',
			'script_loading'           => 'defer',
			'share_url'                => '',
			'share_url_roles'          => array(),
			'dashboard_widget'         => true,

			// Events & integrations tab.
			'autotrack_links'          => false,
			'autotrack_buttons'        => true,
			'autotrack_forms'          => true,
			'autotrack_outbound'       => true,
			'track_comments'           => false,
			'enable_gutenberg'         => true,
			'enable_cf7'               => true,
			'enable_wpforms'           => true,
			'enable_woocommerce'       => true,
			'enable_elementor'         => true,

			// Advanced tab.
			'excluded_roles'           => array( 'administrator', 'editor', 'shop_manager' ),
			'host_url_override'        => '',
			'domains'                  => '',
			'tag'                      => '',
			'performance_tracking'     => false,
			'exclude_search'           => false,
			'exclude_hash'             => false,
			'do_not_track'             => false,
			'auto_pageview'            => true,

			// Tools & support tab.
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Read the current settings, with defaults merged in for any missing
	 * key (forward-compatible with schema additions), then re-coerced to
	 * their schema types (see coerce_types()) so a stored value that lost
	 * its shape can never reach a consumer typed against that shape.
	 *
	 * @return array<string, mixed>
	 */
	public static function get() {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::coerce_types( wp_parse_args( $stored, self::defaults() ) );
	}

	/**
	 * Re-coerce every known field present in $data to its schema type
	 * (array/bool/string), so a value that lost its shape - via WP-CLI
	 * `wp option update`, a DB import/migration, another plugin writing this
	 * same option, or the `_tab`-less admin passthrough (see
	 * Admin\SettingsPage::sanitize()) - can never reach a consumer typed
	 * against that shape (e.g. `esc_url()`'s `ltrim()` on an array, or a
	 * typed `array $selected` parameter).
	 *
	 * Type-only: this must NEVER re-run the semantic sanitizer (no
	 * wp_roles() intersection, no esc_ or sanitize_ helper calls), because
	 * those have side effects of their own - e.g. role-intersection drops
	 * "shop_manager" whenever WooCommerce happens to be inactive on the
	 * current request, which is exactly why the admin passthrough this
	 * feeds exists in the first place. A malformed value falls back to its
	 * default rather than being cast - an (string) cast on an array would
	 * itself produce a wrong-but-still-a-string shape (the literal
	 * "Array"), which is no better than the fatal it replaces.
	 *
	 * The array/bool/string partition is derived from
	 * Sanitizer::FIELD_TYPES ("roles" => array, "bool" => bool, everything
	 * else => string) rather than a second hardcoded list, so it can never
	 * drift out of sync with the sanitizer's own field types. Only keys
	 * present in both $data and FIELD_TYPES are touched; schema_version and
	 * any other unknown key pass through untouched.
	 *
	 * @param array<string, mixed> $data Settings array to coerce (full or partial).
	 * @return array<string, mixed> Same array, with every present known field's shape corrected.
	 */
	public static function coerce_types( array $data ) {
		$defaults = self::defaults();

		foreach ( Sanitizer::FIELD_TYPES as $field => $type ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}

			$value = $data[ $field ];

			if ( 'roles' === $type ) {
				$data[ $field ] = is_array( $value ) ? $value : $defaults[ $field ];
			} elseif ( 'bool' === $type ) {
				$data[ $field ] = (bool) $value;
			} else {
				$data[ $field ] = is_string( $value ) ? $value : $defaults[ $field ];
			}
		}

		return $data;
	}

	/**
	 * Persist a settings array as-is (callers are responsible for having
	 * sanitized it, e.g. via Sanitizer::sanitize()).
	 *
	 * @param array<string, mixed> $data Full settings array to store.
	 * @return bool
	 */
	public static function update( array $data ) {
		return update_option( self::OPTION_KEY, $data );
	}

	/**
	 * Activation callback: seed the option with defaults if it doesn't
	 * exist yet. Never overwrites an existing option (re-activation after
	 * deactivation must not reset a site's settings).
	 */
	public static function activate() {
		if ( false !== get_option( self::OPTION_KEY, false ) ) {
			return;
		}

		$defaults                   = self::defaults();
		$defaults['schema_version'] = self::SCHEMA_VERSION;

		add_option( self::OPTION_KEY, $defaults );
	}

	/**
	 * Boot-time migration runner, intended to be hooked on plugins_loaded.
	 * WordPress.org auto-updates never re-fire register_activation_hook,
	 * so schema migrations must run on every boot and be a cheap no-op
	 * once the stored schema_version is current.
	 */
	public static function maybe_migrate() {
		$stored = get_option( self::OPTION_KEY, false );

		// Not installed yet (activation hasn't run / seeded nothing) -
		// nothing to migrate.
		if ( false === $stored || ! is_array( $stored ) ) {
			return;
		}

		$current_version = isset( $stored['schema_version'] ) ? (int) $stored['schema_version'] : 0;

		if ( $current_version >= self::SCHEMA_VERSION ) {
			return;
		}

		$migrated = self::migrate( $stored, $current_version );

		$migrated['schema_version'] = self::SCHEMA_VERSION;

		self::update( $migrated );
	}

	/**
	 * Apply migrations in sequence, from the stored version up to
	 * SCHEMA_VERSION.
	 *
	 * @param array<string, mixed> $data    Stored settings array.
	 * @param int                  $from_version Schema version the data is currently at.
	 * @return array<string, mixed> Migrated settings array.
	 */
	private static function migrate( array $data, $from_version ) {
		if ( $from_version < 2 ) {
			// v1 -> v2: disable_umami_auto_track removed - it
			// was fully redundant with the tracker's own precise toggles
			// (Automatic page views / Performance tracking / the Gutenberg
			// switch) and its help text never warned that it also silently
			// suppressed Performance tracking. Nothing replaces it; a stored
			// value under this key is simply discarded.
			unset( $data['disable_umami_auto_track'] );
		}

		return wp_parse_args( $data, self::defaults() );
	}
}
