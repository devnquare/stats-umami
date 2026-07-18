/**
 * JS test for the WPForms builder's add/remove-row script
 * (StatsUmami\Integrations\WPForms::print_kv_script()) - a
 * silent-data-loss regression:
 * a new row's index was `tbody.querySelectorAll('tr').length`, which is NOT
 * unique once any row has ever been removed. Repro: 3 pairs (indices 0,1,2)
 * -> remove the MIDDLE row (indices now 0,2; length 2) -> "Add data pair"
 * assigns the new row index 2 too - two `settings[stats_umami_event_data][2]
 * [key]` fields submit, and WPForms' native PHP array-building lets the
 * later one silently win, destroying the pre-existing third pair with no
 * error. Fix: a monotonic `data-next-index` counter on the table (see
 * render_section()/print_kv_script()), never decremented, never reused.
 *
 * This script is embedded inline in a PHP method (echoed only on WPForms
 * admin screens), not a standalone .js file - so, to test the REAL shipped
 * code rather than a hand-copied duplicate that could silently drift from
 * it (exactly the vacuous-test risk this project has hit before), this test
 * extracts the actual `<script>` block straight out of the PHP SOURCE FILE
 * at test time and executes it verbatim in jsdom. The one PHP interpolation
 * inside that block (`<?php echo $remove_label; ?>`) is substituted with the
 * literal value it evaluates to in production (`wp_json_encode( __( 'Remove',
 * 'stats-umami' ) )` = `"Remove"` in the plugin's English source strings) -
 * every other byte of the extracted script is untouched.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const WPFORMS_PHP_PATH = path.join( __dirname, '..', '..', 'src', 'Integrations', 'WPForms.php' );

/**
 * Pull the literal `<script>...</script>` body out of print_kv_script()'s
 * PHP source and substitute its one PHP interpolation with the literal
 * value production evaluates it to.
 *
 * @return {string} The extracted, directly-executable JS.
 */
function extractPrintKvScript() {
	const php = fs.readFileSync( WPFORMS_PHP_PATH, 'utf8' );

	const methodStart = php.indexOf( 'public static function print_kv_script()' );
	if ( -1 === methodStart ) {
		throw new Error( 'print_kv_script() not found in WPForms.php - has it been renamed/moved?' );
	}

	const scriptOpenTag = '<script>';
	const scriptCloseTag = '</script>';
	const openIndex = php.indexOf( scriptOpenTag, methodStart );
	const closeIndex = php.indexOf( scriptCloseTag, openIndex );

	if ( -1 === openIndex || -1 === closeIndex ) {
		throw new Error( 'Could not find the <script>...</script> block inside print_kv_script().' );
	}

	let js = php.slice( openIndex + scriptOpenTag.length, closeIndex );

	// The PHP interpolations inside this block: substitute each with the literal value production
	// evaluates it to in the plugin's English source strings.
	js = js.replace( /<\?php echo \$remove_label;[^?]*\?>/, '"Remove"' );
	js = js.replace( /<\?php echo \$key_placeholder;[^?]*\?>/, '"key"' );
	js = js.replace( /<\?php echo \$value_placeholder;[^?]*\?>/, '"value"' );

	if ( js.indexOf( '<?php' ) !== -1 ) {
		throw new Error( 'Unreplaced PHP interpolation remains in the extracted script - update the substitution above.' );
	}

	return js;
}

/**
 * Build the table markup render_section() emits for N existing pairs -
 * enough of it for this add/remove behaviour, not a full page fixture.
 *
 * @param {Array<{key: string, value: string}>} pairs Existing pairs, in order.
 * @return {string} HTML for the table + its rows + the add-row button.
 */
function kvTableMarkup( pairs ) {
	const prefix = 'settings[stats_umami_event_data]';

	const rows = pairs
		.map(
			( pair, index ) =>
				'<tr>' +
				'<td><input type="text" class="stats-umami-wpforms-kv-key" name="' +
				prefix +
				'[' +
				index +
				'][key]" value="' +
				pair.key +
				'" /></td>' +
				'<td><input type="text" class="stats-umami-wpforms-kv-value" name="' +
				prefix +
				'[' +
				index +
				'][value]" value="' +
				pair.value +
				'" /></td>' +
				'<td><button type="button" class="button stats-umami-wpforms-remove-row">Remove</button></td>' +
				'</tr>'
		)
		.join( '' );

	return (
		'<table id="stats-umami-wpforms-kv-table" data-name-prefix="' +
		prefix +
		'" data-next-index="' +
		pairs.length +
		'">' +
		'<tbody>' +
		rows +
		'</tbody>' +
		'</table>' +
		'<button type="button" id="stats-umami-wpforms-add-row">Add data pair</button>'
	);
}

/**
 * @param {string} attrName Attribute to read from ('name' or 'value').
 * @param {string} className Class selector fragment, e.g. 'stats-umami-wpforms-kv-key'.
 * @return {Array<string>}
 */
function collectFieldValues( className, attrName ) {
	return Array.prototype.map.call(
		document.querySelectorAll( '.' + className ),
		( el ) => el.getAttribute( attrName )
	);
}

describe( 'WPForms builder add/remove-row script (print_kv_script)', () => {
	beforeEach( () => {
		document.body.innerHTML = '';

		// Load the REAL extracted script fresh into the document per test -
		// it has no double-init guard of its own (unlike frontend.js), and
		// is only ever loaded once per real admin page anyway.
		const script = document.createElement( 'script' );
		script.textContent = extractPrintKvScript();
		document.body.appendChild( script );
	} );

	test( 'removing the middle row then adding a new one assigns a UNIQUE index, not a reused one', () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			kvTableMarkup( [
				{ key: 'source', value: 'homepage' },
				{ key: 'campaign', value: 'spring-sale' },
				{ key: 'medium', value: 'email' },
			] )
		);

		// Remove the MIDDLE row (the "campaign" pair, index 1) - surviving
		// rows are now indices 0 and 2, table length 2.
		const removeButtons = document.querySelectorAll( '.stats-umami-wpforms-remove-row' );
		removeButtons[ 1 ].dispatchEvent( new window.Event( 'click', { bubbles: true, cancelable: true } ) );

		expect( document.querySelectorAll( 'tbody tr' ).length ).toBe( 2 );

		// Add a new pair.
		document
			.getElementById( 'stats-umami-wpforms-add-row' )
			.dispatchEvent( new window.Event( 'click', { bubbles: true, cancelable: true } ) );

		const keyNames = collectFieldValues( 'stats-umami-wpforms-kv-key', 'name' );

		// Every field's index must be UNIQUE - no two key inputs may share
		// the same settings[stats_umami_event_data][N][key] index, or
		// WPForms' own PHP array-building lets the later one silently win
		// and destroy the earlier one.
		const indices = keyNames.map( ( name ) => name.match( /\[(\d+)\]\[key\]$/ )[ 1 ] );
		expect( new Set( indices ).size ).toBe( indices.length );

		// Both surviving original pairs (source@0, medium@2) must still be
		// present, untouched, by value.
		const values = collectFieldValues( 'stats-umami-wpforms-kv-value', 'value' );
		expect( values ).toEqual( expect.arrayContaining( [ 'homepage', 'email' ] ) );

		// The new row must not have reused index 2 (the surviving "medium"
		// row's own index) - it must be a genuinely new, unused number.
		expect( indices ).not.toEqual( [ '0', '2', '2' ] );
		expect( indices.filter( ( i ) => '2' === i ).length ).toBe( 1 );
	} );
} );
