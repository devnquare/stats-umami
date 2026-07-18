<?php
/**
 * Pure, tab-aware settings sanitizer.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitizes one submitted settings tab at a time against the current
 * stored values, without touching get_option()/update_option() itself.
 *
 * MUST-REPLICATE pattern (see docs/DECISIONS.md [D2]): all four settings
 * tabs share ONE register_setting()/settings_fields('stats_umami') group
 * against the ONE stats_umami_options option, with this single sanitize
 * callback branching on a hidden `_tab` field. Registering four separate
 * groups against the same option name would silently corrupt saves,
 * because $wp_registered_settings is keyed by option name, not group -
 * the last-registered group's callback would win for every tab's POST.
 *
 * UNSLASH BOUNDARY: WordPress passes the raw *slashed* value straight from
 * $_POST into a register_setting() sanitize_callback (it never unslashes on
 * your behalf). This class therefore assumes its caller has already called
 * wp_unslash() ONCE on the whole submitted array before it reaches
 * sanitize() - see Admin\SettingsPage::sanitize(), the sole production
 * caller. Sanitizer itself never calls wp_unslash(), so a value is only
 * ever unslashed once, however many fields it flows through.
 */
class Sanitizer {

	/**
	 * Field names owned by each settings tab.
	 *
	 * @var array<string, string[]>
	 */
	const TAB_FIELDS = array(
		'general'  => array(
			'enabled',
			'host_url',
			'website_id',
			'share_url',
			'share_url_roles',
			'dashboard_widget',
			'performance_tracking',
		),
		'events'   => array(
			'autotrack_links',
			'autotrack_buttons',
			'autotrack_forms',
			'autotrack_outbound',
			'track_comments',
			'enable_gutenberg',
			'enable_cf7',
			'enable_wpforms',
			'enable_woocommerce',
			'enable_elementor',
		),
		'advanced' => array(
			'excluded_roles',
			'host_url_override',
			'script_loading',
			'domains',
			'tag',
			'exclude_search',
			'exclude_hash',
			'do_not_track',
			'auto_pageview',
		),
		'tools'    => array(
			'delete_data_on_uninstall',
		),
	);

	/**
	 * Per-field sanitization strategy.
	 *
	 * @var array<string, string>
	 */
	const FIELD_TYPES = array(
		'enabled'                  => 'bool',
		'host_url'                 => 'url',
		'website_id'               => 'uuid',
		'script_loading'           => 'script_loading',
		'share_url'                => 'url',
		'share_url_roles'          => 'roles',
		'dashboard_widget'         => 'bool',
		'autotrack_links'          => 'bool',
		'autotrack_buttons'        => 'bool',
		'autotrack_forms'          => 'bool',
		'autotrack_outbound'       => 'bool',
		'track_comments'           => 'bool',
		'enable_gutenberg'         => 'bool',
		'enable_cf7'               => 'bool',
		'enable_wpforms'           => 'bool',
		'enable_woocommerce'       => 'bool',
		'enable_elementor'         => 'bool',
		'excluded_roles'           => 'roles',
		'host_url_override'        => 'url',
		'domains'                  => 'text',
		'tag'                      => 'text',
		'performance_tracking'     => 'bool',
		'exclude_search'           => 'bool',
		'exclude_hash'             => 'bool',
		'do_not_track'             => 'bool',
		'auto_pageview'            => 'bool',
		'delete_data_on_uninstall' => 'bool',
	);

	/**
	 * Strict UUID (v1-5) format, case-insensitive.
	 *
	 * @var string
	 */
	const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

	/**
	 * Whether a value is a well-formed Umami website ID (strict UUID v1-5).
	 * Public so the Settings layer can independently check a raw submission
	 * and surface a user-facing rejection notice - see docs/DECISIONS.md
	 * "Surface the invalid-UUID rejection to the user" - while this class
	 * stays pure (no add_settings_error()/WP admin-only calls here).
	 *
	 * @param mixed $value Already-unslashed candidate value.
	 * @return bool
	 */
	public static function is_valid_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( self::UUID_PATTERN, $value );
	}

	/**
	 * Sanitize a single tab's submission, merged onto the current stored
	 * settings. Fields belonging to other tabs are left untouched.
	 *
	 * @param array<string, mixed> $input   Already-unslashed submission (see the unslash-boundary note above).
	 * @param array<string, mixed> $current Current stored settings (already defaults-merged).
	 * @return array<string, mixed> Full settings array with only the submitted tab's fields updated.
	 */
	public static function sanitize( array $input, array $current ) {
		$tab = isset( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : '';

		$fields = isset( self::TAB_FIELDS[ $tab ] ) ? self::TAB_FIELDS[ $tab ] : self::all_fields();

		$result = $current;

		foreach ( $fields as $field ) {
			$result[ $field ] = self::sanitize_field(
				$field,
				array_key_exists( $field, $input ) ? $input[ $field ] : null,
				isset( $current[ $field ] ) ? $current[ $field ] : null
			);
		}

		return $result;
	}

	/**
	 * All known field names across every tab, in a flat list.
	 *
	 * @return string[]
	 */
	private static function all_fields() {
		return array_keys( self::FIELD_TYPES );
	}

	/**
	 * Sanitize a single field's raw value according to its type.
	 *
	 * @param string $field        Field name (see FIELD_TYPES).
	 * @param mixed  $raw_value    Raw submitted value, or null when absent (e.g. unchecked checkbox).
	 * @param mixed  $current_value Current stored value for this field, used as fallback.
	 * @return mixed Sanitized value.
	 */
	private static function sanitize_field( $field, $raw_value, $current_value ) {
		$type = isset( self::FIELD_TYPES[ $field ] ) ? self::FIELD_TYPES[ $field ] : 'text';

		switch ( $type ) {
			case 'bool':
				return self::sanitize_bool( $raw_value );

			case 'url':
				return self::sanitize_url( $raw_value );

			case 'uuid':
				return self::sanitize_uuid( $raw_value, $current_value );

			case 'script_loading':
				return self::sanitize_script_loading( $raw_value );

			case 'roles':
				return self::sanitize_roles( $raw_value );

			case 'text':
			default:
				return self::sanitize_text( $raw_value );
		}
	}

	/**
	 * Bool fields: absent/empty means unchecked (checkboxes don't submit
	 * when unchecked), anything non-empty means checked.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function sanitize_bool( $value ) {
		return ! empty( $value );
	}

	/**
	 * URL fields.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function sanitize_url( $value ) {
		if ( null === $value ) {
			return '';
		}

		return esc_url_raw( trim( (string) $value ) );
	}

	/**
	 * Website ID: strict UUID (v1-5) format. On a malformed NON-BLANK
	 * submission (the fat-finger-paste case this fallback exists for) the
	 * previously stored value is kept rather than clearing tracking. A BLANK
	 * submission is different: it is a deliberate, valid "unconfigured"
	 * value - clearing the field and
	 * saving must actually clear it, not silently restore the old ID with no
	 * feedback.
	 *
	 * @param mixed $value   Raw value.
	 * @param mixed $current Current stored value, used as fallback on invalid non-blank input.
	 * @return string
	 */
	private static function sanitize_uuid( $value, $current ) {
		$candidate = sanitize_text_field( (string) $value );

		if ( '' === $candidate ) {
			return '';
		}

		if ( self::is_valid_uuid( $candidate ) ) {
			return strtolower( $candidate );
		}

		return is_string( $current ) ? $current : '';
	}

	/**
	 * Script loading strategy: allowlist of "defer"/"async", default "defer".
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function sanitize_script_loading( $value ) {
		$allowed = array( 'defer', 'async' );
		$value   = is_string( $value ) ? $value : '';

		return in_array( $value, $allowed, true ) ? $value : 'defer';
	}

	/**
	 * Role lists: each entry sanitized as a key and intersected with the
	 * site's real registered roles (drops anything invalid/stale), then
	 * de-duplicated - a crafted submission can repeat the same role slug
	 * several times, which array_intersect() alone would keep.
	 *
	 * @param mixed $value Raw value, expected to be an array of role slugs.
	 * @return string[]
	 */
	private static function sanitize_roles( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$submitted = array_map( 'sanitize_key', $value );

		$valid_roles = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->roles ) : $submitted;

		return array_values( array_unique( array_intersect( $submitted, $valid_roles ) ) );
	}

	/**
	 * Plain text fields.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function sanitize_text( $value ) {
		if ( null === $value ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}
}
