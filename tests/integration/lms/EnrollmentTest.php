<?php
/**
 * Enrollment behaviour. Spec: LMS-ENR-001..011.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Courses\Enrollment;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Plugin;
use ODSI\LMS\Repositories\EnrollmentRepository;
use ODSI\LMS\Support\Meta;
use ODSI\Tests\Integration\TestCase;

final class EnrollmentTest extends TestCase {

	private Enrollment $enrollment;
	private EnrollmentRepository $rows;

	public function set_up(): void {
		parent::set_up();
		$this->enrollment = Plugin::instance()->container()->get( Enrollment::class );
		$this->rows       = Plugin::instance()->container()->get( EnrollmentRepository::class );
	}

	public function test_enr_001_first_enrollment_creates_an_active_row(): void {
		$course = $this->lms->course();
		$user   = $this->lms->learner();

		$fired = 0;
		add_action( 'odsi_lms_user_enrolled', static function () use ( &$fired ): void { ++$fired; } );

		$id = $this->enrollment->enroll( $user, $course, array( 'source' => 'self' ) );
		self::assertGreaterThan( 0, $id );

		$row = $this->rows->find_for( $user, $course );
		self::assertSame( 'active', $row->status );
		self::assertSame( 'self', $row->source );
		self::assertNull( $row->expires_at, 'No access window means no expiry.' );
		self::assertNotSame( '0000-00-00 00:00:00', $row->enrolled_at );
		self::assertSame( 1, $fired, 'LMS-ENR-005: fires once.' );
		self::assertTrue( $this->enrollment->is_enrolled( $user, $course ) );
	}

	public function test_enr_001_access_window_sets_expiry(): void {
		$course = $this->lms->course( array( 'meta' => array( Meta::ACCESS_DAYS => 30 ) ) );
		$user   = $this->lms->learner();

		$this->enrollment->enroll( $user, $course );
		$row = $this->rows->find_for( $user, $course );

		self::assertNotNull( $row->expires_at );
		self::assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, strtotime( $row->expires_at ), 120 );
	}

	public function test_enr_002_and_005_duplicate_enroll_is_a_silent_no_op(): void {
		$course = $this->lms->course();
		$user   = $this->lms->learner();

		$this->enrollment->enroll( $user, $course, array( 'source' => 'manual' ) );
		$before = $this->rows->find_for( $user, $course );

		$fired = 0;
		add_action( 'odsi_lms_user_enrolled', static function () use ( &$fired ): void { ++$fired; } );

		$id = $this->enrollment->enroll( $user, $course, array( 'source' => 'self' ) );

		$after = $this->rows->find_for( $user, $course );
		self::assertSame( (int) $before->id, $id );
		self::assertSame( 'manual', $after->source, 'LMS-ENR-002: source untouched.' );
		self::assertSame( $before->enrolled_at, $after->enrolled_at );
		self::assertSame( 0, $fired, 'LMS-ENR-005: no event on the no-op.' );
	}

	public function test_enr_003_reenrolling_after_expiry_resets_enrolled_at(): void {
		$course = $this->lms->course();
		$user   = $this->lms->learner();

		$this->enrollment->enroll( $user, $course );

		global $wpdb;
		$wpdb->update( $this->rows->table(), array( 'status' => 'expired', 'enrolled_at' => '2020-01-01 00:00:00' ), array( 'user_id' => $user, 'course_id' => $course ) );
		self::assertFalse( $this->enrollment->is_enrolled( $user, $course ) );

		$this->enrollment->enroll( $user, $course );
		$row = $this->rows->find_for( $user, $course );

		self::assertSame( 'active', $row->status );
		self::assertNotSame( '2020-01-01 00:00:00', $row->enrolled_at, 'LMS-ENR-003: enrolled_at is fresh.' );
		self::assertEqualsWithDelta( time(), strtotime( $row->enrolled_at ), 120 );
	}

	public function test_enr_004_pre_enroll_filter_vetoes_without_side_effects(): void {
		$course = $this->lms->course();
		$user   = $this->lms->learner();

		add_filter( 'odsi_lms_pre_enroll', '__return_false' );
		$fired = 0;
		add_action( 'odsi_lms_user_enrolled', static function () use ( &$fired ): void { ++$fired; } );

		self::assertSame( 0, $this->enrollment->enroll( $user, $course ) );
		self::assertNull( $this->rows->find_for( $user, $course ) );
		self::assertSame( 0, $fired );
	}

	public function test_enr_006_unenroll_keeps_progress_by_default(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );

		$progress = Plugin::instance()->container()->get( Progress::class );
		self::assertTrue( $progress->complete_step( $user, $c['lesson1'] ) );

		self::assertTrue( $this->enrollment->unenroll( $user, $c['course'] ) );
		self::assertFalse( $this->enrollment->is_enrolled( $user, $c['course'] ) );
		self::assertTrue( $progress->repository()->is_completed( $user, $c['lesson1'] ), 'LMS-ENR-006: progress retained.' );

		$this->enrollment->enroll( $user, $c['course'] );
		self::assertSame( 1, $progress->completed_count( $user, $c['course'] ), 'Re-enrolling resumes.' );
	}

	public function test_enr_007_explicit_reset_deletes_progress(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );

		$progress = Plugin::instance()->container()->get( Progress::class );
		$progress->complete_step( $user, $c['lesson1'] );

		$this->enrollment->unenroll( $user, $c['course'], true );
		self::assertFalse( $progress->repository()->is_completed( $user, $c['lesson1'] ) );
	}

	public function test_enr_009_enrolling_on_a_non_course_is_rejected(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$user = $this->lms->learner();

		self::assertSame( 0, $this->enrollment->enroll( $user, $page ) );
		self::assertSame( 0, $this->enrollment->enroll( 0, $this->lms->course() ) );
	}

	public function test_enr_010_past_expiry_denies_access_before_cron_runs(): void {
		$course = $this->lms->course();
		$user   = $this->lms->learner();
		$this->enrollment->enroll( $user, $course );

		global $wpdb;
		$wpdb->update( $this->rows->table(), array( 'expires_at' => '2020-01-01 00:00:00' ), array( 'user_id' => $user, 'course_id' => $course ) );

		self::assertFalse( $this->enrollment->is_enrolled( $user, $course ) );
		self::assertSame( 'active', $this->rows->find_for( $user, $course )->status, 'Row is persisted lazily.' );
	}

	public function test_enr_011_maintenance_expires_due_rows_and_fires_once_each(): void {
		$course = $this->lms->course();
		$a      = $this->lms->learner();
		$b      = $this->lms->learner();
		$this->enrollment->enroll( $a, $course );
		$this->enrollment->enroll( $b, $course );

		global $wpdb;
		$wpdb->update( $this->rows->table(), array( 'expires_at' => '2020-01-01 00:00:00' ), array( 'user_id' => $a, 'course_id' => $course ) );

		$expired = array();
		add_action( 'odsi_lms_enrollment_expired', static function ( int $user_id ) use ( &$expired ): void { $expired[] = $user_id; } );

		do_action( 'odsi_lms_daily_maintenance' );

		self::assertSame( 'expired', $this->rows->find_for( $a, $course )->status );
		self::assertSame( 'active', $this->rows->find_for( $b, $course )->status );
		self::assertSame( array( $a ), $expired, 'LMS-ENR-011: one event per expired row.' );

		do_action( 'odsi_lms_daily_maintenance' );
		self::assertSame( array( $a ), $expired, 'LMS-ADM-005: safe to run twice.' );
	}

	public function test_courses_for_user_filters_by_status(): void {
		$c1   = $this->lms->course();
		$c2   = $this->lms->course();
		$user = $this->lms->learner();
		$this->enrollment->enroll( $user, $c1 );
		$this->enrollment->enroll( $user, $c2 );
		$this->rows->set_status( $user, $c2, 'cancelled' );

		self::assertEqualsCanonicalizing( array( $c1, $c2 ), $this->enrollment->courses_for( $user ) );
		self::assertSame( array( $c1 ), $this->enrollment->courses_for( $user, 'active' ) );
	}
}
