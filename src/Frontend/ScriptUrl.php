<?php
/**
 * Pure derivation of the Umami tracker <script src> from the configured
 * host URL.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mirrors the old plugin's derive_script_url() (see
 * docs/research/OLD-PLUGIN-INVENTORY.md §5.4): when the configured host URL
 * has no path (or just "/"), append "/script.js"; when it already carries a
 * non-empty path, use it verbatim - UNLESS that path ends in a trailing
 * slash, in which case "script.js" is appended to it (see the two branches
 * below for why each rule is correct, not merely convenient).
 *
 * Branch 1 - empty path -> append "/script.js". The common case: a bare
 * host URL like "https://analytics.example.com".
 *
 * Branch 2 - non-empty path, no trailing slash -> used VERBATIM. This is
 * NOT a bug, and must never be "corrected" to only trust a path ending in
 * ".js" (a remediation an earlier audit proposed and the PM REJECTED after
 * verifying it against Umami's own docs and a live install - see
 * docs/DECISIONS.md 2026-07-09 "ScriptUrl::derive() is CORRECT"). Umami's
 * `TRACKER_SCRIPT_NAME` setting documents that "the .js extension is not
 * required. The value can also be any path you choose, for example
 * /path/to/tracker" - i.e. an extensionless, path-bearing tracker URL (e.g.
 * ".../app", ".../path/to/tracker") is a first-class, DOCUMENTED shape, not
 * a mistake to guard against. Rewriting it would silently break tracking
 * for exactly the ad-blocker-bypass-conscious users who followed Umami's
 * own renaming guidance (confirmed against a real production install using
 * this shape).
 *
 * Branch 3 - non-empty path WITH a trailing slash -> "script.js" is
 * appended to it. This is the one genuinely broken input the verbatim rule
 * above cannot handle: a bare `BASE_PATH` subdirectory root (e.g.
 * "https://example.com/analytics/") is irreducibly ambiguous with an
 * extensionless TRACKER_SCRIPT_NAME and cannot be told apart from the URL
 * alone (this plugin makes zero server-to-server calls, by decision, so it
 * cannot probe to find out). But nothing valid ever ends in "/" - a path
 * component, renamed or not, is never itself a trailing slash - so treating
 * a trailing slash as "this must be a directory" and appending the
 * canonical script name can only ever turn a broken config into a working
 * one, never break a working one.
 *
 * Branches 1 and 3 build off a QUERY/FRAGMENT-STRIPPED base, not the raw
 * host URL - appending "script.js"/"/script.js" straight onto a string that
 * still carries a trailing "?x=1" or "#frag" splices the suffix into the
 * middle of it (e.g. "...?x=1script.js"), which is a malformed URL, not a
 * script URL with a query string. Branch 2 (the verbatim path) is NOT
 * stripped - a renamed script may legitimately carry its own query string
 * (e.g. a cache-busting "?v=2"), and since that branch already trusts the
 * path as given, the query is part of what "given" means.
 */
class ScriptUrl {

	/**
	 * Derive the full tracker script URL from the configured host URL.
	 *
	 * @param string $host_url The configured host URL.
	 * @return string The full script URL.
	 */
	public static function derive( $host_url ) {
		$host_url = (string) $host_url;
		$parsed   = wp_parse_url( $host_url );
		$raw_path = isset( $parsed['path'] ) ? $parsed['path'] : '';
		$path     = trim( $raw_path, '/' );

		if ( '' === $path ) {
			$base = substr( $host_url, 0, strcspn( $host_url, '?#' ) );

			return rtrim( $base, '/' ) . '/script.js';
		}

		if ( '/' === substr( $raw_path, -1 ) ) {
			$base = substr( $host_url, 0, strcspn( $host_url, '?#' ) );

			return $base . 'script.js';
		}

		return $host_url;
	}
}
