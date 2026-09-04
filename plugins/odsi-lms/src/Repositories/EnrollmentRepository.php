<?php
/**
 * Enrollment persistence.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the enrollments table.
 */
final class EnrollmentRepository extends AbstractRepository {

	public const STATUS_ACTIVE    = 'active';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_EXPIRED   = 'expired';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_PENDING   = 'pending';

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'enrollments';
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
			'status'       => '%s',
			'source'       => '%s',
			'source_id'    => '%d',
			'enrolled_at'  => '%s',
			'started_at'   => '%s',
			'completed_at' => '%s',
			'expires_at'   => '%s',
			'created_at'   => '%s',
			'updated_at'   => '%s',
		);
	}

	/**
	 * Enroll a user, or reactivate an existing enrollment.
	 *
	 * @param int                  $user_id   User id.
	 * @param int                  $course_id Course post id.
	 * @param array<string, mixed> $args      Optional `source`, `source_id`, `expires_at`, `status`.
	 *
	 * @return int Enrollment id, or 0 on failure.
	 */
	public function enroll( int $user_id, int $course_id, array $args = array() ): int {
		$existing = $this->find_for( $user_id, $course_id );
		$now      = $this->now();

		$data = array(
			'status'     => (string) ( $args['status'] ?? self::STATUS_ACTIVE ),
			'source'     => (string) ( $args['source'] ?? 'manual' ),
			'source_id'  => (int) ( $args['source_id'] ?? 0 ),
			'expires_at' => $args['expires_at'] ?? null,
			'updated_at' => $now,
		);

		if ( $existing ) {
			$this->update_row( (int) $existing->id, $data );

			return (int) $existing->id;
		}

		return $this->insert_row(
			$data + array(
				'user_id'     => $user_id,
				'course_id'   => $course_id,
				'enrolled_at' => $now,
				'created_at'  => $now,
			)
		);
	}

	/**
	 * Fetch the enrollment row linking a user to a course.
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 */
	public function find_for( int $user_id, int $course_id ): ?object {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $this->db->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND course_id = %d",
				$user_id,
				$course_id
			)
		);

		return $row ?: null;
	}

	/**
	 * Whether the user currently has access to the course.
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 */
	public function has_access( int $user_id, int $course_id ): bool {
		$enrollment = $this->find_for( $user_id, $course_id );

		if ( ! $enrollment ) {
			return false;
		}

		if ( ! in_array( $enrollment->status, array( self::STATUS_ACTIVE, self::STATUS_COMPLETED ), true ) ) {
			return false;
		}

		if ( ! empty( $enrollment->expires_at ) && strtotime( (string) $enrollment->expires_at ) < time() ) {
			return false;
		}

		return true;
	}

	/**
	 * Change an enrollment's status.
	 *
	 * @param int    $user_id   User id.
	 * @param int    $course_id Course post id.
	 * @param string $status    New status.
	 */
	public function set_status( int $user_id, int $course_id, string $status ): bool {
		$enrollment = $this->find_for( $user_id, $course_id );

		if ( ! $enrollment ) {
			return false;
		}

		$data = array(
			'status'     => $status,
			'updated_at' => $this->now(),
		);

		if ( self::STATUS_COMPLETED === $status ) {
			$data['completed_at'] = $this->now();
		}

		return $this->update_row( (int) $enrollment->id, $data );
	}

	/**
	 * Remove a user's enrollment entirely.
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 */
	public function unenroll( int $user_id, int $course_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $this->db->delete(
			$this->table(),
			array(
				'user_id'   => $user_id,
				'course_id' => $course_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Course ids a user is enrolled on.
	 *
	 * @param int         $user_id User id.
	 * @param string|null $status  Optional status filter.
	 *
	 * @return int[]
	 */
	public function course_ids_for_user( int $user_id, ?string $status = null ): array {
		$table = $this->table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $this->db->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->db->prepare( "SELECT course_id FROM {$table} WHERE user_id = %d", $user_id )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $this->db->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->db->prepare(
					"SELECT course_id FROM {$table} WHERE user_id = %d AND status = %s",
					$user_id,
					$status
				)
			);
		}

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Number of users enrolled on a course.
	 *
	 * @param int         $course_id Course post id.
	 * @param string|null $status    Optional status filter.
	 */
	public function count_for_course( int $course_id, ?string $status = self::STATUS_ACTIVE ): int {
		$table = $this->table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = $this->db->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->db->prepare( "SELECT COUNT(*) FROM {$table} WHERE course_id = %d", $course_id )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = $this->db->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->db->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE course_id = %d AND status = %s",
					$course_id,
					$status
				)
			);
		}

		return (int) $count;
	}

	/**
	 * Enrollment rows for a course, newest first.
	 *
	 * @param int $course_id Course post id.
	 * @param int $limit     Maximum rows.
	 * @param int $offset    Rows to skip.
	 *
	 * @return object[]
	 */
	public function for_course( int $course_id, int $limit = 50, int $offset = 0 ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE course_id = %d ORDER BY enrolled_at DESC LIMIT %d OFFSET %d",
				$course_id,
				$limit,
				$offset
			)
		);
	}

	/**
	 * Expire every active enrollment whose access window has closed.
	 *
	 * @return int Number of rows expired.
	 */
	public function expire_due(): int {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"UPDATE {$table} SET status = %s, updated_at = %s
				 WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s",
				self::STATUS_EXPIRED,
				$this->now(),
				self::STATUS_ACTIVE,
				$this->now()
			)
		);

		return (int) $rows;
	}
}
