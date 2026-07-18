<?php
/**
 * Plugin Name:       Stats Umami
 * Plugin URI:        https://umamiwp.com
 * Description:       Connect WordPress to your self-hosted Umami v3.x for cookie-free, privacy-friendly analytics with events for forms, blocks, and WooCommerce.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            nquare
 * Author URI:        https://nquare.pt
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stats-umami
 * Domain Path:       /languages
 *
 * @package StatsUmami
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STATS_UMAMI_VERSION', '1.1.0' );
define( 'STATS_UMAMI_FILE', __FILE__ );
define( 'STATS_UMAMI_DIR', plugin_dir_path( __FILE__ ) );
define( 'STATS_UMAMI_URL', plugin_dir_url( __FILE__ ) );

/**
 * Lightweight PSR-4 autoloader for the StatsUmami\ namespace, mapped to src/.
 * No Composer autoloader is shipped at runtime (zero runtime dependencies).
 */
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

register_activation_hook( STATS_UMAMI_FILE, array( 'StatsUmami\\Settings\\Options', 'activate' ) );

add_action( 'plugins_loaded', array( 'StatsUmami\\Plugin', 'boot' ) );
