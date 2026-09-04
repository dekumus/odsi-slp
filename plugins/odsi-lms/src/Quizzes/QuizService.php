<?php
/**
 * Quiz lifecycle.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Quizzes;

use ODSI\LMS\Contracts\Bootable;
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
final class QuizService implements Bootable {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'odsi_lms_progress_reset', array( $this, 'on_progress_reset' ), 10, 2 );
	}

	/**
	 * A progress reset also wipes attempts (LMS-ENR-007).
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 */
	public function on_progress_reset( int $user_id, int $course_id ): void {
		$this->attempts->delete_for_course( $user_id, $course_id );
	}

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
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded internal outline query, not a listing.
				'posts_per_page'         => 500,
				// LMS-AUT-005: menu_order, then date, then id, so ordering is total.
				'orderby'                => array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
					'ID'         => 'ASC',
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
	 * Open an attempt, or resume the learner's open one (LMS-QZ-001, LMS-QZ-002).
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

		$open = $this->attempts->find_open( $user_id, $quiz_id );

		if ( $open ) {
			if ( ! $this->has_timed_out( $open ) ) {
				return (int) $open->id;
			}

			$this->attempts->abandon( (int) $open->id );
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
	 * Whether the learner's open attempt is being resumed rather than created.
	 *
	 * @param int $user_id User id.
	 * @param int $quiz_id Quiz post id.
	 */
	public function has_open_attempt( int $user_id, int $quiz_id ): bool {
		$open = $this->attempts->find_open( $user_id, $quiz_id );

		return $open && ! $this->has_timed_out( $open );
	}

	/**
	 * Grade and close an attempt.
	 *
	 * @param int               $attempt_id Attempt id.
	 * @param array<int, mixed> $answers    Submitted answers keyed by question id.
	 *
	 * @return array<string, mixed>|WP_Error Result with per-question breakdown.
	 */
	public function submit( int $attempt_id, array $answers ): array|WP_Error {
		$attempt = $this->attempts->find( $attempt_id );

		if ( ! $attempt ) {
			return new WP_Error( 'odsi_lms_invalid_attempt', __( 'That quiz attempt does not exist.', 'odsi-lms' ) );
		}

		if ( QuizAttemptRepository::STATUS_IN_PROGRESS !== $attempt->status ) {
			return new WP_Error( 'odsi_lms_attempt_closed', __( 'That quiz attempt has already been submitted.', 'odsi-lms' ) );
		}

		if ( $this->has_timed_out( $attempt ) ) {
			$this->attempts->abandon( $attempt_id );

			return new WP_Error( 'odsi_lms_attempt_timed_out', __( 'The time limit for this quiz has expired.', 'odsi-lms' ) );
		}

		$quiz_id       = (int) $attempt->quiz_id;
		$earned        = 0.0;
		$possible      = 0.0;
		$needs_grading = false;
		$breakdown     = array();

		foreach ( $this->questions( $quiz_id ) as $question_id ) {
			$grade = $this->grader->grade( $question_id, $answers[ $question_id ] ?? null );

			$earned   += (float) $grade['points_earned'];
			$possible += (float) $grade['points_possible'];

			if ( ! empty( $grade['needs_grading'] ) ) {
				$needs_grading = true;
			}

			$this->attempts->save_answer( $attempt_id, $question_id, $answers[ $question_id ] ?? null, $grade );

			$breakdown[ $question_id ] = array(
				'is_correct'      => (bool) $grade['is_correct'],
				'needs_grading'   => (bool) $grade['needs_grading'],
				'points_earned'   => (float) $grade['points_earned'],
				'points_possible' => (float) $grade['points_possible'],
			);
		}

		$pass_mark  = (float) get_post_meta( $quiz_id, Meta::PASS_MARK, true );
		$percentage = $possible > 0 ? round( ( $earned / $possible ) * 100, 2 ) : 0.0;
		$passed     = $percentage >= $pass_mark && ! $needs_grading;

		if ( ! $this->attempts->complete( $attempt_id, $earned, $possible, $passed ) ) {
			return new WP_Error( 'odsi_lms_attempt_closed', __( 'That quiz attempt has already been submitted.', 'odsi-lms' ) );
		}

		if ( $passed ) {
			$this->progress->complete_quiz( (int) $attempt->user_id, $quiz_id );
		}

		$result = array(
			'attempt_id'      => $attempt_id,
			'points_earned'   => $earned,
			'points_possible' => $possible,
			'percentage'      => $percentage,
			'passed'          => $passed,
			'needs_grading'   => $needs_grading,
			'questions'       => $breakdown,
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
	 * Manually grade one answer and recompute the attempt (LMS-QZ-010).
	 *
	 * @param int   $attempt_id  Attempt id.
	 * @param int   $question_id Question post id.
	 * @param float $points      Points awarded; capped at the question's points.
	 */
	public function grade_answer( int $attempt_id, int $question_id, float $points ): bool {
		$attempt = $this->attempts->find( $attempt_id );

		if ( ! $attempt || QuizAttemptRepository::STATUS_COMPLETED !== $attempt->status ) {
			return false;
		}

		$max    = (float) get_post_meta( $question_id, Meta::QUESTION_POINTS, true ) ?: 1.0;
		$points = max( 0.0, min( $points, $max ) );

		if ( ! $this->attempts->grade_answer( $attempt_id, $question_id, $points ) ) {
			return false;
		}

		$earned        = 0.0;
		$possible      = 0.0;
		$needs_grading = false;

		foreach ( $this->attempts->answers_for( $attempt_id ) as $answer ) {
			$earned   += (float) $answer->points_earned;
			$possible += (float) $answer->points_possible;

			if ( (int) $answer->needs_grading ) {
				$needs_grading = true;
			}
		}

		$pass_mark  = (float) get_post_meta( (int) $attempt->quiz_id, Meta::PASS_MARK, true );
		$percentage = $possible > 0 ? round( ( $earned / $possible ) * 100, 2 ) : 0.0;
		$passed     = $percentage >= $pass_mark && ! $needs_grading;

		$this->attempts->rescore( $attempt_id, $earned, $possible, $passed );

		if ( $passed ) {
			$this->progress->complete_quiz( (int) $attempt->user_id, (int) $attempt->quiz_id );
		}

		/**
		 * Fires after an answer has been graded by hand.
		 *
		 * @param int   $attempt_id  Attempt id.
		 * @param int   $question_id Question post id.
		 * @param float $points      Points awarded.
		 * @param bool  $passed      Whether the attempt now passes.
		 */
		do_action( 'odsi_lms_answer_graded', $attempt_id, $question_id, $points, $passed );

		return true;
	}

	/**
	 * Whether an attempt has run past its quiz's time limit plus grace (LMS-QZ-006).
	 *
	 * @param object $attempt Attempt row.
	 */
	public function has_timed_out( object $attempt ): bool {
		$limit = (int) get_post_meta( (int) $attempt->quiz_id, Meta::TIME_LIMIT, true );

		if ( $limit <= 0 ) {
			return false;
		}

		$deadline = strtotime( (string) $attempt->started_at ) + ( $limit * MINUTE_IN_SECONDS ) + 30;

		return time() > $deadline;
	}

	/**
	 * Underlying repository, for callers that need raw rows.
	 */
	public function repository(): QuizAttemptRepository {
		return $this->attempts;
	}
}
