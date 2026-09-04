<?php
/**
 * Group memberships.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * One row per (group, member) with a role and a status.
 */
final class GroupMemberRepository extends AbstractRepository {

	public const ROLE_ORGANISER = 'organiser';
	public const ROLE_MODERATOR = 'moderator';
	public const ROLE_MEMBER    = 'member';

	public const STATUS_ACTIVE  = 'active';
	public const STATUS_PENDING = 'pending';
	public const STATUS_INVITED = 'invited';
	public const STATUS_BANNED  = 'banned';

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'group_members';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'group_id'   => '%d',
			'user_id'    => '%d',
			'role'       => '%s',
			'status'     => '%s',
			'inviter_id' => '%d',
			'created_at' => '%s',
			'updated_at' => '%s',
		);
	}

	/**
	 * The membership row for a user in a group.
	 *
	 * @param int $group_id Group post id.
	 * @param int $user_id  User id.
	 */
	public function find_for( int $group_id, int $user_id ): ?object {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE group_id = %d AND user_id = %d", $group_id, $user_id ) );

		return $row ?: null;
	}

	/**
	 * Create or overwrite the row.
	 *
	 * @param int    $group_id   Group post id.
	 * @param int    $user_id    User id.
	 * @param string $role       Role.
	 * @param string $status     Status.
	 * @param int    $inviter_id Inviter, when invited.
	 *
	 * @return int Row id.
	 */
	public function put( int $group_id, int $user_id, string $role, string $status, int $inviter_id = 0 ): int {
		$existing = $this->find_for( $group_id, $user_id );
		$now      = $this->now();
		$data     = array(
			'role'       => $role,
			'status'     => $status,
			'inviter_id' => $inviter_id,
			'updated_at' => $now,
		);

		if ( $existing ) {
			$this->update_row( (int) $existing->id, $data );

			return (int) $existing->id;
		}

		return $this->insert_row(
			$data + array(
				'group_id'   => $group_id,
				'user_id'    => $user_id,
				'created_at' => $now,
			)
		);
	}

	/**
	 * Remove the row.
	 *
	 * @param int $group_id Group post id.
	 * @param int $user_id  User id.
	 */
	public function remove( int $group_id, int $user_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $this->db->delete(
			$this->table(),
			array(
				'group_id' => $group_id,
				'user_id'  => $user_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Whether the user is an active member.
	 *
	 * @param int $group_id Group post id.
	 * @param int $user_id  User id.
	 */
	public function is_active( int $group_id, int $user_id ): bool {
		$row = $this->find_for( $group_id, $user_id );

		return $row && self::STATUS_ACTIVE === $row->status;
	}

	/**
	 * The user's role, or '' when not an active member.
	 *
	 * @param int $group_id Group post id.
	 * @param int $user_id  User id.
	 */
	public function role_of( int $group_id, int $user_id ): string {
		$row = $this->find_for( $group_id, $user_id );

		return $row && self::STATUS_ACTIVE === $row->status ? (string) $row->role : '';
	}

	/**
	 * Group ids the user is active in.
	 *
	 * @param int $user_id User id.
	 *
	 * @return int[]
	 */
	public function group_ids_for( int $user_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $this->db->get_col( $this->db->prepare( "SELECT group_id FROM {$table} WHERE user_id = %d AND status = %s", $user_id, self::STATUS_ACTIVE ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Rows for a user in a given status (pending requests, invitations).
	 *
	 * @param int    $user_id User id.
	 * @param string $status  Status.
	 *
	 * @return object[]
	 */
	public function for_user( int $user_id, string $status ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND status = %s ORDER BY updated_at DESC", $user_id, $status ) );
	}

	/**
	 * Members of a group by status, optionally by role.
	 *
	 * @param int         $group_id Group post id.
	 * @param string      $status   Status.
	 * @param string|null $role     Role filter.
	 * @param int         $limit    Limit.
	 * @param int         $offset   Offset.
	 *
	 * @return object[]
	 */
	public function for_group( int $group_id, string $status = self::STATUS_ACTIVE, ?string $role = null, int $limit = 50, int $offset = 0 ): array {
		$table  = $this->table();
		$sql    = "SELECT * FROM {$table} WHERE group_id = %d AND status = %s";
		$params = array( $group_id, $status );

		if ( null !== $role ) {
			$sql     .= ' AND role = %s';
			$params[] = $role;
		}

		$sql     .= ' ORDER BY FIELD(role, %s, %s, %s), created_at ASC LIMIT %d OFFSET %d';
		$params[] = self::ROLE_ORGANISER;
		$params[] = self::ROLE_MODERATOR;
		$params[] = self::ROLE_MEMBER;
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $this->db->get_results( $this->db->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count rows in a group by status and optional role.
	 *
	 * @param int         $group_id Group post id.
	 * @param string      $status   Status.
	 * @param string|null $role     Role filter.
	 */
	public function count( int $group_id, string $status = self::STATUS_ACTIVE, ?string $role = null ): int {
		$table  = $this->table();
		$sql    = "SELECT COUNT(*) FROM {$table} WHERE group_id = %d AND status = %s";
		$params = array( $group_id, $status );

		if ( null !== $role ) {
			$sql     .= ' AND role = %s';
			$params[] = $role;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->db->get_var( $this->db->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete every row for a group.
	 *
	 * @param int $group_id Group post id.
	 */
	public function delete_group( int $group_id ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $this->db->delete( $this->table(), array( 'group_id' => $group_id ), array( '%d' ) );
	}

	/**
	 * Delete every row for a user.
	 *
	 * @param int $user_id User id.
	 *
	 * @return int[] Group ids the user was active in, so counts can be adjusted.
	 */
	public function delete_user( int $user_id ): array {
		$groups = $this->group_ids_for( $user_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->delete( $this->table(), array( 'user_id' => $user_id ), array( '%d' ) );

		return $groups;
	}
}
