<?php
/**
 * Duplicate a course with its whole outline.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Courses;

defined( 'ABSPATH' ) || exit;

use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Meta;
use WP_Query;

/**
 * Copies the course post, every lesson, topic, quiz and question under it
 * (drafts included) with their meta and terms, rewiring the parent links to
 * the copies (LMS-AUT-013). The copy is a draft owned by the actor; learner
 * data, certificates and the commerce product link are not copied.
 */
final class Cloner {

	/**
	 * Meta keys that must not travel to the copy.
	 */
	private const SKIP_META = array( Meta::WC_PRODUCT_ID, '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date' );

	/**
	 * Duplicate a course.
	 *
	 * @param int $course_id Source course.
	 * @param int $actor_id  User who becomes the author; must be allowed to edit the source.
	 *
	 * @return int New course id, 0 when refused.
	 */
	public function duplicate( int $course_id, int $actor_id ): int {
		if ( PostTypes::COURSE !== get_post_type( $course_id ) || $actor_id <= 0 || ! user_can( $actor_id, 'edit_post', $course_id ) ) {
			return 0;
		}

		$type = get_post_type_object( PostTypes::COURSE );

		if ( ! $type || ! user_can( $actor_id, $type->cap->create_posts ) ) {
			return 0;
		}

		$map = array();

		/* translators: %s: course title. */
		$new_course = $this->copy_post( $course_id, $actor_id, sprintf( __( 'Copy of %s', 'odsi-lms' ), (string) get_the_title( $course_id ) ) );

		if ( $new_course <= 0 ) {
			return 0;
		}

		$map[ $course_id ] = $new_course;

		foreach ( $this->children( PostTypes::LESSON, Meta::COURSE_ID, $course_id ) as $lesson_id ) {
			$new_lesson = $this->copy_post( $lesson_id, $actor_id );

			if ( $new_lesson <= 0 ) {
				continue;
			}

			$map[ $lesson_id ] = $new_lesson;
			update_post_meta( $new_lesson, Meta::COURSE_ID, $new_course );

			foreach ( $this->children( PostTypes::TOPIC, Meta::LESSON_ID, $lesson_id ) as $topic_id ) {
				$new_topic = $this->copy_post( $topic_id, $actor_id );

				if ( $new_topic > 0 ) {
					$map[ $topic_id ] = $new_topic;
					update_post_meta( $new_topic, Meta::LESSON_ID, $new_lesson );
					update_post_meta( $new_topic, Meta::COURSE_ID, $new_course );
				}
			}
		}

		// Quizzes hang off the course, a lesson or a topic; the map resolves
		// whichever parent they had.
		foreach ( $this->children( PostTypes::QUIZ, Meta::COURSE_ID, $course_id ) as $quiz_id ) {
			$new_quiz = $this->copy_post( $quiz_id, $actor_id );

			if ( $new_quiz <= 0 ) {
				continue;
			}

			$map[ $quiz_id ] = $new_quiz;
			update_post_meta( $new_quiz, Meta::COURSE_ID, $new_course );

			$parent = (int) get_post_meta( $quiz_id, Meta::LESSON_ID, true );

			if ( $parent > 0 ) {
				update_post_meta( $new_quiz, Meta::LESSON_ID, $map[ $parent ] ?? 0 );
			}

			foreach ( $this->children( PostTypes::QUESTION, Meta::QUIZ_ID, $quiz_id ) as $question_id ) {
				$new_question = $this->copy_post( $question_id, $actor_id );

				if ( $new_question > 0 ) {
					$map[ $question_id ] = $new_question;
					update_post_meta( $new_question, Meta::QUIZ_ID, $new_quiz );
				}
			}
		}//end foreach

		// Prerequisites that pointed at the source now point at the source
		// still (a copy of "Advanced" still requires "Basics"); a self
		// reference is dropped by the access rule.
		/**
		 * Fires once a course and its outline were duplicated.
		 *
		 * @param int                $new_course Copy.
		 * @param int                $course_id  Source.
		 * @param array<int, int>    $map        Source id => copy id for every post.
		 */
		do_action( 'odsi_lms_course_duplicated', $new_course, $course_id, $map );

		return $new_course;
	}

	/**
	 * Copy one post as a draft with its meta and terms.
	 *
	 * @param int         $post_id  Source.
	 * @param int         $actor_id Author of the copy.
	 * @param string|null $title    Override title.
	 */
	private function copy_post( int $post_id, int $actor_id, ?string $title = null ): int {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return 0;
		}

		$new_id = wp_insert_post(
			array(
				'post_type'    => $post->post_type,
				'post_status'  => 'draft',
				'post_author'  => $actor_id,
				'post_title'   => $title ?? $post->post_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'menu_order'   => $post->menu_order,
				'post_parent'  => 0,
			),
			true
		);

		if ( is_wp_error( $new_id ) || $new_id <= 0 ) {
			return 0;
		}

		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( in_array( $key, self::SKIP_META, true ) ) {
				continue;
			}

			foreach ( (array) $values as $value ) {
				add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
			}
		}

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );

			if ( ! is_wp_error( $terms ) && array() !== $terms ) {
				wp_set_object_terms( $new_id, array_map( 'intval', $terms ), $taxonomy );
			}
		}

		return (int) $new_id;
	}

	/**
	 * Children of a node by parent meta, every status but trash, in order.
	 *
	 * @param string $post_type Child type.
	 * @param string $meta_key  Parent key.
	 * @param int    $parent_id Parent id.
	 *
	 * @return int[]
	 */
	private function children( string $post_type, string $meta_key, int $parent_id ): array {
		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded admin/outline read.
				'fields'                 => 'ids',
				'orderby'                => array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
				),
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- outline membership is meta by design.
					array(
						'key'   => $meta_key,
						'value' => $parent_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}
}
