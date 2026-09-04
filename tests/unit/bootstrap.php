<?php
/**
 * Unit suite bootstrap.
 *
 * No WordPress is loaded. The plugin classes guard on ABSPATH, so it is defined
 * here to a harmless value; every WordPress function a class calls is stubbed
 * per test through Brain Monkey.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

// Time constants the services use; WordPress defines these in wp-includes/default-constants.php.
foreach (
	array(
		'MINUTE_IN_SECONDS' => 60,
		'HOUR_IN_SECONDS'   => 3600,
		'DAY_IN_SECONDS'    => 86400,
		'WEEK_IN_SECONDS'   => 604800,
	) as $odsi_constant => $odsi_value
) {
	if ( ! defined( $odsi_constant ) ) {
		define( $odsi_constant, $odsi_value );
	}
}

// Plugin-level constants normally set by each main plugin file.
foreach (
	array(
		'ODSI\\LMS\\VERSION'        => '0.1.0',
		'ODSI\\LMS\\PLUGIN_FILE'    => dirname( __DIR__, 2 ) . '/plugins/odsi-lms/odsi-lms.php',
		'ODSI\\Social\\VERSION'     => '0.1.0',
		'ODSI\\Social\\PLUGIN_FILE' => dirname( __DIR__, 2 ) . '/plugins/odsi-social/odsi-social.php',
		'ODSI\\Bridge\\VERSION'     => '0.1.0',
		'ODSI\\Bridge\\PLUGIN_FILE' => dirname( __DIR__, 2 ) . '/plugins/odsi-bridge/odsi-bridge.php',
	) as $odsi_constant => $odsi_value
) {
	if ( ! defined( $odsi_constant ) ) {
		define( $odsi_constant, $odsi_value );
	}
}
