<?php
/**
 * Plugin Name:       ODSI Bridge
 * Plugin URI:        https://github.com/dekumus/odsi-slp
 * Description:       Connects ODSI LMS and ODSI Social: course activity in the feed, groups linked to courses, shared progress.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  odsi-lms, odsi-social
 * Author:            ODSI
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       odsi-bridge
 * Domain Path:       /languages
 *
 * @package ODSI\Bridge
 */

declare( strict_types = 1 );

namespace ODSI\Bridge;

defined( 'ABSPATH' ) || exit;

const VERSION            = '0.1.0';
const MIN_PHP            = '8.1';
const MIN_WP             = '6.4';
const MIN_LMS_VERSION    = '0.1.0';
const MIN_SOCIAL_VERSION = '0.1.0';
const PLUGIN_FILE        = __FILE__;

/**
 * Reasons the bridge cannot run here. Empty when it can.
 *
 * @return string[]
 */
function environment_errors(): array {
	$errors = array();

	if ( version_compare( PHP_VERSION, MIN_PHP, '<' ) ) {
		/* translators: %s: required PHP version. */
		$errors[] = sprintf( __( 'ODSI Bridge requires PHP %s or newer.', 'odsi-bridge' ), MIN_PHP );
	}

	if ( version_compare( get_bloginfo( 'version' ), MIN_WP, '<' ) ) {
		/* translators: %s: required WordPress version. */
		$errors[] = sprintf( __( 'ODSI Bridge requires WordPress %s or newer.', 'odsi-bridge' ), MIN_WP );
	}

	return $errors;
}

/**
 * Reasons the bridge's two dependencies are not satisfied. Empty when they are.
 *
 * Checked on `plugins_loaded` after both plugins have had their chance to
 * define themselves, so a missing plugin is detected by its absent namespace
 * constant rather than by its file path.
 *
 * @return string[]
 */
function dependency_errors(): array {
	$errors = array();

	if ( ! defined( 'ODSI\\LMS\\VERSION' ) ) {
		$errors[] = __( 'ODSI Bridge needs the ODSI LMS plugin to be active.', 'odsi-bridge' );
	} elseif ( version_compare( (string) constant( 'ODSI\\LMS\\VERSION' ), MIN_LMS_VERSION, '<' ) ) {
		/* translators: %s: required version. */
		$errors[] = sprintf( __( 'ODSI Bridge needs ODSI LMS %s or newer.', 'odsi-bridge' ), MIN_LMS_VERSION );
	}

	if ( ! defined( 'ODSI\\Social\\VERSION' ) ) {
		$errors[] = __( 'ODSI Bridge needs the ODSI Social plugin to be active.', 'odsi-bridge' );
	} elseif ( version_compare( (string) constant( 'ODSI\\Social\\VERSION' ), MIN_SOCIAL_VERSION, '<' ) ) {
		/* translators: %s: required version. */
		$errors[] = sprintf( __( 'ODSI Bridge needs ODSI Social %s or newer.', 'odsi-bridge' ), MIN_SOCIAL_VERSION );
	}

	return $errors;
}

/**
 * Register the bundled PSR-4 autoloader.
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

			$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

/**
 * Show why the bridge is not running, and switch it off so it stops trying.
 *
 * @param string[] $errors Reasons.
 */
function stand_down( array $errors ): void {
	add_action(
		'admin_notices',
		static function () use ( $errors ): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s %s</p></div>',
				esc_html( implode( ' ', $errors ) ),
				esc_html__( 'ODSI Bridge has been deactivated; activate it again once both are available.', 'odsi-bridge' )
			);
		}
	);

	add_action(
		'admin_init',
		static function (): void {
			// Only a real admin page load by someone who may manage plugins
			// switches the bridge off; admin-ajax, admin-post and cron never do.
			if ( wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$basename = plugin_basename( __FILE__ );

			deactivate_plugins( $basename, false, is_multisite() && is_plugin_active_for_network( $basename ) );
		}
	);
}

/**
 * Boot after both dependencies have loaded (they boot at priority 5).
 */
function bootstrap(): void {
	$errors = array_merge( environment_errors(), dependency_errors() );

	if ( ! empty( $errors ) ) {
		stand_down( $errors );

		return;
	}

	register_autoloader();

	Plugin::instance()->boot();
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap', 10 );

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
		register_autoloader();
		Installer::deactivate();
	}
);
