/**
 * Builds the editor bundles for each plugin with @wordpress/scripts' defaults.
 * Output lands next to the source under assets/build with an .asset.php the
 * PHP side reads for dependencies and versioning. One configuration per
 * plugin keeps each plugin's bundle inside its own directory.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

/**
 * Build one plugin's configuration.
 *
 * @param {string} plugin  Plugin directory name.
 * @param {Object} entries Entry name => source path relative to the plugin's assets/src.
 * @return {Object} Webpack configuration.
 */
function pluginConfig( plugin, entries ) {
	const entry = {};

	Object.keys( entries ).forEach( ( name ) => {
		entry[ name ] = path.resolve( __dirname, 'plugins', plugin, 'assets/src', entries[ name ] );
	} );

	return {
		...defaultConfig,
		name: plugin,
		entry,
		output: {
			...defaultConfig.output,
			path: path.resolve( __dirname, 'plugins', plugin, 'assets/build' ),
		},
	};
}

module.exports = [
	pluginConfig( 'odsi-lms', {
		'course-builder': 'course-builder/index.js',
		blocks: 'blocks/index.js',
	} ),
	pluginConfig( 'odsi-social', {
		blocks: 'blocks/index.js',
	} ),
];
