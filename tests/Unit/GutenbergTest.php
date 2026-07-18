<?php
/**
 * Unit tests for StatsUmami\Integrations\Gutenberg.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Unit;

use Brain\Monkey\Functions;
use StatsUmami\Integrations\Gutenberg;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \StatsUmami\Integrations\Gutenberg
 */
final class GutenbergTest extends TestCase {

	protected function set_up() {
		parent::set_up();

		// esc_attr() is stubbed as identity here (matching TrackerTest's
		// precedent) because these tests are about the PURE sanitization/
		// injection logic; real WP escaping of an actually-hostile value is
		// exercised by the DB-backed GutenbergIntegrationTest, which runs
		// inside a real WP bootstrap.
		Functions\when( 'esc_attr' )->alias(
			static function ( $value ) {
				return (string) $value;
			}
		);
	}

	// ---------------------------------------------------------------
	// inject_event_attributes(): the render_block filter callback. The
	// sanitize_event_name()/sanitize_key()/build_attribute_string() logic
	// itself now lives in, and is unit-tested via,
	// StatsUmami\Support\EventAttributes (promoted there in 3.7 - see
	// EventAttributesTest - once CF7 + WPForms became the 2nd/3rd consumers).
	// ---------------------------------------------------------------

	private function button_markup() {
		return '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com">Sign up</a></div>';
	}

	public function test_injects_single_event_attribute_and_one_data_attribute_per_pair() {
		$block = array(
			'blockName' => 'core/button',
			'attrs'     => array(
				'umamiEvent'     => 'signup',
				'umamiDataPairs' => array(
					array(
						'key'   => 'Plan',
						'value' => 'pro',
					),
				),
			),
		);

		$result = Gutenberg::inject_event_attributes( $this->button_markup(), $block );

		$this->assertSame( 1, substr_count( $result, 'data-umami-event=' ) );
		$this->assertStringContainsString( 'data-umami-event="signup"', $result );
		$this->assertStringContainsString( 'data-umami-event-plan="pro"', $result );
	}

	public function test_returns_content_unchanged_for_a_non_button_block() {
		$block = array(
			'blockName' => 'core/paragraph',
			'attrs'     => array( 'umamiEvent' => 'signup' ),
		);

		$content = '<p>Hello</p>';

		$this->assertSame( $content, Gutenberg::inject_event_attributes( $content, $block ) );
	}

	public function test_returns_content_unchanged_when_no_umami_event_is_set() {
		$block = array(
			'blockName' => 'core/button',
			'attrs'     => array(),
		);

		$this->assertSame( $this->button_markup(), Gutenberg::inject_event_attributes( $this->button_markup(), $block ) );
	}

	public function test_returns_content_unchanged_when_umami_event_is_blank() {
		$block = array(
			'blockName' => 'core/button',
			'attrs'     => array( 'umamiEvent' => '   ' ),
		);

		$this->assertSame( $this->button_markup(), Gutenberg::inject_event_attributes( $this->button_markup(), $block ) );
	}

	public function test_dedupe_guard_leaves_a_pre_existing_attribute_single() {
		$block = array(
			'blockName' => 'core/button',
			'attrs'     => array( 'umamiEvent' => 'new-event' ),
		);

		$already_tagged = '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" data-umami-event="already" href="https://example.com">Sign up</a></div>';

		$result = Gutenberg::inject_event_attributes( $already_tagged, $block );

		$this->assertSame( 1, substr_count( $result, 'data-umami-event=' ) );
		$this->assertStringContainsString( 'data-umami-event="already"', $result );
		$this->assertStringNotContainsString( 'new-event', $result );
	}

	public function test_data_pair_with_unsanitizable_key_is_skipped() {
		$block = array(
			'blockName' => 'core/button',
			'attrs'     => array(
				'umamiEvent'     => 'signup',
				'umamiDataPairs' => array(
					array(
						'key'   => '!!!',
						'value' => 'ignored',
					),
				),
			),
		);

		$result = Gutenberg::inject_event_attributes( $this->button_markup(), $block );

		$this->assertStringNotContainsString( 'ignored', $result );
		$this->assertSame( 1, substr_count( $result, 'data-umami-event' ) );
	}

	public function test_missing_block_name_returns_content_unchanged() {
		$block = array( 'attrs' => array( 'umamiEvent' => 'signup' ) );

		$this->assertSame( $this->button_markup(), Gutenberg::inject_event_attributes( $this->button_markup(), $block ) );
	}

	// ---------------------------------------------------------------
	// core/button's own `tagName` attribute (enum a|button) lets it save as
	// a <button> instead of an <a> - confirmed present in WP core's
	// block-library bundle from ~6.4+, still within our supported 6.0-7.0
	// range (absent on the 6.0 floor, which only ever saves <a>).
	// ---------------------------------------------------------------

	private function button_tag_markup() {
		return '<div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button">Sign up</button></div>';
	}

	public function test_injects_into_a_button_tag_variant() {
		$block = array(
			'blockName' => 'core/button',
			'attrs'     => array( 'umamiEvent' => 'signup' ),
		);

		$result = Gutenberg::inject_event_attributes( $this->button_tag_markup(), $block );

		$this->assertSame( 1, substr_count( $result, 'data-umami-event=' ) );
		$this->assertStringContainsString( 'data-umami-event="signup"', $result );
	}

	public function test_dedupe_guard_leaves_a_pre_existing_attribute_single_on_a_button_tag() {
		$block = array(
			'blockName' => 'core/button',
			'attrs'     => array( 'umamiEvent' => 'new-event' ),
		);

		$already_tagged = '<div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button" data-umami-event="already">Sign up</button></div>';

		$result = Gutenberg::inject_event_attributes( $already_tagged, $block );

		$this->assertSame( 1, substr_count( $result, 'data-umami-event=' ) );
		$this->assertStringContainsString( 'data-umami-event="already"', $result );
		$this->assertStringNotContainsString( 'new-event', $result );
	}
}
