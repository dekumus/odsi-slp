<?php
/**
 * Activity rows.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Repositories;

use ODSI\Social\Database\Schema;
use ODSI\Social\Support\Cursor;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the activity table. Feed predicates are supplied by callers
 * (the Feed service composes scope + privacy); this class only knows SQL shape.
 */
final class ActivityRepository extends AbstractRepository {

	public const STATUS_PUBLISHED = 'published';
	public const STATUS_HIDDEN    = 'hidden';

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'activity';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'user_id'           => '%d',
			'component'         => '%s',
			'type'              => '%s',
			'content'           => '%s',
			'parent_id'         => '%d',
			'group_id'          => '%d',
			'primary_item_id'   => '%d',
			'secondary_item_id' => '%d',
			'privacy'           => '%s',
			'status'            => '%s',
			'external_id'       => '%s',
			'comment_count'     => '%d',
			'reaction_count'    => '%d',
			'is_edited'         => '%d',
			'date_recorded'     => '%s',
			'date_updated'      => '%s',
		);
	}

	/**
	 * Insert a row.
	 *
	 * @param array<string, mixed> $data Columns.
	 *
	 * @return int New id.
	 */
	public function insert( array $data ): int {
		$now = $this->now();

		return $this->insert_row(
			$data + array(
				'date_recorded' => $now,
				'date_updated'  => $now,
			)
		);
	}

	/**
	 * Update a row.
	 *
	 * @param int                  $id   Activity id.
	 * @param array<string, mixed> $data Columns.
	 */
	public function update( int $id, array $data ): bool {
		return $this->update_row( $id, $data + array( 'date_updated' => $this->now() ) );
	}

	/**
	 * Find by idempotency key.
	 *
	 * @param string $component   Component.
	 * @param string $external_id External id.
	 */
	public function find_external( string $component, string $external_id ): ?object {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE component = %s AND external_id = %s", $component, $external_id ) );

		return $row ?: null;
	}

	/**
	 * Fetch several rows by id, keyed by id.
	 *
	 * @param int[] $ids Ids.
	 *
	 * @return array<int, object>
	 */
	public function find_many( array $ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$table = $this->table();
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE id IN ({$in})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$by_id = array();

		foreach ( $rows as $row ) {
			$by_id[ (int) $row->id ] = $row;
		}

		return $by_id;
	}

	/**
	 * A page of top-level items matching a predicate, newest first, plus one
	 * extra row so the caller knows whether a next page exists.
	 *
	 * @param string       $where  SQL predicate over aliases `a` (activity) and `g` (groups index).
	 * @param array<mixed> $params Prepared parameters for `$where`.
	 * @param int          $limit  Page size.
	 * @param string       $cursor Opaque cursor, or ''.
	 *
	 * @return object[] Up to `$limit + 1` rows.
	 */
	public function page( string $where, array $params, int $limit, string $cursor = '' ): array {
		$table  = $this->table();
		$groups = Schema::table( 'groups' );
		$after  = Cursor::decode( $cursor );
		$sql    = "SELECT a.* FROM {$table} a LEFT JOIN {$groups} g ON g.post_id = a.group_id
				   WHERE a.parent_id = 0 AND a.status = %s AND ({$where})";
		$args   = array_merge( array( self::STATUS_PUBLISHED ), $params );

		if ( $after ) {
			$sql   .= ' AND (a.date_recorded < %s OR (a.date_recorded = %s AND a.id < %d))';
			$args[] = $after['timestamp'];
			$args[] = $after['timestamp'];
			$args[] = $after['id'];
		}

		$sql   .= ' ORDER BY a.date_recorded DESC, a.id DESC LIMIT %d';
		$args[] = $limit + 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $this->db->get_results( $this->db->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * The latest N comments for each of several parents, in one query.
	 *
	 * @param int[] $parent_ids Parent ids.
	 * @param int   $per_parent Comments per parent.
	 *
	 * @return array<int, object[]> Parent id => comments, newest first.
	 */
	public function latest_comments( array $parent_ids, int $per_parent = 3 ): array {
		$parent_ids = array_values( array_unique( array_map( 'intval', $parent_ids ) ) );

		if ( array() === $parent_ids ) {
			return array();
		}

		$table = $this->table();
		$parts = array();
		$args  = array();

		foreach ( $parent_ids as $parent_id ) {
			$parts[] = "(SELECT * FROM {$table} WHERE parent_id = %d AND status = %s ORDER BY date_recorded DESC, id DESC LIMIT %d)";
			$args[]  = $parent_id;
			$args[]  = self::STATUS_PUBLISHED;
			$args[]  = $per_parent;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $this->db->get_results( $this->db->prepare( implode( ' UNION ALL ', $parts ), $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$grouped = array_fill_keys( $parent_ids, array() );

		foreach ( $rows as $row ) {
			$grouped[ (int) $row->parent_id ][] = $row;
		}

		return $grouped;
	}

	/**
	 * All comments of an item, oldest first.
	 *
	 * @param int $parent_id Item id.
	 * @param int $limit     Limit.
	 * @param int $offset    Offset.
	 *
	 * @return object[]
	 */
	public function comments( int $parent_id, int $limit = 100, int $offset = 0 ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE parent_id = %d AND status = %s ORDER BY date_recorded ASC, id ASC LIMIT %d OFFSET %d",
				$parent_id,
				self::STATUS_PUBLISHED,
				$limit,
				$offset
			)
		);
	}

	/**
	 * Ids of every comment under an item.
	 *
	 * @param int $parent_id Item id.
	 *
	 * @return int[]
	 */
	public function comment_ids( int $parent_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $this->db->get_col( $this->db->prepare( "SELECT id FROM {$table} WHERE parent_id = %d", $parent_id ) ) );
	}

	/**
	 * Add a delta to a counter.
	 *
	 * @param int    $id     Activity id.
	 * @param string $column `comment_count` or `reaction_count`.
	 * @param int    $delta  Delta.
	 */
	public function adjust( int $id, string $column, int $delta ): void {
		if ( ! in_array( $column, array( 'comment_count', 'reaction_count' ), true ) ) {
			return;
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query( $this->db->prepare( "UPDATE {$table} SET {$column} = GREATEST(0, CAST({$column} AS SIGNED) + %d) WHERE id = %d", $delta, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Ids of every item and comment in a group.
	 *
	 * @param int $group_id Group post id.
	 *
	 * @return int[]
	 */
	public function ids_in_group( int $group_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $this->db->get_col( $this->db->prepare( "SELECT id FROM {$table} WHERE group_id = %d", $group_id ) ) );
	}

	/**
	 * Ids of every item and comment by an author.
	 *
	 * @param int $user_id User id.
	 *
	 * @return int[]
	 */
	public function ids_by_user( int $user_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $this->db->get_col( $this->db->prepare( "SELECT id FROM {$table} WHERE user_id = %d", $user_id ) ) );
	}

	/**
	 * Bulk update rows in a group (visibility changes).
	 *
	 * @param int                  $group_id Group post id.
	 * @param array<string, mixed> $data     Columns.
	 */
	public function update_group_items( int $group_id, array $data ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->update( $this->table(), $data, array( 'group_id' => $group_id ), $this->formats_for( $data ), array( '%d' ) );
	}

	/**
	 * Count top-level items by an author.
	 *
	 * @param int $user_id User id.
	 */
	public function count_items_by( int $user_id ): int {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND parent_id = 0 AND status = %s", $user_id, self::STATUS_PUBLISHED ) );
	}
}
