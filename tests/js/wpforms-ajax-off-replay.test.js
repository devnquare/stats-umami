/**
 * JS tests for the WPForms AJAX-off replay's genuine-success signal (the
 * URL marker) and readiness poll for window.umami.
 *
 * consumeWpformsAjaxOffPending() runs exactly ONCE, synchronously, at
 * initAutoTrack() time (frontend.js's own double-init guard,
 * window.__STATS_UMAMI_INIT__, makes a second require() of the file a
 * no-op) - unlike the click/submit-driven behaviour tested in
 * frontend.test.js, there is no re-triggerable DOM event to dispatch per
 * scenario. So each test here fresh-loads frontend.js into a clean module
 * + window state (jest.resetModules() + deleting the init guard) AFTER
 * seeding this test's own sessionStorage/URL/DOM - a faithful simulation
 * of "the browser just navigated to this page," which is exactly when the
 * real consumeWpformsAjaxOffPending() runs in production.
 */

const path = require( 'path' );

const FRONTEND_JS_PATH = path.join( __dirname, '..', '..', 'assets', 'js', 'frontend.js' );
const PENDING_KEY = 'statsUmamiWpformsAjaxOffPending';
const MARKER_ARG = 'stats_umami_wpf_ok';

/**
 * Fresh-load frontend.js so initAutoTrack()/consumeWpformsAjaxOffPending()
 * genuinely re-execute against THIS test's seeded state, rather than
 * whatever module-level state a previous test's require() left behind.
 */
function loadFreshFrontend() {
	jest.resetModules();
	delete window.__STATS_UMAMI_INIT__;
	delete window.statsUmami;
	window.__STATS_UMAMI_CFG__ = {};
	require( FRONTEND_JS_PATH );
}

/**
 * Navigate jsdom's window.location to the given URL without a real page
 * load, via history.replaceState - the same primitive
 * stripAjaxOffSuccessMarker() itself uses, so this faithfully simulates
 * "the browser landed on this URL after a server-side redirect."
 *
 * @param {string} href Absolute or relative URL to navigate to.
 */
function navigateTo( href ) {
	window.history.replaceState( null, '', href );
}

/**
 * Seed the sessionStorage pending record maybeQueueWpformsAjaxOffSuccess()
 * would have written at submit time on the PREVIOUS page.
 *
 * @param {string} formId Numeric string form id.
 * @param {string} name   Event name.
 * @param {Object} [data] Optional event data.
 */
function seedPending( formId, name, data ) {
	window.sessionStorage.setItem( PENDING_KEY, JSON.stringify( { formId: formId, name: name, data: data } ) );
}

describe( 'WPForms AJAX-off replay: genuine-success signal (URL marker) and readiness poll', () => {
	let trackCalls;

	beforeEach( () => {
		document.body.innerHTML = '';
		window.sessionStorage.clear();
		navigateTo( 'http://localhost/thank-you/' );
		trackCalls = [];
		window.umami = {
			track: ( name, data ) => {
				trackCalls.push( [ name, data ] );
			},
		};
	} );

	afterEach( () => {
		delete window.umami;
		jest.useRealTimers();
	} );

	// -----------------------------------------------------------------
	// The URL marker proves success for the Redirect/Page confirmation
	// types, which never render a confirmation container at all.
	// -----------------------------------------------------------------

	test( 'a URL marker matching the pending formId fires the event, with no confirmation container present', () => {
		seedPending( '7', 'contact', { source: 'homepage' } );
		navigateTo( 'http://localhost/thank-you/?' + MARKER_ARG + '=7' );

		loadFreshFrontend();

		expect( trackCalls ).toEqual( [ [ 'contact', { source: 'homepage' } ] ] );
		// Single-fire guarantee: the sessionStorage entry is consumed.
		expect( window.sessionStorage.getItem( PENDING_KEY ) ).toBeNull();
	} );

	test( 'the marker query arg is stripped from the URL after firing, preserving other query args and the hash', () => {
		seedPending( '7', 'contact', undefined );
		navigateTo( 'http://localhost/thank-you/?utm_source=test&' + MARKER_ARG + '=7#section' );

		loadFreshFrontend();

		expect( trackCalls ).toEqual( [ [ 'contact', undefined ] ] );
		expect( window.location.search ).toBe( '?utm_source=test' );
		expect( window.location.hash ).toBe( '#section' );
	} );

	test( 'the confirmation container still fires the event for the Message confirmation type, with no marker present', () => {
		seedPending( '7', 'contact', undefined );
		document.body.innerHTML = '<div id="wpforms-confirmation-7">Thanks!</div>';

		loadFreshFrontend();

		expect( trackCalls ).toEqual( [ [ 'contact', undefined ] ] );
	} );

	test( 'a validation-failure shape - no container, no marker - fires nothing', () => {
		seedPending( '7', 'contact', undefined );
		// Landed on an ordinary page: no confirmation container, no marker.

		loadFreshFrontend();

		expect( trackCalls ).toEqual( [] );
	} );

	test( 'a marker for a DIFFERENT form id than the pending entry fires nothing', () => {
		seedPending( '7', 'contact', undefined );
		navigateTo( 'http://localhost/thank-you/?' + MARKER_ARG + '=99' );

		loadFreshFrontend();

		expect( trackCalls ).toEqual( [] );
	} );

	// -----------------------------------------------------------------
	// A bounded readiness poll for window.umami, mirroring
	// WooCommerce::print_pending_event()'s pattern.
	// -----------------------------------------------------------------

	test( 'window.umami not ready at consume time still fires exactly once, once it becomes ready', () => {
		jest.useFakeTimers();

		seedPending( '7', 'contact', { source: 'homepage' } );
		navigateTo( 'http://localhost/thank-you/?' + MARKER_ARG + '=7' );
		delete window.umami; // Not ready yet - e.g. script_loading=async.

		loadFreshFrontend();

		expect( trackCalls ).toEqual( [] );

		// The tracker becomes ready shortly after.
		window.umami = {
			track: ( name, data ) => {
				trackCalls.push( [ name, data ] );
			},
		};

		jest.advanceTimersByTime( 5000 );

		expect( trackCalls ).toEqual( [ [ 'contact', { source: 'homepage' } ] ] );

		// Still fires only once even if more time passes.
		jest.advanceTimersByTime( 20000 );

		expect( trackCalls ).toEqual( [ [ 'contact', { source: 'homepage' } ] ] );
	} );

	test( 'window.umami never becoming ready gives up silently after the poll budget, firing nothing', () => {
		jest.useFakeTimers();

		seedPending( '7', 'contact', undefined );
		navigateTo( 'http://localhost/thank-you/?' + MARKER_ARG + '=7' );
		delete window.umami;

		loadFreshFrontend();

		jest.advanceTimersByTime( 60000 );

		expect( trackCalls ).toEqual( [] );
	} );
} );
