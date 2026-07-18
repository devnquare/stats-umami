<?php
/**
 * Front-end tracker injection: the should_output() gate, the tracker
 * <script> + all data-* attributes, and the small JS config object that
 * Phase 3.4's frontend.js will consume.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Frontend;

use StatsUmami\Settings\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for whether/what to emit at wp_head. Both the
 * tracker <script> and the config object are gated by the same
 * should_output() check (see docs/PLAN.md §5 - fixes OLD-PLUGIN-INVENTORY
 * §12 defect #4, where the old plugin's integrations emitted regardless of
 * the master switch/role exclusion).
 */
class Tracker {

	/**
	 * Register front-end hooks. Callers are responsible for only invoking
	 * this outside wp-admin (see Plugin::boot()).
	 */
	public static function register() {
		add_action( 'wp_head', array( __CLASS__, 'output' ) );
	}

	/**
	 * Whether the tracker (and, by extension, integration side-effects
	 * that check this same gate) should fire on the current request.
	 *
	 * False when: not enabled; OR host_url/website_id empty; OR the
	 * current logged-in user has a role in excluded_roles. Anonymous
	 * visitors are always tracked. Never true in wp-admin.
	 *
	 * @param array<string, mixed>|null $options Plugin settings; fetched via Options::get() when omitted.
	 * @return bool
	 */
	public static function should_output( ?array $options = null ) {
		if ( null === $options ) {
			$options = Options::get();
		}

		$should = true;

		if ( is_admin() ) {
			$should = false;
		} elseif ( empty( $options['enabled'] ) ) {
			$should = false;
		} elseif ( empty( $options['host_url'] ) || empty( $options['website_id'] ) ) {
			$should = false;
		} elseif ( self::current_user_has_excluded_role( $options ) ) {
			$should = false;
		}

		/**
		 * Filter whether the tracker should output on this request.
		 *
		 * @param bool                 $should_output Whether the tracker should output.
		 * @param array<string, mixed> $options       Current plugin settings.
		 */
		return (bool) apply_filters( 'stats_umami_should_output', $should, $options );
	}

	/**
	 * Emit the config object (priority-agnostic - both outputs share one
	 * should_output() gate) and the tracker <script> at wp_head.
	 */
	public static function output() {
		$options = Options::get();

		if ( ! self::should_output( $options ) ) {
			return;
		}

		self::output_config( $options );
		self::output_script( $options );

		/**
		 * Fires immediately after the tracker <script> tag has been output.
		 *
		 * @param array<string, mixed> $options Current plugin settings.
		 */
		do_action( 'stats_umami_tracker_output', $options );
	}

	/**
	 * Whether the current logged-in user's roles intersect excluded_roles.
	 * Always false for anonymous visitors.
	 *
	 * @param array<string, mixed> $options Current plugin settings.
	 * @return bool
	 */
	private static function current_user_has_excluded_role( array $options ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$excluded = isset( $options['excluded_roles'] ) && is_array( $options['excluded_roles'] )
			? $options['excluded_roles']
			: array();

		if ( empty( $excluded ) ) {
			return false;
		}

		$user = wp_get_current_user();

		return (bool) array_intersect( (array) $user->roles, $excluded );
	}

	/**
	 * Build the <script> attribute array from options. Every value is
	 * escaped here (esc_url/esc_attr, or a hardcoded literal) so that
	 * output() can print the (possibly filter-modified) array without a
	 * second escaping pass; filter consumers are responsible for escaping
	 * anything they add.
	 *
	 * Boolean HTML attributes (defer/async) are represented as `true`
	 * rather than a string value.
	 *
	 * @param array<string, mixed> $options Current plugin settings.
	 * @return array<string, string|true> Attribute name => escaped value (or `true`).
	 */
	private static function build_attributes( array $options ) {
		$attributes = array();

		$attributes[ ( 'async' === $options['script_loading'] ) ? 'async' : 'defer' ] = true;
		$attributes['src']             = esc_url( ScriptUrl::derive( $options['host_url'] ) );
		$attributes['data-website-id'] = esc_attr( $options['website_id'] );

		if ( '' !== $options['host_url_override'] ) {
			$attributes['data-host-url'] = esc_url( $options['host_url_override'] );
		}

		if ( '' !== $options['domains'] ) {
			$attributes['data-domains'] = esc_attr( $options['domains'] );
		}

		if ( '' !== $options['tag'] ) {
			$attributes['data-tag'] = esc_attr( $options['tag'] );
		}

		if ( ! empty( $options['performance_tracking'] ) ) {
			$attributes['data-performance'] = 'true';
		}

		if ( ! empty( $options['exclude_search'] ) ) {
			$attributes['data-exclude-search'] = 'true';
		}

		if ( ! empty( $options['exclude_hash'] ) ) {
			$attributes['data-exclude-hash'] = 'true';
		}

		if ( ! empty( $options['do_not_track'] ) ) {
			$attributes['data-do-not-track'] = 'true';
		}

		// Umami's own default is true; we only ever emit the attribute to
		// disable it, keeping the common case's markup minimal.
		if ( empty( $options['auto_pageview'] ) ) {
			$attributes['data-auto-pageview'] = 'false';
		}

		return $attributes;
	}

	/**
	 * Print the tracker <script> tag built from $options, after running
	 * the public stats_umami_tracker_attributes filter.
	 *
	 * @param array<string, mixed> $options Current plugin settings.
	 */
	private static function output_script( array $options ) {
		$attributes = self::build_attributes( $options );

		/**
		 * Filter the tracker <script> attributes before output.
		 *
		 * A callback is expected to return an attribute name => value array
		 * (already-escaped value, or `true` for boolean attributes like
		 * defer/async), but - like any WordPress filter - is not statically
		 * guaranteed to; documented here as `mixed` (rather than the expected
		 * array shape) precisely so the is_array() guard below is real
		 * defensive code, not a check PHPStan can prove always true.
		 *
		 * @param mixed                $attributes Attribute name => already-escaped value (or `true` for boolean attributes like defer/async).
		 * @param array<string, mixed> $options    Current plugin settings.
		 */
		$filtered = apply_filters( 'stats_umami_tracker_attributes', $attributes, $options );

		// render_attributes() has an array-typed parameter, and PHP does not
		// coerce null/string/object into it - it throws a TypeError. A
		// consumer that forgets a `return` in its filter callback would
		// otherwise white-screen every tracked page on the site; falling
		// back to the unfiltered attributes matches how the sibling
		// stats_umami_should_output filter is already guarded (with a bool
		// cast) above.
		$attributes = is_array( $filtered ) ? $filtered : $attributes;

		// Every value was escaped in build_attributes(); filter consumers
		// own the escaping of anything they add (documented above).
		echo "\n" . '<script ' . self::render_attributes( $attributes ) . '></script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render an attribute array (see build_attributes()) into a string of
	 * space-separated HTML attributes.
	 *
	 * @param array<string, mixed> $attributes Attribute name => value (or `true` for boolean attributes).
	 * @return string
	 */
	private static function render_attributes( array $attributes ) {
		$parts = array();

		foreach ( $attributes as $name => $value ) {
			if ( true === $value ) {
				$parts[] = $name;
			} else {
				$parts[] = sprintf( '%s="%s"', $name, $value );
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Print the small server-built JS config object that Phase 3.4's
	 * frontend.js will read for its auto-track behaviour. Contains only
	 * stored settings + the site's own host - never request data.
	 *
	 * @param array<string, mixed> $options Current plugin settings.
	 */
	private static function output_config( array $options ) {
		$config = array(
			'autotrack_links'         => (bool) $options['autotrack_links'],
			'autotrack_buttons'       => (bool) $options['autotrack_buttons'],
			'autotrack_forms'         => (bool) $options['autotrack_forms'],
			'autotrack_outbound'      => (bool) $options['autotrack_outbound'],
			'track_comments'          => (bool) $options['track_comments'],
			'site_host'               => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			// A SITE-LEVEL predicate (WooCommerce is active
			// on this install), deliberately NOT the enable_woocommerce
			// toggle - an add-to-cart form is never a meaningful generic
			// "form submission" regardless of whether purchase tracking
			// itself is on, and a site-level flag is far easier to reason
			// about and test than one that moves with a setting. Lets
			// frontend.js's generic auto-trackers recognize and skip
			// WooCommerce's own commerce controls (cart/checkout forms,
			// add-to-cart buttons), which otherwise reintroduce
			// add_to_cart/begin_checkout through the generic form_submit/
			// button_click auto-trackers - contradicting DECISIONS 2026-06-29
			// "WooCommerce = purchase/revenue ONLY".
			'woo_present'             => class_exists( 'WooCommerce' ),
			// 1.1.0 (Elementor feature round): TOGGLE-aware, unlike
			// woo_present above - this flag's only job is telling
			// frontend.js's initAutoTrack() whether to attach the delegated
			// click listener at all when every autotrack_* toggle is off, so
			// it must reflect whether Integrations\Elementor is actually
			// registered (master + enable_elementor + Elementor detected),
			// not merely whether Elementor is installed.
			'track_elementor_buttons' => ! empty( $options['enable_elementor'] ) && defined( 'ELEMENTOR_VERSION' ),
		);

		// wp_json_encode() escapes forward slashes by default, which
		// prevents a "</script>" breakout even though nothing here is
		// request data in the first place. JSON_HEX_TAG|AMP|APOS|QUOT is
		// cheap extra defense-in-depth on the same inline-JSON site -
		// low risk here (site-host + booleans only)
		// but harmonized with the WooCommerce purchase-event hardening.
		$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

		echo '<script>window.__STATS_UMAMI_CFG__=' . wp_json_encode( $config, $json_flags ) . ';</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
