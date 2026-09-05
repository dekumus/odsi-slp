<?php
/**
 * Course UI injected into post content.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Frontend;

use ODSI\LMS\Assignments\Assignments;
use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Courses\Access;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\PostTypes\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the progress bar, enroll button, outline, step navigation, mark-complete
 * control, assignment form and quiz player to course content through
 * `the_content`, so the LMS works on block themes and classic themes alike
 * without the theme knowing about it (ADR-017).
 *
 * The plugin's own PHP templates only wrap the content; everything LMS-shaped
 * comes from here, so both paths render the same markup. The theme supplies
 * the page's `h1`, so every heading emitted here starts at `h2`.
 */
final class ContentDecorator implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param Structure   $structure   Outline.
	 * @param Progress    $progress    Progress.
	 * @param Access      $access      Access.
	 * @param Shortcodes  $shortcodes  Shortcode renderers.
	 * @param Assignments $assignments Assignments.
	 * @param Templates   $templates   Template loader.
	 */
	public function __construct(
		private Structure $structure,
		private Progress $progress,
		private Access $access,
		private Shortcodes $shortcodes,
		private Assignments $assignments,
		private Templates $templates
	) {
	}

	/**
	 * Marker every decorated page carries; content that already has it is
	 * never decorated a second time.
	 */
	private const MARKER = 'class="odsi-lms-outline-section';

	/**
	 * Re-entrancy guard: a template or shortcode rendered while decorating
	 * may itself apply `the_content`.
	 *
	 * @var bool
	 */
	private bool $decorating = false;

	/**
	 * Register hooks. Runs after Access (20) has replaced locked content.
	 */
	public function boot(): void {
		add_filter( 'the_content', array( $this, 'decorate' ), 30 );
	}

	/**
	 * Decorate.
	 *
	 * @param string $content Content.
	 */
	public function decorate( string $content ): string {
		if ( ! $this->should_decorate() ) {
			return $content;
		}

		$post_id = (int) get_the_ID();

		// Only the queried post is decorated, so a query loop or a "more
		// posts" block that renders the_content for other posts stays plain.
		if ( $post_id !== (int) get_queried_object_id() ) {
			return $content;
		}

		if ( $this->decorating || str_contains( $content, self::MARKER ) ) {
			return $content;
		}

		$type = (string) get_post_type( $post_id );

		/**
		 * Filters whether the LMS decorates this post's content.
		 *
		 * @param bool $decorate Whether to decorate.
		 * @param int  $post_id  Post id.
		 */
		if ( ! apply_filters( 'odsi_lms_decorate_content', true, $post_id ) ) {
			return $content;
		}

		$this->decorating = true;

		try {
			return match ( $type ) {
				PostTypes::COURSE => $this->course( $content, $post_id ),
				PostTypes::LESSON,
				PostTypes::TOPIC  => $this->step( $content, $post_id ),
				PostTypes::QUIZ   => $this->quiz( $content, $post_id ),
				default           => $content,
			};
		} finally {
			$this->decorating = false;
		}
	}

	/**
	 * Whether this `the_content` call is the page body of the queried post.
	 *
	 * `the_content` also runs inside `wp_trim_excerpt()` (an automatic
	 * excerpt), inside secondary queries and in the admin; decorating there
	 * would put the outline and buttons into excerpts, cards and feeds.
	 */
	private function should_decorate(): bool {
		if ( is_admin() || ! is_singular() || ! is_main_query() || ! in_the_loop() ) {
			return false;
		}

		if ( doing_filter( 'get_the_excerpt' ) || doing_filter( 'the_excerpt' ) || doing_filter( 'the_excerpt_rss' ) || doing_filter( 'the_content_feed' ) || is_feed() ) {
			return false;
		}

		return true;
	}

	/**
	 * Course page: progress, content, enroll, outline.
	 *
	 * @param string $content   Content.
	 * @param int    $course_id Course.
	 */
	private function course( string $content, int $course_id ): string {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			$this->progress->reconcile( $user_id, $course_id );
		}

		$progress = $user_id > 0 ? $this->shortcodes->render_progress( array( 'course_id' => (string) $course_id ) ) : '';
		$enroll   = $this->shortcodes->render_enroll_button( array( 'course_id' => (string) $course_id ) );

		return $progress . $content . $enroll . $this->outline_section( $course_id, 'course' );
	}

	/**
	 * Lesson or topic: content, mark-complete or assignment, navigation, outline.
	 *
	 * @param string $content Content (already replaced by a notice when locked).
	 * @param int    $step_id Step.
	 */
	private function step( string $content, int $step_id ): string {
		$user_id   = get_current_user_id();
		$course_id = $this->structure->course_id_for( $step_id );
		$controls  = '';

		if ( $user_id > 0 && $course_id > 0 && $this->access->can_access_step( $user_id, $step_id ) && ! $this->structure->is_section( $step_id ) ) {
			$this->progress->touch_step( $user_id, $step_id );
			$done     = $this->progress->repository()->is_completed( $user_id, $step_id );
			$controls = '<footer class="odsi-lms-lesson__footer">';

			if ( $this->assignments->requires_assignment( $step_id ) ) {
				$controls .= $this->assignment( $user_id, $step_id );
			} else {
				$controls .= $done
					? '<button type="button" class="odsi-lms-button odsi-lms-complete odsi-lms-complete--done" data-step-id="' . esc_attr( (string) $step_id ) . '" disabled>' . esc_html__( 'Completed', 'odsi-lms' ) . '</button>'
					: '<button type="button" class="odsi-lms-button odsi-lms-complete" data-step-id="' . esc_attr( (string) $step_id ) . '">' . esc_html__( 'Mark complete', 'odsi-lms' ) . '</button>';

				// Live regions the script writes into: progress announcements and
				// request failures, so nothing is reported through alert().
				$controls .= '<p class="odsi-lms-lesson__status" role="status" aria-live="polite"></p>';
				$controls .= '<p class="odsi-lms-lesson__error" role="alert" hidden></p>';
			}

			$controls .= '</footer>';
		}

		return $content . $controls . $this->step_nav( $step_id, $course_id ) . $this->outline_section( $course_id, 'step' );
	}

	/**
	 * Assignment form and history for a step.
	 *
	 * @param int $user_id Learner.
	 * @param int $step_id Step.
	 */
	private function assignment( int $user_id, int $step_id ): string {
		$history    = array_map( array( $this->assignments, 'present' ), $this->assignments->repository()->history( $user_id, $step_id ) );
		$latest     = $history[0] ?? null;
		$extensions = explode( '|', implode( '|', array_keys( $this->assignments->allowed_mimes() ) ) );
		$max_bytes  = Assignments::max_bytes();

		return $this->templates->render(
			'parts/assignment',
			array(
				'step_id'         => $step_id,
				'points_possible' => $this->assignments->points( $step_id ),
				'latest'          => $latest,
				'history'         => $history,
				'can_submit'      => null === $latest || 'rejected' === $latest['status'],
				'accept'          => implode( ',', array_map( static fn ( string $ext ): string => '.' . $ext, $extensions ) ),
				'accept_list'     => implode( ', ', $extensions ),
				'max_bytes'       => $max_bytes,
				'max_label'       => (string) size_format( $max_bytes ),
			)
		);
	}

	/**
	 * Quiz: content, the player mount, navigation, outline.
	 *
	 * @param string $content Content (already replaced by a notice when locked).
	 * @param int    $quiz_id Quiz.
	 */
	private function quiz( string $content, int $quiz_id ): string {
		$user_id   = get_current_user_id();
		$course_id = $this->structure->course_id_for( $quiz_id );
		$after     = $this->step_nav( $quiz_id, $course_id ) . $this->outline_section( $course_id, 'step' );

		// The locked notice already says why, and for a visitor it already
		// says to log in; a second message would only repeat it.
		if ( ! $this->access->can_access_step( $user_id, $quiz_id ) ) {
			return $content . $after;
		}

		if ( $user_id <= 0 ) {
			return $content . '<p class="odsi-lms-quiz__login"><a class="odsi-lms-button" href="' . esc_url( wp_login_url( (string) get_permalink( $quiz_id ) ) ) . '">' . esc_html__( 'Log in to take this quiz', 'odsi-lms' ) . '</a></p>' . $after;
		}

		$player = '<div class="odsi-lms-quiz__player" data-quiz-id="' . esc_attr( (string) $quiz_id ) . '" tabindex="-1">'
			. '<button type="button" class="odsi-lms-button odsi-lms-quiz__start" disabled>' . esc_html__( 'Start quiz', 'odsi-lms' ) . '</button>'
			. '<noscript><p class="odsi-lms-notice odsi-lms-quiz__notice">' . esc_html__( 'JavaScript is required to take this quiz.', 'odsi-lms' ) . '</p></noscript>'
			. '</div>';

		return $content . $player . $after;
	}

	/**
	 * Previous / next / back-to-course links for a step (LMS-IF-005).
	 *
	 * A step the learner cannot open yet is shown as text marked locked and
	 * carries its id, so the script can turn it into a link the moment the
	 * current step completes.
	 *
	 * @param int $step_id   Step being read.
	 * @param int $course_id Its course.
	 */
	private function step_nav( int $step_id, int $course_id ): string {
		if ( $course_id <= 0 || ! in_array( $step_id, $this->structure->step_ids( $course_id ), true ) ) {
			return '';
		}

		$user_id  = get_current_user_id();
		$previous = $this->structure->previous_step( $course_id, $step_id );
		$next     = $this->structure->next_step( $course_id, $step_id );

		$html = '<nav class="odsi-lms-step-nav" aria-label="' . esc_attr__( 'Course navigation', 'odsi-lms' ) . '">';

		$html .= sprintf(
			'<a class="odsi-lms-step-nav__link odsi-lms-step-nav__link--course" href="%1$s">%2$s</a>',
			esc_url( (string) get_permalink( $course_id ) ),
			esc_html__( 'Back to the course', 'odsi-lms' )
		);

		if ( $previous ) {
			$html .= $this->nav_link( (int) $previous['id'], 'previous', __( 'Previous', 'odsi-lms' ), $user_id );
		}

		if ( $next ) {
			$html .= $this->nav_link( (int) $next['id'], 'next', __( 'Next', 'odsi-lms' ), $user_id );
		}

		return $html . '</nav>';
	}

	/**
	 * One step-navigation entry: a link when openable, locked text otherwise.
	 *
	 * @param int    $target_id Step linked to.
	 * @param string $direction `previous` or `next`.
	 * @param string $label     Visible direction label.
	 * @param int    $user_id   Viewer.
	 */
	private function nav_link( int $target_id, string $direction, string $label, int $user_id ): string {
		$inner = sprintf(
			'<span class="odsi-lms-step-nav__label">%1$s</span> <span class="odsi-lms-step-nav__title">%2$s</span>',
			esc_html( $label ),
			esc_html( (string) get_the_title( $target_id ) )
		);

		if ( $this->access->can_access_step( $user_id, $target_id ) ) {
			return sprintf(
				'<a class="odsi-lms-step-nav__link odsi-lms-step-nav__link--%1$s" rel="%2$s" href="%3$s">%4$s</a>',
				esc_attr( $direction ),
				'previous' === $direction ? 'prev' : 'next',
				esc_url( (string) get_permalink( $target_id ) ),
				$inner
			);
		}

		return sprintf(
			'<span class="odsi-lms-step-nav__link odsi-lms-step-nav__link--%1$s odsi-lms-step-nav__link--locked" data-step-id="%2$d">%3$s <span class="odsi-lms-step-nav__lock">%4$s</span></span>',
			esc_attr( $direction ),
			$target_id,
			$inner,
			esc_html__( 'Locked', 'odsi-lms' )
		);
	}

	/**
	 * The outline as a labelled region, identical on the course page and on
	 * every step page.
	 *
	 * @param int    $course_id Course.
	 * @param string $context   `course` or `step`, as a modifier for themes.
	 */
	private function outline_section( int $course_id, string $context ): string {
		if ( $course_id <= 0 ) {
			return '';
		}

		$outline = $this->shortcodes->render_outline( array( 'course_id' => (string) $course_id ) );

		if ( '' === $outline ) {
			return '';
		}

		$heading_id = 'odsi-lms-outline-heading-' . $course_id;

		return sprintf(
			'<section class="odsi-lms-outline-section odsi-lms-outline-section--%1$s" aria-labelledby="%2$s"><h2 class="odsi-lms-outline-section__heading" id="%2$s">%3$s</h2>%4$s</section>',
			esc_attr( $context ),
			esc_attr( $heading_id ),
			esc_html__( 'Course content', 'odsi-lms' ),
			$outline
		);
	}
}
