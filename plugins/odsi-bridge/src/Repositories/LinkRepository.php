<?php
/**
 * Course ↔ group links.
 *
 * @package ODSI\Bridge
 */

declare( strict_types = 1 );

namespace ODSI\Bridge\Repositories;

use ODSI\Bridge\Database\Schema;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * One row per link; each side unique.
 */
final class LinkRepository {

	/**
	 * Database.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null $db Database.
	 */
	public function __construct( ?wpdb $db = null ) {
		global $wpdb;

		$this->db = $db ?? $wpdb;
	}

	/**
	 * Table name.
	 */
	public function table(): string {
		return Schema::table( 'course_groups' );
	}

	/**
	 * Group linked to a course, or 0.
	 *
	 * @param int $course_id Course.
	 */
	public function group_for( int $course_id ): int {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var( $this->db->prepare( "SELECT group_id FROM {$table} WHERE course_id = %d", $course_id ) );
	}

	/**
	 * Course linked to a group, or 0.
	 *
	 * @param int $group_id Group.
	 */
	public function course_for( int $group_id ): int {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var( $this->db->prepare( "SELECT course_id FROM {$table} WHERE group_id = %d", $group_id ) );
	}

	/**
	 * Link, replacing any existing link on either side.
	 *
	 * @param int $course_id Course.
	 * @param int $group_id  Group.
	 */
	public function link( int $course_id, int $group_id ): bool {
		$this->unlink_course( $course_id );
		$this->unlink_group( $group_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $this->db->insert(
			$this->table(),
			array(
				'course_id'  => $course_id,
				'group_id'   => $group_id,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s' )
		);
	}

	/**
	 * Remove a course's link.
	 *
	 * @param int $course_id Course.
	 */
	public function unlink_course( int $course_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $this->db->delete( $this->table(), array( 'course_id' => $course_id ), array( '%d' ) );
	}

	/**
	 * Remove a group's link.
	 *
	 * @param int $group_id Group.
	 */
	public function unlink_group( int $group_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $this->db->delete( $this->table(), array( 'group_id' => $group_id ), array( '%d' ) );
	}
}
