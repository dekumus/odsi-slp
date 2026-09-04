<?php
/**
 * Reports screen.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Admin;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Courses\Enrollment;
use ODSI\LMS\Reports\EnrollmentReport;
use ODSI\LMS\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The enrollment report with enroll / remove / reset actions (LMS-ADM-002/003).
 */
final class ReportsScreen implements Bootable {

	public const SLUG          = 'odsi-lms-reports';
	private const NONCE        = 'odsi_lms_report_action';
	private const EXPORT_NONCE = 'odsi_lms_report_export';

	/**
	 * Constructor.
	 *
	 * @param EnrollmentReport $report     Report queries.
	 * @param Enrollment       $enrollment Enrollment service.
	 */
	public function __construct(
		private EnrollmentReport $report,
		private Enrollment $enrollment
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_post_odsi_lms_report_action', array( $this, 'handle_action' ) );
		add_action( 'admin_post_odsi_lms_report_export', array( $this, 'handle_export' ) );
	}

	/**
	 * Render the screen. Called by AdminMenu.
	 */
	public function render(): void {
		$user_id   = get_current_user_id();
		$courses   = $this->report->reportable_courses( $user_id );
		$course_id = absint( $_GET['course_id'] ?? ( $courses[0] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.

		echo '<div class="wrap"><h1>' . esc_html__( 'Reports', 'odsi-lms' ) . '</h1>';

		if ( array() === $courses ) {
			echo '<p>' . esc_html__( 'No courses to report on yet.', 'odsi-lms' ) . '</p></div>';

			return;
		}

		if ( ! in_array( $course_id, $courses, true ) ) {
			$course_id = $courses[0];
		}

		$this->render_course_picker( $courses, $course_id );

		if ( ! $this->report->can_report( $user_id, $course_id ) ) {
			echo '</div>';

			return;
		}

		$this->render_summary( $course_id );
		$this->render_export_link( $course_id );

		$table = new EnrollmentListTable( $this->report, $course_id );
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="course_id" value="' . esc_attr( (string) $course_id ) . '" />';
		$table->search_box( __( 'Search learners', 'odsi-lms' ), 'learner' );
		$table->display();
		echo '</form>';

		$this->render_enroll_form( $course_id );

		echo '</div>';
	}

	/**
	 * Course selector.
	 *
	 * @param int[] $courses   Ids.
	 * @param int   $course_id Selected.
	 */
	private function render_course_picker( array $courses, int $course_id ): void {
		echo '<form method="get" style="margin:1em 0"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<label for="odsi-report-course">' . esc_html__( 'Course', 'odsi-lms' ) . '</label> <select id="odsi-report-course" name="course_id" onchange="this.form.submit()">';

		foreach ( $courses as $id ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $id, selected( $course_id, $id, false ), esc_html( (string) get_the_title( $id ) ) );
		}

		echo '</select></form>';
	}

	/**
	 * Headline numbers.
	 *
	 * @param int $course_id Course.
	 */
	private function render_summary( int $course_id ): void {
		$s = $this->report->summary( $course_id );

		echo '<ul class="odsi-lms-summary" style="display:flex;gap:2em;list-style:none;padding:0">';
		printf( '<li><strong>%d</strong> %s</li>', (int) $s['enrolled'], esc_html__( 'enrolled', 'odsi-lms' ) );
		printf( '<li><strong>%d</strong> %s</li>', (int) $s['active'], esc_html__( 'active', 'odsi-lms' ) );
		printf( '<li><strong>%d</strong> %s</li>', (int) $s['completed'], esc_html__( 'completed', 'odsi-lms' ) );
		printf( '<li><strong>%s%%</strong> %s</li>', esc_html( (string) $s['completion_rate'] ), esc_html__( 'completion rate', 'odsi-lms' ) );
		echo '</ul>';
	}

	/**
	 * Export button, carrying the current status filter (LMS-ADM-006).
	 *
	 * @param int $course_id Course.
	 */
	private function render_export_link( int $course_id ): void {
		$status = sanitize_key( (string) ( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$url    = wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'odsi_lms_report_export',
					'course_id' => $course_id,
					'status'    => $status,
				),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_NONCE
		);

		printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $url ), esc_html__( 'Export CSV', 'odsi-lms' ) );
	}

	/**
	 * Stream the enrollment report as a CSV download (LMS-ADM-006).
	 */
	public function handle_export(): void {
		check_admin_referer( self::EXPORT_NONCE );

		$course_id = absint( $_GET['course_id'] ?? 0 );
		$status    = sanitize_key( (string) ( $_GET['status'] ?? '' ) );

		if ( ! $this->report->can_report( get_current_user_id(), $course_id ) ) {
			wp_die( esc_html__( 'You cannot export this course.', 'odsi-lms' ) );
		}

		$filename = sanitize_file_name( 'enrollments-' . sanitize_title( (string) get_the_title( $course_id ) ) . '-' . gmdate( 'Y-m-d' ) . '.csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$handle = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download.

		if ( false !== $handle ) {
			fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM so spreadsheets read UTF-8.
			$this->report->export_csv( $course_id, $status, $handle );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		exit;
	}

	/**
	 * Manual enrollment form.
	 *
	 * @param int $course_id Course.
	 */
	private function render_enroll_form( int $course_id ): void {
		echo '<h2>' . esc_html__( 'Enroll a learner', 'odsi-lms' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="odsi_lms_report_action" /><input type="hidden" name="do" value="enroll" />';
		echo '<input type="hidden" name="course_id" value="' . esc_attr( (string) $course_id ) . '" />';
		echo '<input type="text" name="user" required placeholder="' . esc_attr__( 'Username or email', 'odsi-lms' ) . '" /> ';
		submit_button( __( 'Enroll', 'odsi-lms' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Handle enroll / remove / reset (LMS-ADM-003).
	 */
	public function handle_action(): void {
		check_admin_referer( self::NONCE );

		$course_id = absint( $_POST['course_id'] ?? 0 );
		$actor     = get_current_user_id();

		if ( ! $this->report->can_report( $actor, $course_id ) ) {
			wp_die( esc_html__( 'You cannot manage this course.', 'odsi-lms' ) );
		}

		$operation = sanitize_key( (string) ( $_POST['do'] ?? '' ) );
		$user_id   = absint( $_POST['user_id'] ?? 0 );

		if ( 'enroll' === $operation ) {
			$needle = sanitize_text_field( wp_unslash( (string) ( $_POST['user'] ?? '' ) ) );
			$user   = is_email( $needle ) ? get_user_by( 'email', $needle ) : get_user_by( 'login', $needle );

			if ( $user ) {
				$this->enrollment->enroll(
					(int) $user->ID,
					$course_id,
					array(
						'source'    => 'manual',
						'source_id' => $actor,
					)
				);
			}
		} elseif ( 'remove' === $operation && $user_id > 0 ) {
			$this->enrollment->unenroll( $user_id, $course_id );
		} elseif ( 'reset' === $operation && $user_id > 0 ) {
			$this->enrollment->reset_progress( $user_id, $course_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::SLUG,
					'course_id' => $course_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Nonce name, for the list table's action links.
	 */
	public static function nonce_action(): string {
		return self::NONCE;
	}
}
