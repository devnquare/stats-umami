<?php
/**
 * PHPStan-only stub for Contact Form 7's WPCF7_ContactForm class (scanned,
 * never loaded at runtime - CF7 isn't a project dependency, so PHPStan has
 * no other way to resolve the methods Integrations\ContactForm7 calls on
 * the instance CF7's own hooks pass it). Mirrors the phpstan-bootstrap.php
 * precedent for STATS_UMAMI_* constants.
 *
 * @package StatsUmami
 */

if ( ! class_exists( 'WPCF7_ContactForm', false ) ) {
	/**
	 * Minimal shape: only the two methods Integrations\ContactForm7 calls.
	 */
	class WPCF7_ContactForm {

		/**
		 * @return int
		 */
		public function id() {
			return 0;
		}

		/**
		 * @return string
		 */
		public function title() {
			return '';
		}
	}
}

if ( ! function_exists( 'wpcf7_get_contact_form_by_hash' ) ) {
	/**
	 * @param string $hash Hash string.
	 * @return WPCF7_ContactForm|null
	 */
	function wpcf7_get_contact_form_by_hash( $hash ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- stubbing CF7's own real function name (see file docblock).
		unset( $hash );

		return null;
	}
}

if ( ! function_exists( 'wpcf7_contact_form' ) ) {
	/**
	 * @param mixed $post Post ID, WP_Post, or WPCF7_ContactForm instance.
	 * @return WPCF7_ContactForm|null
	 */
	function wpcf7_contact_form( $post ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- stubbing CF7's own real function name (see file docblock).
		unset( $post );

		return null;
	}
}

if ( ! function_exists( 'wpcf7_get_contact_form_by_title' ) ) {
	/**
	 * @param string $title Form title.
	 * @return WPCF7_ContactForm|null
	 */
	function wpcf7_get_contact_form_by_title( $title ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- stubbing CF7's own real function name (see file docblock).
		unset( $title );

		return null;
	}
}
