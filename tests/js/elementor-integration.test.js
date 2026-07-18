/**
 * JS test for the 1.1.0 Elementor feature round's initAutoTrack() gate
 * change: the delegated `document` click listener must attach when
 * CFG.track_elementor_buttons is the ONLY true autotrack-family flag (every
 * autotrack_links/autotrack_outbound/autotrack_buttons flag false) - the
 * "master enable is on + Elementor integration is on, but every generic
 * auto-track toggle is off" case, which is exactly why
 * Integrations\Elementor's marker exists at all (see
 * Frontend/Tracker::output_config()'s docblock for track_elementor_buttons).
 *
 * initAutoTrack()'s "attach the listener at all" decision is made ONCE, at
 * load time (see frontend.test.js's own docblock for why its shared harness
 * can't flip that decision after the fact) - so, like
 * wpforms-ajax-off-replay.test.js, this fresh-loads frontend.js into a clean
 * module + window state with the exact CFG this scenario needs seeded
 * BEFORE the require().
 *
 * Deliberately ONE test, alone in its own file (not paired with a "listener
 * never attaches" control here): a `document`-level event listener a
 * previous require() in THIS SAME jsdom document already attached stays
 * live even after jest.resetModules() + a fresh require() - only the module
 * registry resets, not prior DOM listeners - so a second scenario dispatching
 * another click in this same file would also re-invoke the FIRST scenario's
 * stale closure. Jest gives each TEST FILE its own fresh jsdom document
 * (this is why frontend.test.js's single beforeAll()-loaded module is safe
 * across its many tests, and why wpforms-ajax-off-replay.test.js's own
 * multi-scenario file never dispatches a repeatable DOM event after each
 * reload) - so the "no listener attaches" negative is covered instead by
 * this project's other CFG.autotrack_* -all-false coverage already in
 * frontend.test.js, which proves the pre-1.1.0 gate shape.
 */

const path = require( 'path' );

const FRONTEND_JS_PATH = path.join( __dirname, '..', '..', 'assets', 'js', 'frontend.js' );

/**
 * Dispatch a real, bubbling click event on an element.
 *
 * @param {Element} el The element to click.
 */
function clickElement( el ) {
	el.dispatchEvent( new window.Event( 'click', { bubbles: true, cancelable: true } ) );
}

describe( 'initAutoTrack() attaches the click listener for track_elementor_buttons alone (1.1.0)', () => {
	test( 'a marked link still fires when every autotrack_* flag is false and only track_elementor_buttons is true', () => {
		window.__STATS_UMAMI_CFG__ = {
			autotrack_links: false,
			autotrack_outbound: false,
			autotrack_buttons: false,
			track_elementor_buttons: true,
		};
		require( FRONTEND_JS_PATH );

		var trackCalls = [];
		window.umami = {
			track: function ( name, data ) {
				trackCalls.push( [ name, data ] );
			},
		};

		document.body.innerHTML = '<div data-umami-link="1"><a href="/x">Go</a></div>';

		clickElement( document.querySelector( 'a' ) );

		expect( trackCalls ).toEqual( [ [ 'link:Go', { url: 'http://localhost/x' } ] ] );
	} );
} );
