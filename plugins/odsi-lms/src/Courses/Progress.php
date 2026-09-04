<?php
/**
 * Progress service.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Courses;

use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Repositories\EnrollmentRepository;
use ODSI\LMS\Repositories\ProgressRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Records step completion and derives course level progress from it.
 */
final class Progress {

	/**
	 * Constructor.
	 *
	 * @param ProgressRepository   $progress    Progress storage.
	 * @param EnrollmentRepository $enrollments Enrollment storage.
	 * @param Structure            $structure   Course outline resolver.
	 */
	public function __construct(
		private ProgressRepository $progress,
		private EnrollmentRepository $enrollments,
		private Structure $structure
	) {
	}

	/**
	 * Mark a lesson, topic or quiz as complete for a user.
	 *
	 * @param int $user_id   User id.
	 * @param int $object_id Step post id.
	 *
	 * @return bool True when the step was recorded.
	 */
	public function complete_step( int $user_id, int $object_id ): bool {
		$type = (string) get_post_type( $object_id );

		if ( ! in_array( $type, PostTypes::trackable(), true ) ) {
			return false;
		}

		$course_id = $this->structure->course_id_for( $object_id );

		if ( $course_id <= 0 || ! $this->enrollments->has_access( $user_id, $course_id ) ) {
			return false;
		}

		if ( $this->progress->is_completed( $user_id, $object_id ) ) {
			return true;
		}

		$recorded = $this->progress->record(
			$user_id,
			$object_id,
			$course_id,
			$type,
			array( 'status' => ProgressRepository::STATUS_COMPLETED )
		);

		if ( $recorded <= 0 ) {
			return false;
		}

		/**
		 * Fires when a learner completes a single course step.
		 *
		 * @param int    $user_id   User id.
		 * @param int    $object_id Step post id.
		 * @param int    $course_id Course post id.
		 * @param string $type      Step post type.
		 */
		do_action( 'odsi_lms_step_completed', $user_id, $object_id, $course_id, $type );

		$this->maybe_complete_course( $user_id, $course_id );

		return true;
	}

	/**
	 * Record that a learner has started a step without completing it.
	 *
	 * @param int $user_id    User id.
	 * @param int $object_id  Step post id.
	 * @param int $time_spent Seconds to add to the running total.
	 */
	public function touch_step( int $user_id, int $object_id, int $time_spent = 0 ): void {
		$type = (string) get_post_type( $object_id );

		if ( ! in_array( $type, PostTypes::trackable(), true ) ) {
			return;
		}

		if ( $this->progress->is_completed( $user_id, $object_id ) ) {
			return;
		}

		$this->progress->record(
			$user_id,
			$object_id,
			$this->structure->course_id_for( $object_id ),
			$type,
			array( 'time_spent' => $time_spent )
		);
	}

	/**
	 * Percentage of a course a user has completed, 0-100.
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 */
	public function course_percentage( int $user_id, int $course_id ): float {
		$total = $this->structure->total_steps( $course_id );

		if ( 0 === $total ) {
			return 0.0;
		}

		return round( ( $this->completed_count( $user_id, $course_id ) / $total ) * 100, 2 );
	}

	/**
	 * Number of steps in a course the user has completed.
	 *
	 * Progress rows for steps that were later removed from the outline are
	 * ignored, so deleting a lesson cannot push a learner above 100%.
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 */
	public function completed_count( int $user_id, int $course_id ): int {
		$completed = $this->progress->completed_ids( $user_id, $course_id );
		$outline   = $this->structure->step_ids( $course_id );

		return count( array_intersect( $completed, $outline ) );
	}

	/**
	 * Whether a user has finished every step of a course.
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 */
	public function has_completed_course( int $user_id, int $course_id ): bool {
		$total = $this->structure->total_steps( $course_id );

		return $total > 0 && $this->completed_count( $user_id, $course_id ) >= $total;
	}

	/**
	 * Underlying repository, for callers that need raw rows.
	 */
	public function repository(): ProgressRepository {
		return $this->progress;
	}

	/**
	 * Flip the enrollment to completed once every step is done.
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 */
	private function maybe_complete_course( int $user_id, int $course_id ): void {
		if ( ! $this->has_completed_course( $user_id, $course_id ) ) {
			return;
		}

		$enrollment = $this->enrollments->find_for( $user_id, $course_id );

		if ( ! $enrollment || EnrollmentRepository::STATUS_COMPLETED === $enrollment->status ) {
			return;
		}

		$this->enrollments->set_status( $user_id, $course_id, EnrollmentRepository::STATUS_COMPLETED );

		/**
		 * Fires when a learner completes every step of a course.
		 *
		 * @param int $user_id   User id.
		 * @param int $course_id Course post id.
		 */
		do_action( 'odsi_lms_course_completed', $user_id, $course_id );
	}
}
