<?php
/**
 * LMS-ADM-008 bulk enrollment and LMS-ADM-009 quiz results.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Plugin;
use ODSI\LMS\Quizzes\Grader;
use ODSI\LMS\Quizzes\QuizService;
use ODSI\LMS\Reports\EnrollmentReport;
use ODSI\LMS\Reports\QuizReport;
use ODSI\Tests\Integration\TestCase;

final class QuizReportTest extends TestCase {

	public function test_adm_009_summary_and_breakdown_count_completed_attempts_only(): void {
		$course = $this->lms->course( array( 'meta' => array( \ODSI\LMS\Support\Meta::LINEAR_PROGRESSION => 0 ) ) );
		$quiz   = $this->lms->quiz( $course, 0, array( 'post_title' => 'Reported quiz' ) );
		$q1     = $this->lms->single_choice_question( $quiz, 2 );
		$q2     = $this->lms->question( $quiz, Grader::TYPE_ESSAY, array(), 3 );
		$one    = $this->lms->enrolled_learner( $course );
		$two    = $this->lms->enrolled_learner( $course );
		$svc    = Plugin::instance()->container()->get( QuizService::class );
		$report = Plugin::instance()->container()->get( QuizReport::class );

		self::assertSame( array( $quiz ), $report->quizzes_for_course( $course ) );
		self::assertSame( 0, $report->summary( $quiz )['attempts'] );

		$svc->submit(
			$svc->start( $one, $quiz ),
			array(
				$q1 => 0,
				$q2 => 'Prose.',
			)
		);
		$svc->submit(
			$svc->start( $two, $quiz ),
			array(
				$q1 => 1,
				$q2 => 'More prose.',
			)
		);
		$svc->start( $two, $quiz ); // Open, so it must not count.

		$summary = $report->summary( $quiz );
		self::assertSame( 2, $summary['attempts'] );
		self::assertSame( 2, $summary['learners'] );
		self::assertSame( 0, $summary['passed'], 'Essays hold the pass until graded.' );

		$rows = $report->breakdown( $quiz );
		self::assertSame( array( $q1, $q2 ), array_column( $rows, 'question_id' ), 'Quiz order.' );
		self::assertSame( 2, $rows[0]['answered'] );
		self::assertSame( 1, $rows[0]['correct'] );
		self::assertSame( 50.0, $rows[0]['correct_rate'] );
		self::assertSame( 1.0, $rows[0]['average_points'] );
		self::assertSame( 2.0, $rows[0]['points_possible'] );
		self::assertSame( 2, $rows[1]['needs_grading'] );

		// A deleted question stays in the breakdown as such.
		wp_delete_post( $q2, true );
		$rows = $report->breakdown( $quiz );
		self::assertSame( '(deleted question)', $rows[1]['title'] );

		$handle = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory stream.
		self::assertSame( 2, $report->export_csv( $quiz, $handle ) );
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		self::assertStringStartsWith( 'question_id,question,type,answered', $csv );
		self::assertStringContainsString( '50', $csv );
	}

	public function test_adm_008_bulk_enrollment_resolves_names_and_emails_and_reports_the_rest(): void {
		$course = $this->lms->course();
		$admin  = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$a      = $this->factory()->user->create(
			array(
				'role' => 'subscriber',
				'user_login' => 'bulk-a',
				'user_email' => 'bulk-a@example.org',
			)
		);
		$b      = $this->factory()->user->create(
			array(
				'role' => 'subscriber',
				'user_login' => 'bulk-b',
				'user_email' => 'bulk-b@example.org',
			)
		);
		$c      = $this->lms->enrolled_learner( $course );
		$report = Plugin::instance()->container()->get( EnrollmentReport::class );

		$lines   = "bulk-a\nbulk-b@example.org, " . get_userdata( $c )->user_login . "\nnobody-here\n\nbulk-a";
		$outcome = $report->bulk_enroll( $admin, $course, $lines );

		self::assertSame( 2, $outcome['enrolled'] );
		self::assertSame( 1, $outcome['already'] );
		self::assertSame( array( 'nobody-here' ), $outcome['unknown'] );
		self::assertTrue( $this->lms->enrollment()->is_enrolled( $a, $course ) );
		self::assertTrue( $this->lms->enrollment()->is_enrolled( $b, $course ) );
		self::assertSame( 'manual', $this->lms->enrollment()->repository()->find_for( $a, $course )->source );

		$stranger = $this->lms->instructor();
		self::assertSame( 0, $report->bulk_enroll( $stranger, $course, 'bulk-a' )['enrolled'], 'Only someone who may report on the course enrolls in bulk.' );
	}
}
