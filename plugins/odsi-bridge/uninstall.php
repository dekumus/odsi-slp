<?php
/**
 * Uninstall: remove the link table and settings. Nothing in either other
 * plugin is touched.
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

\ODSI\Bridge\Database\Migrator::drop();
delete_option( \ODSI\Bridge\Support\Settings::OPTION );
