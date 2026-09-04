<?php
/**
 * Quiz attempt persistence.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Repositories;

use ODSI\LMS\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes quiz attempts and their answers.
 *
 * Attempts are kept rather than overwritten, so "best of", "latest" and
 * "attempts remaining" rules are all questions about the same rows.
 */
final class QuizAttemptRepository extends AbstractRepository {

	public const STATUS_IN_PROGRESS = 'in_progress';
	public const STATUS_COMPLETED   = 'completed';
	public const STATUS_ABANDONED   = 'abandoned';

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'quiz_attempts';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'user_id'         => '%d',
			'quiz_id'         => '%d',
			'course_id'       => '%d',
			'attempt_number'  => '%d',
			'status'          => '%s',
			'points_earned'   => '%f',
			'points_possible' => '%f',
			'percentage'      => '%f',
			'passed'          => '%d',
			'time_spent'      => '%d',
			'started_at'      => '%s',
			'completed_at'    => '%s',
		);
	}

	/**
	 * Open a new attempt for a user.
	 *
	 * @param int $user_id   User id.
	 * @param int $quiz_id   Quiz post id.
	 * @param int $course_id Owning course post id.
	 *
	 * @return int Attempt id, or 0 on failure.
	 */
	public function start( int $user_id, int $quiz_id, int $course_id = 0 ): int {
		return $this->insert_row(
			array(
				'user_id'        => $user_id,
				'quiz_id'        => $quiz_id,
				'course_id'      => $course_id,
				'attempt_number' => $this->count_all( $user_id, $quiz_id ) + 1,
				'status'         => self::STATUS_IN_PROGRESS,
				'started_at'     => $this->now(),
			)
		);
	}

	/**
	 * Close an attempt and store its score.
	 *
	 * The pass decision is the service's, because it depends on more than the
	 * percentage (an ungraded essay holds a pass back, LMS-QZ-009).
	 *
	 * @param int   $attempt_id Attempt id.
	 * @param float $earned     Points earned.
	 * @param float $possible   Points available.
	 * @param bool  $passed     Whether the attempt passes.
	 */
	public function complete( int $attempt_id, float $earned, float $possible, bool $passed ): bool {
		$percentage = $possible > 0 ? round( ( $earned / $possible ) * 100, 2 ) : 0.0;
		$existing   = $this->find( $attempt_id );

		return $this->update_row(
			$attempt_id,
			array(
				'status'          => self::STATUS_COMPLETED,
				'points_earned'   => $earned,
				'points_possible' => $possible,
				'percentage'      => $percentage,
				'passed'          => $passed ? 1 : 0,
				'completed_at'    => $existing && ! empty( $existing->completed_at ) ? $existing->completed_at : $this->now(),
			)
		);
	}

	/**
	 * Delete every attempt and answer a user has on a course's quizzes (LMS-ENR-007).
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 */
	public function delete_for_course( int $user_id, int $course_id ): void {
		$table   = $this->table();
		$answers = Schema::table( 'quiz_answers' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', (array) $this->db->get_col( $this->db->prepare( "SELECT id FROM {$table} WHERE user_id = %d AND course_id = %d", $user_id, $course_id ) ) );

		if ( array() === $ids ) {
			return;
		}

		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query( $this->db->prepare( "DELETE FROM {$answers} WHERE attempt_id IN ({$in})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query( $this->db->prepare( "DELETE FROM {$table} WHERE id IN ({$in})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * How many attempts a user has used at a quiz: closed ones only. An open
	 * attempt is resumable and has not been spent (LMS-QZ-003).
	 *
	 * @param int $user_id User id.
	 * @param int $quiz_id Quiz post id.
	 */
	public function count_attempts( int $user_id, int $quiz_id ): int {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $this->db->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE user_id = %d AND quiz_id = %d AND status IN (%s, %s)",
				$user_id,
				$quiz_id,
				self::STATUS_COMPLETED,
				self::STATUS_ABANDONED
			)
		);

		return (int) $count;
	}

	/**
	 * Total rows for a user and quiz, whatever their status.
	 *
	 * @param int $user_id User id.
	 * @param int $quiz_id Quiz post id.
	 */
	public function count_all( int $user_id, int $quiz_id ): int {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND quiz_id = %d", $user_id, $quiz_id )
		);
	}

	/**
	 * The user's open attempt at a quiz, if any.
	 *
	 * @param int $user_id User id.
	 * @param int $quiz_id Quiz post id.
	 */
	public function find_open( int $user_id, int $quiz_id ): ?object {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND quiz_id = %d AND status = %s
				 ORDER BY id DESC LIMIT 1",
				$user_id,
				$quiz_id,
				self::STATUS_IN_PROGRESS
			)
		);

		return $row ?: null;
	}

	/**
	 * Mark an attempt abandoned.
	 *
	 * @param int $attempt_id Attempt id.
	 */
	public function abandon( int $attempt_id ): bool {
		return $this->update_row(
			$attempt_id,
			array(
				'status'       => self::STATUS_ABANDONED,
				'completed_at' => $this->now(),
			)
		);
	}

	/**
	 * Overwrite the score of a single answer and report whether it exists.
	 *
	 * @param int   $attempt_id  Attempt id.
	 * @param int   $question_id Question post id.
	 * @param float $points      Points awarded.
	 */
	public function grade_answer( int $attempt_id, int $question_id, float $points ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $this->db->update(
			Schema::table( 'quiz_answers' ),
			array(
				'points_earned' => $points,
				'is_correct'    => $points > 0 ? 1 : 0,
				'needs_grading' => 0,
			),
			array(
				'attempt_id'  => $attempt_id,
				'question_id' => $question_id,
			),
			array( '%f', '%d', '%d' ),
			array( '%d', '%d' )
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * A user's attempts at a quiz, newest first.
	 *
	 * @param int $user_id User id.
	 * @param int $quiz_id Quiz post id.
	 *
	 * @return object[]
	 */
	public function attempts_for( int $user_id, int $quiz_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND quiz_id = %d
				 ORDER BY attempt_number DESC",
				$user_id,
				$quiz_id
			)
		);
	}

	/**
	 * The user's highest scoring completed attempt at a quiz.
	 *
	 * @param int $user_id User id.
	 * @param int $quiz_id Quiz post id.
	 */
	public function best_attempt( int $user_id, int $quiz_id ): ?object {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT * FROM {$table}
				 WHERE user_id = %d AND quiz_id = %d AND status = %s
				 ORDER BY percentage DESC, completed_at ASC LIMIT 1",
				$user_id,
				$quiz_id,
				self::STATUS_COMPLETED
			)
		);

		return $row ?: null;
	}

	/**
	 * Store a single answer against an attempt.
	 *
	 * @param int                  $attempt_id  Attempt id.
	 * @param int                  $question_id Question post id.
	 * @param mixed                $answer      Raw submitted answer, JSON encoded on write.
	 * @param array<string, mixed> $grade       `points_earned`, `points_possible`, `is_correct`, `needs_grading`.
	 */
	public function save_answer( int $attempt_id, int $question_id, mixed $answer, array $grade = array() ): bool {
		$table = Schema::table( 'quiz_answers' );

		$data = array(
			'attempt_id'      => $attempt_id,
			'question_id'     => $question_id,
			'answer'          => (string) wp_json_encode( $answer ),
			'points_earned'   => (float) ( $grade['points_earned'] ?? 0 ),
			'points_possible' => (float) ( $grade['points_possible'] ?? 0 ),
			'is_correct'      => ! empty( $grade['is_correct'] ) ? 1 : 0,
			'needs_grading'   => ! empty( $grade['needs_grading'] ) ? 1 : 0,
			'answered_at'     => $this->now(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$replaced = $this->db->replace(
			$table,
			$data,
			array( '%d', '%d', '%s', '%f', '%f', '%d', '%d', '%s' )
		);

		return false !== $replaced;
	}

	/**
	 * Every stored answer for an attempt.
	 *
	 * @param int $attempt_id Attempt id.
	 *
	 * @return object[]
	 */
	public function answers_for( int $attempt_id ): array {
		$table = Schema::table( 'quiz_answers' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare( "SELECT * FROM {$table} WHERE attempt_id = %d ORDER BY id ASC", $attempt_id )
		);
	}
}
