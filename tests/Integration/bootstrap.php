<?php
/**
 * PHPUnit bootstrap for the DB-backed WP integration suite (real WordPress
 * core + a real test database - no Brain\Monkey mocking). See docs/TESTING.md
 * for the one-time DB + wp-tests-config.php setup this bootstrap requires.
 *
 * @package StatsUmami
 */

$config_file = __DIR__ . '/../wp-tests-config.php';

if ( ! file_exists( $config_file ) ) {
	echo PHP_EOL . 'ERROR: tests/wp-tests-config.php is missing. Copy tests/wp-tests-config-sample.php'
		. ' to tests/wp-tests-config.php, point ABSPATH at a local WP core checkout, and ensure the'
		. ' stats_umami_test database exists (see docs/TESTING.md).' . PHP_EOL;
	exit( 1 );
}

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $config_file );
putenv( 'WP_TESTS_DIR=' . dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit' );

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require dirname( __DIR__, 2 ) . '/vendor/yoast/wp-test-utils/src/WPIntegration/bootstrap-functions.php';

Yoast\WPTestUtils\WPIntegration\bootstrap_it();

// admin-only WP core functions our Admin\* classes call (add_menu_page(),
// plugin_action_links helpers) live here and are not loaded by the front-end
// bootstrap above.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// wp_add_dashboard_widget() (Admin\DashboardWidget) lives here, also not
// loaded by the front-end bootstrap above.
require_once ABSPATH . 'wp-admin/includes/dashboard.php';

// get_current_screen()/set_current_screen() + the WP_Screen class, needed so
// tests can simulate a real dashboard-screen request before firing
// wp_dashboard_setup (a screen is required for wp_add_dashboard_widget()'s
// add_meta_box() call to bucket widgets under the 'dashboard' screen id).
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

if ( ! defined( 'STATS_UMAMI_DIR' ) ) {
	define( 'STATS_UMAMI_DIR', dirname( __DIR__, 2 ) . '/' );
}

if ( ! defined( 'STATS_UMAMI_FILE' ) ) {
	define( 'STATS_UMAMI_FILE', dirname( __DIR__, 2 ) . '/stats-umami.php' );
}

// Asset-enqueuing classes (Admin\SettingsPage, Frontend\DeveloperApi) read
// these; the main plugin file is never require'd here (see the autoloader
// note below), so they are otherwise left undefined in this bootstrap.
if ( ! defined( 'STATS_UMAMI_VERSION' ) ) {
	define( 'STATS_UMAMI_VERSION', '1.0.0' );
}

if ( ! defined( 'STATS_UMAMI_URL' ) ) {
	define( 'STATS_UMAMI_URL', 'https://example.com/wp-content/plugins/stats-umami/' );
}

// Mirrors the PSR-4 autoloader registered in stats-umami.php (see
// tests/bootstrap.php for why this is duplicated rather than loading the
// main file directly).
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
