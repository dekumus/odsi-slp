<?php
/**
 * Regression tests for the correctness review of the LMS.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Admin\QuestionMetaBox;
use ODSI\LMS\Courses\Access;
use ODSI\LMS\Courses\Enrollment;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\Plugin;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Quizzes\Grader;
use ODSI\LMS\Quizzes\QuizService;
use ODSI\LMS\Repositories\CertificateRepository;
use ODSI\LMS\Repositories\EnrollmentRepository;
use ODSI\LMS\Repositories\QuizAttemptRepository;
use ODSI\LMS\Support\Meta;
use ODSI\Tests\Integration\TestCase;

final class CorrectnessTest extends TestCase {

	private Progress $progress;
	private Enrollment $enrollment;
	private Access $access;

	public function set_up(): void {
		parent::set_up();
		$c                = Plugin::instance()->container();
		$this->progress   = $c->get( Progress::class );
		$this->enrollment = $c->get( Enrollment::class );
		$this->access     = $c->get( Access::class );
		$c->get( Structure::class )->flush();
		$this->access->forget();
		do_action( 'rest_api_init' );
	}

	public function test_completed_enrollment_is_not_reactivated_by_re_enrolling(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );
		$repo = Plugin::instance()->container()->get( EnrollmentRepository::class );

		foreach ( array( $c['lesson1'], $c['topic21'], $c['topic22'], $c['lesson3'] ) as $step ) {
			$this->progress->complete_step( $user, $step );
		}
		$quiz = Plugin::instance()->container()->get( QuizService::class );
		$quiz->submit( $quiz->start( $user, $c['quiz2'] ), array( $c['question'] => 0 ) );

		self::assertSame( 'completed', $repo->find_for( $user, $c['course'] )->status );

		$this->enrollment->enroll( $user, $c['course'] );
		self::assertSame( 'completed', $repo->find_for( $user, $c['course'] )->status, 'Re-enrolling must not reopen a finished course.' );
	}

	public function test_prg_009_reconcile_completes_a_course_whose_steps_are_all_done(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );
		$repo = Plugin::instance()->container()->get( EnrollmentRepository::class );

		foreach ( array( $c['lesson1'], $c['topic21'], $c['topic22'], $c['lesson3'] ) as $step ) {
			$this->progress->complete_step( $user, $step );
		}
		// The quiz was removed after the fact, so no completion event ever fired.
		wp_delete_post( $c['quiz2'], true );
		Plugin::instance()->container()->get( Structure::class )->flush();

		self::assertSame( 'active', $repo->find_for( $user, $c['course'] )->status );
		self::assertTrue( $this->progress->reconcile( $user, $c['course'] ) );
		self::assertSame( 'completed', $repo->find_for( $user, $c['course'] )->status );
		self::assertFalse( $this->progress->reconcile( $user, $c['course'] ), 'Reconciling twice is a no-op.' );
	}

	public function test_qz_014_second_submit_of_the_same_attempt_is_rejected(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );
		$quiz = Plugin::instance()->container()->get( QuizService::class );

		$attempt = $quiz->start( $user, $c['quiz2'] );
		self::assertIsArray( $quiz->submit( $attempt, array( $c['question'] => 0 ) ) );

		$again = $quiz->submit( $attempt, array( $c['question'] => 0 ) );
		self::assertInstanceOf( \WP_Error::class, $again );
		self::assertSame( 'odsi_lms_attempt_closed', $again->get_error_code() );

		$attempts = Plugin::instance()->container()->get( QuizAttemptRepository::class );
		self::assertFalse( $attempts->complete( $attempt, 0, 1, false ), 'A closed attempt cannot be closed again.' );
	}

	public function test_qz_004_quiz_start_reports_seconds_remaining_and_submit_respects_locks(): void {
		$c = $this->lms->standard_course( array( 'meta' => array( Meta::LINEAR_PROGRESSION => true ) ) );
		update_post_meta( $c['quiz2'], Meta::TIME_LIMIT, 10 ); // Minutes.
		$user = $this->lms->enrolled_learner( $c['course'] );

		$this->access->forget();
		$locked = $this->as_user( $user, fn () => $this->rest( 'POST', "/odsi-lms/v1/quizzes/{$c['quiz2']}/attempts" ) );
		self::assertSame( 403, $locked->get_status(), 'The quiz sits behind incomplete steps.' );

		foreach ( array( $c['lesson1'], $c['topic21'], $c['topic22'] ) as $step ) {
			$this->progress->complete_step( $user, $step );
		}
		$this->access->forget();

		$started = $this->as_user( $user, fn () => $this->rest( 'POST', "/odsi-lms/v1/quizzes/{$c['quiz2']}/attempts" ) );
		self::assertSame( 201, $started->get_status() );
		self::assertGreaterThan( 590, (int) $started->get_data()['seconds_remaining'] );
		self::assertLessThanOrEqual( 600, (int) $started->get_data()['seconds_remaining'] );
	}

	public function test_aut_008_question_editor_stores_answers_the_grader_reads(): void {
		$single = QuestionMetaBox::parse( Grader::TYPE_SINGLE, "Paris\n*Lyon\n*Nice\n\n", false );
		self::assertSame(
			array(
				array(
					'text' => 'Paris',
					'correct' => false,
				),
				array(
					'text' => 'Lyon',
					'correct' => true,
				),
				array(
					'text' => 'Nice',
					'correct' => false,
				),
			),
			$single,
			'Single choice keeps one correct option.'
		);

		$multi = QuestionMetaBox::parse( Grader::TYPE_MULTIPLE, "*a\n*b\nc", false );
		self::assertSame( array( true, true, false ), array_column( $multi, 'correct' ) );

		$blank = QuestionMetaBox::parse( Grader::TYPE_FILL_BLANK, "colour\ncolor", false );
		self::assertSame( array( true, true ), array_column( $blank, 'correct' ) );

		$tf = QuestionMetaBox::parse( Grader::TYPE_TRUE_FALSE, '', false );
		self::assertFalse( $tf[0]['correct'] );
		self::assertTrue( $tf[1]['correct'] );
		self::assertSame( array(), QuestionMetaBox::parse( Grader::TYPE_ESSAY, 'ignored', true ) );

		$grader = Plugin::instance()->container()->get( Grader::class );
		$c      = $this->lms->standard_course();
		$q      = $this->lms->question( $c['quiz2'], Grader::TYPE_SINGLE, $single, 2 );
		self::assertTrue( $grader->grade( $q, 1 )['is_correct'] );
		self::assertFalse( $grader->grade( $q, 0 )['is_correct'] );
	}

	public function test_aut_008_instructors_write_settings_on_their_own_posts_only(): void {
		$instructor = $this->lms->instructor();
		$other      = $this->lms->instructor();
		$course     = $this->lms->course( array( 'post_author' => $instructor ) );

		$this->as_user(
			$instructor,
			function () use ( $course ) {
				$r = $this->rest(
					'POST',
					"/wp/v2/odsi_course/{$course}",
					array(
						'meta' => array( Meta::ACCESS_MODE => 'closed' ),
					)
				);
				self::assertSame( 200, $r->get_status() );
			}
		);
		self::assertSame( 'closed', get_post_meta( $course, Meta::ACCESS_MODE, true ) );

		$this->as_user(
			$other,
			function () use ( $course ) {
				$r = $this->rest( 'POST', "/wp/v2/odsi_course/{$course}", array( 'meta' => array( Meta::ACCESS_MODE => 'open' ) ) );
				self::assertContains( $r->get_status(), array( 403, 401 ) );
			}
		);
		self::assertSame( 'closed', get_post_meta( $course, Meta::ACCESS_MODE, true ) );
	}

	public function test_data_003_deleting_a_user_erases_their_learning_rows(): void {
		global $wpdb;

		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );
		$this->progress->complete_step( $user, $c['lesson1'] );
		$quiz = Plugin::instance()->container()->get( QuizService::class );
		$quiz->submit( $quiz->start( $user, $c['quiz2'] ), array( $c['question'] => 0 ) );

		self::assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}odsi_lms_quiz_attempts WHERE user_id = %d", $user ) ) );

		wp_delete_user( $user );

		foreach ( array( 'enrollments', 'progress', 'quiz_attempts' ) as $table ) {
			self::assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}odsi_lms_{$table} WHERE user_id = %d", $user ) ), $table ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		self::assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}odsi_lms_quiz_answers a LEFT JOIN {$wpdb->prefix}odsi_lms_quiz_attempts t ON t.id = a.attempt_id WHERE t.id IS NULL" ), 'No orphaned answers.' );
	}

	public function test_aut_002_moving_a_topic_between_lessons_realigns_its_course(): void {
		$a     = $this->lms->standard_course();
		$b     = $this->lms->standard_course();
		$topic = $a['topic21'];

		update_post_meta( $topic, Meta::LESSON_ID, $b['lesson1'] );

		self::assertSame( $b['course'], (int) get_post_meta( $topic, Meta::COURSE_ID, true ) );
	}

	public function test_certificates_setting_switches_issuing_off(): void {
		$c = $this->lms->standard_course();
		$t = $this->factory()->post->create(
			array(
				'post_type' => PostTypes::CERTIFICATE,
				'post_status' => 'publish',
				'post_content' => 'Well done {learner_name}',
			)
		);
		update_post_meta( $c['course'], Meta::CERTIFICATE_ID, $t );
		$user = $this->lms->enrolled_learner( $c['course'] );

		update_option( 'odsi_lms_settings', array( 'enable_certificates' => false ) );
		do_action( 'odsi_lms_course_completed', $user, $c['course'] );
		self::assertNull( Plugin::instance()->container()->get( CertificateRepository::class )->find_for( $user, $c['course'] ) );

		update_option( 'odsi_lms_settings', array( 'enable_certificates' => true ) );
		do_action( 'odsi_lms_course_completed', $user, $c['course'] );
		self::assertNotNull( Plugin::instance()->container()->get( CertificateRepository::class )->find_for( $user, $c['course'] ) );
	}

	public function test_date_drip_uses_the_site_timezone(): void {
		update_option( 'timezone_string', 'Pacific/Auckland' );
		$ts = Access::date_release_timestamp( '2030-01-01' );
		self::assertSame( '2030-01-01 00:00', wp_date( 'Y-m-d H:i', $ts ) );
		self::assertSame( '2029-12-31 11:00', gmdate( 'Y-m-d H:i', $ts ) );
		update_option( 'timezone_string', '' );
	}
}
