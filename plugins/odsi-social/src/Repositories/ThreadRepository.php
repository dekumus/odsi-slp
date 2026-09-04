<?php
/**
 * Message threads and participants.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Repositories;

use ODSI\Social\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Threads plus their per-participant rows.
 */
final class ThreadRepository extends AbstractRepository {

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'threads';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'pair_key'        => '%s',
			'last_message_id' => '%d',
			'last_message_at' => '%s',
			'message_count'   => '%d',
			'created_at'      => '%s',
		);
	}

	/**
	 * Key for a two-party thread.
	 *
	 * @param int $a User id.
	 * @param int $b User id.
	 */
	public static function pair_key( int $a, int $b ): string {
		return $a < $b ? "{$a}:{$b}" : "{$b}:{$a}";
	}

	/**
	 * Find the thread between two members.
	 *
	 * @param int $a User id.
	 * @param int $b User id.
	 */
	public function find_pair( int $a, int $b ): ?object {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE pair_key = %s", self::pair_key( $a, $b ) ) );

		return $row ?: null;
	}

	/**
	 * Create a thread with its participants.
	 *
	 * @param int[]       $user_ids Participants.
	 * @param string|null $pair_key Pair key for two-party threads.
	 *
	 * @return int Thread id.
	 */
	public function create( array $user_ids, ?string $pair_key ): int {
		$now = $this->now();
		$id  = $this->insert_row(
			array(
				'pair_key'        => $pair_key,
				'last_message_at' => $now,
				'created_at'      => $now,
			)
		);

		foreach ( array_unique( array_map( 'intval', $user_ids ) ) as $user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->db->insert(
				Schema::table( 'thread_participants' ),
				array(
					'thread_id' => $id,
					'user_id'   => $user_id,
				),
				array( '%d', '%d' )
			);
		}

		return $id;
	}

	/**
	 * The participant row for a user in a thread.
	 *
	 * @param int $thread_id Thread id.
	 * @param int $user_id   User id.
	 */
	public function participant( int $thread_id, int $user_id ): ?object {
		$table = Schema::table( 'thread_participants' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE thread_id = %d AND user_id = %d", $thread_id, $user_id ) );

		return $row ?: null;
	}

	/**
	 * All participant rows of a thread.
	 *
	 * @param int $thread_id Thread id.
	 *
	 * @return object[]
	 */
	public function participants( int $thread_id ): array {
		$table = Schema::table( 'thread_participants' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE thread_id = %d", $thread_id ) );
	}

	/**
	 * Record a new message: bump thread and every other participant's unread
	 * count; restore the thread for participants who had deleted it.
	 *
	 * @param int $thread_id  Thread id.
	 * @param int $message_id Message id.
	 * @param int $sender_id  Sender.
	 */
	public function record_message( int $thread_id, int $message_id, int $sender_id ): void {
		$now          = $this->now();
		$participants = Schema::table( 'thread_participants' );
		$threads      = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query( $this->db->prepare( "UPDATE {$threads} SET last_message_id = %d, last_message_at = %s, message_count = message_count + 1 WHERE id = %d", $message_id, $now, $thread_id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query( $this->db->prepare( "UPDATE {$participants} SET unread_count = unread_count + 1, is_deleted = 0, deleted_at = NULL WHERE thread_id = %d AND user_id <> %d", $thread_id, $sender_id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query( $this->db->prepare( "UPDATE {$participants} SET is_deleted = 0, deleted_at = NULL, last_read_at = %s WHERE thread_id = %d AND user_id = %d", $now, $thread_id, $sender_id ) );
	}

	/**
	 * Zero a participant's unread count.
	 *
	 * @param int $thread_id Thread id.
	 * @param int $user_id   User id.
	 */
	public function mark_read( int $thread_id, int $user_id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->update(
			Schema::table( 'thread_participants' ),
			array(
				'unread_count' => 0,
				'last_read_at' => $this->now(),
			),
			array(
				'thread_id' => $thread_id,
				'user_id'   => $user_id,
			),
			array( '%d', '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Soft-delete a thread for one participant.
	 *
	 * @param int $thread_id Thread id.
	 * @param int $user_id   User id.
	 */
	public function soft_delete( int $thread_id, int $user_id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->update(
			Schema::table( 'thread_participants' ),
			array(
				'is_deleted'   => 1,
				'deleted_at'   => $this->now(),
				'unread_count' => 0,
			),
			array(
				'thread_id' => $thread_id,
				'user_id'   => $user_id,
			),
			array( '%d', '%s', '%d' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * A user's live threads, newest message first.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Limit.
	 * @param int $offset  Offset.
	 *
	 * @return object[] Thread rows joined with the participant's `unread_count`.
	 */
	public function inbox( int $user_id, int $limit = 20, int $offset = 0 ): array {
		$threads      = $this->table();
		$participants = Schema::table( 'thread_participants' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT t.*, p.unread_count FROM {$participants} p
				 INNER JOIN {$threads} t ON t.id = p.thread_id
				 WHERE p.user_id = %d AND p.is_deleted = 0
				 ORDER BY t.last_message_at DESC, t.id DESC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			)
		);
	}

	/**
	 * Total unread messages for a user across live threads.
	 *
	 * @param int $user_id User id.
	 */
	public function unread_total( int $user_id ): int {
		$participants = Schema::table( 'thread_participants' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COALESCE(SUM(unread_count), 0) FROM {$participants} WHERE user_id = %d AND is_deleted = 0", $user_id ) );
	}

	/**
	 * Delete a thread outright with its participants and messages.
	 *
	 * @param int $id Thread id.
	 */
	public function delete( int $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->delete( Schema::table( 'messages' ), array( 'thread_id' => $id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->delete( Schema::table( 'thread_participants' ), array( 'thread_id' => $id ), array( '%d' ) );

		return parent::delete( $id );
	}

	/**
	 * Ids of threads every participant has deleted.
	 *
	 * @return int[]
	 */
	public function fully_deleted_ids(): array {
		$participants = Schema::table( 'thread_participants' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $this->db->get_col( "SELECT thread_id FROM {$participants} GROUP BY thread_id HAVING SUM(is_deleted = 0) = 0" ) );
	}
}
