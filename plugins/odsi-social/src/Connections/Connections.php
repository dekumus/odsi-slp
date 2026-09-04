<?php
/**
 * Connections.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Connections;

use ODSI\Social\Repositories\ConnectionRepository;
use ODSI\Social\Repositories\MemberRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The connection state machine (spec § 2). Every method takes the acting
 * member first and rejects anything the table does not permit.
 */
final class Connections {

	public const NONE             = '';
	public const PENDING_SENT     = 'pending_sent';
	public const PENDING_RECEIVED = 'pending_received';
	public const ACCEPTED         = 'accepted';

	/**
	 * Constructor.
	 *
	 * @param ConnectionRepository $connections Storage.
	 * @param MemberRepository     $members     Member index, for counts.
	 */
	public function __construct(
		private ConnectionRepository $connections,
		private MemberRepository $members
	) {
	}

	/**
	 * The relationship from the actor's point of view.
	 *
	 * @param int $actor_id Actor.
	 * @param int $other_id Other member.
	 */
	public function status( int $actor_id, int $other_id ): string {
		$row = $this->connections->find_pair( $actor_id, $other_id );

		if ( ! $row ) {
			return self::NONE;
		}

		if ( ConnectionRepository::STATUS_ACCEPTED === $row->status ) {
			return self::ACCEPTED;
		}

		return (int) $row->initiator_id === $actor_id ? self::PENDING_SENT : self::PENDING_RECEIVED;
	}

	/**
	 * Whether two members are connected.
	 *
	 * @param int $a Member.
	 * @param int $b Member.
	 */
	public function are_connected( int $a, int $b ): bool {
		return $this->connections->are_connected( $a, $b );
	}

	/**
	 * Ids of a member's connections.
	 *
	 * @param int $user_id Member.
	 *
	 * @return int[]
	 */
	public function ids_for( int $user_id ): array {
		return $this->connections->ids_for( $user_id );
	}

	/**
	 * Warm the pair cache for a member against several others, so a list of
	 * profiles asks about each relationship without a query per row.
	 *
	 * @param int   $user_id Member.
	 * @param int[] $others  Other members.
	 */
	public function prime_pairs( int $user_id, array $others ): void {
		$this->connections->prime_pairs( $user_id, $others );
	}

	/**
	 * Send a request.
	 *
	 * @param int $actor_id  Actor.
	 * @param int $target_id Target.
	 *
	 * @return true|WP_Error
	 */
	public function request( int $actor_id, int $target_id ): bool|WP_Error {
		if ( $actor_id <= 0 || $target_id <= 0 || $actor_id === $target_id || ! get_userdata( $target_id ) ) {
			return new WP_Error( 'odsi_social_invalid_target', __( 'You cannot connect with that member.', 'odsi-social' ) );
		}

		if ( $this->connections->find_pair( $actor_id, $target_id ) ) {
			return new WP_Error( 'odsi_social_connection_exists', __( 'A connection or request already exists.', 'odsi-social' ) );
		}

		// Withdraw-and-request loops would notify the target every time; after
		// a withdrawal or removal the pair rests before a new request.
		if ( get_transient( "odsi_social_conn_cooldown_{$actor_id}_{$target_id}" ) ) {
			return new WP_Error( 'odsi_social_connection_cooldown', __( 'You recently withdrew a request to this member. Try again later.', 'odsi-social' ), array( 'status' => 429 ) );
		}

		$this->connections->request( $actor_id, $target_id );

		/**
		 * Fires when a connection request is sent.
		 *
		 * @param int $initiator_id Sender.
		 * @param int $recipient_id Recipient.
		 */
		do_action( 'odsi_social_connection_requested', $actor_id, $target_id );

		return true;
	}

	/**
	 * Accept a request sent to the actor.
	 *
	 * @param int $actor_id     Actor (the recipient).
	 * @param int $initiator_id Sender.
	 *
	 * @return true|WP_Error
	 */
	public function accept( int $actor_id, int $initiator_id ): bool|WP_Error {
		$row = $this->connections->find_pair( $actor_id, $initiator_id );

		if ( ! $row || ConnectionRepository::STATUS_PENDING !== $row->status || (int) $row->initiator_id !== $initiator_id || $actor_id === $initiator_id ) {
			return new WP_Error( 'odsi_social_no_request', __( 'There is no request to accept.', 'odsi-social' ) );
		}

		$this->connections->accept( (int) $row->id );
		$this->members->adjust( $actor_id, 'connection_count', 1 );
		$this->members->adjust( $initiator_id, 'connection_count', 1 );

		/**
		 * Fires when a connection request is accepted.
		 *
		 * @param int $initiator_id Sender.
		 * @param int $recipient_id Accepter.
		 */
		do_action( 'odsi_social_connection_accepted', $initiator_id, $actor_id );

		return true;
	}

	/**
	 * Withdraw a request the actor sent, decline one they received, or remove a connection.
	 *
	 * @param int $actor_id Actor.
	 * @param int $other_id Other member.
	 *
	 * @return true|WP_Error
	 */
	public function remove( int $actor_id, int $other_id ): bool|WP_Error {
		$row = $this->connections->find_pair( $actor_id, $other_id );

		if ( ! $row ) {
			return new WP_Error( 'odsi_social_no_connection', __( 'There is nothing to remove.', 'odsi-social' ) );
		}

		$previous = (string) $row->status;

		if ( ConnectionRepository::STATUS_PENDING === $previous ) {
			$previous = (int) $row->initiator_id === $actor_id ? 'withdrawn' : 'declined';
		}

		$this->connections->delete( (int) $row->id );

		/**
		 * Filters how long a pair rests after a withdrawal, decline or removal
		 * before either side may send a new request.
		 *
		 * @param int $seconds Seconds; one hour by default.
		 */
		$cooldown = (int) apply_filters( 'odsi_social_connection_cooldown', HOUR_IN_SECONDS );

		if ( $cooldown > 0 ) {
			set_transient( "odsi_social_conn_cooldown_{$actor_id}_{$other_id}", 1, $cooldown );
			set_transient( "odsi_social_conn_cooldown_{$other_id}_{$actor_id}", 1, $cooldown );
		}

		if ( ConnectionRepository::STATUS_ACCEPTED === (string) $row->status ) {
			$this->members->adjust( $actor_id, 'connection_count', -1 );
			$this->members->adjust( $other_id, 'connection_count', -1 );
		}

		/**
		 * Fires on any removal: withdraw, decline, or removing a connection.
		 *
		 * @param int    $actor_id Actor.
		 * @param int    $other_id Other member.
		 * @param string $previous `accepted`, `withdrawn` or `declined`.
		 */
		do_action( 'odsi_social_connection_removed', $actor_id, $other_id, $previous );

		return true;
	}

	/**
	 * Pending requests received by a member.
	 *
	 * @param int $user_id Member.
	 *
	 * @return int[] Initiator ids.
	 */
	public function pending_received( int $user_id ): array {
		return array_map( static fn ( object $r ): int => (int) $r->initiator_id, $this->connections->pending_for( $user_id ) );
	}

	/**
	 * Pending requests sent by a member.
	 *
	 * @param int $user_id Member.
	 *
	 * @return int[] Recipient ids.
	 */
	public function pending_sent( int $user_id ): array {
		return array_map(
			static fn ( object $r ): int => (int) $r->user_low === (int) $r->initiator_id ? (int) $r->user_high : (int) $r->user_low,
			$this->connections->sent_by( $user_id )
		);
	}

	/**
	 * Remove every edge for a deleted user and fix the other side's counts.
	 *
	 * @param int $user_id Deleted member.
	 */
	public function purge_user( int $user_id ): void {
		foreach ( $this->connections->delete_user( $user_id ) as $other ) {
			$this->members->adjust( $other, 'connection_count', -1 );
		}
	}
}
