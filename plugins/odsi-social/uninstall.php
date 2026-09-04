<?php
/**
 * Uninstall routine. Destroys data only when the owner opted in.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'ODSI\\Social\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

$odsi_social_settings = (array) get_option( \ODSI\Social\Support\Settings::OPTION, array() );

if ( empty( $odsi_social_settings['reset_data_on_uninstall'] ) ) {
	return;
}

\ODSI\Social\Database\Migrator::drop();
\ODSI\Social\Support\Capabilities::uninstall();

delete_option( \ODSI\Social\Support\Settings::OPTION );
