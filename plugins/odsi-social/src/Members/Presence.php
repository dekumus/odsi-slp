<?php
/**
 * Presence.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Members;

use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Repositories\MemberRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Writes `last_active` at most once per five minutes (SOC-MEM-011) and creates
 * the member index row lazily.
 */
final class Presence implements Bootable {

	private const INTERVAL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param MemberRepository $members Member index.
	 */
	public function __construct( private MemberRepository $members ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'touch_current_user' ), 20 );
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
	}

	/**
	 * Record activity for the current user, throttled.
	 */
	public function touch_current_user(): void {
		$user_id = get_current_user_id();

		if ( $user_id > 0 && ! wp_doing_cron() ) {
			$this->touch( $user_id );
		}
	}

	/**
	 * Record activity on login without throttling.
	 *
	 * @param string   $login Login.
	 * @param \WP_User $user  User.
	 */
	public function on_login( string $login, \WP_User $user ): void {
		$this->touch( (int) $user->ID, true );
	}

	/**
	 * Record activity.
	 *
	 * @param int  $user_id Member.
	 * @param bool $force   Ignore the throttle.
	 */
	public function touch( int $user_id, bool $force = false ): void {
		$row = $this->members->ensure( $user_id );

		if ( ! $force && strtotime( (string) $row->last_active ) > time() - self::INTERVAL ) {
			return;
		}

		$this->members->update( $user_id, array( 'last_active' => current_time( 'mysql', true ) ) );

		/**
		 * Fires when a member's presence is recorded.
		 *
		 * @param int $user_id Member.
		 */
		do_action( 'odsi_social_member_active', $user_id );
	}
}
