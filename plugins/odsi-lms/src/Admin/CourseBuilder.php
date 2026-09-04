<?php
/**
 * Course relationship meta boxes.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Admin;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Capabilities;
use ODSI\LMS\Support\Meta;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Classic meta boxes for attaching lessons, topics and quizzes to a course.
 *
 * These are the always-available fallback. The React drag-and-drop builder,
 * once compiled, renders over the same meta keys rather than replacing them, so
 * the data model stays identical either way.
 */
final class CourseBuilder implements Bootable {

	private const NONCE_ACTION = 'odsi_lms_save_relationships';
	private const NONCE_FIELD  = 'odsi_lms_relationships_nonce';

	/**
	 * Constructor.
	 *
	 * @param Structure $structure Course outline resolver.
	 */
	public function __construct( private Structure $structure ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Add the relationship meta box to every child post type.
	 */
	public function register_meta_boxes(): void {
		foreach ( array( PostTypes::LESSON, PostTypes::TOPIC, PostTypes::QUIZ, PostTypes::QUESTION ) as $post_type ) {
			add_meta_box(
				'odsi-lms-relationships',
				__( 'Course placement', 'odsi-lms' ),
				array( $this, 'render' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$type = $post->post_type;

		if ( PostTypes::QUESTION === $type ) {
			$this->render_select(
				Meta::QUIZ_ID,
				__( 'Quiz', 'odsi-lms' ),
				PostTypes::QUIZ,
				(int) get_post_meta( $post->ID, Meta::QUIZ_ID, true )
			);

			return;
		}

		$this->render_select(
			Meta::COURSE_ID,
			__( 'Course', 'odsi-lms' ),
			PostTypes::COURSE,
			(int) get_post_meta( $post->ID, Meta::COURSE_ID, true )
		);

		if ( in_array( $type, array( PostTypes::TOPIC, PostTypes::QUIZ ), true ) ) {
			$this->render_select(
				Meta::LESSON_ID,
				__( 'Lesson', 'odsi-lms' ),
				PostTypes::LESSON,
				(int) get_post_meta( $post->ID, Meta::LESSON_ID, true )
			);
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Ordering follows the Order field under Page Attributes.', 'odsi-lms' )
		);
	}

	/**
	 * Persist the submitted relationships.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post object.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! in_array( $post->post_type, array( PostTypes::LESSON, PostTypes::TOPIC, PostTypes::QUIZ, PostTypes::QUESTION ), true ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE ) && ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( array( Meta::COURSE_ID, Meta::LESSON_ID, Meta::QUIZ_ID ) as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			update_post_meta( $post_id, $key, absint( wp_unslash( $_POST[ $key ] ) ) );
		}

		// A topic or quiz always inherits its course from the lesson it sits under,
		// so the two can never drift apart.
		$lesson_id = (int) get_post_meta( $post_id, Meta::LESSON_ID, true );

		if ( $lesson_id > 0 && in_array( $post->post_type, array( PostTypes::TOPIC, PostTypes::QUIZ ), true ) ) {
			update_post_meta( $post_id, Meta::COURSE_ID, (int) get_post_meta( $lesson_id, Meta::COURSE_ID, true ) );
		}

		$this->structure->flush();
	}

	/**
	 * Render a single post-picker select.
	 *
	 * @param string $name      Field name and meta key.
	 * @param string $label     Field label.
	 * @param string $post_type Post type to list.
	 * @param int    $selected  Currently selected post id.
	 */
	private function render_select( string $name, string $label, string $post_type, int $selected ): void {
		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'posts_per_page'         => 200,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		printf( '<p><label for="%1$s"><strong>%2$s</strong></label><br />', esc_attr( $name ), esc_html( $label ) );
		printf( '<select id="%1$s" name="%1$s" class="widefat">', esc_attr( $name ) );
		printf( '<option value="0">%s</option>', esc_html__( '— None —', 'odsi-lms' ) );

		foreach ( $query->posts as $post_id ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $post_id,
				selected( $selected, (int) $post_id, false ),
				esc_html( (string) get_the_title( (int) $post_id ) )
			);
		}

		echo '</select></p>';
	}
}
