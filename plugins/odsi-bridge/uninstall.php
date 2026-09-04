<?php
/**
 * Uninstall: remove the link table and settings, only when the site owner
 * opted in. Nothing in either other plugin is touched.
 *
 * @package ODSI\Bridge
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'ODSI\\Bridge\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

$odsi_bridge_settings = (array) get_option( \ODSI\Bridge\Support\Settings::OPTION, array() );

if ( empty( $odsi_bridge_settings['reset_data_on_uninstall'] ) ) {
	return;
}

\ODSI\Bridge\Database\Migrator::drop();
delete_option( \ODSI\Bridge\Support\Settings::OPTION );
