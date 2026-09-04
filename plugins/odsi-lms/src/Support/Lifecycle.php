<?php
/**
 * Account lifecycle: erase a learner's rows when their account goes.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Support;

defined( 'ABSPATH' ) || exit;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Repositories\CertificateRepository;
use ODSI\LMS\Repositories\EnrollmentRepository;
use ODSI\LMS\Repositories\ProgressRepository;
use ODSI\LMS\Repositories\QuizAttemptRepository;
use ODSI\LMS\Repositories\SubmissionRepository;

/**
 * Custom tables have no foreign keys to wp_users, so the plugin removes a
 * deleted user's learning records itself (LMS-DATA-003). Reassignment of
 * authored content is WordPress's own concern.
 */
final class Lifecycle implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param EnrollmentRepository  $enrollments  Enrollments.
	 * @param ProgressRepository    $progress     Progress.
	 * @param QuizAttemptRepository $attempts     Attempts.
	 * @param SubmissionRepository  $submissions  Submissions.
	 * @param CertificateRepository $certificates Certificates.
	 */
	public function __construct(
		private EnrollmentRepository $enrollments,
		private ProgressRepository $progress,
		private QuizAttemptRepository $attempts,
		private SubmissionRepository $submissions,
		private CertificateRepository $certificates
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'deleted_user', array( $this, 'on_user_deleted' ), 10, 1 );
	}

	/**
	 * Remove every row the user owned.
	 *
	 * @param int $user_id Deleted user.
	 */
	public function on_user_deleted( int $user_id ): void {
		/**
		 * Fires before a deleted user's learning records are removed.
		 *
		 * @param int $user_id User id.
		 */
		do_action( 'odsi_lms_before_erase_user', $user_id );

		$this->attempts->delete_for_user( $user_id );
		$this->submissions->delete_for_user( $user_id );
		$this->certificates->delete_for_user( $user_id );
		$this->progress->delete_for_user( $user_id );
		$this->enrollments->delete_for_user( $user_id );
	}
}
