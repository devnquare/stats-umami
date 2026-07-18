/**
 * Adds an "Umami Tracking" Inspector panel to core/button, letting an editor
 * attach a custom Umami event (name + optional key/value data) to the block.
 *
 * Attributes persist only in the block's stored attributes (the delimiter
 * comment) - this file never touches getSaveContent/extraProps, so the saved
 * HTML carries no data-umami-event*. StatsUmami\Integrations\Gutenberg's
 * render_block filter is the SOLE place that ever emits the attribute (see
 * docs/DECISIONS.md [D3]) - this keeps the event impossible to duplicate.
 *
 * Written with createElement() rather than JSX: @wordpress/scripts' default
 * JSX transform (since it bundles a newer Babel/React preset) targets the
 * "automatic" runtime, which depends on the `react-jsx-runtime` script
 * handle - only registered by WordPress core since 6.6. Our floor is 6.0
 * (docs/DECISIONS.md "WordPress floor = 6.0"), so this file avoids the JSX
 * transform entirely rather than fighting the build config to force the
 * classic runtime.
 */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, Button } from '@wordpress/components';
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const TARGET_BLOCK_NAME = 'core/button';

/**
 * blocks.registerBlockType: adds the two custom attributes to core/button
 * only. No `source` is set, so WordPress stores them in the block comment
 * rather than trying to derive them from saved markup.
 *
 * @param {Object} settings Block type settings being registered.
 * @param {string} name     Block type name.
 * @return {Object} Possibly-modified settings.
 */
function addUmamiAttributes( settings, name ) {
	if ( TARGET_BLOCK_NAME !== name ) {
		return settings;
	}

	return {
		...settings,
		attributes: {
			...settings.attributes,
			umamiEvent: {
				type: 'string',
				default: '',
			},
			umamiDataPairs: {
				type: 'array',
				default: [],
			},
		},
	};
}

addFilter( 'blocks.registerBlockType', 'stats-umami/add-attributes', addUmamiAttributes );

/**
 * editor.BlockEdit HOC: renders the "Umami Tracking" InspectorControls panel
 * on core/button only, leaving every other block's edit UI untouched.
 */
const withUmamiInspectorControls = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		if ( TARGET_BLOCK_NAME !== props.name ) {
			return createElement( BlockEdit, props );
		}

		const { attributes, setAttributes } = props;
		const umamiEvent = attributes.umamiEvent || '';
		const umamiDataPairs = attributes.umamiDataPairs || [];

		const setPair = ( index, field, value ) => {
			const next = umamiDataPairs.slice();
			next[ index ] = { ...next[ index ], [ field ]: value };
			setAttributes( { umamiDataPairs: next } );
		};

		const removePair = ( index ) => {
			const next = umamiDataPairs.slice();
			next.splice( index, 1 );
			setAttributes( { umamiDataPairs: next } );
		};

		const addPair = () => {
			setAttributes( { umamiDataPairs: [ ...umamiDataPairs, { key: '', value: '' } ] } );
		};

		const dataPairFields = umamiEvent
			? [
					...umamiDataPairs.map( ( pair, index ) =>
						createElement(
							'div',
							{ className: 'stats-umami-kv-row', key: index },
							createElement( TextControl, {
								label: __( 'Key', 'stats-umami' ),
								value: pair.key || '',
								onChange: ( value ) => setPair( index, 'key', value ),
								__next40pxDefaultSize: true,
								__nextHasNoMarginBottom: true,
							} ),
							createElement( TextControl, {
								label: __( 'Value', 'stats-umami' ),
								value: pair.value || '',
								onChange: ( value ) => setPair( index, 'value', value ),
								__next40pxDefaultSize: true,
								__nextHasNoMarginBottom: true,
							} ),
							createElement(
								Button,
								{ variant: 'secondary', isDestructive: true, onClick: () => removePair( index ) },
								__( 'Remove', 'stats-umami' )
							)
						)
					),
					createElement(
						Button,
						{ variant: 'secondary', onClick: addPair, key: 'stats-umami-add-pair' },
						__( 'Add data', 'stats-umami' )
					),
			  ]
			: [];

		return createElement(
			Fragment,
			null,
			createElement( BlockEdit, props ),
			createElement(
				InspectorControls,
				null,
				createElement(
					PanelBody,
					{ title: __( 'Umami Tracking', 'stats-umami' ), initialOpen: false },
					createElement( TextControl, {
						label: __( 'Event name', 'stats-umami' ),
						help: __( 'Fires a custom Umami event when this button is clicked.', 'stats-umami' ),
						value: umamiEvent,
						onChange: ( value ) => setAttributes( { umamiEvent: value } ),
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					} ),
					...dataPairFields
				)
			)
		);
	};
}, 'withUmamiInspectorControls' );

addFilter( 'editor.BlockEdit', 'stats-umami/with-inspector-controls', withUmamiInspectorControls );
