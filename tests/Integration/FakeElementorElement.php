<?php
/**
 * Minimal stand-in for Elementor's \Elementor\Element_Base, used by
 * ElementorIntegrationTest - Integrations\Elementor::mark_button_widget()
 * only ever calls ->get_name() and ->add_render_attribute(). Elementor isn't
 * installed in the integration bootstrap, so its real class can't be used.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

/**
 * Duck-types the two Element_Base methods Integrations\Elementor calls, and
 * captures every add_render_attribute() call so a test can assert on it.
 */
final class FakeElementorElement {

	/**
	 * @var string
	 */
	private $name;

	/**
	 * Every add_render_attribute() call this element received, as
	 * [element, key, value] tuples, in call order.
	 *
	 * @var array<int, array{0: string, 1: string, 2: mixed}>
	 */
	public $render_attribute_calls = array();

	/**
	 * @param string $name The widget name this element stands in for (e.g. 'button', 'heading').
	 */
	public function __construct( $name ) {
		$this->name = $name;
	}

	/**
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * @param string $element Render-attribute bag name (e.g. '_wrapper').
	 * @param string $key     Attribute name.
	 * @param mixed  $value   Attribute value.
	 */
	public function add_render_attribute( $element, $key, $value ) {
		$this->render_attribute_calls[] = array( $element, $key, $value );
	}
}
