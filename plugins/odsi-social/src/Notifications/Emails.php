<?php
/**
 * Notification emails.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Notifications;

use ODSI\Social\Contracts\Bootable;

defined( 'ABSPATH' ) || exit;

/**
 * One plain-text email per in-app notification, for members who have not
 * opted out (SOC-NOT-008). Folded notifications (SOC-NOT-004) never send a
 * second email.
 */
final class Emails implements Bootable {

	public const USER_META = 'odsi_social_email_notifications';

	/**
	 * Constructor.
	 *
	 * @param Notifications $notifications Presentation (text and URL).
	 */
	public function __construct( private Notifications $notifications ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'odsi_social_notification_created', array( $this, 'on_created' ), 10, 2 );
	}

	/**
	 * Whether a member wants emails. On by default.
	 *
	 * @param int $user_id Member.
	 */
	public static function wants_email( int $user_id ): bool {
		return '0' !== (string) get_user_meta( $user_id, self::USER_META, true );
	}

	/**
	 * Store the preference.
	 *
	 * @param int  $user_id Member.
	 * @param bool $wants   Preference.
	 */
	public static function set_wants_email( int $user_id, bool $wants ): void {
		update_user_meta( $user_id, self::USER_META, $wants ? '1' : '0' );
	}

	/**
	 * Send for a fresh notification.
	 *
	 * @param object $row       Notification row.
	 * @param bool   $collapsed Whether it folded into an existing unread row.
	 */
	public function on_created( object $row, bool $collapsed ): void {
		if ( $collapsed ) {
			return;
		}

		$user = get_userdata( (int) $row->user_id );

		if ( ! $user || ! self::wants_email( (int) $row->user_id ) ) {
			return;
		}

		$presented = $this->notifications->present( $row );

		/**
		 * Filters an outgoing notification email. Return an empty array to suppress it.
		 *
		 * @param array<string, mixed> $email `to`, `subject`, `body`, `headers`.
		 * @param object               $row   Notification row.
		 */
		$email = (array) apply_filters(
			'odsi_social_notification_email',
			array(
				'to'      => $user->user_email,
				'subject' => wp_strip_all_tags( (string) $presented['text'] ),
				'body'    => wp_strip_all_tags( (string) $presented['text'] ) . ( '' !== (string) $presented['url'] ? "\n\n" . (string) $presented['url'] : '' ),
				'headers' => array(),
			),
			$row
		);

		if ( array() === $email || empty( $email['to'] ) ) {
			return;
		}

		wp_mail( (string) $email['to'], (string) $email['subject'], (string) $email['body'], (array) ( $email['headers'] ?? array() ) );
	}
}
