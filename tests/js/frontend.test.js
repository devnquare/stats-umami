/**
 * JS unit tests for assets/js/frontend.js: the plugin's
 * worst defect surface had zero automated coverage for a long time - every
 * defect in it was found by a
 * human or an external audit, never by a test. This is the harness that
 * closes that gap, covering:
 * elementLabel(), the form-id/name resolution, isTrackableWpformsForm(),
 * shouldSkip(), and resolveClickTarget()'s one-action-one-event precedence.
 *
 * frontend.js is hand-written with NO build step and NO module.exports (a
 * deliberate zero-runtime-dependency design - see its own docblock): it is a
 * self-invoking script that binds real DOM listeners on load. So these tests
 * do not import internal functions directly; they `require()` the real file
 * ONCE into a jsdom document (see the beforeAll/setCfg() note below), then
 * drive it exactly as a browser would (real elements, real dispatched
 * events) and observe its one public side-channel, `window.umami.track()`
 * calls - the same black-box shape a live-browser reproduction uses, just
 * automated.
 *
 * jsdom gotcha: the HTML spec's "override built-ins" behaviour
 * (a `<form>` control named `id`/`name` shadows the FORM ELEMENT'S OWN
 * `.id`/`.name` IDL property with itself) is real, live-verified Chrome
 * behaviour, but
 * jsdom 20 does NOT implement it - `form.id`/`form.name` always read the
 * reflected attribute in jsdom, bug or no bug, which would make a test that
 * merely adds `<input name="id">` to a real jsdom form pass identically
 * whether frontend.js reads `form.id` or `form.getAttribute('id')` - exactly
 * the kind of vacuous test this project has shipped three times before.
 * shadowIdOrName() below closes that gap: it manually defines an own
 * `id`/`name` property on the form instance pointing at the shadowing
 * control, which is precisely the observable effect the real browser bug
 * produces (a plain assignment like `form.id = 'x'` would instead invoke
 * Element's reflected id SETTER and rewrite the attribute - the wrong
 * simulation - so this uses Object.defineProperty to shadow the accessor on
 * the instance without touching the attribute, matching the real bug
 * exactly).
 */

const path = require( 'path' );

const FRONTEND_JS_PATH = path.join( __dirname, '..', '..', 'assets', 'js', 'frontend.js' );

// frontend.js binds its listeners on `document` exactly once per real page
// load (guarded by window.__STATS_UMAMI_INIT__ - see its own docblock).
// jsdom, unlike a browser navigation, keeps ONE `document` alive for every
// test in this file, so re-`require()`-ing the file per test (bypassing the
// guard to pick up a fresh config) would keep adding duplicate `document`
// click/submit listeners, and a single simulated click would fire several
// times over by the later tests. Instead: require it exactly ONCE in
// beforeAll with a MUTABLE config object, and mutate that same object's
// properties per test via setCfg() - frontend.js holds a reference to it
// (`var CFG = window.__STATS_UMAMI_CFG__ || {}`), so mutating its properties
// is visible to the already-installed listeners without reloading the file.
let cfg;

beforeAll( () => {
	// initAutoTrack() ALSO makes a one-time decision at load - whether to
	// install the delegated `document` click listener at all - gated on
	// `CFG.autotrack_links || CFG.autotrack_outbound || CFG.autotrack_buttons`
	// being true AT THAT MOMENT (see its own source). Unlike every other CFG
	// read (re-checked per click/submit), THIS one decision is snapshotted
	// once and can never be revisited later - so cfg must already have at
	// least one of these true here, or no test in this file could ever
	// observe a button/link click at all, regardless of what setCfg() sets
	// afterwards. setCfg() is free to flip them individually per test from
	// here on; only the initial "install the listener at all" gate needed
	// this.
	cfg = { autotrack_buttons: true, autotrack_links: true, autotrack_outbound: true };
	window.__STATS_UMAMI_CFG__ = cfg;
	require( FRONTEND_JS_PATH );
} );

/**
 * Replace the live CFG object's contents for the next test (frontend.js
 * itself never re-reads window.__STATS_UMAMI_CFG__ after load - see above).
 *
 * @param {Object} overrides New config for the object CFG already references.
 */
function setCfg( overrides ) {
	Object.keys( cfg ).forEach( ( key ) => delete cfg[ key ] );
	Object.assign( cfg, overrides || {} );
}

/**
 * Simulate a form control (e.g. `<input name="id">`) shadowing the FORM
 * element's own `.id`/`.name` IDL property - real, spec-mandated, live
 * browser behaviour that jsdom does not implement (see file docblock).
 *
 * @param {HTMLFormElement} form     The form element.
 * @param {string}          propName 'id' or 'name'.
 * @param {Element}         control  The shadowing control (returned as the
 *                                   property's value, matching the real bug).
 */
function shadowIdOrName( form, propName, control ) {
	Object.defineProperty( form, propName, { value: control, configurable: true } );
}

/**
 * Dispatch a real, bubbling, capture-observable submit event on a form.
 *
 * @param {HTMLFormElement} form The form to submit.
 */
function submitForm( form ) {
	form.dispatchEvent( new window.Event( 'submit', { bubbles: true, cancelable: true } ) );
}

/**
 * Dispatch a real, bubbling click event on an element.
 *
 * @param {Element} el The element to click.
 */
function clickElement( el ) {
	el.dispatchEvent( new window.Event( 'click', { bubbles: true, cancelable: true } ) );
}

describe( 'frontend.js', () => {
	let trackCalls;

	beforeEach( () => {
		document.body.innerHTML = '';
		window.sessionStorage.clear();
		trackCalls = [];
		window.umami = {
			track: ( name, data ) => {
				trackCalls.push( [ name, data ] );
			},
		};
	} );

	afterEach( () => {
		delete window.umami;
		delete window.jQuery;
		// window.statsUmami is deliberately NOT deleted here: frontend.js
		// assigns it exactly once, in the ONE require() call in beforeAll
		// (see this file's docblock) - there is nothing that would recreate
		// it for a later test if it were removed. The clamp test below is the
		// first in this file to call the public window.statsUmami.track()
		// API directly rather than driving it through a DOM event.
	} );

	// -----------------------------------------------------------------
	// form.id / form.name property reads are shadowable by a control
	// named "id"/"name". The fix reads form.getAttribute('id'/'name')
	// instead, which cannot be shadowed.
	// -----------------------------------------------------------------

	describe( 'form id/name resolution is not shadowed by a same-named control', () => {
		test( 'comment tracking still fires when the comment form has an input named "id"', () => {
			setCfg( { track_comments: true, autotrack_forms: false } );

			document.body.innerHTML =
				'<form id="commentform"><input type="text" name="id" /><button type="submit">Post</button></form>';

			const form = document.getElementById( 'commentform' );
			shadowIdOrName( form, 'id', form.querySelector( 'input[name="id"]' ) );

			submitForm( form );

			expect( trackCalls ).toEqual( [ [ 'comment_submit', undefined ] ] );
		} );

		test( 'a WPForms form is still recognized as trackable when it has an input named "id"', () => {
			setCfg( {} );

			document.body.innerHTML =
				'<form id="wpforms-form-77" data-umami-wpforms-event="signup"><input type="text" name="id" /></form>';

			const form = document.getElementById( 'wpforms-form-77' );
			shadowIdOrName( form, 'id', form.querySelector( 'input[name="id"]' ) );

			submitForm( form );

			// The AJAX-off queue (no window.jQuery, form lacks
			// wpforms-ajax-form) is the observable side effect of
			// isTrackableWpformsForm() resolving true - it can only be
			// written when the form-id regex genuinely matched.
			expect( window.sessionStorage.getItem( 'statsUmamiWpformsAjaxOffPending' ) ).not.toBeNull();

			const pending = JSON.parse( window.sessionStorage.getItem( 'statsUmamiWpformsAjaxOffPending' ) );
			expect( pending.formId ).toBe( '77' );
			expect( pending.name ).toBe( 'signup' );
		} );

		test( 'the generic form-submit fallback resolves the real form name when a control is named "name"', () => {
			setCfg( { autotrack_forms: true } );

			document.body.innerHTML =
				'<form name="my-form"><input type="text" name="name" /><button type="submit">Go</button></form>';

			const form = document.querySelector( 'form' );
			shadowIdOrName( form, 'name', form.querySelector( 'input[name="name"]' ) );

			submitForm( form );

			expect( trackCalls ).toEqual( [ [ 'form:my-form', undefined ] ] );
		} );
	} );

	// -----------------------------------------------------------------
	// The comment form must be
	// governed SOLELY by track_comments. Pre-fix, with track_comments off
	// and autotrack_forms on, a commentform submission fell through to the
	// generic branch and fired 'form:commentform' - so the dedicated toggle
	// didn't actually stop comment tracking, it just renamed the event.
	// -----------------------------------------------------------------

	describe( 'the comment form is governed solely by track_comments (F-3)', () => {
		test( 'track_comments off + autotrack_forms on fires nothing for the comment form', () => {
			setCfg( { track_comments: false, autotrack_forms: true } );

			document.body.innerHTML = '<form id="commentform"><button type="submit">Post</button></form>';

			submitForm( document.getElementById( 'commentform' ) );

			expect( trackCalls ).toEqual( [] );
		} );

		test( 'track_comments on fires exactly one comment_submit, regardless of autotrack_forms', () => {
			setCfg( { track_comments: true, autotrack_forms: true } );

			document.body.innerHTML = '<form id="commentform"><button type="submit">Post</button></form>';

			submitForm( document.getElementById( 'commentform' ) );

			expect( trackCalls ).toEqual( [ [ 'comment_submit', undefined ] ] );
		} );

		test( 'a non-comment form still fires the generic form event when autotrack_forms is on', () => {
			setCfg( { track_comments: false, autotrack_forms: true } );

			document.body.innerHTML = '<form id="my-form"><button type="submit">Go</button></form>';

			submitForm( document.getElementById( 'my-form' ) );

			expect( trackCalls ).toEqual( [ [ 'form:my-form', undefined ] ] );
		} );
	} );

	// -----------------------------------------------------------------
	// elementLabel() only trimmed the ends, leaking internal
	// whitespace (newlines/tabs from source formatting) into event names.
	// -----------------------------------------------------------------

	describe( 'elementLabel() collapses internal whitespace', () => {
		test( 'a button whose label is split across source lines yields a single-spaced event name', () => {
			setCfg( { autotrack_buttons: true } );

			document.body.innerHTML =
				'<button type="button">Add\n\t\tto\n\t\tcart</button>';

			clickElement( document.querySelector( 'button' ) );

			expect( trackCalls ).toEqual( [ [ 'button:Add to cart', undefined ] ] );
		} );

		test( 'an aria-label with internal whitespace is also collapsed', () => {
			setCfg( { autotrack_buttons: true } );

			document.body.innerHTML =
				'<button type="button" aria-label="Open\n\tmenu"></button>';

			clickElement( document.querySelector( 'button' ) );

			expect( trackCalls ).toEqual( [ [ 'button:Open menu', undefined ] ] );
		} );
	} );

	// -----------------------------------------------------------------
	// shouldSkip(): the explicit opt-outs for this file's generic
	// auto-track (data-umami-skip, and Umami's own data-umami-event).
	// -----------------------------------------------------------------

	describe( 'shouldSkip()', () => {
		test( 'a button carrying data-umami-skip="1" never fires the generic click track', () => {
			setCfg( { autotrack_buttons: true } );

			document.body.innerHTML = '<button type="button" data-umami-skip="1">Skip me</button>';

			clickElement( document.querySelector( 'button' ) );

			expect( trackCalls ).toEqual( [] );
		} );

		test( 'a button carrying Umami\'s own data-umami-event never double-fires this file\'s generic click track', () => {
			setCfg( { autotrack_buttons: true } );

			document.body.innerHTML = '<button type="button" data-umami-event="signup">Sign up</button>';

			clickElement( document.querySelector( 'button' ) );

			expect( trackCalls ).toEqual( [] );
		} );
	} );

	// -----------------------------------------------------------------
	// resolveClickTarget(): one user action fires at most one event - a
	// button nested inside a link fires only the (innermost) button's
	// event, never the link's too.
	// -----------------------------------------------------------------

	describe( 'resolveClickTarget() one-action-one-event precedence', () => {
		test( 'clicking a button nested inside an anchor fires only the button event', () => {
			setCfg( { autotrack_buttons: true, autotrack_links: true } );

			document.body.innerHTML = '<a href="/somewhere"><button type="button">Go</button></a>';

			clickElement( document.querySelector( 'button' ) );

			expect( trackCalls ).toEqual( [ [ 'button:Go', undefined ] ] );
		} );
	} );

	// -----------------------------------------------------------------
	// The mini-cart drawer's "Go to checkout" link is now suppressed
	// (a deliberate scope call, closing the accepted asymmetry with the cart
	// page's own Proceed to Checkout anchor), while an ordinary link in the
	// same drawer must keep firing.
	// -----------------------------------------------------------------

	describe( 'WooCommerce control suppression', () => {
		test( 'the mini-cart "Go to checkout" link fires nothing, but an ordinary drawer link still fires', () => {
			setCfg( { autotrack_links: true, woo_present: true } );

			document.body.innerHTML =
				'<div class="wc-block-mini-cart__drawer">' +
				'<a class="wc-block-mini-cart__footer-checkout" href="/checkout/">Go to checkout</a>' +
				'<a href="/products/widget/">Widget</a>' +
				'</div>';

			clickElement( document.querySelector( '.wc-block-mini-cart__footer-checkout' ) );
			clickElement( document.querySelector( 'a[href="/products/widget/"]' ) );

			expect( trackCalls ).toEqual( [ [ 'link:Widget', { url: 'http://localhost/products/widget/' } ] ] );
		} );
	} );

	// -----------------------------------------------------------------
	// Verified against
	// the real Umami 3.2 stack: track()'s clamp must bound the result to
	// 50 UTF-16 CODE UNITS, not 50 code points. A prior code-point clamp
	// (Array.from(name).slice(0,50)) never split a surrogate pair itself,
	// but Umami's OWN truncateString() (src/lib/format.ts:126) re-clamps
	// every event name via a plain `value.substring(0, 50)` - a code-UNIT
	// cut - so a name whose code-point-clamped length still exceeded 50
	// code units got RE-SPLIT by Umami itself, reproducing the exact
	// U+FFFD corruption this fix exists to prevent. Clamping to code UNITS
	// here instead makes Umami's own substring(0,50) a no-op: a character
	// that would not fit is dropped WHOLE, never split.
	// -----------------------------------------------------------------

	describe( 'track() clamps to 50 UTF-16 code units without ever splitting a surrogate pair', () => {
		test( 'an astral character that would not fit within 50 code units is dropped whole, not split', () => {
			const emoji = '😀'; // U+1F600 "😀" - a surrogate pair (2 UTF-16 code units, 1 code point).
			const name = 'z'.repeat( 49 ) + emoji; // 49 code units + 2 = 51 code units total.

			window.statsUmami.track( name );

			expect( trackCalls.length ).toBe( 1 );

			const sentName = trackCalls[ 0 ][ 0 ];

			// The un-fittable emoji is dropped whole - the result is exactly
			// the 49 ASCII characters, never a lone surrogate (U+FFFD on
			// Umami's side) and never the intact emoji pushing the result to
			// 51 code units (which Umami would then re-split itself).
			expect( sentName ).toBe( 'z'.repeat( 49 ) );
			expect( sentName.length ).toBe( 49 );
			expect( /[\uD800-\uDFFF]/.test( sentName ) ).toBe( false );
		} );

		test( 'an astral character that fits exactly within 50 code units is kept intact', () => {
			const emoji = '😀';
			const name = 'z'.repeat( 48 ) + emoji; // 48 + 2 = 50 code units total - fits exactly.

			window.statsUmami.track( name );

			const sentName = trackCalls[ 0 ][ 0 ];

			expect( sentName ).toBe( name );
			expect( sentName.length ).toBe( 50 );
		} );

		test( '25 astral characters (50 code units) are kept fully intact', () => {
			const name = '😀'.repeat( 25 ); // 25 * 2 = 50 code units, 25 code points.

			window.statsUmami.track( name );

			const sentName = trackCalls[ 0 ][ 0 ];

			expect( sentName ).toBe( name );
			expect( sentName.length ).toBe( 50 );
			expect( Array.from( sentName ).length ).toBe( 25 );
		} );

		test( '26 astral characters (52 code units) are clamped to the first 25, the 26th dropped whole', () => {
			const name = '😀'.repeat( 26 );

			window.statsUmami.track( name );

			const sentName = trackCalls[ 0 ][ 0 ];

			expect( sentName ).toBe( '😀'.repeat( 25 ) );
			expect( sentName.length ).toBe( 50 );
			expect( Array.from( sentName ).length ).toBe( 25 );
		} );
	} );

	// -----------------------------------------------------------------
	// elementLabel() falls back to a descendant <img alt> when the control
	// has no text/aria-label/title of its own - generic (any WordPress
	// content, not page-builder-specific), so a linked/buttoned image is
	// attributable instead of the anonymous link_click/button_click.
	// -----------------------------------------------------------------

	describe( 'elementLabel() falls back to a descendant image alt', () => {
		test( 'a linked image with no anchor text fires link:<alt> instead of link_click', () => {
			setCfg( { autotrack_links: true } );

			document.body.innerHTML = '<a href="/x"><img alt="Product photo"></a>';

			clickElement( document.querySelector( 'a' ) );

			expect( trackCalls ).toEqual( [ [ 'link:Product photo', { url: 'http://localhost/x' } ] ] );
		} );

		test( 'a linked decorative image (empty alt) still fires the anonymous link_click', () => {
			setCfg( { autotrack_links: true } );

			document.body.innerHTML = '<a href="/x"><img alt=""></a>';

			clickElement( document.querySelector( 'a' ) );

			expect( trackCalls ).toEqual( [ [ 'link_click', { url: 'http://localhost/x' } ] ] );
		} );

		test( 'the image-alt fallback never overrides an anchor that already has its own text', () => {
			setCfg( { autotrack_links: true } );

			document.body.innerHTML = '<a href="/x"><img alt="Product photo"> Buy now</a>';

			clickElement( document.querySelector( 'a' ) );

			expect( trackCalls ).toEqual( [ [ 'link:Buy now', { url: 'http://localhost/x' } ] ] );
		} );

		test( 'a buttoned image with no text also fires button:<alt>', () => {
			setCfg( { autotrack_buttons: true } );

			document.body.innerHTML = '<button type="button"><img alt="Close"></button>';

			clickElement( document.querySelector( 'button' ) );

			expect( trackCalls ).toEqual( [ [ 'button:Close', undefined ] ] );
		} );
	} );

	// -----------------------------------------------------------------
	// 1.1.0 (Elementor feature round): isForcedLink() bypasses the
	// autotrack_links off-gate ONLY for a link marked by
	// Integrations\Elementor's generic `data-umami-link` attribute - every
	// other rule (label, URL, outbound precedence, shouldSkip()) stays
	// exactly the same as for any other link. The marker itself is asserted
	// via a plain `<div data-umami-link>` wrapper here (frontend.js has no
	// idea it came from Elementor - see isForcedLink()'s own docblock).
	// -----------------------------------------------------------------

	describe( 'isForcedLink() bypasses the autotrack_links off-gate for a marked link only (1.1.0)', () => {
		test( 'a marked link fires link:<label> even with autotrack_links off', () => {
			setCfg( { autotrack_links: false } );

			document.body.innerHTML = '<div data-umami-link="1"><a href="/x">Go</a></div>';

			clickElement( document.querySelector( 'a' ) );

			expect( trackCalls ).toEqual( [ [ 'link:Go', { url: 'http://localhost/x' } ] ] );
		} );

		test( 'an unmarked link with autotrack_links off fires nothing (no regression to default-off behavior)', () => {
			setCfg( { autotrack_links: false } );

			document.body.innerHTML = '<a href="/x">Go</a>';

			clickElement( document.querySelector( 'a' ) );

			expect( trackCalls ).toEqual( [] );
		} );

		test( 'a marked link with autotrack_links ALSO on fires exactly one link: event (no double-fire)', () => {
			setCfg( { autotrack_links: true } );

			document.body.innerHTML = '<div data-umami-link="1"><a href="/x">Go</a></div>';

			clickElement( document.querySelector( 'a' ) );

			expect( trackCalls ).toEqual( [ [ 'link:Go', { url: 'http://localhost/x' } ] ] );
		} );

		test( 'Gutenberg-safety: an element inside a data-umami-link marker that ALSO carries data-umami-event is never touched by our track() (shouldSkip returns first)', () => {
			setCfg( { autotrack_links: true } );

			document.body.innerHTML = '<div data-umami-link="1"><a href="/x" data-umami-event="native">Go</a></div>';

			clickElement( document.querySelector( 'a' ) );

			expect( trackCalls ).toEqual( [] );
		} );

		test( 'a marked outbound link still fires outbound-link-click once, not link: (outbound precedence unchanged)', () => {
			setCfg( { autotrack_links: false, autotrack_outbound: true } );

			document.body.innerHTML = '<div data-umami-link="1"><a href="https://external.example/x">Go</a></div>';

			clickElement( document.querySelector( 'a' ) );

			expect( trackCalls ).toEqual( [ [ 'outbound-link-click', { url: 'https://external.example/x' } ] ] );
		} );
	} );

	// -----------------------------------------------------------------
	// isTrackableWpformsForm(): the positive/negative boundary, independent
	// of the shadowing case covered above.
	// -----------------------------------------------------------------

	describe( 'isTrackableWpformsForm()', () => {
		test( 'a form missing the data-umami-wpforms-event attribute is not queued', () => {
			setCfg( {} );

			document.body.innerHTML = '<form id="wpforms-form-5"></form>';

			submitForm( document.getElementById( 'wpforms-form-5' ) );

			expect( window.sessionStorage.getItem( 'statsUmamiWpformsAjaxOffPending' ) ).toBeNull();
		} );

		test( 'a form whose id does not match the wpforms-form-N shape is not queued', () => {
			setCfg( {} );

			document.body.innerHTML = '<form id="some-other-form" data-umami-wpforms-event="signup"></form>';

			submitForm( document.getElementById( 'some-other-form' ) );

			expect( window.sessionStorage.getItem( 'statsUmamiWpformsAjaxOffPending' ) ).toBeNull();
		} );
	} );
} );
