<?php
/**
 * Contact Form 7 integration: a per-form "Umami" editor panel (event name +
 * key/value data), and a do_shortcode_tag injector that stamps the stored
 * event - as a renamed, non-Umami-recognized attribute pair fired by
 * frontend.js on genuine submission success, not on click (see
 * inject_into_form()'s docblock) - onto the form element, falling back to
 * the form's title-slug when no event has been set.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Integrations;

use StatsUmami\Support\EventAttributes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage: `_stats_umami_cf7_event` (plain text) + `_stats_umami_cf7_data`
 * (JSON `{key:value}`) post meta on the `wpcf7_contact_form` post - written
 * only by save_meta() (nonce + cap verified), read only by inject_attributes()
 * and render_panel() (both read-only consumers of already-sanitized/-stored
 * values, never raw request data).
 */
class ContactForm7 {

	/**
	 * Post meta key for the stored event name.
	 *
	 * @var string
	 */
	const META_EVENT = '_stats_umami_cf7_event';

	/**
	 * Post meta key for the stored event data (JSON {key:value}).
	 *
	 * @var string
	 */
	const META_DATA = '_stats_umami_cf7_data';

	/**
	 * Register this integration's hooks. Called by Integrations\Manager only
	 * when the master switch + enable_cf7 + the WPCF7_VERSION dependency
	 * predicate all pass.
	 */
	public static function register() {
		add_filter( 'wpcf7_editor_panels', array( __CLASS__, 'add_panel' ) );
		add_action( 'wpcf7_after_save', array( __CLASS__, 'save_meta' ) );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'inject_attributes' ), 10, 3 );
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'print_kv_script' ) );
	}

	/**
	 * Hooked on wpcf7_editor_panels: add a "Umami" panel to the CF7 form editor.
	 *
	 * @param array<string, array<string, mixed>> $panels Existing editor panels, keyed by panel id.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_panel( $panels ) {
		$panels['stats-umami'] = array(
			'title'    => __( 'Umami', 'stats-umami' ),
			'callback' => array( __CLASS__, 'render_panel' ),
		);

		return $panels;
	}

	/**
	 * Render the "Umami" editor panel: an event-name field pre-filled from
	 * stored meta (placeholder shows the title-slug fallback that applies
	 * when left blank) + a dynamic key/value data list.
	 *
	 * @param \WPCF7_ContactForm $contact_form The form being edited.
	 */
	public static function render_panel( $contact_form ) {
		$form_id = (int) $contact_form->id();
		$title   = (string) $contact_form->title();

		$event_name = (string) get_post_meta( $form_id, self::META_EVENT, true );
		$data_pairs = EventAttributes::decode_data_pairs_json( (string) get_post_meta( $form_id, self::META_DATA, true ) );

		wp_nonce_field( 'stats_umami_cf7_save', 'stats_umami_cf7_nonce' );
		?>
		<h2><?php esc_html_e( 'Umami event tracking', 'stats-umami' ); ?></h2>
		<p>
			<?php esc_html_e( 'Fire a custom Umami event when this form is submitted successfully. Leave the event name blank to use the form title instead.', 'stats-umami' ); ?>
		</p>

		<fieldset>
			<legend><?php esc_html_e( 'Event name', 'stats-umami' ); ?></legend>
			<label for="stats-umami-cf7-event-name">
				<input
					type="text"
					id="stats-umami-cf7-event-name"
					name="stats_umami_cf7_event_name"
					class="large-text"
					value="<?php echo esc_attr( $event_name ); ?>"
					placeholder="<?php echo esc_attr( sanitize_title( $title ) ); ?>"
				/>
			</label>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'Event data', 'stats-umami' ); ?></legend>
			<p><?php esc_html_e( 'Optional extra details sent with the event, as key/value pairs - for example: source = contact_page.', 'stats-umami' ); ?></p>
			<table class="widefat" id="stats-umami-cf7-kv-table">
				<tbody>
					<?php foreach ( $data_pairs as $pair ) : ?>
						<tr>
							<td><input type="text" class="stats-umami-cf7-kv-key" name="stats_umami_cf7_data_key[]" value="<?php echo esc_attr( (string) $pair['key'] ); ?>" placeholder="<?php esc_attr_e( 'key', 'stats-umami' ); ?>" /></td>
							<td><input type="text" class="stats-umami-cf7-kv-value" name="stats_umami_cf7_data_value[]" value="<?php echo esc_attr( (string) $pair['value'] ); ?>" placeholder="<?php esc_attr_e( 'value', 'stats-umami' ); ?>" /></td>
							<td><button type="button" class="button stats-umami-cf7-remove-row"><?php esc_html_e( 'Remove', 'stats-umami' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="stats-umami-cf7-add-row"><?php esc_html_e( 'Add data pair', 'stats-umami' ); ?></button></p>
		</fieldset>
		<?php
	}

	/**
	 * Hooked on wpcf7_after_save: verify a dedicated nonce + CF7's own
	 * per-form edit capability, then sanitize + store the event name/data
	 * meta from the panel's submitted fields. Fails soft (returns without
	 * writing) on a missing/invalid nonce or capability, rather than
	 * wp_die()-ing - CF7 has already saved the form by the time this fires,
	 * so our side channel must not break its own save response.
	 *
	 * @param \WPCF7_ContactForm $contact_form The form that was just saved.
	 */
	public static function save_meta( $contact_form ) {
		if (
			! isset( $_POST['stats_umami_cf7_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['stats_umami_cf7_nonce'] ) ), 'stats_umami_cf7_save' )
		) {
			return;
		}

		// $post_id must be resolved BEFORE the capability check below: CF7's
		// real edit capability is the META capability `wpcf7_edit_contact_form`
		// (see the class docblock note this replaces), which - unlike a plain
		// capability - takes the object id as its second argument.
		$post_id = (int) $contact_form->id();

		if ( ! current_user_can( 'wpcf7_edit_contact_form', $post_id ) ) {
			return;
		}

		$event_name = isset( $_POST['stats_umami_cf7_event_name'] )
			? EventAttributes::sanitize_event_name( sanitize_text_field( wp_unslash( $_POST['stats_umami_cf7_event_name'] ) ) )
			: '';

		if ( '' === $event_name ) {
			delete_post_meta( $post_id, self::META_EVENT );
		} else {
			// wp_slash(): update_metadata() unconditionally wp_unslash()es the
			// value it's given; without a compensating slash here, a literal
			// `\` in the event name is eaten at write time - the same bug
			// class as the sibling data-meta write below, left un-fixed on
			// this write.
			update_post_meta( $post_id, self::META_EVENT, wp_slash( $event_name ) );
		}

		// Inlined here (rather than a separate helper) so this nonce+cap
		// guard is in the same function scope as these $_POST reads -
		// WPCS's NonceVerification sniff only recognizes a guard within the
		// SAME function, not across a call to a private helper. Each element
		// is sanitize_text_field()'d right at the extraction point (not just
		// later in the loop) so WPCS's static input-sanitization sniff -
		// which pattern-matches the function wrapping the $_POST access
		// itself, not later data flow - recognizes it too.
		$keys   = isset( $_POST['stats_umami_cf7_data_key'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['stats_umami_cf7_data_key'] ) ) : array();
		$values = isset( $_POST['stats_umami_cf7_data_value'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['stats_umami_cf7_data_value'] ) ) : array();

		$data = array();

		foreach ( $keys as $index => $raw_key ) {
			$key = EventAttributes::sanitize_key( $raw_key );

			if ( '' === $key ) {
				continue;
			}

			$data[ $key ] = isset( $values[ $index ] ) ? $values[ $index ] : '';
		}

		if ( empty( $data ) ) {
			delete_post_meta( $post_id, self::META_DATA );
		} else {
			// wp_slash(): update_metadata() unconditionally wp_unslash()es the
			// value it's given; without a compensating slash here, any `"`
			// that wp_json_encode() wrote as `\"` loses its backslash and the
			// stored string stops being valid JSON (see docs/DECISIONS.md).
			update_post_meta( $post_id, self::META_DATA, wp_slash( wp_json_encode( $data ) ) );
		}
	}

	/**
	 * Hooked on do_shortcode_tag (3 args): for the contact-form-7 shortcode
	 * ONLY, resolve the target form, read its stored (or title-slug
	 * fallback) event, and inject the success-event attribute pair plus
	 * data-umami-skip="1" onto the <form> - exactly once, via bounded
	 * preg_replace_callback, null-coalesced back to the original output on
	 * no match.
	 *
	 * @param mixed  $output Shortcode output - typed mixed (not string) because WordPress's own do_shortcode_tag contract passes through whatever the shortcode callback returned, without guaranteeing a string.
	 * @param string $tag    Shortcode tag.
	 * @param mixed  $attr   Shortcode attributes - array in the common case, but shortcode_parse_atts() (WP core) can return '' for a tag with no attributes at all.
	 * @return mixed
	 */
	public static function inject_attributes( $output, $tag, $attr ) {
		if ( 'contact-form-7' !== $tag || ! is_string( $output ) || '' === $output ) {
			return $output;
		}

		$form_post = self::resolve_form_post( is_array( $attr ) ? $attr : array() );

		if ( ! $form_post ) {
			return $output;
		}

		$stored_event = (string) get_post_meta( $form_post->ID, self::META_EVENT, true );
		$event_name   = EventAttributes::resolve_event_name( $stored_event, $form_post->post_title );

		if ( '' === $event_name ) {
			return $output;
		}

		$data_pairs       = EventAttributes::decode_data_pairs_json( (string) get_post_meta( $form_post->ID, self::META_DATA, true ) );
		$attribute_string = EventAttributes::build_prefixed_event_attributes( 'cf7', $event_name, $data_pairs );

		return self::inject_into_form( $output, $attribute_string );
	}

	/**
	 * Resolve the target wpcf7_contact_form post from the shortcode's
	 * attributes, mirroring CF7's own wpcf7_contact_form_tag_func()
	 * resolution order exactly: hash id, then legacy numeric post id, then
	 * an exact title match. CF7 5.6+ shortcodes carry a HASH STRING in
	 * `id` (not the post ID - `[contact-form-7 id="<hash>" title="..."]`),
	 * so a hand-rolled is_numeric()-only guess silently fails to resolve
	 * every currently-shipped CF7 form; only CF7's own resolver functions
	 * know the real lookup rules across versions.
	 *
	 * @param array<string, mixed> $attr Shortcode attributes.
	 * @return \WP_Post|null
	 */
	private static function resolve_form_post( array $attr ) {
		$id    = isset( $attr['id'] ) ? trim( (string) $attr['id'] ) : '';
		$title = isset( $attr['title'] ) ? trim( (string) $attr['title'] ) : '';

		if ( function_exists( 'wpcf7_get_contact_form_by_hash' ) ) {
			$contact_form = '' !== $id ? wpcf7_get_contact_form_by_hash( $id ) : null;

			if ( ! $contact_form && '' !== $id ) {
				$contact_form = wpcf7_contact_form( $id );
			}

			if ( ! $contact_form && '' !== $title ) {
				$contact_form = wpcf7_get_contact_form_by_title( $title );
			}

			if ( $contact_form ) {
				$post = get_post( $contact_form->id() );

				return ( $post instanceof \WP_Post ) ? $post : null;
			}

			return null;
		}

		// CF7 isn't loaded here (e.g. the PHPUnit integration bootstrap -
		// see the class docblock); this minimal numeric-id/title lookup
		// keeps the method testable against a real DB without CF7
		// installed. register() only ever runs when WPCF7_VERSION is
		// defined, so real use always takes the branch above.
		if ( '' !== $id && is_numeric( $id ) ) {
			$post = get_post( (int) $id );

			return ( $post instanceof \WP_Post && 'wpcf7_contact_form' === $post->post_type ) ? $post : null;
		}

		if ( '' !== $title ) {
			$posts = get_posts(
				array(
					'post_type'      => 'wpcf7_contact_form',
					'title'          => $title,
					'posts_per_page' => 1,
					'post_status'    => 'any',
				)
			);

			return isset( $posts[0] ) ? $posts[0] : null;
		}

		return null;
	}

	/**
	 * Thin public delegate to EventAttributes::resolve_event_name() (the
	 * real implementation lives there so WPForms no longer
	 * depends on this class) - kept only because ContactForm7Test.php
	 * exercises it directly; the production call site in
	 * inject_attributes() calls EventAttributes::resolve_event_name()
	 * itself.
	 *
	 * @param string $stored_event Raw stored _stats_umami_cf7_event meta value ('' if unset).
	 * @param string $post_title   The form post's title, used for the fallback.
	 * @return string
	 */
	public static function resolve_event_name( $stored_event, $post_title ) {
		return EventAttributes::resolve_event_name( $stored_event, $post_title );
	}

	/**
	 * Inject the success-event attribute pair (data-umami-cf7-event/-data)
	 * plus data-umami-skip="1" onto the CF7 <form class="wpcf7-form">
	 * element - not the submit control. `data-umami-event` is Umami's OWN
	 * native click-track attribute, which Umami's bundled tracker always
	 * auto-fires on click (unconditionally, since the
	 * option that used to suppress it); stamping it on the submit control
	 * would fire on every click, including invalid/failed submits, not on
	 * genuine success. The renamed `data-umami-cf7-event`
	 * pair is instead read by frontend.js's success listener and fired only
	 * on the real `wpcf7mailsent` signal. All three attributes are added in
	 * one preg_replace_callback pass over the form tag. Skips (dedupe guard)
	 * when the form already carries `data-umami-cf7-event=` - e.g. a second
	 * filter pass over already-injected content. Pure - no WordPress calls.
	 *
	 * @param string $output           The shortcode's rendered HTML.
	 * @param string $attribute_string Pre-built, escaped data-umami-cf7-event(+-data) attribute string.
	 * @return string
	 */
	public static function inject_into_form( $output, $attribute_string ) {
		$injected = preg_replace_callback(
			'/<form\b[^>]*\bclass="[^"]*\bwpcf7-form\b[^"]*"[^>]*>/i',
			static function ( $matches ) use ( $attribute_string ) {
				$tag = $matches[0];

				if ( false !== strpos( $tag, 'data-umami-cf7-event=' ) ) {
					return $tag;
				}

				return substr( $tag, 0, -1 ) . ' ' . $attribute_string . ' data-umami-skip="1">';
			},
			$output,
			1
		);

		return null !== $injected ? $injected : $output;
	}

	/**
	 * Hooked on admin_print_footer_scripts: print the panel's add/remove-row JS, only
	 * on CF7 admin screens (existence-checked selectors make this a safe
	 * no-op on the CF7 list screen, where the panel markup isn't present).
	 */
	public static function print_kv_script() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || false === strpos( (string) $screen->id, 'wpcf7' ) ) {
			return;
		}

		$remove_label      = wp_json_encode( __( 'Remove', 'stats-umami' ) );
		$key_placeholder   = wp_json_encode( __( 'key', 'stats-umami' ) );
		$value_placeholder = wp_json_encode( __( 'value', 'stats-umami' ) );
		?>
		<script>
		( function () {
			'use strict';

			document.addEventListener( 'click', function ( event ) {
				var table = document.getElementById( 'stats-umami-cf7-kv-table' );

				if ( ! table ) {
					return;
				}

				if ( event.target && event.target.id === 'stats-umami-cf7-add-row' ) {
					event.preventDefault();

					var tbody = table.querySelector( 'tbody' );
					var row   = document.createElement( 'tr' );

					// Built via
					// createElement + the .placeholder PROPERTY (not an
					// innerHTML string), so the translated placeholder,
					// matching the server-rendered row's own esc_attr_e()
					// call, can be assigned safely and can never be
					// interpreted as markup.
					var keyCell  = document.createElement( 'td' );
					var keyInput = document.createElement( 'input' );
					keyInput.type = 'text';
					keyInput.className = 'stats-umami-cf7-kv-key';
					keyInput.name = 'stats_umami_cf7_data_key[]';
					keyInput.placeholder = <?php echo $key_placeholder; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assigned to .placeholder, never .innerHTML, so this can never be interpreted as markup regardless of its content. ?>;
					keyCell.appendChild( keyInput );

					var valueCell  = document.createElement( 'td' );
					var valueInput = document.createElement( 'input' );
					valueInput.type = 'text';
					valueInput.className = 'stats-umami-cf7-kv-value';
					valueInput.name = 'stats_umami_cf7_data_value[]';
					valueInput.placeholder = <?php echo $value_placeholder; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assigned to .placeholder, never .innerHTML, so this can never be interpreted as markup regardless of its content. ?>;
					valueCell.appendChild( valueInput );

					var buttonCell = document.createElement( 'td' );
					var button     = document.createElement( 'button' );
					button.type = 'button';
					button.className = 'button stats-umami-cf7-remove-row';
					button.textContent = <?php echo $remove_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assigned to .textContent, never .innerHTML, so this can never be interpreted as markup regardless of its content. ?>;
					buttonCell.appendChild( button );

					row.appendChild( keyCell );
					row.appendChild( valueCell );
					row.appendChild( buttonCell );

					tbody.appendChild( row );
				}

				if ( event.target && event.target.classList && event.target.classList.contains( 'stats-umami-cf7-remove-row' ) ) {
					event.preventDefault();
					event.target.closest( 'tr' ).remove();
				}
			} );
		}() );
		</script>
		<?php
	}
}
