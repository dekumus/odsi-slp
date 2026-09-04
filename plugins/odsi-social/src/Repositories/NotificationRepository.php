<?php
/**
 * Notifications.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Per-recipient notification rows with write-time collapse (ADR-014).
 */
final class NotificationRepository extends AbstractRepository {

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'notifications';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'user_id'           => '%d',
			'actor_id'          => '%d',
			'component'         => '%s',
			'action'            => '%s',
			'item_id'           => '%d',
			'secondary_item_id' => '%d',
			'collapse_key'      => '%s',
			'actor_count'       => '%d',
			'is_new'            => '%d',
			'date_notified'     => '%s',
			'date_read'         => '%s',
		);
	}

	/**
	 * Insert a notification, folding into an existing unread row when a
	 * collapse key is given and one exists.
	 *
	 * @param array<string, mixed> $data     Columns; `collapse_key` null for non-collapsing.
	 *
	 * @return array{id: int, collapsed: bool}
	 */
	public function upsert( array $data ): array {
		$now  = $this->now();
		$data = $data + array(
			'actor_count'   => 1,
			'is_new'        => 1,
			'date_notified' => $now,
		);

		if ( empty( $data['collapse_key'] ) ) {
			$data['collapse_key'] = null;

			return array(
				'id'        => $this->insert_row( $data ),
				'collapsed' => false,
			);
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND collapse_key = %s", (int) $data['user_id'], (string) $data['collapse_key'] ) );

		if ( $existing ) {
			$bump = (int) $existing->actor_id !== (int) $data['actor_id'];

			$this->update_row(
				(int) $existing->id,
				array(
					'actor_id'      => (int) $data['actor_id'],
					'actor_count'   => (int) $existing->actor_count + ( $bump ? 1 : 0 ),
					'date_notified' => $now,
					'is_new'        => 1,
				)
			);

			return array(
				'id'        => (int) $existing->id,
				'collapsed' => true,
			);
		}

		return array(
			'id'        => $this->insert_row( $data ),
			'collapsed' => false,
		);
	}

	/**
	 * A page of a user's notifications, newest first.
	 *
	 * @param int  $user_id     User id.
	 * @param bool $unread_only Unread only.
	 * @param int  $limit       Limit.
	 * @param int  $offset      Offset.
	 *
	 * @return object[]
	 */
	public function for_user( int $user_id, bool $unread_only = false, int $limit = 20, int $offset = 0 ): array {
		$table  = $this->table();
		$sql    = "SELECT * FROM {$table} WHERE user_id = %d";
		$params = array( $user_id );

		if ( $unread_only ) {
			$sql .= ' AND is_new = 1';
		}

		$sql     .= ' ORDER BY date_notified DESC, id DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $this->db->get_results( $this->db->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Total rows for a user, for pagination.
	 *
	 * @param int  $user_id     User id.
	 * @param bool $unread_only Unread only.
	 */
	public function count_for_user( int $user_id, bool $unread_only = false ): int {
		$table = $this->table();
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE user_id = %d" . ( $unread_only ? ' AND is_new = 1' : '' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->db->get_var( $this->db->prepare( $sql, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Mark a user's unread rows about one item read (opening a thread reads
	 * its "new message" notification).
	 *
	 * @param int    $user_id   User id.
	 * @param string $component Component.
	 * @param string $action    Action.
	 * @param int    $item_id   Item id.
	 *
	 * @return int Rows changed.
	 */
	public function mark_read_for_item( int $user_id, string $component, string $action, int $item_id ): int {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"UPDATE {$table} SET is_new = 0, collapse_key = NULL, date_read = %s WHERE user_id = %d AND is_new = 1 AND component = %s AND action = %s AND item_id = %d",
				$this->now(),
				$user_id,
				$component,
				$action,
				$item_id
			)
		);
	}

	/**
	 * Unread count.
	 *
	 * @param int $user_id User id.
	 */
	public function unread_count( int $user_id ): int {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_new = 1", $user_id ) );
	}

	/**
	 * Mark rows read. Nulling the collapse key lets the next event open a new row.
	 *
	 * @param int        $user_id User id.
	 * @param int[]|null $ids     Specific ids, or null for all.
	 *
	 * @return int Rows changed.
	 */
	public function mark_read( int $user_id, ?array $ids = null ): int {
		$table  = $this->table();
		$sql    = "UPDATE {$table} SET is_new = 0, collapse_key = NULL, date_read = %s WHERE user_id = %d AND is_new = 1";
		$params = array( $this->now(), $user_id );

		if ( null !== $ids ) {
			$ids = array_values( array_map( 'intval', $ids ) );

			if ( array() === $ids ) {
				return 0;
			}

			$sql   .= ' AND id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params = array_merge( $params, $ids );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->db->query( $this->db->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete every notification about an item.
	 *
	 * @param string $component Component.
	 * @param int    $item_id   Item id.
	 *
	 * @return int[] Affected user ids, for cache invalidation.
	 */
	public function delete_for_item( string $component, int $item_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$users = array_map( 'intval', (array) $this->db->get_col( $this->db->prepare( "SELECT DISTINCT user_id FROM {$table} WHERE component = %s AND item_id = %d", $component, $item_id ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->delete(
			$this->table(),
			array(
				'component' => $component,
				'item_id'   => $item_id,
			),
			array( '%s', '%d' )
		);

		return $users;
	}

	/**
	 * Delete a user's own notifications.
	 *
	 * @param int $user_id User id.
	 */
	public function delete_user( int $user_id ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $this->db->delete( $this->table(), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/**
	 * Delete read notifications older than a cutoff.
	 *
	 * @param string $before MySQL datetime.
	 *
	 * @return int Rows removed.
	 */
	public function purge_read_before( string $before ): int {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->query( $this->db->prepare( "DELETE FROM {$table} WHERE is_new = 0 AND date_notified < %s", $before ) );
	}
}
