<?php
/**
 * Human labels for stored keys.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a stored key (a privacy level, a group visibility, a group
 * role) becomes translated text, so templates and admin screens agree and
 * nothing prints an `ucfirst()`ed database value.
 */
final class Labels {

	/**
	 * Label for an activity privacy level or a profile field visibility.
	 *
	 * @param string $level `public`, `members`, `connections`, `only_me`, `group`.
	 */
	public static function privacy( string $level ): string {
		return match ( $level ) {
			'public'      => __( 'Everyone', 'odsi-social' ),
			'members'     => __( 'Members', 'odsi-social' ),
			'connections' => __( 'My connections', 'odsi-social' ),
			'only_me'     => __( 'Only me', 'odsi-social' ),
			'group'       => __( 'Group members', 'odsi-social' ),
			default       => $level,
		};
	}

	/**
	 * Label for a group visibility.
	 *
	 * @param string $visibility `public`, `private`, `hidden`.
	 */
	public static function visibility( string $visibility ): string {
		return match ( $visibility ) {
			'public'  => __( 'Public', 'odsi-social' ),
			'private' => __( 'Private', 'odsi-social' ),
			'hidden'  => __( 'Hidden', 'odsi-social' ),
			default   => $visibility,
		};
	}

	/**
	 * Label for a group role.
	 *
	 * @param string $role `organiser`, `moderator`, `member`.
	 */
	public static function role( string $role ): string {
		return match ( $role ) {
			'organiser' => __( 'Organiser', 'odsi-social' ),
			'moderator' => __( 'Moderator', 'odsi-social' ),
			'member'    => __( 'Member', 'odsi-social' ),
			default     => $role,
		};
	}

	/**
	 * A stored UTC timestamp as an ISO 8601 `datetime` attribute value.
	 *
	 * @param string $mysql_utc `Y-m-d H:i:s` in UTC.
	 */
	public static function iso( string $mysql_utc ): string {
		$timestamp = strtotime( $mysql_utc . ' UTC' );

		return false === $timestamp ? '' : gmdate( 'c', $timestamp );
	}

	/**
	 * A stored UTC timestamp as "x ago".
	 *
	 * @param string $mysql_utc `Y-m-d H:i:s` in UTC.
	 */
	public static function ago( string $mysql_utc ): string {
		$timestamp = strtotime( $mysql_utc . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		/* translators: %s: human time difference. */
		return sprintf( __( '%s ago', 'odsi-social' ), human_time_diff( $timestamp ) );
	}

	/**
	 * A stored UTC timestamp in the site's date and time format, for a
	 * `title` attribute next to a relative time.
	 *
	 * @param string $mysql_utc `Y-m-d H:i:s` in UTC.
	 */
	public static function absolute( string $mysql_utc ): string {
		$timestamp = strtotime( $mysql_utc . ' UTC' );

		return false === $timestamp ? '' : (string) wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $timestamp );
	}
}
