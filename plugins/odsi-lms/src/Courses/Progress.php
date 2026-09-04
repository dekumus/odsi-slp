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

		// Quizzes complete by passing (LMS-PRG-002) and sections complete with
		// their descendants (LMS-PRG-003); neither is marked by hand.
		if ( PostTypes::QUIZ === $type || $this->structure->is_section( $object_id ) ) {
			return false;
		}

		/**
		 * Filters whether a learner may mark a step complete by hand.
		 *
		 * Assignments register here: a step that requires one completes only
		 * once a submission is approved. Access is not checked here; callers
		 * that reach this from the outside check it first.
		 *
		 * @param bool $can       Whether completion is allowed.
		 * @param int  $user_id   User id.
		 * @param int  $object_id Step post id.
		 */
		if ( ! apply_filters( 'odsi_lms_can_complete_step', true, $user_id, $object_id ) ) {
			return false;
		}

		return $this->record_completion( $user_id, $object_id, $type );
	}

	/**
	 * Complete a quiz node on behalf of the quiz service after a pass.
	 *
	 * @param int $user_id User id.
	 * @param int $quiz_id Quiz post id.
	 */
	public function complete_quiz( int $user_id, int $quiz_id ): bool {
		if ( PostTypes::QUIZ !== get_post_type( $quiz_id ) ) {
			return false;
		}

		return $this->record_completion( $user_id, $quiz_id, PostTypes::QUIZ );
	}

	/**
	 * The node a learner should be sent to next (LMS-PRG-011).
	 *
	 * First incomplete node the learner can open; the last node when everything
	 * is complete; the first node when nothing is openable yet.
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 *
	 * @return int Node post id, or 0 for an empty course.
	 */
	public function resume_step( int $user_id, int $course_id ): int {
		$steps = $this->structure->outline( $course_id );

		if ( array() === $steps ) {
			return 0;
		}

		$completed = $this->progress->completed_ids( $user_id, $course_id );
		$all_done  = true;

		foreach ( $steps as $step ) {
			if ( in_array( $step['id'], $completed, true ) ) {
				continue;
			}

			$all_done = false;

			// Sections are headings, not destinations.
			if ( ! empty( $step['section'] ) ) {
				continue;
			}

			/**
			 * Filters whether a node may be opened, for the resume calculation.
			 *
			 * The access service registers here so that Progress need not depend
			 * on it, which would be circular.
			 *
			 * @param bool $can_open  Whether the learner may open the node.
			 * @param int  $user_id   User id.
			 * @param int  $object_id Node post id.
			 */
			if ( apply_filters( 'odsi_lms_resume_can_open', true, $user_id, $step['id'] ) ) {
				return $step['id'];
			}
		}//end foreach

		if ( $all_done ) {
			return $steps[ count( $steps ) - 1 ]['id'];
		}

		return $steps[0]['id'];
	}

	/**
	 * Write a completion row and run the consequences.
	 *
	 * @param int    $user_id   User id.
	 * @param int    $object_id Node post id.
	 * @param string $type      Node post type.
	 */
	private function record_completion( int $user_id, int $object_id, string $type ): bool {
		if ( ! in_array( $type, PostTypes::trackable(), true ) ) {
			return false;
		}

		$course_id = $this->structure->course_id_for( $object_id );

		if ( $course_id <= 0 ) {
			return false;
		}

		// Authors and managers preview without an enrollment; when they act,
		// their progress is recorded like anyone's (spec § 9, edge cases).
		$may_act = $this->enrollments->has_access( $user_id, $course_id )
			|| user_can( $user_id, \ODSI\LMS\Support\Capabilities::MANAGE )
			|| (int) get_post_field( 'post_author', $course_id ) === $user_id;

		if ( ! $may_act ) {
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

		$this->maybe_complete_sections( $user_id, $object_id, $course_id );
		$this->maybe_complete_course( $user_id, $course_id );

		return true;
	}

	/**
	 * Complete any section whose last descendant just completed (LMS-PRG-003).
	 *
	 * @param int $user_id   User id.
	 * @param int $object_id Node that just completed.
	 * @param int $course_id Course post id.
	 */
	private function maybe_complete_sections( int $user_id, int $object_id, int $course_id ): void {
		$steps     = $this->structure->outline( $course_id );
		$completed = $this->progress->completed_ids( $user_id, $course_id );

		foreach ( $steps as $index => $step ) {
			if ( empty( $step['section'] ) || in_array( $step['id'], $completed, true ) ) {
				continue;
			}

			$descendants = array();
			$total       = count( $steps );

			for ( $i = $index + 1; $i < $total; $i++ ) {
				if ( 0 === $steps[ $i ]['depth'] ) {
					break;
				}

				$descendants[] = $steps[ $i ]['id'];
			}

			if ( array() === $descendants || array_diff( $descendants, $completed ) !== array() ) {
				continue;
			}

			$this->progress->record(
				$user_id,
				$step['id'],
				$course_id,
				PostTypes::LESSON,
				array( 'status' => ProgressRepository::STATUS_COMPLETED )
			);

			do_action( 'odsi_lms_step_completed', $user_id, $step['id'], $course_id, PostTypes::LESSON );
		}//end foreach
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
	 * Percentages for many users in one query, for reports.
	 *
	 * @param int[] $user_ids  Users.
	 * @param int   $course_id Course post id.
	 *
	 * @return array<int, float> User id => percentage (every requested user present).
	 */
	public function course_percentages( array $user_ids, int $course_id ): array {
		$outline = $this->structure->step_ids( $course_id );
		$total   = count( $outline );
		$counts  = 0 === $total ? array() : $this->progress->completed_counts( $user_ids, $course_id, $outline );
		$out     = array();

		foreach ( $user_ids as $user_id ) {
			$out[ (int) $user_id ] = 0 === $total ? 0.0 : round( ( ( $counts[ (int) $user_id ] ?? 0 ) / $total ) * 100, 2 );
		}

		return $out;
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
		$required = $this->required_step_ids( $course_id );

		if ( array() === $required ) {
			return false;
		}

		$completed = $this->progress->completed_ids( $user_id, $course_id );

		return array() === array_diff( $required, $completed );
	}

	/**
	 * Node ids that must be complete for the course to count as complete.
	 *
	 * Every node in v1. A future "optional step" flag is a filter here, not a
	 * schema change (spec § 11.2).
	 *
	 * @param int $course_id Course post id.
	 *
	 * @return int[]
	 */
	public function required_step_ids( int $course_id ): array {
		/**
		 * Filters the nodes a learner must complete to finish a course.
		 *
		 * @param int[] $ids       Node post ids.
		 * @param int   $course_id Course post id.
		 */
		return array_map( 'intval', (array) apply_filters( 'odsi_lms_required_step_ids', $this->structure->step_ids( $course_id ), $course_id ) );
	}

	/**
	 * Close an enrollment whose remaining steps were removed from the outline
	 * (LMS-PRG-009). Cheap: one completed-ids query, so callers run it
	 * whenever a learner looks at the course.
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 *
	 * @return bool Whether the course was completed by this call.
	 */
	public function reconcile( int $user_id, int $course_id ): bool {
		$enrollment = $this->enrollments->find_for( $user_id, $course_id );

		if ( ! $enrollment || EnrollmentRepository::STATUS_ACTIVE !== $enrollment->status ) {
			return false;
		}

		// A section whose remaining child was removed completes now, as it
		// would have when that child was finished.
		$this->maybe_complete_sections( $user_id, 0, $course_id );

		if ( ! $this->has_completed_course( $user_id, $course_id ) ) {
			return false;
		}

		$this->maybe_complete_course( $user_id, $course_id );

		return true;
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
