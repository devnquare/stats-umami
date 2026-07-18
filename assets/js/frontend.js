/**
 * Stats Umami front-end behaviour: the public developer API
 * (window.statsUmami.track), the CF7/WPForms genuine-success listeners, and
 * the config-gated auto-track behaviour. Hand-written, no build step, no
 * REQUIRED dependencies - WPForms' real success signal
 * (`wpformsAjaxSubmitSuccess`) is a jQuery custom event triggered on the
 * submitted `<form>` itself, not a native DOM event, so this file binds it
 * via `window.jQuery` WHEN PRESENT (see initAutoTrack()/onWpformsAjaxSuccess()'s
 * docblock) - WPForms always enqueues jQuery on any page it renders a form,
 * so this is not a NEW dependency for a page with a WPForms form, and every
 * other page (no WPForms, no jQuery) still gets a fully dependency-free
 * frontend.js via the MutationObserver fallback. Reads
 * window.__STATS_UMAMI_CFG__ (emitted server-side at wp_head by
 * Frontend/Tracker::output_config()) for which behaviours are enabled and
 * the site's own host (for outbound-link detection).
 *
 * Governing invariant for the auto-track behaviour: one user action fires
 * at most one event, with an explicit precedence and clean element
 * resolution - CF7/WPForms success (renamed, non-Umami-recognized
 * attributes) > a submit control belonging to a form (defers to the form's
 * own submit) > form submit > button > outbound link > link. Gutenberg's
 * core/button integration is NOT handled here - it uses Umami's OWN native
 * `data-umami-event` click-track attribute (see Integrations/Gutenberg.php),
 * which Umami's bundled tracker fires itself; `shouldSkip()` below is what
 * keeps this file's own auto-track from ALSO firing for it.
 *
 * @package StatsUmami
 */

( function () {
	'use strict';

	// A second execution of this file (e.g. an optimizer's
	// inline/concat pass, or a theme/plugin re-enqueueing it) must not
	// double-bind every listener below. Bail before anything else runs.
	if ( window.__STATS_UMAMI_INIT__ ) {
		return;
	}

	window.__STATS_UMAMI_INIT__ = true;

	var CFG = window.__STATS_UMAMI_CFG__ || {};

	var BUTTON_SELECTOR = 'button, input[type="button"], input[type="submit"], input[type="reset"]';

	// How long the no-jQuery MutationObserver fallback keeps watching
	// for a WPForms confirmation container before giving up on a submission
	// that never reached genuine success (validation failure, AJAX error,
	// abandoned page) - see maybeTrackWpformsSuccessFallback().
	var WPFORMS_SUCCESS_TIMEOUT_MS = 15000;

	function onReady( fn ) {
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	/**
	 * Clamp a string to at most `maxUnits` UTF-16 code units WITHOUT ever
	 * splitting a surrogate pair (verified against the real Umami 3.2 source and
	 * stack): a first fix clamped by Unicode CODE POINT (Array.from().slice()) -
	 * correct on the wire (an astral character was never split by OUR OWN
	 * clamp), but Umami's own truncateString() (src/lib/format.ts:126)
	 * re-clamps every event name via a plain `value.substring(0, 50)` - a
	 * UTF-16 CODE-UNIT cut - so a name whose code-point-clamped length
	 * still exceeded 50 code units (any astral character pushes it over)
	 * got RE-SPLIT by Umami itself, reproducing the exact U+FFFD
	 * corruption this fix exists to prevent. Clamping to code UNITS here
	 * instead makes Umami's own substring(0,50) a no-op: iterate by code
	 * point (Array.from), accumulate each character's OWN UTF-16 width (1
	 * for a Basic-Multilingual-Plane character, 2 for an astral
	 * surrogate-pair character - `.length` on a single Array.from()
	 * element already reflects this), and stop BEFORE appending any
	 * character that would push the running total over `maxUnits` - so a
	 * character that would not fit is dropped whole, never split. Mirrors
	 * the PHP side's clamp_to_utf16_code_units()
	 * (Support/EventAttributes::sanitize_event_name()) exactly.
	 *
	 * @param {string} name     Source string.
	 * @param {number} maxUnits Maximum UTF-16 code units in the result.
	 * @return {string}
	 */
	function clampToCodeUnits( name, maxUnits ) {
		var units = 0;
		var result = '';
		var chars = Array.from( name );

		for ( var i = 0; i < chars.length; i++ ) {
			var width = chars[ i ].length;

			if ( units + width > maxUnits ) {
				break;
			}

			result += chars[ i ];
			units += width;
		}

		return result;
	}

	/**
	 * Safe wrapper over window.umami.track(): ignored/clamped/never-throwing
	 * so a host page (or this file's own auto-track listeners) can never be
	 * broken by a tracking call.
	 *
	 * @param {string} name Event name (non-empty, truncated to Umami's 50-char limit).
	 * @param {Object} [data] Optional event payload.
	 */
	function track( name, data ) {
		try {
			if ( 'string' !== typeof name || '' === name ) {
				return;
			}

			if ( ! window.umami || 'function' !== typeof window.umami.track ) {
				return;
			}

			var clamped = clampToCodeUnits( name, 50 );

			if ( data ) {
				window.umami.track( clamped, data );
			} else {
				window.umami.track( clamped );
			}
		} catch ( error ) {
			// Never throw into the host page.
		}
	}

	window.statsUmami = { track: track };

	/**
	 * Parse a data-umami-<prefix>-data JSON attribute (see
	 * Support/EventAttributes::build_prefixed_event_attributes()) into a
	 * plain object, or undefined if absent/malformed - track()'s `data`
	 * argument is optional, so callers can pass this straight through.
	 *
	 * @param {Element} el       Element carrying the attribute.
	 * @param {string}  attrName Attribute name.
	 * @return {(Object|undefined)}
	 */
	function parseDataAttribute( el, attrName ) {
		var raw = el.getAttribute( attrName );

		if ( ! raw ) {
			return undefined;
		}

		try {
			var parsed = JSON.parse( raw );

			return ( parsed && 'object' === typeof parsed ) ? parsed : undefined;
		} catch ( error ) {
			return undefined;
		}
	}

	// Element-level Umami events (data-umami-event, Umami's own native
	// click-track attribute - Gutenberg core/button uses it) take
	// precedence over this file's auto-track and must never double-fire
	// with it; data-umami-skip="1" (CF7/WPForms forms) is the explicit
	// opt-out for our generic form/button auto-track.
	function shouldSkip( el ) {
		return '1' === el.getAttribute( 'data-umami-skip' ) || el.hasAttribute( 'data-umami-event' );
	}

	/**
	 * 1.1.0 (Elementor feature round): whether a clicked anchor sits inside a
	 * generic `data-umami-link` marker - stamped server-side, ONLY on
	 * Elementor Button widgets, by `Integrations\Elementor`. This file holds
	 * NO Elementor/CSS knowledge of its own: the marker only bypasses the
	 * autotrack_links off-gate in handleLinkClick() below, on the SAME single
	 * delegated click path every other link already uses - so a marked link
	 * gets identical label/URL/outbound treatment to any other link, and can
	 * never double-fire (shouldSkip() above still runs first, so an element
	 * that also carries Umami's own native `data-umami-event` - e.g. a
	 * Gutenberg core/button block - is never touched by this file at all).
	 *
	 * @param {Element} a Anchor element.
	 * @return {boolean}
	 */
	function isForcedLink( a ) {
		return !! ( a.closest && a.closest( '[data-umami-link]' ) );
	}

	/**
	 * Resolve a user-facing label for a clicked button/link, so an
	 * icon-only control (e.g. the WooCommerce mini-cart button, a nav
	 * open/close toggle) still yields an attributable event instead of the
	 * useless, unattributable `button_click`/`link_click`. Resolution order:
	 * `value` (INPUT) or `textContent`, then `aria-label`, then `title` -
	 * first non-empty trimmed string wins. Deliberately NOT falling back to
	 * `name`/`id`: those are developer identifiers, not user-facing labels,
	 * and would leak high-cardinality noise into event names. Internal
	 * whitespace is collapsed to a single space (not just end-trimmed) on
	 * every branch - a label split across source lines (`icon + text`) used
	 * to carry its literal newlines/tabs into the event name, and the same
	 * control forked into a different name whenever surrounding HTML
	 * whitespace changed (e.g. a minifier plugin). Last resort, a descendant
	 * `<img alt>` - a linked/buttoned image with no text/aria-label/title of
	 * its own (e.g. `<a href="…"><img alt="Product photo"></a>`) otherwise
	 * yields the unattributable `link_click`/`button_click`. Deliberately
	 * generic (any `<img alt>`, no page-builder-specific markup) and only
	 * reached when every prior branch found nothing, so it can never change
	 * an existing label.
	 *
	 * @param {Element} el Clicked element.
	 * @return {string} Label, or '' if the element genuinely has no accessible name.
	 */
	function elementLabel( el ) {
		var text = 'INPUT' === el.tagName ? ( el.value || '' ) : ( el.textContent || '' );

		text = text.replace( /\s+/g, ' ' ).trim();

		if ( text ) {
			return text;
		}

		var ariaLabel = ( el.getAttribute( 'aria-label' ) || '' ).replace( /\s+/g, ' ' ).trim();

		if ( ariaLabel ) {
			return ariaLabel;
		}

		var title = ( el.getAttribute( 'title' ) || '' ).replace( /\s+/g, ' ' ).trim();

		if ( title ) {
			return title;
		}

		if ( el.querySelectorAll ) {
			var imgs = el.querySelectorAll( 'img' );

			for ( var i = 0; i < imgs.length; i++ ) {
				var alt = ( imgs[ i ].getAttribute( 'alt' ) || '' ).replace( /\s+/g, ' ' ).trim();

				if ( alt ) {
					return alt;
				}
			}
		}

		return '';
	}

	function normalizeHost( host ) {
		host = ( host || '' ).toLowerCase();

		return 0 === host.indexOf( 'www.' ) ? host.slice( 4 ) : host;
	}

	/**
	 * Compare hostnames case-insensitively and tolerate a "www." prefix
	 * difference, so a mixed-case/www home doesn't read its own links as
	 * outbound. `.hostname` (not `.host`) throughout - it never includes a
	 * port, unlike `.host` on a non-default-port origin (e.g. a local dev
	 * site on :8690), which would otherwise make every same-site link
	 * register as outbound there.
	 *
	 * PRIMARY comparand is `window.location.hostname`, not `CFG.site_host`:
	 * `new URL(href).hostname` is always browser-normalized punycode, but
	 * `CFG.site_host` is `wp_parse_url(home_url())` verbatim - if a site's
	 * stored `siteurl` is Unicode (e.g. "bücher.example") rather than
	 * punycode, comparing against it directly would read every internal
	 * link as outbound. `location.hostname` is already punycode with zero
	 * configuration, so it is compared first; `CFG.site_host` is kept as a
	 * second, additional internal-host match (e.g. the visitor is on a
	 * mapped/CDN domain that differs from the configured home_url) - a link
	 * is internal if it matches EITHER.
	 *
	 * @param {Element} a Anchor element (already confirmed not an SVG <a>).
	 * @return {boolean}
	 */
	function isOutbound( a ) {
		var href = a.href;

		if ( 'string' !== typeof href || ! /^https?:\/\//i.test( href ) ) {
			return false;
		}

		try {
			var linkHost = normalizeHost( new URL( href ).hostname );
			var isInternal = linkHost === normalizeHost( window.location.hostname ) || linkHost === normalizeHost( CFG.site_host );

			return ! isInternal;
		} catch ( error ) {
			return false;
		}
	}

	// A scheme token, e.g. "data:"/
	// "blob:"/"javascript:"/"mailto:"/"tel:"/"http:" - anything before the
	// first `/`, `?`, or `#`, matching the WHATWG URL scheme grammar closely
	// enough for this allowlist (a scheme-less relative href, e.g.
	// "page.html" or "?x=1", never matches).
	var HREF_SCHEME_RE = /^([a-z][a-z0-9+.-]*):/i;

	/**
	 * Whether `a` has a real navigational href worth an auto-tracked
	 * event - false for an SVG <a> (its .href is an SVGAnimatedString, not a
	 * usable string - reading the raw attribute instead still isn't a page
	 * navigation worth tracking the same way, so it is simply excluded) and
	 * an empty/`#`-only href (menu toggles, accordions, JS widgets - no junk
	 * {url:''}/same-page events).
	 *
	 * The scheme check is an ALLOWLIST, not a blocklist: a href is
	 * navigable only if it has no scheme at all (root-relative "/path",
	 * protocol-relative "//host/path", query/path-relative "?x=1"/
	 * "page.html") or its scheme is http/https. Previously this blocklisted
	 * `javascript:`/`mailto:`/`tel:` specifically, which let `data:`/
	 * `blob:` (and anything else) through - a `data:` link with
	 * `autotrack_links` on fired `link:*` carrying a possibly-megabyte-sized
	 * `{url}` payload. The allowlist rejects every non-http(s) scheme in one
	 * rule, including `mailto:`/`tel:` (still deliberately excluded from
	 * both the internal-link and outbound buckets rather than given a
	 * distinct type, to keep this batch's scope bounded).
	 *
	 * @param {Element} a Anchor element.
	 * @return {boolean}
	 */
	function hasNavigableHref( a ) {
		if ( window.SVGElement && a instanceof window.SVGElement ) {
			return false;
		}

		var href = ( a.getAttribute( 'href' ) || '' ).trim();

		if ( '' === href || '#' === href ) {
			return false;
		}

		if ( 0 === href.indexOf( '//' ) ) {
			return true;
		}

		var schemeMatch = HREF_SCHEME_RE.exec( href );

		if ( ! schemeMatch ) {
			return true;
		}

		var scheme = schemeMatch[ 1 ].toLowerCase();

		return 'http' === scheme || 'https' === scheme;
	}

	/**
	 * Resolve the single innermost interactive element for a
	 * click, so a `<a><button>Label</button></a>` fires only the button's
	 * event, never both. Both selectors are resolved from the same
	 * `event.target`, so if both match they are necessarily nested with
	 * respect to each other - whichever does NOT contain the other is the
	 * innermost.
	 *
	 * @param {Element} target `event.target`.
	 * @return {(Object|null)} `{type: 'button'|'link', el: Element}` or null.
	 */
	function resolveClickTarget( target ) {
		if ( ! target || 'function' !== typeof target.closest ) {
			return null;
		}

		var button = target.closest( BUTTON_SELECTOR );
		var link = target.closest( 'a' );

		if ( button && link ) {
			return link.contains( button ) ? { type: 'button', el: button } : { type: 'link', el: link };
		}

		if ( button ) {
			return { type: 'button', el: button };
		}

		if ( link ) {
			return { type: 'link', el: link };
		}

		return null;
	}

	// Whole-page-section
	// CONTAINER selectors (replacing an earlier single mixed selector list),
	// matched by ANCESTRY (el.closest) and consulted
	// ONLY for buttons and forms, never for links - covers both the classic
	// and block front ends. Ancestry alone must never suppress a link, or
	// every ordinary navigational/outbound link rendered inside one of these
	// sections (a cart line item's product-title link, WooCommerce Blocks'
	// own Terms/Privacy links on checkout) is silently lost - see
	// isSkippedWooControl()'s docblock. `.wc-block-mini-cart` silences
	// the mini-cart button - and, as a side effect, its drawer's quantity
	// -/+ and Remove buttons, all cart-manipulation actions explicitly out
	// of scope - because both `textContent` and `aria-label` embed the live
	// cart count, so no label strategy (including the fallbacks above) yields a useful,
	// bounded-cardinality event name for it; ordinary links inside the
	// drawer keep working under the link rule below. `.wc-block-mini-cart__drawer`
	// is ALSO required, not just `.wc-block-mini-cart` (live-verified, WP
	// 6.9.4 + WooCommerce 10.9.3): the toggle button lives inside
	// `.wc-block-mini-cart`, but the open drawer's own content (the
	// quantity/Remove buttons) is a React portal appended as a sibling
	// under `<body>`, entirely outside that wrapper's DOM subtree - without
	// this second class the drawer's quantity `+`/`-` buttons fired
	// `button:＋`/`button:－` and Remove fired `button:Remove …`.
	var WOO_CONTAINER_SELECTOR = 'form.cart, form.woocommerce-cart-form, form.checkout, form.woocommerce-checkout, .wp-block-woocommerce-checkout, .wc-block-checkout, .wp-block-woocommerce-cart, .wc-block-cart, .wc-block-mini-cart, .wc-block-mini-cart__drawer';

	// Commerce controls rendered as anchors or as standalone buttons
	// OUTSIDE any container above, matched on the element ITSELF
	// (el.matches), never by ancestry - the block cart's Proceed to
	// Checkout, the classic cart's Checkout button, and the block shop
	// grid's Add to cart (which already carries `add_to_cart_button` itself,
	// so the self-match covers it with no separate class needed).
	// `.wc-block-mini-cart__footer-checkout` (a deliberate scope call)
	// closes what the container/control selectors above had left as an accepted
	// asymmetry: the mini-cart drawer's "Go to checkout" link used to fire
	// `link:Go to checkout` - a begin_checkout-class event, exactly the
	// class DECISIONS 2026-06-29 ("WooCommerce = purchase/revenue ONLY")
	// explicitly dropped - while the cart page's own "Proceed to Checkout"
	// anchor (`.wc-block-cart__submit-button`, already in this list) was
	// already suppressed. Self-match only, so it silences that one link
	// without touching any other link inside the drawer (e.g. a
	// product-title link, which must keep firing).
	var WOO_CONTROL_SELECTOR = '.add_to_cart_button, .wc-block-cart__submit-button, .checkout-button, .wc-block-mini-cart__footer-checkout';

	/**
	 * Whether a resolved click/submit target is one of WooCommerce's own
	 * commerce controls (Add to Cart, Place Order, Proceed to Checkout) - a
	 * generic auto-track event there is never meaningful on its own (the
	 * actual revenue signal is the dedicated `purchase` event printed by
	 * Integrations\WooCommerce, entirely independent of this file), and
	 * DECISIONS 2026-06-29 scoped WooCommerce to purchase/revenue ONLY -
	 * explicitly dropping add_to_cart/begin_checkout. Gated on
	 * CFG.woo_present (WooCommerce active on this site) rather than the
	 * enable_woocommerce toggle: a commerce control is never a meaningful
	 * generic form/button/link event regardless of whether we happen to be
	 * tracking purchases.
	 *
	 * A LINK is skipped only via the self-match control list - being inside
	 * a cart/checkout container must never by itself suppress a link.
	 * Buttons and forms are ALSO skipped by ancestry, since Woo's
	 * add-to-cart/cart/checkout forms and their structural buttons carry
	 * neither a stable name/id nor always the control class itself.
	 *
	 * @param {Element} el   Element to test (a form, or a clicked button/link).
	 * @param {string}  kind 'button' | 'link' | 'form'.
	 * @return {boolean}
	 */
	function isSkippedWooControl( el, kind ) {
		if ( ! CFG.woo_present || ! el.matches ) {
			return false;
		}

		if ( el.matches( WOO_CONTROL_SELECTOR ) ) {
			return true;
		}

		return 'link' !== kind && !! ( el.closest && el.closest( WOO_CONTAINER_SELECTOR ) );
	}

	function isSubmitControl( el ) {
		if ( 'INPUT' === el.tagName ) {
			return 'submit' === el.type;
		}

		// A <button> with no explicit type attribute defaults to "submit"
		// per the HTML spec, and el.type already reflects that default.
		return 'BUTTON' === el.tagName && 'submit' === el.type;
	}

	function handleLinkClick( a ) {
		if ( shouldSkip( a ) ) {
			return;
		}

		if ( isOutbound( a ) ) {
			if ( CFG.autotrack_outbound ) {
				track( 'outbound-link-click', { url: a.href } );
			}

			return;
		}

		if ( ( ! CFG.autotrack_links && ! isForcedLink( a ) ) || ! hasNavigableHref( a ) ) {
			return;
		}

		var label = elementLabel( a );

		track( label ? 'link:' + label : 'link_click', { url: a.href } );
	}

	function handleButtonClick( el ) {
		if ( shouldSkip( el ) ) {
			return;
		}

		// A submit control belonging to a form defers to that
		// form's own submit event - one logical submission is exactly one
		// event, whether triggered by a click or by pressing Enter. Only
		// non-submit buttons (type=button/reset) fire their own event.
		if ( isSubmitControl( el ) && el.form ) {
			return;
		}

		if ( ! CFG.autotrack_buttons ) {
			return;
		}

		var label = elementLabel( el );

		track( label ? 'button:' + label : 'button_click' );
	}

	function onClick( event ) {
		// The listener itself must honour this file's own never-throw
		// contract, not just track() - a click whose target is `document`
		// (dispatchable by any other plugin/script) has no `.closest`, and
		// resolveClickTarget() guards that specific case, but this try/catch
		// is the general backstop for anything else that could throw here.
		try {
			var resolved = resolveClickTarget( event.target );

			if ( ! resolved ) {
				return;
			}

			// WooCommerce's own commerce controls (Add to Cart, Place
			// Order, Proceed to Checkout) are never a generic auto-track
			// event - see isSkippedWooControl()'s docblock. Checked before
			// button/link handling so it applies to both types (an archive
			// add-to-cart is an <a>; the block checkout's Place Order is a
			// type="button" <button>), and passes the resolved kind so a
			// link is skipped only by the self-match control list, never by
			// container ancestry alone.
			if ( isSkippedWooControl( resolved.el, resolved.type ) ) {
				return;
			}

			if ( 'button' === resolved.type ) {
				handleButtonClick( resolved.el );
			} else {
				handleLinkClick( resolved.el );
			}
		} catch ( error ) {
			// Never throw into the host page.
		}
	}

	// Per-form-instance
	// "already fired" latch, consumed at the moment a WPForms success event
	// is actually tracked - belt-and-suspenders so that NEITHER the jQuery
	// path NOR the MutationObserver fallback (nor a pile-up of several
	// fallback observers started by repeated invalid-then-valid submits)
	// can ever track the same form's success twice.
	var wpformsFired = 'undefined' !== typeof WeakSet ? new WeakSet() : null;

	function hasWpformsFired( form ) {
		return wpformsFired ? wpformsFired.has( form ) : false;
	}

	function markWpformsFired( form ) {
		if ( wpformsFired ) {
			wpformsFired.add( form );
		}
	}

	/**
	 * Read a WPForms form's stored success-event attributes and track()
	 * them exactly once, guarded by the one-shot latch above. Shared by the
	 * jQuery success path, the no-jQuery observer fallback, and the
	 * AJAX-off sessionStorage replay.
	 *
	 * @param {Element} form The WPForms <form id="wpforms-form-N">.
	 */
	function fireWpformsSuccess( form ) {
		if ( ! form || hasWpformsFired( form ) ) {
			return;
		}

		markWpformsFired( form );
		track( form.getAttribute( 'data-umami-wpforms-event' ), parseDataAttribute( form, 'data-umami-wpforms-data' ) );
	}

	/**
	 * Resolve the real `<form id="wpforms-form-N">` a
	 * `wpformsAjaxSubmitSuccess` event refers to. Live-verified against the
	 * installed WPForms Lite 1.10.2.1 (`assets/js/frontend/wpforms.min.js`):
	 * `formSubmitAjax` receives the submitted form as a jQuery object and,
	 * on a successful AJAX response, calls `$form.trigger(
	 * 'wpformsAjaxSubmitSuccess', response )` - BEFORE branching on
	 * `response.data.redirect_url` to redirect (so we fire before
	 * any "Go to URL"/"Show Page" navigation) or on `response.data.confirmation`
	 * to swap in the Message-confirmation markup. A jQuery `.trigger()` call
	 * bubbles like a native event, so binding once on `jQuery(document)`
	 * (installed in initAutoTrack(), not per-submit) catches every form's
	 * success; `event.target` is the originating `<form>` element itself, so
	 * the direct-hit branch below covers the verified case, with a
	 * `closest('form')` fallback for robustness against a future WPForms
	 * version triggering on a descendant instead.
	 *
	 * @param {Element} target `event.target` of the wpformsAjaxSubmitSuccess event.
	 * @return {(Element|null)}
	 */
	function resolveWpformsForm( target ) {
		if ( ! target ) {
			return null;
		}

		if ( 'FORM' === target.tagName ) {
			return target;
		}

		return ( target.closest && target.closest( 'form' ) ) || null;
	}

	/**
	 * Use `form.getAttribute('id')`, NOT `form.id`, and likewise
	 * `getAttribute('name')` everywhere else in this file that resolves a
	 * form's own identity. `HTMLFormElement` is `[LegacyOverrideBuiltIns]`:
	 * a form control named `id` or `name` (e.g. `<input name="id">`, a
	 * common field name) becomes an OWN property on the form element that
	 * SHADOWS the `.id`/`.name` IDL attribute, even when the form has a real
	 * `id=""` - so `form.id` can silently stop being the id string at all.
	 * `getAttribute()` always reads the literal attribute and cannot be
	 * shadowed this way.
	 */
	function isTrackableWpformsForm( form ) {
		return !! ( form && form.hasAttribute( 'data-umami-wpforms-event' ) && /^wpforms-form-\d+$/.test( form.getAttribute( 'id' ) || '' ) );
	}

	/**
	 * jQuery success-path handler, bound once on `jQuery(document)` in
	 * initAutoTrack() when `window.jQuery` is present.
	 *
	 * @param {Object} event jQuery Event object (`.target` is the succeeded `<form>`).
	 */
	function onWpformsAjaxSuccess( event ) {
		var form = resolveWpformsForm( event && event.target );

		if ( isTrackableWpformsForm( form ) ) {
			fireWpformsSuccess( form );
		}
	}

	/**
	 * No-jQuery fallback ONLY (never started when window.jQuery is
	 * present - see onSubmit() - so this path and the jQuery path can never
	 * both fire for the same submission). Poll (via MutationObserver, not a
	 * fixed-interval timer) for WPForms' own `#wpforms-confirmation-<id>`
	 * container (live-confirmed DOM id convention) to appear, then fire
	 * once. Unlike the pre-fix version, this does NOT fire merely because an
	 * id exists somewhere in the document (a pre-existing container - e.g.
	 * the same form embedded twice -
	 * used to fire immediately, before this submission's outcome was known,
	 * and would also have kept matching on every LATER unrelated mutation
	 * elsewhere on the page) - it inspects each mutation's OWN addedNodes
	 * and only fires when the target id was actually part of what THIS
	 * mutation just inserted. A validation failure or abandoned AJAX
	 * submission never produces the id, so the wait simply times out and
	 * nothing fires. The one-shot latch in fireWpformsSuccess() (not this
	 * function) is what stops a pile-up of observers from a failed-then-
	 * retried submission from double-tracking once the confirmation
	 * genuinely appears.
	 *
	 * @param {Element} form The submitted <form>.
	 */
	function maybeTrackWpformsSuccessFallback( form ) {
		if ( ! isTrackableWpformsForm( form ) ) {
			return;
		}

		var id = 'wpforms-confirmation-' + /^wpforms-form-(\d+)$/.exec( form.getAttribute( 'id' ) )[ 1 ];
		var timeoutId;

		var observer = new MutationObserver( function ( mutations ) {
			for ( var i = 0; i < mutations.length; i++ ) {
				var added = mutations[ i ].addedNodes;

				for ( var j = 0; j < added.length; j++ ) {
					var node = added[ j ];

					if ( 1 !== node.nodeType ) {
						continue;
					}

					if ( id === node.id || ( node.querySelector && node.querySelector( '#' + id ) ) ) {
						cleanup();
						fireWpformsSuccess( form );

						return;
					}
				}
			}
		} );

		function cleanup() {
			observer.disconnect();
			clearTimeout( timeoutId );
		}

		observer.observe( document.body, { childList: true, subtree: true } );

		// Safety timeout: a validation-failed or otherwise abandoned AJAX
		// submission never shows the confirmation container - stop
		// watching rather than observe forever.
		timeoutId = setTimeout( cleanup, WPFORMS_SUCCESS_TIMEOUT_MS );
	}

	// The
	// jQuery/observer paths above only ever see a submission that completes
	// on THIS page (AJAX). A WPForms form with AJAX disabled
	// (`ajax_submit` off in the form's own settings) is a classic
	// full-page POST - the browser navigates away before any in-page signal
	// could fire. sessionStorage survives that navigation: queue the
	// pending event at submit time, then on the NEXT page's load, fire it
	// only once a genuine-success signal confirms it (see
	// consumeWpformsAjaxOffPending()'s docblock for
	// why the confirmation-container check alone was insufficient).
	var WPFORMS_AJAX_OFF_PENDING_KEY = 'statsUmamiWpformsAjaxOffPending';

	// The one-shot, same-origin URL query-arg marker
	// Integrations\WPForms::maybe_append_ajax_off_success_marker() appends
	// to a Redirect/Page confirmation's destination URL on genuine success
	// (server-side, cookie-free by design - see that method's docblock).
	// Carries only the form id, matching WPFORMS_AJAX_OFF_PENDING_KEY's
	// own pending.formId shape (a numeric string).
	var WPFORMS_AJAX_OFF_SUCCESS_QUERY_ARG = 'stats_umami_wpf_ok';

	// Bounded readiness poll for window.umami, mirroring
	// Integrations\WooCommerce::print_pending_event()'s own
	// POLL_INTERVAL_MS/POLL_MAX_ATTEMPTS constants (~10s ceiling at the
	// default 200ms interval) - script_loading=async (or a delay-JS
	// optimizer) does not guarantee window.umami is ready the instant this
	// runs, and firing once immediately (the previous behaviour) silently
	// dropped the event with no recovery.
	var WPFORMS_TRACK_POLL_INTERVAL_MS = 200;
	var WPFORMS_TRACK_POLL_MAX_ATTEMPTS = 50;

	function isWpformsAjaxForm( form ) {
		return !! ( form.classList && form.classList.contains( 'wpforms-ajax-form' ) );
	}

	/**
	 * At the native submit of an AJAX-off WPForms form, stash the
	 * pending event in sessionStorage so consumeWpformsAjaxOffPending() (run
	 * on the next page's load) can fire it if - and only if - that next
	 * page shows this form's confirmation container. Never throws: a
	 * sessionStorage write can fail in some private-browsing modes, in
	 * which case the event is simply not queued rather than breaking the
	 * page.
	 *
	 * @param {Element} form The submitted <form>.
	 */
	function maybeQueueWpformsAjaxOffSuccess( form ) {
		if ( ! isTrackableWpformsForm( form ) || isWpformsAjaxForm( form ) ) {
			return;
		}

		try {
			window.sessionStorage.setItem(
				WPFORMS_AJAX_OFF_PENDING_KEY,
				JSON.stringify( {
					formId: /^wpforms-form-(\d+)$/.exec( form.getAttribute( 'id' ) )[ 1 ],
					name: form.getAttribute( 'data-umami-wpforms-event' ),
					data: parseDataAttribute( form, 'data-umami-wpforms-data' ),
				} )
			);
		} catch ( error ) {
			// sessionStorage unavailable - nothing to queue; see docblock.
		}
	}

	/**
	 * Whether the CURRENT page's URL carries the one-shot success
	 * marker Integrations\WPForms::maybe_append_ajax_off_success_marker()
	 * appends server-side, for the given pending form id. Only the
	 * Redirect/Page confirmation types ever produce this marker (Message
	 * never redirects, so it keeps using the confirmation-container check
	 * below instead - see consumeWpformsAjaxOffPending()'s docblock).
	 *
	 * @param {string} formId Pending record's formId (a numeric string).
	 * @return {boolean}
	 */
	function hasAjaxOffSuccessMarker( formId ) {
		try {
			var params = new window.URLSearchParams( window.location.search );
			var value = params.get( WPFORMS_AJAX_OFF_SUCCESS_QUERY_ARG );

			return null !== value && String( parseInt( value, 10 ) ) === formId;
		} catch ( error ) {
			return false;
		}
	}

	/**
	 * Strip ONLY the success-marker query arg from the current URL via
	 * history.replaceState, preserving every other query arg and the hash -
	 * URL hygiene, not a correctness requirement. The sessionStorage
	 * pending entry is already consumed (read + removed) by the time this
	 * runs, so a bookmarked/shared URL that still carries a stale marker
	 * can never re-fire regardless - see consumeWpformsAjaxOffPending().
	 */
	function stripAjaxOffSuccessMarker() {
		try {
			if ( ! window.history || 'function' !== typeof window.history.replaceState ) {
				return;
			}

			var url = new window.URL( window.location.href );

			if ( ! url.searchParams.has( WPFORMS_AJAX_OFF_SUCCESS_QUERY_ARG ) ) {
				return;
			}

			url.searchParams.delete( WPFORMS_AJAX_OFF_SUCCESS_QUERY_ARG );

			window.history.replaceState( window.history.state, '', url.pathname + url.search + url.hash );
		} catch ( error ) {
			// Never throw into the host page.
		}
	}

	/**
	 * Bounded readiness poll mirroring
	 * Integrations\WooCommerce::print_pending_event()'s pattern - the
	 * sessionStorage pending record has ALREADY been consumed (read +
	 * removed) by the caller, so the payload is held here in the closure
	 * across the poll rather than re-read. Fires exactly once, once
	 * window.umami.track is actually ready; gives up silently after the
	 * bounded attempt budget.
	 *
	 * @param {Object} pending {formId, name, data} - already removed from sessionStorage.
	 */
	function pollAndFireWpformsAjaxOffSuccess( pending ) {
		var attempts = 0;

		( function poll() {
			if ( window.umami && 'function' === typeof window.umami.track ) {
				track( pending.name, pending.data );
				stripAjaxOffSuccessMarker();

				return;
			}

			if ( ++attempts >= WPFORMS_TRACK_POLL_MAX_ATTEMPTS ) {
				return;
			}

			setTimeout( poll, WPFORMS_TRACK_POLL_INTERVAL_MS );
		}() );
	}

	/**
	 * Run once at page load. Consumes (reads + removes) any
	 * pending AJAX-off WPForms event queued by the PREVIOUS page's submit,
	 * and fires it only once a genuine-success signal confirms it - either
	 * this page's initial DOM contains that form's confirmation container
	 * (the Message confirmation type, which never redirects), OR this
	 * page's URL carries the server-appended one-shot marker matching this
	 * form id (the Redirect/Page confirmation types, replacing an earlier
	 * container-only check, which never
	 * proved success for either of those two types since WPForms
	 * server-side redirects away before any in-page signal could fire). A
	 * validation failure (no navigation at all), a re-rendered error page
	 * (navigation, but no confirmation container and no marker - WPForms
	 * only reaches wpforms_process_redirect_url on GENUINE success), or
	 * simply landing on an unrelated later page all self-clean without
	 * ever firing a false event. Single-fire is guaranteed twice over: the
	 * sessionStorage entry is consumed exactly once here (read + removed
	 * before this function returns), and a bookmarked/shared URL still
	 * carrying the marker fires nothing on a later visit because there is
	 * no longer a matching pending entry in that (new) session.
	 */
	function consumeWpformsAjaxOffPending() {
		var raw;

		try {
			raw = window.sessionStorage.getItem( WPFORMS_AJAX_OFF_PENDING_KEY );

			if ( raw ) {
				window.sessionStorage.removeItem( WPFORMS_AJAX_OFF_PENDING_KEY );
			}
		} catch ( error ) {
			return;
		}

		if ( ! raw ) {
			return;
		}

		var pending;

		try {
			pending = JSON.parse( raw );
		} catch ( error ) {
			return;
		}

		if ( ! pending || ! pending.formId || 'string' !== typeof pending.name ) {
			return;
		}

		var confirmed = !! document.getElementById( 'wpforms-confirmation-' + pending.formId ) || hasAjaxOffSuccessMarker( pending.formId );

		if ( ! confirmed ) {
			return;
		}

		pollAndFireWpformsAjaxOffSuccess( pending );
	}

	function onSubmit( event ) {
		// Honour this file's own never-throw contract at the listener
		// level, not just inside track() - see onClick()'s matching guard.
		try {
			var form = event.target;

			if ( ! form || 'FORM' !== form.tagName ) {
				return;
			}

			// Independent of data-umami-skip / autotrack_forms / track_comments
			// below (the success listeners always work, even
			// with every auto-track toggle off).
			// AJAX-on/off is orthogonal to jQuery presence (WPForms enqueues
			// jQuery on any page it renders a form either way), so it is
			// branched on separately: an AJAX-off form is a full-page POST that
			// destroys any in-page listener/observer regardless of jQuery, so
			// it always goes through the sessionStorage queue; an AJAX-on
			// form is covered by the jQuery `wpformsAjaxSubmitSuccess` listener
			// (bound once in initAutoTrack()) when jQuery is present, with the
			// MutationObserver fallback only when it is absent - so the
			// jQuery path and the fallback observer can never both fire for one
			// submission.
			if ( isTrackableWpformsForm( form ) ) {
				if ( isWpformsAjaxForm( form ) ) {
					if ( ! window.jQuery ) {
						maybeTrackWpformsSuccessFallback( form );
					}
				} else {
					maybeQueueWpformsAjaxOffSuccess( form );
				}
			}

			if ( shouldSkip( form ) ) {
				return;
			}

			// Delegated (not bound once at DOMContentLoaded), so
			// a late/lazy-injected comment form still tracks; works even when
			// autotrack_forms is off.
			// The comment form is governed SOLELY by track_comments: turning
			// "Comment submissions" off must stop comment tracking entirely, not
			// merely rename it to a generic form event. So a commentform submission always returns here and
			// never falls through to the generic autotrack_forms branch below.
			if ( 'commentform' === form.getAttribute( 'id' ) ) {
				if ( CFG.track_comments ) {
					track( 'comment_submit' );
				}

				return;
			}

			if ( ! CFG.autotrack_forms ) {
				return;
			}

			// The generic form_submit branch only - CF7/WPForms
			// success listeners above already returned by this point, so this
			// never touches them. Woo's own add-to-cart/cart/checkout forms
			// are never a meaningful generic "form submission" - see
			// isSkippedWooControl()'s docblock.
			if ( isSkippedWooControl( form, 'form' ) ) {
				return;
			}

			var idOrName = form.getAttribute( 'id' ) || form.getAttribute( 'name' ) || '';

			track( idOrName ? 'form:' + idOrName : 'form_submit' );
		} catch ( error ) {
			// Never throw into the host page.
		}
	}

	/**
	 * Resolve the wpcf7mailsent
	 * target to the actual `<form data-umami-cf7-event>` element.
	 * Live-confirmed against the installed CF7 6.1.6: `event.target` IS the
	 * `<form class="wpcf7-form">` element itself, so the direct-hit branch
	 * covers it exactly. The querySelector fallback hardens against
	 * version/skin drift on other CF7 releases that might dispatch the
	 * event on a wrapper element instead (e.g. a `<div>` around the form) -
	 * cheap, and a no-op today.
	 *
	 * @param {Element} target `event.target` of the wpcf7mailsent event.
	 * @return {(Element|null)}
	 */
	function resolveCf7Form( target ) {
		if ( ! target ) {
			return null;
		}

		if ( target.hasAttribute && target.hasAttribute( 'data-umami-cf7-event' ) ) {
			return target;
		}

		return ( target.querySelector && target.querySelector( 'form[data-umami-cf7-event]' ) ) || null;
	}

	/**
	 * Hooked on wpcf7mailsent - the event never fires for a validation-failed
	 * or mail-failed submission, only a genuinely sent one. Always
	 * installed, independent of autotrack_forms - see this file's docblock.
	 *
	 * @param {Event} event The wpcf7mailsent event.
	 */
	function onCf7MailSent( event ) {
		var form = resolveCf7Form( event.target );

		if ( ! form ) {
			return;
		}

		track( form.getAttribute( 'data-umami-cf7-event' ), parseDataAttribute( form, 'data-umami-cf7-data' ) );
	}

	function initAutoTrack() {
		// CF7/WPForms genuine-success tracking - always on (no-ops
		// without the attribute), independent of every toggle below.
		document.addEventListener( 'wpcf7mailsent', onCf7MailSent );
		document.addEventListener( 'submit', onSubmit, true );

		// Install the real WPForms success listener ONCE here (not per
		// submit) when jQuery is present - see resolveWpformsForm()'s
		// docblock for the live-verified event contract.
		if ( window.jQuery ) {
			window.jQuery( document ).on( 'wpformsAjaxSubmitSuccess', onWpformsAjaxSuccess );
		}

		// Fire any AJAX-off WPForms success queued by the previous
		// page's submit, if this page's initial DOM confirms it succeeded.
		consumeWpformsAjaxOffPending();

		if ( CFG.autotrack_links || CFG.autotrack_outbound || CFG.autotrack_buttons || CFG.track_elementor_buttons ) {
			document.addEventListener( 'click', onClick, true );
		}
	}

	onReady( initAutoTrack );
}() );
