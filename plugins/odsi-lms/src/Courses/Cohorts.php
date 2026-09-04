<?php
/**
 * Cohorts.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Courses;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Repositories\EnrollmentRepository;

defined( 'ABSPATH' ) || exit;

/**
 * A cohort enrolls its members on its courses (LMS-ENR-012, ADR-010).
 *
 * Membership and course lists are post meta on the cohort post; the
 * enrollment rows carry `source = cohort` and the cohort id so removal is
 * precise.
 */
final class Cohorts implements Bootable {

	public const META_COURSES = '_odsi_cohort_courses';
	public const META_MEMBERS = '_odsi_cohort_members';
	public const SOURCE       = 'cohort';

	/**
	 * Constructor.
	 *
	 * @param Enrollment $enrollment Enrollment service.
	 */
	public function __construct( private Enrollment $enrollment ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'before_delete_post', array( $this, 'on_delete' ), 10, 2 );
		add_action( 'deleted_user', array( $this, 'on_deleted_user' ) );
	}

	/**
	 * Course ids of a cohort.
	 *
	 * @param int $cohort_id Cohort post.
	 *
	 * @return int[]
	 */
	public function courses( int $cohort_id ): array {
		return array_values( array_filter( array_map( 'intval', (array) get_post_meta( $cohort_id, self::META_COURSES, true ) ) ) );
	}

	/**
	 * Member ids of a cohort.
	 *
	 * @param int $cohort_id Cohort post.
	 *
	 * @return int[]
	 */
	public function members( int $cohort_id ): array {
		return array_values( array_filter( array_map( 'intval', (array) get_post_meta( $cohort_id, self::META_MEMBERS, true ) ) ) );
	}

	/**
	 * Replace the course list, enrolling members on new courses and cancelling
	 * cohort enrollments on removed ones.
	 *
	 * @param int   $cohort_id  Cohort post.
	 * @param int[] $course_ids Courses.
	 */
	public function set_courses( int $cohort_id, array $course_ids ): void {
		$course_ids = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ), static fn ( int $id ): bool => PostTypes::COURSE === get_post_type( $id ) ) ) );
		$previous   = $this->courses( $cohort_id );

		update_post_meta( $cohort_id, self::META_COURSES, $course_ids );

		foreach ( $this->members( $cohort_id ) as $user_id ) {
			foreach ( array_diff( $course_ids, $previous ) as $course_id ) {
				$this->enroll( $cohort_id, $user_id, $course_id );
			}

			foreach ( array_diff( $previous, $course_ids ) as $course_id ) {
				$this->cancel( $cohort_id, $user_id, $course_id );
			}
		}
	}

	/**
	 * Add a member: enroll on every cohort course.
	 *
	 * @param int $cohort_id Cohort post.
	 * @param int $user_id   User.
	 */
	public function add_member( int $cohort_id, int $user_id ): bool {
		if ( PostTypes::COHORT !== get_post_type( $cohort_id ) || ! get_userdata( $user_id ) ) {
			return false;
		}

		$members = $this->members( $cohort_id );

		if ( ! in_array( $user_id, $members, true ) ) {
			$members[] = $user_id;
			update_post_meta( $cohort_id, self::META_MEMBERS, $members );
		}

		foreach ( $this->courses( $cohort_id ) as $course_id ) {
			$this->enroll( $cohort_id, $user_id, $course_id );
		}

		/**
		 * Fires after a member is added to a cohort.
		 *
		 * @param int $cohort_id Cohort.
		 * @param int $user_id   User.
		 */
		do_action( 'odsi_lms_cohort_member_added', $cohort_id, $user_id );

		return true;
	}

	/**
	 * Remove a member: cancel only this cohort's enrollments; keep progress.
	 *
	 * @param int $cohort_id Cohort post.
	 * @param int $user_id   User.
	 */
	public function remove_member( int $cohort_id, int $user_id ): bool {
		$members = $this->members( $cohort_id );

		if ( ! in_array( $user_id, $members, true ) ) {
			return false;
		}

		update_post_meta( $cohort_id, self::META_MEMBERS, array_values( array_diff( $members, array( $user_id ) ) ) );

		foreach ( $this->courses( $cohort_id ) as $course_id ) {
			$this->cancel( $cohort_id, $user_id, $course_id );
		}

		/**
		 * Fires after a member is removed from a cohort.
		 *
		 * @param int $cohort_id Cohort.
		 * @param int $user_id   User.
		 */
		do_action( 'odsi_lms_cohort_member_removed', $cohort_id, $user_id );

		return true;
	}

	/**
	 * Deleting a cohort cancels its enrollments.
	 *
	 * @param int           $post_id Post.
	 * @param \WP_Post|null $post    Post.
	 */
	public function on_delete( int $post_id, ?\WP_Post $post = null ): void {
		$post = $post ?? get_post( $post_id );

		if ( ! $post || PostTypes::COHORT !== $post->post_type ) {
			return;
		}

		foreach ( $this->members( $post_id ) as $user_id ) {
			foreach ( $this->courses( $post_id ) as $course_id ) {
				$this->cancel( $post_id, $user_id, $course_id );
			}
		}
	}

	/**
	 * A deleted user leaves every cohort list.
	 *
	 * @param int $user_id User.
	 */
	public function on_deleted_user( int $user_id ): void {
		$cohorts = get_posts(
			array(
				'post_type'      => PostTypes::COHORT,
				'post_status'    => 'any',
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- cleanup sweep.
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_MEMBERS,
						'value'   => 'i:' . $user_id . ';',
						'compare' => 'LIKE',
					),
				),
			)
		);

		foreach ( $cohorts as $cohort_id ) {
			update_post_meta( (int) $cohort_id, self::META_MEMBERS, array_values( array_diff( $this->members( (int) $cohort_id ), array( $user_id ) ) ) );
		}
	}

	/**
	 * Enroll via the cohort, leaving any other-source enrollment alone.
	 *
	 * @param int $cohort_id Cohort.
	 * @param int $user_id   User.
	 * @param int $course_id Course.
	 */
	private function enroll( int $cohort_id, int $user_id, int $course_id ): void {
		$this->enrollment->enroll(
			$user_id,
			$course_id,
			array(
				'source'    => self::SOURCE,
				'source_id' => $cohort_id,
			)
		);
	}

	/**
	 * Cancel an enrollment only if this cohort created it.
	 *
	 * @param int $cohort_id Cohort.
	 * @param int $user_id   User.
	 * @param int $course_id Course.
	 */
	private function cancel( int $cohort_id, int $user_id, int $course_id ): void {
		$row = $this->enrollment->repository()->find_for( $user_id, $course_id );

		if ( $row && self::SOURCE === (string) $row->source && (int) $row->source_id === $cohort_id && EnrollmentRepository::STATUS_CANCELLED !== (string) $row->status ) {
			$this->enrollment->repository()->set_status( $user_id, $course_id, EnrollmentRepository::STATUS_CANCELLED );

			/**
			 * Fires when a cohort cancels one of its enrollments.
			 *
			 * @param int $user_id   User.
			 * @param int $course_id Course.
			 * @param int $cohort_id Cohort.
			 */
			do_action( 'odsi_lms_cohort_enrollment_cancelled', $user_id, $course_id, $cohort_id );
		}
	}
}
