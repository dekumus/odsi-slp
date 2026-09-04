<?php
/**
 * Bridge tables.
 *
 * @package ODSI\Bridge
 */

declare( strict_types = 1 );

namespace ODSI\Bridge\Database;

defined( 'ABSPATH' ) || exit;

/**
 * The bridge's own storage: the course ↔ group link.
 */
final class Schema {

	public const DB_VERSION     = '1.0.0';
	public const VERSION_OPTION = 'odsi_bridge_db_version';

	/**
	 * Short keys to unprefixed names.
	 *
	 * @var array<string, string>
	 */
	private const TABLES = array(
		'course_groups' => 'odsi_bridge_course_groups',
	);

	/**
	 * Prefixed table name.
	 *
	 * @param string $key Short key.
	 */
	public static function table( string $key ): string {
		global $wpdb;

		return isset( self::TABLES[ $key ] ) ? $wpdb->prefix . self::TABLES[ $key ] : '';
	}

	/**
	 * Every table.
	 *
	 * @return string[]
	 */
	public static function all_tables(): array {
		return array_map( array( self::class, 'table' ), array_keys( self::TABLES ) );
	}

	/**
	 * The dbDelta statements.
	 *
	 * @return string[]
	 */
	public static function statements(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();
		$table   = self::table( 'course_groups' );

		return array(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				course_id bigint(20) unsigned NOT NULL,
				group_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY course_id (course_id),
				UNIQUE KEY group_id (group_id)
			) {$collate};",
		);
	}
}
