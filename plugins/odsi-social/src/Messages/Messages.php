<?php
/**
 * Private messages.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Messages;

use ODSI\Social\Connections\Connections;
use ODSI\Social\Repositories\MemberRepository;
use ODSI\Social\Repositories\MessageRepository;
use ODSI\Social\Repositories\ThreadRepository;
use ODSI\Social\Support\Capabilities;
use ODSI\Social\Support\Sanitizer;
use ODSI\Social\Support\Settings;
use stdClass;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Two-party threads (spec § 6).
 */
final class Messages {

	/**
	 * Constructor.
	 *
	 * @param ThreadRepository  $threads     Threads.
	 * @param MessageRepository $messages    Messages.
	 * @param MemberRepository  $members     Member index, for the message setting.
	 * @param Connections       $connections Connections, for `connections` setting.
	 * @param Settings          $settings    Settings.
	 */
	public function __construct(
		private ThreadRepository $threads,
		private MessageRepository $messages,
		private MemberRepository $members,
		private Connections $connections,
		private Settings $settings
	) {
	}

	/**
	 * Whether the sender may message the recipient (SOC-MSG-002).
	 *
	 * @param int $sender_id    Sender.
	 * @param int $recipient_id Recipient.
	 */
	public function can_message( int $sender_id, int $recipient_id ): bool {
		if ( $sender_id <= 0 || $recipient_id <= 0 || $sender_id === $recipient_id || ! get_userdata( $recipient_id ) ) {
			return false;
		}

		$row     = $this->members->find( $recipient_id );
		$setting = $row ? (string) $row->message_setting : 'anyone';

		$allowed = match ( $setting ) {
			'no_one'      => false,
			'connections' => $this->connections->are_connected( $sender_id, $recipient_id ),
			default       => true,
		};

		if ( Capabilities::is_admin( $sender_id ) ) {
			$allowed = true;
		}

		/**
		 * Filters whether a member may message another.
		 *
		 * @param bool $allowed      Decision.
		 * @param int  $sender_id    Sender.
		 * @param int  $recipient_id Recipient.
		 */
		return (bool) apply_filters( 'odsi_social_can_message', $allowed, $sender_id, $recipient_id );
	}

	/**
	 * Send a message, creating or reusing the pair's thread (SOC-MSG-001).
	 *
	 * @param int    $sender_id    Sender.
	 * @param int    $recipient_id Recipient.
	 * @param string $content      Raw content.
	 *
	 * @return stdClass|WP_Error Message row.
	 */
	public function send( int $sender_id, int $recipient_id, string $content ): stdClass|WP_Error {
		if ( ! $this->can_message( $sender_id, $recipient_id ) ) {
			return new WP_Error( 'odsi_social_cannot_message', __( 'You cannot message this member.', 'odsi-social' ), array( 'status' => 403 ) );
		}

		$content = Sanitizer::content( $content, $this->settings->int( 'message_max_length' ) );

		if ( '' === $content ) {
			return new WP_Error( 'odsi_social_empty_content', __( 'Please write a message first.', 'odsi-social' ) );
		}

		$thread = $this->threads->find_pair( $sender_id, $recipient_id );
		$id     = $thread ? (int) $thread->id : $this->threads->create( array( $sender_id, $recipient_id ), ThreadRepository::pair_key( $sender_id, $recipient_id ) );

		$message_id = $this->messages->send( $id, $sender_id, $content );
		$this->threads->record_message( $id, $message_id, $sender_id );

		wp_cache_delete( "unread_msgs_{$recipient_id}", 'odsi_social' );

		$message = $this->messages->find( $message_id );

		/**
		 * Fires after a message is sent.
		 *
		 * @param object $message       Message row.
		 * @param int[]  $recipient_ids Other participants.
		 */
		do_action( 'odsi_social_message_sent', $message, array( $recipient_id ) );

		return $message;
	}

	/**
	 * Reply into an existing thread the actor participates in.
	 *
	 * @param int    $sender_id Sender.
	 * @param int    $thread_id Thread.
	 * @param string $content   Raw content.
	 *
	 * @return stdClass|WP_Error
	 */
	public function reply( int $sender_id, int $thread_id, string $content ): stdClass|WP_Error {
		$other = $this->other_participant( $sender_id, $thread_id );

		if ( null === $other ) {
			return $this->not_found();
		}

		return $this->send( $sender_id, $other, $content );
	}

	/**
	 * Inbox page (SOC-MSG-006).
	 *
	 * @param int $user_id  Member.
	 * @param int $page     Page.
	 * @param int $per_page Page size.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function inbox( int $user_id, int $page = 1, int $per_page = 20 ): array {
		$rows = $this->threads->inbox( $user_id, $per_page, max( 0, $page - 1 ) * $per_page );
		$out  = array();

		foreach ( $rows as $thread ) {
			$other = $this->other_participant( $user_id, (int) $thread->id );
			$last  = (int) $thread->last_message_id > 0 ? $this->messages->find( (int) $thread->last_message_id ) : null;
			$user  = $other ? get_userdata( $other ) : null;

			$out[] = array(
				'thread_id'    => (int) $thread->id,
				'other'        => array(
					'id'     => (int) $other,
					'name'   => $user ? $user->display_name : __( 'A former member', 'odsi-social' ),
					'avatar' => $user ? get_avatar_url( (int) $other, array( 'size' => 64 ) ) : '',
				),
				'unread_count' => (int) $thread->unread_count,
				'last_message' => $last ? wp_trim_words( wp_strip_all_tags( (string) $last->content ), 20 ) : '',
				'last_at'      => (string) $thread->last_message_at,
			);
		}

		return $out;
	}

	/**
	 * Read a thread and mark it read (SOC-MSG-004).
	 *
	 * @param int $user_id   Member.
	 * @param int $thread_id Thread.
	 * @param int $before_id Older than this message id, or 0.
	 * @param int $limit     Limit.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function thread( int $user_id, int $thread_id, int $before_id = 0, int $limit = 50 ): array|WP_Error {
		$participant = $this->threads->participant( $thread_id, $user_id );

		if ( ! $participant || (int) $participant->is_deleted ) {
			return $this->not_found();
		}

		$this->threads->mark_read( $thread_id, $user_id );
		wp_cache_delete( "unread_msgs_{$user_id}", 'odsi_social' );

		$messages = $this->messages->for_thread( $thread_id, $limit, $before_id );

		cache_users( array_values( array_unique( array_map( static fn ( object $m ): int => (int) $m->sender_id, $messages ) ) ) );

		return array(
			'thread_id' => $thread_id,
			'other'     => (int) $this->other_participant( $user_id, $thread_id ),
			'messages'  => array_map(
				static function ( object $m ): array {
					$sender = get_userdata( (int) $m->sender_id );

					return array(
						'id'        => (int) $m->id,
						'sender_id' => (int) $m->sender_id,
						'sender'    => $sender ? $sender->display_name : __( 'A former member', 'odsi-social' ),
						'content'   => Sanitizer::render( (string) $m->content ),
						'date'      => (string) $m->date_sent,
					);
				},
				$messages
			),
		);
	}

	/**
	 * Delete a thread for the actor only (SOC-MSG-005), or outright for admins.
	 *
	 * @param int  $user_id   Member.
	 * @param int  $thread_id Thread.
	 * @param bool $hard      Physically delete (admin).
	 *
	 * @return true|WP_Error
	 */
	public function delete( int $user_id, int $thread_id, bool $hard = false ): bool|WP_Error {
		if ( $hard && Capabilities::is_admin( $user_id ) ) {
			$this->threads->delete( $thread_id );

			return true;
		}

		$participant = $this->threads->participant( $thread_id, $user_id );

		if ( ! $participant ) {
			return $this->not_found();
		}

		$this->threads->soft_delete( $thread_id, $user_id );
		wp_cache_delete( "unread_msgs_{$user_id}", 'odsi_social' );

		return true;
	}

	/**
	 * Unread total, cached.
	 *
	 * @param int $user_id Member.
	 */
	public function unread_total( int $user_id ): int {
		$cached = wp_cache_get( "unread_msgs_{$user_id}", 'odsi_social' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$total = $this->threads->unread_total( $user_id );
		wp_cache_set( "unread_msgs_{$user_id}", $total, 'odsi_social', HOUR_IN_SECONDS );

		return $total;
	}

	/**
	 * Remove threads every participant has deleted (maintenance).
	 */
	public function purge_fully_deleted(): int {
		$count = 0;

		foreach ( $this->threads->fully_deleted_ids() as $id ) {
			$this->threads->delete( $id );
			++$count;
		}

		return $count;
	}

	/**
	 * The other participant of a two-party thread the user is in.
	 *
	 * @param int $user_id   Member.
	 * @param int $thread_id Thread.
	 */
	private function other_participant( int $user_id, int $thread_id ): ?int {
		$participants = $this->threads->participants( $thread_id );
		$mine         = false;
		$other        = null;

		foreach ( $participants as $p ) {
			if ( (int) $p->user_id === $user_id ) {
				$mine = true;
			} else {
				$other = (int) $p->user_id;
			}
		}

		return $mine ? $other : null;
	}

	/**
	 * 404-style error (ADR-011).
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'odsi_social_thread_not_found', __( 'That conversation does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
	}
}
