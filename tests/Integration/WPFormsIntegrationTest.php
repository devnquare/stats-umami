<?php
/**
 * DB-backed integration tests for the WPForms integration: settings
 * resolution (real get_post() reads against a real WPForms-shaped form
 * post - WPForms itself isn't installed in this bootstrap, so
 * wpforms_decode()/wpforms() aren't available and the code exercises its
 * own json_decode() fallback path, which is real production behaviour, not
 * a mock) plus Integrations\Manager's registration gating, against a real
 * WP core bootstrap + test database.
 *
 * inject_in_content() is called directly (a plain method call) rather than
 * via apply_filters( 'the_content', ... ): a real WP bootstrap's
 * default-filters.php attaches wpautop()/wptexturize()/do_shortcode() etc.
 * to the_content, which would mangle these fixtures for reasons that have
 * nothing to do with this integration. Calling the exact registered
 * callback directly still exercises the same real get_post()/post_content
 * decode path end to end; the Manager gating test below separately proves
 * the callback IS the one wired to the_content via has_filter().
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

use StatsUmami\Integrations\Manager;
use StatsUmami\Integrations\WPForms;
use StatsUmami\Settings\Options;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * @covers \StatsUmami\Integrations\Manager
 * @covers \StatsUmami\Integrations\WPForms
 */
final class WPFormsIntegrationTest extends TestCase {

	public function set_up() {
		parent::set_up();

		delete_option( Options::OPTION_KEY );
		wp_set_current_user( 0 );
	}

	/**
	 * A fully-configured, trackable + WPForms-enabled options array, with
	 * the given overrides layered on top.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function trackable_options( array $overrides = array() ) {
		$options                   = Options::defaults();
		$options['enabled']        = true;
		$options['enable_wpforms'] = true;
		$options['schema_version'] = Options::SCHEMA_VERSION;

		return array_merge( $options, $overrides );
	}

	/**
	 * A real WPForms form post: post_content holds the JSON shape WPForms'
	 * own post_content storage uses (`{"settings": {...}}`), which
	 * WPForms::extract_settings() decodes via its json_decode() fallback
	 * since wpforms_decode() isn't defined in this bootstrap.
	 *
	 * @param array<string, mixed> $settings Settings to store under the 'settings' key.
	 * @return int
	 */
	private function create_form_post( array $settings ) {
		// wp_insert_post() (which the post factory calls) unslashes its
		// input, as if it came straight from $_POST - wp_slash() here
		// counteracts that so the backslash-escaped quotes inside this
		// nested JSON (the event-data value is itself a JSON string) survive
		// the round-trip intact, matching how a real WPForms builder save
		// (going through an actual $_POST submission) would arrive slashed.
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'wpforms',
				'post_title'   => 'A WPForms form',
				'post_status'  => 'publish',
				'post_content' => wp_slash( wp_json_encode( array( 'settings' => $settings ) ) ),
			)
		);
	}

	/**
	 * Realistic WPForms embed markup for a given form id.
	 *
	 * @param int $form_id The form's post ID.
	 * @return string
	 */
	private function wpforms_markup( $form_id ) {
		return '<div class="wpforms-container wpforms-container-full" id="wpforms-' . $form_id . '">'
			. '<form id="wpforms-form-' . $form_id . '" class="wpforms-validate wpforms-form" data-formid="' . $form_id . '" method="post">'
			. '<div class="wpforms-field-container"><input type="text" name="wpforms[fields][1]" /></div>'
			. '<button type="submit" name="wpforms[submit]" id="wpforms-submit-' . $form_id . '" class="wpforms-submit">Submit</button>'
			. '</form></div>';
	}

	// ---------------------------------------------------------------
	// inject_in_content(): direct call, real DB-backed settings read.
	// ---------------------------------------------------------------

	public function test_injects_single_event_and_skip_marker_using_stored_settings() {
		$form_id = $this->create_form_post(
			array(
				'stats_umami_event_name' => 'contact',
				'stats_umami_event_data' => wp_json_encode( array( 'source' => 'footer' ) ),
			)
		);

		$rendered = WPForms::inject_in_content( $this->wpforms_markup( $form_id ) );

		$this->assertSame( 1, substr_count( $rendered, 'data-umami-wpforms-event=' ) );
		$this->assertStringContainsString( 'data-umami-wpforms-event="contact"', $rendered );
		$this->assertSame( 1, substr_count( $rendered, 'data-umami-wpforms-data=' ) );
		$this->assertStringContainsString( 'source', $rendered );
		$this->assertSame( 1, substr_count( $rendered, 'data-umami-skip=' ) );
		$this->assertStringContainsString( 'data-umami-skip="1"', $rendered );
		// Never Umami's own native click-track attribute - see
		// WPForms::inject_into_form()'s docblock.
		$this->assertStringNotContainsString( 'data-umami-event=', $rendered );
	}

	public function test_falls_back_to_the_title_slug_when_no_event_name_is_stored() {
		// Closes the asymmetry with CF7 - an unconfigured
		// WPForms form no longer emits neither attribute (which used to let
		// the generic auto-track submit listener fire on every submission,
		// including validation failures - see Integrations\WPForms's class
		// docblock). It now always gets the success attributes + skip
		// marker, using its own title (create_form_post()'s fixture title
		// is "A WPForms form") as the event name.
		$form_id = $this->create_form_post( array() );

		$rendered = WPForms::inject_in_content( $this->wpforms_markup( $form_id ) );

		$this->assertSame( 1, substr_count( $rendered, 'data-umami-wpforms-event=' ) );
		$this->assertStringContainsString( 'data-umami-wpforms-event="a-wpforms-form"', $rendered );
		$this->assertSame( 1, substr_count( $rendered, 'data-umami-skip=' ) );
		$this->assertStringContainsString( 'data-umami-skip="1"', $rendered );
		$this->assertStringNotContainsString( 'data-umami-event=', $rendered );
	}

	// ---------------------------------------------------------------
	// extract_settings() must decode
	// post_content with plain json_decode(), not wpforms_decode() - the
	// latter's extra wp_unslash() strips backslashes that are semantically
	// part of our inner JSON (a data value containing `"` or `\`), which
	// used to decode the whole settings sub-array to NULL and silently drop
	// every stored pair. The no-quote baseline is already covered by
	// test_injects_single_event_and_skip_marker_using_stored_settings()
	// above (its 'source' => 'footer' pair) - unaffected by this fix,
	// asserted there as the regression guard for swapping the decoder.
	//
	// WPForms itself isn't installed in this bootstrap, so
	// function_exists('wpforms_decode') is always false on its own - the
	// PRE-FIX ternary would therefore silently take the exact same
	// json_decode() branch the fixed code always takes, making a test that
	// exercises only that shared branch unable to tell fixed from unfixed
	// (a first version of these tests had exactly this defect - passed
	// against the reverted line too, per a PM live falsification. Caught,
	// not shipped). require the wpforms_decode() stub below FIRST so
	// function_exists('wpforms_decode') is genuinely true and the buggy
	// branch is actually reachable when the fix is reverted.
	// ---------------------------------------------------------------

	/**
	 * Build a form whose event-data has one pair {note: $value}, run it
	 * through the real inject_in_content() -> extract_settings() path, and
	 * assert the value survives byte-for-byte.
	 *
	 * @param string $value Raw event-data value to round-trip.
	 */
	private function assert_wpforms_event_data_value_round_trips( $value ) {
		require_once __DIR__ . '/wpforms-decode-function-stub.php';

		$form_id = $this->create_form_post(
			array(
				'stats_umami_event_name' => 'contact',
				'stats_umami_event_data' => wp_json_encode( array( 'note' => $value ) ),
			)
		);

		$rendered = WPForms::inject_in_content( $this->wpforms_markup( $form_id ) );

		$this->assertMatchesRegularExpression( '/data-umami-wpforms-data="([^"]*)"/', $rendered );

		preg_match( '/data-umami-wpforms-data="([^"]*)"/', $rendered, $matches );

		$decoded = json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true );

		$this->assertSame( array( 'note' => $value ), $decoded );
	}

	public function test_injects_a_data_pair_whose_value_contains_a_double_quote() {
		$this->assert_wpforms_event_data_value_round_trips( 'He said "hi" to me' );
	}

	public function test_injects_a_data_pair_whose_value_contains_a_backslash() {
		$this->assert_wpforms_event_data_value_round_trips( 'C:\path\to\file' );
	}

	public function test_injects_a_data_pair_whose_value_contains_an_emoji() {
		$this->assert_wpforms_event_data_value_round_trips( 'Great job 🎉' );
	}

	// ---------------------------------------------------------------
	// The THREE tests above build post_content
	// directly via wp_slash(wp_json_encode(...)) - a convenient FIXTURE,
	// not a real save. That shortcut is exactly what made an earlier
	// regression test on this project vacuous (see this file's own comment
	// above), and it is ALSO why an earlier one-line reader fix was
	// wrongly declared to have closed the underlying issue: WPForms' REAL save path
	// (wpforms_save_form() -> WPForms_Form_Handler::update()) corrupts a
	// value containing `"` at WRITE time, before any reader ever runs - a
	// fixture that starts from an already-correctly-escaped string can
	// never exercise that. The tests below drive the value through the
	// REAL mechanism instead, confirmed against the installed WPForms Lite
	// 1.10.2.1 source (wp-content/plugins/wpforms-lite/includes/
	// class-form.php update(); includes/functions/forms.php
	// wpforms_encode()):
	//   1. A real HTTP request's $_POST arrives slashed (WP's own boot-time
	//      wp_magic_quotes()).
	//   2. wpforms_save_form(): json_decode(wp_unslash($_POST['data'])) -
	//      ONE unslash, cancels step 1, giving a clean decoded array
	//      matching exactly what the builder's JS sent.
	//   3. WPForms_Form_Handler::update(), default (non-slashing) mode:
	//      $data = (array) wp_unslash($data) - an EXTRA, UNCANCELLED
	//      unslash over data that is already clean. This is the actual
	//      corrupting step for the OLD nested-JSON-STRING storage shape
	//      (stripping the backslash out of an escaped `\"` inside a value
	//      that is itself a JSON-encoded string - see Integrations\WPForms's
	//      class docblock). The CURRENT plain-ARRAY shape has no such
	//      nested string for this step to damage - it only ever touches
	//      leaf key/value strings (losing a literal `\`, same as every
	//      native WPForms setting - documented, minor, accepted).
	//   4. wpforms_encode(): wp_slash(wp_json_encode($data)) before handing
	//      off to wp_update_post().
	//   5. The REAL wp_update_post() -> wp_insert_post() (not simulated)
	//      runs its OWN final wp_unslash($data) immediately before the DB
	//      write (wp-includes/post.php), cancelling step 4's slash - so
	//      post_content lands PLAIN in the DB, exactly like a real save.
	// ---------------------------------------------------------------

	/**
	 * Drive the given form settings through WPForms' REAL save mechanism
	 * end-to-end (steps 1-5 above), landing the result in the given form's
	 * actual post_content via the REAL wp_update_post()/wp_insert_post()
	 * (not a hand-rolled DB write).
	 *
	 * @param int                  $form_id  The form's post ID.
	 * @param array<string, mixed> $settings The 'settings' sub-array, as the builder JS would submit it.
	 */
	private function save_form_via_wpforms_real_update_path( $form_id, array $settings ) {
		$form_submission = array(
			'id'       => $form_id,
			'settings' => $settings,
		);

		// Step 1: a real request's $_POST['data'] arrives slashed.
		$slashed_post_data = wp_slash( wp_json_encode( $form_submission ) );

		// Step 2, mirroring wpforms_save_form()'s own decode.
		$data = json_decode( wp_unslash( $slashed_post_data ), true );

		// Step 3, mirroring WPForms_Form_Handler::update()'s default
		// non-slashing mode - the actual corrupting step for the old
		// string shape.
		$data = (array) wp_unslash( $data );

		// Step 4, mirroring wpforms_encode()'s own re-slash.
		$post_content = wp_slash( wp_json_encode( $data ) );

		// Step 5: the REAL wp_update_post()/wp_insert_post().
		$result = wp_update_post(
			array(
				'ID'           => $form_id,
				'post_content' => $post_content,
			),
			true
		);

		$this->assertNotWPError( $result, 'The real-path simulated save itself must succeed.' );
	}

	/**
	 * Create a throwaway form, drive ONE data-pair value through WPForms'
	 * real save mechanism (as the CURRENT array-shaped builder markup - see
	 * render_section() - would submit it), and assert it survives to the
	 * injected data-umami-wpforms-data.
	 *
	 * @param string $value Raw event-data value to round-trip.
	 */
	private function assert_real_update_path_event_data_value_round_trips( $value ) {
		$form_id = $this->create_form_post( array() );

		$this->save_form_via_wpforms_real_update_path(
			$form_id,
			array(
				'stats_umami_event_name' => 'contact',
				'stats_umami_event_data' => array(
					array(
						'key'   => 'note',
						'value' => $value,
					),
				),
			)
		);

		$rendered = WPForms::inject_in_content( $this->wpforms_markup( $form_id ) );

		$this->assertMatchesRegularExpression( '/data-umami-wpforms-data="([^"]*)"/', $rendered );

		preg_match( '/data-umami-wpforms-data="([^"]*)"/', $rendered, $matches );

		$decoded = json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true );

		$this->assertSame( array( 'note' => $value ), $decoded );
	}

	public function test_real_update_path_a_plain_control_value_round_trips() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assert_real_update_path_event_data_value_round_trips( 'homepage' );
	}

	/**
	 * THE headline case. Must fail against the previous
	 * nested-JSON-string storage: reverting resolve_data_pairs() back to
	 * always decoding settings[stats_umami_event_data] as a JSON string
	 * makes step 3 above corrupt this value's escaped quote at WRITE time,
	 * before extract_settings()/decode_data_pairs_json() ever runs, so no
	 * reader-side fix can recover it - see the class docblock.
	 */
	public function test_real_update_path_a_double_quote_value_round_trips() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assert_real_update_path_event_data_value_round_trips( 'promo "q" test' );
	}

	public function test_real_update_path_an_emoji_value_round_trips() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assert_real_update_path_event_data_value_round_trips( 'done 🎉' );
	}

	public function test_does_not_inject_for_content_with_no_wpforms_markers() {
		$content = '<p>Nothing to see here.</p>';

		$this->assertSame( $content, WPForms::inject_in_content( $content ) );
	}

	public function test_injects_into_multiple_forms_present_in_the_same_content() {
		$form_a = $this->create_form_post( array( 'stats_umami_event_name' => 'form-a' ) );
		$form_b = $this->create_form_post( array( 'stats_umami_event_name' => 'form-b' ) );

		$content  = $this->wpforms_markup( $form_a ) . $this->wpforms_markup( $form_b );
		$rendered = WPForms::inject_in_content( $content );

		$this->assertStringContainsString( 'data-umami-wpforms-event="form-a"', $rendered );
		$this->assertStringContainsString( 'data-umami-wpforms-event="form-b"', $rendered );
		$this->assertSame( 2, substr_count( $rendered, 'data-umami-wpforms-event=' ) );
	}

	/**
	 * The SAME form id can legitimately appear
	 * more than once in one page's content (the post body plus a widget, or
	 * two `[wpforms id=N]` shortcodes) - inject_into_form()'s
	 * preg_replace_callback used to cap at a limit of 1, so only the FIRST
	 * occurrence got the success attributes + data-umami-skip; the second
	 * got neither, losing its own conversion event and (since it then
	 * carries no data-umami-skip) firing a spurious generic form_submit/
	 * form: event from frontend.js's auto-track instead. Must fail against
	 * the limit-1 version (only 1 occurrence rewritten instead of 2).
	 */
	public function test_injects_into_both_occurrences_when_the_same_form_id_appears_twice() {
		$form_id = $this->create_form_post( array( 'stats_umami_event_name' => 'contact' ) );

		$content  = $this->wpforms_markup( $form_id ) . '<p>Some content between.</p>' . $this->wpforms_markup( $form_id );
		$rendered = WPForms::inject_in_content( $content );

		$this->assertSame( 2, substr_count( $rendered, 'data-umami-wpforms-event="contact"' ) );
		$this->assertSame( 2, substr_count( $rendered, 'data-umami-skip="1"' ) );
	}

	// ---------------------------------------------------------------
	// Integrations\Manager gating.
	// ---------------------------------------------------------------

	// ---------------------------------------------------------------
	// print_kv_script()'s JS-added rows
	// must use the SAME translated key/value placeholders as the
	// server-rendered rows (esc_attr_e('key'/'value', 'stats-umami') in
	// render_section()), not hardcoded English literals. Driven via a real
	// `gettext` filter override (CLAVE/VALOR).
	// ---------------------------------------------------------------

	public function test_print_kv_script_uses_translated_key_value_placeholders() {
		set_current_screen( 'wpforms_page_wpforms-builder' );

		add_filter(
			'gettext',
			static function ( $translated, $text, $domain ) {
				if ( 'stats-umami' !== $domain ) {
					return $translated;
				}

				if ( 'key' === $text ) {
					return 'CLAVE';
				}

				if ( 'value' === $text ) {
					return 'VALOR';
				}

				return $translated;
			},
			10,
			3
		);

		ob_start();
		WPForms::print_kv_script();
		$script = ob_get_clean();

		$this->assertStringContainsString( 'placeholder = "CLAVE"', $script );
		$this->assertStringContainsString( 'placeholder = "VALOR"', $script );
		$this->assertStringNotContainsString( "placeholder = 'key'", $script );
		$this->assertStringNotContainsString( "placeholder = 'value'", $script );
	}

	// ---------------------------------------------------------------
	// A direct wpforms_display()
	// embed - or the classic widget, or the Gutenberg block - renders
	// through WPForms' Frontend::output(), which fires
	// wpforms_frontend_form_atts, but NEVER passes through the_content /
	// widget_text_content / widget_block_content. inject_via_form_atts() is
	// hooked on that filter so it covers every render path, closing the gap
	// the content-filter-only mechanism left. Called directly here (as this
	// project's tests call every other registered callback directly - see
	// this file's own docblock) with hand-built $form_atts/$form_data
	// matching the REAL shape WPForms' Frontend::output() passes (verified
	// against the installed WPForms Lite 1.10.2.1 source,
	// src/Frontend/Frontend.php:282-303): $form_atts carries
	// ['data' => ['formid' => N]] before the filter runs, and $form_data is
	// WPForms' own already-decoded settings array (the SAME one every other
	// WPForms internal reads for this render) - never a second,
	// independent post_content fetch of our own.
	// ---------------------------------------------------------------

	/**
	 * The $form_atts shape WPForms' Frontend::output() builds BEFORE
	 * calling apply_filters( 'wpforms_frontend_form_atts', ... ).
	 *
	 * @param int $form_id The form's post ID.
	 * @return array<string, mixed>
	 */
	private function real_pre_filter_form_atts( $form_id ) {
		return array(
			'id'    => 'wpforms-form-' . $form_id,
			'class' => array( 'wpforms-validate', 'wpforms-form' ),
			'data'  => array( 'formid' => $form_id ),
			'atts'  => array(
				'method'  => 'post',
				'enctype' => 'multipart/form-data',
				'action'  => '',
			),
		);
	}

	public function test_inject_via_form_atts_adds_event_and_skip_for_a_wpforms_display_style_render_with_no_content_filter_involved() {
		$form_data = array(
			'id'       => 42,
			'settings' => array(
				'stats_umami_event_name' => 'contact',
				'stats_umami_event_data' => array(
					array(
						'key'   => 'source',
						'value' => 'footer',
					),
				),
			),
		);

		$post_id = $this->create_form_post( array() );

		$result = WPForms::inject_via_form_atts( $this->real_pre_filter_form_atts( $post_id ), $form_data );

		$this->assertSame( 'contact', $result['data']['umami-wpforms-event'] );
		$this->assertSame( array( 'source' => 'footer' ), json_decode( $result['data']['umami-wpforms-data'], true ) );
		$this->assertSame( '1', $result['data']['umami-skip'] );
		// The pre-existing keys WPForms itself set before the filter ran
		// must survive untouched.
		$this->assertSame( $post_id, $result['data']['formid'] );
	}

	public function test_inject_via_form_atts_falls_back_to_the_title_slug_when_no_event_name_is_stored() {
		$post_id = $this->create_form_post( array() );

		$form_data = array(
			'id'       => $post_id,
			'settings' => array(),
		);

		$result = WPForms::inject_via_form_atts( $this->real_pre_filter_form_atts( $post_id ), $form_data );

		$this->assertSame( 'a-wpforms-form', $result['data']['umami-wpforms-event'] );
		$this->assertSame( '1', $result['data']['umami-skip'] );
	}

	public function test_inject_via_form_atts_leaves_form_atts_unchanged_when_formid_is_missing_or_zero() {
		$form_atts = array(
			'id'   => 'wpforms-form-0',
			'data' => array(),
		);

		$result = WPForms::inject_via_form_atts( $form_atts, array( 'settings' => array( 'stats_umami_event_name' => 'contact' ) ) );

		$this->assertSame( $form_atts, $result );
	}

	/**
	 * The requirement's other half: the generic form: fallback in
	 * frontend.js is suppressed by data-umami-skip="1" on the <form>
	 * element - this asserts the PHP side of that contract (the attribute
	 * is genuinely present on the rendered tag) using the values
	 * inject_via_form_atts() returns, exactly as WPForms' own
	 * wpforms_html_attributes() would render them (data-<key>="<val>" per
	 * entry - includes/functions/escape-sanitize.php, verified against the
	 * installed source).
	 */
	public function test_inject_via_form_atts_result_renders_the_skip_attribute_wpforms_html_attributes_would_produce() {
		$post_id = $this->create_form_post( array( 'stats_umami_event_name' => 'contact' ) );

		$result = WPForms::inject_via_form_atts( $this->real_pre_filter_form_atts( $post_id ), array( 'settings' => array( 'stats_umami_event_name' => 'contact' ) ) );

		$rendered = '';
		foreach ( $result['data'] as $key => $val ) {
			$rendered .= sprintf( ' data-%s="%s"', $key, esc_attr( $val ) );
		}

		$this->assertStringContainsString( 'data-umami-skip="1"', $rendered );
		$this->assertStringContainsString( 'data-umami-wpforms-event="contact"', $rendered );
	}

	/**
	 * No double-injection: inject_via_form_atts() runs INSIDE WPForms'
	 * Frontend::output() before the resulting markup ever reaches the
	 * content filters below (do_shortcode() expands the [wpforms]
	 * shortcode - and therefore fires wpforms_frontend_form_atts - at a
	 * lower the_content priority than this plugin's own priority-19
	 * inject_in_content()). Simulates that ordering: build the markup AS IF
	 * the form_atts hook had already stamped it, then run inject_in_content()
	 * over it and confirm its dedupe guard leaves it untouched.
	 */
	public function test_content_filter_does_not_double_inject_when_form_atts_hook_already_stamped_the_form() {
		$form_id = $this->create_form_post( array( 'stats_umami_event_name' => 'contact' ) );

		$already_stamped = '<div class="wpforms-container" id="wpforms-' . $form_id . '">'
			. '<form id="wpforms-form-' . $form_id . '" class="wpforms-validate wpforms-form" data-formid="' . $form_id . '" '
			. 'data-umami-wpforms-event="contact" data-umami-skip="1" method="post">'
			. '<button type="submit">Submit</button></form></div>';

		$result = WPForms::inject_in_content( $already_stamped );

		$this->assertSame( 1, substr_count( $result, 'data-umami-wpforms-event=' ) );
		$this->assertSame( 1, substr_count( $result, 'data-umami-skip=' ) );
		$this->assertSame( $already_stamped, $result );
	}

	// ---------------------------------------------------------------
	// maybe_append_ajax_off_success_marker()
	// - hooked on wpforms_process_redirect_url (installed WPForms Lite
	// 1.10.2.1 class-process.php:1504), fires only on GENUINE success for
	// the Redirect/Page confirmation types, before the shared wp_redirect()
	// at :1522. Cookie-free by design (Filipe, 2026-07-13): the marker is a
	// same-origin URL query arg, appended only for a tracked, AJAX-off,
	// same-origin redirect.
	// ---------------------------------------------------------------

	// These two positive
	// fixtures used to hardcode 'https://example.org/thank-you/' while the WP
	// test harness's home_url() is 'http://example.org' (http, no port) - they
	// passed only because the pre-fix host-only gate ignored the scheme
	// mismatch. Deriving the base URL from home_url() itself keeps every case
	// genuinely same-origin and immune to harness config drift.

	public function test_marker_appended_for_a_tracked_ajax_off_same_origin_redirect() {
		$post_id = $this->create_form_post( array( 'stats_umami_event_name' => 'contact' ) );

		$form_data = array(
			'id'       => $post_id,
			'settings' => array(
				'stats_umami_event_name' => 'contact',
				// ajax_submit deliberately absent/falsy => AJAX-off.
			),
		);

		$result = WPForms::maybe_append_ajax_off_success_marker( home_url( '/thank-you/' ), $post_id, array(), $form_data, 123 );

		$this->assertStringContainsString( 'stats_umami_wpf_ok=' . $post_id, $result );
	}

	public function test_marker_uses_the_title_slug_fallback_when_no_event_is_stored() {
		$post_id = $this->create_form_post( array() );

		$form_data = array(
			'id'       => $post_id,
			'settings' => array(),
		);

		$result = WPForms::maybe_append_ajax_off_success_marker( home_url( '/thank-you/' ), $post_id, array(), $form_data, 123 );

		$this->assertStringContainsString( 'stats_umami_wpf_ok=' . $post_id, $result );
	}

	public function test_marker_not_appended_for_an_ajax_on_form() {
		$post_id = $this->create_form_post( array( 'stats_umami_event_name' => 'contact' ) );

		$form_data = array(
			'id'       => $post_id,
			'settings' => array(
				'stats_umami_event_name' => 'contact',
				'ajax_submit'            => 1,
			),
		);

		$url    = 'https://example.org/thank-you/';
		$result = WPForms::maybe_append_ajax_off_success_marker( $url, $post_id, array(), $form_data, 123 );

		$this->assertSame( $url, $result );
	}

	public function test_marker_not_appended_for_a_cross_origin_redirect() {
		$post_id = $this->create_form_post( array( 'stats_umami_event_name' => 'contact' ) );

		$form_data = array(
			'id'       => $post_id,
			'settings' => array( 'stats_umami_event_name' => 'contact' ),
		);

		$url    = 'https://a-completely-different-site.example/thanks/';
		$result = WPForms::maybe_append_ajax_off_success_marker( $url, $post_id, array(), $form_data, 123 );

		$this->assertSame( $url, $result );
	}

	// is_same_origin_redirect() used to compare host only, so a
	// same-host redirect on a different port or scheme was wrongly treated as
	// same-origin. These three cases derive their URLs from home_url() itself
	// so they stay correct regardless of the harness's configured domain.

	public function test_marker_not_appended_for_a_cross_port_redirect() {
		$post_id = $this->create_form_post( array( 'stats_umami_event_name' => 'contact' ) );

		$form_data = array(
			'id'       => $post_id,
			'settings' => array( 'stats_umami_event_name' => 'contact' ),
		);

		$home = wp_parse_url( home_url() );
		$url  = $home['scheme'] . '://' . $home['host'] . ':3000/thank-you/';

		$result = WPForms::maybe_append_ajax_off_success_marker( $url, $post_id, array(), $form_data, 123 );

		$this->assertSame( $url, $result );
	}

	public function test_marker_not_appended_for_a_cross_scheme_redirect() {
		$post_id = $this->create_form_post( array( 'stats_umami_event_name' => 'contact' ) );

		$form_data = array(
			'id'       => $post_id,
			'settings' => array( 'stats_umami_event_name' => 'contact' ),
		);

		$home           = wp_parse_url( home_url() );
		$flipped_scheme = ( 'https' === $home['scheme'] ) ? 'http' : 'https';
		$url            = $flipped_scheme . '://' . $home['host'] . '/thank-you/';

		$result = WPForms::maybe_append_ajax_off_success_marker( $url, $post_id, array(), $form_data, 123 );

		$this->assertSame( $url, $result );
	}

	public function test_marker_appended_when_explicit_default_port_matches_omitted_port() {
		$post_id = $this->create_form_post( array( 'stats_umami_event_name' => 'contact' ) );

		$form_data = array(
			'id'       => $post_id,
			'settings' => array( 'stats_umami_event_name' => 'contact' ),
		);

		$home         = wp_parse_url( home_url() );
		$default_port = ( 'https' === $home['scheme'] ) ? 443 : 80;
		$url          = $home['scheme'] . '://' . $home['host'] . ':' . $default_port . '/thank-you/';

		$result = WPForms::maybe_append_ajax_off_success_marker( $url, $post_id, array(), $form_data, 123 );

		$this->assertStringContainsString( 'stats_umami_wpf_ok=' . $post_id, $result );
	}

	public function test_marker_not_appended_when_url_is_empty_or_not_a_string() {
		$post_id = $this->create_form_post( array( 'stats_umami_event_name' => 'contact' ) );

		$form_data = array(
			'id'       => $post_id,
			'settings' => array( 'stats_umami_event_name' => 'contact' ),
		);

		$this->assertSame( '', WPForms::maybe_append_ajax_off_success_marker( '', $post_id, array(), $form_data, 123 ) );
		$this->assertNull( WPForms::maybe_append_ajax_off_success_marker( null, $post_id, array(), $form_data, 123 ) );
	}

	public function test_marker_appended_for_a_relative_same_origin_url() {
		$post_id = $this->create_form_post( array( 'stats_umami_event_name' => 'contact' ) );

		$form_data = array(
			'id'       => $post_id,
			'settings' => array( 'stats_umami_event_name' => 'contact' ),
		);

		$result = WPForms::maybe_append_ajax_off_success_marker( '/thank-you/?foo=bar', $post_id, array(), $form_data, 123 );

		$this->assertStringContainsString( 'stats_umami_wpf_ok=' . $post_id, $result );
		$this->assertStringContainsString( 'foo=bar', $result );
	}

	public function test_manager_gates_wpforms_registration_on_master_toggle_and_dependency() {
		$callback = array( WPForms::class, 'inject_in_content' );

		// 1. Master switch off (dependency currently undefined, matching
		// this bootstrap's default - WPForms is never installed here).
		Options::update( $this->trackable_options( array( 'enabled' => false ) ) );
		Manager::register();
		$this->assertFalse( has_filter( 'the_content', $callback ) );

		// 2. Master on, enable_wpforms off, dependency still undefined.
		Options::update( $this->trackable_options( array( 'enable_wpforms' => false ) ) );
		Manager::register();
		$this->assertFalse( has_filter( 'the_content', $callback ) );

		// 3. Master + toggle on, dependency STILL undefined - not registered.
		Options::update( $this->trackable_options() );
		Manager::register();
		$this->assertFalse( has_filter( 'the_content', $callback ) );

		// 4. Declare the dependency (mirrors WPForms actually being active)
		// - master + toggle already on from step 3, so this alone flips the
		// gate to registered.
		require_once __DIR__ . '/wpforms-class-stub.php';

		Manager::register();
		$this->assertNotFalse( has_filter( 'the_content', $callback ) );
		$this->assertNotFalse( has_filter( 'widget_text_content', $callback ) );
		$this->assertNotFalse( has_filter( 'widget_block_content', $callback ) );
		$this->assertNotFalse( has_filter( 'wpforms_builder_settings_sections', array( WPForms::class, 'add_section' ) ) );
		$this->assertNotFalse( has_filter( 'wpforms_frontend_form_atts', array( WPForms::class, 'inject_via_form_atts' ) ) );
		$this->assertNotFalse( has_filter( 'wpforms_process_redirect_url', array( WPForms::class, 'maybe_append_ajax_off_success_marker' ) ) );
	}
}
