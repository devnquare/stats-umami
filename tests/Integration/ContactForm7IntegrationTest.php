<?php
/**
 * DB-backed integration tests for the Contact Form 7 integration: the
 * save_meta() nonce/cap-guarded meta round-trip, do_shortcode_tag injection
 * (via the REAL WordPress do_shortcode_tag filter dispatch - CF7 itself
 * isn't installed in this bootstrap, so the shortcode output is a realistic
 * fixture rather than CF7's own rendering), and Integrations\Manager's
 * registration gating, against a real WP core bootstrap + test database.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

use StatsUmami\Integrations\ContactForm7;
use StatsUmami\Integrations\Manager;
use StatsUmami\Settings\Options;
use Yoast\WPTestUtils\WPIntegration\TestCase;

require_once __DIR__ . '/FakeContactForm.php';

/**
 * @covers \StatsUmami\Integrations\ContactForm7
 * @covers \StatsUmami\Integrations\Manager
 */
final class ContactForm7IntegrationTest extends TestCase {

	public function set_up() {
		parent::set_up();

		delete_option( Options::OPTION_KEY );
		wp_set_current_user( 0 );
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- resetting the test's own superglobal fixture between tests, not processing a request.

		// CF7 itself isn't installed in this bootstrap, so nothing maps the
		// `wpcf7_edit_contact_form` meta capability the way CF7's own
		// includes/capabilities.php does (to WPCF7_ADMIN_READ_WRITE_CAPABILITY,
		// default 'publish_pages') - without this, save_meta()'s capability
		// check would reject every user, including
		// administrators, since no role is literally granted a capability
		// named "wpcf7_edit_contact_form". This filter reproduces CF7's real
		// mapping so the tests below exercise the actual pass/fail boundary
		// (administrator has publish_pages; subscriber does not) rather than
		// a bootstrap artifact. WP core's test framework backs up/restores
		// hooks between tests automatically, so no explicit removal is needed.
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap ) {
				return 'wpcf7_edit_contact_form' === $cap ? array( 'publish_pages' ) : $caps;
			},
			10,
			2
		);
	}

	public function tear_down() {
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same as set_up().

		parent::tear_down();
	}

	/**
	 * A fully-configured, trackable + CF7-enabled options array, with the
	 * given overrides layered on top.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function trackable_options( array $overrides = array() ) {
		$options                   = Options::defaults();
		$options['enabled']        = true;
		$options['enable_cf7']     = true;
		$options['schema_version'] = Options::SCHEMA_VERSION;

		return array_merge( $options, $overrides );
	}

	/**
	 * @param string $title Post title.
	 * @return int
	 */
	private function create_form_post( $title ) {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => 'wpcf7_contact_form',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Realistic CF7 shortcode output fixture (the shape CF7's own
	 * wpcf7_contact_form_tag_func() produces): a wrapper div, the wpcf7-form
	 * form element, and a submit input.
	 *
	 * @return string
	 */
	private function cf7_markup() {
		return '<div class="wpcf7" id="wpcf7-f1-p2-o1"><form action="/contact/" method="post" class="wpcf7-form init">'
			. '<p><input type="text" name="your-name" /></p>'
			. '<p><input type="submit" value="Send" class="wpcf7-form-control wpcf7-submit" /></p>'
			. '</form></div>';
	}

	// ---------------------------------------------------------------
	// save_meta(): nonce/cap-guarded meta round-trip.
	// ---------------------------------------------------------------

	public function test_save_meta_stores_event_and_data_when_nonce_and_cap_pass() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$post_id = $this->create_form_post( 'Contact form 1' );

		$_POST['stats_umami_cf7_nonce']      = wp_create_nonce( 'stats_umami_cf7_save' );
		$_POST['stats_umami_cf7_event_name'] = 'signup';
		$_POST['stats_umami_cf7_data_key']   = array( 'Plan' );
		$_POST['stats_umami_cf7_data_value'] = array( 'pro' );

		ContactForm7::save_meta( new FakeContactForm( $post_id, 'Contact form 1' ) );

		$this->assertSame( 'signup', get_post_meta( $post_id, '_stats_umami_cf7_event', true ) );
		$this->assertSame( array( 'plan' => 'pro' ), json_decode( get_post_meta( $post_id, '_stats_umami_cf7_data', true ), true ) );
	}

	// ---------------------------------------------------------------
	// save_meta() must wp_slash() the JSON it
	// hands to update_post_meta(), or update_metadata()'s own wp_unslash()
	// eats any backslash wp_json_encode() wrote for an escaped `"`/`\`,
	// corrupting the stored JSON and silently dropping every data pair.
	// ---------------------------------------------------------------

	/**
	 * Submit one event-data value through the real save_meta() nonce/cap
	 * path and assert it round-trips byte-for-byte through stored meta.
	 * $_POST is set to the wp_slash()'d value, mirroring what a real HTTP
	 * request's $_POST actually contains (WordPress's own boot-time
	 * wp_magic_quotes() slashes every superglobal) - save_meta()'s own
	 * wp_unslash() call undoes exactly that one layer.
	 *
	 * @param string $value Raw (unslashed) event-data value to round-trip.
	 */
	private function assert_cf7_event_data_value_round_trips( $value ) {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$post_id = $this->create_form_post( 'Contact form 1' );

		$_POST['stats_umami_cf7_nonce']      = wp_create_nonce( 'stats_umami_cf7_save' );
		$_POST['stats_umami_cf7_event_name'] = 'signup';
		$_POST['stats_umami_cf7_data_key']   = array( 'note' );
		$_POST['stats_umami_cf7_data_value'] = array( wp_slash( $value ) );

		ContactForm7::save_meta( new FakeContactForm( $post_id, 'Contact form 1' ) );

		$stored = get_post_meta( $post_id, '_stats_umami_cf7_data', true );

		$this->assertSame( array( 'note' => $value ), json_decode( $stored, true ) );
	}

	public function test_save_meta_round_trips_a_value_containing_a_double_quote() {
		$this->assert_cf7_event_data_value_round_trips( 'He said "hi" to me' );
	}

	public function test_save_meta_round_trips_a_value_containing_a_backslash() {
		$this->assert_cf7_event_data_value_round_trips( 'C:\path\to\file' );
	}

	public function test_save_meta_round_trips_a_value_containing_an_emoji() {
		$this->assert_cf7_event_data_value_round_trips( 'Great job 🎉' );
	}

	/**
	 * The event-NAME meta write
	 * had the same bug class as the data-meta write above but was never
	 * fixed - update_post_meta( $post_id, self::META_EVENT, $event_name )
	 * handed update_metadata() a bare value, so its unconditional
	 * wp_unslash() ate a literal backslash at write time. $_POST is set to
	 * the wp_slash()'d form (mirroring WordPress's real magic-quotes
	 * slashing of every superglobal), matching save_meta()'s own
	 * wp_unslash() call on it - the trap flagged in the spec: a test that
	 * sets $_POST to the raw, unslashed value would pass against the broken
	 * code too and prove nothing.
	 */
	public function test_save_meta_round_trips_an_event_name_containing_a_backslash() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$post_id = $this->create_form_post( 'Contact form 1' );

		$event_name = 'cf7 "quoted" \\slash 🎉 event';

		$_POST['stats_umami_cf7_nonce']      = wp_create_nonce( 'stats_umami_cf7_save' );
		$_POST['stats_umami_cf7_event_name'] = wp_slash( $event_name );

		ContactForm7::save_meta( new FakeContactForm( $post_id, 'Contact form 1' ) );

		$this->assertSame( $event_name, get_post_meta( $post_id, '_stats_umami_cf7_event', true ) );
	}

	public function test_save_meta_stores_nothing_when_nonce_is_missing() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$post_id = $this->create_form_post( 'Contact form 1' );

		$_POST['stats_umami_cf7_event_name'] = 'signup';

		ContactForm7::save_meta( new FakeContactForm( $post_id, 'Contact form 1' ) );

		$this->assertSame( '', get_post_meta( $post_id, '_stats_umami_cf7_event', true ) );
	}

	public function test_save_meta_stores_nothing_when_nonce_is_invalid() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$post_id = $this->create_form_post( 'Contact form 1' );

		$_POST['stats_umami_cf7_nonce']      = 'not-a-real-nonce';
		$_POST['stats_umami_cf7_event_name'] = 'signup';

		ContactForm7::save_meta( new FakeContactForm( $post_id, 'Contact form 1' ) );

		$this->assertSame( '', get_post_meta( $post_id, '_stats_umami_cf7_event', true ) );
	}

	public function test_save_meta_stores_nothing_when_current_user_lacks_the_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$post_id = $this->create_form_post( 'Contact form 1' );

		$_POST['stats_umami_cf7_nonce']      = wp_create_nonce( 'stats_umami_cf7_save' );
		$_POST['stats_umami_cf7_event_name'] = 'signup';

		ContactForm7::save_meta( new FakeContactForm( $post_id, 'Contact form 1' ) );

		$this->assertSame( '', get_post_meta( $post_id, '_stats_umami_cf7_event', true ) );
	}

	/**
	 * save_meta()'s capability gate was changed
	 * from `manage_options` to CF7's real meta cap `wpcf7_edit_contact_form`
	 * (mapped, per set_up(), to `publish_pages`) - an AUTHORIZATION change
	 * that shipped with NO test discriminating the two gates: the existing
	 * administrator test passes both, and the existing subscriber test
	 * (above) fails both, so neither tells this change apart from a plain
	 * `manage_options` check. An Editor is the one built-in role that DOES:
	 * it has `publish_pages` but NOT `manage_options`. Must fail if this
	 * change is reverted to `manage_options` (an Editor would then be rejected).
	 */
	public function test_save_meta_stores_event_when_current_user_is_an_editor_lacking_manage_options() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue( user_can( $user_id, 'publish_pages' ) );
		$this->assertFalse( user_can( $user_id, 'manage_options' ) );

		$post_id = $this->create_form_post( 'Contact form 1' );

		$_POST['stats_umami_cf7_nonce']      = wp_create_nonce( 'stats_umami_cf7_save' );
		$_POST['stats_umami_cf7_event_name'] = 'signup';

		ContactForm7::save_meta( new FakeContactForm( $post_id, 'Contact form 1' ) );

		$this->assertSame( 'signup', get_post_meta( $post_id, '_stats_umami_cf7_event', true ) );
	}

	public function test_save_meta_deletes_existing_meta_when_resubmitted_blank() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$post_id = $this->create_form_post( 'Contact form 1' );
		update_post_meta( $post_id, '_stats_umami_cf7_event', 'old-event' );
		update_post_meta( $post_id, '_stats_umami_cf7_data', wp_json_encode( array( 'plan' => 'pro' ) ) );

		$_POST['stats_umami_cf7_nonce']      = wp_create_nonce( 'stats_umami_cf7_save' );
		$_POST['stats_umami_cf7_event_name'] = '';

		ContactForm7::save_meta( new FakeContactForm( $post_id, 'Contact form 1' ) );

		$this->assertSame( '', get_post_meta( $post_id, '_stats_umami_cf7_event', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_stats_umami_cf7_data', true ) );
	}

	// ---------------------------------------------------------------
	// inject_attributes(): called directly (a plain method call) rather
	// than via apply_filters('do_shortcode_tag', ...) - that would require
	// Integrations\Manager to have actually registered the callback first,
	// which needs WPCF7_VERSION defined; since PHP constants can't be
	// undefined again once set, that would make these tests' outcome depend
	// on execution order relative to the gating test below (which defines
	// it). Calling the real callback directly still exercises the same real
	// get_post()/get_posts()/get_post_meta() reads against the DB; the
	// gating test separately proves Manager wires this exact callback to
	// do_shortcode_tag via has_filter().
	// ---------------------------------------------------------------

	public function test_inject_attributes_injects_single_event_and_skip_marker_using_stored_event() {
		$post_id = $this->create_form_post( 'Contact form 1' );
		update_post_meta( $post_id, '_stats_umami_cf7_event', 'signup' );
		update_post_meta( $post_id, '_stats_umami_cf7_data', wp_json_encode( array( 'plan' => 'pro' ) ) );

		$rendered = ContactForm7::inject_attributes( $this->cf7_markup(), 'contact-form-7', array( 'id' => (string) $post_id ) );

		$this->assertSame( 1, substr_count( $rendered, 'data-umami-cf7-event=' ) );
		$this->assertStringContainsString( 'data-umami-cf7-event="signup"', $rendered );
		$this->assertSame( 1, substr_count( $rendered, 'data-umami-cf7-data=' ) );
		$this->assertStringContainsString( 'plan', $rendered );
		$this->assertSame( 1, substr_count( $rendered, 'data-umami-skip=' ) );
		$this->assertStringContainsString( 'data-umami-skip="1"', $rendered );
		// Never Umami's own native click-track attribute - see
		// ContactForm7::inject_into_form()'s docblock.
		$this->assertStringNotContainsString( 'data-umami-event=', $rendered );
	}

	public function test_inject_attributes_falls_back_to_title_slug_when_no_event_is_stored() {
		$post_id = $this->create_form_post( 'Contact Form 1' );

		$rendered = ContactForm7::inject_attributes( $this->cf7_markup(), 'contact-form-7', array( 'id' => (string) $post_id ) );

		$this->assertSame( 1, substr_count( $rendered, 'data-umami-cf7-event=' ) );
		$this->assertStringContainsString( 'data-umami-cf7-event="' . sanitize_title( 'Contact Form 1' ) . '"', $rendered );
	}

	public function test_inject_attributes_resolves_form_by_title_when_id_is_not_numeric() {
		$this->create_form_post( 'Contact form 1' );
		update_post_meta( $this->create_form_post( 'Newsletter signup' ), '_stats_umami_cf7_event', 'newsletter' );

		$rendered = ContactForm7::inject_attributes( $this->cf7_markup(), 'contact-form-7', array( 'title' => 'Newsletter signup' ) );

		$this->assertStringContainsString( 'data-umami-cf7-event="newsletter"', $rendered );
	}

	public function test_inject_attributes_ignores_non_cf7_shortcode_tags() {
		$output   = '<p>[gallery]</p>';
		$rendered = ContactForm7::inject_attributes( $output, 'gallery', array() );

		$this->assertSame( $output, $rendered );
	}

	/**
	 * Regression guard for a real bug found in live E2E: CF7 5.6+
	 * shortcodes carry a HASH STRING in `id` (e.g.
	 * `[contact-form-7 id="f2ab7f2" title="..."]`), NOT the post ID - a
	 * hand-rolled is_numeric() guess never resolves it. When CF7's own
	 * wpcf7_get_contact_form_by_hash() is available, resolve_form_post()
	 * must delegate to it rather than treating the hash as a raw post ID.
	 */
	public function test_inject_attributes_resolves_via_cf7s_hash_lookup_function_when_available() {
		require_once __DIR__ . '/cf7-hash-functions-stub.php';

		$post_id = $this->create_form_post( 'Contact form 1' );
		update_post_meta( $post_id, '_stats_umami_cf7_event', 'signup' );

		$GLOBALS['stats_umami_test_cf7_hash_target'] = $post_id;

		// A non-numeric hash string: (int) 'f2ab7f2' casts to 0, so if
		// resolve_form_post() fell through to the is_numeric()/get_post()
		// fallback instead of delegating to the stubbed hash function above,
		// this would resolve nothing and injection would fail.
		$rendered = ContactForm7::inject_attributes( $this->cf7_markup(), 'contact-form-7', array( 'id' => 'f2ab7f2' ) );

		unset( $GLOBALS['stats_umami_test_cf7_hash_target'] );

		$this->assertStringContainsString( 'data-umami-cf7-event="signup"', $rendered );
	}

	// ---------------------------------------------------------------
	// print_kv_script()'s JS-added rows
	// must use the SAME translated key/value placeholders as the
	// server-rendered rows (esc_attr_e('key'/'value', 'stats-umami') in
	// render_panel()), not hardcoded English literals baked into an
	// innerHTML string. Driven via a real `gettext` filter override
	// (CLAVE/VALOR).
	// ---------------------------------------------------------------

	public function test_print_kv_script_uses_translated_key_value_placeholders() {
		set_current_screen( 'toplevel_page_wpcf7' );

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
		ContactForm7::print_kv_script();
		$script = ob_get_clean();

		$this->assertStringContainsString( 'CLAVE', $script );
		$this->assertStringContainsString( 'VALOR', $script );
		$this->assertStringNotContainsString( 'placeholder="key"', $script );
		$this->assertStringNotContainsString( 'placeholder="value"', $script );
	}

	// ---------------------------------------------------------------
	// Integrations\Manager gating.
	// ---------------------------------------------------------------

	public function test_manager_gates_cf7_registration_on_master_toggle_and_dependency() {
		$callback = array( ContactForm7::class, 'inject_attributes' );

		// 1. Master switch off (dependency currently undefined, matching
		// this bootstrap's default - CF7 is never installed here).
		Options::update( $this->trackable_options( array( 'enabled' => false ) ) );
		Manager::register();
		$this->assertFalse( has_filter( 'do_shortcode_tag', $callback ) );

		// 2. Master on, enable_cf7 off, dependency still undefined.
		Options::update( $this->trackable_options( array( 'enable_cf7' => false ) ) );
		Manager::register();
		$this->assertFalse( has_filter( 'do_shortcode_tag', $callback ) );

		// 3. Master + toggle on, dependency STILL undefined - not registered.
		Options::update( $this->trackable_options() );
		Manager::register();
		$this->assertFalse( has_filter( 'do_shortcode_tag', $callback ) );

		// 4. Define the dependency (mirrors CF7 actually being active) -
		// master + toggle already on from step 3, so this alone flips the
		// gate to registered.
		if ( ! defined( 'WPCF7_VERSION' ) ) {
			define( 'WPCF7_VERSION', '5.9' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulating Contact Form 7's own real constant so Manager's class_exists()-style dependency predicate can be driven to `true` in a bootstrap that never installs CF7.
		}

		Manager::register();
		$this->assertNotFalse( has_filter( 'do_shortcode_tag', $callback ) );
		$this->assertNotFalse( has_action( 'wpcf7_after_save', array( ContactForm7::class, 'save_meta' ) ) );
		$this->assertNotFalse( has_filter( 'wpcf7_editor_panels', array( ContactForm7::class, 'add_panel' ) ) );
	}
}
