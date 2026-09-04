<?php
/**
 * LMS-ACC-009: course prerequisites.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Courses\Access;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Plugin;
use ODSI\LMS\Quizzes\QuizService;
use ODSI\LMS\Support\Meta;
use ODSI\Tests\Integration\TestCase;

final class PrerequisitesTest extends TestCase {

	private Access $access;

	public function set_up(): void {
		parent::set_up();
		$this->access = Plugin::instance()->container()->get( Access::class );
		$this->access->forget();
		do_action( 'rest_api_init' );
	}

	public function test_acc_009_an_unfinished_prerequisite_locks_the_course_until_it_is_completed(): void {
		$basics   = $this->lms->standard_course();
		$advanced = $this->lms->course( array( 'meta' => array( Meta::PREREQUISITES => array( $basics['course'], 999999, $basics['course'] ) ) ) );
		$user     = $this->lms->enrolled_learner( $advanced );

		self::assertSame( array( $basics['course'] ), Access::prerequisites( $advanced ), 'Unknown and duplicate ids are dropped.' );
		self::assertSame( array( $basics['course'] ), $this->access->missing_prerequisites( $user, $advanced ) );
		self::assertFalse( $this->access->can_access_course( $user, $advanced ), 'Enrolled, but the prerequisite is unfinished.' );

		$this->as_user(
			$user,
			function () use ( $advanced ): void {
				$html = do_shortcode( '[odsi_enroll_button course_id="' . $advanced . '"]' );
				self::assertStringContainsString( 'Complete these courses first', $html );
				self::assertStringContainsString( 'odsi-lms-enroll__prerequisites', $html );
			}
		);

		// Finish the prerequisite.
		$this->lms->enrollment()->enroll( $user, $basics['course'] );
		$progress = Plugin::instance()->container()->get( Progress::class );
		foreach ( array( $basics['lesson1'], $basics['topic21'], $basics['topic22'], $basics['lesson3'] ) as $step ) {
			$progress->complete_step( $user, $step );
		}
		$quiz = Plugin::instance()->container()->get( QuizService::class );
		$quiz->submit( $quiz->start( $user, $basics['quiz2'] ), array( $basics['question'] => 0 ) );

		$this->access->forget();
		self::assertSame( array(), $this->access->missing_prerequisites( $user, $advanced ) );
		self::assertTrue( $this->access->can_access_course( $user, $advanced ) );
	}

	public function test_acc_009_self_enrollment_is_refused_until_prerequisites_are_met_and_managers_are_exempt(): void {
		$basics   = $this->lms->course();
		$advanced = $this->lms->course( array( 'meta' => array( Meta::PREREQUISITES => array( $basics ) ) ) );
		$user     = $this->lms->learner();

		$refused = $this->as_user( $user, fn () => $this->rest( 'POST', "/odsi-lms/v1/courses/{$advanced}/enroll" ) );
		self::assertSame( 403, $refused->get_status() );
		self::assertSame( 'odsi_lms_prerequisites', $refused->get_data()['code'] );
		self::assertSame( array( $basics ), $refused->get_data()['data']['missing'] );
		self::assertFalse( $this->lms->enrollment()->is_enrolled( $user, $advanced ) );

		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		self::assertTrue( $this->access->can_access_course( $admin, $advanced ) );

		// An unpublished prerequisite does not count.
		wp_update_post(
			array(
				'ID' => $basics,
				'post_status' => 'draft',
			)
		);
		self::assertSame( array(), Access::prerequisites( $advanced ) );
	}

	public function test_acc_009_prerequisites_are_writable_through_registered_meta_by_the_author_only(): void {
		$instructor = $this->lms->instructor();
		$other      = $this->lms->instructor();
		$basics     = $this->lms->course();
		$course     = $this->lms->course( array( 'post_author' => $instructor ) );

		$ok = $this->as_user( $instructor, fn () => $this->rest( 'POST', "/wp/v2/odsi_course/{$course}", array( 'meta' => array( Meta::PREREQUISITES => array( $basics ) ) ) ) );
		self::assertSame( 200, $ok->get_status() );
		self::assertSame( array( $basics ), array_map( 'intval', (array) get_post_meta( $course, Meta::PREREQUISITES, true ) ) );

		$denied = $this->as_user( $other, fn () => $this->rest( 'POST', "/wp/v2/odsi_course/{$course}", array( 'meta' => array( Meta::PREREQUISITES => array() ) ) ) );
		self::assertContains( $denied->get_status(), array( 401, 403 ) );
	}
}
