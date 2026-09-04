<?php
/**
 * Front-end shortcodes.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Frontend;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Courses\Access;
use ODSI\LMS\Courses\Enrollment;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Meta;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the course player, outline and dashboards as shortcodes.
 *
 * Shortcodes are the lowest common denominator that works in every theme and
 * page builder; the same renderers are reused by the block equivalents.
 */
final class Shortcodes implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param Structure  $structure  Course outline resolver.
	 * @param Progress   $progress   Progress service.
	 * @param Enrollment $enrollment Enrollment service.
	 * @param Access     $access     Access rules.
	 * @param Templates  $templates  Template loader.
	 */
	public function __construct(
		private Structure $structure,
		private Progress $progress,
		private Enrollment $enrollment,
		private Access $access,
		private Templates $templates
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_shortcode( 'odsi_course_outline', array( $this, 'render_outline' ) );
		add_shortcode( 'odsi_course_progress', array( $this, 'render_progress' ) );
		add_shortcode( 'odsi_enroll_button', array( $this, 'render_enroll_button' ) );
		add_shortcode( 'odsi_my_courses', array( $this, 'render_my_courses' ) );
		add_shortcode( 'odsi_course_grid', array( $this, 'render_course_grid' ) );
	}

	/**
	 * `[odsi_course_outline course_id="123"]`
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function render_outline( array|string $atts = array() ): string {
		$atts      = shortcode_atts( array( 'course_id' => 0 ), (array) $atts, 'odsi_course_outline' );
		$course_id = $this->resolve_course_id( (int) $atts['course_id'] );

		if ( $course_id <= 0 ) {
			return '';
		}

		$user_id = get_current_user_id();

		return $this->templates->render(
			'parts/course-outline',
			array(
				'course_id' => $course_id,
				'steps'     => $this->structure->outline( $course_id ),
				'completed' => $this->progress->repository()->completed_ids( $user_id, $course_id ),
				'access'    => $this->access,
				'user_id'   => $user_id,
			)
		);
	}

	/**
	 * `[odsi_course_progress course_id="123"]`
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function render_progress( array|string $atts = array() ): string {
		$atts      = shortcode_atts( array( 'course_id' => 0 ), (array) $atts, 'odsi_course_progress' );
		$course_id = $this->resolve_course_id( (int) $atts['course_id'] );
		$user_id   = get_current_user_id();

		if ( $course_id <= 0 || $user_id <= 0 ) {
			return '';
		}

		return $this->templates->render(
			'parts/progress-bar',
			array(
				'course_id'  => $course_id,
				'percentage' => $this->progress->course_percentage( $user_id, $course_id ),
				'completed'  => $this->progress->completed_count( $user_id, $course_id ),
				'total'      => $this->structure->total_steps( $course_id ),
			)
		);
	}

	/**
	 * `[odsi_enroll_button course_id="123"]`
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function render_enroll_button( array|string $atts = array() ): string {
		$atts      = shortcode_atts( array( 'course_id' => 0 ), (array) $atts, 'odsi_enroll_button' );
		$course_id = $this->resolve_course_id( (int) $atts['course_id'] );

		if ( $course_id <= 0 ) {
			return '';
		}

		$user_id = get_current_user_id();

		$is_enrolled = $user_id > 0 && $this->enrollment->is_enrolled( $user_id, $course_id );
		$resume_id   = $is_enrolled ? $this->progress->resume_step( $user_id, $course_id ) : 0;

		return $this->templates->render(
			'parts/enroll-button',
			array(
				'course_id'   => $course_id,
				'user_id'     => $user_id,
				'is_enrolled' => $is_enrolled,
				'access_mode' => (string) get_post_meta( $course_id, Meta::ACCESS_MODE, true ) ?: 'free',
				'next_step'   => $resume_id > 0 ? array( 'id' => $resume_id ) : ( $this->structure->outline( $course_id )[0] ?? null ),
			)
		);
	}

	/**
	 * `[odsi_my_courses status="active"]`
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function render_my_courses( array|string $atts = array() ): string {
		$atts    = shortcode_atts( array( 'status' => '' ), (array) $atts, 'odsi_my_courses' );
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return $this->templates->render( 'parts/login-required' );
		}

		$status     = '' !== $atts['status'] ? (string) $atts['status'] : null;
		$course_ids = $this->enrollment->courses_for( $user_id, $status );

		return $this->templates->render(
			'parts/my-courses',
			array(
				'course_ids' => $course_ids,
				'progress'   => $this->progress,
				'user_id'    => $user_id,
			)
		);
	}

	/**
	 * `[odsi_course_grid per_page="9" category="design"]`
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function render_course_grid( array|string $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'per_page' => 9,
				'category' => '',
			),
			(array) $atts,
			'odsi_course_grid'
		);

		$args = array(
			'post_type'      => PostTypes::COURSE,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, (int) $atts['per_page'] ),
			'paged'          => max( 1, (int) get_query_var( 'paged' ) ),
		);

		if ( '' !== $atts['category'] ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => \ODSI\LMS\PostTypes\Taxonomies::COURSE_CATEGORY,
					'field'    => 'slug',
					'terms'    => array_map( 'trim', explode( ',', (string) $atts['category'] ) ),
				),
			);
		}

		return $this->templates->render(
			'parts/course-grid',
			array( 'query' => new WP_Query( $args ) )
		);
	}

	/**
	 * Fall back to the current post's course when no id was given.
	 *
	 * @param int $course_id Course id from the shortcode, or 0.
	 */
	private function resolve_course_id( int $course_id ): int {
		if ( $course_id > 0 ) {
			return $course_id;
		}

		$post_id = get_the_ID();

		if ( ! $post_id ) {
			return 0;
		}

		if ( PostTypes::COURSE === get_post_type( $post_id ) ) {
			return (int) $post_id;
		}

		return $this->structure->course_id_for( (int) $post_id );
	}
}
