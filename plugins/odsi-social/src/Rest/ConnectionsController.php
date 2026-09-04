<?php
/**
 * Connections and follows REST controller.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Rest;

use ODSI\Social\Connections\Connections;
use ODSI\Social\Connections\Follows;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Connection requests and follows.
 */
final class ConnectionsController {

	/**
	 * Constructor.
	 *
	 * @param Connections $connections Connections.
	 * @param Follows     $follows     Follows.
	 */
	public function __construct(
		private Connections $connections,
		private Follows $follows
	) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$ns   = RestServiceProvider::NAMESPACE;
		$in   = array( RestServiceProvider::class, 'logged_in' );
		$user = array( 'user' => RestServiceProvider::int_arg() );

		register_rest_route(
			$ns,
			'/connections',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list' ),
				'permission_callback' => $in,
			)
		);

		register_rest_route(
			$ns,
			'/connections/(?P<user>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'request' ),
					'permission_callback' => $in,
					'args'                => $user,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => $in,
					'args'                => $user,
				),
			)
		);

		register_rest_route(
			$ns,
			'/connections/(?P<user>\d+)/accept',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'accept' ),
				'permission_callback' => $in,
				'args'                => $user,
			)
		);

		register_rest_route(
			$ns,
			'/follows/(?P<user>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'follow' ),
					'permission_callback' => $in,
					'args'                => $user,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unfollow' ),
					'permission_callback' => $in,
					'args'                => $user,
				),
			)
		);
	}

	/**
	 * `GET /connections`
	 */
	public function list(): WP_REST_Response {
		$me = get_current_user_id();

		return new WP_REST_Response(
			array(
				'connections'      => $this->connections->ids_for( $me ),
				'pending_received' => $this->connections->pending_received( $me ),
				'pending_sent'     => $this->connections->pending_sent( $me ),
				'following'        => $this->follows->following( $me ),
				'followers'        => $this->follows->followers( $me ),
			)
		);
	}

	/**
	 * `POST /connections/{user}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->status_response( $this->connections->request( get_current_user_id(), (int) $request['user'] ), (int) $request['user'], 201 );
	}

	/**
	 * `POST /connections/{user}/accept`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function accept( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->status_response( $this->connections->accept( get_current_user_id(), (int) $request['user'] ), (int) $request['user'] );
	}

	/**
	 * `DELETE /connections/{user}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function remove( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->status_response( $this->connections->remove( get_current_user_id(), (int) $request['user'] ), (int) $request['user'] );
	}

	/**
	 * `PUT /follows/{user}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function follow( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->follows->follow( get_current_user_id(), (int) $request['user'] );

		return RestServiceProvider::respond( true === $result ? array( 'following' => true ) : $result );
	}

	/**
	 * `DELETE /follows/{user}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function unfollow( WP_REST_Request $request ): WP_REST_Response {
		$this->follows->unfollow( get_current_user_id(), (int) $request['user'] );

		return new WP_REST_Response( array( 'following' => false ) );
	}

	/**
	 * Respond with the relationship status after an action.
	 *
	 * @param true|WP_Error $result  Service result.
	 * @param int           $user_id Other member.
	 * @param int           $success Status code.
	 */
	private function status_response( bool|WP_Error $result, int $user_id, int $success = 200 ): WP_REST_Response|WP_Error {
		if ( $result instanceof WP_Error ) {
			return RestServiceProvider::respond( $result );
		}

		return new WP_REST_Response( array( 'status' => $this->connections->status( get_current_user_id(), $user_id ) ), $success );
	}
}
