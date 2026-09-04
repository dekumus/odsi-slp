<?php
/**
 * Quiz lifecycle.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Quizzes;

use ODSI\LMS\Courses\Progress;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Repositories\QuizAttemptRepository;
use ODSI\LMS\Support\Meta;
use WP_Error;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Starts, scores and closes quiz attempts.
 */
final class QuizService {

	/**
	 * Constructor.
	 *
	 * @param QuizAttemptRepository $attempts Attempt storage.
	 * @param Grader                $grader   Answer grader.
	 * @param Progress              $progress Progress service.
	 */
	public function __construct(
		private QuizAttemptRepository $attempts,
		private Grader $grader,
		private Progress $progress
	) {
	}

	/**
	 * Question post ids belonging to a quiz, in order.
	 *
	 * @param int $quiz_id Quiz post id.
	 *
	 * @return int[]
	 */
	public function questions( int $quiz_id ): array {
		$query = new WP_Query(
			array(
				'post_type'              => PostTypes::QUESTION,
				'post_status'            => 'publish',
				'posts_per_page'         => 500,
				'orderby'                => array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
				),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'               => Meta::QUIZ_ID,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'             => $quiz_id,
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * How many attempts a user has left, or null when unlimited.
	 *
	 * @param int $user_id User id.
	 * @param int $quiz_id Quiz post id.
	 */
	public function attempts_remaining( int $user_id, int $quiz_id ): ?int {
		$max = (int) get_post_meta( $quiz_id, Meta::MAX_ATTEMPTS, true );

		if ( $max <= 0 ) {
			return null;
		}

		return max( 0, $max - $this->attempts->count_attempts( $user_id, $quiz_id ) );
	}

	/**
	 * Open a new attempt.
	 *
	 * @param int $user_id User id.
	 * @param int $quiz_id Quiz post id.
	 *
	 * @return int|WP_Error Attempt id, or an error when the learner is out of attempts.
	 */
	public function start( int $user_id, int $quiz_id ): int|WP_Error {
		if ( PostTypes::QUIZ !== get_post_type( $quiz_id ) ) {
			return new WP_Error( 'odsi_lms_invalid_quiz', __( 'That quiz does not exist.', 'odsi-lms' ) );
		}

		$remaining = $this->attempts_remaining( $user_id, $quiz_id );

		if ( null !== $remaining && $remaining <= 0 ) {
			return new WP_Error(
				'odsi_lms_no_attempts_left',
				__( 'You have used all of your attempts at this quiz.', 'odsi-lms' )
			);
		}

		$course_id  = (int) get_post_meta( $quiz_id, Meta::COURSE_ID, true );
		$attempt_id = $this->attempts->start( $user_id, $quiz_id, $course_id );

		if ( $attempt_id <= 0 ) {
			return new WP_Error( 'odsi_lms_attempt_failed', __( 'The attempt could not be started.', 'odsi-lms' ) );
		}

		/**
		 * Fires when a learner starts a quiz attempt.
		 *
		 * @param int $attempt_id Attempt row id.
		 * @param int $user_id    User id.
		 * @param int $quiz_id    Quiz post id.
		 */
		do_action( 'odsi_lms_quiz_started', $attempt_id, $user_id, $quiz_id );

		return $attempt_id;
	}

	/**
	 * Grade and close an attempt.
	 *
	 * @param int                $attempt_id Attempt id.
	 * @param array<int, mixed>  $answers    Submitted answers keyed by question id.
	 *
	 * @return array{attempt_id: int, points_earned: float, points_possible: float, percentage: float, passed: bool, needs_grading: bool}|WP_Error
	 */
	public function submit( int $attempt_id, array $answers ): array|WP_Error {
		$attempt = $this->attempts->find( $attempt_id );

		if ( ! $attempt ) {
			return new WP_Error( 'odsi_lms_invalid_attempt', __( 'That quiz attempt does not exist.', 'odsi-lms' ) );
		}

		if ( QuizAttemptRepository::STATUS_IN_PROGRESS !== $attempt->status ) {
			return new WP_Error( 'odsi_lms_attempt_closed', __( 'That quiz attempt has already been submitted.', 'odsi-lms' ) );
		}

		$quiz_id       = (int) $attempt->quiz_id;
		$earned        = 0.0;
		$possible      = 0.0;
		$needs_grading = false;

		foreach ( $this->questions( $quiz_id ) as $question_id ) {
			$grade = $this->grader->grade( $question_id, $answers[ $question_id ] ?? null );

			$earned   += (float) $grade['points_earned'];
			$possible += (float) $grade['points_possible'];

			if ( ! empty( $grade['needs_grading'] ) ) {
				$needs_grading = true;
			}

			$this->attempts->save_answer( $attempt_id, $question_id, $answers[ $question_id ] ?? null, $grade );
		}

		$pass_mark  = (float) get_post_meta( $quiz_id, Meta::PASS_MARK, true );
		$percentage = $possible > 0 ? round( ( $earned / $possible ) * 100, 2 ) : 0.0;
		$passed     = $percentage >= $pass_mark;

		$this->attempts->complete( $attempt_id, $earned, $possible, $pass_mark );

		// An attempt awaiting manual marking is not final, so it must not unlock
		// the next step yet.
		if ( $passed && ! $needs_grading ) {
			$this->progress->complete_step( (int) $attempt->user_id, $quiz_id );
		}

		$result = array(
			'attempt_id'      => $attempt_id,
			'points_earned'   => $earned,
			'points_possible' => $possible,
			'percentage'      => $percentage,
			'passed'          => $passed,
			'needs_grading'   => $needs_grading,
		);

		/**
		 * Fires once a quiz attempt has been graded and closed.
		 *
		 * @param array<string, mixed> $result  Attempt result.
		 * @param int                  $user_id User id.
		 * @param int                  $quiz_id Quiz post id.
		 */
		do_action( 'odsi_lms_quiz_completed', $result, (int) $attempt->user_id, $quiz_id );

		return $result;
	}

	/**
	 * Underlying repository, for callers that need raw rows.
	 */
	public function repository(): QuizAttemptRepository {
		return $this->attempts;
	}
}
