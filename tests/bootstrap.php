<?php
/**
 * PHPUnit bootstrap for pure-logic unit tests (Brain\Monkey - no WP/DB boot).
 *
 * Phase 3.1 scope: Sanitizer + Options are pure enough to unit-test against
 * mocked WordPress functions (see docs/PLAN.md §6 "Unit: pure logic" bucket).
 * A DB-backed WP-core integration suite is deferred to whichever phase first
 * needs real hook-firing assertions (e.g. render_block injection in 3.6) -
 * flagged to the PM in the Phase 3.1 handoff.
 *
 * @package StatsUmami
 */

require_once dirname( __DIR__ ) . '/vendor/yoast/wp-test-utils/src/BrainMonkey/bootstrap.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'STATS_UMAMI_DIR' ) ) {
	define( 'STATS_UMAMI_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'STATS_UMAMI_FILE' ) ) {
	define( 'STATS_UMAMI_FILE', dirname( __DIR__ ) . '/stats-umami.php' );
}

// Mirrors the PSR-4 autoloader registered in stats-umami.php. Duplicated
// (rather than requiring the main file) because the main file also calls
// register_activation_hook()/add_action() at load time, which are only
// safely callable once Brain\Monkey's per-test setUp() has stubbed them.
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'StatsUmami\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$relative_path  = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';
		$file           = STATS_UMAMI_DIR . 'src' . DIRECTORY_SEPARATOR . $relative_path;

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);
