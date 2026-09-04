<?php
/**
 * Member deletion.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Members;

use ODSI\Social\Activity\Activity;
use ODSI\Social\Connections\Connections;
use ODSI\Social\Connections\Follows;
use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Groups\Membership;
use ODSI\Social\Notifications\Notifications;
use ODSI\Social\Repositories\ActivityRepository;
use ODSI\Social\Repositories\ReactionRepository;
use ODSI\Social\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * What happens to a member's community data when their account is deleted
 * (SOC-MEM-010 and the edge-case table).
 */
final class Lifecycle implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param Profiles           $profiles      Profiles.
	 * @param Connections        $connections   Connections.
	 * @param Follows            $follows       Follows.
	 * @param Membership         $membership    Group membership.
	 * @param Notifications      $notifications Notifications.
	 * @param ReactionRepository $reactions     Reactions.
	 * @param ActivityRepository $activity      Activity rows.
	 * @param Activity           $activity_service Activity writer.
	 * @param Settings           $settings      Settings.
	 * @param Blocks             $blocks        Blocks.
	 */
	public function __construct(
		private Profiles $profiles,
		private Connections $connections,
		private Follows $follows,
		private Membership $membership,
		private Notifications $notifications,
		private ReactionRepository $reactions,
		private ActivityRepository $activity,
		private Activity $activity_service,
		private Settings $settings,
		private Blocks $blocks
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'deleted_user', array( $this, 'on_deleted_user' ) );
	}

	/**
	 * Clean up after a deleted account.
	 *
	 * @param int $user_id Deleted user.
	 */
	public function on_deleted_user( int $user_id ): void {
		$this->profiles->purge_user( $user_id );
		$this->connections->purge_user( $user_id );
		$this->follows->purge_user( $user_id );
		$this->membership->purge_user( $user_id );
		$this->notifications->purge_user( $user_id );
		$this->blocks->purge_user( $user_id );

		foreach ( $this->reactions->delete_user( $user_id ) as $activity_id ) {
			$this->activity->adjust( $activity_id, 'reaction_count', -1 );
		}

		if ( $this->settings->bool( 'delete_content_with_user' ) ) {
			foreach ( $this->activity->find_many( $this->activity->ids_by_user( $user_id ) ) as $item ) {
				$this->activity_service->destroy( $item );
			}
		}
	}
}
