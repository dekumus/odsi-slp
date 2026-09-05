<?php
/**
 * Asset registration.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Support;

use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Frontend\Router;
use ODSI\Social\Plugin;
use const ODSI\Social\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end scripts and styles, enqueued only on community pages.
 */
final class Assets implements Bootable {

	public const STYLE  = 'odsi-social';
	public const SCRIPT = 'odsi-social';

	/**
	 * Constructor.
	 *
	 * @param Router $router Router, to know when a community page is rendering.
	 */
	public function __construct( private Router $router ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register' ) );
	}

	/**
	 * Register and conditionally enqueue.
	 */
	public function register(): void {
		wp_register_style( self::STYLE, Plugin::url() . 'assets/css/frontend.css', array(), VERSION );
		wp_register_script( self::SCRIPT, Plugin::url() . 'assets/js/frontend.js', array(), VERSION, true );

		/**
		 * Filters whether community assets load on this request.
		 *
		 * @param bool $load Whether to enqueue.
		 */
		if ( ! apply_filters( 'odsi_social_enqueue_frontend_assets', ( $this->router->is_community_page() && ! is_404() ) || is_singular( \ODSI\Social\PostTypes\GroupPostType::NAME ) ) ) {
			return;
		}

		wp_enqueue_style( self::STYLE );
		wp_enqueue_script( self::SCRIPT );

		wp_localize_script( self::SCRIPT, 'odsiSocial', self::script_data() );
	}

	/**
	 * Everything the script needs: endpoints, the nonce, and every string it
	 * can show, so nothing visible is hard-coded in JavaScript.
	 *
	 * @return array<string, mixed>
	 */
	public static function script_data(): array {
		return array(
			'restUrl' => esc_url_raw( rest_url( 'odsi-social/v1' ) ),
			'homeUrl' => esc_url_raw( home_url( '/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'userId'  => get_current_user_id(),
			'i18n'    => array(
				'error'            => __( 'Something went wrong. Please try again.', 'odsi-social' ),
				'sessionExpired'   => __( 'Your session has expired. Reload the page and try again.', 'odsi-social' ),
				'confirmDelete'    => __( 'Delete this? This cannot be undone.', 'odsi-social' ),
				'confirmBlock'     => __( 'Block this member? You will no longer see each other anywhere on the site.', 'odsi-social' ),
				'deleted'          => __( 'Deleted.', 'odsi-social' ),
				'posted'           => __( 'Your update has been posted.', 'odsi-social' ),
				'commented'        => __( 'Your comment has been posted.', 'odsi-social' ),
				'reported'         => __( 'Thank you. A moderator will review your report.', 'odsi-social' ),
				'loading'          => __( 'Loading…', 'odsi-social' ),
				'loaded'           => __( 'More updates loaded.', 'odsi-social' ),
				'connect'          => __( 'Connect', 'odsi-social' ),
				'withdraw'         => __( 'Withdraw request', 'odsi-social' ),
				'accept'           => __( 'Accept request', 'odsi-social' ),
				'removeConnection' => __( 'Remove connection', 'odsi-social' ),
				'follow'           => __( 'Follow', 'odsi-social' ),
				'unfollow'         => __( 'Unfollow', 'odsi-social' ),
				/* translators: %d: number of unread notifications. */
				'unread'           => __( '%d unread', 'odsi-social' ),
				/* translators: %d: characters remaining. */
				'characterLeft'    => __( '%d character left', 'odsi-social' ),
				/* translators: %d: characters remaining. */
				'charactersLeft'   => __( '%d characters left', 'odsi-social' ),
			),
		);
	}
}
