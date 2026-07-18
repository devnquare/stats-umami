<?php
/**
 * Test-only global-namespace stand-in for WPForms' own wpforms_decode()
 * (wpforms-lite/includes/functions/forms.php:77-91), required on-demand by
 * WPFormsIntegrationTest to genuinely exercise Integrations\WPForms::
 * extract_settings()'s PRE-FIX branch - WPForms itself isn't loaded in the
 * phpunit integration bootstrap (see Integrations\WPForms's own class
 * docblock), so without this stub function_exists('wpforms_decode') is
 * always false and the pre-fix ternary silently took the same json_decode()
 * branch the fixed code always takes - making a same-branch test unable to
 * distinguish fixed from unfixed.
 *
 * Reproduces the REAL implementation's real mechanism: json_decode() runs
 * FIRST, then (after a json_last_error() guard) wp_unslash() runs on the
 * DECODED ARRAY - not on the raw string, and not before decoding (an
 * earlier version of this stub had that backwards; corrected after the PM
 * proved the difference live on WP 7.0.1 against the real installed
 * wpforms-lite plugin).
 *
 * Because wp_unslash() (stripslashes_deep()) runs AFTER decoding, it only
 * ever touches STRING VALUES inside the already-decoded array, never the
 * outer JSON's own structural characters - so the OUTER settings survive
 * (a plain string like 'contact' has no backslashes to strip). What it
 * corrupts is any string value that is ITSELF a nested JSON-encoded string
 * containing escaped quotes/backslashes - exactly our
 * stats_umami_event_data shape (a JSON object stored as a string value
 * inside the settings array). stripslashes() strips the backslash out of
 * that nested value's `\"` sequences, turning e.g.
 * `{"note":"He said \"hi\" to me"}` into the syntactically-broken
 * `{"note":"He said "hi" to me"}` - so a LATER json_decode() of that
 * corrupted string (see EventAttributes::decode_data_pairs_json(), called
 * downstream on $settings['stats_umami_event_data']) fails and the data
 * pairs are lost, while stats_umami_event_name is untouched.
 *
 * Test-only: never required outside tests/.
 *
 * @package StatsUmami
 */

if ( ! function_exists( 'wpforms_decode' ) ) {
	/**
	 * @param string $data Raw string to decode.
	 * @return mixed
	 */
	function wpforms_decode( $data ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- test-only stub of WPForms' own real function name (see file docblock).
		$decoded_data = json_decode( $data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array();
		}

		return wp_unslash( $decoded_data );
	}
}
