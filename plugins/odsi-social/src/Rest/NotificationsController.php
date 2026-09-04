<?php
/**
 * Notifications REST controller.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Rest;

use ODSI\Social\Notifications\Notifications;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Own notifications only.
 */
final class NotificationsController {

	/**
	 * Constructor.
	 *
	 * @param Notifications $notifications Notifications.
	 */
	public function __construct( private Notifications $notifications ) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$ns = RestServiceProvider::NAMESPACE;
		$in = array( RestServiceProvider::class, 'logged_in' );

		register_rest_route(
			$ns,
			'/notifications',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list' ),
				'permission_callback' => $in,
				'args'                => array(
					'unread' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'page'   => array(
						'type'    => 'integer',
						'default' => 1,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/notifications/read',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'read_all' ),
				'permission_callback' => $in,
			)
		);

		register_rest_route(
			$ns,
			'/notifications/(?P<id>\d+)/read',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'read_one' ),
				'permission_callback' => $in,
				'args'                => array( 'id' => RestServiceProvider::int_arg() ),
			)
		);
	}

	/**
	 * `GET /notifications`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list( WP_REST_Request $request ): WP_REST_Response {
		$me = get_current_user_id();

		return new WP_REST_Response(
			array(
				'notifications' => $this->notifications->list( $me, (bool) $request['unread'], (int) $request['page'] ),
				'unread_count'  => $this->notifications->unread_count( $me ),
			)
		);
	}

	/**
	 * `POST /notifications/read`
	 */
	public function read_all(): WP_REST_Response {
		$me = get_current_user_id();
		$this->notifications->mark_read( $me );

		return new WP_REST_Response( array( 'unread_count' => $this->notifications->unread_count( $me ) ) );
	}

	/**
	 * `POST /notifications/{id}/read`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function read_one( WP_REST_Request $request ): WP_REST_Response {
		$me = get_current_user_id();
		$this->notifications->mark_read( $me, array( (int) $request['id'] ) );

		return new WP_REST_Response( array( 'unread_count' => $this->notifications->unread_count( $me ) ) );
	}
}
