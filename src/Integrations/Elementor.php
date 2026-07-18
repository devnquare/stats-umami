<?php
/**
 * Elementor (free) integration: stamps a generic, builder-agnostic opt-in
 * marker attribute on Elementor Button widgets so frontend.js tracks them as
 * link:<label> events even when the global "Link clicks" toggle is off.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single-fire by construction (see docs/DECISIONS.md 2026-07-17): this class
 * adds NO new click listener and duplicates NO naming logic. It only stamps
 * a generic `data-umami-link="1"` marker - never `.elementor-button` or any
 * other builder-specific CSS knowledge - onto the rendered wrapper of an
 * Elementor Button widget. frontend.js's existing single delegated click
 * path (onClick -> resolveClickTarget -> handleLinkClick) is the ONLY place
 * that ever reads the marker (via isForcedLink()), and only to bypass the
 * autotrack_links off-gate - the label/URL/outbound rules stay exactly the
 * same as for any other link, so the tracker itself holds zero Elementor
 * knowledge.
 *
 * Verified against the installed Elementor 4.1.4 source
 * (wp-content/plugins/elementor/):
 * - `Widget_Button::get_name()` returns the literal string 'button'
 *   (includes/widgets/button.php:33-35).
 * - `Element_Base::print_element()` fires `do_action( 'elementor/frontend/before_render', $this )`
 *   (includes/base/element-base.php:492) BEFORE `add_render_attributes()`
 *   (:539, which only ever merges additional keys/classes into the SAME
 *   `_wrapper` bag - see `add_render_attribute()`'s `array_merge`,
 *   includes/base/controls-stack.php:1918-1948, default $overwrite=false)
 *   and before `before_render()` prints the `_wrapper` `<div>`
 *   (includes/base/widget-base.php:716-720). So a `data-umami-link`
 *   attribute added on this hook is guaranteed to still be present when the
 *   wrapper is printed, and can never be clobbered by Elementor's own later
 *   `_wrapper` writes (different attribute name).
 * - The Button widget's wrapper contains exactly one interactive element
 *   (the anchor built by `Button_Trait::render_button()`,
 *   includes/widgets/traits/button-trait.php:520-568), so marking the
 *   wrapper cannot mark any unrelated link.
 *
 * Also verified against the live Umami 3.2.0 tracker actually served by this
 * project's test stack (`GET http://localhost:3000/script.js`) and the
 * matching Umami 3.0.3 tracker source
 * (`src/tracker/index.js`, `handleClicks()`): Umami's own click handler
 * reads only `data-umami-event` (+ the `data-umami-event-<key>` data
 * attributes via its `eventRegex`) - it has no knowledge of
 * `data-umami-link` at all, so this marker can never make Umami's bundled
 * tracker fire on its own.
 */
class Elementor {

	/**
	 * Register this integration's hooks. Called by Integrations\Manager only
	 * when the master switch + enable_elementor + the ELEMENTOR_VERSION
	 * dependency predicate all pass.
	 */
	public static function register() {
		add_action( 'elementor/frontend/before_render', array( __CLASS__, 'mark_button_widget' ) );
	}

	/**
	 * Hooked on elementor/frontend/before_render (fires for every Elementor
	 * element, not only buttons - see the class docblock for the exact call
	 * order this relies on). Stamps the generic `data-umami-link="1"` marker
	 * onto the Button widget's `_wrapper` render attribute bag; every other
	 * element type is left untouched.
	 *
	 * Not gated on the button having a configured URL: a URL-less Elementor
	 * button renders a non-navigable `<a>`, and frontend.js's existing
	 * hasNavigableHref() already filters it out on the JS side - marking it
	 * here is harmless, and keeps this class from having to duplicate that
	 * rule.
	 *
	 * @param mixed $element The Elementor element about to render - documented as `\Elementor\Element_Base` (Elementor is not part of this project's dependency graph, so it is intentionally left untyped here, matching this project's existing WPForms integration's own precedent for an undeclared third-party type).
	 */
	public static function mark_button_widget( $element ) {
		if ( ! is_object( $element ) || ! method_exists( $element, 'get_name' ) || ! method_exists( $element, 'add_render_attribute' ) ) {
			return;
		}

		if ( 'button' !== $element->get_name() ) {
			return;
		}

		$element->add_render_attribute( '_wrapper', 'data-umami-link', '1' );
	}
}
