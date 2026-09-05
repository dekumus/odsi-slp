<?php
/**
 * Assignments service.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Assignments;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Courses\Access;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Repositories\SubmissionRepository;
use ODSI\LMS\Support\Capabilities;
use ODSI\LMS\Support\Meta;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A lesson or topic can require an assignment: the learner hands in text
 * and/or a file, an instructor approves or rejects it, and the step only
 * completes once a submission is approved (LMS-ASN-*).
 *
 * The service owns every rule; the REST controller, the admin screen and the
 * front-end form are thin.
 */
final class Assignments implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param SubmissionRepository $submissions Storage.
	 * @param Structure            $structure   Outline.
	 * @param Access               $access      Access rules.
	 * @param Progress             $progress    Progress.
	 */
	public function __construct(
		private SubmissionRepository $submissions,
		private Structure $structure,
		private Access $access,
		private Progress $progress
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'odsi_lms_can_complete_step', array( $this, 'gate_completion' ), 10, 3 );
		add_action( 'odsi_lms_progress_reset', array( $this, 'on_progress_reset' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ), 10, 2 );
	}

	/**
	 * Whether a step requires an assignment (LMS-ASN-001).
	 *
	 * @param int $step_id Lesson or topic.
	 */
	public function requires_assignment( int $step_id ): bool {
		if ( ! in_array( get_post_type( $step_id ), array( PostTypes::LESSON, PostTypes::TOPIC ), true ) ) {
			return false;
		}

		if ( ! (bool) get_post_meta( $step_id, Meta::ASSIGNMENT_REQUIRED, true ) ) {
			return false;
		}

		// A section lesson completes through its topics, so it cannot carry one.
		return ! $this->structure->is_section( $step_id );
	}

	/**
	 * Points the assignment is worth; zero means approve/reject only.
	 *
	 * @param int $step_id Step.
	 */
	public function points( int $step_id ): float {
		return max( 0.0, (float) get_post_meta( $step_id, Meta::ASSIGNMENT_POINTS, true ) );
	}

	/**
	 * Hand in an assignment (LMS-ASN-002..005).
	 *
	 * @param int                  $user_id Learner.
	 * @param int                  $step_id Step.
	 * @param string               $content Text answer, may be empty when a file is given.
	 * @param array<string, mixed> $file    One `$_FILES` entry, or empty.
	 *
	 * @return int|WP_Error Submission id.
	 */
	public function submit( int $user_id, int $step_id, string $content, array $file = array() ): int|WP_Error {
		if ( ! $this->requires_assignment( $step_id ) ) {
			return new WP_Error( 'odsi_lms_no_assignment', __( 'This step has no assignment.', 'odsi-lms' ), array( 'status' => 404 ) );
		}

		if ( $user_id <= 0 || ! $this->access->can_access_step( $user_id, $step_id ) ) {
			return new WP_Error( 'odsi_lms_step_locked', __( 'This step is not available to you yet.', 'odsi-lms' ), array( 'status' => 403 ) );
		}

		$latest = $this->submissions->latest_for( $user_id, $step_id );

		if ( $latest && SubmissionRepository::STATUS_APPROVED === $latest->status ) {
			return new WP_Error( 'odsi_lms_already_approved', __( 'Your assignment has already been approved.', 'odsi-lms' ), array( 'status' => 400 ) );
		}

		if ( $latest && SubmissionRepository::STATUS_PENDING === $latest->status ) {
			return new WP_Error( 'odsi_lms_submission_pending', __( 'Your previous submission is still waiting to be reviewed.', 'odsi-lms' ), array( 'status' => 400 ) );
		}

		$content       = trim( wp_kses( $content, self::allowed_html() ) );
		$attachment_id = 0;

		if ( array() !== $file && ! empty( $file['name'] ) ) {
			$uploaded = $this->store_upload( $user_id, $step_id, $file );

			if ( $uploaded instanceof WP_Error ) {
				return $uploaded;
			}

			$attachment_id = $uploaded;
		}

		if ( '' === $content && 0 === $attachment_id ) {
			return new WP_Error( 'odsi_lms_submission_empty', __( 'Write something or attach a file.', 'odsi-lms' ), array( 'status' => 400 ) );
		}

		$course_id = $this->structure->course_id_for( $step_id );
		$id        = $this->submissions->create( $user_id, $step_id, $course_id, $content, $attachment_id, $this->points( $step_id ) );

		if ( $id <= 0 ) {
			return new WP_Error( 'odsi_lms_submission_failed', __( 'The submission could not be saved.', 'odsi-lms' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires when a learner hands in an assignment.
		 *
		 * @param int $id        Submission id.
		 * @param int $user_id   Learner.
		 * @param int $step_id   Step.
		 * @param int $course_id Course.
		 */
		do_action( 'odsi_lms_submission_created', $id, $user_id, $step_id, $course_id );

		if ( (bool) get_post_meta( $step_id, Meta::ASSIGNMENT_AUTO_APPROVE, true ) ) {
			$this->approve( $id, $this->points( $step_id ), '', 0 );
		}

		return $id;
	}

	/**
	 * Approve a submission and complete the step (LMS-ASN-006).
	 *
	 * @param int    $id       Submission.
	 * @param float  $points   Points awarded, capped at the assignment's points.
	 * @param string $feedback Feedback.
	 * @param int    $grader   Grader user id; 0 for the system.
	 */
	public function approve( int $id, float $points, string $feedback, int $grader ): bool {
		$row = $this->submissions->find( $id );

		if ( ! $row ) {
			return false;
		}

		$points = min( max( 0.0, $points ), (float) $row->points_possible );

		if ( ! $this->submissions->grade( $id, SubmissionRepository::STATUS_APPROVED, $points, trim( wp_kses( $feedback, self::allowed_html() ) ), $grader ) ) {
			return false;
		}

		$this->fire_graded( $id, SubmissionRepository::STATUS_APPROVED );
		$this->progress->complete_step( (int) $row->user_id, (int) $row->lesson_id );

		return true;
	}

	/**
	 * Reject a submission; the learner may resubmit (LMS-ASN-007).
	 *
	 * @param int    $id       Submission.
	 * @param string $feedback Feedback.
	 * @param int    $grader   Grader user id.
	 */
	public function reject( int $id, string $feedback, int $grader ): bool {
		$row = $this->submissions->find( $id );

		if ( ! $row ) {
			return false;
		}

		if ( ! $this->submissions->grade( $id, SubmissionRepository::STATUS_REJECTED, 0.0, trim( wp_kses( $feedback, self::allowed_html() ) ), $grader ) ) {
			return false;
		}

		$this->fire_graded( $id, SubmissionRepository::STATUS_REJECTED );

		return true;
	}

	/**
	 * Whether a user may grade a submission: managers, or the course author.
	 *
	 * @param int    $user_id    Grader.
	 * @param object $submission Submission row.
	 */
	public function can_grade( int $user_id, object $submission ): bool {
		if ( ! user_can( $user_id, Capabilities::REPORT ) ) {
			return false;
		}

		return user_can( $user_id, Capabilities::MANAGE ) || (int) get_post_field( 'post_author', (int) $submission->course_id ) === $user_id;
	}

	/**
	 * A step requiring an assignment completes only once one is approved (LMS-ASN-005).
	 *
	 * @param bool $can     Whether completion is allowed so far.
	 * @param int  $user_id Learner.
	 * @param int  $step_id Step.
	 */
	public function gate_completion( bool $can, int $user_id, int $step_id ): bool {
		if ( ! $can || ! $this->requires_assignment( $step_id ) ) {
			return $can;
		}

		return $this->submissions->has_approved( $user_id, $step_id );
	}

	/**
	 * Resetting progress also clears submissions (LMS-ASN-009).
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 */
	public function on_progress_reset( int $user_id, int $course_id ): void {
		$this->submissions->delete_for_course( $user_id, $course_id );
	}

	/**
	 * Deleting a step deletes its submissions; their uploads stay in the library.
	 *
	 * @param int          $post_id Post.
	 * @param WP_Post|null $post    Post object.
	 */
	public function on_delete_post( int $post_id, ?WP_Post $post = null ): void {
		$type = $post ? $post->post_type : (string) get_post_type( $post_id );

		if ( in_array( $type, array( PostTypes::LESSON, PostTypes::TOPIC ), true ) ) {
			$this->submissions->delete_for_step( $post_id );
		}
	}

	/**
	 * Present a submission row for REST and templates.
	 *
	 * @param object $row Row.
	 *
	 * @return array<string, mixed>
	 */
	public function present( object $row ): array {
		$attachment_id = (int) $row->attachment_id;

		return array(
			'id'              => (int) $row->id,
			'user_id'         => (int) $row->user_id,
			'step_id'         => (int) $row->lesson_id,
			'course_id'       => (int) $row->course_id,
			'status'          => (string) $row->status,
			'content'         => (string) $row->content,
			'attachment_id'   => $attachment_id,
			'attachment_url'  => $attachment_id > 0 ? (string) wp_get_attachment_url( $attachment_id ) : '',
			'attachment_name' => $attachment_id > 0 ? wp_basename( (string) get_attached_file( $attachment_id ) ) : '',
			'points_earned'   => (float) $row->points_earned,
			'points_possible' => (float) $row->points_possible,
			'feedback'        => (string) $row->feedback,
			'submitted_at'    => (string) $row->submitted_at,
			'graded_at'       => $row->graded_at ? (string) $row->graded_at : null,
		);
	}

	/**
	 * Underlying repository.
	 */
	public function repository(): SubmissionRepository {
		return $this->submissions;
	}

	/**
	 * HTML a learner may hand in: text formatting only. No images, which
	 * would let a learner log who opens their work, and no links.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		$tags = array_fill_keys( array( 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre', 'h2', 'h3', 'h4' ), array() );

		/**
		 * Filters the HTML allowed in assignment text and feedback.
		 *
		 * @param array<string, array<string, bool>> $tags Tags => attributes, as `wp_kses()` expects.
		 */
		return (array) apply_filters( 'odsi_lms_assignment_allowed_html', $tags );
	}

	/**
	 * Largest upload a submission may carry, in bytes. The front end reads the
	 * same number so its pre-flight check and the server agree (LMS-ASN-010).
	 */
	public static function max_bytes(): int {
		/**
		 * Filters the maximum assignment upload size in bytes.
		 *
		 * @param int $bytes Bytes; defaults to the site's upload limit.
		 */
		return (int) apply_filters( 'odsi_lms_assignment_max_bytes', wp_max_upload_size() );
	}

	/**
	 * File extensions a submission may carry.
	 *
	 * @return array<string, string> Extension pattern => mime type, as `get_allowed_mime_types()`.
	 */
	public function allowed_mimes(): array {
		$defaults = array(
			'pdf'          => 'application/pdf',
			'doc'          => 'application/msword',
			'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'ppt'          => 'application/vnd.ms-powerpoint',
			'pptx'         => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'xls'          => 'application/vnd.ms-excel',
			'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'odt'          => 'application/vnd.oasis.opendocument.text',
			'txt|md'       => 'text/plain',
			'csv'          => 'text/csv',
			'zip'          => 'application/zip',
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			'mp4|m4v'      => 'video/mp4',
			'mp3|m4a'      => 'audio/mpeg',
		);

		/**
		 * Filters the file types a learner may attach to an assignment.
		 *
		 * @param array<string, string> $mimes Extension pattern => mime type.
		 */
		return (array) apply_filters( 'odsi_lms_assignment_mime_types', $defaults );
	}

	/**
	 * Store an uploaded file as an attachment owned by the learner.
	 *
	 * @param int                  $user_id Learner.
	 * @param int                  $step_id Step.
	 * @param array<string, mixed> $file    `$_FILES` entry.
	 *
	 * @return int|WP_Error Attachment id.
	 */
	private function store_upload( int $user_id, int $step_id, array $file ): int|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$max = self::max_bytes();

		if ( (int) ( $file['size'] ?? 0 ) > $max ) {
			return new WP_Error(
				'odsi_lms_upload_too_large',
				/* translators: %s: size. */
				sprintf( __( 'The file is larger than the %s limit.', 'odsi-lms' ), size_format( $max ) ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Filters the WordPress upload handler used for assignment files.
		 *
		 * `wp_handle_upload` insists on a real browser upload; a test harness
		 * that stages files itself can switch to `wp_handle_sideload`.
		 *
		 * @param string $handler Function name.
		 */
		$handler  = (string) apply_filters( 'odsi_lms_assignment_upload_handler', 'wp_handle_upload' );
		$handler  = in_array( $handler, array( 'wp_handle_upload', 'wp_handle_sideload' ), true ) ? $handler : 'wp_handle_upload';
		$uploaded = $handler(
			$file,
			array(
				'test_form'                => false,
				'mimes'                    => $this->allowed_mimes(),
				// Uploads live in the public uploads directory, so the name is
				// the only thing between a file and anyone who can guess it.
				'unique_filename_callback' => static fn ( string $dir, string $name, string $ext ): string => wp_generate_password( 24, false ) . $ext,
			)
		);

		if ( isset( $uploaded['error'] ) || empty( $uploaded['file'] ) ) {
			$message = isset( $uploaded['error'] ) && is_string( $uploaded['error'] ) ? $uploaded['error'] : __( 'The file could not be uploaded.', 'odsi-lms' );

			return new WP_Error( 'odsi_lms_upload_failed', $message, array( 'status' => 400 ) );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => (string) $uploaded['type'],
				'post_title'     => sanitize_text_field( wp_basename( (string) $uploaded['file'] ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_author'    => $user_id,
				'post_parent'    => $step_id,
			),
			(string) $uploaded['file'],
			$step_id,
			true
		);

		if ( $attachment_id instanceof WP_Error ) {
			$attachment_id->add_data( array( 'status' => 500 ) );

			return $attachment_id;
		}

		update_post_meta( $attachment_id, '_odsi_submission_user', $user_id );
		wp_update_attachment_metadata( $attachment_id, (array) wp_generate_attachment_metadata( $attachment_id, (string) $uploaded['file'] ) );

		return $attachment_id;
	}

	/**
	 * Fire the graded action.
	 *
	 * @param int    $id     Submission.
	 * @param string $status Outcome.
	 */
	private function fire_graded( int $id, string $status ): void {
		$row = $this->submissions->find( $id );

		if ( ! $row ) {
			return;
		}

		/**
		 * Fires when a submission is approved or rejected.
		 *
		 * @param int    $id        Submission id.
		 * @param string $status    `approved` or `rejected`.
		 * @param int    $user_id   Learner.
		 * @param int    $step_id   Step.
		 * @param int    $course_id Course.
		 */
		do_action( 'odsi_lms_submission_graded', $id, $status, (int) $row->user_id, (int) $row->lesson_id, (int) $row->course_id );
	}
}
