<?php
/**
 * Uninstall routine.
 *
 * Data is only destroyed when the site owner explicitly opted in, because
 * deactivating and reinstalling a plugin should never silently delete a
 * course catalogue or a learner's history.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The main plugin file is deliberately not loaded here: it registers hooks that
// have no meaning during uninstall. A local autoloader is enough.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'ODSI\\LMS\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

$odsi_lms_settings = (array) get_option( \ODSI\LMS\Installer::SETTINGS_OPTION, array() );

if ( empty( $odsi_lms_settings['reset_data_on_uninstall'] ) ) {
	return;
}

\ODSI\LMS\Database\Migrator::drop();
\ODSI\LMS\Support\Capabilities::uninstall();

delete_option( \ODSI\LMS\Installer::SETTINGS_OPTION );
