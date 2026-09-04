<?php
/**
 * Messages.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Individual messages within threads.
 */
final class MessageRepository extends AbstractRepository {

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'messages';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'thread_id' => '%d',
			'sender_id' => '%d',
			'content'   => '%s',
			'date_sent' => '%s',
		);
	}

	/**
	 * Insert a message.
	 *
	 * @param int    $thread_id Thread id.
	 * @param int    $sender_id Sender.
	 * @param string $content   Sanitised content.
	 *
	 * @return int Message id.
	 */
	public function send( int $thread_id, int $sender_id, string $content ): int {
		return $this->insert_row(
			array(
				'thread_id' => $thread_id,
				'sender_id' => $sender_id,
				'content'   => $content,
				'date_sent' => $this->now(),
			)
		);
	}

	/**
	 * Messages in a thread, oldest first, optionally before an id.
	 *
	 * @param int $thread_id Thread id.
	 * @param int $limit     Limit.
	 * @param int $before_id Only messages with a smaller id, or 0.
	 *
	 * @return object[]
	 */
	public function for_thread( int $thread_id, int $limit = 50, int $before_id = 0 ): array {
		$table  = $this->table();
		$sql    = "SELECT * FROM {$table} WHERE thread_id = %d";
		$params = array( $thread_id );

		if ( $before_id > 0 ) {
			$sql     .= ' AND id < %d';
			$params[] = $before_id;
		}

		$sql     .= ' ORDER BY date_sent DESC, id DESC LIMIT %d';
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $this->db->get_results( $this->db->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_reverse( $rows );
	}
}
