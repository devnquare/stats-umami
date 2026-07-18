<?php
/**
 * DB-backed integration test for uninstall.php's HPOS wc_orders_meta cleanup.
 * The delete must run whenever the wc_orders_meta table
 * EXISTS, independent of whether HPOS is the CURRENTLY active order storage:
 * a store that ran HPOS (writing rows into this table), then switched back
 * to legacy order storage, must not have those rows orphaned forever by an
 * opt-in "Delete data on uninstall" - the plugin's one explicit
 * data-deletion promise. Reproduced here with a real $wpdb-created
 * wc_orders_meta table (WooCommerce itself isn't installed in this
 * bootstrap) and order-util-hpos-inactive-stub.php's
 * OrderUtil::custom_orders_table_usage_is_enabled(), which reports HPOS
 * INACTIVE - exactly the "ran HPOS, switched back" scenario the pre-fix gate
 * got wrong.
 *
 * uninstall.php is a standalone script (WP_UNINSTALL_PLUGIN guard, no
 * StatsUmami\ classes, no function/class declarations of its own - see its
 * own docblock), so it is `include`d directly (not require_once) so each
 * test method gets a fresh run.
 *
 * TABLE LIFECYCLE GOTCHA (the reason the fixture table is created/dropped at
 * the CLASS level, not per-test): wp-phpunit's own test-transaction wrapper
 * (abstract-testcase.php start_transaction(), installed per-TEST inside
 * set_up()) adds a `query` filter that rewrites any literal `CREATE TABLE`/
 * `DROP TABLE` statement into `CREATE`/`DROP TEMPORARY TABLE` - so a per-test
 * `wpdb->query('CREATE TABLE ...')` silently becomes a MySQL TEMPORARY
 * TABLE, which `SHOW TABLES` structurally cannot see. uninstall.php's own
 * guard uses exactly `SHOW TABLES LIKE`, so creating this fixture DURING a
 * test (where that rewrite filter is active) makes uninstall.php see no
 * table at all and skip the delete - a fixture-timing trap, not a bug in
 * uninstall.php (caught by a first version of this test failing even
 * against the fix; confirmed by direct debugging that the table backing the
 * assertions had already silently become a session-scoped temporary table).
 * set_up_before_class() runs before any test's set_up() ever installs that
 * filter, so creating the REAL table there sidesteps the rewrite entirely.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

use Yoast\WPTestUtils\WPIntegration\TestCase;

final class UninstallHposMetaTest extends TestCase {

	const OPTION_KEY = 'stats_umami_options';

	/**
	 * @var string
	 */
	private static $hpos_meta_table;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		global $wpdb;

		self::$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- test-only fixture table, mirroring WooCommerce's own HPOS schema closely enough to exercise a real SHOW TABLES + DELETE against it; WooCommerce isn't installed in this bootstrap so there is no WP API for either, and $wpdb->prepare() has no placeholder for a table identifier (only values) - the table name is a literal built two lines above, never request input. See the class docblock for why this runs here, not in set_up().
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::$hpos_meta_table );
		$wpdb->query(
			'CREATE TABLE ' . self::$hpos_meta_table . " (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				order_id BIGINT UNSIGNED NOT NULL,
				meta_key VARCHAR(255) NULL,
				meta_value LONGTEXT NULL
			) {$wpdb->get_charset_collate()}"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function tear_down_after_class() {
		global $wpdb, $wp_filter; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reading/temporarily restoring WP core's OWN $wp_filter global to bypass a WP-core test-framework hook (see the comment below), not defining a new plugin global.

		// The per-test CREATE/DROP-TABLE-to-TEMPORARY rewrite filter (see
		// the class docblock) may still be installed here - the last test's
		// set_up() added it, and nothing removes it before this class-level
		// hook runs - which would otherwise turn this DROP TABLE into a
		// no-op DROP TEMPORARY TABLE against our real, non-temporary fixture
		// table. Bypassed the same way WP core itself adds/removes it: by
		// touching $wp_filter directly, since the specific test INSTANCE
		// that added it isn't available in this static context.
		$saved_query_filters = isset( $wp_filter['query'] ) ? $wp_filter['query'] : null;
		unset( $wp_filter['query'] );

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::$hpos_meta_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- see set_up_before_class()'s comment; same test-only fixture table.

		if ( null !== $saved_query_filters ) {
			$wp_filter['query'] = $saved_query_filters; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the WP-core hook state this method itself temporarily cleared above, not defining a new plugin global.
		}

		parent::tear_down_after_class();
	}

	public function set_up() {
		parent::set_up();

		delete_option( self::OPTION_KEY );
	}

	/**
	 * Run uninstall.php's real, standalone code.
	 */
	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'stats-umami/stats-umami.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core's own real constant, required verbatim so uninstall.php's own guard (`if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }`) passes.
		}

		include dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	/**
	 * @return int Count of rows in the fixture table with meta_key = '_stats_umami_woo_tracked'.
	 */
	private function count_tracked_rows() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- test-only fixture table (see class docblock); the table name is a literal set in set_up_before_class(), never request input - $wpdb->prepare() has no placeholder for table/column identifiers, only values, and meta_key IS passed through its own parameterization below.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::$hpos_meta_table . ' WHERE meta_key = %s',
				'_stats_umami_woo_tracked'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_hpos_meta_row_is_deleted_when_hpos_is_reported_inactive_but_the_table_exists() {
		require_once __DIR__ . '/order-util-hpos-inactive-stub.php';

		update_option( self::OPTION_KEY, array( 'delete_data_on_uninstall' => true ) );

		global $wpdb;

		$wpdb->insert(
			self::$hpos_meta_table,
			array(
				'order_id'   => 501,
				'meta_key'   => '_stats_umami_woo_tracked',
				'meta_value' => '1',
			)
		);

		// Sanity: the row genuinely exists before uninstall runs.
		$this->assertSame( 1, $this->count_tracked_rows() );

		$this->run_uninstall();

		$this->assertSame( 0, $this->count_tracked_rows() );
	}

	public function test_does_nothing_when_delete_data_on_uninstall_is_off() {
		require_once __DIR__ . '/order-util-hpos-inactive-stub.php';

		update_option( self::OPTION_KEY, array( 'delete_data_on_uninstall' => false ) );

		global $wpdb;

		$wpdb->insert(
			self::$hpos_meta_table,
			array(
				'order_id'   => 502,
				'meta_key'   => '_stats_umami_woo_tracked',
				'meta_value' => '1',
			)
		);

		$this->run_uninstall();

		$this->assertSame( 1, $this->count_tracked_rows() );
		$this->assertNotFalse( get_option( self::OPTION_KEY ) );
	}

	public function test_setup_notice_user_meta_is_deleted_on_opt_in_uninstall() {
		$user_id_1 = self::factory()->user->create();
		$user_id_2 = self::factory()->user->create();

		add_user_meta( $user_id_1, 'stats_umami_setup_notice_dismissed', 1 );
		add_user_meta( $user_id_2, 'stats_umami_setup_notice_dismissed', 1 );

		update_option( self::OPTION_KEY, array( 'delete_data_on_uninstall' => true ) );

		// Sanity: the meta genuinely exists on both users before uninstall runs.
		$this->assertSame( '1', get_user_meta( $user_id_1, 'stats_umami_setup_notice_dismissed', true ) );
		$this->assertSame( '1', get_user_meta( $user_id_2, 'stats_umami_setup_notice_dismissed', true ) );

		$this->run_uninstall();

		$this->assertSame( '', get_user_meta( $user_id_1, 'stats_umami_setup_notice_dismissed', true ) );
		$this->assertSame( '', get_user_meta( $user_id_2, 'stats_umami_setup_notice_dismissed', true ) );
	}

	public function test_setup_notice_user_meta_is_kept_when_delete_data_is_off() {
		$user_id = self::factory()->user->create();

		add_user_meta( $user_id, 'stats_umami_setup_notice_dismissed', 1 );

		update_option( self::OPTION_KEY, array( 'delete_data_on_uninstall' => false ) );

		$this->run_uninstall();

		$this->assertSame( '1', get_user_meta( $user_id, 'stats_umami_setup_notice_dismissed', true ) );
	}
}
