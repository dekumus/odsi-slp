<?php
/**
 * Reactions.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * One row per (activity, member).
 */
final class ReactionRepository extends AbstractRepository {

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'reactions';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'activity_id' => '%d',
			'user_id'     => '%d',
			'reaction'    => '%s',
			'created_at'  => '%s',
		);
	}

	/**
	 * The member's reaction on an item.
	 *
	 * @param int $activity_id Activity id.
	 * @param int $user_id     User id.
	 */
	public function find_for( int $activity_id, int $user_id ): ?object {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE activity_id = %d AND user_id = %d", $activity_id, $user_id ) );

		return $row ?: null;
	}

	/**
	 * Set or replace the member's reaction.
	 *
	 * @param int    $activity_id Activity id.
	 * @param int    $user_id     User id.
	 * @param string $reaction    Type.
	 *
	 * @return string `created`, `replaced` or `unchanged`.
	 */
	public function put( int $activity_id, int $user_id, string $reaction ): string {
		$existing = $this->find_for( $activity_id, $user_id );

		if ( $existing ) {
			if ( $existing->reaction === $reaction ) {
				return 'unchanged';
			}

			$this->update_row( (int) $existing->id, array( 'reaction' => $reaction ) );

			return 'replaced';
		}

		$this->insert_row(
			array(
				'activity_id' => $activity_id,
				'user_id'     => $user_id,
				'reaction'    => $reaction,
				'created_at'  => $this->now(),
			)
		);

		return 'created';
	}

	/**
	 * Remove the member's reaction.
	 *
	 * @param int $activity_id Activity id.
	 * @param int $user_id     User id.
	 */
	public function remove( int $activity_id, int $user_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $this->db->delete(
			$this->table(),
			array(
				'activity_id' => $activity_id,
				'user_id'     => $user_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * The viewer's reactions across several items, keyed by activity id.
	 *
	 * @param int[] $activity_ids Activity ids.
	 * @param int   $user_id      Viewer id.
	 *
	 * @return array<int, string>
	 */
	public function for_viewer( array $activity_ids, int $user_id ): array {
		$activity_ids = array_values( array_unique( array_map( 'intval', $activity_ids ) ) );

		if ( array() === $activity_ids || $user_id <= 0 ) {
			return array();
		}

		$table = $this->table();
		$in    = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $this->db->get_results( $this->db->prepare( "SELECT activity_id, reaction FROM {$table} WHERE user_id = %d AND activity_id IN ({$in})", array_merge( array( $user_id ), $activity_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$map = array();

		foreach ( $rows as $row ) {
			$map[ (int) $row->activity_id ] = (string) $row->reaction;
		}

		return $map;
	}

	/**
	 * User ids who reacted to an item, most recent first.
	 *
	 * @param int $activity_id Activity id.
	 * @param int $limit       Limit.
	 *
	 * @return int[]
	 */
	public function user_ids_for( int $activity_id, int $limit = 20 ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $this->db->get_col( $this->db->prepare( "SELECT user_id FROM {$table} WHERE activity_id = %d ORDER BY created_at DESC, id DESC LIMIT %d", $activity_id, $limit ) ) );
	}

	/**
	 * Delete every reaction on a set of items.
	 *
	 * @param int[] $activity_ids Activity ids.
	 */
	public function delete_for_items( array $activity_ids ): void {
		$activity_ids = array_values( array_unique( array_map( 'intval', $activity_ids ) ) );

		if ( array() === $activity_ids ) {
			return;
		}

		$table = $this->table();
		$in    = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query( $this->db->prepare( "DELETE FROM {$table} WHERE activity_id IN ({$in})", $activity_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete every reaction by a user.
	 *
	 * @param int $user_id User id.
	 *
	 * @return int[] Activity ids affected, so counts can be adjusted.
	 */
	public function delete_user( int $user_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', (array) $this->db->get_col( $this->db->prepare( "SELECT activity_id FROM {$table} WHERE user_id = %d", $user_id ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->delete( $this->table(), array( 'user_id' => $user_id ), array( '%d' ) );

		return $ids;
	}
}
