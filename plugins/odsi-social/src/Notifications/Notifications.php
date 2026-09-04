<?php
/**
 * Notifications.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Notifications;

use ODSI\Social\Repositories\MemberRepository;
use ODSI\Social\Repositories\NotificationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Write, read and count notifications. Never notifies an actor of their own action.
 */
final class Notifications {

	private const CACHE_GROUP = 'odsi_social';

	/**
	 * Constructor.
	 *
	 * @param NotificationRepository $notifications Storage.
	 * @param Renderers              $renderers     Renderers.
	 * @param MemberRepository       $members       Member index, for actor avatars.
	 */
	public function __construct(
		private NotificationRepository $notifications,
		private Renderers $renderers,
		private MemberRepository $members
	) {
	}

	/**
	 * Notify one member.
	 *
	 * @param int    $recipient_id Recipient.
	 * @param int    $actor_id     Actor.
	 * @param string $component    Component.
	 * @param string $action       Action.
	 * @param int    $item_id      Item.
	 * @param int    $secondary_id Secondary item.
	 * @param bool   $collapse     Fold into an existing unread row for the same item (SOC-NOT-004).
	 *
	 * @return int Row id, or 0 when suppressed.
	 */
	public function notify( int $recipient_id, int $actor_id, string $component, string $action, int $item_id, int $secondary_id = 0, bool $collapse = false ): int {
		if ( $recipient_id <= 0 || $recipient_id === $actor_id || ! get_userdata( $recipient_id ) ) {
			return 0;
		}

		$result = $this->notifications->upsert(
			array(
				'user_id'           => $recipient_id,
				'actor_id'          => $actor_id,
				'component'         => sanitize_key( $component ),
				'action'            => sanitize_key( $action ),
				'item_id'           => $item_id,
				'secondary_item_id' => $secondary_id,
				'collapse_key'      => $collapse ? md5( "{$component}|{$action}|{$item_id}" ) : null,
			)
		);

		wp_cache_delete( "unread_{$recipient_id}", self::CACHE_GROUP );

		$row = $this->notifications->find( $result['id'] );

		if ( $row ) {
			/**
			 * Fires after a notification is written or folded.
			 *
			 * @param object $notification Row.
			 * @param bool   $collapsed    Whether it folded into an existing row.
			 */
			do_action( 'odsi_social_notification_created', $row, $result['collapsed'] );
		}

		return $result['id'];
	}

	/**
	 * Notify several members, excluding the actor and duplicates.
	 *
	 * @param int[]  $recipient_ids Recipients.
	 * @param int    $actor_id      Actor.
	 * @param string $component     Component.
	 * @param string $action        Action.
	 * @param int    $item_id       Item.
	 * @param int    $secondary_id  Secondary item.
	 * @param bool   $collapse      Collapse.
	 */
	public function notify_many( array $recipient_ids, int $actor_id, string $component, string $action, int $item_id, int $secondary_id = 0, bool $collapse = false ): void {
		/**
		 * Filters the recipients of a notification before it is written.
		 *
		 * @param int[]                $recipients Recipients.
		 * @param string               $component  Component.
		 * @param string               $action     Action.
		 * @param array<string, mixed> $context    `actor_id`, `item_id`, `secondary_id`.
		 */
		$recipient_ids = (array) apply_filters(
			'odsi_social_notification_recipients',
			array_values( array_unique( array_map( 'intval', $recipient_ids ) ) ),
			$component,
			$action,
			array(
				'actor_id'     => $actor_id,
				'item_id'      => $item_id,
				'secondary_id' => $secondary_id,
			)
		);

		foreach ( $recipient_ids as $recipient_id ) {
			$this->notify( (int) $recipient_id, $actor_id, $component, $action, $item_id, $secondary_id, $collapse );
		}
	}

	/**
	 * Unread count, cached.
	 *
	 * @param int $user_id Member.
	 */
	public function unread_count( int $user_id ): int {
		$cached = wp_cache_get( "unread_{$user_id}", self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = $this->notifications->unread_count( $user_id );
		wp_cache_set( "unread_{$user_id}", $count, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * A page of a member's notifications, rendered.
	 *
	 * @param int  $user_id     Member.
	 * @param bool $unread_only Unread only.
	 * @param int  $page        Page number.
	 * @param int  $per_page    Page size.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list( int $user_id, bool $unread_only = false, int $page = 1, int $per_page = 20 ): array {
		$rows = $this->notifications->for_user( $user_id, $unread_only, $per_page, max( 0, $page - 1 ) * $per_page );

		$this->members->prime_display( array_map( static fn ( object $r ): int => (int) $r->actor_id, $rows ) );

		return array_map( fn ( object $row ): array => $this->present( $row ), $rows );
	}

	/**
	 * How many notifications a member has, for pagination.
	 *
	 * @param int  $user_id     Member.
	 * @param bool $unread_only Unread only.
	 */
	public function count( int $user_id, bool $unread_only = false ): int {
		return $this->notifications->count_for_user( $user_id, $unread_only );
	}

	/**
	 * Presentation shape.
	 *
	 * @param object $row Notification row.
	 *
	 * @return array<string, mixed>
	 */
	public function present( object $row ): array {
		$actor = get_userdata( (int) $row->actor_id );

		return array(
			'id'          => (int) $row->id,
			'component'   => (string) $row->component,
			'action'      => (string) $row->action,
			'item_id'     => (int) $row->item_id,
			'actor'       => array(
				'id'     => (int) $row->actor_id,
				'name'   => $actor ? $actor->display_name : __( 'A former member', 'odsi-social' ),
				'avatar' => $actor ? get_avatar_url( (int) $row->actor_id, array( 'size' => 64 ) ) : '',
			),
			'actor_count' => (int) $row->actor_count,
			'text'        => $this->renderers->text( $row ),
			'url'         => $this->renderers->url( $row ),
			'is_new'      => (bool) $row->is_new,
			'date'        => (string) $row->date_notified,
		);
	}

	/**
	 * Mark some or all read.
	 *
	 * @param int        $user_id Member.
	 * @param int[]|null $ids     Ids, or null for all.
	 */
	public function mark_read( int $user_id, ?array $ids = null ): int {
		$changed = $this->notifications->mark_read( $user_id, $ids );

		wp_cache_delete( "unread_{$user_id}", self::CACHE_GROUP );

		if ( $changed > 0 ) {
			/**
			 * Fires after notifications are marked read.
			 *
			 * @param int        $user_id Member.
			 * @param int[]|null $ids     Ids, or null for all.
			 */
			do_action( 'odsi_social_notifications_read', $user_id, $ids );
		}

		return $changed;
	}

	/**
	 * Mark a member's unread notifications about one item read.
	 *
	 * @param int    $user_id   Member.
	 * @param string $component Component.
	 * @param string $action    Action.
	 * @param int    $item_id   Item.
	 */
	public function mark_read_for_item( int $user_id, string $component, string $action, int $item_id ): int {
		$changed = $this->notifications->mark_read_for_item( $user_id, $component, $action, $item_id );

		if ( $changed > 0 ) {
			wp_cache_delete( "unread_{$user_id}", self::CACHE_GROUP );
		}

		return $changed;
	}

	/**
	 * Delete every notification about an item (SOC-NOT-006).
	 *
	 * @param string $component Component.
	 * @param int    $item_id   Item.
	 */
	public function delete_for_item( string $component, int $item_id ): void {
		foreach ( $this->notifications->delete_for_item( $component, $item_id ) as $user_id ) {
			wp_cache_delete( "unread_{$user_id}", self::CACHE_GROUP );
		}
	}

	/**
	 * Delete a member's own notifications.
	 *
	 * @param int $user_id Member.
	 */
	public function purge_user( int $user_id ): void {
		$this->notifications->delete_user( $user_id );
		wp_cache_delete( "unread_{$user_id}", self::CACHE_GROUP );
	}

	/**
	 * Retention sweep (SOC-NOT-008).
	 *
	 * @param int $days Days to keep read notifications.
	 */
	public function purge_read_older_than( int $days ): int {
		return $this->notifications->purge_read_before( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) );
	}
}
