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
use ODSI\LMS\Support\Settings;

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
		$this->warn_expiring();
	}

	/**
	 * Announce every enrollment that ends within the configured warning
	 * window, once per expiry date (LMS-ENR-015). A renewed access window is
	 * warned about again because the stored marker no longer matches.
	 *
	 * @return int Warnings issued.
	 */
	public function warn_expiring(): int {
		$days = (int) ( new Settings() )->get( 'expiry_warning_days' );

		if ( $days <= 0 ) {
			return 0;
		}

		$count = 0;

		foreach ( $this->enrollments->expiring_within( $days ) as $row ) {
			$user_id   = (int) $row->user_id;
			$course_id = (int) $row->course_id;
			$marker    = self::WARNED_META . $course_id;

			if ( (string) get_user_meta( $user_id, $marker, true ) === (string) $row->expires_at ) {
				continue;
			}

			update_user_meta( $user_id, $marker, (string) $row->expires_at );
			++$count;

			/**
			 * Fires once per expiry date when an enrollment is about to lapse.
			 *
			 * @param int    $user_id    User id.
			 * @param int    $course_id  Course post id.
			 * @param string $expires_at Expiry, UTC MySQL datetime.
			 * @param int    $row_id     Enrollment row id.
			 */
			do_action( 'odsi_lms_enrollment_expiring', $user_id, $course_id, (string) $row->expires_at, (int) $row->id );
		}//end foreach

		return $count;
	}

	/**
	 * User meta prefix recording which expiry date was already announced.
	 */
	public const WARNED_META = '_odsi_lms_expiry_warned_';

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
