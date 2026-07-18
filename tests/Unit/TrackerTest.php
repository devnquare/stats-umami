<?php
/**
 * Unit tests for StatsUmami\Frontend\Tracker.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Unit;

use Brain\Monkey\Functions;
use StatsUmami\Frontend\Tracker;
use StatsUmami\Settings\Options;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \StatsUmami\Frontend\Tracker
 */
final class TrackerTest extends TestCase {

	protected function set_up() {
		parent::set_up();

		Functions\when( 'esc_url' )->alias(
			static function ( $value ) {
				return (string) $value;
			}
		);

		Functions\when( 'esc_attr' )->alias(
			static function ( $value ) {
				return (string) $value;
			}
		);

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $value ) {
				return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		Functions\when( 'home_url' )->justReturn( 'https://site.example.com' );

		Functions\when( 'is_admin' )->justReturn( false );

		Functions\when( 'is_user_logged_in' )->justReturn( false );

		// apply_filters()/do_action()/add_filter() are Brain\Monkey's own
		// real hook-registry implementations (not stubbed here): with no
		// filter/action attached they behave exactly like WordPress core -
		// apply_filters() returns its $value argument unchanged, do_action()
		// is a no-op. Tests that care about a specific hook attach a real
		// callback via add_filter()/add_action() below.
	}

	/**
	 * A fully-configured, trackable options array (all defaults sane),
	 * with the given overrides layered on top.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function full_options( array $overrides = array() ) {
		$defaults = array(
			'enabled'                  => true,
			'host_url'                 => 'https://analytics.example.com',
			'website_id'               => 'a1b2c3d4-e5f6-4789-8abc-def012345678',
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
			'excluded_roles'           => array( 'administrator' ),
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

		return array_merge( $defaults, $overrides );
	}

	/**
	 * Stub Options::get()'s dependencies to return full_options()+$overrides.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 */
	private function stub_options( array $overrides = array() ) {
		Functions\expect( 'get_option' )
			->once()
			->with( Options::OPTION_KEY, array() )
			->andReturn( $this->full_options( $overrides ) );

		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);
	}

	// ---------------------------------------------------------------
	// should_output() truth table.
	// ---------------------------------------------------------------

	public function test_should_output_true_for_anonymous_visitor_when_fully_configured() {
		$this->assertTrue( Tracker::should_output( $this->full_options() ) );
	}

	public function test_should_output_false_when_not_enabled() {
		$this->assertFalse( Tracker::should_output( $this->full_options( array( 'enabled' => false ) ) ) );
	}

	public function test_should_output_false_when_host_url_empty() {
		$this->assertFalse( Tracker::should_output( $this->full_options( array( 'host_url' => '' ) ) ) );
	}

	public function test_should_output_false_when_website_id_empty() {
		$this->assertFalse( Tracker::should_output( $this->full_options( array( 'website_id' => '' ) ) ) );
	}

	public function test_should_output_false_in_wp_admin() {
		Functions\when( 'is_admin' )->justReturn( true );

		$this->assertFalse( Tracker::should_output( $this->full_options() ) );
	}

	public function test_should_output_false_for_logged_in_excluded_role() {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'roles' => array( 'administrator' ) ) );

		$options = $this->full_options( array( 'excluded_roles' => array( 'administrator', 'editor' ) ) );

		$this->assertFalse( Tracker::should_output( $options ) );
	}

	public function test_should_output_true_for_logged_in_non_excluded_role() {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'roles' => array( 'subscriber' ) ) );

		$options = $this->full_options( array( 'excluded_roles' => array( 'administrator', 'editor' ) ) );

		$this->assertTrue( Tracker::should_output( $options ) );
	}

	public function test_should_output_filter_is_authoritative_over_the_computed_value() {
		\Brain\Monkey\Filters\expectApplied( 'stats_umami_should_output' )
			->once()
			->with( false, \Mockery::type( 'array' ) )
			->andReturn( true );

		// enabled=false would normally compute false; the filter overrides it.
		$this->assertTrue( Tracker::should_output( $this->full_options( array( 'enabled' => false ) ) ) );
	}

	// ---------------------------------------------------------------
	// output(): attribute-array building via the rendered <script>.
	// ---------------------------------------------------------------

	public function test_output_prints_nothing_when_should_output_is_false() {
		$this->stub_options( array( 'enabled' => false ) );

		ob_start();
		Tracker::output();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	public function test_output_prints_only_required_attributes_by_default() {
		$this->stub_options();

		ob_start();
		Tracker::output();
		$html = ob_get_clean();

		$this->assertStringContainsString(
			'<script defer src="https://analytics.example.com/script.js" data-website-id="a1b2c3d4-e5f6-4789-8abc-def012345678"></script>', // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- assertion string, not real markup emission.
			$html
		);

		foreach ( array( 'data-host-url', 'data-auto-track', 'data-domains', 'data-tag=', 'data-performance', 'data-exclude-search', 'data-exclude-hash', 'data-do-not-track', 'data-auto-pageview' ) as $absent ) {
			$this->assertStringNotContainsString( $absent, $html );
		}
	}

	public function test_output_uses_async_when_configured() {
		$this->stub_options( array( 'script_loading' => 'async' ) );

		ob_start();
		Tracker::output();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<script async src=', $html ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- assertion string, not real markup emission.
		$this->assertStringNotContainsString( '<script defer', $html );
	}

	public function test_output_includes_every_conditional_attribute_when_set() {
		$this->stub_options(
			array(
				'host_url_override'    => 'https://override.example.com',
				'domains'              => 'example.com,example.org',
				'tag'                  => 'campaign-a',
				'performance_tracking' => true,
				'exclude_search'       => true,
				'exclude_hash'         => true,
				'do_not_track'         => true,
				'auto_pageview'        => false,
			)
		);

		ob_start();
		Tracker::output();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-host-url="https://override.example.com"', $html );
		$this->assertStringNotContainsString( 'data-auto-track', $html );
		$this->assertStringContainsString( 'data-domains="example.com,example.org"', $html );
		$this->assertStringContainsString( 'data-tag="campaign-a"', $html );
		$this->assertStringContainsString( 'data-performance="true"', $html );
		$this->assertStringContainsString( 'data-exclude-search="true"', $html );
		$this->assertStringContainsString( 'data-exclude-hash="true"', $html );
		$this->assertStringContainsString( 'data-do-not-track="true"', $html );
		$this->assertStringContainsString( 'data-auto-pageview="false"', $html );
	}

	public function test_output_omits_auto_pageview_attribute_when_on_by_default() {
		$this->stub_options( array( 'auto_pageview' => true ) );

		ob_start();
		Tracker::output();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'data-auto-pageview', $html );
	}

	public function test_tracker_attributes_filter_can_modify_the_rendered_script() {
		\Brain\Monkey\Filters\expectApplied( 'stats_umami_tracker_attributes' )
			->once()
			->with( \Mockery::type( 'array' ), \Mockery::type( 'array' ) )
			->andReturnUsing(
				static function ( $attributes ) {
					$attributes['data-tag'] = 'filter-added';
					return $attributes;
				}
			);

		$this->stub_options();

		ob_start();
		Tracker::output();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-tag="filter-added"', $html );
	}

	// ---------------------------------------------------------------
	// render_attributes() has an array-typed
	// parameter, and PHP does not coerce null/string into it - a filter
	// callback that forgets a `return` (or returns the wrong type) must not
	// throw a TypeError and white-screen every tracked page; the unfiltered
	// attributes must still be used.
	// ---------------------------------------------------------------

	public function test_tracker_attributes_filter_returning_null_does_not_throw_and_keeps_unfiltered_attributes() {
		\Brain\Monkey\Filters\expectApplied( 'stats_umami_tracker_attributes' )
			->once()
			->andReturn( null );

		$this->stub_options();

		ob_start();
		Tracker::output();
		$html = ob_get_clean();

		$this->assertStringContainsString(
			'<script defer src="https://analytics.example.com/script.js" data-website-id="a1b2c3d4-e5f6-4789-8abc-def012345678"></script>', // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- assertion string, not real markup emission.
			$html
		);
	}

	public function test_tracker_attributes_filter_returning_a_string_does_not_throw_and_keeps_unfiltered_attributes() {
		\Brain\Monkey\Filters\expectApplied( 'stats_umami_tracker_attributes' )
			->once()
			->andReturn( 'not-an-array' );

		$this->stub_options();

		ob_start();
		Tracker::output();
		$html = ob_get_clean();

		$this->assertStringContainsString(
			'<script defer src="https://analytics.example.com/script.js" data-website-id="a1b2c3d4-e5f6-4789-8abc-def012345678"></script>', // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- assertion string, not real markup emission.
			$html
		);
	}

	public function test_tracker_output_action_fires_once_after_the_script_tag() {
		\Brain\Monkey\Actions\expectDone( 'stats_umami_tracker_output' )
			->once()
			->with( \Mockery::type( 'array' ) );

		$this->stub_options();

		ob_start();
		Tracker::output();
		ob_end_clean();
	}

	public function test_output_emits_config_object_with_autotrack_flags_and_site_host() {
		$this->stub_options(
			array(
				'autotrack_links'    => true,
				'autotrack_buttons'  => false,
				'autotrack_forms'    => true,
				'autotrack_outbound' => false,
				'track_comments'     => true,
			)
		);

		ob_start();
		Tracker::output();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/window\.__STATS_UMAMI_CFG__=(\{.*?\});/', $html );

		preg_match( '/window\.__STATS_UMAMI_CFG__=(\{.*?\});/', $html, $matches );
		$config = json_decode( $matches[1], true );

		$this->assertTrue( $config['autotrack_links'] );
		$this->assertFalse( $config['autotrack_buttons'] );
		$this->assertTrue( $config['autotrack_forms'] );
		$this->assertFalse( $config['autotrack_outbound'] );
		$this->assertTrue( $config['track_comments'] );
		$this->assertSame( 'site.example.com', $config['site_host'] );
		// WooCommerce isn't loaded in this unit bootstrap, so class_exists()
		// naturally resolves false here - the true case (a real WooCommerce
		// install) is proven by live verification.
		$this->assertFalse( $config['woo_present'] );
	}

	/**
	 * output_config()'s own JSON_HEX_TAG|AMP|APOS|QUOT
	 * hardening had no test - `grep JSON_HEX tests/` finds
	 * nothing, and deleting the flags leaves the rest of this suite green,
	 * because set_up()'s own wp_json_encode() stub ignores any $flags
	 * argument entirely (ANY test built on that stub is structurally unable
	 * to tell hardened from unhardened). This test installs its OWN
	 * wp_json_encode() alias that genuinely respects flags (a thin wrapper
	 * over the real json_encode()), so it can actually exercise the
	 * hardening on the one field that reaches it: site_host.
	 */
	public function test_output_config_hex_escapes_markup_breakout_characters_in_site_host() {
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $value, $flags = 0 ) {
				return json_encode( $value, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		// wp_parse_url() is aliased to the real parse_url() in set_up();
		// this deliberately malformed home_url() return value is one whose
		// HOST component PHP's parse_url() still extracts verbatim,
		// carrying every hardened character (<, >, &, ', ").
		$malicious_host = "a<b>c&d'e\"f.com";
		Functions\when( 'home_url' )->justReturn( 'https://' . $malicious_host . '/' );

		$this->stub_options();

		ob_start();
		Tracker::output();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/window\.__STATS_UMAMI_CFG__=(\{.*?\});/', $html );

		preg_match( '/window\.__STATS_UMAMI_CFG__=(\{.*?\});/', $html, $matches );

		$this->assertStringNotContainsString( $malicious_host, $matches[1] );

		$hex_tag  = chr( 92 ) . 'u003C'; // backslash + u003C, i.e. escaped "<".
		$hex_amp  = chr( 92 ) . 'u0026'; // escaped "&".
		$hex_apos = chr( 92 ) . 'u0027'; // escaped "'".
		$hex_quot = chr( 92 ) . 'u0022'; // escaped '"'.

		$this->assertStringContainsString( $hex_tag, $matches[1] );
		$this->assertStringContainsString( $hex_amp, $matches[1] );
		$this->assertStringContainsString( $hex_apos, $matches[1] );
		$this->assertStringContainsString( $hex_quot, $matches[1] );

		$config = json_decode( $matches[1], true );
		$this->assertSame( $malicious_host, $config['site_host'] );
	}
}
