<?php
/**
 * PHPStan-only constant declarations. STATS_UMAMI_DIR/_URL are defined in
 * stats-umami.php via plugin_dir_path()/plugin_dir_url() (function calls,
 * not literals PHPStan can constant-fold), so cross-file references to them
 * from src/ are otherwise unresolvable during static analysis. Never loaded
 * at runtime - the real constants only ever come from stats-umami.php's own
 * define() calls; this file exists solely so PHPStan can type these as
 * `string`.
 *
 * @package StatsUmami
 */

if ( ! defined( 'STATS_UMAMI_VERSION' ) ) {
	define( 'STATS_UMAMI_VERSION', '1.0.0' );
}

if ( ! defined( 'STATS_UMAMI_FILE' ) ) {
	define( 'STATS_UMAMI_FILE', __DIR__ . '/stats-umami.php' );
}

if ( ! defined( 'STATS_UMAMI_DIR' ) ) {
	define( 'STATS_UMAMI_DIR', __DIR__ . '/' );
}

if ( ! defined( 'STATS_UMAMI_URL' ) ) {
	define( 'STATS_UMAMI_URL', 'https://example.com/wp-content/plugins/stats-umami/' );
}
