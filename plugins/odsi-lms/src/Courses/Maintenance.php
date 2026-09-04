<?php
/**
 * Scheduled maintenance.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Courses;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Installer;
use ODSI\LMS\Repositories\EnrollmentRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Work that runs on the daily cron rather than in a request.
 */
final class Maintenance implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param EnrollmentRepository $enrollments Enrollment storage.
	 */
	public function __construct( private EnrollmentRepository $enrollments ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( Installer::CRON_HOOK, array( $this, 'run' ), 5 );
	}

	/**
	 * Cron entry point.
	 */
	public function run(): void {
		$this->expire_enrollments();
	}

	/**
	 * Move every active enrollment past its access window to `expired`,
	 * announcing each one (LMS-ENR-011). Safe to run repeatedly (LMS-ADM-005).
	 *
	 * @return int Rows expired.
	 */
	public function expire_enrollments(): int {
		$count = 0;

		foreach ( $this->enrollments->due_to_expire() as $row ) {
			if ( ! $this->enrollments->set_status( (int) $row->user_id, (int) $row->course_id, EnrollmentRepository::STATUS_EXPIRED ) ) {
				continue;
			}

			++$count;

			/**
			 * Fires when an enrollment lapses because its access window closed.
			 *
			 * @param int $user_id   User id.
			 * @param int $course_id Course post id.
			 * @param int $row_id    Enrollment row id.
			 */
			do_action( 'odsi_lms_enrollment_expired', (int) $row->user_id, (int) $row->course_id, (int) $row->id );
		}

		return $count;
	}
}
