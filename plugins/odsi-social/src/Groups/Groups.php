<?php
/**
 * Groups.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Groups;

use ODSI\Social\Activity\Activity;
use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Members\Uploads;
use ODSI\Social\PostTypes\GroupPostType;
use ODSI\Social\Repositories\ActivityRepository;
use ODSI\Social\Repositories\GroupMemberRepository;
use ODSI\Social\Repositories\GroupRepository;
use ODSI\Social\Support\Capabilities;
use ODSI\Social\Support\Meta;
use ODSI\Social\Support\Settings;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Group lifecycle: create atomically with an organiser, update, delete with
 * cascade, and keep the index row mirrored from the post (ADR-015).
 */
final class Groups implements Bootable {

	public const VISIBILITIES = array( 'public', 'private', 'hidden' );

	/**
	 * Constructor.
	 *
	 * @param GroupRepository       $groups      Index rows.
	 * @param GroupMemberRepository $memberships Memberships.
	 * @param ActivityRepository    $activity    Activity, for visibility cascades.
	 * @param Activity              $activity_service Activity writer, for cascade delete.
	 * @param Settings              $settings    Settings.
	 */
	public function __construct(
		private GroupRepository $groups,
		private GroupMemberRepository $memberships,
		private ActivityRepository $activity,
		private Activity $activity_service,
		private Settings $settings
	) {
	}

	/**
	 * Register hooks: mirror on save, cascade on delete.
	 */
	public function boot(): void {
		add_action( 'save_post_' . GroupPostType::NAME, array( $this, 'on_save' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete' ), 10, 2 );
	}

	/**
	 * Whether the member may create groups (SOC-GRP-002).
	 *
	 * @param int $user_id Member.
	 */
	public function can_create( int $user_id ): bool {
		$allowed = $user_id > 0 && ( Capabilities::is_admin( $user_id ) || $this->settings->bool( 'members_can_create_groups' ) );

		/**
		 * Filters whether a member may create a group.
		 *
		 * @param bool $allowed Decision.
		 * @param int  $user_id Member.
		 */
		return (bool) apply_filters( 'odsi_social_can_create_group', $allowed, $user_id );
	}

	/**
	 * Create a group with its first organiser (SOC-GRP-001).
	 *
	 * @param int                  $creator_id Creator.
	 * @param array<string, mixed> $args       `name`, `description`, `visibility`, `avatar_id`, `cover_id`.
	 *
	 * @return int|WP_Error Group post id.
	 */
	public function create( int $creator_id, array $args ): int|WP_Error {
		if ( ! $this->can_create( $creator_id ) ) {
			return new WP_Error( 'odsi_social_forbidden', __( 'You cannot create groups.', 'odsi-social' ), array( 'status' => 403 ) );
		}

		$name = sanitize_text_field( (string) ( $args['name'] ?? '' ) );

		if ( '' === $name ) {
			return new WP_Error( 'odsi_social_group_name_required', __( 'A group needs a name.', 'odsi-social' ) );
		}

		$visibility = (string) ( $args['visibility'] ?? 'public' );

		if ( ! in_array( $visibility, self::VISIBILITIES, true ) ) {
			return new WP_Error( 'odsi_social_invalid_visibility', __( 'That visibility is not valid.', 'odsi-social' ) );
		}

		// The organiser row is written inside the same save cycle as the post
		// so the group is never observable without one.
		$post_id = wp_insert_post(
			array(
				'post_type'    => GroupPostType::NAME,
				'post_status'  => 'publish',
				'post_title'   => $name,
				'post_content' => wp_kses_post( (string) ( $args['description'] ?? '' ) ),
				'post_author'  => $creator_id,
				'meta_input'   => array(
					Meta::GROUP_VISIBILITY => $visibility,
					Meta::GROUP_CREATOR_ID => $creator_id,
					Meta::GROUP_COVER_ID   => Uploads::owned_by( (int) ( $args['cover_id'] ?? 0 ), $creator_id ) ? (int) $args['cover_id'] : 0,
				),
			),
			true
		);

		if ( $post_id instanceof WP_Error ) {
			return $post_id;
		}

		if ( ! empty( $args['avatar_id'] ) && Uploads::owned_by( (int) $args['avatar_id'], $creator_id ) ) {
			set_post_thumbnail( $post_id, (int) $args['avatar_id'] );
		}

		// `on_save` already ran inside wp_insert_post and seated the author as
		// organiser; this repeats it for a kernel booted without the hook.
		$this->mirror( get_post( $post_id ) );
		$this->ensure_organiser( $post_id, $creator_id );

		/**
		 * Fires once a group and its first organiser exist.
		 *
		 * @param int $group_id   Group post id.
		 * @param int $creator_id Creator.
		 */
		do_action( 'odsi_social_group_created', $post_id, $creator_id );

		return $post_id;
	}

	/**
	 * Update settings (organiser or admin).
	 *
	 * @param int                  $actor_id Actor.
	 * @param int                  $group_id Group post id.
	 * @param array<string, mixed> $args     Any of `name`, `description`, `visibility`, `avatar_id`, `cover_id`.
	 *
	 * @return true|WP_Error
	 */
	public function update( int $actor_id, int $group_id, array $args ): bool|WP_Error {
		if ( ! $this->exists( $group_id ) || ! $this->can_view( $actor_id, $group_id ) ) {
			return $this->not_found();
		}

		if ( ! $this->is_organiser( $actor_id, $group_id ) ) {
			return new WP_Error( 'odsi_social_forbidden', __( 'Only organisers can change group settings.', 'odsi-social' ), array( 'status' => 403 ) );
		}

		$changes = array();
		$post    = array( 'ID' => $group_id );

		if ( isset( $args['name'] ) && '' !== sanitize_text_field( (string) $args['name'] ) ) {
			$post['post_title'] = sanitize_text_field( (string) $args['name'] );
			$changes['name']    = $post['post_title'];
		}

		if ( isset( $args['description'] ) ) {
			$post['post_content']   = wp_kses_post( (string) $args['description'] );
			$changes['description'] = true;
		}

		if ( isset( $args['visibility'] ) ) {
			if ( ! in_array( $args['visibility'], self::VISIBILITIES, true ) ) {
				return new WP_Error( 'odsi_social_invalid_visibility', __( 'That visibility is not valid.', 'odsi-social' ) );
			}

			$previous = $this->visibility( $group_id );

			if ( $previous !== $args['visibility'] ) {
				update_post_meta( $group_id, Meta::GROUP_VISIBILITY, (string) $args['visibility'] );
				$changes['visibility'] = array(
					'from' => $previous,
					'to'   => (string) $args['visibility'],
				);
			}
		}

		// An attachment id is accepted only when the actor may use that file;
		// otherwise any member could publish the URL of any upload on the site.
		if ( isset( $args['cover_id'] ) && ( 0 === (int) $args['cover_id'] || Uploads::owned_by( (int) $args['cover_id'], $actor_id ) ) ) {
			$previous_cover = (int) get_post_meta( $group_id, Meta::GROUP_COVER_ID, true );
			update_post_meta( $group_id, Meta::GROUP_COVER_ID, (int) $args['cover_id'] );

			if ( $previous_cover !== (int) $args['cover_id'] ) {
				Uploads::reclaim( $previous_cover );
			}
		}

		if ( isset( $args['avatar_id'] ) && ( 0 === (int) $args['avatar_id'] || Uploads::owned_by( (int) $args['avatar_id'], $actor_id ) ) ) {
			$previous_avatar = (int) get_post_thumbnail_id( $group_id );
			(int) $args['avatar_id'] > 0 ? set_post_thumbnail( $group_id, (int) $args['avatar_id'] ) : delete_post_thumbnail( $group_id );

			if ( $previous_avatar !== (int) $args['avatar_id'] ) {
				Uploads::reclaim( $previous_avatar );
			}
		}

		if ( count( $post ) > 1 ) {
			wp_update_post( $post );
		} else {
			$this->mirror( get_post( $group_id ) );
		}

		/**
		 * Fires after group settings change.
		 *
		 * @param int                  $group_id Group.
		 * @param array<string, mixed> $changes  What changed.
		 */
		do_action( 'odsi_social_group_updated', $group_id, $changes );

		return true;
	}

	/**
	 * Delete a group with everything in it (SOC-GRP-007).
	 *
	 * @param int $actor_id Actor.
	 * @param int $group_id Group.
	 *
	 * @return true|WP_Error
	 */
	public function delete( int $actor_id, int $group_id ): bool|WP_Error {
		if ( ! $this->exists( $group_id ) || ! $this->can_view( $actor_id, $group_id ) ) {
			return $this->not_found();
		}

		if ( ! $this->is_organiser( $actor_id, $group_id ) ) {
			return new WP_Error( 'odsi_social_forbidden', __( 'Only organisers can delete a group.', 'odsi-social' ), array( 'status' => 403 ) );
		}

		wp_delete_post( $group_id, true );

		return true;
	}

	/**
	 * Whether a published group exists.
	 *
	 * @param int $group_id Group.
	 */
	public function exists( int $group_id ): bool {
		return GroupPostType::NAME === get_post_type( $group_id ) && 'publish' === get_post_status( $group_id );
	}

	/**
	 * Group visibility.
	 *
	 * @param int $group_id Group.
	 */
	public function visibility( int $group_id ): string {
		$row = $this->groups->find( $group_id );

		return $row ? (string) $row->visibility : (string) ( get_post_meta( $group_id, Meta::GROUP_VISIBILITY, true ) ?: 'public' );
	}

	/**
	 * Whether the viewer may know the group exists (SOC-GRP-005). An
	 * invitation is itself permission to see a hidden group: the invitee
	 * must be able to reach the page to accept or decline.
	 *
	 * @param int $viewer_id Viewer.
	 * @param int $group_id  Group.
	 */
	public function can_view( int $viewer_id, int $group_id ): bool {
		if ( ! $this->exists( $group_id ) ) {
			return false;
		}

		if ( 'hidden' !== $this->visibility( $group_id ) ) {
			return true;
		}

		if ( $viewer_id <= 0 ) {
			return false;
		}

		if ( Capabilities::is_admin( $viewer_id ) ) {
			return true;
		}

		$row = $this->memberships->find_for( $group_id, $viewer_id );

		return $row && in_array( (string) $row->status, array( GroupMemberRepository::STATUS_ACTIVE, GroupMemberRepository::STATUS_INVITED ), true );
	}

	/**
	 * Whether the viewer may see the group's feed and member list.
	 *
	 * @param int $viewer_id Viewer.
	 * @param int $group_id  Group.
	 */
	public function can_view_content( int $viewer_id, int $group_id ): bool {
		if ( ! $this->can_view( $viewer_id, $group_id ) ) {
			return false;
		}

		if ( 'public' === $this->visibility( $group_id ) ) {
			return true;
		}

		return $viewer_id > 0 && ( Capabilities::is_admin( $viewer_id ) || $this->memberships->is_active( $group_id, $viewer_id ) );
	}

	/**
	 * Whether the actor is an organiser (or admin).
	 *
	 * @param int $actor_id Actor.
	 * @param int $group_id Group.
	 */
	public function is_organiser( int $actor_id, int $group_id ): bool {
		return Capabilities::is_admin( $actor_id ) || GroupMemberRepository::ROLE_ORGANISER === $this->memberships->role_of( $group_id, $actor_id );
	}

	/**
	 * Whether the actor moderates (organiser, moderator or admin).
	 *
	 * @param int $actor_id Actor.
	 * @param int $group_id Group.
	 */
	public function is_moderator( int $actor_id, int $group_id ): bool {
		return Capabilities::is_admin( $actor_id ) || in_array( $this->memberships->role_of( $group_id, $actor_id ), array( GroupMemberRepository::ROLE_ORGANISER, GroupMemberRepository::ROLE_MODERATOR ), true );
	}

	/**
	 * Warm every cache `present()` reads for a list of groups — posts and
	 * their meta, the index rows, the viewer's memberships, and the avatar
	 * and cover attachments — in a fixed number of queries.
	 *
	 * @param int   $viewer_id Viewer.
	 * @param int[] $group_ids Group post ids.
	 */
	public function prime( int $viewer_id, array $group_ids ): void {
		$group_ids = array_values( array_unique( array_filter( array_map( 'intval', $group_ids ) ) ) );

		if ( array() === $group_ids ) {
			return;
		}

		_prime_post_caches( $group_ids, false, true );
		$this->groups->prime( $group_ids );
		$this->memberships->prime_for_user( $viewer_id, $group_ids );

		$attachments = array();

		foreach ( $group_ids as $group_id ) {
			$attachments[] = (int) get_post_thumbnail_id( $group_id );
			$attachments[] = (int) get_post_meta( $group_id, Meta::GROUP_COVER_ID, true );
		}

		$attachments = array_values( array_unique( array_filter( $attachments ) ) );

		if ( array() !== $attachments ) {
			_prime_post_caches( $attachments, false, true );
		}
	}

	/**
	 * Presentation shape.
	 *
	 * @param int $viewer_id Viewer.
	 * @param int $group_id  Group.
	 *
	 * @return array<string, mixed>|null
	 */
	public function present( int $viewer_id, int $group_id ): ?array {
		if ( ! $this->can_view( $viewer_id, $group_id ) ) {
			return null;
		}

		$post       = get_post( $group_id );
		$row        = $this->groups->find( $group_id );
		$membership = $viewer_id > 0 ? $this->memberships->find_for( $group_id, $viewer_id ) : null;

		return array(
			'id'           => $group_id,
			'name'         => $post ? html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ) : '',
			'slug'         => $post ? $post->post_name : '',
			// Member-written text: formatted, never run through `the_content`,
			// which would execute shortcodes and dynamic blocks for every viewer.
			'description'  => $post ? wpautop( wp_kses_post( $post->post_content ) ) : '',
			'visibility'   => $this->visibility( $group_id ),
			'member_count' => $row ? (int) $row->member_count : 0,
			'avatar'       => get_the_post_thumbnail_url( $group_id, 'thumbnail' ) ?: '',
			'cover'        => wp_get_attachment_image_url( (int) get_post_meta( $group_id, Meta::GROUP_COVER_ID, true ), 'large' ) ?: '',
			'url'          => (string) apply_filters( 'odsi_social_group_url', '', $group_id ),
			'viewer'       => array(
				'role'   => $membership && GroupMemberRepository::STATUS_ACTIVE === (string) $membership->status ? (string) $membership->role : '',
				'status' => $membership ? (string) $membership->status : '',
			),
			'created'      => $post ? $post->post_date_gmt : '',
		);
	}

	/**
	 * Keep the index row in step with the post.
	 *
	 * @param int          $post_id Post id.
	 * @param WP_Post|null $post    Post.
	 */
	public function on_save( int $post_id, ?WP_Post $post = null ): void {
		$post = $post ?? get_post( $post_id );

		if ( ! $post || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// A trashed or unpublished group is gone from the community until it
		// is back: without an index row Privacy denies every viewer.
		if ( 'publish' !== $post->post_status ) {
			$this->groups->delete( $post_id );

			return;
		}

		$previous = $this->groups->find( $post_id );
		$this->mirror( $post );

		// A group saved from wp-admin never went through `create()`: seat the
		// post author as organiser so the group is never observable without
		// one (SOC-GRP-001), and keep the member count true to the table.
		$this->ensure_organiser( $post_id, (int) $post->post_author );

		$now_visibility = $this->visibility( $post_id );

		// Visibility changes cascade to existing items so a group made private
		// hides its history immediately (SOC-GRP-003, edge-case table).
		if ( $previous && (string) $previous->visibility !== $now_visibility ) {
			$this->activity->update_group_items( $post_id, array( 'privacy' => \ODSI\Social\Activity\Privacy::GROUP ) );
		}
	}

	/**
	 * Cascade when the post is deleted.
	 *
	 * @param int          $post_id Post id.
	 * @param WP_Post|null $post    Post.
	 */
	public function on_before_delete( int $post_id, ?WP_Post $post = null ): void {
		$post = $post ?? get_post( $post_id );

		if ( ! $post || GroupPostType::NAME !== $post->post_type ) {
			return;
		}

		/**
		 * Fires before a group and its memberships and activity are removed.
		 *
		 * @param int $group_id Group.
		 */
		do_action( 'odsi_social_group_deleted', $post_id );

		$this->activity_service->destroy_group( $post_id );
		$this->memberships->delete_group( $post_id );
		$this->groups->delete( $post_id );
	}

	/**
	 * Seat a member as organiser when the group has none, then recount members.
	 *
	 * @param int $group_id Group post id.
	 * @param int $user_id  Member to seat, typically the post author.
	 */
	private function ensure_organiser( int $group_id, int $user_id ): void {
		if ( $user_id > 0 && get_userdata( $user_id ) && 0 === $this->memberships->count( $group_id, GroupMemberRepository::STATUS_ACTIVE, GroupMemberRepository::ROLE_ORGANISER ) ) {
			$this->memberships->put( $group_id, $user_id, GroupMemberRepository::ROLE_ORGANISER, GroupMemberRepository::STATUS_ACTIVE );
		}

		$this->groups->recount_members( $group_id );
	}

	/**
	 * Write the mirror row from the post.
	 *
	 * @param WP_Post|null $post Post.
	 */
	public function mirror( ?WP_Post $post ): void {
		if ( ! $post ) {
			return;
		}

		$visibility = (string) get_post_meta( $post->ID, Meta::GROUP_VISIBILITY, true );

		$this->groups->mirror(
			$post->ID,
			array(
				'slug'       => $post->post_name ?: sanitize_title( $post->post_title ),
				'visibility' => in_array( $visibility, self::VISIBILITIES, true ) ? $visibility : 'public',
			)
		);
	}

	/**
	 * A 404-style error (ADR-011).
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'odsi_social_group_not_found', __( 'That group does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
	}
}
