/**
 * Stats Umami admin screen behaviour: client-side Website ID format
 * validation (UX only - the server-side Settings\Sanitizer stays the source
 * of truth) and a confirm step before resetting to defaults. Hand-written,
 * no build step, no dependencies.
 *
 * @package StatsUmami
 */

( function () {
	'use strict';

	var UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

	function onReady( fn ) {
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	function strings() {
		return window.statsUmamiAdmin || {};
	}

	function initWebsiteIdValidation() {
		var field = document.getElementById( 'stats_umami_website_id' );

		if ( ! field ) {
			return;
		}

		var hint = field.parentNode.parentNode.querySelector( '.us-hint' );

		function update() {
			var value = field.value.trim();

			field.classList.remove( 'us-input--valid', 'us-input--invalid' );

			if ( '' === value ) {
				field.removeAttribute( 'aria-invalid' );
				return;
			}

			if ( UUID_PATTERN.test( value ) ) {
				field.classList.add( 'us-input--valid' );
				field.removeAttribute( 'aria-invalid' );

				if ( hint ) {
					hint.className = 'us-hint us-hint--ok';
					hint.textContent = strings().validHint || '';
				}
			} else {
				field.classList.add( 'us-input--invalid' );
				field.setAttribute( 'aria-invalid', 'true' );

				if ( hint ) {
					hint.className = 'us-hint us-hint--error';
					hint.textContent = strings().invalidHint || '';
				}
			}
		}

		field.addEventListener( 'input', update );
	}

	function initSwitchLabels() {
		var switches = document.querySelectorAll( '.us-switch input[type="checkbox"]' );

		Array.prototype.forEach.call( switches, function ( input ) {
			var wrapper = input.closest( '.us-switch' );
			var label   = wrapper ? wrapper.nextElementSibling : null;

			if ( ! label ) {
				return;
			}

			input.addEventListener( 'change', function () {
				label.textContent = input.checked
					? ( strings().switchOn || 'On' )
					: ( strings().switchOff || 'Off' );
			} );
		} );
	}

	function initResetConfirm() {
		var form = document.getElementById( 'stats-umami-reset-form' );

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			var message = strings().confirmReset || 'Are you sure?';

			if ( ! window.confirm( message ) ) { // eslint-disable-line no-alert
				event.preventDefault();
			}
		} );
	}

	onReady( function () {
		initWebsiteIdValidation();
		initSwitchLabels();
		initResetConfirm();
	} );
}() );
