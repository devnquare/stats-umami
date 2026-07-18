<?php
/**
 * Unit tests for StatsUmami\Integrations\ContactForm7's pure logic: the
 * title-slug fallback decision and the two string-in/string-out inject
 * transforms. Form resolution + meta storage (real WP calls) are exercised
 * by the DB-backed ContactForm7IntegrationTest instead.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Unit;

use Brain\Monkey\Functions;
use StatsUmami\Integrations\ContactForm7;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \StatsUmami\Integrations\ContactForm7
 */
final class ContactForm7Test extends TestCase {

	protected function set_up() {
		parent::set_up();

		// Identity stub, matching GutenbergTest's precedent - these tests
		// are about the PURE logic; real WP escaping/slugifying of hostile
		// input is exercised by the DB-backed integration test.
		Functions\when( 'esc_attr' )->alias(
			static function ( $value ) {
				return (string) $value;
			}
		);

		Functions\when( 'sanitize_title' )->alias(
			static function ( $value ) {
				return strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $value ), '-' ) );
			}
		);
	}

	// ---------------------------------------------------------------
	// resolve_event_name(): the title-slug fallback decision.
	// ---------------------------------------------------------------

	public function test_resolve_event_name_uses_stored_event_when_set() {
		$this->assertSame( 'signup', ContactForm7::resolve_event_name( 'signup', 'Contact form 1' ) );
	}

	public function test_resolve_event_name_falls_back_to_title_slug_when_stored_event_is_empty() {
		$this->assertSame( 'contact-form-1', ContactForm7::resolve_event_name( '', 'Contact form 1' ) );
	}

	public function test_resolve_event_name_falls_back_to_title_slug_when_stored_event_is_whitespace() {
		$this->assertSame( 'contact-form-1', ContactForm7::resolve_event_name( '   ', 'Contact form 1' ) );
	}

	public function test_resolve_event_name_clamps_stored_event_to_fifty_characters() {
		$long = str_repeat( 'a', 80 );

		$this->assertSame( str_repeat( 'a', 50 ), ContactForm7::resolve_event_name( $long, 'Contact form 1' ) );
	}

	// ---------------------------------------------------------------
	// inject_into_form(): pure string-in/string-out. Targets the
	// <form class="wpcf7-form"> element (not the submit control) - see the
	// method's docblock for why (data-umami-event is Umami's own native
	// click-track attribute; the renamed pair fires on success instead).
	// ---------------------------------------------------------------

	public function test_injects_event_and_skip_attributes_onto_the_form_element() {
		$markup = '<form action="/" method="post" class="wpcf7-form init"><input type="submit" value="Send" /></form>';

		$result = ContactForm7::inject_into_form( $markup, 'data-umami-cf7-event="signup"' );

		$this->assertSame( 1, substr_count( $result, 'data-umami-cf7-event=' ) );
		$this->assertStringContainsString( 'data-umami-cf7-event="signup"', $result );
		$this->assertSame( 1, substr_count( $result, 'data-umami-skip=' ) );
		$this->assertStringContainsString( 'data-umami-skip="1"', $result );
		// Never on the submit control - only Gutenberg/native-click cases use
		// data-umami-event*, and CF7's submit control must carry neither.
		$this->assertStringNotContainsString( 'data-umami-event=', $result );
	}

	public function test_injects_data_attribute_when_present_in_the_prebuilt_string() {
		$markup = '<form class="wpcf7-form">stuff</form>';

		$result = ContactForm7::inject_into_form( $markup, 'data-umami-cf7-event="signup" data-umami-cf7-data="{&quot;plan&quot;:&quot;pro&quot;}"' );

		$this->assertStringContainsString( 'data-umami-cf7-event="signup"', $result );
		$this->assertStringContainsString( 'data-umami-cf7-data=', $result );
	}

	public function test_dedupe_guard_leaves_a_pre_existing_event_attribute_single() {
		$markup = '<form class="wpcf7-form" data-umami-cf7-event="already" data-umami-skip="1">stuff</form>';

		$result = ContactForm7::inject_into_form( $markup, 'data-umami-cf7-event="new-event"' );

		$this->assertSame( 1, substr_count( $result, 'data-umami-cf7-event=' ) );
		$this->assertStringContainsString( 'data-umami-cf7-event="already"', $result );
		$this->assertStringNotContainsString( 'new-event', $result );
		$this->assertSame( 1, substr_count( $result, 'data-umami-skip=' ) );
	}

	public function test_returns_markup_unchanged_when_no_cf7_form_is_present() {
		$markup = '<p>No form here.</p>';

		$this->assertSame( $markup, ContactForm7::inject_into_form( $markup, 'data-umami-cf7-event="signup"' ) );
	}
}
