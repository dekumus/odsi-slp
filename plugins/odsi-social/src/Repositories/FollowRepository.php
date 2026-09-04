<?php
/**
 * Follows.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Directed follower → following edges.
 */
final class FollowRepository extends AbstractRepository {

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'follows';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'follower_id'  => '%d',
			'following_id' => '%d',
			'created_at'   => '%s',
		);
	}

	/**
	 * Whether the edge exists.
	 *
	 * @param int $follower_id  Follower.
	 * @param int $following_id Followed.
	 */
	public function exists( int $follower_id, int $following_id ): bool {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$table} WHERE follower_id = %d AND following_id = %d", $follower_id, $following_id ) );
	}

	/**
	 * Create the edge if missing.
	 *
	 * @param int $follower_id  Follower.
	 * @param int $following_id Followed.
	 *
	 * @return bool True when a new edge was created.
	 */
	public function add( int $follower_id, int $following_id ): bool {
		if ( $this->exists( $follower_id, $following_id ) ) {
			return false;
		}

		return $this->insert_row(
			array(
				'follower_id'  => $follower_id,
				'following_id' => $following_id,
				'created_at'   => $this->now(),
			)
		) > 0;
	}

	/**
	 * Remove the edge.
	 *
	 * @param int $follower_id  Follower.
	 * @param int $following_id Followed.
	 *
	 * @return bool True when an edge was removed.
	 */
	public function remove( int $follower_id, int $following_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $this->db->delete(
			$this->table(),
			array(
				'follower_id'  => $follower_id,
				'following_id' => $following_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Ids a user follows.
	 *
	 * @param int $user_id User id.
	 *
	 * @return int[]
	 */
	public function following_ids( int $user_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $this->db->get_col( $this->db->prepare( "SELECT following_id FROM {$table} WHERE follower_id = %d", $user_id ) ) );
	}

	/**
	 * Ids following a user.
	 *
	 * @param int $user_id User id.
	 *
	 * @return int[]
	 */
	public function follower_ids( int $user_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $this->db->get_col( $this->db->prepare( "SELECT follower_id FROM {$table} WHERE following_id = %d", $user_id ) ) );
	}

	/**
	 * Delete every edge touching a user.
	 *
	 * @param int $user_id User id.
	 *
	 * @return array{following: int[], followers: int[]}
	 */
	public function delete_user( int $user_id ): array {
		$result = array(
			'following' => $this->following_ids( $user_id ),
			'followers' => $this->follower_ids( $user_id ),
		);
		$table  = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query( $this->db->prepare( "DELETE FROM {$table} WHERE follower_id = %d OR following_id = %d", $user_id, $user_id ) );

		return $result;
	}
}
