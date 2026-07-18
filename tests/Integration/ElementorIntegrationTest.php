<?php
/**
 * DB-backed integration tests for the 1.1.0 Elementor integration:
 * mark_button_widget()'s callback logic against a lightweight fake element
 * (Elementor itself isn't installed in this bootstrap, so its real
 * Element_Base/Widget_Button classes can't be used - see
 * FakeElementorElement's docblock), and Integrations\Manager's registration
 * gating, against a real WP core bootstrap + test database.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

use StatsUmami\Integrations\Elementor;
use StatsUmami\Integrations\Manager;
use StatsUmami\Settings\Options;
use Yoast\WPTestUtils\WPIntegration\TestCase;

require_once __DIR__ . '/FakeElementorElement.php';

/**
 * @covers \StatsUmami\Integrations\Elementor
 * @covers \StatsUmami\Integrations\Manager
 */
final class ElementorIntegrationTest extends TestCase {

	public function set_up() {
		parent::set_up();

		delete_option( Options::OPTION_KEY );
	}

	/**
	 * A fully-configured, trackable + Elementor-enabled options array, with
	 * the given overrides layered on top.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function trackable_options( array $overrides = array() ) {
		$options                     = Options::defaults();
		$options['enabled']          = true;
		$options['enable_elementor'] = true;
		$options['schema_version']   = Options::SCHEMA_VERSION;

		return array_merge( $options, $overrides );
	}

	public function test_mark_button_widget_stamps_the_marker_on_a_button_widget() {
		$element = new FakeElementorElement( 'button' );

		Elementor::mark_button_widget( $element );

		$this->assertSame(
			array( array( '_wrapper', 'data-umami-link', '1' ) ),
			$element->render_attribute_calls
		);
	}

	public function test_mark_button_widget_does_nothing_for_a_non_button_widget() {
		$element = new FakeElementorElement( 'heading' );

		Elementor::mark_button_widget( $element );

		$this->assertSame( array(), $element->render_attribute_calls );
	}

	public function test_mark_button_widget_ignores_a_value_with_no_get_name_method() {
		// Defensive shape guard: anything Elementor might conceivably pass
		// that doesn't duck-type as an element must be a safe no-op, never a
		// fatal - see mark_button_widget()'s own guard clause.
		Elementor::mark_button_widget( 'not-an-element' );
		Elementor::mark_button_widget( null );

		$this->addToAssertionCount( 2 ); // Reaching here without a fatal is the assertion.
	}

	public function test_manager_gates_elementor_registration_on_master_toggle_and_dependency() {
		$callback = array( Elementor::class, 'mark_button_widget' );

		// 1. Master switch off (dependency currently undefined, matching
		// this bootstrap's default - Elementor is never installed here).
		Options::update( $this->trackable_options( array( 'enabled' => false ) ) );
		Manager::register();
		$this->assertFalse( has_action( 'elementor/frontend/before_render', $callback ) );

		// 2. Master on, enable_elementor off, dependency still undefined.
		Options::update( $this->trackable_options( array( 'enable_elementor' => false ) ) );
		Manager::register();
		$this->assertFalse( has_action( 'elementor/frontend/before_render', $callback ) );

		// 3. Master + toggle on, dependency STILL undefined - not registered.
		Options::update( $this->trackable_options() );
		Manager::register();
		$this->assertFalse( has_action( 'elementor/frontend/before_render', $callback ) );

		// 4. Define the dependency (mirrors Elementor actually being active)
		// - master + toggle already on from step 3, so this alone flips the
		// gate to registered.
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.1.4' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulating Elementor's own real constant so Manager's class_exists()-style dependency predicate can be driven to `true` in a bootstrap that never installs Elementor.
		}

		Manager::register();
		$this->assertNotFalse( has_action( 'elementor/frontend/before_render', $callback ) );
	}
}
