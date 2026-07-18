<?php
/**
 * Unit tests for StatsUmami\Settings\Options.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Unit;

use Brain\Monkey\Functions;
use StatsUmami\Settings\Options;
use StatsUmami\Settings\Sanitizer;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \StatsUmami\Settings\Options
 */
final class OptionsTest extends TestCase {

	public function test_defaults_match_the_locked_product_defaults() {
		$defaults = Options::defaults();

		$this->assertFalse( $defaults['enabled'] );
		$this->assertSame( 'defer', $defaults['script_loading'] );
		$this->assertTrue( $defaults['dashboard_widget'] );
		$this->assertFalse( $defaults['performance_tracking'] );
		$this->assertFalse( $defaults['delete_data_on_uninstall'] );
		$this->assertTrue( $defaults['auto_pageview'] );
		$this->assertSame(
			array( 'administrator', 'editor', 'shop_manager' ),
			$defaults['excluded_roles']
		);
	}

	public function test_get_merges_stored_values_over_defaults() {
		Functions\expect( 'get_option' )
			->once()
			->with( Options::OPTION_KEY, array() )
			->andReturn(
				array(
					'enabled'  => true,
					'host_url' => 'https://umami.example.com',
				)
			);

		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);

		$result = Options::get();

		$this->assertTrue( $result['enabled'] );
		$this->assertSame( 'https://umami.example.com', $result['host_url'] );
		// Untouched keys still come from defaults().
		$this->assertSame( 'defer', $result['script_loading'] );
	}

	public function test_get_tolerates_a_non_array_stored_value() {
		Functions\expect( 'get_option' )
			->once()
			->with( Options::OPTION_KEY, array() )
			->andReturn( false );

		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);

		$result = Options::get();

		$this->assertSame( Options::defaults(), $result );
	}

	public function test_update_persists_the_given_array_verbatim() {
		$data = array( 'enabled' => true );

		Functions\expect( 'update_option' )
			->once()
			->with( Options::OPTION_KEY, $data )
			->andReturn( true );

		$this->assertTrue( Options::update( $data ) );
	}

	public function test_activate_seeds_defaults_when_option_absent() {
		Functions\expect( 'get_option' )
			->once()
			->with( Options::OPTION_KEY, false )
			->andReturn( false );

		Functions\expect( 'add_option' )
			->once()
			->with(
				Options::OPTION_KEY,
				\Mockery::on(
					static function ( $seeded ) {
						return Options::SCHEMA_VERSION === $seeded['schema_version']
							&& false === $seeded['enabled'];
					}
				)
			);

		Options::activate();
	}

	public function test_activate_never_overwrites_an_existing_option() {
		Functions\expect( 'get_option' )
			->once()
			->with( Options::OPTION_KEY, false )
			->andReturn(
				array(
					'enabled'        => true,
					'schema_version' => 1,
				)
			);

		Functions\expect( 'add_option' )->never();

		Options::activate();
	}

	public function test_maybe_migrate_is_a_noop_when_schema_is_current() {
		Functions\expect( 'get_option' )
			->once()
			->with( Options::OPTION_KEY, false )
			->andReturn(
				array_merge( Options::defaults(), array( 'schema_version' => Options::SCHEMA_VERSION ) )
			);

		Functions\expect( 'update_option' )->never();

		Options::maybe_migrate();
	}

	public function test_maybe_migrate_updates_when_schema_is_stale() {
		Functions\expect( 'get_option' )
			->once()
			->with( Options::OPTION_KEY, false )
			->andReturn( array_merge( Options::defaults(), array( 'schema_version' => 0 ) ) );

		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);

		Functions\expect( 'update_option' )
			->once()
			->with(
				Options::OPTION_KEY,
				\Mockery::on(
					static function ( $migrated ) {
						return Options::SCHEMA_VERSION === $migrated['schema_version'];
					}
				)
			)
			->andReturn( true );

		Options::maybe_migrate();
	}

	public function test_maybe_migrate_does_nothing_before_activation_has_seeded_the_option() {
		Functions\expect( 'get_option' )
			->once()
			->with( Options::OPTION_KEY, false )
			->andReturn( false );

		Functions\expect( 'update_option' )->never();

		Options::maybe_migrate();
	}

	/**
	 * The first real schema migration. A v1 stored array
	 * still carrying `disable_umami_auto_track` (removed entirely - it was
	 * redundant with the tracker's own precise toggles and its help text
	 * never warned it also silently suppressed Performance tracking) must
	 * migrate to v2 WITHOUT that key, alongside the schema_version bump. (A
	 * v2 array being a no-op is already covered generically by
	 * test_maybe_migrate_is_a_noop_when_schema_is_current(), since
	 * SCHEMA_VERSION is now 2.)
	 */
	public function test_maybe_migrate_removes_disable_umami_auto_track_when_migrating_from_v1() {
		Functions\expect( 'get_option' )
			->once()
			->with( Options::OPTION_KEY, false )
			->andReturn(
				array_merge(
					Options::defaults(),
					array(
						'schema_version'           => 1,
						'disable_umami_auto_track' => true,
					)
				)
			);

		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);

		Functions\expect( 'update_option' )
			->once()
			->with(
				Options::OPTION_KEY,
				\Mockery::on(
					static function ( $migrated ) {
						return 2 === $migrated['schema_version']
							&& ! array_key_exists( 'disable_umami_auto_track', $migrated );
					}
				)
			)
			->andReturn( true );

		Options::maybe_migrate();
	}

	/**
	 * A stored "roles"-type field that lost its array
	 * shape (e.g. a WP-CLI `wp option update` writing a scalar) must fall
	 * back to its array default rather than reaching a consumer typed
	 * `array $selected` (the admin-page fatal) or
	 * `array $allowed_roles` (the dashboard-widget fatal).
	 */
	public function test_coerce_types_replaces_a_non_array_roles_field_with_its_default() {
		$data                    = Options::defaults();
		$data['share_url_roles'] = 'not-an-array';
		$data['excluded_roles']  = 42;

		$result = Options::coerce_types( $data );

		$this->assertSame( Options::defaults()['share_url_roles'], $result['share_url_roles'] );
		$this->assertSame( Options::defaults()['excluded_roles'], $result['excluded_roles'] );
	}

	/**
	 * A stored string-type field (url/uuid/script_loading/text) that
	 * lost its string shape must fall back to its default, and NEVER be
	 * (string)-cast - an array cast produces the literal "Array" (PHP's
	 * "Array to string" warning class), which is a wrong value, not a
	 * safe one. This is the exact CRITICAL repro shape:
	 * host_url_override stored as an array used to reach
	 * esc_url()'s ltrim() and fatal on wp_head.
	 */
	public function test_coerce_types_replaces_a_non_string_field_with_its_default_never_casting() {
		$data                      = Options::defaults();
		$data['host_url_override'] = array( 'cdn' );
		$data['share_url']         = array( 'x' );
		$data['domains']           = 123;
		$data['website_id']        = false;

		$result = Options::coerce_types( $data );

		$this->assertSame( '', $result['host_url_override'] );
		$this->assertSame( '', $result['share_url'] );
		$this->assertSame( '', $result['domains'] );
		$this->assertSame( '', $result['website_id'] );
	}

	/**
	 * Bool-typed fields are coerced with (bool), which never fatals and
	 * keeps intent for genuinely-boolean-ish stored values.
	 */
	public function test_coerce_types_coerces_bool_typed_fields() {
		$data                         = Options::defaults();
		$data['enabled']              = 1;
		$data['performance_tracking'] = 0;
		$data['dashboard_widget']     = array();

		$result = Options::coerce_types( $data );

		$this->assertTrue( $result['enabled'] );
		$this->assertFalse( $result['performance_tracking'] );
		$this->assertFalse( $result['dashboard_widget'] );
	}

	/**
	 * Valid, already-correctly-shaped stored data must pass
	 * through completely unchanged - the coercion must never mangle good
	 * values.
	 */
	public function test_coerce_types_leaves_valid_stored_values_unchanged() {
		$data                         = Options::defaults();
		$data['host_url']             = 'https://analytics.example.com';
		$data['website_id']           = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
		$data['excluded_roles']       = array( 'administrator', 'shop_manager' );
		$data['enabled']              = true;
		$data['performance_tracking'] = false;

		$result = Options::coerce_types( $data );

		$this->assertSame( $data, $result );
	}

	/**
	 * schema_version and any key not in Sanitizer::FIELD_TYPES (e.g. a
	 * future/unknown key) must pass through untouched - coercion only ever
	 * acts on known settings fields.
	 */
	public function test_coerce_types_preserves_schema_version_and_unknown_keys() {
		$data                            = Options::defaults();
		$data['schema_version']          = 3;
		$data['some_future_unknown_key'] = array( 'whatever' );

		$result = Options::coerce_types( $data );

		$this->assertSame( 3, $result['schema_version'] );
		$this->assertSame( array( 'whatever' ), $result['some_future_unknown_key'] );
	}

	/**
	 * coerce_types() only acts on keys actually present in the given
	 * array (it never invents missing keys) - the [D2] passthrough can
	 * hand it a partial array, not always the full settings shape.
	 */
	public function test_coerce_types_only_touches_keys_present_in_the_input() {
		$result = Options::coerce_types( array( 'host_url_override' => array( 'bad' ) ) );

		$this->assertSame( array( 'host_url_override' => '' ), $result );
	}

	/**
	 * coerce_types() iterates Sanitizer::FIELD_TYPES and
	 * looks up defaults()[$field] for every key - the two lists were only
	 * kept in sync by hand, with nothing enforcing it. A field added to
	 * defaults() ONLY is never type-coerced, silently reopening the whole
	 * type-safety class of bug (which held a CRITICAL front-end fatal: a
	 * non-string host_url_override reaching esc_url()'s ltrim() on
	 * wp_head). A field added to FIELD_TYPES ONLY makes coerce_types() read
	 * an undefined defaults() offset (a PHP notice, then a null default).
	 * schema_version is excluded from both lists deliberately - it is
	 * stored alongside the settings but is not itself a "setting".
	 */
	public function test_defaults_and_field_types_declare_exactly_the_same_fields() {
		$default_fields    = array_diff( array_keys( Options::defaults() ), array( 'schema_version' ) );
		$field_type_fields = array_diff( array_keys( Sanitizer::FIELD_TYPES ), array( 'schema_version' ) );

		sort( $default_fields );
		sort( $field_type_fields );

		$this->assertSame(
			$field_type_fields,
			$default_fields,
			'Options::defaults() and Sanitizer::FIELD_TYPES must declare exactly the same field set. '
			. 'A field present ONLY in defaults() is silently never type-coerced by Options::coerce_types() '
			. '(reopening the type-safety class of bug that held a CRITICAL front-end fatal); a field '
			. 'present ONLY in FIELD_TYPES makes coerce_types() read an undefined defaults() offset.'
		);
	}

	/**
	 * End-to-end: Options::get() itself applies the coercion after the
	 * wp_parse_args() merge - this is the actual CRITICAL fix,
	 * proven at the unit level (the live equivalent is the WP-CLI repro in
	 * the acceptance evidence).
	 */
	public function test_get_re_coerces_a_malformed_stored_host_url_override() {
		Functions\expect( 'get_option' )
			->once()
			->with( Options::OPTION_KEY, array() )
			->andReturn( array( 'host_url_override' => array( 'cdn' ) ) );

		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);

		$result = Options::get();

		$this->assertSame( '', $result['host_url_override'] );
	}
}
