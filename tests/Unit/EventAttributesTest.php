<?php
/**
 * Unit tests for StatsUmami\Support\EventAttributes.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Unit;

use Brain\Monkey\Functions;
use StatsUmami\Support\EventAttributes;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \StatsUmami\Support\EventAttributes
 */
final class EventAttributesTest extends TestCase {

	protected function set_up() {
		parent::set_up();

		// esc_attr() is stubbed as identity here (matching GutenbergTest's
		// precedent) because these tests are about the PURE sanitization/
		// build logic; real WP escaping of an actually-hostile value is
		// exercised by the DB-backed integration tests, which run inside a
		// real WP bootstrap.
		Functions\when( 'esc_attr' )->alias(
			static function ( $value ) {
				return (string) $value;
			}
		);

		// Identity/slugify stub, matching ContactForm7Test/WPFormsTest's
		// precedent - resolve_event_name() calls sanitize_title().
		Functions\when( 'sanitize_title' )->alias(
			static function ( $value ) {
				return strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $value ), '-' ) );
			}
		);
	}

	// ---------------------------------------------------------------
	// resolve_event_name(): the title-slug fallback shared by
	// ContactForm7 and WPForms (moved the real
	// implementation here off ContactForm7 so neither form integration
	// depends on the other).
	// ---------------------------------------------------------------

	public function test_resolve_event_name_uses_stored_event_when_set() {
		$this->assertSame( 'signup', EventAttributes::resolve_event_name( 'signup', 'Contact form 1' ) );
	}

	public function test_resolve_event_name_falls_back_to_title_slug_when_stored_event_is_empty() {
		$this->assertSame( 'contact-form-1', EventAttributes::resolve_event_name( '', 'Contact form 1' ) );
	}

	public function test_resolve_event_name_falls_back_to_title_slug_when_stored_event_is_whitespace() {
		$this->assertSame( 'contact-form-1', EventAttributes::resolve_event_name( '   ', 'Contact form 1' ) );
	}

	public function test_resolve_event_name_clamps_stored_event_to_fifty_characters() {
		$long = str_repeat( 'a', 80 );

		$this->assertSame( str_repeat( 'a', 50 ), EventAttributes::resolve_event_name( $long, 'Contact form 1' ) );
	}

	// ---------------------------------------------------------------
	// sanitize_event_name(): pure, no WP calls.
	// ---------------------------------------------------------------

	public function test_sanitize_event_name_trims_whitespace() {
		$this->assertSame( 'signup', EventAttributes::sanitize_event_name( '  signup  ' ) );
	}

	public function test_sanitize_event_name_clamps_to_fifty_characters() {
		$long = str_repeat( 'a', 80 );

		$this->assertSame( str_repeat( 'a', 50 ), EventAttributes::sanitize_event_name( $long ) );
	}

	public function test_sanitize_event_name_returns_empty_string_for_non_string() {
		$this->assertSame( '', EventAttributes::sanitize_event_name( array( 'not', 'a', 'string' ) ) );
		$this->assertSame( '', EventAttributes::sanitize_event_name( null ) );
	}

	public function test_sanitize_event_name_returns_empty_string_for_blank_input() {
		$this->assertSame( '', EventAttributes::sanitize_event_name( '   ' ) );
		$this->assertSame( '', EventAttributes::sanitize_event_name( '' ) );
	}

	// ---------------------------------------------------------------
	// Verified against
	// the real Umami 3.2 stack: the clamp must bound the result to 50
	// UTF-16 CODE UNITS, not 50 code points - Umami's own truncateString()
	// (src/lib/format.ts:126) re-clamps every event name via a plain
	// `value.substring(0, 50)` (a code-UNIT cut), so a code-point clamp
	// that still exceeds 50 code units for an astral-heavy name gets
	// RE-SPLIT by Umami itself. Must fail against today's
	// mb_substr(..., 0, 50) (a code-POINT clamp).
	// ---------------------------------------------------------------

	public function test_sanitize_event_name_drops_an_astral_character_whole_when_it_would_not_fit_within_fifty_code_units() {
		$emoji = "\xF0\x9F\x98\x80"; // U+1F600 "😀" - a surrogate pair (2 UTF-16 code units, 1 code point).
		$name  = str_repeat( 'z', 49 ) . $emoji; // 49 code units + 2 = 51 code units total.

		$result = EventAttributes::sanitize_event_name( $name );

		// The un-fittable emoji is dropped whole - the result is exactly the
		// 49 ASCII characters, never a broken/incomplete UTF-8 sequence and
		// never the intact emoji pushing the result to 51 code units (which
		// Umami would then re-split itself).
		$this->assertSame( str_repeat( 'z', 49 ), $result );
		$this->assertSame( 49, mb_strlen( $result ) );
	}

	public function test_sanitize_event_name_keeps_an_astral_character_intact_when_it_fits_exactly_within_fifty_code_units() {
		$emoji = "\xF0\x9F\x98\x80";
		$name  = str_repeat( 'z', 48 ) . $emoji; // 48 + 2 = 50 code units total - fits exactly.

		$this->assertSame( $name, EventAttributes::sanitize_event_name( $name ) );
	}

	public function test_sanitize_event_name_keeps_twenty_five_astral_characters_fully_intact() {
		$name = str_repeat( "\xF0\x9F\x98\x80", 25 ); // 25 * 2 = 50 code units, 25 code points.

		$this->assertSame( $name, EventAttributes::sanitize_event_name( $name ) );
	}

	// ---------------------------------------------------------------
	// sanitize_key(): pure, no WP calls.
	// ---------------------------------------------------------------

	public function test_sanitize_key_lowercases() {
		$this->assertSame( 'plan', EventAttributes::sanitize_key( 'PLAN' ) );
	}

	public function test_sanitize_key_strips_disallowed_characters() {
		$this->assertSame( 'plantype', EventAttributes::sanitize_key( 'plan type!' ) );
		$this->assertSame( 'plan_type-2', EventAttributes::sanitize_key( 'plan_type-2' ) );
	}

	public function test_sanitize_key_returns_empty_string_for_non_string() {
		$this->assertSame( '', EventAttributes::sanitize_key( array( 'x' ) ) );
	}

	public function test_sanitize_key_returns_empty_string_when_every_character_is_stripped() {
		$this->assertSame( '', EventAttributes::sanitize_key( '!!!' ) );
	}

	// ---------------------------------------------------------------
	// build_attribute_string()
	// ---------------------------------------------------------------

	public function test_build_attribute_string_with_no_data_pairs() {
		$this->assertSame( 'data-umami-event="signup"', EventAttributes::build_attribute_string( 'signup', array() ) );
	}

	public function test_build_attribute_string_appends_one_attribute_per_valid_pair() {
		$result = EventAttributes::build_attribute_string(
			'signup',
			array(
				array(
					'key'   => 'Plan',
					'value' => 'pro',
				),
				array(
					'key'   => 'source',
					'value' => 'footer',
				),
			)
		);

		$this->assertSame( 'data-umami-event="signup" data-umami-event-plan="pro" data-umami-event-source="footer"', $result );
	}

	public function test_build_attribute_string_skips_pairs_with_unsanitizable_or_missing_keys() {
		$result = EventAttributes::build_attribute_string(
			'signup',
			array(
				array(
					'key'   => '!!!',
					'value' => 'ignored',
				),
				array( 'value' => 'also-ignored' ),
				'not-an-array',
			)
		);

		$this->assertSame( 'data-umami-event="signup"', $result );
	}

	/**
	 * A non-scalar 'value' (e.g. a
	 * hand-written `"value": ["x"]` block comment - survives kses) must not
	 * be cast to string - (string) on an array raises a PHP
	 * "Array to string conversion" warning AND renders the literal string
	 * "Array" as the attribute value. A temporary error handler catches any
	 * warning the (string) cast would have raised.
	 */
	public function test_build_attribute_string_skips_a_non_scalar_value_without_warning_or_stringifying() {
		$warnings = array();

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test-only: temporarily captures PHP warnings (e.g. "Array to string conversion") as assertable data, restored immediately below; never shipped.
			static function ( $errno, $errstr ) use ( &$warnings ) {
				$warnings[] = $errstr;

				return true;
			}
		);

		$result = EventAttributes::build_attribute_string(
			'signup',
			array(
				array(
					'key'   => 'tags',
					'value' => array( 'x' ),
				),
			)
		);

		restore_error_handler();

		$this->assertSame( array(), $warnings );
		$this->assertStringNotContainsString( 'Array', $result );
		$this->assertSame( 'data-umami-event="signup" data-umami-event-tags=""', $result );
	}

	// ---------------------------------------------------------------
	// build_prefixed_event_attributes(): the CF7/WPForms success-event
	// attribute pair (deliberately NOT data-umami-event* - see docblock).
	// ---------------------------------------------------------------

	public function test_build_prefixed_event_attributes_with_no_data_pairs() {
		$this->assertSame(
			'data-umami-cf7-event="signup"',
			EventAttributes::build_prefixed_event_attributes( 'cf7', 'signup', array() )
		);
	}

	public function test_build_prefixed_event_attributes_uses_the_given_prefix() {
		$this->assertStringStartsWith(
			'data-umami-wpforms-event=',
			EventAttributes::build_prefixed_event_attributes( 'wpforms', 'contact', array() )
		);
	}

	public function test_build_prefixed_event_attributes_encodes_data_pairs_as_one_json_attribute() {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$result = EventAttributes::build_prefixed_event_attributes(
			'cf7',
			'signup',
			array(
				array(
					'key'   => 'Plan',
					'value' => 'pro',
				),
				array(
					'key'   => 'source',
					'value' => 'footer',
				),
			)
		);

		$this->assertStringContainsString( 'data-umami-cf7-event="signup"', $result );
		$this->assertStringContainsString( 'data-umami-cf7-data=', $result );
		// esc_attr() is identity-stubbed in this suite (see set_up()), so the
		// raw JSON's quotes are visible here rather than &quot;-escaped - a
		// real WP bootstrap's esc_attr() is exercised by the DB-backed
		// integration tests instead.
		$this->assertStringContainsString( '{"plan":"pro","source":"footer"}', $result );
	}

	public function test_build_prefixed_event_attributes_skips_pairs_with_unsanitizable_or_missing_keys() {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$result = EventAttributes::build_prefixed_event_attributes(
			'cf7',
			'signup',
			array(
				array(
					'key'   => '!!!',
					'value' => 'ignored',
				),
				array( 'value' => 'also-ignored' ),
				'not-an-array',
			)
		);

		$this->assertSame( 'data-umami-cf7-event="signup"', $result );
	}

	/**
	 * (The sibling guard at :135 - the same cast
	 * this class's build_attribute_string() test above covers at :88).
	 */
	public function test_build_prefixed_event_attributes_skips_a_non_scalar_value_without_warning_or_stringifying() {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$warnings = array();

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test-only: temporarily captures PHP warnings (e.g. "Array to string conversion") as assertable data, restored immediately below; never shipped.
			static function ( $errno, $errstr ) use ( &$warnings ) {
				$warnings[] = $errstr;

				return true;
			}
		);

		$result = EventAttributes::build_prefixed_event_attributes(
			'cf7',
			'signup',
			array(
				array(
					'key'   => 'tags',
					'value' => array( 'x' ),
				),
			)
		);

		restore_error_handler();

		$this->assertSame( array(), $warnings );
		$this->assertStringNotContainsString( 'Array', $result );
		$this->assertSame( 'data-umami-cf7-event="signup" data-umami-cf7-data="{"tags":""}"', $result );
	}

	// ---------------------------------------------------------------
	// decode_data_pairs_json()
	// ---------------------------------------------------------------

	public function test_decode_data_pairs_json_converts_object_to_pair_array() {
		$pairs = EventAttributes::decode_data_pairs_json( '{"plan":"pro","source":"footer"}' );

		$this->assertSame(
			array(
				array(
					'key'   => 'plan',
					'value' => 'pro',
				),
				array(
					'key'   => 'source',
					'value' => 'footer',
				),
			),
			$pairs
		);
	}

	public function test_decode_data_pairs_json_returns_empty_array_for_blank_or_invalid_input() {
		$this->assertSame( array(), EventAttributes::decode_data_pairs_json( '' ) );
		$this->assertSame( array(), EventAttributes::decode_data_pairs_json( null ) );
		$this->assertSame( array(), EventAttributes::decode_data_pairs_json( 'not json' ) );
		$this->assertSame( array(), EventAttributes::decode_data_pairs_json( '"just a string"' ) );
	}
}
