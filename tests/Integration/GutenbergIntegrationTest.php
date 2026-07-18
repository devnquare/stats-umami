<?php
/**
 * DB-backed integration tests for the Gutenberg core/button integration:
 * proves render_block injection (via the REAL do_blocks()/render_block
 * pipeline, real esc_attr() escaping) and Integrations\Manager's
 * master-switch / enable_gutenberg registration gating against a real WP
 * core bootstrap + test database.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

use StatsUmami\Integrations\Gutenberg;
use StatsUmami\Integrations\Manager;
use StatsUmami\Settings\Options;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * @covers \StatsUmami\Integrations\Manager
 * @covers \StatsUmami\Integrations\Gutenberg
 */
final class GutenbergIntegrationTest extends TestCase {

	public function set_up() {
		parent::set_up();

		delete_option( Options::OPTION_KEY );
	}

	/**
	 * A fully-configured, trackable + Gutenberg-enabled options array, with
	 * the given overrides layered on top.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function trackable_options( array $overrides = array() ) {
		$options                     = Options::defaults();
		$options['enabled']          = true;
		$options['enable_gutenberg'] = true;
		$options['schema_version']   = Options::SCHEMA_VERSION;

		return array_merge( $options, $overrides );
	}

	/**
	 * A real serialized core/button block, comment attrs + saved markup,
	 * exactly as WordPress's block parser would encounter it in post_content.
	 *
	 * @param string $attrs_json JSON object literal for the block comment (no surrounding braces needed - pass e.g. '"umamiEvent":"signup"').
	 * @param string $anchor_extra Extra raw text inserted into the saved <a> tag (e.g. a pre-existing data-umami-event attribute), before the closing '>'.
	 * @return string
	 */
	private function button_block_content( $attrs_json, $anchor_extra = '' ) {
		return '<!-- wp:button {' . $attrs_json . '} -->'
			. '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . $anchor_extra . ' href="https://example.com">Sign up</a></div>'
			. '<!-- /wp:button -->';
	}

	public function test_render_block_injects_single_event_attribute_and_data_pair_when_enabled() {
		Options::update( $this->trackable_options() );
		Manager::register();

		$content = $this->button_block_content( '"umamiEvent":"signup","umamiDataPairs":[{"key":"plan","value":"pro"}]' );

		$rendered = do_blocks( $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WordPress core's own block-rendering entry point, not defining a new hook.

		$this->assertSame( 1, substr_count( $rendered, 'data-umami-event=' ) );
		$this->assertStringContainsString( 'data-umami-event="signup"', $rendered );
		$this->assertStringContainsString( 'data-umami-event-plan="pro"', $rendered );
	}

	public function test_render_block_escapes_a_hostile_data_pair_value() {
		Options::update( $this->trackable_options() );
		Manager::register();

		$content = $this->button_block_content( '"umamiEvent":"signup","umamiDataPairs":[{"key":"plan","value":"<b>pro<\/b> & more"}]' );

		$rendered = do_blocks( $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WordPress core's own block-rendering entry point, not defining a new hook.

		$this->assertStringNotContainsString( '<b>pro</b>', $rendered );
		$this->assertStringContainsString( 'data-umami-event-plan="&lt;b&gt;pro&lt;/b&gt; &amp; more"', $rendered );
	}

	public function test_dedupe_guard_leaves_pre_existing_attribute_single_on_legacy_content() {
		Options::update( $this->trackable_options() );
		Manager::register();

		// Simulates a post migrated from the old plugin, whose editor.js
		// baked data-umami-event straight into saved markup (see
		// docs/research/OLD-PLUGIN-INVENTORY.md defect #3/#4): the block
		// still carries a (compatible-named) umamiEvent attribute, but the
		// saved HTML already has the attribute too.
		$content = $this->button_block_content( '"umamiEvent":"new-event"', ' data-umami-event="already"' );

		$rendered = do_blocks( $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WordPress core's own block-rendering entry point, not defining a new hook.

		$this->assertSame( 1, substr_count( $rendered, 'data-umami-event=' ) );
		$this->assertStringContainsString( 'data-umami-event="already"', $rendered );
		$this->assertStringNotContainsString( 'new-event', $rendered );
	}

	public function test_render_block_leaves_other_blocks_untouched() {
		Options::update( $this->trackable_options() );
		Manager::register();

		$content = '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->';

		$rendered = do_blocks( $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WordPress core's own block-rendering entry point, not defining a new hook.

		$this->assertStringNotContainsString( 'data-umami-event', $rendered );
	}

	public function test_manager_does_not_register_gutenberg_when_master_switch_is_off() {
		Options::update( $this->trackable_options( array( 'enabled' => false ) ) );

		Manager::register();

		$this->assertFalse( has_filter( 'render_block', array( Gutenberg::class, 'inject_event_attributes' ) ) );
		$this->assertFalse( has_action( 'enqueue_block_editor_assets', array( Gutenberg::class, 'enqueue_editor_assets' ) ) );

		$content  = $this->button_block_content( '"umamiEvent":"signup"' );
		$rendered = do_blocks( $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WordPress core's own block-rendering entry point, not defining a new hook.

		$this->assertStringNotContainsString( 'data-umami-event', $rendered );
	}

	public function test_manager_does_not_register_gutenberg_when_enable_gutenberg_is_off() {
		Options::update( $this->trackable_options( array( 'enable_gutenberg' => false ) ) );

		Manager::register();

		$this->assertFalse( has_filter( 'render_block', array( Gutenberg::class, 'inject_event_attributes' ) ) );
		$this->assertFalse( has_action( 'enqueue_block_editor_assets', array( Gutenberg::class, 'enqueue_editor_assets' ) ) );

		$content  = $this->button_block_content( '"umamiEvent":"signup"' );
		$rendered = do_blocks( $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WordPress core's own block-rendering entry point, not defining a new hook.

		$this->assertStringNotContainsString( 'data-umami-event', $rendered );
	}

	public function test_manager_registers_gutenberg_when_fully_enabled() {
		Options::update( $this->trackable_options() );

		Manager::register();

		$this->assertNotFalse( has_filter( 'render_block', array( Gutenberg::class, 'inject_event_attributes' ) ) );
		$this->assertNotFalse( has_action( 'enqueue_block_editor_assets', array( Gutenberg::class, 'enqueue_editor_assets' ) ) );
	}
}
