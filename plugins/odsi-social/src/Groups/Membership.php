<?php
/**
 * Group membership.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Groups;

use ODSI\Social\Repositories\GroupMemberRepository as Members;
use ODSI\Social\Repositories\GroupRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The membership state machine and role changes (spec § 4), with the
 * "at least one organiser" invariant enforced in exactly one place.
 */
final class Membership {

	/**
	 * Constructor.
	 *
	 * @param Members         $members Membership rows.
	 * @param GroupRepository $groups  Index rows, for counts.
	 * @param Groups          $service Group service, for visibility and roles.
	 */
	public function __construct(
		private Members $members,
		private GroupRepository $groups,
		private Groups $service
	) {
	}

	/**
	 * Join a public group.
	 *
	 * @param int $actor_id Actor.
	 * @param int $group_id Group.
	 *
	 * @return true|WP_Error
	 */
	public function join( int $actor_id, int $group_id ): bool|WP_Error {
		$existing = $this->members->find_for( $group_id, $actor_id );
		$invited  = $existing && Members::STATUS_INVITED === $existing->status && $this->service->exists( $group_id );

		// An invitation is itself permission to see the group, hidden or not.
		if ( ! $invited && ! $this->service->can_view( $actor_id, $group_id ) ) {
			return $this->not_found();
		}

		if ( $existing && Members::STATUS_BANNED === $existing->status ) {
			return new WP_Error( 'odsi_social_banned', __( 'You cannot join this group.', 'odsi-social' ), array( 'status' => 403 ) );
		}

		if ( $existing && Members::STATUS_ACTIVE === $existing->status ) {
			return true;
		}

		// An invitation may be accepted by joining; a request to a public group
		// is simply a join.
		if ( 'public' !== $this->service->visibility( $group_id ) && ( ! $existing || Members::STATUS_INVITED !== $existing->status ) ) {
			return new WP_Error( 'odsi_social_request_required', __( 'This group requires a request to join.', 'odsi-social' ), array( 'status' => 403 ) );
		}

		$via = $existing && Members::STATUS_INVITED === $existing->status ? 'accept_invite' : 'join';

		$this->activate( $group_id, $actor_id, Members::ROLE_MEMBER, $via, $existing ? (int) $existing->inviter_id : 0 );

		return true;
	}

	/**
	 * Request to join a private group.
	 *
	 * @param int $actor_id Actor.
	 * @param int $group_id Group.
	 *
	 * @return true|WP_Error
	 */
	public function request( int $actor_id, int $group_id ): bool|WP_Error {
		if ( ! $this->service->can_view( $actor_id, $group_id ) || 'hidden' === $this->service->visibility( $group_id ) ) {
			return $this->not_found();
		}

		if ( 'public' === $this->service->visibility( $group_id ) ) {
			return $this->join( $actor_id, $group_id );
		}

		$existing = $this->members->find_for( $group_id, $actor_id );

		if ( $existing ) {
			if ( Members::STATUS_INVITED === $existing->status ) {
				return $this->join( $actor_id, $group_id );
			}

			if ( Members::STATUS_BANNED === $existing->status ) {
				return new WP_Error( 'odsi_social_banned', __( 'You cannot join this group.', 'odsi-social' ), array( 'status' => 403 ) );
			}

			return true;
		}

		$this->members->put( $group_id, $actor_id, Members::ROLE_MEMBER, Members::STATUS_PENDING );

		/**
		 * Fires when a member requests to join.
		 *
		 * @param int $group_id Group.
		 * @param int $user_id  Requester.
		 */
		do_action( 'odsi_social_group_member_requested', $group_id, $actor_id );

		return true;
	}

	/**
	 * Invite a member (organiser or moderator).
	 *
	 * @param int $actor_id Actor.
	 * @param int $group_id Group.
	 * @param int $user_id  Invitee.
	 *
	 * @return true|WP_Error
	 */
	public function invite( int $actor_id, int $group_id, int $user_id ): bool|WP_Error {
		if ( ! $this->service->can_view( $actor_id, $group_id ) ) {
			return $this->not_found();
		}

		if ( ! $this->service->is_moderator( $actor_id, $group_id ) ) {
			return $this->forbidden();
		}

		if ( $user_id <= 0 || $user_id === $actor_id || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'odsi_social_invalid_target', __( 'That member cannot be invited.', 'odsi-social' ) );
		}

		$existing = $this->members->find_for( $group_id, $user_id );

		if ( $existing ) {
			if ( Members::STATUS_PENDING === $existing->status ) {
				return $this->approve( $actor_id, $group_id, $user_id );
			}

			return Members::STATUS_BANNED === $existing->status ? $this->forbidden() : true;
		}

		$this->members->put( $group_id, $user_id, Members::ROLE_MEMBER, Members::STATUS_INVITED, $actor_id );

		/**
		 * Fires when a member is invited.
		 *
		 * @param int $group_id   Group.
		 * @param int $user_id    Invitee.
		 * @param int $inviter_id Inviter.
		 */
		do_action( 'odsi_social_group_member_invited', $group_id, $user_id, $actor_id );

		return true;
	}

	/**
	 * Approve a pending request.
	 *
	 * @param int $actor_id Actor.
	 * @param int $group_id Group.
	 * @param int $user_id  Requester.
	 *
	 * @return true|WP_Error
	 */
	public function approve( int $actor_id, int $group_id, int $user_id ): bool|WP_Error {
		if ( ! $this->service->can_view( $actor_id, $group_id ) ) {
			return $this->not_found();
		}

		if ( ! $this->service->is_moderator( $actor_id, $group_id ) ) {
			return $this->forbidden();
		}

		$existing = $this->members->find_for( $group_id, $user_id );

		if ( ! $existing || Members::STATUS_PENDING !== $existing->status ) {
			return new WP_Error( 'odsi_social_no_request', __( 'There is no request to approve.', 'odsi-social' ) );
		}

		$this->activate( $group_id, $user_id, Members::ROLE_MEMBER, 'approve', $actor_id );

		return true;
	}

	/**
	 * Reject a request, revoke an invitation, or remove/withdraw per the actor.
	 *
	 * Covers reject (moderator on pending), revoke (moderator on invited),
	 * withdraw (self on pending), decline (self on invited), leave (self on
	 * active) and remove (moderator on active).
	 *
	 * @param int $actor_id Actor.
	 * @param int $group_id Group.
	 * @param int $user_id  Subject.
	 *
	 * @return true|WP_Error
	 */
	public function remove( int $actor_id, int $group_id, int $user_id ): bool|WP_Error {
		if ( ! $this->service->can_view( $actor_id, $group_id ) ) {
			return $this->not_found();
		}

		$row = $this->members->find_for( $group_id, $user_id );

		if ( ! $row || Members::STATUS_BANNED === $row->status ) {
			return new WP_Error( 'odsi_social_no_membership', __( 'There is no membership to remove.', 'odsi-social' ) );
		}

		$self = $actor_id === $user_id;

		if ( ! $self && ! $this->service->is_moderator( $actor_id, $group_id ) ) {
			return $this->forbidden();
		}

		if ( ! $self && ! $this->may_act_on( $actor_id, $group_id, $row ) ) {
			return $this->forbidden();
		}

		if ( Members::STATUS_ACTIVE === $row->status && Members::ROLE_ORGANISER === $row->role && $this->members->count( $group_id, Members::STATUS_ACTIVE, Members::ROLE_ORGANISER ) <= 1 ) {
			return new WP_Error( 'odsi_social_last_organiser', __( 'Promote another organiser before leaving.', 'odsi-social' ) );
		}

		$was_active = Members::STATUS_ACTIVE === $row->status;

		$this->members->remove( $group_id, $user_id );

		if ( $was_active ) {
			$this->groups->adjust( $group_id, 'member_count', -1 );

			/**
			 * Fires when an active member leaves or is removed.
			 *
			 * @param int    $group_id Group.
			 * @param int    $user_id  Member.
			 * @param string $via      `leave` or `remove`.
			 */
			do_action( 'odsi_social_group_member_left', $group_id, $user_id, $self ? 'leave' : 'remove' );
		}

		return true;
	}

	/**
	 * Ban an active member.
	 *
	 * @param int $actor_id Actor.
	 * @param int $group_id Group.
	 * @param int $user_id  Member.
	 *
	 * @return true|WP_Error
	 */
	public function ban( int $actor_id, int $group_id, int $user_id ): bool|WP_Error {
		if ( ! $this->service->can_view( $actor_id, $group_id ) ) {
			return $this->not_found();
		}

		$row = $this->members->find_for( $group_id, $user_id );

		if ( $actor_id === $user_id || ! $this->service->is_moderator( $actor_id, $group_id ) || ( $row && ! $this->may_act_on( $actor_id, $group_id, $row ) ) ) {
			return $this->forbidden();
		}

		if ( $row && Members::ROLE_ORGANISER === $row->role && Members::STATUS_ACTIVE === $row->status ) {
			return $this->forbidden();
		}

		$was_active = $row && Members::STATUS_ACTIVE === $row->status;

		$this->members->put( $group_id, $user_id, Members::ROLE_MEMBER, Members::STATUS_BANNED );

		if ( $was_active ) {
			$this->groups->adjust( $group_id, 'member_count', -1 );
			do_action( 'odsi_social_group_member_left', $group_id, $user_id, 'remove' );
		}

		/**
		 * Fires when a member is banned.
		 *
		 * @param int $group_id Group.
		 * @param int $user_id  Member.
		 * @param int $actor_id Actor.
		 */
		do_action( 'odsi_social_group_member_banned', $group_id, $user_id, $actor_id );

		return true;
	}

	/**
	 * Lift a ban.
	 *
	 * @param int $actor_id Actor.
	 * @param int $group_id Group.
	 * @param int $user_id  Member.
	 *
	 * @return true|WP_Error
	 */
	public function unban( int $actor_id, int $group_id, int $user_id ): bool|WP_Error {
		if ( ! $this->service->can_view( $actor_id, $group_id ) ) {
			return $this->not_found();
		}

		if ( ! $this->service->is_moderator( $actor_id, $group_id ) ) {
			return $this->forbidden();
		}

		$row = $this->members->find_for( $group_id, $user_id );

		if ( ! $row || Members::STATUS_BANNED !== $row->status ) {
			return true;
		}

		$this->members->remove( $group_id, $user_id );

		/**
		 * Fires when a ban is lifted.
		 *
		 * @param int $group_id Group.
		 * @param int $user_id  Member.
		 * @param int $actor_id Actor.
		 */
		do_action( 'odsi_social_group_member_unbanned', $group_id, $user_id, $actor_id );

		return true;
	}

	/**
	 * Change an active member's role (organiser only).
	 *
	 * @param int    $actor_id Actor.
	 * @param int    $group_id Group.
	 * @param int    $user_id  Member.
	 * @param string $role     New role.
	 *
	 * @return true|WP_Error
	 */
	public function set_role( int $actor_id, int $group_id, int $user_id, string $role ): bool|WP_Error {
		if ( ! $this->service->can_view( $actor_id, $group_id ) ) {
			return $this->not_found();
		}

		if ( ! $this->service->is_organiser( $actor_id, $group_id ) ) {
			return $this->forbidden();
		}

		if ( ! in_array( $role, array( Members::ROLE_ORGANISER, Members::ROLE_MODERATOR, Members::ROLE_MEMBER ), true ) ) {
			return new WP_Error( 'odsi_social_invalid_role', __( 'That role does not exist.', 'odsi-social' ) );
		}

		$row = $this->members->find_for( $group_id, $user_id );

		if ( ! $row || Members::STATUS_ACTIVE !== $row->status ) {
			return new WP_Error( 'odsi_social_no_membership', __( 'That member is not in the group.', 'odsi-social' ) );
		}

		$previous = (string) $row->role;

		if ( $previous === $role ) {
			return true;
		}

		if ( Members::ROLE_ORGANISER === $previous && $this->members->count( $group_id, Members::STATUS_ACTIVE, Members::ROLE_ORGANISER ) <= 1 ) {
			return new WP_Error( 'odsi_social_last_organiser', __( 'A group must keep at least one organiser.', 'odsi-social' ) );
		}

		$this->members->put( $group_id, $user_id, $role, Members::STATUS_ACTIVE, (int) $row->inviter_id );

		/**
		 * Fires when a member's group role changes.
		 *
		 * @param int    $group_id Group.
		 * @param int    $user_id  Member.
		 * @param string $role     New role.
		 * @param string $previous Previous role.
		 */
		do_action( 'odsi_social_group_role_changed', $group_id, $user_id, $role, $previous );

		return true;
	}

	/**
	 * System-level activation with no acting member: the caller is a process
	 * (a cohort sync, the bridge), not a person, so no visibility check applies.
	 * Bans are still honoured. Idempotent on an active row.
	 *
	 * @param int    $group_id Group.
	 * @param int    $user_id  Member.
	 * @param string $via      Reason string passed to `odsi_social_group_member_joined`.
	 */
	public function add( int $group_id, int $user_id, string $via = 'system' ): bool {
		if ( ! $this->service->exists( $group_id ) || $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return false;
		}

		$existing = $this->members->find_for( $group_id, $user_id );

		if ( $existing && Members::STATUS_BANNED === $existing->status ) {
			return false;
		}

		if ( $existing && Members::STATUS_ACTIVE === $existing->status ) {
			return true;
		}

		$this->activate( $group_id, $user_id, Members::ROLE_MEMBER, $via, $existing ? (int) $existing->inviter_id : 0 );

		return true;
	}

	/**
	 * System-level removal of a plain member. Organisers and moderators are left
	 * alone: a process may not strip a role a person granted.
	 *
	 * @param int $group_id Group.
	 * @param int $user_id  Member.
	 *
	 * @return bool True when an active membership was removed.
	 */
	public function remove_member( int $group_id, int $user_id ): bool {
		$row = $this->members->find_for( $group_id, $user_id );

		if ( ! $row || Members::STATUS_ACTIVE !== $row->status || Members::ROLE_MEMBER !== $row->role ) {
			return false;
		}

		$this->members->remove( $group_id, $user_id );
		$this->groups->adjust( $group_id, 'member_count', -1 );

		do_action( 'odsi_social_group_member_left', $group_id, $user_id, 'remove' );

		return true;
	}

	/**
	 * Group ids a member is active in.
	 *
	 * @param int $user_id Member.
	 *
	 * @return int[]
	 */
	public function groups_of( int $user_id ): array {
		return $this->members->group_ids_for( $user_id );
	}

	/**
	 * Remove a deleted user from every group, keeping the organiser invariant
	 * (edge-case table: sole organiser deleted).
	 *
	 * @param int $user_id Deleted member.
	 */
	public function purge_user( int $user_id ): void {
		foreach ( $this->members->group_ids_for( $user_id ) as $group_id ) {
			$row = $this->members->find_for( $group_id, $user_id );

			if ( $row && Members::ROLE_ORGANISER === $row->role && $this->members->count( $group_id, Members::STATUS_ACTIVE, Members::ROLE_ORGANISER ) <= 1 ) {
				$this->promote_successor( $group_id, $user_id );
			}

			$this->groups->adjust( $group_id, 'member_count', -1 );
		}

		$this->members->delete_user( $user_id );
	}

	/**
	 * Transition a row to active and announce it.
	 *
	 * @param int    $group_id   Group.
	 * @param int    $user_id    Member.
	 * @param string $role       Role.
	 * @param string $via        `join`, `approve` or `accept_invite`.
	 * @param int    $inviter_id Inviter, if any.
	 */
	private function activate( int $group_id, int $user_id, string $role, string $via, int $inviter_id ): void {
		$this->members->put( $group_id, $user_id, $role, Members::STATUS_ACTIVE, $inviter_id );
		$this->groups->adjust( $group_id, 'member_count', 1 );

		/**
		 * Fires when a member becomes active in a group.
		 *
		 * @param int    $group_id   Group.
		 * @param int    $user_id    Member.
		 * @param string $via        How.
		 * @param int    $inviter_id Inviter or approver, if any.
		 */
		do_action( 'odsi_social_group_member_joined', $group_id, $user_id, $via, $inviter_id );
	}

	/**
	 * Moderators may not act on organisers; nobody but an organiser acts on an organiser.
	 *
	 * @param int    $actor_id Actor.
	 * @param int    $group_id Group.
	 * @param object $row      Subject row.
	 */
	private function may_act_on( int $actor_id, int $group_id, object $row ): bool {
		if ( Members::ROLE_ORGANISER !== (string) $row->role || Members::STATUS_ACTIVE !== (string) $row->status ) {
			return true;
		}

		return $this->service->is_organiser( $actor_id, $group_id );
	}

	/**
	 * Promote the longest-standing moderator, else member, else delete the group.
	 *
	 * @param int $group_id  Group.
	 * @param int $departing Departing organiser.
	 */
	private function promote_successor( int $group_id, int $departing ): void {
		foreach ( array( Members::ROLE_MODERATOR, Members::ROLE_MEMBER ) as $role ) {
			foreach ( $this->members->for_group( $group_id, Members::STATUS_ACTIVE, $role, 50 ) as $candidate ) {
				if ( (int) $candidate->user_id === $departing ) {
					continue;
				}

				$this->members->put( $group_id, (int) $candidate->user_id, Members::ROLE_ORGANISER, Members::STATUS_ACTIVE, (int) $candidate->inviter_id );
				do_action( 'odsi_social_group_role_changed', $group_id, (int) $candidate->user_id, Members::ROLE_ORGANISER, $role );

				return;
			}
		}

		wp_delete_post( $group_id, true );
	}

	/**
	 * 404-style error (ADR-011).
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'odsi_social_group_not_found', __( 'That group does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
	}

	/**
	 * 403-style error.
	 */
	private function forbidden(): WP_Error {
		return new WP_Error( 'odsi_social_forbidden', __( 'You cannot do that in this group.', 'odsi-social' ), array( 'status' => 403 ) );
	}
}
