<?php
/**
 * Messages REST controller.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Rest;

use ODSI\Social\Messages\Messages;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Inbox, threads, sending.
 */
final class MessagesController {

	/**
	 * Constructor.
	 *
	 * @param Messages $messages Messages.
	 */
	public function __construct( private Messages $messages ) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$ns = RestServiceProvider::NAMESPACE;
		$in = array( RestServiceProvider::class, 'logged_in' );

		register_rest_route(
			$ns,
			'/messages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'inbox' ),
				'permission_callback' => $in,
				'args'                => array(
					'page' => array(
						'type'    => 'integer',
						'default' => 1,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/messages/(?P<thread>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'thread' ),
					'permission_callback' => $in,
					'args'                => array(
						'thread' => RestServiceProvider::int_arg(),
						'before' => array(
							'type'    => 'integer',
							'default' => 0,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reply' ),
					'permission_callback' => $in,
					'args'                => array(
						'thread'  => RestServiceProvider::int_arg(),
						'content' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => $in,
					'args'                => array( 'thread' => RestServiceProvider::int_arg() ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/messages/to/(?P<user>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send' ),
				'permission_callback' => $in,
				'args'                => array(
					'user'    => RestServiceProvider::int_arg(),
					'content' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * `GET /messages`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function inbox( WP_REST_Request $request ): WP_REST_Response {
		$me = get_current_user_id();

		return new WP_REST_Response(
			array(
				'threads'      => $this->messages->inbox( $me, (int) $request['page'] ),
				'unread_total' => $this->messages->unread_total( $me ),
			)
		);
	}

	/**
	 * `GET /messages/{thread}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function thread( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return RestServiceProvider::respond( $this->messages->thread( get_current_user_id(), (int) $request['thread'], (int) $request['before'] ) );
	}

	/**
	 * `POST /messages/{thread}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->messages->reply( get_current_user_id(), (int) $request['thread'], (string) $request['content'] );

		return RestServiceProvider::respond(
			$result instanceof WP_Error ? $result : array(
				'message_id' => (int) $result->id,
				'thread_id'  => (int) $result->thread_id,
			),
			201
		);
	}

	/**
	 * `POST /messages/to/{user}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function send( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limited = \ODSI\Social\Support\RateLimiter::check( 'message_send', get_current_user_id() );

		if ( $limited instanceof WP_Error ) {
			return $limited;
		}

		$result = $this->messages->send( get_current_user_id(), (int) $request['user'], (string) $request['content'] );

		return RestServiceProvider::respond(
			$result instanceof WP_Error ? $result : array(
				'message_id' => (int) $result->id,
				'thread_id'  => (int) $result->thread_id,
			),
			201
		);
	}

	/**
	 * `DELETE /messages/{thread}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->messages->delete( get_current_user_id(), (int) $request['thread'] );

		return RestServiceProvider::respond( true === $result ? array( 'deleted' => true ) : $result );
	}
}
