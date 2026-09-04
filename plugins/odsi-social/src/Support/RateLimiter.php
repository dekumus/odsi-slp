<?php
/**
 * Per-member rate limits.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sliding-window counters in the object cache (transients without one), so a
 * single member cannot flood others with requests, mentions or uploads
 * (SOC-EDGE "abuse"). Limits are generous for people and tight for scripts.
 */
final class RateLimiter {

	private const GROUP = 'odsi_social_rate';

	/**
	 * Default limits: action => [ count, seconds ].
	 *
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function limits(): array {
		$defaults = array(
			'activity_post'      => array( 30, 600 ),
			'activity_comment'   => array( 60, 600 ),
			'connection_request' => array( 20, 3600 ),
			'message_send'       => array( 60, 600 ),
			'group_invite'       => array( 60, 3600 ),
			'group_create'       => array( 10, 86400 ),
			'image_upload'       => array( 10, 3600 ),
		);

		/**
		 * Filters the per-member rate limits.
		 *
		 * @param array<string, array{0: int, 1: int}> $limits Action => [ count, window seconds ].
		 */
		return (array) apply_filters( 'odsi_social_rate_limits', $defaults );
	}

	/**
	 * Count one action and report whether it is still within its limit.
	 *
	 * @param string $action  Limit key.
	 * @param int    $user_id Member; 0 is never limited (the site's own code).
	 *
	 * @return true|WP_Error 429 error when over the limit.
	 */
	public static function check( string $action, int $user_id ): bool|WP_Error {
		$limits = self::limits();

		if ( $user_id <= 0 || empty( $limits[ $action ] ) ) {
			return true;
		}

		[ $max, $window ] = $limits[ $action ];
		$key              = "{$action}_{$user_id}";
		$count            = (int) wp_cache_get( $key, self::GROUP );

		if ( false === wp_cache_get( $key, self::GROUP ) ) {
			$count = (int) get_transient( self::GROUP . "_{$key}" );
		}

		if ( $count >= $max ) {
			return new WP_Error(
				'odsi_social_rate_limited',
				__( 'You are doing that too often. Please wait a while and try again.', 'odsi-social' ),
				array( 'status' => 429 )
			);
		}

		++$count;

		if ( ! wp_cache_set( $key, $count, self::GROUP, $window ) || ! wp_using_ext_object_cache() ) {
			set_transient( self::GROUP . "_{$key}", $count, $window );
		}

		return true;
	}

	/**
	 * Forget a member's counters (tests, moderation).
	 *
	 * @param int $user_id Member.
	 */
	public static function reset( int $user_id ): void {
		foreach ( array_keys( self::limits() ) as $action ) {
			wp_cache_delete( "{$action}_{$user_id}", self::GROUP );
			delete_transient( self::GROUP . "_{$action}_{$user_id}" );
		}
	}
}
