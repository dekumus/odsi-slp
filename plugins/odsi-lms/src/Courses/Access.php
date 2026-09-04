<?php
/**
 * Content access rules.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Courses;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Repositories\EnrollmentRepository;
use ODSI\LMS\Repositories\ProgressRepository;
use ODSI\LMS\Support\Capabilities;
use ODSI\LMS\Support\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what a given user is allowed to open.
 *
 * Three rules compose here: enrollment (are they on the course), drip (has the
 * step unlocked yet) and linear progression (did they finish the previous step).
 */
final class Access implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param EnrollmentRepository $enrollments Enrollment storage.
	 * @param ProgressRepository   $progress    Progress storage.
	 * @param Structure            $structure   Course outline resolver.
	 */
	public function __construct(
		private EnrollmentRepository $enrollments,
		private ProgressRepository $progress,
		private Structure $structure
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'the_content', array( $this, 'filter_content' ), 20 );
		add_filter( 'get_the_excerpt', array( $this, 'filter_excerpt' ), 20, 2 );
		add_filter( 'the_excerpt_rss', array( $this, 'filter_excerpt_rss' ), 20 );
		add_filter( 'odsi_lms_resume_can_open', array( $this, 'filter_resume_can_open' ), 10, 3 );
	}

	/**
	 * Supply the access decision to the resume calculation.
	 *
	 * @param bool $can_open  Current decision.
	 * @param int  $user_id   User id.
	 * @param int  $object_id Node post id.
	 */
	public function filter_resume_can_open( bool $can_open, int $user_id, int $object_id ): bool {
		return $can_open && $this->can_access_step( $user_id, $object_id );
	}

	/**
	 * Whether a user may open a course.
	 *
	 * @param int $user_id   User id, or 0 for a logged out visitor.
	 * @param int $course_id Course post id.
	 */
	public function can_access_course( int $user_id, int $course_id ): bool {
		if ( $this->can_manage( $user_id, $course_id ) ) {
			return true;
		}

		// A course that is not published has no learners yet, whatever its mode.
		if ( 'publish' !== get_post_status( $course_id ) ) {
			return false;
		}

		$mode = (string) get_post_meta( $course_id, Meta::ACCESS_MODE, true );

		if ( 'open' === $mode ) {
			$this->record_open_enrollment( $user_id, $course_id );

			return true;
		}

		$allowed = $user_id > 0 && $this->enrollments->has_access( $user_id, $course_id );

		/**
		 * Filters whether a user may access a course.
		 *
		 * @param bool $allowed   Whether access is granted.
		 * @param int  $user_id   User id.
		 * @param int  $course_id Course post id.
		 */
		return (bool) apply_filters( 'odsi_lms_can_access_course', $allowed, $user_id, $course_id );
	}

	/**
	 * Whether a user may open a single lesson, topic or quiz.
	 *
	 * @param int $user_id   User id.
	 * @param int $object_id Step post id.
	 */
	public function can_access_step( int $user_id, int $object_id ): bool {
		$course_id = $this->structure->course_id_for( $object_id );

		if ( $this->can_manage( $user_id, $course_id ) ) {
			return true;
		}

		// Drafts are the author's; a learner cannot open, complete or attempt
		// them before they are published, even with a sequential id in hand.
		if ( 'publish' !== get_post_status( $object_id ) || ! $this->can_access_course( $user_id, $course_id ) ) {
			return false;
		}

		$allowed = $this->is_dripped( $user_id, $object_id, $course_id )
			&& $this->passes_linear_progression( $user_id, $object_id, $course_id );

		/**
		 * Filters whether a user may access a single course step.
		 *
		 * @param bool $allowed   Whether access is granted.
		 * @param int  $user_id   User id.
		 * @param int  $object_id Step post id.
		 * @param int  $course_id Course post id.
		 */
		return (bool) apply_filters( 'odsi_lms_can_access_step', $allowed, $user_id, $object_id, $course_id );
	}

	/**
	 * Replace locked content with a notice on the front end.
	 *
	 * @param string $content Post content.
	 *
	 * @return string
	 */
	public function filter_content( string $content ): string {
		// Every place WordPress renders a step's content passes through here:
		// the singular page, block-theme query loops, feeds, search excerpts and
		// the core REST API's `content.rendered`. There is deliberately no
		// is_singular() guard, because that is exactly what let feeds and REST
		// bypass the lock (LMS-ACC-002/007).
		if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $content;
		}

		$post_id = get_the_ID();

		if ( ! $post_id ) {
			return $content;
		}

		$user_id = get_current_user_id();
		$type    = (string) get_post_type( $post_id );

		if ( PostTypes::COURSE === $type ) {
			// The course page itself stays readable; it is the sales and outline
			// page. Individual steps are what get locked.
			return $content;
		}

		if ( ! in_array( $type, PostTypes::trackable(), true ) ) {
			return $content;
		}

		$reason = $this->lock_reason( $user_id, $post_id );

		if ( '' === $reason ) {
			return $content;
		}

		return $this->locked_notice( $post_id, $user_id, $reason );
	}

	/**
	 * A hand-written excerpt bypasses `the_content`, so archives, search and
	 * the REST API would show it for a locked step. Blank it instead.
	 *
	 * @param string        $excerpt Excerpt.
	 * @param \WP_Post|null $post    Post.
	 */
	public function filter_excerpt( string $excerpt, ?\WP_Post $post = null ): string {
		if ( ! $post || ! in_array( $post->post_type, PostTypes::trackable(), true ) ) {
			return $excerpt;
		}

		return '' === $this->lock_reason( get_current_user_id(), (int) $post->ID ) ? $excerpt : '';
	}

	/**
	 * Feed excerpts run through a different filter with no post argument.
	 *
	 * @param string $excerpt Excerpt.
	 */
	public function filter_excerpt_rss( string $excerpt ): string {
		$post = get_post();

		return $post ? $this->filter_excerpt( $excerpt, $post ) : $excerpt;
	}

	/**
	 * The content a viewer may see for a step: the notice when locked, else ''.
	 *
	 * @param int $object_id Step post id.
	 */
	public function filter_content_for( int $object_id ): string {
		$reason = $this->lock_reason( get_current_user_id(), $object_id );

		return '' === $reason ? '' : $this->locked_notice( $object_id, get_current_user_id(), $reason );
	}

	/**
	 * Why a user cannot open a node: `enroll`, `drip`, `progression`, or '' when they can.
	 *
	 * Evaluated in that order so the most fundamental reason wins (LMS-ACC-006).
	 *
	 * @param int $user_id   User id.
	 * @param int $object_id Node post id.
	 */
	public function lock_reason( int $user_id, int $object_id ): string {
		$course_id = $this->structure->course_id_for( $object_id );

		if ( $this->can_manage( $user_id, $course_id ) ) {
			return '';
		}

		if ( ! $this->can_access_course( $user_id, $course_id ) || 'publish' !== get_post_status( $object_id ) ) {
			return 'enroll';
		}

		if ( ! $this->is_dripped( $user_id, $object_id, $course_id ) ) {
			return 'drip';
		}

		if ( ! $this->passes_linear_progression( $user_id, $object_id, $course_id ) ) {
			return 'progression';
		}

		return $this->can_access_step( $user_id, $object_id ) ? '' : 'progression';
	}

	/**
	 * On an open course, a logged-in visitor's first access becomes an
	 * enrollment with `source = open`, so that drip schedules keyed to the
	 * enrollment date have a date to key to (LMS-ACC-003).
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 */
	private function record_open_enrollment( int $user_id, int $course_id ): void {
		if ( $user_id <= 0 || $this->enrollments->find_for( $user_id, $course_id ) ) {
			return;
		}

		$this->enrollments->enroll( $user_id, $course_id, array( 'source' => 'open' ) );
	}

	/**
	 * Whether the drip schedule has released a step for this user.
	 *
	 * @param int $user_id   User id.
	 * @param int $object_id Step post id.
	 * @param int $course_id Course post id.
	 */
	private function is_dripped( int $user_id, int $object_id, int $course_id ): bool {
		$type = (string) get_post_meta( $object_id, Meta::DRIP_TYPE, true );

		if ( '' === $type || 'none' === $type ) {
			return true;
		}

		$value = (string) get_post_meta( $object_id, Meta::DRIP_VALUE, true );

		if ( 'date' === $type ) {
			return '' === $value || strtotime( $value ) <= time();
		}

		if ( 'days_after_enrollment' === $type ) {
			$enrollment = $this->enrollments->find_for( $user_id, $course_id );

			if ( ! $enrollment ) {
				return false;
			}

			$unlocks = strtotime( (string) $enrollment->enrolled_at ) + ( (int) $value * DAY_IN_SECONDS );

			return $unlocks <= time();
		}

		return true;
	}

	/**
	 * Whether the previous step has been completed, when the course is linear.
	 *
	 * @param int $user_id   User id.
	 * @param int $object_id Step post id.
	 * @param int $course_id Course post id.
	 */
	private function passes_linear_progression( int $user_id, int $object_id, int $course_id ): bool {
		if ( ! get_post_meta( $course_id, Meta::LINEAR_PROGRESSION, true ) ) {
			return true;
		}

		$gate = $this->structure->gate( $course_id, $object_id );

		if ( null === $gate ) {
			return true;
		}

		return $this->progress->is_completed( $user_id, $gate['id'] );
	}

	/**
	 * Whether the user is an instructor or admin for this course.
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course post id.
	 */
	private function can_manage( int $user_id, int $course_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( user_can( $user_id, Capabilities::MANAGE ) ) {
			return true;
		}

		return $course_id > 0 && (int) get_post_field( 'post_author', $course_id ) === $user_id;
	}

	/**
	 * Markup shown in place of locked content, naming the reason (LMS-ACC-006).
	 *
	 * @param int    $object_id Step post id.
	 * @param int    $user_id   User id.
	 * @param string $reason    `enroll`, `drip` or `progression`.
	 */
	private function locked_notice( int $object_id, int $user_id, string $reason ): string {
		$course_id = $this->structure->course_id_for( $object_id );

		switch ( $reason ) {
			case 'drip':
				$value   = (string) get_post_meta( $object_id, Meta::DRIP_VALUE, true );
				$type    = (string) get_post_meta( $object_id, Meta::DRIP_TYPE, true );
				$message = __( 'This lesson is not available yet.', 'odsi-lms' );

				if ( 'date' === $type && '' !== $value ) {
					$message = sprintf(
						/* translators: %s: formatted date. */
						__( 'This lesson will be available on %s.', 'odsi-lms' ),
						wp_date( (string) get_option( 'date_format' ), (int) strtotime( $value ) )
					);
				}
				break;

			case 'progression':
				$message = __( 'Complete the previous step to unlock this one.', 'odsi-lms' );
				break;

			default:
				$message = $user_id > 0
					? __( 'Enroll on this course to view this lesson.', 'odsi-lms' )
					: __( 'Please log in and enroll on this course to view this lesson.', 'odsi-lms' );
		}//end switch

		$html = sprintf(
			'<div class="odsi-lms-locked odsi-lms-locked--%1$s"><p>%2$s</p>',
			esc_attr( $reason ),
			esc_html( $message )
		);

		if ( $course_id > 0 ) {
			$html .= sprintf(
				'<p><a class="odsi-lms-locked__link" href="%s">%s</a></p>',
				esc_url( (string) get_permalink( $course_id ) ),
				esc_html__( 'Back to the course', 'odsi-lms' )
			);
		}

		return $html . '</div>';
	}
}
