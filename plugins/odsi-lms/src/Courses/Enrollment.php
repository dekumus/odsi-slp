<?php
/**
 * Enrollment service.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Courses;

use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Repositories\EnrollmentRepository;
use ODSI\LMS\Repositories\ProgressRepository;
use ODSI\LMS\Support\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Application-level enrollment rules.
 *
 * The repository stores rows; this class decides when a row should exist, works
 * out access windows, and fires the hooks other components (and the social
 * plugin) listen to.
 */
final class Enrollment {

	/**
	 * Constructor.
	 *
	 * @param EnrollmentRepository $enrollments Enrollment storage.
	 * @param ProgressRepository   $progress    Progress storage.
	 */
	public function __construct(
		private EnrollmentRepository $enrollments,
		private ProgressRepository $progress
	) {
	}

	/**
	 * Enroll a user on a course.
	 *
	 * @param int                  $user_id   User id.
	 * @param int                  $course_id Course post id.
	 * @param array<string, mixed> $args      Optional `source`, `source_id`, `expires_at`.
	 *
	 * @return int Enrollment id, or 0 when the course is invalid or a filter vetoes it.
	 */
	public function enroll( int $user_id, int $course_id, array $args = array() ): int {
		if ( $user_id <= 0 || PostTypes::COURSE !== get_post_type( $course_id ) || ! get_userdata( $user_id ) ) {
			return 0;
		}

		/**
		 * Filters whether an enrollment may proceed.
		 *
		 * @param bool                 $allowed   Whether to enroll.
		 * @param int                  $user_id   User id.
		 * @param int                  $course_id Course post id.
		 * @param array<string, mixed> $args      Enrollment arguments.
		 */
		if ( ! apply_filters( 'odsi_lms_pre_enroll', true, $user_id, $course_id, $args ) ) {
			return 0;
		}

		if ( ! isset( $args['expires_at'] ) ) {
			$args['expires_at'] = $this->access_expiry( $course_id );
		}

		// An already-active learner is a no-op: nothing changes and nothing
		// fires (LMS-ENR-002, LMS-ENR-005).
		if ( $this->enrollments->is_active( $user_id, $course_id ) ) {
			return (int) $this->enrollments->find_for( $user_id, $course_id )->id;
		}

		$enrollment_id = $this->enrollments->enroll( $user_id, $course_id, $args );

		if ( $enrollment_id > 0 ) {
			/**
			 * Fires once a user has been enrolled on a course.
			 *
			 * @param int                  $user_id       User id.
			 * @param int                  $course_id     Course post id.
			 * @param int                  $enrollment_id Enrollment row id.
			 * @param array<string, mixed> $args          Enrollment arguments.
			 */
			do_action( 'odsi_lms_user_enrolled', $user_id, $course_id, $enrollment_id, $args );
		}

		return $enrollment_id;
	}

	/**
	 * Remove a user's enrollment and, optionally, their progress.
	 *
	 * @param int  $user_id        User id.
	 * @param int  $course_id      Course post id.
	 * @param bool $reset_progress Whether to delete progress rows too.
	 */
	public function unenroll( int $user_id, int $course_id, bool $reset_progress = false ): bool {
		$removed = $this->enrollments->unenroll( $user_id, $course_id );

		if ( $reset_progress ) {
			$this->progress->reset_course( $user_id, $course_id );
			do_action( 'odsi_lms_progress_reset', $user_id, $course_id );
		}

		if ( $removed ) {
			/**
			 * Fires once a user has been removed from a course.
			 *
			 * @param int  $user_id        User id.
			 * @param int  $course_id      Course post id.
			 * @param bool $reset_progress Whether progress was deleted.
			 */
			do_action( 'odsi_lms_user_unenrolled', $user_id, $course_id, $reset_progress );
		}

		return $removed;
	}

	/**
	 * Wipe a learner's progress and attempts on a course and reopen a completed
	 * enrollment (LMS-ENR-007). Issued certificates are not revoked.
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 */
	public function reset_progress( int $user_id, int $course_id ): void {
		$this->progress->reset_course( $user_id, $course_id );

		/**
		 * Fires when a learner's progress on a course is reset, before the
		 * enrollment is reopened. Listeners holding derived data (quiz
		 * attempts, caches) clear it here.
		 *
		 * @param int $user_id   User id.
		 * @param int $course_id Course post id.
		 */
		do_action( 'odsi_lms_progress_reset', $user_id, $course_id );

		$row = $this->enrollments->find_for( $user_id, $course_id );

		if ( $row && EnrollmentRepository::STATUS_COMPLETED === $row->status ) {
			$this->enrollments->set_status( $user_id, $course_id, EnrollmentRepository::STATUS_ACTIVE );
			$this->enrollments->clear_completed_at( $user_id, $course_id );
		}
	}

	/**
	 * Whether the user currently has access to the course.
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 */
	public function is_enrolled( int $user_id, int $course_id ): bool {
		return $this->enrollments->has_access( $user_id, $course_id );
	}

	/**
	 * Course ids the user is enrolled on.
	 *
	 * @param int         $user_id User id.
	 * @param string|null $status  Optional status filter.
	 *
	 * @return int[]
	 */
	public function courses_for( int $user_id, ?string $status = null ): array {
		return $this->enrollments->course_ids_for_user( $user_id, $status );
	}

	/**
	 * Underlying repository, for callers that need raw rows.
	 */
	public function repository(): EnrollmentRepository {
		return $this->enrollments;
	}

	/**
	 * Work out when access should end, based on the course's access window.
	 *
	 * @param int $course_id Course post id.
	 *
	 * @return string|null MySQL datetime, or null for unlimited access.
	 */
	private function access_expiry( int $course_id ): ?string {
		$days = (int) get_post_meta( $course_id, Meta::ACCESS_DAYS, true );

		if ( $days <= 0 ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) );
	}
}
