/**
 * Builds the editor bundles for each plugin with @wordpress/scripts' defaults.
 * Output lands next to the source under assets/build with an .asset.php the
 * PHP side reads for dependencies and versioning.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'course-builder': path.resolve( __dirname, 'plugins/odsi-lms/assets/src/course-builder/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'plugins/odsi-lms/assets/build' ),
	},
};
