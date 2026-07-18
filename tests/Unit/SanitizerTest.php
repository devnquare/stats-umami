<?php
/**
 * Unit tests for StatsUmami\Settings\Sanitizer.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Unit;

use Brain\Monkey\Functions;
use StatsUmami\Settings\Sanitizer;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \StatsUmami\Settings\Sanitizer
 */
final class SanitizerTest extends TestCase {

	protected function set_up() {
		parent::set_up();

		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				$key = strtolower( (string) $key );
				return preg_replace( '/[^a-z0-9_\-]/', '', $key );
			}
		);

		Functions\when( 'wp_unslash' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? stripslashes( $value ) : $value;
			}
		);

		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) );
			}
		);

		Functions\when( 'esc_url_raw' )->alias(
			static function ( $value ) {
				return trim( (string) $value );
			}
		);

		Functions\when( 'wp_roles' )->justReturn(
			(object) array(
				'roles' => array(
					'administrator' => array(),
					'editor'        => array(),
					'author'        => array(),
					'shop_manager'  => array(),
				),
			)
		);
	}

	private function current_defaults() {
		return array(
			'enabled'                  => false,
			'host_url'                 => '',
			'website_id'               => '',
			'script_loading'           => 'defer',
			'share_url'                => '',
			'share_url_roles'          => array(),
			'dashboard_widget'         => true,
			'autotrack_links'          => false,
			'autotrack_buttons'        => true,
			'autotrack_forms'          => true,
			'autotrack_outbound'       => true,
			'track_comments'           => false,
			'enable_gutenberg'         => true,
			'enable_cf7'               => true,
			'enable_wpforms'           => true,
			'enable_woocommerce'       => true,
			'excluded_roles'           => array( 'administrator', 'editor', 'shop_manager' ),
			'host_url_override'        => '',
			'domains'                  => '',
			'tag'                      => '',
			'performance_tracking'     => false,
			'exclude_search'           => false,
			'exclude_hash'             => false,
			'do_not_track'             => false,
			'auto_pageview'            => true,
			'delete_data_on_uninstall' => false,
		);
	}

	public function test_general_tab_only_updates_general_fields() {
		$current                    = $this->current_defaults();
		$current['autotrack_links'] = true; // Belongs to another tab; must survive untouched.

		$input = array(
			'_tab'                 => 'general',
			'enabled'              => '1',
			'host_url'             => ' https://analytics.example.com ',
			'website_id'           => 'a1b2c3d4-e5f6-4789-8abc-def012345678',
			'share_url'            => 'https://analytics.example.com/share/x',
			'performance_tracking' => '1',
		);

		$result = Sanitizer::sanitize( $input, $current );

		$this->assertTrue( $result['enabled'] );
		$this->assertSame( 'https://analytics.example.com', $result['host_url'] );
		$this->assertSame( 'a1b2c3d4-e5f6-4789-8abc-def012345678', $result['website_id'] );
		// performance_tracking moved to General - proven saved from here.
		$this->assertTrue( $result['performance_tracking'] );
		// Untouched by this tab's submission.
		$this->assertTrue( $result['autotrack_links'], 'Fields outside the submitted tab must be preserved.' );
		// script_loading moved OUT of General to Advanced - absent from
		// General's TAB_FIELDS now, so it simply passes through from
		// $current unsanitized-again (not re-derived via the allowlist).
		$this->assertSame( 'defer', $result['script_loading'] );
	}

	public function test_invalid_uuid_keeps_current_website_id() {
		$current               = $this->current_defaults();
		$current['website_id'] = 'a1b2c3d4-e5f6-4789-8abc-def012345678';

		$input = array(
			'_tab'       => 'general',
			'website_id' => 'not-a-uuid',
		);

		$result = Sanitizer::sanitize( $input, $current );

		$this->assertSame( 'a1b2c3d4-e5f6-4789-8abc-def012345678', $result['website_id'] );
	}

	public function test_is_valid_uuid_public_helper() {
		$this->assertTrue( Sanitizer::is_valid_uuid( 'a1b2c3d4-e5f6-4789-8abc-def012345678' ) );
		$this->assertTrue( Sanitizer::is_valid_uuid( 'A1B2C3D4-E5F6-4789-8ABC-DEF012345678' ) );
		$this->assertFalse( Sanitizer::is_valid_uuid( 'not-a-uuid' ) );
		$this->assertFalse( Sanitizer::is_valid_uuid( '' ) );
		$this->assertFalse( Sanitizer::is_valid_uuid( null ) );
		$this->assertFalse( Sanitizer::is_valid_uuid( array() ) );
	}

	public function test_script_loading_rejects_unknown_value() {
		$current = $this->current_defaults();

		$input = array(
			'_tab'           => 'advanced',
			'script_loading' => 'immediate',
		);

		$result = Sanitizer::sanitize( $input, $current );

		$this->assertSame( 'defer', $result['script_loading'] );
	}

	public function test_script_loading_accepts_async() {
		$current = $this->current_defaults();

		$input = array(
			'_tab'           => 'advanced',
			'script_loading' => 'async',
		);

		$result = Sanitizer::sanitize( $input, $current );

		$this->assertSame( 'async', $result['script_loading'] );
	}

	public function test_events_tab_only_updates_events_fields() {
		$current             = $this->current_defaults();
		$current['host_url'] = 'https://existing.example.com';

		$input = array(
			'_tab'               => 'events',
			'autotrack_links'    => '1',
			'enable_woocommerce' => '', // Unchecked.
		);

		$result = Sanitizer::sanitize( $input, $current );

		$this->assertTrue( $result['autotrack_links'] );
		$this->assertFalse( $result['enable_woocommerce'] );
		// General-tab field untouched by the events submission.
		$this->assertSame( 'https://existing.example.com', $result['host_url'] );
	}

	public function test_unchecked_checkbox_absent_from_input_is_false() {
		$current                      = $this->current_defaults();
		$current['autotrack_buttons'] = true;

		$input = array(
			'_tab' => 'events',
			// 'autotrack_buttons' intentionally omitted, simulating an unchecked box.
		);

		$result = Sanitizer::sanitize( $input, $current );

		$this->assertFalse( $result['autotrack_buttons'] );
	}

	public function test_advanced_tab_filters_roles_and_sanitizes_text() {
		$current = $this->current_defaults();

		$input = array(
			'_tab'           => 'advanced',
			'excluded_roles' => array( 'administrator', 'not-a-real-role' ),
			'domains'        => ' example.com ',
			'tag'            => 'campaign-a',
			'auto_pageview'  => '', // Unchecked -> false.
		);

		$result = Sanitizer::sanitize( $input, $current );

		$this->assertSame( array( 'administrator' ), $result['excluded_roles'] );
		$this->assertSame( 'example.com', $result['domains'] );
		$this->assertSame( 'campaign-a', $result['tag'] );
		$this->assertFalse( $result['auto_pageview'] );
	}

	public function test_advanced_tab_sanitizes_host_url_override_and_remaining_toggle_fields() {
		$current = $this->current_defaults();

		$input = array(
			'_tab'              => 'advanced',
			'host_url_override' => ' https://cdn.example.com ',
			'script_loading'    => 'async',
			'exclude_search'    => '1',
			'exclude_hash'      => '1',
			'do_not_track'      => '1',
		);

		$result = Sanitizer::sanitize( $input, $current );

		$this->assertSame( 'https://cdn.example.com', $result['host_url_override'] );
		$this->assertSame( 'async', $result['script_loading'] );
		$this->assertTrue( $result['exclude_search'] );
		$this->assertTrue( $result['exclude_hash'] );
		$this->assertTrue( $result['do_not_track'] );
	}

	/**
	 * performance_tracking moved from Advanced to General -
	 * saving the Advanced tab must therefore leave it untouched.
	 */
	public function test_advanced_tab_does_not_touch_performance_tracking() {
		$current                         = $this->current_defaults();
		$current['performance_tracking'] = true;

		$input = array(
			'_tab'           => 'advanced',
			'script_loading' => 'async',
		);

		$result = Sanitizer::sanitize( $input, $current );

		$this->assertTrue( $result['performance_tracking'] );
	}

	/**
	 * script_loading moved from General to Advanced -
	 * saving the General tab must therefore leave it untouched (it isn't in
	 * General's TAB_FIELDS list any more, so it simply passes through from
	 * $current unsanitized-again, matching every other field owned by a
	 * different tab).
	 */
	public function test_general_tab_does_not_touch_script_loading() {
		$current                   = $this->current_defaults();
		$current['script_loading'] = 'async';

		$input = array(
			'_tab'     => 'general',
			'host_url' => 'https://analytics.example.com',
		);

		$result = Sanitizer::sanitize( $input, $current );

		$this->assertSame( 'async', $result['script_loading'] );
	}

	/**
	 * A submission with the same role slug repeated must
	 * be stored de-duplicated - array_intersect() alone keeps duplicates
	 * already present in the submission.
	 */
	public function test_sanitize_roles_deduplicates_repeated_submitted_roles() {
		$current = $this->current_defaults();

		$input = array(
			'_tab'           => 'advanced',
			'excluded_roles' => array( 'administrator', 'administrator', 'editor', 'editor', 'editor' ),
		);

		$result = Sanitizer::sanitize( $input, $current );

		$this->assertSame( array( 'administrator', 'editor' ), $result['excluded_roles'] );
	}

	public function test_tools_tab_only_updates_delete_on_uninstall() {
		$current = $this->current_defaults();

		$input = array(
			'_tab'                     => 'tools',
			'delete_data_on_uninstall' => '1',
		);

		$result = Sanitizer::sanitize( $input, $current );

		$this->assertTrue( $result['delete_data_on_uninstall'] );
	}

	public function test_sanitize_does_not_unslash_text_fields_itself() {
		// Sanitizer assumes its caller (Admin\SettingsPage::sanitize()) has
		// already wp_unslash()'d the whole submitted array ONCE at the
		// boundary. A value containing a literal backslash/quote must pass
		// through unchanged here - if Sanitizer unslashed again internally,
		// this backslash would be silently eaten.
		$current = $this->current_defaults();

		$input = array(
			'_tab'    => 'advanced',
			'domains' => 'O\\\'Brien\\\'s \\"site\\".example.com',
		);

		$result = Sanitizer::sanitize( $input, $current );

		$this->assertSame( 'O\\\'Brien\\\'s \\"site\\".example.com', $result['domains'] );
	}

	public function test_missing_tab_falls_back_to_sanitizing_every_field() {
		$current = $this->current_defaults();

		$input = array(
			'enabled'                  => '1',
			'autotrack_links'          => '1',
			'delete_data_on_uninstall' => '1',
		);

		$result = Sanitizer::sanitize( $input, $current );

		$this->assertTrue( $result['enabled'] );
		$this->assertTrue( $result['autotrack_links'] );
		$this->assertTrue( $result['delete_data_on_uninstall'] );
		// Fields absent from input, but part of the full fallback sweep, are false.
		$this->assertFalse( $result['autotrack_buttons'] );
	}
}
