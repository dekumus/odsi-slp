<?php
/**
 * Integration suite bootstrap.
 *
 * Loads the WordPress core test framework, then every plugin in the monorepo as
 * a must-use plugin so that their `plugins_loaded` bootstraps run inside the
 * fully installed test site. Activation routines run explicitly afterwards
 * because must-use plugins never receive activation hooks.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

$odsi_root      = dirname( __DIR__, 2 );
$odsi_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $odsi_tests_dir ) {
	$odsi_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! is_readable( $odsi_tests_dir . '/includes/functions.php' ) ) {
	fwrite( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostics.
		STDERR,
		"WordPress test library not found at {$odsi_tests_dir}.\n" .
		"Run bin/install-wp-tests.sh, or set WP_TESTS_DIR. See docs/DEVELOPMENT.md.\n"
	);
	exit( 1 );
}

require_once $odsi_root . '/vendor/autoload.php';

define( 'WP_TESTS_CONFIG_FILE_PATH', $odsi_tests_dir . '/wp-tests-config.php' );

require_once $odsi_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $odsi_root ): void {
		foreach ( array( 'odsi-lms', 'odsi-social', 'odsi-bridge' ) as $slug ) {
			$file = "{$odsi_root}/plugins/{$slug}/{$slug}.php";

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	}
);

// Activation normally runs from register_activation_hook; must-use plugins
// never get one, so run it once the plugins have booted.
tests_add_filter(
	'plugins_loaded',
	static function (): void {
		foreach ( array( '\\ODSI\\LMS\\Installer', '\\ODSI\\Social\\Installer', '\\ODSI\\Bridge\\Installer' ) as $installer ) {
			if ( class_exists( $installer ) && method_exists( $installer, 'activate' ) ) {
				$installer::activate();
			}
		}
	},
	20
);

require $odsi_tests_dir . '/includes/bootstrap.php';
