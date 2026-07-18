<?php
/**
 * DB-backed integration tests for the [D2] tab-save contract, the unslash
 * boundary, and invalid-website-ID surfacing - run against a real WP core
 * bootstrap + a real test database (see docs/TESTING.md), not Brain\Monkey
 * mocks. These prove the SAME real WordPress functions (wp_unslash(),
 * sanitize_text_field(), get_option()/update_option(), get_settings_errors())
 * behave as intended end to end through Admin\SettingsPage::sanitize().
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

use StatsUmami\Admin\SettingsPage;
use StatsUmami\Settings\Options;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * @covers \StatsUmami\Admin\SettingsPage
 */
final class SettingsPageTabSaveTest extends TestCase {

	public function set_up() {
		parent::set_up();

		delete_option( Options::OPTION_KEY );

		// get_settings_errors() reads a process-global that add_settings_error()
		// appends to; reset it so one test's queued errors can't leak into the
		// next (WP core provides no public reset function for this).
		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test-only reset of WP core's own settings-errors registry between tests, not a plugin global.
	}

	/**
	 * [D2]: saving one tab's form must persist ONLY that tab's keys and
	 * leave the other three tabs' stored values untouched. This is the
	 * exact bug the shared register_setting()/settings_fields() group
	 * (rather than four separate groups) exists to prevent.
	 */
	public function test_saving_general_tab_preserves_the_other_three_tabs_values() {
		$seed                             = Options::defaults();
		$seed['autotrack_links']          = true;
		$seed['enable_woocommerce']       = false;
		$seed['domains']                  = 'example.com';
		$seed['tag']                      = 'campaign-a';
		$seed['delete_data_on_uninstall'] = true;
		Options::update( $seed );

		$input = array(
			'_tab'       => 'general',
			'enabled'    => '1',
			'host_url'   => 'https://analytics.example.com',
			'website_id' => 'a1b2c3d4-e5f6-4789-8abc-def012345678',
		);

		$result = SettingsPage::sanitize( $input );
		Options::update( $result );

		$stored = Options::get();

		// This tab's own submission took effect.
		$this->assertTrue( $stored['enabled'] );
		$this->assertSame( 'https://analytics.example.com', $stored['host_url'] );
		$this->assertSame( 'a1b2c3d4-e5f6-4789-8abc-def012345678', $stored['website_id'] );

		// The other three tabs' values must survive completely untouched.
		$this->assertTrue( $stored['autotrack_links'] );
		$this->assertFalse( $stored['enable_woocommerce'] );
		$this->assertSame( 'example.com', $stored['domains'] );
		$this->assertSame( 'campaign-a', $stored['tag'] );
		$this->assertTrue( $stored['delete_data_on_uninstall'] );
	}

	/**
	 * Unslash boundary: SettingsPage::sanitize() must wp_unslash() the
	 * submission exactly ONCE. A value containing a literal backslash and a
	 * quote proves this precisely - if Sanitizer also unslashed internally
	 * (double-unslash), the backslash would be silently eaten, because real
	 * stripslashes() removes a backslash before ANY character, not only
	 * before quotes.
	 */
	public function test_input_is_unslashed_exactly_once() {
		Options::update( Options::defaults() );

		// Bytes as WordPress's own wp_magic_quotes() (addslashes-style)
		// would leave them in $_POST for a user-typed value of
		// `back\slash and a 'quote`.
		$raw = 'back\\\\slash and a \\\'quote';

		$input = array(
			'_tab'    => 'advanced',
			'domains' => $raw,
		);

		$result = SettingsPage::sanitize( $input );

		$this->assertSame( 'back\\slash and a \'quote', $result['domains'] );
	}

	/**
	 * An invalid website_id is rejected (the previously-stored value is
	 * kept) and a user-facing rejection notice is queued via
	 * add_settings_error() - surfaced on the General tab as the crit
	 * connection state (see SettingsPage::connection_state()).
	 */
	public function test_invalid_website_id_is_rejected_and_reports_a_settings_error() {
		$seed               = Options::defaults();
		$seed['website_id'] = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
		Options::update( $seed );

		$input = array(
			'_tab'       => 'general',
			'website_id' => 'not-a-uuid',
		);

		$result = SettingsPage::sanitize( $input );

		$this->assertSame( 'a1b2c3d4-e5f6-4789-8abc-def012345678', $result['website_id'] );

		$errors = get_settings_errors( Options::OPTION_KEY );
		$codes  = wp_list_pluck( $errors, 'code' );

		$this->assertContains( 'stats_umami_invalid_website_id', $codes );
	}

	/**
	 * A blank website_id submission (e.g. saving the General tab before
	 * ever configuring it) must NOT be treated as an invalid-format
	 * rejection - it is simply "not filled in yet" (the neutral state, not
	 * the crit one).
	 */
	public function test_blank_website_id_is_not_reported_as_invalid() {
		Options::update( Options::defaults() );

		$input = array(
			'_tab'       => 'general',
			'website_id' => '',
		);

		SettingsPage::sanitize( $input );

		$errors = get_settings_errors( Options::OPTION_KEY );
		$codes  = wp_list_pluck( $errors, 'code' );

		$this->assertNotContains( 'stats_umami_invalid_website_id', $codes );
	}

	/**
	 * A deliberately CLEARED website_id must persist as '' - blank is a
	 * valid "unconfigured" value, not a
	 * malformed submission to reject-and-retain. Pre-fix,
	 * Sanitizer::sanitize_uuid() treated blank identically to a malformed
	 * non-blank string (fall back to the current stored value), so clearing
	 * the field and saving silently restored the old ID while the page showed
	 * "Settings saved." with no error. The malformed-non-blank retain+error
	 * behaviour this must NOT disturb is already covered by
	 * test_invalid_website_id_is_rejected_and_reports_a_settings_error() above.
	 */
	public function test_clearing_the_website_id_persists_as_blank() {
		$seed               = Options::defaults();
		$seed['website_id'] = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
		Options::update( $seed );

		$input = array(
			'_tab'       => 'general',
			'website_id' => '',
		);

		$result = SettingsPage::sanitize( $input );

		$this->assertSame( '', $result['website_id'] );
	}

	/**
	 * register_setting()'s sanitize_callback is wired to the GLOBAL
	 * sanitize_option_{$option} WordPress hook - it fires on EVERY
	 * update_option() call for stats_umami_options, not only real tab form
	 * submissions. Options::update() (used internally by the boot-time
	 * migration and by maybe_handle_reset()) must therefore round-trip an
	 * already-valid settings array UNCHANGED through that same global hook,
	 * even when it contains a role the site doesn't currently have
	 * registered (e.g. "shop_manager" while WooCommerce is inactive) - this
	 * is a real WP core gotcha (see SettingsPage::sanitize()'s _tab guard),
	 * caught by going through the REAL sanitize_option filter chain rather
	 * than calling Sanitizer/SettingsPage::sanitize() directly.
	 */
	public function test_internal_options_update_does_not_corrupt_role_arrays() {
		// register_setting() is normally called on admin_init (via
		// SettingsPage::register()), which this bootstrap never fires;
		// call it directly so the real sanitize_option_stats_umami_options
		// filter is actually attached, exactly as it is on every real
		// wp-admin request - otherwise this test would silently pass
		// without ever exercising the bug it exists to catch.
		SettingsPage::register_setting();

		$seed                   = Options::defaults();
		$seed['schema_version'] = Options::SCHEMA_VERSION;
		$seed['excluded_roles'] = array( 'administrator', 'editor', 'shop_manager' );
		Options::update( $seed );

		$stored = Options::get();

		$this->assertSame( array( 'administrator', 'editor', 'shop_manager' ), $stored['excluded_roles'] );
	}

	/**
	 * performance_tracking moved from Advanced to General -
	 * (a) it must genuinely save when the General tab is submitted, and
	 * (b) it must survive completely untouched when the Advanced tab is
	 * submitted (proving it left Sanitizer::TAB_FIELDS['advanced']).
	 */
	public function test_performance_tracking_saves_from_general_and_is_untouched_by_advanced() {
		$seed                         = Options::defaults();
		$seed['performance_tracking'] = false;
		Options::update( $seed );

		$general_input = array(
			'_tab'                 => 'general',
			'performance_tracking' => '1',
		);

		$result = SettingsPage::sanitize( $general_input );
		Options::update( $result );

		$this->assertTrue( Options::get()['performance_tracking'], 'performance_tracking must save from its new tab, General.' );

		$advanced_input = array(
			'_tab'           => 'advanced',
			'script_loading' => 'async',
		);

		$result = SettingsPage::sanitize( $advanced_input );
		Options::update( $result );

		$this->assertTrue( Options::get()['performance_tracking'], 'performance_tracking must be left untouched by an Advanced-tab save.' );
	}

	/**
	 * script_loading moved from General to Advanced -
	 * (a) it must genuinely save when the Advanced tab is submitted, and
	 * (b) it must survive completely untouched when the General tab is
	 * submitted (proving it left Sanitizer::TAB_FIELDS['general']).
	 */
	public function test_script_loading_saves_from_advanced_and_is_untouched_by_general() {
		$seed                   = Options::defaults();
		$seed['script_loading'] = 'defer';
		Options::update( $seed );

		$advanced_input = array(
			'_tab'           => 'advanced',
			'script_loading' => 'async',
		);

		$result = SettingsPage::sanitize( $advanced_input );
		Options::update( $result );

		$this->assertSame( 'async', Options::get()['script_loading'], 'script_loading must save from its new tab, Advanced.' );

		$general_input = array(
			'_tab'     => 'general',
			'host_url' => 'https://analytics.example.com',
		);

		$result = SettingsPage::sanitize( $general_input );
		Options::update( $result );

		$this->assertSame( 'async', Options::get()['script_loading'], 'script_loading must be left untouched by a General-tab save.' );
	}

	/**
	 * The same `_tab`-less passthrough now type-coerces
	 * its input via Options::coerce_types() before persisting, so a field
	 * that lost its shape (e.g. a WP-CLI write, or a DB import) is never
	 * WRITTEN with the wrong type - while still NOT re-running the
	 * tab-field/role-intersection sweep, which is exactly what would have
	 * dropped "shop_manager" here on a bootstrap with no WooCommerce.
	 */
	public function test_internal_options_update_type_coerces_a_malformed_field_without_dropping_valid_roles() {
		SettingsPage::register_setting();

		$seed                    = Options::defaults();
		$seed['schema_version']  = Options::SCHEMA_VERSION;
		$seed['excluded_roles']  = array( 'administrator', 'editor', 'shop_manager' );
		$seed['share_url_roles'] = 'not-an-array';

		Options::update( $seed );

		$stored = Options::get();

		// The malformed field was type-coerced to its array default at
		// write time - and, redundantly, would be again at read time
		// even if it hadn't been.
		$this->assertSame( array(), $stored['share_url_roles'] );

		// The valid role list survived untouched - the passthrough's whole
		// reason for existing.
		$this->assertSame( array( 'administrator', 'editor', 'shop_manager' ), $stored['excluded_roles'] );
	}
}
