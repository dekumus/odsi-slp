<?php
/**
 * Schema installer / upgrader.
 *
 * @package ODSI\Bridge
 */

declare( strict_types = 1 );

namespace ODSI\Bridge\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's custom tables.
 */
final class Migrator {

	/**
	 * Create or update every table to match the current schema.
	 *
	 * `dbDelta()` is additive: it adds missing tables, columns and indexes but
	 * never drops them, so running this repeatedly is safe.
	 */
	public static function migrate(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::statements() as $statement ) {
			dbDelta( $statement );
		}

		update_option( Schema::VERSION_OPTION, Schema::DB_VERSION, false );
	}

	/**
	 * Whether the installed schema is older than the shipped one.
	 */
	public static function needs_migration(): bool {
		$installed = get_option( Schema::VERSION_OPTION, '0.0.0' );

		return version_compare( (string) $installed, Schema::DB_VERSION, '<' );
	}

	/**
	 * Run pending migrations on a normal page load.
	 *
	 * Plugins are sometimes updated by copying files over FTP or by a deploy that
	 * never fires the activation hook, so the check also runs at request time.
	 */
	public static function maybe_migrate(): void {
		if ( ! self::needs_migration() ) {
			return;
		}

		self::migrate();
	}

	/**
	 * Permanently drop every plugin table. Only ever called from uninstall.
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( Schema::all_tables() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( Schema::VERSION_OPTION );
	}
}
