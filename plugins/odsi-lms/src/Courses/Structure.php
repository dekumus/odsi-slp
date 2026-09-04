<?php
/**
 * Course outline resolution.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Courses;

use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Meta;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a course into an ordered, flattened list of steps.
 *
 * The outline is the single source of truth for navigation, progress percentages
 * and linear-progression locking, so every consumer sees the same ordering.
 */
final class Structure {

	/**
	 * Cached outlines for the current request, keyed by course id.
	 *
	 * @var array<int, array<int, array{id: int, type: string, parent: int, depth: int}>>
	 */
	private array $cache = array();

	/**
	 * Flattened, ordered outline for a course.
	 *
	 * Lessons come first in `menu_order`, each followed by its topics and then any
	 * quiz attached to it; course level quizzes come last.
	 *
	 * @param int $course_id Course post id.
	 *
	 * @return array<int, array{id: int, type: string, parent: int, depth: int}>
	 */
	public function outline( int $course_id ): array {
		if ( isset( $this->cache[ $course_id ] ) ) {
			return $this->cache[ $course_id ];
		}

		$steps = array();

		foreach ( $this->lessons( $course_id ) as $lesson_id ) {
			$steps[] = array(
				'id'     => $lesson_id,
				'type'   => PostTypes::LESSON,
				'parent' => $course_id,
				'depth'  => 0,
			);

			foreach ( $this->topics( $lesson_id ) as $topic_id ) {
				$steps[] = array(
					'id'     => $topic_id,
					'type'   => PostTypes::TOPIC,
					'parent' => $lesson_id,
					'depth'  => 1,
				);

				foreach ( $this->quizzes_for( $topic_id ) as $quiz_id ) {
					$steps[] = array(
						'id'     => $quiz_id,
						'type'   => PostTypes::QUIZ,
						'parent' => $topic_id,
						'depth'  => 2,
					);
				}
			}

			foreach ( $this->quizzes_for( $lesson_id ) as $quiz_id ) {
				$steps[] = array(
					'id'     => $quiz_id,
					'type'   => PostTypes::QUIZ,
					'parent' => $lesson_id,
					'depth'  => 1,
				);
			}
		}//end foreach

		foreach ( $this->course_quizzes( $course_id ) as $quiz_id ) {
			$steps[] = array(
				'id'     => $quiz_id,
				'type'   => PostTypes::QUIZ,
				'parent' => $course_id,
				'depth'  => 0,
			);
		}

		/**
		 * Filters a course outline after it has been assembled.
		 *
		 * @param array<int, array{id: int, type: string, parent: int, depth: int}> $steps     Ordered steps.
		 * @param int                                                               $course_id Course post id.
		 */
		$steps = (array) apply_filters( 'odsi_lms_course_outline', $steps, $course_id );

		$this->cache[ $course_id ] = $steps;

		return $steps;
	}

	/**
	 * Step post ids in outline order.
	 *
	 * @param int $course_id Course post id.
	 *
	 * @return int[]
	 */
	public function step_ids( int $course_id ): array {
		return array_map(
			static fn ( array $step ): int => $step['id'],
			$this->outline( $course_id )
		);
	}

	/**
	 * Total number of trackable steps in a course.
	 *
	 * @param int $course_id Course post id.
	 */
	public function total_steps( int $course_id ): int {
		return count( $this->outline( $course_id ) );
	}

	/**
	 * Lesson ids belonging to a course, in `menu_order`.
	 *
	 * @param int $course_id Course post id.
	 *
	 * @return int[]
	 */
	public function lessons( int $course_id ): array {
		return $this->children( PostTypes::LESSON, Meta::COURSE_ID, $course_id );
	}

	/**
	 * Topic ids belonging to a lesson, in `menu_order`.
	 *
	 * @param int $lesson_id Lesson post id.
	 *
	 * @return int[]
	 */
	public function topics( int $lesson_id ): array {
		return $this->children( PostTypes::TOPIC, Meta::LESSON_ID, $lesson_id );
	}

	/**
	 * Quiz ids attached to a lesson or topic.
	 *
	 * @param int $parent_id Lesson or topic post id.
	 *
	 * @return int[]
	 */
	public function quizzes_for( int $parent_id ): array {
		return $this->children( PostTypes::QUIZ, Meta::LESSON_ID, $parent_id );
	}

	/**
	 * Quiz ids attached directly to a course rather than to a lesson.
	 *
	 * @param int $course_id Course post id.
	 *
	 * @return int[]
	 */
	public function course_quizzes( int $course_id ): array {
		$query = new WP_Query(
			array(
				'post_type'              => PostTypes::QUIZ,
				'post_status'            => 'publish',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded internal outline query, not a listing.
				'posts_per_page'         => 200,
				'orderby'                => array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
				),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => Meta::COURSE_ID,
						'value' => $course_id,
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => Meta::LESSON_ID,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'   => Meta::LESSON_ID,
							'value' => 0,
						),
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * The course a step belongs to.
	 *
	 * @param int $object_id Lesson, topic or quiz post id.
	 */
	public function course_id_for( int $object_id ): int {
		return (int) get_post_meta( $object_id, Meta::COURSE_ID, true );
	}

	/**
	 * The step that follows the given one, or null at the end of the course.
	 *
	 * @param int $course_id Course post id.
	 * @param int $object_id Current step post id.
	 *
	 * @return array{id: int, type: string, parent: int, depth: int}|null
	 */
	public function next_step( int $course_id, int $object_id ): ?array {
		$steps = $this->outline( $course_id );
		$ids   = array_column( $steps, 'id' );
		$index = array_search( $object_id, $ids, true );

		if ( false === $index ) {
			return $steps[0] ?? null;
		}

		return $steps[ $index + 1 ] ?? null;
	}

	/**
	 * The step that precedes the given one, or null at the start of the course.
	 *
	 * @param int $course_id Course post id.
	 * @param int $object_id Current step post id.
	 *
	 * @return array{id: int, type: string, parent: int, depth: int}|null
	 */
	public function previous_step( int $course_id, int $object_id ): ?array {
		$steps = $this->outline( $course_id );
		$ids   = array_column( $steps, 'id' );
		$index = array_search( $object_id, $ids, true );

		if ( false === $index || 0 === $index ) {
			return null;
		}

		return $steps[ $index - 1 ] ?? null;
	}

	/**
	 * Discard the in-request outline cache for a course.
	 *
	 * @param int|null $course_id Course post id, or null to clear everything.
	 */
	public function flush( ?int $course_id = null ): void {
		if ( null === $course_id ) {
			$this->cache = array();

			return;
		}

		unset( $this->cache[ $course_id ] );
	}

	/**
	 * Ordered child post ids matching a single meta relationship.
	 *
	 * @param string $post_type Child post type.
	 * @param string $meta_key  Meta key holding the parent id.
	 * @param int    $parent_id Parent post id.
	 *
	 * @return int[]
	 */
	private function children( string $post_type, string $meta_key, int $parent_id ): array {
		if ( $parent_id <= 0 ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded internal outline query, not a listing.
				'posts_per_page'         => 500,
				'orderby'                => array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
				),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'               => $meta_key,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'             => $parent_id,
			)
		);

		return array_map( 'intval', $query->posts );
	}
}
