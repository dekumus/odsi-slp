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
use ODSI\LMS\Reports\QuizReport;
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
	 * @param QuizReport       $quizzes    Quiz report.
	 */
	public function __construct(
		private EnrollmentReport $report,
		private Enrollment $enrollment,
		private QuizReport $quizzes
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
		$this->render_notices();

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
		$this->render_bulk_form( $course_id );
		$this->render_quiz_section( $course_id );

		echo '</div>';
	}

	/**
	 * Outcome of a bulk enrollment, carried on the redirect.
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['bulk_enrolled'] ) ) {
			$unknown = array_filter( explode( ',', sanitize_text_field( wp_unslash( (string) ( $_GET['bulk_unknown'] ?? '' ) ) ) ) );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p>%s</div>',
				esc_html(
					sprintf(
						/* translators: 1: enrolled count, 2: already-enrolled count. */
						__( '%1$d learners enrolled, %2$d were already enrolled.', 'odsi-lms' ),
						absint( $_GET['bulk_enrolled'] ),
						absint( $_GET['bulk_already'] ?? 0 )
					)
				),
				array() !== $unknown ? '<p>' . esc_html( sprintf( /* translators: %s: list of names. */ __( 'Not found: %s', 'odsi-lms' ), implode( ', ', $unknown ) ) ) . '</p>' : ''
			);
		}
		// phpcs:enable
	}

	/**
	 * Bulk enrollment form (LMS-ADM-008).
	 *
	 * @param int $course_id Course.
	 */
	private function render_bulk_form( int $course_id ): void {
		echo '<h2>' . esc_html__( 'Enroll many learners', 'odsi-lms' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="odsi_lms_report_action" /><input type="hidden" name="do" value="bulk_enroll" />';
		echo '<input type="hidden" name="course_id" value="' . esc_attr( (string) $course_id ) . '" />';
		echo '<label for="odsi-lms-bulk-users" class="screen-reader-text">' . esc_html__( 'Usernames or emails, one per line', 'odsi-lms' ) . '</label>';
		echo '<textarea id="odsi-lms-bulk-users" name="users" rows="6" class="large-text code" placeholder="' . esc_attr__( 'Usernames or emails, one per line', 'odsi-lms' ) . '"></textarea>';
		printf( '<p class="description">%s</p>', esc_html( sprintf( /* translators: %d: maximum names. */ __( 'Up to %d per request. Names that match nobody are reported back, not created.', 'odsi-lms' ), EnrollmentReport::BULK_LIMIT ) ) );
		submit_button( __( 'Enroll all', 'odsi-lms' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Quiz results for the course's quizzes (LMS-ADM-009).
	 *
	 * @param int $course_id Course.
	 */
	private function render_quiz_section( int $course_id ): void {
		$quizzes = $this->quizzes->quizzes_for_course( $course_id );

		echo '<h2>' . esc_html__( 'Quiz results', 'odsi-lms' ) . '</h2>';

		if ( array() === $quizzes ) {
			echo '<p>' . esc_html__( 'This course has no quizzes.', 'odsi-lms' ) . '</p>';
			return;
		}

		$quiz_id = absint( $_GET['quiz_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$quiz_id = in_array( $quiz_id, $quizzes, true ) ? $quiz_id : (int) $quizzes[0];

		echo '<form method="get" style="margin:1em 0"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="course_id" value="' . esc_attr( (string) $course_id ) . '" />';
		echo '<label for="odsi-report-quiz">' . esc_html__( 'Quiz', 'odsi-lms' ) . '</label> <select id="odsi-report-quiz" name="quiz_id" onchange="this.form.submit()">';
		foreach ( $quizzes as $id ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $id, selected( $quiz_id, $id, false ), esc_html( (string) get_the_title( $id ) ) );
		}
		echo '</select></form>';

		$s = $this->quizzes->summary( $quiz_id );
		echo '<ul class="odsi-lms-summary" style="display:flex;gap:2em;list-style:none;padding:0">';
		printf( '<li><strong>%d</strong> %s</li>', (int) $s['attempts'], esc_html__( 'attempts', 'odsi-lms' ) );
		printf( '<li><strong>%d</strong> %s</li>', (int) $s['learners'], esc_html__( 'learners', 'odsi-lms' ) );
		printf( '<li><strong>%s%%</strong> %s</li>', esc_html( (string) $s['pass_rate'] ), esc_html__( 'pass rate', 'odsi-lms' ) );
		printf( '<li><strong>%s%%</strong> %s</li>', esc_html( (string) $s['average'] ), esc_html__( 'average score', 'odsi-lms' ) );
		echo '</ul>';

		$export = wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'odsi_lms_report_export',
					'report'    => 'quiz',
					'course_id' => $course_id,
					'quiz_id'   => $quiz_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_NONCE
		);
		printf( '<p><a class="button" href="%1$s">%2$s</a></p>', esc_url( $export ), esc_html__( 'Export question breakdown (CSV)', 'odsi-lms' ) );

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Question', 'odsi-lms' ), __( 'Type', 'odsi-lms' ), __( 'Answered', 'odsi-lms' ), __( 'Correct', 'odsi-lms' ), __( 'Average points', 'odsi-lms' ), __( 'Awaiting grading', 'odsi-lms' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $this->quizzes->breakdown( $quiz_id ) as $row ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$d</td><td>%4$d (%5$s%%)</td><td>%6$s / %7$s</td><td>%8$d</td></tr>',
				esc_html( $row['title'] ),
				esc_html( $row['type'] ),
				(int) $row['answered'],
				(int) $row['correct'],
				esc_html( (string) $row['correct_rate'] ),
				esc_html( (string) $row['average_points'] ),
				esc_html( (string) $row['points_possible'] ),
				(int) $row['needs_grading']
			);
		}

		echo '</tbody></table>';
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

		$quiz_id = 0;
		$prefix  = 'enrollments-' . sanitize_title( (string) get_the_title( $course_id ) );

		if ( 'quiz' === sanitize_key( (string) ( $_GET['report'] ?? '' ) ) ) {
			$quiz_id = absint( $_GET['quiz_id'] ?? 0 );

			if ( ! in_array( $quiz_id, $this->quizzes->quizzes_for_course( $course_id ), true ) ) {
				wp_die( esc_html__( 'That quiz is not part of this course.', 'odsi-lms' ) );
			}

			$prefix = 'quiz-' . sanitize_title( (string) get_the_title( $quiz_id ) );
		}

		$filename = sanitize_file_name( $prefix . '-' . gmdate( 'Y-m-d' ) . '.csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$handle = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download.

		if ( false !== $handle ) {
			fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM so spreadsheets read UTF-8.
			if ( $quiz_id > 0 ) {
				$this->quizzes->export_csv( $quiz_id, $handle );
			} else {
				$this->report->export_csv( $course_id, $status, $handle );
			}

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
		echo '<label for="odsi-lms-enroll-user" class="screen-reader-text">' . esc_html__( 'Username or email', 'odsi-lms' ) . '</label>';
		echo '<input type="text" id="odsi-lms-enroll-user" name="user" required placeholder="' . esc_attr__( 'Username or email', 'odsi-lms' ) . '" /> ';
		submit_button( __( 'Enroll', 'odsi-lms' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Handle enroll / remove / reset (LMS-ADM-003).
	 */
	public function handle_action(): void {
		check_admin_referer( self::NONCE );

		$course_id = absint( $_REQUEST['course_id'] ?? 0 );
		$actor     = get_current_user_id();

		if ( ! $this->report->can_report( $actor, $course_id ) ) {
			wp_die( esc_html__( 'You cannot manage this course.', 'odsi-lms' ) );
		}

		$operation = sanitize_key( (string) ( $_REQUEST['do'] ?? '' ) );
		$user_id   = absint( $_REQUEST['user_id'] ?? 0 );

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
		} elseif ( 'bulk_enroll' === $operation ) {
			$outcome = $this->report->bulk_enroll( $actor, $course_id, sanitize_textarea_field( wp_unslash( (string) ( $_POST['users'] ?? '' ) ) ) );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'          => self::SLUG,
						'course_id'     => $course_id,
						'bulk_enrolled' => $outcome['enrolled'],
						'bulk_already'  => $outcome['already'],
						'bulk_unknown'  => rawurlencode( implode( ',', array_slice( $outcome['unknown'], 0, 20 ) ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		} elseif ( 'remove' === $operation && $user_id > 0 ) {
			$this->enrollment->unenroll( $user_id, $course_id );
		} elseif ( 'reset' === $operation && $user_id > 0 ) {
			$this->enrollment->reset_progress( $user_id, $course_id );
		}//end if

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
