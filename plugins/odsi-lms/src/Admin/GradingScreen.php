<?php
/**
 * Manual grading queue.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Admin;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Quizzes\QuizService;
use ODSI\LMS\Reports\EnrollmentReport;
use ODSI\LMS\Support\Capabilities;
use ODSI\LMS\Support\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Essay answers awaiting marks (LMS-ADM-004).
 */
final class GradingScreen implements Bootable {

	public const SLUG   = 'odsi-lms-grading';
	private const NONCE = 'odsi_lms_grade';

	/**
	 * Constructor.
	 *
	 * @param EnrollmentReport $report  Queue query.
	 * @param QuizService      $quizzes Grading.
	 */
	public function __construct(
		private EnrollmentReport $report,
		private QuizService $quizzes
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_post_odsi_lms_grade', array( $this, 'handle' ) );
	}

	/**
	 * Render. Called by AdminMenu.
	 */
	public function render(): void {
		$user_id = get_current_user_id();
		$courses = user_can( $user_id, Capabilities::MANAGE ) ? array() : $this->report->reportable_courses( $user_id );

		if ( ! user_can( $user_id, Capabilities::MANAGE ) && array() === $courses ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Grading', 'odsi-lms' ) . '</h1><p>' . esc_html__( 'Nothing to grade.', 'odsi-lms' ) . '</p></div>';

			return;
		}

		$queue = $this->report->grading_queue( $courses, 20, 20 * ( max( 1, absint( $_GET['paged'] ?? 1 ) ) - 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap"><h1>' . esc_html__( 'Grading', 'odsi-lms' ) . '</h1>';
		printf( '<p>%s</p>', esc_html( sprintf( /* translators: %d: count. */ _n( '%d answer waiting', '%d answers waiting', $queue['total'], 'odsi-lms' ), $queue['total'] ) ) );

		if ( array() === $queue['rows'] ) {
			echo '</div>';

			return;
		}

		cache_users( array_map( static fn ( object $r ): int => (int) $r->user_id, $queue['rows'] ) );

		foreach ( $queue['rows'] as $row ) {
			$learner  = get_userdata( (int) $row->user_id );
			$question = get_post( (int) $row->question_id );
			$points   = (float) get_post_meta( (int) $row->question_id, Meta::QUESTION_POINTS, true ) ?: 1.0;
			$answer   = json_decode( (string) $row->answer, true );

			echo '<div class="card" style="max-width:none;margin-bottom:1em">';
			printf( '<h2>%s — %s</h2>', esc_html( $learner ? $learner->display_name : '#' . (int) $row->user_id ), esc_html( (string) get_the_title( (int) $row->quiz_id ) ) );
			printf( '<p><strong>%s</strong></p>', esc_html( $question ? $question->post_title : '' ) );
			printf( '<blockquote>%s</blockquote>', wp_kses_post( wpautop( is_string( $answer ) ? $answer : (string) wp_json_encode( $answer ) ) ) );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::NONCE );
			printf( '<input type="hidden" name="action" value="odsi_lms_grade" /><input type="hidden" name="attempt_id" value="%d" /><input type="hidden" name="question_id" value="%d" />', (int) $row->attempt_id, (int) $row->question_id );
			printf( '<label>%s <input type="number" name="points" min="0" max="%s" step="0.5" value="0" /> / %s</label> ', esc_html__( 'Points', 'odsi-lms' ), esc_attr( (string) $points ), esc_html( (string) $points ) );
			submit_button( __( 'Save grade', 'odsi-lms' ), 'primary', 'submit', false );
			echo '</form></div>';
		}

		echo '</div>';
	}

	/**
	 * Apply a grade.
	 */
	public function handle(): void {
		check_admin_referer( self::NONCE );

		$attempt_id  = absint( $_POST['attempt_id'] ?? 0 );
		$question_id = absint( $_POST['question_id'] ?? 0 );
		$points      = (float) sanitize_text_field( wp_unslash( (string) ( $_POST['points'] ?? '0' ) ) );
		$attempt     = $this->quizzes->repository()->find( $attempt_id );

		if ( ! $attempt || ! $this->report->can_report( get_current_user_id(), (int) $attempt->course_id ) ) {
			wp_die( esc_html__( 'You cannot grade this attempt.', 'odsi-lms' ) );
		}

		$this->quizzes->grade_answer( $attempt_id, $question_id, $points );

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
