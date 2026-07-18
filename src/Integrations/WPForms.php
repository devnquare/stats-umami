<?php
/**
 * WPForms integration: a "Umami" builder section (event name + key/value
 * data, stored in the form's own settings) and a content-filter injector
 * that stamps the stored event onto the form's submit control wherever
 * WPForms renders it (the_content / widget_text_content / widget_block_content).
 *
 * @package StatsUmami
 */

namespace StatsUmami\Integrations;

use StatsUmami\Support\EventAttributes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage: WPForms' OWN form settings (`settings.stats_umami_event_name` /
 * `settings.stats_umami_event_data`), persisted by WPForms' native builder
 * save - we never write to it ourselves, only read what WPForms already
 * stored. Like Contact Form 7 (closing the asymmetry
 * docs/research/OLD-PLUGIN-INVENTORY.md §12 defect #5 originally documented):
 * leaving the event name blank falls back to the form's own name
 * (`sanitize_title($post->post_title)`, via
 * Support\EventAttributes::resolve_event_name() - the project's single
 * definition of that shape, shared with Contact Form 7 rather than one
 * integration depending on the other), so a form is never
 * left with no success attributes AND no `data-umami-skip` - the previous gap
 * that let the generic auto-track submit listener fire on every submission,
 * including validation failures. The stored event is stamped as a renamed,
 * non-Umami-recognized attribute pair on the <form> element and fired by
 * frontend.js on genuine submission success, not on click (see
 * inject_into_form()'s docblock).
 *
 * EVENT-DATA STORAGE SHAPE: a nested-JSON-STRING
 * value was replaced with a real ARRAY. WPForms' real save path double-unslashes: its AJAX handler
 * (`wpforms_save_form()`) does `json_decode(wp_unslash($_POST['data']))`
 * (cancels the request's own magic-quotes slash, giving a clean array), but
 * `WPForms_Form_Handler::update()`, with its DEFAULT
 * `wpforms_is_form_data_slashing_enabled() === false`, then runs
 * `wp_unslash($data)` a SECOND, uncancelled time over that already-clean
 * array. `wp_unslash()`/`stripslashes_deep()` strips backslashes from every
 * STRING VALUE it finds - harmless for an ordinary string, but our OLD shape
 * stored the event-data pairs as ONE JSON-encoded STRING nested inside a
 * settings value (e.g. `{"source":"promo \"q\" test"}`); the second unslash
 * strips the backslash out of that string's OWN escaped `\"` sequences,
 * corrupting it into invalid JSON (`{"source":"promo "q" test"}`) BEFORE it
 * is ever stored - unrecoverable by any reader, since the damage happens at
 * WRITE time. Storing the pairs as a genuine PHP ARRAY instead (via
 * render_section()'s `settings[stats_umami_event_data][N][key/value]`
 * fields, which WPForms' own JSON-based serialization keeps as real nested
 * array structure, never a string-within-a-string) sidesteps the whole
 * problem: `wp_unslash()` only ever touches the individual key/value leaf
 * strings themselves (losing a literal `\`, exactly like every native
 * WPForms setting e.g. `form_title` - acceptable, minor, and consistent),
 * never a `"` that would otherwise need escaping. A form saved by an older
 * plugin version may still have the old string shape stored; resolve_data_pairs()
 * keeps reading that shape as a back-compat fallback (no migration write).
 */
class WPForms {

	/**
	 * Form-settings key for the stored event name.
	 *
	 * @var string
	 */
	const SETTING_EVENT = 'stats_umami_event_name';

	/**
	 * Form-settings key for the stored event data: an array of {key,value}
	 * pairs (previously a nested JSON-encoded string; see the
	 * class docblock). A form saved by an older plugin version may still hold the old string
	 * shape - resolve_data_pairs() accepts both.
	 *
	 * @var string
	 */
	const SETTING_DATA = 'stats_umami_event_data';

	/**
	 * Query-arg name for the one-shot AJAX-off success marker (cookie-free
	 * design: the
	 * plugin sets zero cookies today and umamiwp.com's own privacy promise
	 * is "no cookies," so the success signal for a redirect/page
	 * confirmation travels as a same-origin URL marker instead - see
	 * maybe_append_ajax_off_success_marker()'s docblock).
	 *
	 * @var string
	 */
	const AJAX_OFF_SUCCESS_QUERY_ARG = 'stats_umami_wpf_ok';

	/**
	 * Register this integration's hooks. Called by Integrations\Manager only
	 * when the master switch + enable_wpforms + the class_exists('WPForms')
	 * dependency predicate all pass.
	 */
	public static function register() {
		add_filter( 'wpforms_builder_settings_sections', array( __CLASS__, 'add_section' ), 20, 2 );
		add_action( 'wpforms_form_settings_panel_content', array( __CLASS__, 'render_section' ), 20 );

		// wpforms_process_redirect_url
		// (installed WPForms Lite 1.10.2.1 class-process.php:1504) fires for
		// BOTH the Redirect and Page confirmation types, on genuine success
		// (WPForms' own
		// validation-error guard has already returned earlier in
		// process()), before the wp_redirect() at :1522 both types share.
		// See maybe_append_ajax_off_success_marker()'s docblock.
		add_filter( 'wpforms_process_redirect_url', array( __CLASS__, 'maybe_append_ajax_off_success_marker' ), 10, 5 );

		// wpforms_frontend_form_atts
		// fires inside WPForms' OWN Frontend::output() (src/Frontend/Frontend.php:303)
		// for EVERY render path - the [wpforms] shortcode, the classic
		// widget, the Gutenberg block, AND a direct wpforms_display() call -
		// unlike the three content filters below, which only ever see markup
		// that already passed through the_content/widget_text_content/
		// widget_block_content. See inject_via_form_atts()'s docblock.
		add_filter( 'wpforms_frontend_form_atts', array( __CLASS__, 'inject_via_form_atts' ), 10, 2 );

		// Kept alongside the form_atts hook above (not retired): this covers
		// the same render paths a second time, but inject_into_form()'s
		// existing dedupe guard (skip when data-umami-wpforms-event= is
		// already present) makes that a safe no-op wherever the form_atts
		// hook already ran - which is every case the two mechanisms overlap
		// on, since Frontend::output() always runs before the resulting
		// markup reaches these later content filters. Retiring these three
		// would touch a dozen-plus existing regression tests unrelated to
		// the wpforms_display() gap the form_atts hook exists to close -
		// left as a follow-up, not done here.
		add_filter( 'the_content', array( __CLASS__, 'inject_in_content' ), 19 );
		add_filter( 'widget_text_content', array( __CLASS__, 'inject_in_content' ), 19 );
		add_filter( 'widget_block_content', array( __CLASS__, 'inject_in_content' ), 19 );

		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'print_kv_script' ) );
	}

	/**
	 * Hooked on wpforms_frontend_form_atts (WPForms' own Frontend.php:303) -
	 * fires for EVERY render path: the [wpforms] shortcode, the classic
	 * widget, the Gutenberg block, and a direct wpforms_display() call (a
	 * gap - none of the three content filters above ever see that last
	 * one, so the form carried neither attribute and frontend.js's generic
	 * onSubmit fired a premature form:wpforms-form-N before success was
	 * known).
	 *
	 * $form_data is the SAME decoded settings array WPForms' own renderer
	 * already built for this render (Frontend::output() runs
	 * wpforms_decode()/the wpforms_frontend_form_data filter over
	 * post_content BEFORE this filter fires) - reading our settings off it
	 * directly means no second, independent post_content fetch/decode of
	 * our own; it is WPForms' own one authoritative decode for this render,
	 * not a side-channel read that could disagree with it.
	 *
	 * $form_atts['data'] entries are rendered as `data-<key>="<val>"` by
	 * WPForms' own wpforms_html_attributes() (includes/functions/escape-
	 * sanitize.php), which esc_attr()'s every value itself - so the values
	 * added here must be RAW, not pre-escaped (a second esc_attr() would
	 * corrupt an already-escaped value, e.g. double-encoding an `&`).
	 *
	 * @param mixed $form_atts Form attributes (id/class/data/atts), about to become the <form> tag.
	 * @param mixed $form_data Decoded form data (WPForms' own wpforms_decode() output).
	 * @return mixed
	 */
	public static function inject_via_form_atts( $form_atts, $form_data ) {
		if ( ! is_array( $form_atts ) || ! is_array( $form_data ) ) {
			return $form_atts;
		}

		$form_id = isset( $form_atts['data']['formid'] ) ? (int) $form_atts['data']['formid'] : 0;

		if ( $form_id <= 0 ) {
			return $form_atts;
		}

		$settings = ( isset( $form_data['settings'] ) && is_array( $form_data['settings'] ) ) ? $form_data['settings'] : array();

		$stored_event = isset( $settings[ self::SETTING_EVENT ] ) ? $settings[ self::SETTING_EVENT ] : '';
		$event_name   = self::resolve_event_name( is_string( $stored_event ) ? $stored_event : '', self::get_form_title( $form_id ) );

		if ( '' === $event_name ) {
			return $form_atts;
		}

		$data_pairs = self::resolve_data_pairs( isset( $settings[ self::SETTING_DATA ] ) ? $settings[ self::SETTING_DATA ] : array() );

		if ( ! isset( $form_atts['data'] ) || ! is_array( $form_atts['data'] ) ) {
			$form_atts['data'] = array();
		}

		$form_atts['data'] = array_merge(
			$form_atts['data'],
			EventAttributes::build_prefixed_event_data( 'wpforms', $event_name, $data_pairs ),
			array( 'umami-skip' => '1' )
		);

		return $form_atts;
	}

	/**
	 * Hooked on wpforms_process_redirect_url (WPForms' own
	 * class-process.php:1504) - fires on GENUINE success (WPForms' own
	 * validation-error guard has already returned earlier in process(),
	 * before process_complete()/this filter are ever reached) for the
	 * Redirect and Page confirmation types, BEFORE the wp_redirect() at
	 * :1522 that both types share. The Message confirmation type never
	 * redirects (no $url is built at all, so this filter never runs for
	 * it) - it keeps using the existing in-page confirmation-container
	 * signal in frontend.js (see consumeWpformsAjaxOffPending()'s
	 * docblock); this filter closes the gap for the two types that DO
	 * redirect.
	 *
	 * COOKIE-FREE BY DESIGN (Filipe, 2026-07-13): the plugin sets zero
	 * cookies today and umamiwp.com's own privacy promise is "no cookies" -
	 * so the success signal travels as a one-shot, same-origin URL
	 * query-arg marker instead of a cookie. The marker carries ONLY the
	 * form id - no event name/data (that payload already lives in the
	 * sessionStorage pending entry queued client-side at submit time,
	 * unchanged) - and is appended ONLY for a same-origin redirect target,
	 * so it can never land on a third party's URL. A cross-origin "Go to
	 * URL" redirect therefore honestly loses the event - the same accepted
	 * limitation class as an ad-blocker blocking frontend.js elsewhere in
	 * this plugin.
	 *
	 * @param mixed $url       Redirect URL WPForms is about to send the visitor to.
	 * @param mixed $form_id   The form's post ID.
	 * @param mixed $fields    Submitted field values (unused - the marker carries no payload).
	 * @param mixed $form_data Decoded form data (WPForms' own wpforms_decode() output).
	 * @param mixed $entry_id  The entry id (unused - WPForms Lite has no queryable entry storage).
	 * @return mixed
	 */
	public static function maybe_append_ajax_off_success_marker( $url, $form_id, $fields, $form_data, $entry_id ) {
		unset( $fields, $entry_id );

		if ( ! is_string( $url ) || '' === $url || ! is_array( $form_data ) ) {
			return $url;
		}

		// AJAX-on submissions already have a working client-side signal
		// (the jQuery wpformsAjaxSubmitSuccess event) - mirrors
		// frontend.js's own isWpformsAjaxForm() branch, so this filter
		// never touches that path.
		if ( ! empty( $form_data['settings']['ajax_submit'] ) ) {
			return $url;
		}

		if ( ! self::is_same_origin_redirect( $url ) ) {
			return $url;
		}

		$resolved_form_id = ( is_int( $form_id ) || is_numeric( $form_id ) ) ? (int) $form_id : 0;

		if ( $resolved_form_id <= 0 ) {
			return $url;
		}

		// Same "does this form have a configured event" predicate
		// inject_via_form_atts() uses - resolve_event_name() always falls
		// back to the title-slug, so this only ever skips a genuinely
		// untitled form.
		$settings     = ( isset( $form_data['settings'] ) && is_array( $form_data['settings'] ) ) ? $form_data['settings'] : array();
		$stored_event = isset( $settings[ self::SETTING_EVENT ] ) ? $settings[ self::SETTING_EVENT ] : '';
		$event_name   = self::resolve_event_name( is_string( $stored_event ) ? $stored_event : '', self::get_form_title( $resolved_form_id ) );

		if ( '' === $event_name ) {
			return $url;
		}

		return (string) add_query_arg( self::AJAX_OFF_SUCCESS_QUERY_ARG, $resolved_form_id, $url );
	}

	/**
	 * Whether a redirect URL is genuinely same-origin (scheme + host +
	 * effective port) as this site's home_url() - a relative URL (no host
	 * component at all, e.g. "/thank-you") counts as same-origin, and a
	 * protocol-relative URL ("//host/path") inherits the page's own scheme
	 * rather than being rejected on a missing one. Used to gate the
	 * success marker so it can never be appended to a destination that
	 * differs from this site by scheme or port, not just hostname (a
	 * previous host-only
	 * check let a same-host-different-port/scheme redirect - e.g. a second
	 * service on the same machine - through). Any parse failure or
	 * ambiguity returns false: a lost marker is the accepted fail-safe
	 * outcome, never a wrong-origin one. Pure aside from home_url().
	 *
	 * @param string $url Candidate redirect URL.
	 * @return bool
	 */
	private static function is_same_origin_redirect( $url ) {
		$target = wp_parse_url( $url );

		if ( ! is_array( $target ) ) {
			return false;
		}

		if ( ! isset( $target['host'] ) || '' === $target['host'] ) {
			return true;
		}

		$site = wp_parse_url( home_url() );

		if ( ! is_array( $site ) || ! isset( $site['host'] ) || '' === $site['host'] ) {
			return false;
		}

		if ( 0 !== strcasecmp( (string) $target['host'], (string) $site['host'] ) ) {
			return false;
		}

		$site_scheme   = isset( $site['scheme'] ) ? strtolower( (string) $site['scheme'] ) : '';
		$target_scheme = isset( $target['scheme'] ) ? strtolower( (string) $target['scheme'] ) : $site_scheme;

		if ( $target_scheme !== $site_scheme ) {
			return false;
		}

		$default_ports = array(
			'http'  => 80,
			'https' => 443,
		);

		$default_port = isset( $default_ports[ $target_scheme ] ) ? $default_ports[ $target_scheme ] : 0;
		$target_port  = isset( $target['port'] ) ? (int) $target['port'] : $default_port;
		$site_port    = isset( $site['port'] ) ? (int) $site['port'] : $default_port;

		return $target_port === $site_port;
	}

	/**
	 * Hooked on wpforms_builder_settings_sections: add a "Umami" builder
	 * section alongside WPForms' own (General, Notifications, ...).
	 *
	 * @param array<string, string> $sections  Existing sections, keyed by section id.
	 * @param array<string, mixed>  $form_data Unused - the section list doesn't depend on the current form.
	 * @return array<string, string>
	 */
	public static function add_section( $sections, $form_data = array() ) {
		unset( $form_data );

		$sections['stats_umami'] = __( 'Umami', 'stats-umami' );

		return $sections;
	}

	/**
	 * Hooked on wpforms_form_settings_panel_content: render the "Umami" section
	 * - an event-name field + a key/value data list. The event-name field and
	 * every key/value row are named as real `settings[...]` fields (the data
	 * rows as ARRAY-shaped
	 * `settings[stats_umami_event_data][N][key/value]` fields, not one hidden
	 * JSON-string field - see the class docblock) so WPForms' own native
	 * builder save persists them into the form's settings automatically (we
	 * never handle the save ourselves).
	 *
	 * @param object $instance The WPForms_Builder_Panel_Settings instance; its ->form_data holds the form's decoded settings.
	 */
	public static function render_section( $instance ) {
		$form_data = ( isset( $instance->form_data ) && is_array( $instance->form_data ) ) ? $instance->form_data : array();
		$settings  = ( isset( $form_data['settings'] ) && is_array( $form_data['settings'] ) ) ? $form_data['settings'] : array();

		$event_name  = isset( $settings[ self::SETTING_EVENT ] ) ? (string) $settings[ self::SETTING_EVENT ] : '';
		$data_pairs  = self::resolve_data_pairs( isset( $settings[ self::SETTING_DATA ] ) ? $settings[ self::SETTING_DATA ] : array() );
		$name_prefix = 'settings[' . self::SETTING_DATA . ']';
		?>
		<div class="wpforms-panel-content-section wpforms-panel-content-section-stats_umami" id="wpforms-panel-field-settings-stats_umami">
			<div class="wpforms-panel-content-section-title"><?php esc_html_e( 'Umami', 'stats-umami' ); ?></div>

			<div class="wpforms-panel-field">
				<label for="stats-umami-wpforms-event-name"><?php esc_html_e( 'Umami event name', 'stats-umami' ); ?></label>
				<input
					type="text"
					id="stats-umami-wpforms-event-name"
					name="settings[<?php echo esc_attr( self::SETTING_EVENT ); ?>]"
					value="<?php echo esc_attr( $event_name ); ?>"
					class="wpforms-panel-field-text"
				/>
				<p class="wpforms-alert wpforms-alert-info"><?php esc_html_e( 'Leave blank to use this form\'s own name as the event name.', 'stats-umami' ); ?></p>
			</div>

			<div class="wpforms-panel-field">
				<label><?php esc_html_e( 'Event data', 'stats-umami' ); ?></label>
				<p class="wpforms-alert wpforms-alert-info"><?php esc_html_e( 'Optional extra details sent with the event, as key/value pairs - for example: source = contact_page.', 'stats-umami' ); ?></p>
				<table class="widefat" id="stats-umami-wpforms-kv-table" data-name-prefix="<?php echo esc_attr( $name_prefix ); ?>" data-next-index="<?php echo (int) count( $data_pairs ); ?>">
					<tbody>
						<?php foreach ( array_values( $data_pairs ) as $index => $pair ) : ?>
							<tr>
								<td><input type="text" class="stats-umami-wpforms-kv-key" name="<?php echo esc_attr( $name_prefix . '[' . $index . '][key]' ); ?>" value="<?php echo esc_attr( (string) $pair['key'] ); ?>" placeholder="<?php esc_attr_e( 'key', 'stats-umami' ); ?>" /></td>
								<td><input type="text" class="stats-umami-wpforms-kv-value" name="<?php echo esc_attr( $name_prefix . '[' . $index . '][value]' ); ?>" value="<?php echo esc_attr( (string) $pair['value'] ); ?>" placeholder="<?php esc_attr_e( 'value', 'stats-umami' ); ?>" /></td>
								<td><button type="button" class="button stats-umami-wpforms-remove-row"><?php esc_html_e( 'Remove', 'stats-umami' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="stats-umami-wpforms-add-row"><?php esc_html_e( 'Add data pair', 'stats-umami' ); ?></button></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Resolve a stored settings[stats_umami_event_data] value into the
	 * {key,value} pair-array shape EventAttributes::build_prefixed_event_attributes()
	 * expects. The CURRENT shape is a real array (see the
	 * class docblock); a STRING is still accepted as a back-compat read path
	 * for a form saved by an older plugin version (no migration write - the old shape
	 * simply keeps decoding the old way indefinitely). Pure - no
	 * WordPress calls.
	 *
	 * @param mixed $stored Raw stored settings[stats_umami_event_data] value.
	 * @return array<int, array{key: string, value: mixed}>
	 */
	private static function resolve_data_pairs( $stored ) {
		if ( ! is_array( $stored ) ) {
			return EventAttributes::decode_data_pairs_json( $stored );
		}

		$pairs = array();

		foreach ( $stored as $pair ) {
			if ( is_array( $pair ) && isset( $pair['key'] ) ) {
				$pairs[] = array(
					'key'   => $pair['key'],
					'value' => isset( $pair['value'] ) ? $pair['value'] : '',
				);
			}
		}

		return $pairs;
	}

	/**
	 * Hooked on the_content / widget_text_content / widget_block_content:
	 * fast-returns unless the content contains WPForms' form/submit markers,
	 * else finds every WPForms form id present and injects each one's stored
	 * event.
	 *
	 * @param mixed $content Filtered content (WordPress guarantees a string for these hooks, but the type isn't enforced upstream).
	 * @return mixed
	 */
	public static function inject_in_content( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		if ( false === strpos( $content, 'wpforms-form-' ) && false === strpos( $content, 'wpforms-submit-' ) ) {
			return $content;
		}

		if ( ! preg_match_all( '/id="wpforms-form-(\d+)"/', $content, $matches ) ) {
			return $content;
		}

		foreach ( array_unique( $matches[1] ) as $form_id ) {
			$content = self::inject_for_form( $content, (int) $form_id );
		}

		return $content;
	}

	/**
	 * Inject one form's stored event into its submit control, plus the
	 * form-level skip marker. Since resolve_event_name() (via
	 * Support\EventAttributes::resolve_event_name()) always falls back to
	 * the form's own title-slug when no event is stored, this only ever returns
	 * $content unchanged for a form whose title itself sanitizes to ''
	 * (e.g. a genuinely untitled form).
	 *
	 * @param string $content The content being filtered.
	 * @param int    $form_id The WPForms form's post ID.
	 * @return string
	 */
	private static function inject_for_form( $content, $form_id ) {
		$settings = self::get_form_settings( $form_id );

		$stored_event = isset( $settings[ self::SETTING_EVENT ] ) ? $settings[ self::SETTING_EVENT ] : '';
		$event_name   = self::resolve_event_name( is_string( $stored_event ) ? $stored_event : '', self::get_form_title( $form_id ) );

		if ( '' === $event_name ) {
			return $content;
		}

		$data_pairs       = self::resolve_data_pairs( isset( $settings[ self::SETTING_DATA ] ) ? $settings[ self::SETTING_DATA ] : array() );
		$attribute_string = EventAttributes::build_prefixed_event_attributes( 'wpforms', $event_name, $data_pairs );

		return self::inject_into_form( $content, $form_id, $attribute_string );
	}

	/**
	 * Resolve the event name to inject: the stored per-form event if set,
	 * else the title-slug fallback - delegates to
	 * EventAttributes::resolve_event_name() rather than re-implementing the
	 * same trim/fallback/clamp shape a second time (moved off
	 * ContactForm7::resolve_event_name() so this integration no longer
	 * depends on another integration for its own behaviour).
	 *
	 * @param string $stored_event Raw stored event-name setting value ('' if unset).
	 * @param string $post_title   The form post's title, used for the fallback.
	 * @return string
	 */
	public static function resolve_event_name( $stored_event, $post_title ) {
		return EventAttributes::resolve_event_name( $stored_event, $post_title );
	}

	/**
	 * The WPForms form's own title, used as resolve_event_name()'s
	 * title-slug fallback input - a separate, minimal get_post() read rather
	 * than folding it into get_form_settings() (which stays settings-only,
	 * unchanged in shape).
	 *
	 * @param int $form_id The WPForms form's post ID.
	 * @return string
	 */
	private static function get_form_title( $form_id ) {
		$post = get_post( $form_id );

		return ( $post instanceof \WP_Post ) ? (string) $post->post_title : '';
	}

	/**
	 * Read a WPForms form's settings array, bypassing WPForms' object cache
	 * by reading get_post() directly (mirrors the old plugin's mechanism),
	 * with a wpforms()->form->get() fallback for setups where that direct
	 * read comes up empty.
	 *
	 * @param int $form_id The WPForms form's post ID.
	 * @return array<string, mixed>
	 */
	private static function get_form_settings( $form_id ) {
		$post = get_post( $form_id );

		if ( $post instanceof \WP_Post && 'wpforms' === $post->post_type ) {
			$settings = self::extract_settings( $post->post_content );

			if ( ! empty( $settings ) ) {
				return $settings;
			}
		}

		if ( function_exists( 'wpforms' ) ) {
			$wpforms = wpforms();

			if ( is_object( $wpforms ) && isset( $wpforms->form ) && is_object( $wpforms->form ) && method_exists( $wpforms->form, 'get' ) ) {
				$form = $wpforms->form->get( $form_id );

				if ( is_object( $form ) && isset( $form->post_content ) ) {
					return self::extract_settings( $form->post_content );
				}
			}
		}

		return array();
	}

	/**
	 * Decode a WPForms form's stored post_content (JSON) and return its
	 * settings sub-array.
	 *
	 * Deliberately plain json_decode(), NOT wpforms_decode():
	 * wpforms_decode() is json_decode() plus a trailing wp_unslash(),
	 * but this method is handed the RAW, already-unslashed post_content off
	 * a WP_Post - that extra unslash strips backslashes that are
	 * semantically part of our inner JSON (a data value containing `"` or
	 * `\`), decoding to NULL and silently dropping the form's stored pairs.
	 * The storage shape was never broken; only wpforms_decode() was the
	 * wrong reader for this call site.
	 *
	 * @param mixed $post_content Raw post_content value.
	 * @return array<string, mixed>
	 */
	private static function extract_settings( $post_content ) {
		if ( ! is_string( $post_content ) || '' === $post_content ) {
			return array();
		}

		$decoded = json_decode( $post_content, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['settings'] ) || ! is_array( $decoded['settings'] ) ) {
			return array();
		}

		return $decoded['settings'];
	}

	/**
	 * Inject the success-event attribute pair (data-umami-wpforms-event/-data)
	 * plus data-umami-skip="1" onto EVERY `<form id="wpforms-form-{id}">` in
	 * the content - not the submit control. `data-umami-event` is Umami's OWN
	 * native click-track attribute, which Umami's bundled tracker always
	 * auto-fires on click (unconditionally, since the
	 * option that used to suppress it); stamping it on the submit control
	 * would fire on every click, including invalid submits, not on genuine
	 * success. The renamed `data-umami-wpforms-event` pair is instead read by
	 * frontend.js's success listener and fired only on the real WPForms
	 * AJAX-success signal. All three attributes are added in one
	 * preg_replace_callback pass over the content - unbounded (was previously
	 * limited to 1), since the same form id can legitimately appear
	 * more than once on one page (the post body plus a widget, or two
	 * `[wpforms id=N]` shortcodes) - a limit of 1 left every occurrence after
	 * the first with neither the success attributes nor data-umami-skip, so
	 * it lost its own conversion event AND fired a spurious generic
	 * `form_submit`/`form:` one instead. The per-tag dedupe guard (skip when
	 * the form already carries `data-umami-wpforms-event=`) already makes an
	 * unbounded replace idempotent, so removing the limit is safe even if
	 * this filter somehow ran twice over the same content. Pure - no
	 * WordPress calls.
	 *
	 * @param string $content          The content being filtered.
	 * @param int    $form_id          The WPForms form's post ID.
	 * @param string $attribute_string Pre-built, escaped data-umami-wpforms-event(+-data) attribute string.
	 * @return string
	 */
	public static function inject_into_form( $content, $form_id, $attribute_string ) {
		$pattern = '/<form\b[^>]*\bid="wpforms-form-' . $form_id . '"[^>]*>/i';

		$injected = preg_replace_callback(
			$pattern,
			static function ( $matches ) use ( $attribute_string ) {
				$tag = $matches[0];

				if ( false !== strpos( $tag, 'data-umami-wpforms-event=' ) ) {
					return $tag;
				}

				return substr( $tag, 0, -1 ) . ' ' . $attribute_string . ' data-umami-skip="1">';
			},
			$content
		);

		return null !== $injected ? $injected : $content;
	}

	/**
	 * Hooked on admin_print_footer_scripts: print the builder section's
	 * add/remove-row JS - only on WPForms admin screens. No longer syncs a
	 * hidden JSON-string field on every keystroke - each
	 * key/value `<input>` IS itself a real `settings[...]` field (see
	 * render_section()), so WPForms' own native save already persists them
	 * directly; this script only needs to add/remove `<tr>` rows, each with
	 * correctly array-indexed `name` attributes read from the table's
	 * `data-name-prefix` (set by render_section(), so the field-name literal
	 * `stats_umami_event_data` lives in exactly one place).
	 *
	 * A new row's index must be a MONOTONIC COUNTER
	 * (`data-next-index`, initialized by render_section() to the pair
	 * count and incremented - never decremented - on every add), not
	 * `tbody.querySelectorAll('tr').length`. Row count is not a unique
	 * index once a row has ever been removed: 3 pairs (indices 0,1,2) ->
	 * remove the MIDDLE row (indices now 0,2, length 2) -> "Add data pair"
	 * using `.length` reused index 2 for the new row too - two fields both
	 * named `settings[stats_umami_event_data][2][key]` submit, and
	 * WPForms' native PHP array-building lets the later one silently win,
	 * destroying the pre-existing third pair with no error at all.
	 * resolve_data_pairs() iterates array VALUES, never depending on the
	 * indices being contiguous or starting at 0, so any scheme that never
	 * reuses a number is sufficient - a counter that only ever increases is
	 * the simplest one.
	 */
	public static function print_kv_script() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || false === strpos( (string) $screen->id, 'wpforms' ) ) {
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
				var table = document.getElementById( 'stats-umami-wpforms-kv-table' );

				if ( ! table || ! event.target ) {
					return;
				}

				if ( event.target.id === 'stats-umami-wpforms-add-row' ) {
					event.preventDefault();

					var tbody  = table.querySelector( 'tbody' );
					var prefix = table.getAttribute( 'data-name-prefix' ) || '';
					var index  = parseInt( table.getAttribute( 'data-next-index' ), 10 ) || 0;
					table.setAttribute( 'data-next-index', String( index + 1 ) );

					var row = document.createElement( 'tr' );

					var keyCell  = document.createElement( 'td' );
					var keyInput = document.createElement( 'input' );
					keyInput.type = 'text';
					keyInput.className = 'stats-umami-wpforms-kv-key';
					keyInput.name = prefix + '[' + index + '][key]';
					// The translated
					// placeholder, matching the server-rendered row's own
					// esc_attr_e() call - not a hardcoded English literal.
					keyInput.placeholder = <?php echo $key_placeholder; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assigned to .placeholder, never .innerHTML, so this can never be interpreted as markup regardless of its content. ?>;
					keyCell.appendChild( keyInput );

					var valueCell  = document.createElement( 'td' );
					var valueInput = document.createElement( 'input' );
					valueInput.type = 'text';
					valueInput.className = 'stats-umami-wpforms-kv-value';
					valueInput.name = prefix + '[' + index + '][value]';
					valueInput.placeholder = <?php echo $value_placeholder; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assigned to .placeholder, never .innerHTML, so this can never be interpreted as markup regardless of its content. ?>;
					valueCell.appendChild( valueInput );

					var buttonCell = document.createElement( 'td' );
					var button     = document.createElement( 'button' );
					button.type = 'button';
					button.className = 'button stats-umami-wpforms-remove-row';
					button.textContent = <?php echo $remove_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assigned to .textContent, never .innerHTML, so this can never be interpreted as markup regardless of its content. ?>;
					buttonCell.appendChild( button );

					row.appendChild( keyCell );
					row.appendChild( valueCell );
					row.appendChild( buttonCell );

					tbody.appendChild( row );
				}

				if ( event.target.classList && event.target.classList.contains( 'stats-umami-wpforms-remove-row' ) ) {
					event.preventDefault();
					event.target.closest( 'tr' ).remove();
				}
			} );
		}() );
		</script>
		<?php
	}
}
