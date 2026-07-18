/**
 * Points @wordpress/scripts at blocks/src -> blocks/build instead of its
 * default src -> build, because src/ is already the plugin's PHP source
 * tree (StatsUmami\ PSR-4 root).
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'blocks/src/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'blocks/build' ),
	},
};
