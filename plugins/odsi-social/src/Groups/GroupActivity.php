<?php
/**
 * Group events as activity.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Groups;

use ODSI\Social\Activity\Activity;
use ODSI\Social\Activity\Privacy;
use ODSI\Social\Activity\Renderers;
use ODSI\Social\Contracts\ActivityRenderer;
use ODSI\Social\Contracts\Bootable;

defined( 'ABSPATH' ) || exit;

/**
 * Posts "created a group" and "joined a group" items (SOC-GRP-008) and renders them.
 */
final class GroupActivity implements Bootable, ActivityRenderer {

	public const COMPONENT = 'groups';

	/**
	 * Constructor.
	 *
	 * @param Activity  $activity  Writer.
	 * @param Groups    $groups    Groups, for visibility.
	 * @param Renderers $renderers Registry.
	 */
	public function __construct(
		private Activity $activity,
		private Groups $groups,
		private Renderers $renderers
	) {
	}

	/**
	 * Register hooks and renderers.
	 */
	public function boot(): void {
		add_action( 'odsi_social_group_created', array( $this, 'on_created' ), 10, 2 );
		add_action( 'odsi_social_group_member_joined', array( $this, 'on_joined' ), 10, 3 );

		$this->renderers->register( 'created_group', $this, self::COMPONENT );
		$this->renderers->register( 'joined_group', $this, self::COMPONENT );
	}

	/**
	 * Group created.
	 *
	 * @param int $group_id   Group.
	 * @param int $creator_id Creator.
	 */
	public function on_created( int $group_id, int $creator_id ): void {
		$this->post( 'created_group', $group_id, $creator_id );
	}

	/**
	 * Member joined, except by invitation into a hidden group.
	 *
	 * @param int    $group_id Group.
	 * @param int    $user_id  Member.
	 * @param string $via      How.
	 */
	public function on_joined( int $group_id, int $user_id, string $via ): void {
		if ( 'accept_invite' === $via && 'hidden' === $this->groups->visibility( $group_id ) ) {
			return;
		}

		$this->post( 'joined_group', $group_id, $user_id );
	}

	/**
	 * Write the item.
	 *
	 * @param string $type     Type.
	 * @param int    $group_id Group.
	 * @param int    $user_id  Actor.
	 */
	private function post( string $type, int $group_id, int $user_id ): void {
		$this->activity->post(
			array(
				'user_id'         => $user_id,
				'component'       => self::COMPONENT,
				'type'            => $type,
				'content'         => '',
				'group_id'        => $group_id,
				'primary_item_id' => $group_id,
				'privacy'         => Privacy::GROUP,
				'external_id'     => "{$type}:{$group_id}:{$user_id}",
			)
		);
	}

	/**
	 * Action sentence.
	 *
	 * @param object $item Activity row.
	 */
	public function action( object $item ): string {
		$user  = get_userdata( (int) $item->user_id );
		$name  = $user ? $user->display_name : __( 'A former member', 'odsi-social' );
		$group = get_post( (int) $item->primary_item_id );
		$title = $group ? $group->post_title : __( 'a group', 'odsi-social' );
		$url   = (string) apply_filters( 'odsi_social_group_url', '', (int) $item->primary_item_id );
		$link  = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $title ) );

		if ( 'created_group' === (string) $item->type ) {
			/* translators: 1: member name, 2: group link. */
			return sprintf( esc_html__( '%1$s created the group %2$s', 'odsi-social' ), esc_html( $name ), $link );
		}

		/* translators: 1: member name, 2: group link. */
		return sprintf( esc_html__( '%1$s joined the group %2$s', 'odsi-social' ), esc_html( $name ), $link );
	}

	/**
	 * No body.
	 *
	 * @param object $item Activity row.
	 */
	public function body( object $item ): string {
		return '';
	}
}
