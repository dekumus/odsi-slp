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
		wp_register_script( self::SCRIPT, Plugin::url() . 'assets/js/frontend.js', array( 'wp-api-fetch' ), VERSION, true );

		/**
		 * Filters whether community assets load on this request.
		 *
		 * @param bool $load Whether to enqueue.
		 */
		if ( ! apply_filters( 'odsi_social_enqueue_frontend_assets', $this->router->is_community_page() || is_singular( \ODSI\Social\PostTypes\GroupPostType::NAME ) ) ) {
			return;
		}

		wp_enqueue_style( self::STYLE );
		wp_enqueue_script( self::SCRIPT );

		wp_localize_script(
			self::SCRIPT,
			'odsiSocial',
			array(
				'restUrl' => esc_url_raw( rest_url( 'odsi-social/v1' ) ),
				'homeUrl' => esc_url_raw( home_url( '/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'userId'  => get_current_user_id(),
				'i18n'    => array(
					'posting'  => __( 'Posting…', 'odsi-social' ),
					'error'    => __( 'Something went wrong. Please try again.', 'odsi-social' ),
					'confirm'  => __( 'Delete this?', 'odsi-social' ),
					'loadMore' => __( 'Load more', 'odsi-social' ),
				),
			)
		);
	}
}
