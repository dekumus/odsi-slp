<?php
/**
 * Member blocking.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Members;

use ODSI\Social\Connections\Connections;
use ODSI\Social\Connections\Follows;
use ODSI\Social\Repositories\BlockRepository;
use ODSI\Social\Repositories\MemberRepository;
use ODSI\Social\Support\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Member-to-member blocks (SOC-MOD-001..003). A block is written here; its
 * effects are enforced where each action lives: `Privacy` hides the pair's
 * items and comments from each other, `Messages`, `Connections` and
 * `Follows` refuse the pair, `Notifications` drops anything from a blocked
 * member, `Profiles` and `Directory` hide the pair from each other.
 */
final class Blocks {

	/**
	 * Constructor.
	 *
	 * @param BlockRepository  $blocks      Storage.
	 * @param Connections      $connections Connections, severed on block.
	 * @param Follows          $follows     Follows, severed on block.
	 * @param MemberRepository $members     Member index, for display data.
	 */
	public function __construct(
		private BlockRepository $blocks,
		private Connections $connections,
		private Follows $follows,
		private MemberRepository $members
	) {
	}

	/**
	 * Block a member (SOC-MOD-001). Idempotent.
	 *
	 * @param int $actor_id  Blocker.
	 * @param int $target_id Blocked.
	 *
	 * @return true|WP_Error
	 */
	public function block( int $actor_id, int $target_id ): bool|WP_Error {
		if ( $actor_id <= 0 || $target_id <= 0 || $actor_id === $target_id || ! get_userdata( $target_id ) ) {
			return new WP_Error( 'odsi_social_invalid_target', __( 'You cannot block that member.', 'odsi-social' ), array( 'status' => 400 ) );
		}

		if ( Capabilities::is_admin( $target_id ) ) {
			return new WP_Error( 'odsi_social_cannot_block_admin', __( 'Administrators cannot be blocked.', 'odsi-social' ), array( 'status' => 403 ) );
		}

		if ( ! $this->blocks->add( $actor_id, $target_id ) ) {
			return true;
		}

		// A block severs whatever relationship the pair had, in both
		// directions: an accepted connection, a pending request either way,
		// and follows either way (SOC-MOD-002).
		$this->connections->remove( $actor_id, $target_id );
		$this->follows->unfollow( $actor_id, $target_id );
		$this->follows->unfollow( $target_id, $actor_id );

		/**
		 * Fires after a member blocks another.
		 *
		 * @param int $blocker_id Blocker.
		 * @param int $blocked_id Blocked member.
		 */
		do_action( 'odsi_social_member_blocked', $actor_id, $target_id );

		return true;
	}

	/**
	 * Lift a block. Idempotent; nothing severed by the block is restored.
	 *
	 * @param int $actor_id  Blocker.
	 * @param int $target_id Blocked.
	 */
	public function unblock( int $actor_id, int $target_id ): bool {
		if ( $actor_id <= 0 || $target_id <= 0 || ! $this->blocks->remove( $actor_id, $target_id ) ) {
			return true;
		}

		/**
		 * Fires after a member lifts a block.
		 *
		 * @param int $blocker_id Blocker.
		 * @param int $blocked_id Formerly blocked member.
		 */
		do_action( 'odsi_social_member_unblocked', $actor_id, $target_id );

		return true;
	}

	/**
	 * Whether either member has blocked the other.
	 *
	 * @param int $a Member.
	 * @param int $b Member.
	 */
	public function is_blocked( int $a, int $b ): bool {
		return $this->blocks->is_blocked( $a, $b );
	}

	/**
	 * Every member on either side of a block with this member, loaded once
	 * per request.
	 *
	 * @param int $user_id Member.
	 *
	 * @return int[]
	 */
	public function blocked_ids( int $user_id ): array {
		return $user_id > 0 ? array_keys( $this->blocks->ids_for( $user_id ) ) : array();
	}

	/**
	 * Members the actor has blocked, with display data, newest first.
	 *
	 * @param int $actor_id Blocker.
	 *
	 * @return array<int, array{id: int, name: string, avatar: string, url: string, since: string}>
	 */
	public function blocking( int $actor_id ): array {
		$rows = $this->blocks->blocking( $actor_id );

		$this->members->prime_display( array_map( static fn ( object $r ): int => (int) $r->blocked_id, $rows ) );

		$out = array();

		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row->blocked_id );

			$out[] = array(
				'id'     => (int) $row->blocked_id,
				'name'   => $user ? $user->display_name : __( 'A former member', 'odsi-social' ),
				'avatar' => $user ? (string) get_avatar_url( (int) $row->blocked_id, array( 'size' => 48 ) ) : '',
				'url'    => $user ? (string) apply_filters( 'odsi_social_member_url', '', (int) $row->blocked_id ) : '',
				'since'  => (string) $row->created_at,
			);
		}

		return $out;
	}

	/**
	 * Remove every block touching a deleted user.
	 *
	 * @param int $user_id Deleted member.
	 */
	public function purge_user( int $user_id ): void {
		$this->blocks->delete_user( $user_id );
	}
}
