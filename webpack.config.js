/**
 * Webpack configuration for the plugin's compiled scripts: the
 * checkout-block scripts (issue #74) and the order screen fulfillment app
 * (issue #182).
 *
 * Extends the @wordpress/scripts default config with:
 * - explicit entries under src/ (dev tooling at the repo root, like
 *   composer.json), built into smart-send-logistics/build/ so the shipped
 *   plugin folder carries the output and the SVN deploy needs no change;
 * - @woocommerce/dependency-extraction-webpack-plugin instead of the
 *   default WordPress one, so @woocommerce/* imports (not just
 *   @wordpress/*) become externals listed in the generated *.asset.php.
 *
 * The build output is committed; .github/workflows/js-build.yml fails when
 * the committed build/ no longer matches src/.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooCommerceDependencyExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );

module.exports = {
	...defaultConfig,
	entry: {
		'pickup-point-block/index': path.resolve( __dirname, 'src/pickup-point-block/index.js' ),
		'pickup-point-block/frontend': path.resolve( __dirname, 'src/pickup-point-block/frontend.js' ),
		// The order meta box app. Its files opt into the classic JSX runtime
		// (pragma comments), so the bundle depends on wp-element rather than
		// the react-jsx-runtime handle WordPress only registers from 6.6 (#183).
		'order-fulfillment/index': path.resolve( __dirname, 'src/order-fulfillment/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'smart-send-logistics/build' ),
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new WooCommerceDependencyExtractionWebpackPlugin(),
	],
};
