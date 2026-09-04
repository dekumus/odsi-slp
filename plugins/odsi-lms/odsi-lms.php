<?php
/**
 * Plugin Name:       ODSI LMS
 * Plugin URI:        https://github.com/dekumus/odsi-slp
 * Description:       Course, lesson, quiz and progress engine for the ODSI social learning platform.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            ODSI
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       odsi-lms
 * Domain Path:       /languages
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.0';
const MIN_PHP     = '8.1';
const MIN_WP      = '6.4';
const PLUGIN_FILE = __FILE__;

/**
 * Verify the host environment can run the plugin.
 *
 * @return string[] List of human readable failure reasons. Empty when supported.
 */
function environment_errors(): array {
	$errors = array();

	if ( version_compare( PHP_VERSION, MIN_PHP, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version. */
			__( 'ODSI LMS requires PHP %1$s or newer. This site runs PHP %2$s.', 'odsi-lms' ),
			MIN_PHP,
			PHP_VERSION
		);
	}

	if ( version_compare( get_bloginfo( 'version' ), MIN_WP, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			__( 'ODSI LMS requires WordPress %1$s or newer. This site runs WordPress %2$s.', 'odsi-lms' ),
			MIN_WP,
			get_bloginfo( 'version' )
		);
	}

	return $errors;
}

/**
 * Register the bundled PSR-4 autoloader.
 *
 * Composer's autoloader is preferred when the plugin was built with `composer install`,
 * but the plugin must also run from a plain git checkout, so we ship a fallback.
 */
function register_autoloader(): void {
	$composer = __DIR__ . '/vendor/autoload.php';

	if ( is_readable( $composer ) ) {
		require_once $composer;

		return;
	}

	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = __NAMESPACE__ . '\\';

			if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
			}

			$relative = substr( $class_name, strlen( $prefix ) );
			$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

/**
 * Render an admin notice listing unmet requirements.
 */
function render_environment_notice(): void {
	$errors = environment_errors();

	if ( empty( $errors ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( implode( ' ', $errors ) )
	);
}

/**
 * Boot the plugin once WordPress has loaded all other plugins.
 */
function bootstrap(): void {
	if ( ! empty( environment_errors() ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_environment_notice' );

		return;
	}

	register_autoloader();

	Plugin::instance()->boot();
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap', 5 );

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( ! empty( environment_errors() ) ) {
			return;
		}

		register_autoloader();
		Installer::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( ! empty( environment_errors() ) ) {
			return;
		}

		register_autoloader();
		Installer::deactivate();
	}
);
