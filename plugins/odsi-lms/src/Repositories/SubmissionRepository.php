<?php
/**
 * Assignment submission persistence.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes assignment submissions.
 *
 * Every submission is kept: a rejected one stays as history under the
 * approved one that eventually replaces it, so "how many tries" is a query.
 */
final class SubmissionRepository extends AbstractRepository {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'submissions';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'user_id'         => '%d',
			'course_id'       => '%d',
			'lesson_id'       => '%d',
			'attachment_id'   => '%d',
			'content'         => '%s',
			'status'          => '%s',
			'points_earned'   => '%f',
			'points_possible' => '%f',
			'feedback'        => '%s',
			'graded_by'       => '%d',
			'submitted_at'    => '%s',
			'graded_at'       => '%s',
		);
	}

	/**
	 * Record a submission.
	 *
	 * @param int    $user_id         Learner.
	 * @param int    $step_id         Lesson or topic.
	 * @param int    $course_id       Course.
	 * @param string $content         Text answer.
	 * @param int    $attachment_id   Uploaded file, or 0.
	 * @param float  $points_possible Points the assignment is worth.
	 *
	 * @return int Submission id, or 0.
	 */
	public function create( int $user_id, int $step_id, int $course_id, string $content, int $attachment_id, float $points_possible ): int {
		return $this->insert_row(
			array(
				'user_id'         => $user_id,
				'course_id'       => $course_id,
				'lesson_id'       => $step_id,
				'attachment_id'   => $attachment_id,
				'content'         => $content,
				'status'          => self::STATUS_PENDING,
				'points_possible' => $points_possible,
				'submitted_at'    => $this->now(),
			)
		);
	}

	/**
	 * Latest submission by a learner for a step.
	 *
	 * @param int $user_id Learner.
	 * @param int $step_id Step.
	 */
	public function latest_for( int $user_id, int $step_id ): ?object {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND lesson_id = %d ORDER BY id DESC LIMIT 1", $user_id, $step_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ?: null;
	}

	/**
	 * Whether a learner has an approved submission for a step.
	 *
	 * @param int $user_id Learner.
	 * @param int $step_id Step.
	 */
	public function has_approved( int $user_id, int $step_id ): bool {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $this->db->get_var( $this->db->prepare( "SELECT 1 FROM {$table} WHERE user_id = %d AND lesson_id = %d AND status = %s LIMIT 1", $user_id, $step_id, self::STATUS_APPROVED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Every submission by a learner for a step, newest first.
	 *
	 * @param int $user_id Learner.
	 * @param int $step_id Step.
	 *
	 * @return object[]
	 */
	public function history( int $user_id, int $step_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND lesson_id = %d ORDER BY id DESC", $user_id, $step_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * A page of submissions by status, oldest first, optionally per course.
	 *
	 * @param string $status     Status.
	 * @param int[]  $course_ids Restrict to these courses; empty for all.
	 * @param int    $limit      Limit.
	 * @param int    $offset     Offset.
	 *
	 * @return array{rows: object[], total: int}
	 */
	public function queue( string $status, array $course_ids = array(), int $limit = 20, int $offset = 0 ): array {
		$table  = $this->table();
		$where  = array( 'status = %s' );
		$params = array( $status );

		if ( array() !== $course_ids ) {
			$ids     = array_map( 'intval', $course_ids );
			$where[] = 'course_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $ids );
		}

		$sql = "FROM {$table} WHERE " . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) {$sql}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $this->db->get_results( $this->db->prepare( "SELECT * {$sql} ORDER BY submitted_at ASC, id ASC LIMIT %d OFFSET %d", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Store a grading decision.
	 *
	 * @param int    $id       Submission.
	 * @param string $status   New status.
	 * @param float  $points   Points earned.
	 * @param string $feedback Feedback for the learner.
	 * @param int    $grader   Grader user id.
	 */
	public function grade( int $id, string $status, float $points, string $feedback, int $grader ): bool {
		return $this->update_row(
			$id,
			array(
				'status'        => $status,
				'points_earned' => $points,
				'feedback'      => $feedback,
				'graded_by'     => $grader,
				'graded_at'     => $this->now(),
			)
		);
	}

	/**
	 * Remove every submission by a learner for a course (progress reset).
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 */
	public function delete_for_course( int $user_id, int $course_id ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $this->db->delete(
			$this->table(),
			array(
				'user_id'   => $user_id,
				'course_id' => $course_id,
			),
			array( '%d', '%d' )
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Remove every submission for a step (the step was deleted).
	 *
	 * @param int $step_id Step.
	 */
	public function delete_for_step( int $step_id ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $this->db->delete( $this->table(), array( 'lesson_id' => $step_id ), array( '%d' ) );

		return false === $deleted ? 0 : (int) $deleted;
	}
}
