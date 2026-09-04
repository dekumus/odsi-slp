<?php
/**
 * Access rules. Spec: LMS-ACC-001..008, LMS-ENR-008.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Courses\Access;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\Plugin;
use ODSI\LMS\Repositories\EnrollmentRepository;
use ODSI\LMS\Support\Meta;
use ODSI\Tests\Integration\TestCase;

final class AccessTest extends TestCase {

	private Access $access;
	private Progress $progress;

	public function set_up(): void {
		parent::set_up();
		$this->access   = Plugin::instance()->container()->get( Access::class );
		$this->progress = Plugin::instance()->container()->get( Progress::class );
		Plugin::instance()->container()->get( Structure::class )->flush();
	}

	public function test_acc_001_course_access_by_mode_and_enrollment(): void {
		$free   = $this->lms->course();
		$open   = $this->lms->course( array( 'meta' => array( Meta::ACCESS_MODE => 'open' ) ) );
		$closed = $this->lms->course( array( 'meta' => array( Meta::ACCESS_MODE => 'closed' ) ) );
		$user   = $this->lms->learner();

		self::assertFalse( $this->access->can_access_course( 0, $free ) );
		self::assertTrue( $this->access->can_access_course( 0, $open ), 'Visitors may open an open course.' );
		self::assertFalse( $this->access->can_access_course( $user, $free ) );
		self::assertFalse( $this->access->can_access_course( $user, $closed ) );

		$this->lms->enrollment()->enroll( $user, $free );
		self::assertTrue( $this->access->can_access_course( $user, $free ) );
	}

	public function test_acc_001_author_and_manager_bypass(): void {
		$instructor = $this->lms->instructor();
		$course     = $this->lms->course(
			array(
				'post_author' => $instructor,
				'meta' => array( Meta::ACCESS_MODE => 'closed' ),
			)
		);
		$admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other      = $this->lms->instructor();

		self::assertTrue( $this->access->can_access_course( $instructor, $course ) );
		self::assertTrue( $this->access->can_access_course( $admin, $course ) );
		self::assertFalse( $this->access->can_access_course( $other, $course ), 'Another instructor is not this course\'s instructor.' );
	}

	public function test_acc_002_linear_progression_gates_on_the_previous_leaf(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );

		self::assertTrue( $this->access->can_access_step( $user, $c['lesson1'] ) );
		self::assertFalse( $this->access->can_access_step( $user, $c['lesson2'] ) );
		self::assertFalse( $this->access->can_access_step( $user, $c['topic21'] ) );

		$this->progress->complete_step( $user, $c['lesson1'] );
		self::assertTrue( $this->access->can_access_step( $user, $c['lesson2'] ), 'The section page opens once the previous leaf is done.' );
		self::assertTrue( $this->access->can_access_step( $user, $c['topic21'] ), 'ADR-007: the first topic is not gated by its own section.' );
		self::assertFalse( $this->access->can_access_step( $user, $c['topic22'] ) );
		self::assertFalse( $this->access->can_access_step( $user, $c['lesson3'] ) );
	}

	public function test_acc_002_non_linear_course_opens_everything(): void {
		$c    = $this->lms->standard_course( array( 'meta' => array( Meta::LINEAR_PROGRESSION => 0 ) ) );
		$user = $this->lms->enrolled_learner( $c['course'] );

		foreach ( array( 'lesson1', 'lesson2', 'topic21', 'topic22', 'quiz2', 'lesson3' ) as $key ) {
			self::assertTrue( $this->access->can_access_step( $user, $c[ $key ] ), $key );
		}
	}

	public function test_acc_002_instructor_bypasses_gate_and_drip(): void {
		$instructor = $this->lms->instructor();
		$c          = $this->lms->standard_course( array( 'post_author' => $instructor ) );
		update_post_meta( $c['lesson3'], Meta::DRIP_TYPE, 'date' );
		update_post_meta( $c['lesson3'], Meta::DRIP_VALUE, '2999-01-01' );

		self::assertTrue( $this->access->can_access_step( $instructor, $c['lesson3'] ) );
	}

	public function test_acc_003_drip_days_after_enrollment(): void {
		$c    = $this->lms->standard_course( array( 'meta' => array( Meta::LINEAR_PROGRESSION => 0 ) ) );
		$user = $this->lms->enrolled_learner( $c['course'] );
		update_post_meta( $c['lesson1'], Meta::DRIP_TYPE, 'days_after_enrollment' );
		update_post_meta( $c['lesson1'], Meta::DRIP_VALUE, '3' );

		self::assertFalse( $this->access->can_access_step( $user, $c['lesson1'] ) );

		global $wpdb;
		$rows = Plugin::instance()->container()->get( EnrollmentRepository::class );
		$wpdb->update(
			$rows->table(),
			array( 'enrolled_at' => gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS ) ),
			array(
				'user_id' => $user,
				'course_id' => $c['course'],
			)
		);

		self::assertTrue( $this->access->can_access_step( $user, $c['lesson1'] ) );
	}

	public function test_acc_003_open_course_writes_an_open_enrollment_on_first_access(): void {
		$course = $this->lms->course(
			array(
				'meta' => array(
					Meta::ACCESS_MODE => 'open',
					Meta::LINEAR_PROGRESSION => 0,
				),
			)
		);
		$lesson = $this->lms->lesson( $course );
		$user   = $this->lms->learner();

		self::assertTrue( $this->access->can_access_step( $user, $lesson ) );

		$row = Plugin::instance()->container()->get( EnrollmentRepository::class )->find_for( $user, $course );
		self::assertNotNull( $row, 'LMS-ACC-003' );
		self::assertSame( 'open', $row->source );
	}

	public function test_acc_004_drip_by_date(): void {
		$c    = $this->lms->standard_course( array( 'meta' => array( Meta::LINEAR_PROGRESSION => 0 ) ) );
		$user = $this->lms->enrolled_learner( $c['course'] );
		update_post_meta( $c['lesson1'], Meta::DRIP_TYPE, 'date' );

		update_post_meta( $c['lesson1'], Meta::DRIP_VALUE, '2999-01-01' );
		self::assertFalse( $this->access->can_access_step( $user, $c['lesson1'] ) );

		update_post_meta( $c['lesson1'], Meta::DRIP_VALUE, '2000-01-01' );
		self::assertTrue( $this->access->can_access_step( $user, $c['lesson1'] ) );
	}

	public function test_acc_006_locked_content_states_the_reason(): void {
		$c        = $this->lms->standard_course();
		$user     = $this->lms->enrolled_learner( $c['course'] );
		$stranger = $this->lms->learner();

		$render = function ( int $user_id, int $post_id ): string {
			return $this->as_user(
				$user_id,
				function () use ( $post_id ): string {
					$this->go_to( get_permalink( $post_id ) );
					the_post();
					return apply_filters( 'the_content', get_post( $post_id )->post_content );
				}
			);
		};

		$no_access = $render( $stranger, $c['lesson1'] );
		self::assertStringContainsString( 'odsi-lms-locked--enroll', $no_access );

		$gated = $render( $user, $c['lesson2'] );
		self::assertStringContainsString( 'odsi-lms-locked--progression', $gated );
		self::assertStringNotContainsString( get_post( $c['lesson2'] )->post_content, $gated );

		update_post_meta( $c['lesson1'], Meta::DRIP_TYPE, 'date' );
		update_post_meta( $c['lesson1'], Meta::DRIP_VALUE, '2999-01-01' );
		$dripped = $render( $user, $c['lesson1'] );
		self::assertStringContainsString( 'odsi-lms-locked--drip', $dripped );
	}

	public function test_acc_005_course_content_is_never_locked(): void {
		$course = $this->lms->course(
			array(
				'post_content' => 'Sales copy',
				'meta' => array( Meta::ACCESS_MODE => 'closed' ),
			)
		);

		$html = $this->as_user(
			0,
			function () use ( $course ): string {
				$this->go_to( get_permalink( $course ) );
				the_post();
				return apply_filters( 'the_content', get_post( $course )->post_content );
			}
		);

		self::assertStringContainsString( 'Sales copy', $html );
	}

	public function test_acc_008_filters_can_grant_and_deny(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->learner();

		add_filter( 'odsi_lms_can_access_course', '__return_true' );
		self::assertTrue( $this->access->can_access_course( $user, $c['course'] ) );
		remove_filter( 'odsi_lms_can_access_course', '__return_true' );

		$this->lms->enrollment()->enroll( $user, $c['course'] );
		add_filter( 'odsi_lms_can_access_step', '__return_false' );
		self::assertFalse( $this->access->can_access_step( $user, $c['lesson1'] ) );
	}
}
