<?php
/**
 * Scheduled maintenance.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social;

use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Messages\Messages;
use ODSI\Social\Notifications\Notifications;
use ODSI\Social\Repositories\ActivityRepository;
use ODSI\Social\Repositories\GroupRepository;
use ODSI\Social\Repositories\MemberRepository;
use ODSI\Social\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Daily housekeeping: notification retention, fully deleted threads, and a
 * recount of every denormalised counter from its source table.
 */
final class Maintenance implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param Notifications      $notifications Notifications.
	 * @param Messages           $messages      Messages.
	 * @param Settings           $settings      Settings.
	 * @param ActivityRepository $activity      Activity rows (comment and reaction counts).
	 * @param GroupRepository    $groups        Group index (member counts).
	 * @param MemberRepository   $members       Member index (activity, connection and follow counts).
	 */
	public function __construct(
		private Notifications $notifications,
		private Messages $messages,
		private Settings $settings,
		private ActivityRepository $activity,
		private GroupRepository $groups,
		private MemberRepository $members
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( Installer::CRON_HOOK, array( $this, 'run' ) );
	}

	/**
	 * Run every task.
	 */
	public function run(): void {
		$this->notifications->purge_read_older_than( max( 1, $this->settings->int( 'notification_retention_days' ) ) );
		$this->messages->purge_fully_deleted();
		$this->recount();
	}

	/**
	 * Bring every denormalised counter back in line with the rows it summarises.
	 * Counters are adjusted incrementally on each write; a crashed request or
	 * a direct database edit can leave them off by one, and this heals that.
	 */
	public function recount(): void {
		$this->activity->recount();
		$this->groups->recount_members();
		$this->members->recount();
	}
}
