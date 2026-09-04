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
 * Adds the progress bar, enroll button, outline, mark-complete control and
 * quiz player to course content through `the_content`, so the LMS works on
 * block themes and classic themes alike without the theme knowing about it.
 *
 * The plugin's own PHP templates only wrap the content; everything LMS-shaped
 * comes from here, so both paths render the same.
 */
final class ContentDecorator implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param Structure   $structure Outline.
	 * @param Progress    $progress  Progress.
	 * @param Access      $access    Access.
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
		if ( ! is_singular() || is_admin() ) {
			return $content;
		}

		$post_id = (int) get_the_ID();

		if ( $post_id !== (int) get_queried_object_id() ) {
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

		return match ( $type ) {
			PostTypes::COURSE => $this->course( $content, $post_id ),
			PostTypes::LESSON,
			PostTypes::TOPIC  => $this->step( $content, $post_id ),
			PostTypes::QUIZ   => $this->quiz( $content, $post_id ),
			default           => $content,
		};
	}

	/**
	 * Course page: progress, content, enroll, outline.
	 *
	 * @param string $content Content.
	 * @param int    $course_id Course.
	 */
	private function course( string $content, int $course_id ): string {
		$progress = get_current_user_id() > 0 ? $this->shortcodes->render_progress( array( 'course_id' => (string) $course_id ) ) : '';
		$enroll   = $this->shortcodes->render_enroll_button( array( 'course_id' => (string) $course_id ) );
		$outline  = $this->shortcodes->render_outline( array( 'course_id' => (string) $course_id ) );

		return $progress . $content . $enroll . '<section class="odsi-lms-course__outline"><h2>' . esc_html__( 'Course content', 'odsi-lms' ) . '</h2>' . $outline . '</section>';
	}

	/**
	 * Lesson or topic: content, mark-complete, outline.
	 *
	 * @param string $content Content (already replaced by a notice when locked).
	 * @param int    $step_id Step.
	 */
	private function step( string $content, int $step_id ): string {
		$user_id   = get_current_user_id();
		$course_id = $this->structure->course_id_for( $step_id );
		$controls  = '';

		if ( $user_id > 0 && $course_id > 0 && $this->access->can_access_step( $user_id, $step_id ) && ! $this->structure->is_section( $step_id ) ) {
			$done     = $this->progress->repository()->is_completed( $user_id, $step_id );
			$controls = '<footer class="odsi-lms-lesson__footer">';

			if ( $this->assignments->requires_assignment( $step_id ) ) {
				$controls .= $this->assignment( $user_id, $step_id );
			} else {
				$controls .= $done
					? '<button type="button" class="odsi-lms-button odsi-lms-complete is-complete" disabled>' . esc_html__( 'Completed', 'odsi-lms' ) . '</button>'
					: '<button type="button" class="odsi-lms-button odsi-lms-complete" data-step-id="' . esc_attr( (string) $step_id ) . '">' . esc_html__( 'Mark complete', 'odsi-lms' ) . '</button>';
			}

			$controls .= '</footer>';
		}

		$outline = $course_id > 0 ? '<aside class="odsi-lms-lesson__outline">' . $this->shortcodes->render_outline( array( 'course_id' => (string) $course_id ) ) . '</aside>' : '';

		return $content . $controls . $outline;
	}

	/**
	 * Assignment form and history for a step.
	 *
	 * @param int $user_id Learner.
	 * @param int $step_id Step.
	 */
	private function assignment( int $user_id, int $step_id ): string {
		$history = array_map( array( $this->assignments, 'present' ), $this->assignments->repository()->history( $user_id, $step_id ) );
		$latest  = $history[0] ?? null;
		$accept  = implode( ',', array_map( static fn ( string $ext ): string => '.' . $ext, explode( '|', implode( '|', array_keys( $this->assignments->allowed_mimes() ) ) ) ) );

		return $this->templates->render(
			'parts/assignment',
			array(
				'step_id'         => $step_id,
				'points_possible' => $this->assignments->points( $step_id ),
				'latest'          => $latest,
				'history'         => $history,
				'can_submit'      => null === $latest || 'rejected' === $latest['status'],
				'accept'          => $accept,
			)
		);
	}

	/**
	 * Quiz: content then the player mount.
	 *
	 * @param string $content Content.
	 * @param int    $quiz_id Quiz.
	 */
	private function quiz( string $content, int $quiz_id ): string {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return $content . '<p><a href="' . esc_url( wp_login_url( (string) get_permalink( $quiz_id ) ) ) . '">' . esc_html__( 'Log in to take this quiz.', 'odsi-lms' ) . '</a></p>';
		}

		if ( ! $this->access->can_access_step( $user_id, $quiz_id ) ) {
			return $content;
		}

		return $content . '<div class="odsi-lms-quiz__player" data-quiz-id="' . esc_attr( (string) $quiz_id ) . '"><button type="button" class="odsi-lms-button" disabled>' . esc_html__( 'Start quiz', 'odsi-lms' ) . '</button><noscript><p>' . esc_html__( 'JavaScript is required to take this quiz.', 'odsi-lms' ) . '</p></noscript></div>';
	}
}
