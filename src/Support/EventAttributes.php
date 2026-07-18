<?php
/**
 * Shared event-name/data-pair sanitization and data-umami-event* attribute
 * building, used by every server-side injector (Gutenberg, Contact Form 7,
 * WPForms) so there is exactly one definition of each rule.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure helpers (aside from esc_attr(), WP's output-escaping call) shared by
 * every integration that injects data-umami-event* attributes server-side.
 */
class EventAttributes {

	/**
	 * Event-name length clamp, matching the JS developer API's contract
	 * (assets/js/frontend.js track()) so a name is never silently treated
	 * differently depending on which path set it. UTF-16 CODE UNITS, not code
	 * points - see sanitize_event_name()'s docblock for why.
	 *
	 * @var int
	 */
	const NAME_MAX_LENGTH = 50;

	/**
	 * Sanitize an event name: trim, then clamp to NAME_MAX_LENGTH UTF-16
	 * code units WITHOUT ever splitting a surrogate pair. Pure - no
	 * WordPress calls.
	 *
	 * Verified against
	 * the real Umami 3.2 source and stack: a first fix clamped by Unicode
	 * CODE POINT (mb_substr(), matching the initial JS fix) - correct on
	 * the wire (an astral character was never split by OUR OWN clamp), but
	 * Umami's own truncateString() (src/lib/format.ts:126) re-clamps every
	 * event name via a plain `value.substring(0, 50)` - a UTF-16
	 * CODE-UNIT cut - so a name whose code-point-clamped length still
	 * exceeded 50 code units (any astral character pushes it over) got
	 * RE-SPLIT by Umami itself, reproducing the exact U+FFFD corruption
	 * this fix exists to prevent; the observable outcome on Umami's side
	 * was unchanged. Clamping to code UNITS here instead makes Umami's own
	 * substring(0,50) a no-op: mirrors the JS side's clampToCodeUnits()
	 * (assets/js/frontend.js) exactly - both are now code-UNIT-bounded, so
	 * they still match each other, and now also match what Umami itself
	 * does with the result.
	 *
	 * @param mixed $value Raw stored event-name value.
	 * @return string Sanitized name, or '' if it was empty/not a string.
	 */
	public static function sanitize_event_name( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$name = trim( $value );

		if ( ! function_exists( 'mb_str_split' ) || ! function_exists( 'mb_ord' ) ) {
			return substr( $name, 0, self::NAME_MAX_LENGTH );
		}

		return self::clamp_to_utf16_code_units( $name, self::NAME_MAX_LENGTH );
	}

	/**
	 * Clamp a string to at most $max_units UTF-16 code units WITHOUT ever
	 * splitting a surrogate pair - see sanitize_event_name()'s docblock for
	 * why this must be code-UNIT-bounded, not code-point-bounded. Iterates
	 * by Unicode code point (mb_str_split()), accumulates each character's
	 * own UTF-16 width (2 for a character outside the Basic Multilingual
	 * Plane - i.e. mb_ord() >= 0x10000 - 1 otherwise), and stops BEFORE
	 * appending any character that would push the running total over
	 * $max_units, so a character that would not fit is dropped whole,
	 * never split. Pure - no WordPress calls.
	 *
	 * @param string $value     Source string.
	 * @param int    $max_units Maximum UTF-16 code units in the result.
	 * @return string
	 */
	private static function clamp_to_utf16_code_units( $value, $max_units ) {
		$units  = 0;
		$result = '';

		foreach ( mb_str_split( $value ) as $char ) {
			$width = mb_ord( $char ) >= 0x10000 ? 2 : 1;

			if ( $units + $width > $max_units ) {
				break;
			}

			$result .= $char;
			$units  += $width;
		}

		return $result;
	}

	/**
	 * Sanitize a data-pair key: lowercase, then strip to [a-z0-9_-]. Pure -
	 * no WordPress calls.
	 *
	 * @param mixed $value Raw stored key.
	 * @return string Sanitized key, or '' if it was empty/not a string/all-invalid-chars.
	 */
	public static function sanitize_key( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$key = strtolower( $value );

		return (string) preg_replace( '/[^a-z0-9_-]/', '', $key );
	}

	/**
	 * Build the escaped `data-umami-event="…" data-umami-event-<key>="…"`
	 * attribute string for one event, ready to splice into an HTML tag
	 * (no leading/trailing space). Pure aside from esc_attr().
	 *
	 * @param string            $name       Already-sanitized event name (see sanitize_event_name()).
	 * @param array<int, mixed> $data_pairs {key,value} pairs - each entry's own shape is untrusted (stored config), so it is re-validated per-item here.
	 * @return string
	 */
	public static function build_attribute_string( $name, array $data_pairs ) {
		$html = sprintf( 'data-umami-event="%s"', esc_attr( $name ) );

		foreach ( $data_pairs as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair['key'] ) ) {
				continue;
			}

			$key = self::sanitize_key( $pair['key'] );

			if ( '' === $key ) {
				continue;
			}

			$value = isset( $pair['value'] ) && is_scalar( $pair['value'] ) ? (string) $pair['value'] : '';

			$html .= sprintf( ' data-umami-event-%s="%s"', $key, esc_attr( $value ) );
		}

		return $html;
	}

	/**
	 * Build the escaped `data-umami-<prefix>-event="…" data-umami-<prefix>-data="…"`
	 * attribute string for one integration's success event, ready to splice
	 * into an HTML tag (no leading/trailing space). Unlike
	 * build_attribute_string() (Umami's OWN native `data-umami-event`, which
	 * Umami's bundled tracker auto-fires on click), the attribute name here is
	 * deliberately NOT `data-umami-event*` - CF7/WPForms need to fire on their
	 * real submission-success signal, not on the submit click, so their
	 * attribute must be one Umami's native auto-track never recognizes (see
	 * docs/DECISIONS.md). frontend.js reads this pair and fires
	 * `track(name, data)` itself once the success signal arrives. The data
	 * pairs are carried as ONE JSON object attribute (rather than one
	 * `data-umami-<prefix>-data-<key>` attribute per pair, as
	 * build_attribute_string() does) so the JS success handler can
	 * `JSON.parse()` it in a single step instead of re-scanning the form's
	 * attribute list for a variable set of per-key attributes. Pure aside
	 * from esc_attr()/wp_json_encode().
	 *
	 * @param string            $prefix     Integration prefix, e.g. 'cf7' or 'wpforms'.
	 * @param string            $name       Already-sanitized event name (see sanitize_event_name()).
	 * @param array<int, mixed> $data_pairs {key,value} pairs - each entry's own shape is untrusted (stored config), so it is re-validated per-item here.
	 * @return string
	 */
	public static function build_prefixed_event_attributes( $prefix, $name, array $data_pairs ) {
		$html = sprintf( 'data-umami-%s-event="%s"', $prefix, esc_attr( $name ) );

		$data = self::normalize_data_pairs( $data_pairs );

		if ( ! empty( $data ) ) {
			$html .= sprintf( ' data-umami-%s-data="%s"', $prefix, esc_attr( (string) wp_json_encode( $data ) ) );
		}

		return $html;
	}

	/**
	 * Build the RAW (unescaped) `data-umami-<prefix>-*` key => value map for
	 * one integration's success event - for a caller that renders and
	 * escapes HTML attributes itself (e.g. WPForms' own
	 * wpforms_frontend_form_atts filter, fed into its own
	 * wpforms_html_attributes() helper), rather than splicing a pre-escaped
	 * attribute string into markup (see build_prefixed_event_attributes()
	 * for that shape - both share the same key names/data shape, so
	 * frontend.js's reader is unaffected by which injection path produced
	 * them). Pure - no WordPress calls beyond wp_json_encode().
	 *
	 * @param string            $prefix     Integration prefix, e.g. 'wpforms'.
	 * @param string            $name       Already-sanitized event name (see sanitize_event_name()).
	 * @param array<int, mixed> $data_pairs {key,value} pairs - each entry's own shape is untrusted (stored config), so it is re-validated per-item here.
	 * @return array<string, string> Keyed by the un-prefixed data-* suffix (e.g. 'umami-wpforms-event'), RAW (unescaped) values.
	 */
	public static function build_prefixed_event_data( $prefix, $name, array $data_pairs ) {
		$result = array( 'umami-' . $prefix . '-event' => $name );

		$data = self::normalize_data_pairs( $data_pairs );

		if ( ! empty( $data ) ) {
			$result[ 'umami-' . $prefix . '-data' ] = (string) wp_json_encode( $data );
		}

		return $result;
	}

	/**
	 * Sanitize a list of {key,value} pairs into a RAW (unescaped)
	 * key => value map, dropping any entry whose key sanitizes to ''.
	 * Shared by build_prefixed_event_attributes() (which escapes the result
	 * for direct HTML splicing) and build_prefixed_event_data() (which
	 * hands the raw values to a caller that escapes them itself). Pure - no
	 * WordPress calls.
	 *
	 * @param array<int, mixed> $data_pairs {key,value} pairs.
	 * @return array<string, string>
	 */
	private static function normalize_data_pairs( array $data_pairs ) {
		$data = array();

		foreach ( $data_pairs as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair['key'] ) ) {
				continue;
			}

			$key = self::sanitize_key( $pair['key'] );

			if ( '' === $key ) {
				continue;
			}

			$data[ $key ] = isset( $pair['value'] ) && is_scalar( $pair['value'] ) ? (string) $pair['value'] : '';
		}

		return $data;
	}

	/**
	 * Resolve the event name to inject for a form-based integration (Contact
	 * Form 7, WPForms): the stored per-form event if set, else the form's
	 * own title-slug fallback (sanitize_title($post_title)) - so a form is
	 * never left with no success attributes AND no data-umami-skip, which
	 * would let the generic auto-track submit listener fire on every
	 * submission, including validation failures. Moved here from
	 * ContactForm7 so neither form integration depends on the
	 * other - this is the project's single-definition helper for anything
	 * data-umami-event-shaped. Pure aside from sanitize_title() (WP's
	 * slugify call, not a data source).
	 *
	 * @param string $stored_event Raw stored event-name value ('' if unset).
	 * @param string $post_title   The form post's title, used for the fallback.
	 * @return string
	 */
	public static function resolve_event_name( $stored_event, $post_title ) {
		$fallback = sanitize_title( $post_title );

		return self::sanitize_event_name( '' !== trim( $stored_event ) ? $stored_event : $fallback );
	}

	/**
	 * Decode a stored `{key:value}` JSON object (the CF7/WPForms event-data
	 * storage shape) into the `[{key,value}]` pair-array shape
	 * build_attribute_string() expects (the same shape Gutenberg's
	 * umamiDataPairs block attribute already uses). Pure - no WordPress calls.
	 *
	 * @param mixed $json Raw stored JSON string (or already-empty value).
	 * @return array<int, array{key: string, value: mixed}>
	 */
	public static function decode_data_pairs_json( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$pairs = array();

		foreach ( $decoded as $key => $value ) {
			$pairs[] = array(
				'key'   => $key,
				'value' => $value,
			);
		}

		return $pairs;
	}
}
