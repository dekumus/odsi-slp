<?php
/**
 * Course ↔ group linkage.
 *
 * @package ODSI\Bridge
 */

declare( strict_types = 1 );

namespace ODSI\Bridge\Modules;

use ODSI\Bridge\Contracts\Bootable;
use ODSI\Bridge\Repositories\LinkRepository;
use ODSI\Bridge\Support\Settings;
use ODSI\LMS\Courses\Enrollment;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\Social\Groups\Groups;
use ODSI\Social\Groups\Membership;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a linked group's membership in step with a course's enrollment
 * (contract § 4), and gives admins a meta box to make the link.
 */
final class GroupLinkage implements Bootable {

	private const NONCE = 'odsi_bridge_link';

	/**
	 * Constructor.
	 *
	 * @param LinkRepository $links    Links.
	 * @param Settings       $settings Settings.
	 */
	public function __construct(
		private LinkRepository $links,
		private Settings $settings
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		if ( ! $this->settings->enabled( 'group_linkage' ) ) {
			return;
		}

		add_action( 'odsi_lms_user_enrolled', array( $this, 'on_enrolled' ), 20, 2 );
		add_action( 'odsi_lms_user_unenrolled', array( $this, 'on_unenrolled' ), 20, 2 );
		// Losing access any other way leaves the group too: expiry by cron and
		// cohort cancellation never fire the unenrolled action.
		add_action( 'odsi_lms_enrollment_expired', array( $this, 'on_unenrolled' ), 20, 2 );
		add_action( 'odsi_lms_cohort_enrollment_cancelled', array( $this, 'on_unenrolled' ), 20, 2 );
		add_action( 'odsi_social_group_deleted', array( $this, 'on_group_deleted' ), 20 );
		add_action( 'trashed_post', array( $this, 'on_trashed_post' ), 20 );
		add_action( 'deleted_post', array( $this, 'on_deleted_post' ), 20, 2 );
		add_action( self::SYNC_HOOK, array( $this, 'sync_page' ), 10, 3 );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_' . PostTypes::COURSE, array( $this, 'save_meta_box' ), 20 );
		// The bridge's own enrolled item should not be doubled by a "joined the group" item.
		add_filter( 'odsi_social_announce_group_join', array( $this, 'silence_course_joins' ), 10, 4 );
	}

	/**
	 * Enrollments synced per request when a course is linked; the rest are
	 * queued through cron so a large cohort never blocks the admin's save.
	 */
	public const SYNC_PAGE = 200;
	public const SYNC_HOOK = 'odsi_bridge_sync_link';

	/**
	 * Group linked to a course, or 0.
	 *
	 * @param int $course_id Course.
	 */
	public function group_for( int $course_id ): int {
		return $this->links->group_for( $course_id );
	}

	/**
	 * Course linked to a group, or 0.
	 *
	 * @param int $group_id Group.
	 */
	public function course_for( int $group_id ): int {
		return $this->links->course_for( $group_id );
	}

	/**
	 * Link a course to a group and sync existing enrollments in.
	 *
	 * @param int $course_id Course.
	 * @param int $group_id  Group.
	 */
	public function link( int $course_id, int $group_id ): bool {
		if ( PostTypes::COURSE !== get_post_type( $course_id ) || ! $this->groups()->exists( $group_id ) ) {
			return false;
		}

		// A course or group can carry one link; anything displaced is announced.
		$displaced = array_filter(
			array(
				array( $course_id, $this->links->group_for( $course_id ) ),
				array( $this->links->course_for( $group_id ), $group_id ),
			),
			static fn ( array $pair ): bool => $pair[0] > 0 && $pair[1] > 0 && ( $pair[0] !== $course_id || $pair[1] !== $group_id )
		);

		if ( ! $this->links->link( $course_id, $group_id ) ) {
			return false;
		}

		foreach ( $displaced as $pair ) {
			do_action( 'odsi_bridge_course_unlinked', $pair[0], $pair[1] );
		}

		$this->sync_page( $course_id, $group_id, 0 );

		/**
		 * Fires after a course and a group are linked.
		 *
		 * @param int $course_id Course.
		 * @param int $group_id  Group.
		 */
		do_action( 'odsi_bridge_course_linked', $course_id, $group_id );

		return true;
	}

	/**
	 * Add one page of a course's learners to its group; queue the next page.
	 *
	 * @param int $course_id Course.
	 * @param int $group_id  Group.
	 * @param int $offset    Offset into the enrollment list.
	 */
	public function sync_page( int $course_id, int $group_id, int $offset = 0 ): void {
		if ( $this->links->group_for( $course_id ) !== $group_id ) {
			return;
			// The link changed while the queue was waiting.
		}

		$learners = $this->enrollment()->repository()->for_course( $course_id, self::SYNC_PAGE, $offset );

		foreach ( $learners as $row ) {
			if ( in_array( (string) $row->status, array( 'active', 'completed' ), true ) ) {
				$this->membership()->add( $group_id, (int) $row->user_id, 'course_enrollment' );
			}
		}

		if ( count( $learners ) === self::SYNC_PAGE ) {
			wp_schedule_single_event( time(), self::SYNC_HOOK, array( $course_id, $group_id, $offset + self::SYNC_PAGE ) );
		}
	}

	/**
	 * A learner added through enrollment already appears as "enrolled".
	 *
	 * @param bool   $announce Whether to post the join.
	 * @param int    $group_id Group.
	 * @param int    $user_id  Member.
	 * @param string $via      How they joined.
	 */
	public function silence_course_joins( bool $announce, int $group_id, int $user_id, string $via ): bool {
		return 'course_enrollment' === $via ? false : $announce;
	}

	/**
	 * A trashed course or group leaves its link behind; drop it so the group
	 * stops receiving members and the picker shows the truth.
	 *
	 * @param int $post_id Post.
	 */
	public function on_trashed_post( int $post_id ): void {
		$type = (string) get_post_type( $post_id );

		if ( PostTypes::COURSE === $type ) {
			$this->unlink( $post_id );
		} elseif ( \ODSI\Social\PostTypes\GroupPostType::NAME === $type ) {
			$course_id = $this->links->course_for( $post_id );

			if ( $course_id > 0 ) {
				$this->unlink( $course_id );
			}
		}
	}

	/**
	 * Remove a course's link. Memberships are left as they are.
	 *
	 * @param int $course_id Course.
	 */
	public function unlink( int $course_id ): void {
		$group_id = $this->links->group_for( $course_id );

		if ( $group_id > 0 && $this->links->unlink_course( $course_id ) ) {
			/**
			 * Fires after a course and a group are unlinked.
			 *
			 * @param int $course_id Course.
			 * @param int $group_id  Group.
			 */
			do_action( 'odsi_bridge_course_unlinked', $course_id, $group_id );
		}
	}

	/**
	 * Enrolled → add to the linked group.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 */
	public function on_enrolled( int $user_id, int $course_id ): void {
		$group_id = $this->links->group_for( $course_id );

		if ( $group_id > 0 ) {
			$this->membership()->add( $group_id, $user_id, 'course_enrollment' );
		}
	}

	/**
	 * Unenrolled → remove from the linked group, unless they hold a role.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 */
	public function on_unenrolled( int $user_id, int $course_id ): void {
		$group_id = $this->links->group_for( $course_id );

		if ( $group_id > 0 ) {
			$this->membership()->remove_member( $group_id, $user_id );
		}
	}

	/**
	 * Group deleted → link removed.
	 *
	 * @param int $group_id Group.
	 */
	public function on_group_deleted( int $group_id ): void {
		$this->links->unlink_group( $group_id );
	}

	/**
	 * Course deleted → link removed.
	 *
	 * @param int          $post_id Post.
	 * @param WP_Post|null $post    Post.
	 */
	public function on_deleted_post( int $post_id, ?WP_Post $post = null ): void {
		if ( $post && PostTypes::COURSE === $post->post_type ) {
			$this->links->unlink_course( $post_id );
		}
	}

	/**
	 * Meta box on the course edit screen.
	 */
	public function register_meta_box(): void {
		// Linking is an admin decision (the save path requires manage_odsi_lms),
		// and the picker lists hidden groups, so only admins get to see it.
		if ( ! current_user_can( \ODSI\LMS\Support\Capabilities::MANAGE ) ) {
			return;
		}

		add_meta_box( 'odsi-bridge-group', __( 'Community group', 'odsi-bridge' ), array( $this, 'render_meta_box' ), PostTypes::COURSE, 'side' );
	}

	/**
	 * Render the group picker.
	 *
	 * @param WP_Post $post Course.
	 */
	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );

		$current = $this->links->group_for( $post->ID );
		$groups  = get_posts(
			array(
				'post_type'      => \ODSI\Social\PostTypes\GroupPostType::NAME,
				'post_status'    => 'publish',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- admin picker.
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		echo '<p>' . esc_html__( 'Members of the linked group see each other\'s progress; enrolling adds learners to it.', 'odsi-bridge' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Anyone who can enroll on this course joins the group, so a private or hidden group linked to an open or free course is effectively open.', 'odsi-bridge' ) . '</p>';
		echo '<select name="odsi_bridge_group" class="widefat"><option value="0">' . esc_html__( '— No group —', 'odsi-bridge' ) . '</option>';

		foreach ( $groups as $group ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $group->ID, selected( $current, (int) $group->ID, false ), esc_html( $group->post_title ) );
		}

		echo '</select>';
	}

	/**
	 * Persist the picker.
	 *
	 * @param int $post_id Course.
	 */
	public function save_meta_box( int $post_id ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! isset( $_POST[ self::NONCE ], $_POST['odsi_bridge_group'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE ] ) ), self::NONCE ) || ! current_user_can( 'manage_odsi_lms' ) ) {
			return;
		}

		$group_id = absint( wp_unslash( $_POST['odsi_bridge_group'] ) );

		if ( 0 === $group_id ) {
			$this->unlink( $post_id );
		} elseif ( $group_id !== $this->links->group_for( $post_id ) ) {
			$this->link( $post_id, $group_id );
		}
	}

	/**
	 * Social groups service.
	 */
	private function groups(): Groups {
		return \ODSI\Social\Plugin::instance()->container()->get( Groups::class );
	}

	/**
	 * Social membership service.
	 */
	private function membership(): Membership {
		return \ODSI\Social\Plugin::instance()->container()->get( Membership::class );
	}

	/**
	 * LMS enrollment service.
	 */
	private function enrollment(): Enrollment {
		return \ODSI\LMS\Plugin::instance()->container()->get( Enrollment::class );
	}
}
