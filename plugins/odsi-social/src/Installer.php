<?php
/**
 * Activation and deactivation routines.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social;

use ODSI\Social\Database\Migrator;
use ODSI\Social\Frontend\Router;
use ODSI\Social\PostTypes\GroupPostType;
use ODSI\Social\Support\Capabilities;
use ODSI\Social\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One-off work for activation and deactivation.
 */
final class Installer {

	/**
	 * Cron hook for daily maintenance.
	 */
	public const CRON_HOOK = 'odsi_social_daily_maintenance';

	/**
	 * Create tables, roles, rewrite rules and the cron schedule.
	 */
	public static function activate(): void {
		Migrator::migrate();
		Capabilities::install();
		Settings::seed();

		( new GroupPostType() )->register();

		// Rewrite rules need $wp_rewrite, which does not exist yet when activation
		// runs early (the test bootstrap, WP-CLI). Flush now when we can, otherwise
		// leave a flag for the router to flush on the next init.
		if ( isset( $GLOBALS['wp_rewrite'] ) ) {
			( new Router( new Settings() ) )->register_rewrites();
			flush_rewrite_rules();
		} else {
			update_option( Router::FLUSH_OPTION, '1', false );
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clean up transient state. Data is left untouched.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();

		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}
}
