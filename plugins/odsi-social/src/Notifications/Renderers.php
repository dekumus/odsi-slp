<?php
/**
 * Notification renderers.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Notifications;

use ODSI\Social\Contracts\NotificationRenderer;

defined( 'ABSPATH' ) || exit;

/**
 * Registry keyed by "component/action" with the built-in sentences and a generic fallback (SOC-NOT-001).
 */
final class Renderers {

	/**
	 * Registered renderers.
	 *
	 * @var array<string, NotificationRenderer>
	 */
	private array $renderers = array();

	/**
	 * Register a renderer.
	 *
	 * @param string               $component Component.
	 * @param string               $action    Action.
	 * @param NotificationRenderer $renderer  Renderer.
	 */
	public function register( string $component, string $action, NotificationRenderer $renderer ): void {
		$this->renderers[ $component . '/' . $action ] = $renderer;
	}

	/**
	 * Sentence for a row.
	 *
	 * @param object $row Notification row.
	 */
	public function text( object $row ): string {
		$renderer = $this->renderers[ $row->component . '/' . $row->action ] ?? null;

		if ( $renderer ) {
			return $renderer->text( $row );
		}

		$actor  = get_userdata( (int) $row->actor_id );
		$name   = $actor ? $actor->display_name : __( 'A former member', 'odsi-social' );
		$others = max( 0, (int) $row->actor_count - 1 );

		if ( $others > 0 ) {
			$name = sprintf(
				/* translators: 1: member name, 2: number of other members. */
				_n( '%1$s and %2$d other', '%1$s and %2$d others', $others, 'odsi-social' ),
				$name,
				$others
			);
		}

		$sentence = match ( $row->component . '/' . $row->action ) {
			/* translators: %s: member name(s). */
			'connections/requested'  => __( '%s sent you a connection request.', 'odsi-social' ),
			/* translators: %s: member name(s). */
			'connections/accepted'   => __( '%s accepted your connection request.', 'odsi-social' ),
			/* translators: %s: member name(s). */
			'activity/mentioned'     => __( '%s mentioned you.', 'odsi-social' ),
			/* translators: %s: member name(s). */
			'activity/commented'     => __( '%s commented on your post.', 'odsi-social' ),
			/* translators: %s: member name(s). */
			'activity/also_commented' => __( '%s also commented on a post you commented on.', 'odsi-social' ),
			/* translators: %s: member name(s). */
			'activity/reacted'       => __( '%s liked your post.', 'odsi-social' ),
			/* translators: %s: member name(s). */
			'groups/requested'       => __( '%s asked to join your group.', 'odsi-social' ),
			/* translators: %s: member name(s). */
			'groups/approved'        => __( '%s approved your request to join a group.', 'odsi-social' ),
			/* translators: %s: member name(s). */
			'groups/invited'         => __( '%s invited you to a group.', 'odsi-social' ),
			/* translators: %s: member name(s). */
			'groups/invite_accepted' => __( '%s accepted your invitation to a group.', 'odsi-social' ),
			/* translators: %s: member name(s). */
			'groups/promoted'        => __( '%s changed your role in a group.', 'odsi-social' ),
			/* translators: %s: member name(s). */
			'messages/new'           => __( '%s sent you a message.', 'odsi-social' ),
			/* translators: %s: member name(s). */
			default                  => __( '%s did something on the site.', 'odsi-social' ),
		};//end match

		return sprintf( $sentence, $name );
	}

	/**
	 * Destination for a row.
	 *
	 * @param object $row Notification row.
	 */
	public function url( object $row ): string {
		$renderer = $this->renderers[ $row->component . '/' . $row->action ] ?? null;

		if ( $renderer ) {
			return $renderer->url( $row );
		}

		$url = match ( (string) $row->component ) {
			'activity'    => (string) apply_filters( 'odsi_social_activity_url', '', (int) $row->item_id ),
			'groups'      => (string) apply_filters( 'odsi_social_group_url', '', (int) $row->item_id ),
			'connections' => (string) apply_filters( 'odsi_social_member_url', '', (int) $row->actor_id ),
			'messages'    => (string) apply_filters( 'odsi_social_thread_url', '', (int) $row->item_id ),
			default       => '',
		};

		/**
		 * Filters a notification's destination.
		 *
		 * @param string $url URL.
		 * @param object $row Notification row.
		 */
		return (string) apply_filters( 'odsi_social_notification_url', $url, $row );
	}
}
