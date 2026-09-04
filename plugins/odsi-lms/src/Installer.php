<?php
/**
 * Activation and deactivation routines.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS;

use ODSI\LMS\Database\Migrator;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\PostTypes\Taxonomies;
use ODSI\LMS\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the one-off work that activation and deactivation need.
 */
final class Installer {

	/**
	 * Option holding first-activation defaults.
	 */
	public const SETTINGS_OPTION = 'odsi_lms_settings';

	/**
	 * Cron hook that expires lapsed enrollments.
	 */
	public const CRON_HOOK = 'odsi_lms_daily_maintenance';

	/**
	 * Create tables, roles and rewrite rules.
	 */
	public static function activate(): void {
		Migrator::migrate();
		Capabilities::install();
		self::seed_settings();

		// Post types are not registered yet during activation, so register them
		// here before flushing or the new permalinks will 404.
		( new Taxonomies() )->register();
		( new PostTypes() )->register();
		\ODSI\LMS\Certificates\Certificates::register_rewrite();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clean up transient state. Data and tables are left untouched.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();

		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Default settings, written only on first activation.
	 */
	private static function seed_settings(): void {
		if ( false !== get_option( self::SETTINGS_OPTION, false ) ) {
			return;
		}

		add_option( self::SETTINGS_OPTION, \ODSI\LMS\Support\Settings::defaults() );
	}
}
