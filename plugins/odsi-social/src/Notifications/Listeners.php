<?php
/**
 * Domain events to notifications.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Notifications;

use ODSI\Social\Activity\Activity;
use ODSI\Social\Activity\Privacy;
use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Repositories\ActivityRepository;
use ODSI\Social\Repositories\GroupMemberRepository as Members;

defined( 'ABSPATH' ) || exit;

/**
 * The spec's trigger table (SOC-NOT-003), one listener per row. Anything not
 * here does not notify.
 */
final class Listeners implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param Notifications      $notifications Writer.
	 * @param ActivityRepository $activity      Activity, for authors and commenters.
	 * @param Members            $members       Group memberships, for organisers.
	 * @param Privacy            $privacy       Visibility, so nobody hears about what they cannot see.
	 */
	public function __construct(
		private Notifications $notifications,
		private ActivityRepository $activity,
		private Members $members,
		private Privacy $privacy
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'odsi_social_connection_requested', array( $this, 'on_connection_requested' ), 10, 2 );
		add_action( 'odsi_social_connection_accepted', array( $this, 'on_connection_accepted' ), 10, 2 );
		add_action( 'odsi_social_mentioned', array( $this, 'on_mentioned' ), 10, 3 );
		add_action( 'odsi_social_activity_posted', array( $this, 'on_activity_posted' ) );
		add_action( 'odsi_social_activity_deleted', array( $this, 'on_activity_deleted' ) );
		add_action( 'odsi_social_reaction_added', array( $this, 'on_reaction_added' ), 10, 4 );
		add_action( 'odsi_social_group_member_requested', array( $this, 'on_group_requested' ), 10, 2 );
		add_action( 'odsi_social_group_member_invited', array( $this, 'on_group_invited' ), 10, 3 );
		add_action( 'odsi_social_group_member_joined', array( $this, 'on_group_joined' ), 10, 4 );
		add_action( 'odsi_social_group_role_changed', array( $this, 'on_group_role_changed' ), 10, 4 );
		add_action( 'odsi_social_group_deleted', array( $this, 'on_group_deleted' ) );
		add_action( 'odsi_social_message_sent', array( $this, 'on_message_sent' ), 10, 2 );
		add_action( 'odsi_social_thread_opened', array( $this, 'on_thread_opened' ), 10, 2 );
	}

	/**
	 * Connection requested → the requested member.
	 *
	 * @param int $initiator_id Sender.
	 * @param int $recipient_id Recipient.
	 */
	public function on_connection_requested( int $initiator_id, int $recipient_id ): void {
		$this->notifications->notify( $recipient_id, $initiator_id, 'connections', 'requested', $initiator_id );
	}

	/**
	 * Connection accepted → the initiator.
	 *
	 * @param int $initiator_id Sender.
	 * @param int $recipient_id Accepter.
	 */
	public function on_connection_accepted( int $initiator_id, int $recipient_id ): void {
		$this->notifications->notify( $initiator_id, $recipient_id, 'connections', 'accepted', $recipient_id );
	}

	/**
	 * Mentioned → the mentioned member.
	 *
	 * @param int    $mentioned_id Mentioned.
	 * @param object $item         Activity row.
	 * @param int    $author_id    Author.
	 */
	public function on_mentioned( int $mentioned_id, object $item, int $author_id ): void {
		$this->notifications->notify( $mentioned_id, $author_id, 'activity', 'mentioned', (int) ( $item->parent_id ?: $item->id ), (int) $item->id );
	}

	/**
	 * Comment → the item's author (collapsed) and other commenters (collapsed), never the actor.
	 *
	 * @param object $item Activity row.
	 */
	public function on_activity_posted( object $item ): void {
		if ( Activity::TYPE_COMMENT !== (string) $item->type || (int) $item->parent_id <= 0 ) {
			return;
		}

		$parent = $this->activity->find( (int) $item->parent_id );

		if ( ! $parent ) {
			return;
		}

		$actor  = (int) $item->user_id;
		$author = (int) $parent->user_id;

		$this->notifications->notify( $author, $actor, 'activity', 'commented', (int) $parent->id, (int) $item->id, true );

		$others = array();

		foreach ( $this->activity->comments( (int) $parent->id, 500 ) as $comment ) {
			$commenter = (int) $comment->user_id;

			if ( $commenter !== $actor && $commenter !== $author && $this->privacy->can_view( $commenter, $parent ) ) {
				$others[] = $commenter;
			}
		}

		$this->notifications->notify_many( $others, $actor, 'activity', 'also_commented', (int) $parent->id, (int) $item->id, true );
	}

	/**
	 * Item deleted → its notifications go with it.
	 *
	 * @param object $item Activity row.
	 */
	public function on_activity_deleted( object $item ): void {
		$this->notifications->delete_for_item( 'activity', (int) $item->id );
	}

	/**
	 * Reaction → the author, collapsed per item.
	 *
	 * @param int    $activity_id Item.
	 * @param int    $user_id     Reactor.
	 * @param string $type        Reaction.
	 * @param object $item        Activity row.
	 */
	public function on_reaction_added( int $activity_id, int $user_id, string $type, object $item ): void {
		$this->notifications->notify( (int) $item->user_id, $user_id, 'activity', 'reacted', $activity_id, 0, true );
	}

	/**
	 * Join request → organisers and moderators.
	 *
	 * @param int $group_id Group.
	 * @param int $user_id  Requester.
	 */
	public function on_group_requested( int $group_id, int $user_id ): void {
		$staff = array();

		foreach ( array( Members::ROLE_ORGANISER, Members::ROLE_MODERATOR ) as $role ) {
			foreach ( $this->members->for_group( $group_id, Members::STATUS_ACTIVE, $role, 200 ) as $row ) {
				$staff[] = (int) $row->user_id;
			}
		}

		$this->notifications->notify_many( $staff, $user_id, 'groups', 'requested', $group_id, $user_id );
	}

	/**
	 * Invitation → the invitee.
	 *
	 * @param int $group_id   Group.
	 * @param int $user_id    Invitee.
	 * @param int $inviter_id Inviter.
	 */
	public function on_group_invited( int $group_id, int $user_id, int $inviter_id ): void {
		$this->notifications->notify( $user_id, $inviter_id, 'groups', 'invited', $group_id );
	}

	/**
	 * Approval → the requester; invitation accepted → the inviter.
	 *
	 * @param int    $group_id   Group.
	 * @param int    $user_id    Member.
	 * @param string $via        How.
	 * @param int    $inviter_id Approver or inviter.
	 */
	public function on_group_joined( int $group_id, int $user_id, string $via, int $inviter_id ): void {
		if ( 'approve' === $via ) {
			$this->notifications->notify( $user_id, $inviter_id, 'groups', 'approved', $group_id );
		} elseif ( 'accept_invite' === $via ) {
			$this->notifications->notify( $inviter_id, $user_id, 'groups', 'invite_accepted', $group_id );
		}
	}

	/**
	 * Promotion → the member (SOC-GRP-009). A demotion notifies no one.
	 *
	 * @param int    $group_id Group.
	 * @param int    $user_id  Member.
	 * @param string $role     New role.
	 * @param string $previous Previous role.
	 */
	public function on_group_role_changed( int $group_id, int $user_id, string $role, string $previous ): void {
		$rank = array(
			Members::ROLE_MEMBER    => 0,
			Members::ROLE_MODERATOR => 1,
			Members::ROLE_ORGANISER => 2,
		);

		if ( ( $rank[ $role ] ?? 0 ) <= ( $rank[ $previous ] ?? 0 ) ) {
			return;
		}

		$this->notifications->notify( $user_id, get_current_user_id(), 'groups', 'promoted', $group_id );
	}

	/**
	 * Group deleted → its notifications go with it.
	 *
	 * @param int $group_id Group.
	 */
	public function on_group_deleted( int $group_id ): void {
		$this->notifications->delete_for_item( 'groups', $group_id );
	}

	/**
	 * Message → other participants, collapsed per thread.
	 *
	 * @param object $message       Message row.
	 * @param int[]  $recipient_ids Other participants.
	 */
	public function on_message_sent( object $message, array $recipient_ids ): void {
		$this->notifications->notify_many( $recipient_ids, (int) $message->sender_id, 'messages', 'new', (int) $message->thread_id, (int) $message->id, true );
	}

	/**
	 * Thread opened → its "new message" notification is read.
	 *
	 * @param int $thread_id Thread.
	 * @param int $user_id   Reader.
	 */
	public function on_thread_opened( int $thread_id, int $user_id ): void {
		$this->notifications->mark_read_for_item( $user_id, 'messages', 'new', $thread_id );
	}
}
