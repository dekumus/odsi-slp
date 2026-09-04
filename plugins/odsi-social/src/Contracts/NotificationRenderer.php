<?php
/**
 * Notification renderer contract.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a notification row into a sentence and a link.
 */
interface NotificationRenderer {

	/**
	 * Plain-text sentence, already translated. Escaped by the caller.
	 *
	 * @param object $notification Notification row.
	 */
	public function text( object $notification ): string;

	/**
	 * Destination URL, or empty string when there is nowhere to go.
	 *
	 * @param object $notification Notification row.
	 */
	public function url( object $notification ): string;
}
