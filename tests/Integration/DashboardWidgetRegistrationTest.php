<?php
/**
 * DB-backed integration tests for StatsUmami\Admin\DashboardWidget's
 * wp_dashboard_setup gating - proves the widget is (or isn't) registered via
 * the REAL wp_add_dashboard_widget()/add_meta_box() machinery against a real
 * WP core bootstrap + test database, asserting the real $GLOBALS['wp_meta_boxes']
 * registry rather than mocking it.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

use StatsUmami\Admin\DashboardWidget;
use StatsUmami\Settings\Options;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * @covers \StatsUmami\Admin\DashboardWidget
 */
final class DashboardWidgetRegistrationTest extends TestCase {

	public function set_up() {
		parent::set_up();

		delete_option( Options::OPTION_KEY );
		wp_set_current_user( 0 );

		// A real dashboard request has already called set_current_screen()
		// by the time wp_dashboard_setup fires; 'index.php' is the real
		// hook suffix for wp-admin's dashboard page and resolves to screen
		// id 'dashboard' (see WP_Screen::get()), exactly like production.
		set_current_screen( 'index.php' );

		// $wp_meta_boxes isn't part of WP core's automatic per-test hook
		// backup/restore - reset it explicitly so one test's registrations
		// can never leak into the next.
		$GLOBALS['wp_meta_boxes'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test-only reset of WP core's own meta-box registry between tests, not a plugin global.
	}

	public function tear_down() {
		$GLOBALS['wp_meta_boxes'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test-only reset of WP core's own meta-box registry between tests, not a plugin global.

		parent::tear_down();
	}

	/**
	 * A fully-configured, trackable options array, with the given overrides
	 * layered on top.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function trackable_options( array $overrides = array() ) {
		$options                     = Options::defaults();
		$options['enabled']          = true;
		$options['host_url']         = 'https://analytics.example.com';
		$options['website_id']       = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
		$options['dashboard_widget'] = true;
		$options['schema_version']   = Options::SCHEMA_VERSION;

		return array_merge( $options, $overrides );
	}

	/**
	 * Register DashboardWidget's hooks and fire the real wp_dashboard_setup
	 * action, exactly as a real dashboard-screen request does.
	 */
	private function fire_dashboard_setup() {
		DashboardWidget::register();
		do_action( 'wp_dashboard_setup' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WordPress core's own hook to simulate a real dashboard request, not defining a new hook.
	}

	/**
	 * @return bool Whether the widget is registered under dashboard/normal/core.
	 */
	private function widget_is_registered() {
		return isset( $GLOBALS['wp_meta_boxes']['dashboard']['normal']['core'][ DashboardWidget::WIDGET_ID ] );
	}

	public function test_widget_is_present_for_an_administrator_when_dashboard_widget_is_on() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		Options::update( $this->trackable_options() );

		$this->fire_dashboard_setup();

		$this->assertTrue( $this->widget_is_registered() );
	}

	public function test_widget_is_absent_when_dashboard_widget_switch_is_off() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		Options::update( $this->trackable_options( array( 'dashboard_widget' => false ) ) );

		$this->fire_dashboard_setup();

		$this->assertFalse( $this->widget_is_registered() );
	}

	public function test_widget_is_absent_for_a_non_admin_role_not_in_share_url_roles() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		Options::update( $this->trackable_options( array( 'share_url_roles' => array( 'author' ) ) ) );

		$this->fire_dashboard_setup();

		$this->assertFalse( $this->widget_is_registered() );
	}

	public function test_widget_is_present_for_a_non_admin_role_in_share_url_roles() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		Options::update( $this->trackable_options( array( 'share_url_roles' => array( 'editor' ) ) ) );

		$this->fire_dashboard_setup();

		$this->assertTrue( $this->widget_is_registered() );
	}
}
