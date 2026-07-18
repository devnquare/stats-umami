<?php
/**
 * Unit tests for StatsUmami\Frontend\ScriptUrl.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Unit;

use Brain\Monkey\Functions;
use StatsUmami\Frontend\ScriptUrl;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \StatsUmami\Frontend\ScriptUrl
 */
final class ScriptUrlTest extends TestCase {

	protected function set_up() {
		parent::set_up();

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	public function test_plain_host_gets_script_js_appended() {
		$this->assertSame( 'https://analytics.example.com/script.js', ScriptUrl::derive( 'https://analytics.example.com' ) );
	}

	public function test_trailing_slash_host_gets_script_js_appended_without_double_slash() {
		$this->assertSame( 'https://analytics.example.com/script.js', ScriptUrl::derive( 'https://analytics.example.com/' ) );
	}

	public function test_custom_path_host_is_used_as_is() {
		$this->assertSame(
			'https://analytics.example.com/custom-name.js',
			ScriptUrl::derive( 'https://analytics.example.com/custom-name.js' )
		);
	}

	public function test_custom_nested_path_host_is_used_as_is() {
		$this->assertSame(
			'https://analytics.example.com/stats/tracker.js',
			ScriptUrl::derive( 'https://analytics.example.com/stats/tracker.js' )
		);
	}

	public function test_empty_host_returns_bare_script_js() {
		$this->assertSame( '/script.js', ScriptUrl::derive( '' ) );
	}

	public function test_host_with_port_and_no_path_gets_script_js_appended() {
		$this->assertSame( 'http://localhost:3000/script.js', ScriptUrl::derive( 'http://localhost:3000' ) );
	}

	// ---------------------------------------------------------------
	// Pin the documented extensionless/path-bearing
	// TRACKER_SCRIPT_NAME shapes so nobody "corrects" derive() to only
	// trust a ".js"-suffixed path later - see docs/DECISIONS.md 2026-07-09
	// "ScriptUrl::derive() is CORRECT" and the class docblock above.
	// ---------------------------------------------------------------

	/**
	 * Umami's docs state the tracker script name "can also be any path you
	 * choose" without a .js extension (e.g. a renamed, ad-blocker-resistant
	 * script). A real production Umami instance serves its tracker at
	 * exactly this shape (host + "/app", no extension) - rewriting it would
	 * silently break tracking on that live install.
	 */
	public function test_extensionless_renamed_script_path_is_used_verbatim() {
		$this->assertSame( 'https://u.example.com/app', ScriptUrl::derive( 'https://u.example.com/app' ) );
	}

	/**
	 * Umami's docs give "/path/to/tracker" as its own literal example of a
	 * supported renamed-script path - a nested, extensionless path must be
	 * just as verbatim as a single-segment one.
	 */
	public function test_extensionless_nested_renamed_script_path_is_used_verbatim() {
		$this->assertSame(
			'https://u.example.com/path/to/tracker',
			ScriptUrl::derive( 'https://u.example.com/path/to/tracker' )
		);
	}

	/**
	 * A path that DOES end in .js is, and has always been, one valid shape
	 * of the same verbatim rule - not the ONLY shape that qualifies for it
	 * (see the two extensionless tests above). Pinned here explicitly so a
	 * future change can't special-case ".js" without this test noticing.
	 */
	public function test_explicit_js_suffixed_path_is_used_verbatim() {
		$this->assertSame( 'https://u.example.com/x.js', ScriptUrl::derive( 'https://u.example.com/x.js' ) );
	}

	/**
	 * The one genuinely broken input the verbatim rule can't handle on its
	 * own: a bare BASE_PATH subdirectory root. Nothing valid ends in "/", so
	 * treating a trailing slash as "this must be a directory" and appending
	 * the canonical script name can only ever fix a broken config, never
	 * break a working one.
	 */
	public function test_path_with_trailing_slash_gets_script_js_appended() {
		$this->assertSame(
			'https://example.com/analytics/script.js',
			ScriptUrl::derive( 'https://example.com/analytics/' )
		);
	}

	// ---------------------------------------------------------------
	// Branches 1 and 3 must cut off a trailing
	// query string/fragment before appending "script.js" - appending
	// straight onto the raw host URL splices the suffix into the middle of
	// it (e.g. "...?x=1script.js"), a malformed URL. Branch 2 (an explicit
	// verbatim path) is NOT stripped - a renamed script may legitimately
	// carry its own query string (e.g. cache-busting "?v=2").
	// ---------------------------------------------------------------

	/**
	 * Branch 3 (trailing-slash path) with a query string: the query must be
	 * cut off before "script.js" is appended, not spliced into the middle
	 * of the URL.
	 */
	public function test_path_with_trailing_slash_and_query_string_drops_the_query() {
		$this->assertSame(
			'https://example.com/analytics/script.js',
			ScriptUrl::derive( 'https://example.com/analytics/?x=1' )
		);
	}

	/**
	 * Branch 3 (trailing-slash path) with a fragment: same rule, for "#"
	 * instead of "?".
	 */
	public function test_path_with_trailing_slash_and_fragment_drops_the_fragment() {
		$this->assertSame(
			'https://example.com/analytics/script.js',
			ScriptUrl::derive( 'https://example.com/analytics/#frag' )
		);
	}

	/**
	 * Branch 1 (empty path) with a query string - the pre-existing bug
	 * class: appending "/script.js" straight onto "https://example.com?x=1"
	 * produced "https://example.com?x=1/script.js". The query must be cut
	 * off first.
	 */
	public function test_empty_path_with_query_string_drops_the_query() {
		$this->assertSame(
			'https://example.com/script.js',
			ScriptUrl::derive( 'https://example.com?x=1' )
		);
	}

	/**
	 * Branch 2 (verbatim renamed-script path) with a query string: unlike
	 * branches 1 and 3, this one must preserve the query string exactly -
	 * a renamed tracker script may legitimately be served with one (e.g. a
	 * cache-busting "?v=2"), and this branch already trusts the path as
	 * given.
	 */
	public function test_verbatim_path_preserves_a_query_string() {
		$this->assertSame(
			'https://u.example.com/app?v=2',
			ScriptUrl::derive( 'https://u.example.com/app?v=2' )
		);
	}
}
