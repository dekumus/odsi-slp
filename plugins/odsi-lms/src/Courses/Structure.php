<?php
/**
 * Course outline resolution.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Courses;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Meta;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a course into an ordered, flattened list of steps.
 *
 * The outline is the single source of truth for navigation, progress percentages
 * and linear-progression locking, so every consumer sees the same ordering.
 */
final class Structure implements Bootable {

	/**
	 * Cached outlines for the current request, keyed by course id.
	 *
	 * @var array<int, array<int, array{id: int, type: string, parent: int, depth: int}>>
	 */
	private array $cache = array();

	/**
	 * Register hooks.
	 *
	 * Any change to a node, or to the relationship meta that places it, drops
	 * the cached outline so that a stale outline is never observable (LMS-OUT-006).
	 */
	public function boot(): void {
		add_action( 'save_post', array( $this, 'on_post_change' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'on_post_change' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'on_post_id_change' ) );
		add_action( 'untrashed_post', array( $this, 'on_post_id_change' ) );
		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );

		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'on_meta_change' ), 10, 3 );
		}
	}

	/**
	 * Flush when a node is saved or deleted.
	 *
	 * @param int          $post_id Post id.
	 * @param WP_Post|null $post    Post object, when the hook supplies one.
	 */
	public function on_post_change( int $post_id, ?WP_Post $post = null ): void {
		$type = $post instanceof WP_Post ? $post->post_type : (string) get_post_type( $post_id );

		if ( $this->is_node_type( $type ) || PostTypes::COURSE === $type ) {
			$this->flush();
		}
	}

	/**
	 * Flush when a node is trashed or restored.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_post_id_change( int $post_id ): void {
		$this->on_post_change( $post_id );
	}

	/**
	 * Flush when a node changes status.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_status_change( string $new_status, string $old_status, WP_Post $post ): void {
		if ( $new_status !== $old_status && $this->is_node_type( $post->post_type ) ) {
			$this->flush();
		}
	}

	/**
	 * Flush when relationship meta changes.
	 *
	 * @param int|int[] $meta_id  Meta id(s).
	 * @param int       $post_id  Post id.
	 * @param string    $meta_key Meta key.
	 */
	public function on_meta_change( int|array $meta_id, int $post_id, string $meta_key ): void {
		if ( ! in_array( $meta_key, array( Meta::COURSE_ID, Meta::LESSON_ID ), true ) ) {
			return;
		}

		// A topic or quiz always belongs to its lesson's course, whichever
		// path wrote the lesson id (LMS-AUT-002).
		if ( Meta::LESSON_ID === $meta_key && in_array( get_post_type( $post_id ), array( PostTypes::TOPIC, PostTypes::QUIZ ), true ) ) {
			$lesson_id = (int) get_post_meta( $post_id, Meta::LESSON_ID, true );
			$course_id = $lesson_id > 0 ? (int) get_post_meta( $lesson_id, Meta::COURSE_ID, true ) : 0;

			if ( $course_id > 0 && (int) get_post_meta( $post_id, Meta::COURSE_ID, true ) !== $course_id ) {
				update_post_meta( $post_id, Meta::COURSE_ID, $course_id );
			}
		}

		$this->flush();
	}

	/**
	 * Whether a post type is an outline node.
	 *
	 * @param string $type Post type.
	 */
	private function is_node_type( string $type ): bool {
		return in_array( $type, PostTypes::trackable(), true );
	}

	/**
	 * Flattened, ordered outline for a course.
	 *
	 * Lessons come first in `menu_order`, each followed by its topics and then any
	 * quiz attached to it; course level quizzes come last.
	 *
	 * @param int $course_id Course post id.
	 *
	 * Lessons carry a `section` flag: true when they have topics (ADR-007).
	 *
	 * @return array<int, array{id: int, type: string, parent: int, depth: int, section?: bool}>
	 */
	public function outline( int $course_id ): array {
		if ( isset( $this->cache[ $course_id ] ) ) {
			return $this->cache[ $course_id ];
		}

		/**
		 * Filters the outline before it is computed. Return an array to short-circuit.
		 *
		 * @param array<int, array<string, mixed>>|null $outline   Null to compute.
		 * @param int                                   $course_id Course post id.
		 */
		$pre = apply_filters( 'odsi_lms_pre_course_outline', null, $course_id );

		if ( is_array( $pre ) ) {
			$this->cache[ $course_id ] = $pre;

			return $pre;
		}

		$steps = array();

		foreach ( $this->lessons( $course_id ) as $lesson_id ) {
			$topics = $this->topics( $lesson_id );

			$steps[] = array(
				'id'      => $lesson_id,
				'type'    => PostTypes::LESSON,
				'parent'  => $course_id,
				'depth'   => 0,
				'section' => array() !== $topics,
			);

			foreach ( $topics as $topic_id ) {
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
				// LMS-AUT-005: menu_order, then date, then id, so ordering is total.
				'orderby'                => array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
					'ID'         => 'ASC',
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
	 * Whether a lesson is a section (has at least one published topic).
	 *
	 * Derived at read time; see ADR-007.
	 *
	 * @param int $lesson_id Lesson post id.
	 */
	public function is_section( int $lesson_id ): bool {
		if ( PostTypes::LESSON !== get_post_type( $lesson_id ) ) {
			return false;
		}

		return array() !== $this->topics( $lesson_id );
	}

	/**
	 * The node whose completion unlocks the given one under linear progression.
	 *
	 * Section lessons are containers and never gates (ADR-007), so this walks
	 * back past them to the nearest leaf node. Null means nothing gates it.
	 *
	 * @param int $course_id Course post id.
	 * @param int $object_id Node post id.
	 *
	 * @return array{id: int, type: string, parent: int, depth: int}|null
	 */
	public function gate( int $course_id, int $object_id ): ?array {
		$steps = $this->outline( $course_id );
		$ids   = array_column( $steps, 'id' );
		$index = array_search( $object_id, $ids, true );

		if ( false === $index ) {
			return null;
		}

		for ( $i = $index - 1; $i >= 0; $i-- ) {
			if ( empty( $steps[ $i ]['section'] ) ) {
				return $steps[ $i ];
			}
		}

		return null;
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
				// LMS-AUT-005: menu_order, then date, then id, so ordering is total.
				'orderby'                => array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
					'ID'         => 'ASC',
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

		$ids = array_map( 'intval', $query->posts );

		// Titles, links and drip meta are read for every node; one query now
		// beats one per node later.
		if ( array() !== $ids ) {
			_prime_post_caches( $ids, false, false );
			update_meta_cache( 'post', $ids );
		}

		return $ids;
	}
}
