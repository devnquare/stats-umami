<?php
/**
 * Unit tests for StatsUmami\Integrations\WPForms's pure logic: the fast-
 * return content guard and the two string-in/string-out inject transforms.
 * Form-settings resolution (real WP calls) is exercised by the DB-backed
 * WPFormsIntegrationTest instead.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Unit;

use Brain\Monkey\Functions;
use StatsUmami\Integrations\WPForms;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \StatsUmami\Integrations\WPForms
 */
final class WPFormsTest extends TestCase {

	protected function set_up() {
		parent::set_up();

		// Identity/slugify stubs, matching ContactForm7Test's precedent -
		// resolve_event_name() delegates to
		// Support\EventAttributes::resolve_event_name(), which calls
		// sanitize_title().
		Functions\when( 'sanitize_title' )->alias(
			static function ( $value ) {
				return strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $value ), '-' ) );
			}
		);
	}

	// ---------------------------------------------------------------
	// resolve_event_name(): the title-slug fallback, delegated to (not
	// re-implemented from) Support\EventAttributes::resolve_event_name()
	// (moved there off ContactForm7).
	// ---------------------------------------------------------------

	public function test_resolve_event_name_uses_stored_event_when_set() {
		$this->assertSame( 'signup', WPForms::resolve_event_name( 'signup', 'A WPForms form' ) );
	}

	public function test_resolve_event_name_falls_back_to_title_slug_when_stored_event_is_empty() {
		$this->assertSame( 'a-wpforms-form', WPForms::resolve_event_name( '', 'A WPForms form' ) );
	}

	// ---------------------------------------------------------------
	// inject_in_content(): the fast-return guard (no WP calls needed
	// when neither marker substring is present).
	// ---------------------------------------------------------------

	public function test_inject_in_content_returns_non_wpforms_content_unchanged() {
		$content = '<p>Nothing to see here.</p>';

		$this->assertSame( $content, WPForms::inject_in_content( $content ) );
	}

	public function test_inject_in_content_returns_non_string_content_unchanged() {
		$this->assertNull( WPForms::inject_in_content( null ) );
		$this->assertFalse( WPForms::inject_in_content( false ) );
	}

	// ---------------------------------------------------------------
	// inject_into_form(): pure string-in/string-out. Targets
	// <form id="wpforms-form-{id}"> (not the submit control) - see the
	// method's docblock for why (data-umami-event is Umami's own native
	// click-track attribute; the renamed pair fires on success instead).
	// ---------------------------------------------------------------

	public function test_injects_event_and_skip_attributes_onto_the_matching_form_element() {
		$markup = '<form id="wpforms-form-42" class="wpforms-validate"><button type="submit" id="wpforms-submit-42">Submit</button></form>';

		$result = WPForms::inject_into_form( $markup, 42, 'data-umami-wpforms-event="contact"' );

		$this->assertSame( 1, substr_count( $result, 'data-umami-wpforms-event=' ) );
		$this->assertStringContainsString( 'data-umami-wpforms-event="contact"', $result );
		$this->assertSame( 1, substr_count( $result, 'data-umami-skip=' ) );
		$this->assertStringContainsString( 'data-umami-skip="1"', $result );
		$this->assertStringNotContainsString( 'data-umami-event=', $result );
	}

	public function test_does_not_inject_into_a_different_form() {
		$markup = '<form id="wpforms-form-99">stuff</form>';

		$result = WPForms::inject_into_form( $markup, 42, 'data-umami-wpforms-event="contact"' );

		$this->assertSame( $markup, $result );
	}

	public function test_dedupe_guard_leaves_a_pre_existing_event_attribute_single() {
		$markup = '<form id="wpforms-form-42" data-umami-wpforms-event="already" data-umami-skip="1">stuff</form>';

		$result = WPForms::inject_into_form( $markup, 42, 'data-umami-wpforms-event="new-event"' );

		$this->assertSame( 1, substr_count( $result, 'data-umami-wpforms-event=' ) );
		$this->assertStringContainsString( 'data-umami-wpforms-event="already"', $result );
		$this->assertSame( 1, substr_count( $result, 'data-umami-skip=' ) );
	}

	public function test_returns_markup_unchanged_when_no_matching_form_is_present() {
		$markup = '<p>No form here.</p>';

		$this->assertSame( $markup, WPForms::inject_into_form( $markup, 42, 'data-umami-wpforms-event="contact"' ) );
	}
}
