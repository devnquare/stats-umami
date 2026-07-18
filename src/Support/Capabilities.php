<?php
/**
 * Small, pure role/visibility helpers shared across admin surfaces (see
 * docs/PLAN.md §5, listed as `Support/Capabilities.php`). Phase 3.5's first
 * consumer is the dashboard-widget viewer gate (share_url_roles - see
 * can_view()'s docblock for exactly what that option does and does not gate).
 *
 * @package StatsUmami
 */

namespace StatsUmami\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure role-intersection logic plus a thin WordPress wrapper reading the
 * current request's user - the pure half stays directly unit-testable with
 * plain arrays, no WP function mocking required.
 */
class Capabilities {

	/**
	 * Whether a user with the given roles may view the Dashboard widget -
	 * the ONLY surface `share_url_roles` gates (it does not gate the
	 * settings page or the General-tab "View your stats" link, both of
	 * which already require manage_options regardless of this option): true
	 * if any of their roles is in the allowed list. Pure - no WordPress
	 * calls.
	 *
	 * @param string[] $user_roles    The user's own role slugs.
	 * @param string[] $allowed_roles The site's configured allowed roles (share_url_roles).
	 * @return bool
	 */
	public static function can_view( array $user_roles, array $allowed_roles ) {
		return array() !== array_intersect( $user_roles, $allowed_roles );
	}

	/**
	 * Whether the CURRENT logged-in user may view the Dashboard widget (see
	 * can_view()'s docblock for exactly what share_url_roles does and does
	 * not gate). Administrators always qualify via manage_options
	 * regardless of $allowed_roles; every other user needs a role
	 * intersection (see can_view()).
	 *
	 * @param string[] $allowed_roles The site's configured allowed roles (share_url_roles).
	 * @return bool
	 */
	public static function current_user_can_view( array $allowed_roles ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$user = wp_get_current_user();

		return self::can_view( (array) $user->roles, $allowed_roles );
	}
}
