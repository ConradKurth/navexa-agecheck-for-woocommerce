/**
 * wp-scripts build for the checkout block: the editor entry comes from
 * block.json (editorScript), the front-end entry (registered with WooCommerce
 * through IntegrationInterface, not block.json) is added here.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const defaultEntry =
	typeof defaultConfig.entry === 'function' ? defaultConfig.entry() : defaultConfig.entry;

module.exports = {
	...defaultConfig,
	entry: {
		...defaultEntry,
		'verify-age/frontend': path.resolve( __dirname, 'assets/src/blocks/verify-age/frontend.js' ),
	},
};
