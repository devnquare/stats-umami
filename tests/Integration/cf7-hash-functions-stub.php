<?php
/**
 * Minimal global-namespace stand-ins for Contact Form 7's own
 * wpcf7_get_contact_form_by_hash()/wpcf7_contact_form()/
 * wpcf7_get_contact_form_by_title() functions, required on-demand by
 * ContactForm7IntegrationTest to drive Integrations\ContactForm7::
 * resolve_form_post()'s CF7-hash-aware branch - CF7 itself isn't loaded in
 * the phpunit integration bootstrap. Test-only: never required outside
 * tests/.
 *
 * wpcf7_get_contact_form_by_hash() ignores the literal $hash value and
 * resolves whatever post ID the test points $GLOBALS['stats_umami_test_cf7_hash_target']
 * at - real hash-matching is CF7's own concern; what this test suite needs
 * to prove is that resolve_form_post() DELEGATES to this function and
 * trusts its result, rather than treating the shortcode's `id` attribute as
 * a raw post ID itself (the real bug this stub exists to regression-guard).
 *
 * @package StatsUmami
 */

if ( ! function_exists( 'wpcf7_get_contact_form_by_hash' ) ) {
	/**
	 * @param string $hash Hash string (ignored - see file docblock).
	 * @return object|null An object exposing id(), or null.
	 */
	function wpcf7_get_contact_form_by_hash( $hash ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- test-only stub of CF7's own real function name (see file docblock).
		unset( $hash );

		if ( empty( $GLOBALS['stats_umami_test_cf7_hash_target'] ) ) {
			return null;
		}

		$post = get_post( $GLOBALS['stats_umami_test_cf7_hash_target'] );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		return new class( $post->ID ) {
			/**
			 * @var int
			 */
			private $post_id;

			/**
			 * @param int $post_id Post ID.
			 */
			public function __construct( $post_id ) {
				$this->post_id = $post_id;
			}

			/**
			 * @return int
			 */
			public function id() {
				return $this->post_id;
			}
		};
	}
}

if ( ! function_exists( 'wpcf7_contact_form' ) ) {
	/**
	 * @param mixed $post Unused in this stub.
	 * @return null
	 */
	function wpcf7_contact_form( $post ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- test-only stub of CF7's own real function name (see file docblock).
		unset( $post );

		return null;
	}
}

if ( ! function_exists( 'wpcf7_get_contact_form_by_title' ) ) {
	/**
	 * @param mixed $title Unused in this stub.
	 * @return null
	 */
	function wpcf7_get_contact_form_by_title( $title ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- test-only stub of CF7's own real function name (see file docblock).
		unset( $title );

		return null;
	}
}
