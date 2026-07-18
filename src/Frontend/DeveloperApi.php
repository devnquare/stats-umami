<?php
/**
 * Front-end-only enqueuer for assets/js/frontend.js: the public JS
 * developer API (window.statsUmami.track) and the config-gated auto-track
 * listeners. Gated by the exact same Tracker::should_output() check as the
 * tracker <script> itself, so it inherits enabled/role-exclusion/
 * completeness for free (see docs/PLAN.md §5, OLD-PLUGIN-INVENTORY §5.1/§6.1
 * should_output/should_enqueue parity).
 *
 * @package StatsUmami
 */

namespace StatsUmami\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the hand-written frontend.js whenever the tracker itself would
 * output, so window.statsUmami.track() is always available while tracking
 * is on - even when every auto-track flag is off.
 */
class DeveloperApi {

	/**
	 * Register front-end hooks. Callers are responsible for only invoking
	 * this outside wp-admin (see Plugin::boot()).
	 */
	public static function register() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue frontend.js in the footer, versioned by STATS_UMAMI_VERSION,
	 * with no dependencies - but only when Tracker::should_output() is
	 * true. Reuses the gate rather than re-deriving it.
	 */
	public static function enqueue() {
		if ( ! Tracker::should_output() ) {
			return;
		}

		wp_enqueue_script(
			'stats-umami-frontend',
			STATS_UMAMI_URL . 'assets/js/frontend.js',
			array(),
			STATS_UMAMI_VERSION,
			true
		);
	}
}
