<?php
/**
 * Progress persistence.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the per-object progress table.
 *
 * One row per user per trackable object. Course-level completion is derived from
 * these rows rather than stored twice, so the two can never disagree.
 */
final class ProgressRepository extends AbstractRepository {

	public const STATUS_IN_PROGRESS = 'in_progress';
	public const STATUS_COMPLETED   = 'completed';

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'progress';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'user_id'      => '%d',
			'course_id'    => '%d',
			'object_id'    => '%d',
			'object_type'  => '%s',
			'status'       => '%s',
			'percentage'   => '%f',
			'time_spent'   => '%d',
			'started_at'   => '%s',
			'completed_at' => '%s',
			'updated_at'   => '%s',
		);
	}

	/**
	 * Fetch the progress row for a user and object.
	 *
	 * @param int $user_id   User id.
	 * @param int $object_id Lesson, topic or quiz post id.
	 */
	public function find_for( int $user_id, int $object_id ): ?object {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $this->db->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND object_id = %d",
				$user_id,
				$object_id
			)
		);

		return $row ?: null;
	}

	/**
	 * Create or update a progress row.
	 *
	 * @param int                  $user_id     User id.
	 * @param int                  $object_id   Object post id.
	 * @param int                  $course_id   Owning course post id.
	 * @param string               $object_type Post type of the object.
	 * @param array<string, mixed> $args        Optional `status`, `percentage`, `time_spent`.
	 *
	 * @return int Progress row id, or 0 on failure.
	 */
	public function record(
		int $user_id,
		int $object_id,
		int $course_id,
		string $object_type,
		array $args = array()
	): int {
		$existing = $this->find_for( $user_id, $object_id );
		$now      = $this->now();
		$status   = (string) ( $args['status'] ?? self::STATUS_IN_PROGRESS );

		$data = array(
			'course_id'   => $course_id,
			'object_type' => $object_type,
			'status'      => $status,
			'percentage'  => (float) ( $args['percentage'] ?? ( self::STATUS_COMPLETED === $status ? 100.0 : 0.0 ) ),
			'updated_at'  => $now,
		);

		if ( isset( $args['time_spent'] ) ) {
			$data['time_spent'] = (int) $args['time_spent'] + ( $existing ? (int) $existing->time_spent : 0 );
		}

		if ( self::STATUS_COMPLETED === $status ) {
			$data['completed_at'] = $existing->completed_at ?? $now;
		}

		if ( $existing ) {
			$this->update_row( (int) $existing->id, $data );

			return (int) $existing->id;
		}

		return $this->insert_row(
			$data + array(
				'user_id'    => $user_id,
				'object_id'  => $object_id,
				'started_at' => $now,
			)
		);
	}

	/**
	 * Whether a user has completed an object.
	 *
	 * @param int $user_id   User id.
	 * @param int $object_id Object post id.
	 */
	public function is_completed( int $user_id, int $object_id ): bool {
		$row = $this->find_for( $user_id, $object_id );

		return $row && self::STATUS_COMPLETED === $row->status;
	}

	/**
	 * Object ids a user has completed within a course.
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 *
	 * @return int[]
	 */
	public function completed_ids( int $user_id, int $course_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $this->db->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT object_id FROM {$table}
				 WHERE user_id = %d AND course_id = %d AND status = %s",
				$user_id,
				$course_id,
				self::STATUS_COMPLETED
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Delete every progress row a user has for a course.
	 *
	 * Used when an enrollment is reset so a learner can retake a course cleanly.
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 *
	 * @return int Rows removed.
	 */
	public function reset_course( int $user_id, int $course_id ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $this->db->delete(
			$this->table(),
			array(
				'user_id'   => $user_id,
				'course_id' => $course_id,
			),
			array( '%d', '%d' )
		);

		return (int) $rows;
	}
}
