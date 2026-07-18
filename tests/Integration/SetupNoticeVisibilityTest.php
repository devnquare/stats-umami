<?php
/**
 * DB-backed integration tests for StatsUmami\Admin\SetupNotice::should_show()
 * against a real WP core bootstrap + test database - real users/roles, real
 * get_current_screen()/user meta, not mocked.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

use StatsUmami\Admin\SettingsPage;
use StatsUmami\Admin\SetupNotice;
use StatsUmami\Settings\Options;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * @covers \StatsUmami\Admin\SetupNotice
 */
final class SetupNoticeVisibilityTest extends TestCase {

	public function set_up() {
		parent::set_up();

		delete_option( Options::OPTION_KEY );
		wp_set_current_user( 0 );
		set_current_screen( 'index.php' );
	}

	/**
	 * An admin user, current screen NOT the plugin's own settings page, not
	 * dismissed.
	 *
	 * @return int User id.
	 */
	private function admin_on_a_foreign_screen() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		set_current_screen( 'index.php' );

		return $user_id;
	}

	public function test_shows_for_an_admin_when_neutral_not_dismissed_and_not_own_screen() {
		$this->admin_on_a_foreign_screen();

		Options::update( Options::defaults() );

		$this->assertTrue( SetupNotice::should_show() );
	}

	public function test_hidden_when_connected_and_tracking_ok() {
		$this->admin_on_a_foreign_screen();

		$options               = Options::defaults();
		$options['enabled']    = true;
		$options['host_url']   = 'https://analytics.example.com';
		$options['website_id'] = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
		Options::update( $options );

		$this->assertSame( 'ok', SettingsPage::connection_state( Options::get() ) );
		$this->assertFalse( SetupNotice::should_show() );
	}

	public function test_hidden_when_configured_but_tracking_off_warn() {
		$this->admin_on_a_foreign_screen();

		$options               = Options::defaults();
		$options['enabled']    = false;
		$options['host_url']   = 'https://analytics.example.com';
		$options['website_id'] = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
		Options::update( $options );

		$this->assertSame( 'warn', SettingsPage::connection_state( Options::get() ) );
		$this->assertFalse( SetupNotice::should_show() );
	}

	public function test_hidden_once_dismissed_by_current_user() {
		$user_id = $this->admin_on_a_foreign_screen();

		Options::update( Options::defaults() );
		update_user_meta( $user_id, SetupNotice::DISMISSED_META_KEY, 1 );

		$this->assertFalse( SetupNotice::should_show() );
	}

	public function test_hidden_for_a_non_admin_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		set_current_screen( 'index.php' );

		Options::update( Options::defaults() );

		$this->assertFalse( SetupNotice::should_show() );
	}

	public function test_hidden_on_the_plugins_own_settings_screen() {
		$this->admin_on_a_foreign_screen();

		Options::update( Options::defaults() );
		set_current_screen( 'toplevel_page_' . SettingsPage::PAGE_SLUG );

		$this->assertFalse( SetupNotice::should_show() );
	}
}
