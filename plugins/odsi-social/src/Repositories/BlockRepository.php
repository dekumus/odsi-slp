<?php
/**
 * Member blocks.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Directed blocker → blocked edges (SOC-MOD-001). Every read path asks "is
 * this pair blocked in either direction?" for the viewer against each author
 * on a page, so a member's full block set (both directions) is loaded once
 * per request and answered from memory afterwards.
 */
final class BlockRepository extends AbstractRepository {

	/**
	 * Per-request cache: user id => ids blocked by or blocking that user.
	 *
	 * @var array<int, array<int, true>>
	 */
	private array $cache = array();

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'blocks';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'blocker_id' => '%d',
			'blocked_id' => '%d',
			'created_at' => '%s',
		);
	}

	/**
	 * Drop the per-request cache.
	 */
	public function flush(): void {
		$this->cache = array();
	}

	/**
	 * Whether the blocker has blocked the blocked member (one direction).
	 *
	 * @param int $blocker_id Blocker.
	 * @param int $blocked_id Blocked.
	 */
	public function exists( int $blocker_id, int $blocked_id ): bool {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$table} WHERE blocker_id = %d AND blocked_id = %d", $blocker_id, $blocked_id ) );
	}

	/**
	 * Whether either member has blocked the other.
	 *
	 * @param int $a Member.
	 * @param int $b Member.
	 */
	public function is_blocked( int $a, int $b ): bool {
		if ( $a <= 0 || $b <= 0 || $a === $b ) {
			return false;
		}

		return isset( $this->ids_for( $a )[ $b ] );
	}

	/**
	 * Every member on either side of a block with this member, keyed by id.
	 * Cached per request: one query however many items a page holds.
	 *
	 * @param int $user_id Member.
	 *
	 * @return array<int, true>
	 */
	public function ids_for( int $user_id ): array {
		if ( isset( $this->cache[ $user_id ] ) ) {
			return $this->cache[ $user_id ];
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = (array) $this->db->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"(SELECT blocked_id AS other FROM {$table} WHERE blocker_id = %d) UNION (SELECT blocker_id AS other FROM {$table} WHERE blocked_id = %d)",
				$user_id,
				$user_id
			)
		);

		$set = array();

		foreach ( $ids as $id ) {
			$set[ (int) $id ] = true;
		}

		$this->cache[ $user_id ] = $set;

		return $set;
	}

	/**
	 * Rows a member has written, newest first (the settings page list).
	 *
	 * @param int $blocker_id Blocker.
	 *
	 * @return object[]
	 */
	public function blocking( int $blocker_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE blocker_id = %d ORDER BY created_at DESC, id DESC", $blocker_id ) );
	}

	/**
	 * Create the edge if missing.
	 *
	 * @param int $blocker_id Blocker.
	 * @param int $blocked_id Blocked.
	 *
	 * @return bool True when a new edge was created.
	 */
	public function add( int $blocker_id, int $blocked_id ): bool {
		if ( $this->exists( $blocker_id, $blocked_id ) ) {
			return false;
		}

		unset( $this->cache[ $blocker_id ], $this->cache[ $blocked_id ] );

		return $this->insert_row(
			array(
				'blocker_id' => $blocker_id,
				'blocked_id' => $blocked_id,
				'created_at' => $this->now(),
			)
		) > 0;
	}

	/**
	 * Remove the edge.
	 *
	 * @param int $blocker_id Blocker.
	 * @param int $blocked_id Blocked.
	 *
	 * @return bool True when an edge was removed.
	 */
	public function remove( int $blocker_id, int $blocked_id ): bool {
		unset( $this->cache[ $blocker_id ], $this->cache[ $blocked_id ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $this->db->delete(
			$this->table(),
			array(
				'blocker_id' => $blocker_id,
				'blocked_id' => $blocked_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Delete every edge touching a user (account deletion).
	 *
	 * @param int $user_id User id.
	 */
	public function delete_user( int $user_id ): int {
		$table = $this->table();
		$this->flush();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->query( $this->db->prepare( "DELETE FROM {$table} WHERE blocker_id = %d OR blocked_id = %d", $user_id, $user_id ) );
	}

	/**
	 * SQL excluding authors blocked either way for a viewer, over alias `a`.
	 *
	 * @param int $viewer_id Viewer.
	 *
	 * @return array{sql: string, params: array<int>}
	 */
	public function exclusion_clause( int $viewer_id ): array {
		$table = $this->table();

		return array(
			'sql'    => "a.user_id NOT IN (SELECT b.blocked_id FROM {$table} b WHERE b.blocker_id = %d UNION SELECT b.blocker_id FROM {$table} b WHERE b.blocked_id = %d)",
			'params' => array( $viewer_id, $viewer_id ),
		);
	}
}
