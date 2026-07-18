<?php
/**
 * Sample config for the DB-backed WP integration test suite (tests/Integration).
 *
 * Copy this file to `wp-tests-config.php` (gitignored - host-specific absolute
 * paths + local DB creds) and adjust ABSPATH to point at any local, fully
 * installed WordPress core checkout (does not need our plugin installed in
 * it - the integration suite loads our classes via Composer's autoloader and
 * exercises real wp-includes functions/DB against the WP core files at
 * ABSPATH). See docs/TESTING.md for the exact one-time DB setup.
 *
 * @package StatsUmami
 */

define( 'DB_NAME', 'your_test_db_name' );
define( 'DB_USER', 'your_db_user' );
define( 'DB_PASSWORD', 'your_db_password' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Stats Umami Integration Tests' );

define( 'WP_PHP_BINARY', 'php8.3' );

// Any local, fully installed WP core checkout - e.g. one of the per-version
// dirs under wordpress-umami-apps/ (adjust to your host).
define( 'ABSPATH', '/absolute/path/to/wordpress-umami-apps/wordpress-6.9.4/wordpress/' );
