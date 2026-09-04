<?php
/**
 * Activity writes.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Activity;

use ODSI\Social\Repositories\ActivityMetaRepository;
use ODSI\Social\Repositories\ActivityRepository;
use ODSI\Social\Repositories\GroupMemberRepository;
use ODSI\Social\Repositories\GroupRepository;
use ODSI\Social\Repositories\MemberRepository;
use ODSI\Social\Repositories\ReactionRepository;
use ODSI\Social\Support\Capabilities;
use ODSI\Social\Support\Sanitizer;
use ODSI\Social\Support\Settings;
use stdClass;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Posting, commenting, editing and deleting activity.
 */
final class Activity {

	public const COMPONENT    = 'activity';
	public const TYPE_UPDATE  = 'update';
	public const TYPE_COMMENT = 'comment';

	/**
	 * Constructor.
	 *
	 * @param ActivityRepository     $activity    Activity storage.
	 * @param ActivityMetaRepository $meta        Meta storage.
	 * @param ReactionRepository     $reactions   Reactions, for cascade.
	 * @param GroupMemberRepository  $memberships Group memberships.
	 * @param GroupRepository        $groups      Group index.
	 * @param MemberRepository       $members     Member index, for counts.
	 * @param Privacy                $privacy     Privacy rule.
	 * @param Settings               $settings    Settings.
	 */
	public function __construct(
		private ActivityRepository $activity,
		private ActivityMetaRepository $meta,
		private ReactionRepository $reactions,
		private GroupMemberRepository $memberships,
		private GroupRepository $groups,
		private MemberRepository $members,
		private Privacy $privacy,
		private Settings $settings
	) {
	}

	/**
	 * Fetch one row.
	 *
	 * @param int $id Activity id.
	 */
	public function get( int $id ): ?object {
		return $this->activity->find( $id );
	}

	/**
	 * A member posts an update (SOC-ACT-002, SOC-ACT-003).
	 *
	 * @param int    $user_id  Author.
	 * @param string $content  Raw content.
	 * @param string $privacy  Chosen privacy; ignored in a group.
	 * @param int    $group_id Group, or 0.
	 *
	 * @return stdClass|WP_Error The new row.
	 */
	public function post_update( int $user_id, string $content, string $privacy = '', int $group_id = 0 ): stdClass|WP_Error {
		if ( $group_id > 0 ) {
			if ( ! $this->memberships->is_active( $group_id, $user_id ) && ! Capabilities::is_admin( $user_id ) ) {
				return new WP_Error( 'odsi_social_not_a_member', __( 'You must be a member of this group to post in it.', 'odsi-social' ) );
			}

			$privacy = Privacy::GROUP;
		} else {
			$privacy = $this->resolve_privacy( $user_id, $privacy );

			if ( $privacy instanceof WP_Error ) {
				return $privacy;
			}
		}

		return $this->post(
			array(
				'user_id'   => $user_id,
				'component' => self::COMPONENT,
				'type'      => self::TYPE_UPDATE,
				'content'   => $content,
				'privacy'   => $privacy,
				'group_id'  => $group_id,
			)
		);
	}

	/**
	 * Post any activity. Components other than the built-ins use this directly.
	 *
	 * @param array<string, mixed> $args `user_id`, `component`, `type`, `content`, `privacy`, `group_id`,
	 *                                   `primary_item_id`, `secondary_item_id`, `external_id`, `meta`, `skip_sanitize`.
	 *
	 * @return stdClass|WP_Error
	 */
	public function post( array $args ): stdClass|WP_Error {
		$user_id     = (int) ( $args['user_id'] ?? 0 );
		$component   = sanitize_key( (string) ( $args['component'] ?? self::COMPONENT ) );
		$type        = sanitize_key( (string) ( $args['type'] ?? self::TYPE_UPDATE ) );
		$external_id = isset( $args['external_id'] ) && '' !== (string) $args['external_id'] ? (string) $args['external_id'] : null;

		if ( $user_id <= 0 ) {
			return new WP_Error( 'odsi_social_no_author', __( 'Activity needs an author.', 'odsi-social' ) );
		}

		// Idempotent external posting (SOC-ACT-012).
		if ( null !== $external_id ) {
			$existing = $this->activity->find_external( $component, $external_id );

			if ( $existing ) {
				return $existing;
			}
		}

		$content = (string) ( $args['content'] ?? '' );

		if ( empty( $args['skip_sanitize'] ) ) {
			$content = Sanitizer::content( $content, (int) apply_filters( 'odsi_social_activity_max_length', $this->settings->int( 'activity_max_length' ) ) );
		}

		if ( '' === $content && self::TYPE_UPDATE === $type ) {
			return new WP_Error( 'odsi_social_empty_content', __( 'Please write something first.', 'odsi-social' ) );
		}

		$group_id = (int) ( $args['group_id'] ?? 0 );
		$privacy  = $group_id > 0 ? Privacy::GROUP : (string) ( $args['privacy'] ?? $this->settings->string( 'default_privacy' ) );

		$id = $this->activity->insert(
			array(
				'user_id'           => $user_id,
				'component'         => $component,
				'type'              => $type,
				'content'           => $content,
				'parent_id'         => 0,
				'group_id'          => $group_id,
				'primary_item_id'   => (int) ( $args['primary_item_id'] ?? 0 ),
				'secondary_item_id' => (int) ( $args['secondary_item_id'] ?? 0 ),
				'privacy'           => $privacy,
				'status'            => ActivityRepository::STATUS_PUBLISHED,
				'external_id'       => $external_id,
			)
		);

		if ( $id <= 0 ) {
			// A race on the idempotency key lands here; return the winner.
			if ( null !== $external_id ) {
				$existing = $this->activity->find_external( $component, $external_id );

				if ( $existing ) {
					return $existing;
				}
			}

			return new WP_Error( 'odsi_social_post_failed', __( 'The activity could not be saved.', 'odsi-social' ) );
		}

		foreach ( (array) ( $args['meta'] ?? array() ) as $key => $value ) {
			$this->meta->set( $id, (string) $key, $value );
		}

		$this->members->adjust( $user_id, 'activity_count', 1 );

		if ( $group_id > 0 ) {
			$this->groups->adjust( $group_id, 'activity_count', 1 );
		}

		$item = $this->activity->find( $id );

		/**
		 * Fires after any activity row is written.
		 *
		 * @param object $item Activity row.
		 */
		do_action( 'odsi_social_activity_posted', $item );

		return $item;
	}

	/**
	 * Comment on an item (SOC-ACT-004).
	 *
	 * @param int    $user_id   Author.
	 * @param int    $parent_id Item, or a comment (re-parented to its item).
	 * @param string $content   Raw content.
	 *
	 * @return stdClass|WP_Error
	 */
	public function comment( int $user_id, int $parent_id, string $content ): stdClass|WP_Error {
		$parent = $this->activity->find( $parent_id );

		if ( $parent && (int) $parent->parent_id > 0 ) {
			$parent = $this->activity->find( (int) $parent->parent_id );
		}

		if ( ! $parent || ! $this->privacy->can_view( $user_id, $parent ) ) {
			return new WP_Error( 'odsi_social_not_found', __( 'That activity does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
		}

		$content = Sanitizer::content( $content, (int) apply_filters( 'odsi_social_activity_max_length', $this->settings->int( 'activity_max_length' ) ) );

		if ( '' === $content ) {
			return new WP_Error( 'odsi_social_empty_content', __( 'Please write something first.', 'odsi-social' ) );
		}

		$id = $this->activity->insert(
			array(
				'user_id'   => $user_id,
				'component' => (string) $parent->component,
				'type'      => self::TYPE_COMMENT,
				'content'   => $content,
				'parent_id' => (int) $parent->id,
				'group_id'  => (int) $parent->group_id,
				'privacy'   => (string) $parent->privacy,
				'status'    => ActivityRepository::STATUS_PUBLISHED,
			)
		);

		if ( $id <= 0 ) {
			return new WP_Error( 'odsi_social_post_failed', __( 'The comment could not be saved.', 'odsi-social' ) );
		}

		$this->activity->adjust( (int) $parent->id, 'comment_count', 1 );

		$item = $this->activity->find( $id );

		do_action( 'odsi_social_activity_posted', $item );

		return $item;
	}

	/**
	 * Edit content within the edit window (SOC-ACT-008).
	 *
	 * @param int    $user_id Actor.
	 * @param int    $id      Activity id.
	 * @param string $content New content.
	 *
	 * @return stdClass|WP_Error
	 */
	public function edit( int $user_id, int $id, string $content ): stdClass|WP_Error {
		$item = $this->activity->find( $id );

		if ( ! $item || ! $this->privacy->can_view( $user_id, $item ) ) {
			return new WP_Error( 'odsi_social_not_found', __( 'That activity does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
		}

		if ( (int) $item->user_id !== $user_id && ! Capabilities::is_admin( $user_id ) ) {
			return new WP_Error( 'odsi_social_forbidden', __( 'You can only edit your own posts.', 'odsi-social' ), array( 'status' => 403 ) );
		}

		$window = $this->settings->int( 'edit_window_minutes' );

		if ( (int) $item->user_id === $user_id && ! Capabilities::is_admin( $user_id ) ) {
			if ( $window <= 0 || strtotime( (string) $item->date_recorded ) + ( $window * MINUTE_IN_SECONDS ) < time() ) {
				return new WP_Error( 'odsi_social_edit_window_closed', __( 'This post can no longer be edited.', 'odsi-social' ), array( 'status' => 403 ) );
			}
		}

		$content = Sanitizer::content( $content, (int) apply_filters( 'odsi_social_activity_max_length', $this->settings->int( 'activity_max_length' ) ) );

		if ( '' === $content ) {
			return new WP_Error( 'odsi_social_empty_content', __( 'Please write something first.', 'odsi-social' ) );
		}

		$this->activity->update(
			$id,
			array(
				'content'   => $content,
				'is_edited' => 1,
			)
		);

		$updated = $this->activity->find( $id );

		/**
		 * Fires after an activity item is edited.
		 *
		 * @param object $updated  New row.
		 * @param object $previous Previous row.
		 */
		do_action( 'odsi_social_activity_updated', $updated, $item );

		return $updated;
	}

	/**
	 * Change an update's privacy.
	 *
	 * @param int    $user_id Actor (author only).
	 * @param int    $id      Activity id.
	 * @param string $privacy New privacy.
	 *
	 * @return stdClass|WP_Error
	 */
	public function set_privacy( int $user_id, int $id, string $privacy ): stdClass|WP_Error {
		$item = $this->activity->find( $id );

		if ( ! $item || (int) $item->parent_id > 0 || (int) $item->group_id > 0 || (int) $item->user_id !== $user_id ) {
			return new WP_Error( 'odsi_social_not_found', __( 'That activity does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
		}

		$privacy = $this->resolve_privacy( $user_id, $privacy );

		if ( $privacy instanceof WP_Error ) {
			return $privacy;
		}

		$this->activity->update( $id, array( 'privacy' => $privacy ) );

		return $this->activity->find( $id );
	}

	/**
	 * Whether the actor may delete the item (SOC-ACT-009).
	 *
	 * @param int    $user_id Actor.
	 * @param object $item    Activity row.
	 */
	public function can_delete( int $user_id, object $item ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( (int) $item->user_id === $user_id || Capabilities::is_admin( $user_id ) ) {
			return true;
		}

		if ( (int) $item->group_id > 0 ) {
			$role = $this->memberships->role_of( (int) $item->group_id, $user_id );

			return in_array( $role, array( GroupMemberRepository::ROLE_ORGANISER, GroupMemberRepository::ROLE_MODERATOR ), true );
		}

		return false;
	}

	/**
	 * Delete an item or comment, with cascade.
	 *
	 * @param int $user_id Actor.
	 * @param int $id      Activity id.
	 *
	 * @return true|WP_Error
	 */
	public function delete( int $user_id, int $id ): bool|WP_Error {
		$item = $this->activity->find( $id );

		if ( ! $item || ! $this->privacy->can_view( $user_id, $item ) ) {
			return new WP_Error( 'odsi_social_not_found', __( 'That activity does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
		}

		if ( ! $this->can_delete( $user_id, $item ) ) {
			return new WP_Error( 'odsi_social_forbidden', __( 'You cannot delete this.', 'odsi-social' ), array( 'status' => 403 ) );
		}

		$this->destroy( $item );

		return true;
	}

	/**
	 * Remove a row and everything hanging off it, without permission checks.
	 *
	 * @param object $item Activity row.
	 */
	public function destroy( object $item ): void {
		$id = (int) $item->id;

		/**
		 * Fires before an activity row and its dependants are removed.
		 *
		 * @param object $item Activity row.
		 */
		do_action( 'odsi_social_activity_deleted', $item );

		$comment_ids = $this->activity->comment_ids( $id );

		// Comments carry their own notifications (a like on a comment is keyed
		// on the comment's id), so each one is announced before it goes.
		foreach ( $this->activity->find_many( $comment_ids ) as $comment ) {
			do_action( 'odsi_social_activity_deleted', $comment );
		}

		$ids = array_merge( array( $id ), $comment_ids );

		$this->reactions->delete_for_items( $ids );

		foreach ( $ids as $each ) {
			$this->meta->remove( $each );
			$this->activity->delete( $each );
		}

		if ( (int) $item->parent_id > 0 ) {
			$this->activity->adjust( (int) $item->parent_id, 'comment_count', -1 );
		} else {
			$this->members->adjust( (int) $item->user_id, 'activity_count', -1 );

			if ( (int) $item->group_id > 0 ) {
				$this->groups->adjust( (int) $item->group_id, 'activity_count', -1 );
			}
		}
	}

	/**
	 * Delete everything in a group.
	 *
	 * @param int $group_id Group post id.
	 */
	public function destroy_group( int $group_id ): void {
		foreach ( $this->activity->find_many( $this->activity->ids_in_group( $group_id ) ) as $item ) {
			if ( 0 === (int) $item->parent_id ) {
				$this->destroy( $item );
			}
		}
	}

	/**
	 * The privacy levels a member may choose for a non-group update
	 * (SOC-ACT-003): the admin's allowed set, filtered.
	 *
	 * @param int $user_id Member.
	 *
	 * @return string[]
	 */
	public function privacy_choices( int $user_id ): array {
		$allowed = array_values( array_intersect( Privacy::choices(), array_map( 'strval', (array) $this->settings->get( 'allowed_privacy' ) ) ) );

		/**
		 * Filters the privacy levels a member may choose.
		 *
		 * @param string[] $allowed  Allowed levels.
		 * @param int      $user_id  Member.
		 * @param int      $group_id Group, 0 here.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'odsi_social_activity_privacy_choices', $allowed, $user_id, 0 ) ) );
	}

	/**
	 * The level preselected for a member: the admin default when allowed,
	 * else the first allowed level.
	 *
	 * @param int $user_id Member.
	 */
	public function default_privacy( int $user_id ): string {
		$allowed = $this->privacy_choices( $user_id );
		$default = $this->settings->string( 'default_privacy' );

		return in_array( $default, $allowed, true ) ? $default : (string) ( $allowed[0] ?? $default );
	}

	/**
	 * Validate a chosen privacy against the allowed set.
	 *
	 * @param int    $user_id User.
	 * @param string $privacy Requested privacy, or '' for the default.
	 *
	 * @return string|WP_Error
	 */
	private function resolve_privacy( int $user_id, string $privacy ): string|WP_Error {
		$allowed = $this->privacy_choices( $user_id );

		if ( '' === $privacy ) {
			$privacy = $this->settings->string( 'default_privacy' );
		}

		if ( ! in_array( $privacy, $allowed, true ) ) {
			return new WP_Error( 'odsi_social_invalid_privacy', __( 'That privacy setting is not available.', 'odsi-social' ) );
		}

		return $privacy;
	}
}
