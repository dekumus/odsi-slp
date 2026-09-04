<?php
/**
 * Progress and completion. Spec: LMS-PRG-001..013.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\Plugin;
use ODSI\LMS\Repositories\EnrollmentRepository;
use ODSI\Tests\Integration\TestCase;

final class ProgressTest extends TestCase {

	private Progress $progress;

	public function set_up(): void {
		parent::set_up();
		$this->progress = Plugin::instance()->container()->get( Progress::class );
		Plugin::instance()->container()->get( Structure::class )->flush();
	}

	public function test_prg_001_leaf_completion_is_recorded_and_idempotent(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );

		$events = 0;
		add_action(
			'odsi_lms_step_completed',
			static function () use ( &$events ): void {
				++$events;
			}
		);

		self::assertTrue( $this->progress->complete_step( $user, $c['lesson1'] ) );
		self::assertTrue( $this->progress->complete_step( $user, $c['lesson1'] ), 'Completing again is a success.' );
		self::assertSame( 1, $events, 'LMS-PRG-012: fires on first completion only.' );
		self::assertTrue( $this->progress->repository()->is_completed( $user, $c['lesson1'] ) );
	}

	public function test_prg_004_completion_needs_enrollment(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->learner();

		self::assertFalse( $this->progress->complete_step( $user, $c['lesson1'] ) );
	}

	public function test_prg_002_a_quiz_cannot_be_marked_complete_directly(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );
		$this->complete_up_to( $user, $c, 'topic22' );

		self::assertFalse( $this->progress->complete_step( $user, $c['quiz2'] ), 'LMS-PRG-002' );
		self::assertFalse( $this->progress->repository()->is_completed( $user, $c['quiz2'] ) );
	}

	public function test_prg_003_a_section_cannot_be_marked_directly_and_auto_completes(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );
		$this->progress->complete_step( $user, $c['lesson1'] );

		self::assertFalse( $this->progress->complete_step( $user, $c['lesson2'] ), 'LMS-PRG-003: sections are not marked by learners.' );

		$this->progress->complete_step( $user, $c['topic21'] );
		$this->progress->complete_step( $user, $c['topic22'] );
		self::assertFalse( $this->progress->repository()->is_completed( $user, $c['lesson2'] ), 'Quiz still outstanding.' );

		$this->pass_quiz( $user, $c['quiz2'] );
		self::assertTrue( $this->progress->repository()->is_completed( $user, $c['lesson2'] ), 'LMS-PRG-003: the section completes with its last descendant.' );
	}

	public function test_prg_005_and_006_percentage_arithmetic(): void {
		$c    = $this->lms->standard_course(); // 6 nodes.
		$user = $this->lms->enrolled_learner( $c['course'] );

		self::assertSame( 0.0, $this->progress->course_percentage( $user, $c['course'] ) );

		$this->progress->complete_step( $user, $c['lesson1'] );
		self::assertSame( 16.67, $this->progress->course_percentage( $user, $c['course'] ) );

		$this->progress->complete_step( $user, $c['topic21'] );
		$this->progress->complete_step( $user, $c['topic22'] );
		self::assertSame( 50.0, $this->progress->course_percentage( $user, $c['course'] ), '3 of 6 = 50.00' );
	}

	public function test_prg_005_empty_course_is_zero(): void {
		$course = $this->lms->course();
		$user   = $this->lms->enrolled_learner( $course );

		self::assertSame( 0.0, $this->progress->course_percentage( $user, $course ) );
		self::assertFalse( $this->progress->has_completed_course( $user, $course ) );
	}

	public function test_prg_007_course_completes_once_and_flips_the_enrollment(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );

		$completions = 0;
		add_action(
			'odsi_lms_course_completed',
			static function () use ( &$completions ): void {
				++$completions;
			}
		);

		$this->complete_all( $user, $c );

		self::assertSame( 100.0, $this->progress->course_percentage( $user, $c['course'] ) );
		self::assertSame( 1, $completions );

		$row = Plugin::instance()->container()->get( EnrollmentRepository::class )->find_for( $user, $c['course'] );
		self::assertSame( 'completed', $row->status );
		self::assertNotNull( $row->completed_at );
	}

	public function test_prg_008_node_added_after_completion(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );
		$completions = 0;
		add_action(
			'odsi_lms_course_completed',
			static function () use ( &$completions ): void {
				++$completions;
			}
		);
		$this->complete_all( $user, $c );

		$new = $this->lms->lesson( $c['course'], array( 'menu_order' => 9 ) );
		self::assertSame( 85.71, $this->progress->course_percentage( $user, $c['course'] ), '6 of 7' );

		$this->progress->complete_step( $user, $new );
		self::assertSame( 100.0, $this->progress->course_percentage( $user, $c['course'] ) );
		self::assertSame( 1, $completions, 'LMS-PRG-008: no second completion event.' );
	}

	public function test_prg_010_stale_rows_never_exceed_100(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );
		$this->complete_all( $user, $c );

		wp_trash_post( $c['lesson3'] );

		self::assertSame( 100.0, $this->progress->course_percentage( $user, $c['course'] ) );
		self::assertSame( 5, $this->progress->completed_count( $user, $c['course'] ) );

		wp_untrash_post( $c['lesson3'] );
		wp_publish_post( $c['lesson3'] );
		self::assertSame( 6, $this->progress->completed_count( $user, $c['course'] ), 'Republishing restores the completion.' );
	}

	public function test_prg_011_resume(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );

		self::assertTrue( method_exists( $this->progress, 'resume_step' ), 'LMS-PRG-011: Progress::resume_step() is required.' );

		self::assertSame( $c['lesson1'], $this->progress->resume_step( $user, $c['course'] ) );
		$this->progress->complete_step( $user, $c['lesson1'] );
		self::assertSame( $c['topic21'], $this->progress->resume_step( $user, $c['course'] ), 'Skips the section to its first openable leaf.' );

		$this->complete_all( $user, $c );
		self::assertSame( $c['lesson3'], $this->progress->resume_step( $user, $c['course'] ), 'Last node when everything is complete.' );
	}

	public function test_prg_013_time_spent_accumulates(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );

		$this->progress->touch_step( $user, $c['lesson1'], 30 );
		$this->progress->touch_step( $user, $c['lesson1'], 45 );

		self::assertSame( 75, (int) $this->progress->repository()->find_for( $user, $c['lesson1'] )->time_spent );
	}

	private function complete_up_to( int $user, array $c, string $last ): void {
		foreach ( array( 'lesson1', 'topic21', 'topic22' ) as $key ) {
			$this->progress->complete_step( $user, $c[ $key ] );
			if ( $key === $last ) {
				return;
			}
		}
	}

	private function complete_all( int $user, array $c ): void {
		$this->complete_up_to( $user, $c, 'topic22' );
		$this->pass_quiz( $user, $c['quiz2'] );
		$this->progress->complete_step( $user, $c['lesson3'] );
	}

	private function pass_quiz( int $user, int $quiz ): void {
		$service = Plugin::instance()->container()->get( \ODSI\LMS\Quizzes\QuizService::class );
		$attempt = $service->start( $user, $quiz );
		$service->submit( $attempt, array_fill_keys( $service->questions( $quiz ), 0 ) );
	}
}
