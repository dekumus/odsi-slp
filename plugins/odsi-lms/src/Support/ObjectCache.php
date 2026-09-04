<?php
/**
 * Persistent caching of derived data.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Support;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Courses\Structure;

defined( 'ABSPATH' ) || exit;

/**
 * Caches course outlines in the object cache across requests (gap 9).
 *
 * `Structure` keeps its per-request array; this layer sits in front of it via
 * the outline filter and drops the cached copy on the same events that flush
 * the per-request cache. With no persistent object cache installed this is a
 * no-op beyond the request, which is fine.
 */
final class ObjectCache implements Bootable {

	public const GROUP = 'odsi_lms';

	/**
	 * Constructor.
	 *
	 * @param Structure $structure Outline resolver.
	 */
	public function __construct( private Structure $structure ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'odsi_lms_course_outline', array( $this, 'store_outline' ), 100, 2 );
		add_filter( 'odsi_lms_pre_course_outline', array( $this, 'load_outline' ), 10, 2 );

		foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post' ) as $hook ) {
			add_action( $hook, array( $this, 'on_post_change' ) );
		}

		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'on_meta_change' ), 10, 3 );
		}
	}

	/**
	 * Supply a cached outline before it is computed.
	 *
	 * @param array<int, array<string, mixed>>|null $outline   Null to compute.
	 * @param int                                   $course_id Course.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	public function load_outline( ?array $outline, int $course_id ): ?array {
		if ( null !== $outline ) {
			return $outline;
		}

		$cached = wp_cache_get( "outline_{$course_id}", self::GROUP );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Store a computed outline.
	 *
	 * @param array<int, array<string, mixed>> $outline   Outline.
	 * @param int                              $course_id Course.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function store_outline( array $outline, int $course_id ): array {
		wp_cache_set( "outline_{$course_id}", $outline, self::GROUP, HOUR_IN_SECONDS );

		return $outline;
	}

	/**
	 * Drop the outline of the course a changed node belongs to.
	 *
	 * @param int $post_id Post.
	 */
	public function on_post_change( int $post_id ): void {
		$course_id = $this->structure->course_id_for( $post_id );

		wp_cache_delete( "outline_{$post_id}", self::GROUP );

		if ( $course_id > 0 ) {
			wp_cache_delete( "outline_{$course_id}", self::GROUP );
		}
	}

	/**
	 * Drop on relationship changes, for both the old and new course.
	 *
	 * @param int|int[] $meta_id  Meta id(s).
	 * @param int       $post_id  Post.
	 * @param string    $meta_key Key.
	 */
	public function on_meta_change( int|array $meta_id, int $post_id, string $meta_key ): void {
		if ( ! in_array( $meta_key, array( Meta::COURSE_ID, Meta::LESSON_ID ), true ) ) {
			return;
		}

		// Cheap and safe: the flush is per course and the group is small.
		wp_cache_flush_group( self::GROUP );
	}
}
