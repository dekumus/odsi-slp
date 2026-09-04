<?php
/**
 * Follows.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Connections;

use ODSI\Social\Repositories\BlockRepository;
use ODSI\Social\Repositories\FollowRepository;
use ODSI\Social\Repositories\MemberRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One-way follows (SOC-CON-002, ADR-012).
 */
final class Follows {

	/**
	 * Constructor.
	 *
	 * @param FollowRepository $follows Storage.
	 * @param MemberRepository $members Member index, for counts.
	 * @param BlockRepository  $blocks  Blocks: a blocked pair cannot follow (SOC-MOD-003).
	 */
	public function __construct(
		private FollowRepository $follows,
		private MemberRepository $members,
		private BlockRepository $blocks
	) {
	}

	/**
	 * Whether the actor follows the target.
	 *
	 * @param int $actor_id  Actor.
	 * @param int $target_id Target.
	 */
	public function is_following( int $actor_id, int $target_id ): bool {
		return $this->follows->exists( $actor_id, $target_id );
	}

	/**
	 * Follow. Idempotent.
	 *
	 * @param int $actor_id  Actor.
	 * @param int $target_id Target.
	 *
	 * @return true|WP_Error
	 */
	public function follow( int $actor_id, int $target_id ): bool|WP_Error {
		if ( $actor_id <= 0 || $target_id <= 0 || $actor_id === $target_id || ! get_userdata( $target_id ) ) {
			return new WP_Error( 'odsi_social_invalid_target', __( 'You cannot follow that member.', 'odsi-social' ) );
		}

		if ( $this->blocks->is_blocked( $actor_id, $target_id ) ) {
			return new WP_Error( 'odsi_social_blocked', __( 'You cannot follow that member.', 'odsi-social' ), array( 'status' => 403 ) );
		}

		if ( ! $this->follows->add( $actor_id, $target_id ) ) {
			return true;
		}

		$this->members->adjust( $actor_id, 'following_count', 1 );
		$this->members->adjust( $target_id, 'follower_count', 1 );

		/**
		 * Fires when a member follows another.
		 *
		 * @param int $follower_id  Follower.
		 * @param int $following_id Followed.
		 */
		do_action( 'odsi_social_followed', $actor_id, $target_id );

		return true;
	}

	/**
	 * Unfollow. Idempotent.
	 *
	 * @param int $actor_id  Actor.
	 * @param int $target_id Target.
	 */
	public function unfollow( int $actor_id, int $target_id ): bool {
		if ( ! $this->follows->remove( $actor_id, $target_id ) ) {
			return true;
		}

		$this->members->adjust( $actor_id, 'following_count', -1 );
		$this->members->adjust( $target_id, 'follower_count', -1 );

		/**
		 * Fires when a member unfollows another.
		 *
		 * @param int $follower_id  Follower.
		 * @param int $following_id Followed.
		 */
		do_action( 'odsi_social_unfollowed', $actor_id, $target_id );

		return true;
	}

	/**
	 * Ids the member follows.
	 *
	 * @param int $user_id Member.
	 *
	 * @return int[]
	 */
	public function following( int $user_id ): array {
		return $this->follows->following_ids( $user_id );
	}

	/**
	 * Ids following the member.
	 *
	 * @param int $user_id Member.
	 *
	 * @return int[]
	 */
	public function followers( int $user_id ): array {
		return $this->follows->follower_ids( $user_id );
	}

	/**
	 * Remove every edge for a deleted user and fix counts.
	 *
	 * @param int $user_id Deleted member.
	 */
	public function purge_user( int $user_id ): void {
		$edges = $this->follows->delete_user( $user_id );

		foreach ( $edges['following'] as $id ) {
			$this->members->adjust( $id, 'follower_count', -1 );
		}

		foreach ( $edges['followers'] as $id ) {
			$this->members->adjust( $id, 'following_count', -1 );
		}
	}
}
