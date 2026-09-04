<?php
/**
 * Reporter notification.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Moderation;

use ODSI\Social\Contracts\NotificationRenderer;

defined( 'ABSPATH' ) || exit;

/**
 * "Your report has been reviewed." The sentence names no moderator and no
 * outcome: the reporter learns the complaint was handled, nothing more.
 */
final class ReportNotificationRenderer implements NotificationRenderer {

	/**
	 * Sentence.
	 *
	 * @param object $notification Notification row.
	 */
	public function text( object $notification ): string {
		return __( 'A moderator has reviewed your report.', 'odsi-social' );
	}

	/**
	 * There is nowhere to go: the content may be gone.
	 *
	 * @param object $notification Notification row.
	 */
	public function url( object $notification ): string {
		return '';
	}
}
