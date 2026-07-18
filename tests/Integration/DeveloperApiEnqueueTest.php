<?php
/**
 * DB-backed integration tests for StatsUmami\Frontend\DeveloperApi's
 * wp_enqueue_scripts gate - proves frontend.js is enqueued (or not) via the
 * REAL should_output()/wp_enqueue_script()/wp_script_is() machinery against
 * a real WP core bootstrap + test database, not Brain\Monkey mocks. Mirrors
 * the 3.2 Tracker::should_output() gate this reuses.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

use StatsUmami\Frontend\DeveloperApi;
use StatsUmami\Settings\Options;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * @covers \StatsUmami\Frontend\DeveloperApi
 */
final class DeveloperApiEnqueueTest extends TestCase {

	public function set_up() {
		parent::set_up();

		delete_option( Options::OPTION_KEY );
		wp_set_current_user( 0 );

		// wp_scripts() isn't part of WP core's automatic per-test hook
		// backup/restore - reset it explicitly so one test's enqueue state
		// can never leak into the next.
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test-only reset of WP core's own script registry between tests, not a plugin global.
	}

	public function tear_down() {
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test-only reset of WP core's own script registry between tests, not a plugin global.

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
		$options                   = Options::defaults();
		$options['enabled']        = true;
		$options['host_url']       = 'https://analytics.example.com';
		$options['website_id']     = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
		$options['schema_version'] = Options::SCHEMA_VERSION;

		return array_merge( $options, $overrides );
	}

	/**
	 * Register DeveloperApi's hook and fire the real wp_enqueue_scripts
	 * action, exactly as a front-end request does.
	 */
	private function enqueue_frontend_assets() {
		DeveloperApi::register();
		do_action( 'wp_enqueue_scripts' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WordPress core's own hook to simulate a real front-end request, not defining a new hook.
	}

	public function test_frontend_js_is_enqueued_for_an_anonymous_visitor_when_fully_configured() {
		Options::update( $this->trackable_options() );

		$this->enqueue_frontend_assets();

		$this->assertTrue( wp_script_is( 'stats-umami-frontend', 'enqueued' ) );
	}

	public function test_frontend_js_is_enqueued_even_when_every_autotrack_flag_is_off() {
		Options::update(
			$this->trackable_options(
				array(
					'autotrack_links'    => false,
					'autotrack_buttons'  => false,
					'autotrack_forms'    => false,
					'autotrack_outbound' => false,
					'track_comments'     => false,
				)
			)
		);

		$this->enqueue_frontend_assets();

		$this->assertTrue( wp_script_is( 'stats-umami-frontend', 'enqueued' ) );
	}

	public function test_frontend_js_is_not_enqueued_when_disabled() {
		Options::update( $this->trackable_options( array( 'enabled' => false ) ) );

		$this->enqueue_frontend_assets();

		$this->assertFalse( wp_script_is( 'stats-umami-frontend', 'enqueued' ) );
	}

	public function test_frontend_js_is_not_enqueued_for_a_logged_in_excluded_role() {
		Options::update( $this->trackable_options( array( 'excluded_roles' => array( 'administrator' ) ) ) );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->enqueue_frontend_assets();

		$this->assertFalse( wp_script_is( 'stats-umami-frontend', 'enqueued' ) );
	}

	public function test_frontend_js_uses_the_plugin_version_and_has_no_dependencies() {
		Options::update( $this->trackable_options() );

		$this->enqueue_frontend_assets();

		$script = wp_scripts()->registered['stats-umami-frontend'];

		$this->assertSame( STATS_UMAMI_VERSION, $script->ver );
		$this->assertSame( array(), $script->deps );
		$this->assertTrue( (bool) $script->extra['group'] ); // group 1 == footer.
	}
}
