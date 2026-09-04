<?php
/**
 * Quiz lifecycle. Spec: LMS-QZ-001..023.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\Plugin;
use ODSI\LMS\Quizzes\QuizService;
use ODSI\LMS\Repositories\QuizAttemptRepository;
use ODSI\LMS\Support\Meta;
use ODSI\Tests\Integration\TestCase;
use WP_Error;

final class QuizTest extends TestCase {

	private QuizService $quizzes;
	private QuizAttemptRepository $attempts;

	public function set_up(): void {
		parent::set_up();
		$this->quizzes  = Plugin::instance()->container()->get( QuizService::class );
		$this->attempts = Plugin::instance()->container()->get( QuizAttemptRepository::class );
		Plugin::instance()->container()->get( Structure::class )->flush();
	}

	/**
	 * A non-linear course with one quiz of two single-choice questions (first
	 * option correct) worth 1 and 3 points, pass mark 80.
	 *
	 * @return array{course:int, quiz:int, q1:int, q3:int, user:int}
	 */
	private function world( array $quiz_meta = array() ): array {
		$course = $this->lms->course( array( 'meta' => array( Meta::LINEAR_PROGRESSION => 0 ) ) );
		$quiz   = $this->lms->quiz( $course, 0, array( 'meta' => $quiz_meta ) );
		$q1     = $this->lms->single_choice_question( $quiz, 1 );
		$q3     = $this->lms->single_choice_question( $quiz, 3 );
		$user   = $this->lms->enrolled_learner( $course );

		return compact( 'course', 'quiz', 'q1', 'q3', 'user' );
	}

	public function test_questions_are_listed_in_order(): void {
		$w = $this->world();

		self::assertSame( array( $w['q1'], $w['q3'] ), $this->quizzes->questions( $w['quiz'] ) );
	}

	public function test_qz_007_008_009_scoring_and_pass(): void {
		$w = $this->world();

		$attempt = $this->quizzes->start( $w['user'], $w['quiz'] );
		self::assertIsInt( $attempt );

		$result = $this->quizzes->submit(
			$attempt,
			array(
				$w['q1'] => 1,
				$w['q3'] => 0,
			)
		);

		self::assertSame( 3.0, $result['points_earned'] );
		self::assertSame( 4.0, $result['points_possible'] );
		self::assertSame( 75.0, $result['percentage'] );
		self::assertFalse( $result['passed'], '75 < 80' );
		self::assertFalse( $result['needs_grading'] );
		self::assertArrayHasKey( 'questions', $result, 'LMS-QZ-022: per-question breakdown.' );
		self::assertFalse( $result['questions'][ $w['q1'] ]['is_correct'] );
		self::assertTrue( $result['questions'][ $w['q3'] ]['is_correct'] );

		self::assertCount( 2, $this->attempts->answers_for( $attempt ), 'LMS-QZ-007: every question gets an answer row.' );
	}

	public function test_qz_007_unanswered_questions_score_zero(): void {
		$w       = $this->world();
		$attempt = $this->quizzes->start( $w['user'], $w['quiz'] );

		$result = $this->quizzes->submit( $attempt, array() );

		self::assertSame( 0.0, $result['percentage'] );
		self::assertCount( 2, $this->attempts->answers_for( $attempt ) );
	}

	public function test_qz_020_pass_completes_the_node_and_later_failures_do_not_undo_it(): void {
		$w        = $this->world();
		$progress = Plugin::instance()->container()->get( Progress::class );

		$this->quizzes->submit(
			$this->quizzes->start( $w['user'], $w['quiz'] ),
			array(
				$w['q1'] => 0,
				$w['q3'] => 0,
			)
		);
		self::assertTrue( $progress->repository()->is_completed( $w['user'], $w['quiz'] ) );

		$this->quizzes->submit( $this->quizzes->start( $w['user'], $w['quiz'] ), array() );
		self::assertTrue( $progress->repository()->is_completed( $w['user'], $w['quiz'] ), 'LMS-QZ-020' );
	}

	public function test_qz_021_best_attempt_is_reported(): void {
		$w = $this->world();

		$this->quizzes->submit( $this->quizzes->start( $w['user'], $w['quiz'] ), array( $w['q1'] => 0 ) );        // 25%
		$this->quizzes->submit(
			$this->quizzes->start( $w['user'], $w['quiz'] ),
			array(
				$w['q1'] => 0,
				$w['q3'] => 0,
			)
		); // 100%
		$this->quizzes->submit( $this->quizzes->start( $w['user'], $w['quiz'] ), array( $w['q3'] => 0 ) );        // 75%

		self::assertSame( 100.0, (float) $this->attempts->best_attempt( $w['user'], $w['quiz'] )->percentage );
		self::assertCount( 3, $this->attempts->attempts_for( $w['user'], $w['quiz'] ) );
	}

	public function test_qz_001_starting_again_resumes_the_open_attempt(): void {
		$w = $this->world();

		$first  = $this->quizzes->start( $w['user'], $w['quiz'] );
		$second = $this->quizzes->start( $w['user'], $w['quiz'] );

		self::assertSame( $first, $second, 'LMS-QZ-001' );
		self::assertCount( 1, $this->attempts->attempts_for( $w['user'], $w['quiz'] ) );
	}

	public function test_qz_003_004_attempt_limit_counts_closed_attempts_only(): void {
		$w = $this->world( array( Meta::MAX_ATTEMPTS => 2 ) );

		self::assertSame( 2, $this->quizzes->attempts_remaining( $w['user'], $w['quiz'] ) );

		$open = $this->quizzes->start( $w['user'], $w['quiz'] );
		self::assertSame( 2, $this->quizzes->attempts_remaining( $w['user'], $w['quiz'] ), 'LMS-QZ-003: an open attempt is not yet used.' );

		$this->quizzes->submit( $open, array() );
		self::assertSame( 1, $this->quizzes->attempts_remaining( $w['user'], $w['quiz'] ) );

		$this->quizzes->submit( $this->quizzes->start( $w['user'], $w['quiz'] ), array() );
		self::assertSame( 0, $this->quizzes->attempts_remaining( $w['user'], $w['quiz'] ) );

		$denied = $this->quizzes->start( $w['user'], $w['quiz'] );
		self::assertInstanceOf( WP_Error::class, $denied, 'LMS-QZ-004' );
		self::assertSame( 'odsi_lms_no_attempts_left', $denied->get_error_code() );
	}

	public function test_qz_002_003_timed_out_attempt_is_abandoned_and_counted(): void {
		$w = $this->world(
			array(
				Meta::TIME_LIMIT => 10,
				Meta::MAX_ATTEMPTS => 2,
			)
		);

		$stale = $this->quizzes->start( $w['user'], $w['quiz'] );
		global $wpdb;
		$wpdb->update( $this->attempts->table(), array( 'started_at' => '2020-01-01 00:00:00' ), array( 'id' => $stale ) );

		$fresh = $this->quizzes->start( $w['user'], $w['quiz'] );

		self::assertNotSame( $stale, $fresh, 'LMS-QZ-002: a timed-out attempt is not resumed.' );
		self::assertSame( QuizAttemptRepository::STATUS_ABANDONED, $this->attempts->find( $stale )->status );
		self::assertSame( 1, $this->quizzes->attempts_remaining( $w['user'], $w['quiz'] ), 'LMS-QZ-003: the abandoned one is spent; the open one is not.' );
	}

	public function test_qz_006_submitting_after_the_time_limit_abandons(): void {
		$w       = $this->world( array( Meta::TIME_LIMIT => 10 ) );
		$attempt = $this->quizzes->start( $w['user'], $w['quiz'] );

		global $wpdb;
		$wpdb->update( $this->attempts->table(), array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS ) ), array( 'id' => $attempt ) );

		$result = $this->quizzes->submit(
			$attempt,
			array(
				$w['q1'] => 0,
				$w['q3'] => 0,
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'odsi_lms_attempt_timed_out', $result->get_error_code() );
		self::assertSame( QuizAttemptRepository::STATUS_ABANDONED, $this->attempts->find( $attempt )->status );
		self::assertCount( 0, $this->attempts->answers_for( $attempt ), 'Answers are not graded.' );
	}

	public function test_qz_006_grace_period_of_thirty_seconds(): void {
		$w       = $this->world( array( Meta::TIME_LIMIT => 10 ) );
		$attempt = $this->quizzes->start( $w['user'], $w['quiz'] );

		global $wpdb;
		$wpdb->update( $this->attempts->table(), array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS - 10 ) ), array( 'id' => $attempt ) );

		self::assertIsArray( $this->quizzes->submit( $attempt, array() ) );
	}

	public function test_qz_005_closed_attempt_cannot_be_resubmitted(): void {
		$w       = $this->world();
		$attempt = $this->quizzes->start( $w['user'], $w['quiz'] );
		$this->quizzes->submit( $attempt, array() );

		$again = $this->quizzes->submit( $attempt, array() );
		self::assertInstanceOf( WP_Error::class, $again );
		self::assertSame( 'odsi_lms_attempt_closed', $again->get_error_code() );
	}

	public function test_qz_009_010_essay_holds_the_pass_until_graded(): void {
		$course = $this->lms->course( array( 'meta' => array( Meta::LINEAR_PROGRESSION => 0 ) ) );
		$quiz   = $this->lms->quiz( $course, 0 );
		$mc     = $this->lms->single_choice_question( $quiz, 1 );
		$essay  = $this->lms->question( $quiz, 'essay', array(), 4 );
		$user   = $this->lms->enrolled_learner( $course );
		$progress = Plugin::instance()->container()->get( Progress::class );

		$attempt = $this->quizzes->start( $user, $quiz );
		$result  = $this->quizzes->submit(
			$attempt,
			array(
				$mc => 0,
				$essay => 'Prose.',
			)
		);

		self::assertTrue( $result['needs_grading'] );
		self::assertFalse( $result['passed'], 'LMS-QZ-009' );
		self::assertFalse( $progress->repository()->is_completed( $user, $quiz ) );

		self::assertTrue( method_exists( $this->quizzes, 'grade_answer' ), 'LMS-QZ-010: QuizService::grade_answer() is required.' );

		$events = 0;
		add_action(
			'odsi_lms_step_completed',
			static function () use ( &$events ): void {
				++$events;
			}
		);

		self::assertTrue( $this->quizzes->grade_answer( $attempt, $essay, 4.0 ) );

		$row = $this->attempts->find( $attempt );
		self::assertSame( 100.0, (float) $row->percentage );
		self::assertSame( 1, (int) $row->passed );
		self::assertTrue( $progress->repository()->is_completed( $user, $quiz ), 'LMS-QZ-010: grading to a pass completes the node.' );
		self::assertSame( 1, $events );
	}

	public function test_qz_010_grading_cannot_exceed_the_questions_points(): void {
		$course = $this->lms->course( array( 'meta' => array( Meta::LINEAR_PROGRESSION => 0 ) ) );
		$quiz   = $this->lms->quiz( $course, 0 );
		$essay  = $this->lms->question( $quiz, 'essay', array(), 4 );
		$user   = $this->lms->enrolled_learner( $course );

		$attempt = $this->quizzes->start( $user, $quiz );
		$this->quizzes->submit( $attempt, array( $essay => 'Prose.' ) );

		self::assertTrue( $this->quizzes->grade_answer( $attempt, $essay, 40.0 ) );
		self::assertSame( 4.0, (float) $this->attempts->find( $attempt )->points_earned, 'Capped at the question\'s points.' );
	}

	public function test_start_on_a_non_quiz_is_rejected(): void {
		$w = $this->world();

		self::assertInstanceOf( WP_Error::class, $this->quizzes->start( $w['user'], $w['course'] ) );
	}
}
