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
use ODSI\Social\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Daily housekeeping: notification retention, fully deleted threads.
 */
final class Maintenance implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param Notifications $notifications Notifications.
	 * @param Messages      $messages      Messages.
	 * @param Settings      $settings      Settings.
	 */
	public function __construct(
		private Notifications $notifications,
		private Messages $messages,
		private Settings $settings
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
	}
}
