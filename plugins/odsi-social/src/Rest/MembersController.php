<?php
/**
 * Members REST controller.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Rest;

use ODSI\Social\Members\Directory;
use ODSI\Social\Members\Profiles;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Directory and profiles.
 */
final class MembersController {

	/**
	 * Constructor.
	 *
	 * @param Directory $directory Directory.
	 * @param Profiles  $profiles  Profiles.
	 */
	public function __construct(
		private Directory $directory,
		private Profiles $profiles
	) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$ns = RestServiceProvider::NAMESPACE;

		register_rest_route(
			$ns,
			'/members',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'directory' ),
				'permission_callback' => fn (): bool => $this->directory->can_view( get_current_user_id() ),
				'args'                => array(
					'search'   => array( 'type' => 'string' ),
					'orderby'  => array( 'type' => 'string' ),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page' => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/members/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'profile' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'id' => RestServiceProvider::int_arg() ),
			)
		);

		register_rest_route(
			$ns,
			'/members/me',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_me' ),
				'permission_callback' => array( RestServiceProvider::class, 'logged_in' ),
			)
		);
	}

	/**
	 * `GET /members`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function directory( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			$this->directory->query(
				get_current_user_id(),
				array_filter(
					array(
						'search'   => $request['search'],
						'orderby'  => $request['orderby'],
						'page'     => $request['page'],
						'per_page' => $request['per_page'],
					),
					static fn ( $v ): bool => null !== $v && '' !== $v
				)
			)
		);
	}

	/**
	 * `GET /members/{id}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$profile = $this->profiles->view( get_current_user_id(), (int) $request['id'] );

		if ( ! $profile ) {
			return new WP_Error( 'odsi_social_member_not_found', __( 'That member does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $profile );
	}

	/**
	 * `PATCH /members/me`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_me( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();

		if ( is_array( $request['fields'] ) ) {
			$result = $this->profiles->update_fields( $user_id, $request['fields'] );

			if ( $result instanceof WP_Error ) {
				return RestServiceProvider::respond( $result );
			}
		}

		if ( null !== $request['avatar_id'] ) {
			$this->profiles->set_avatar( $user_id, (int) $request['avatar_id'] );
		}

		if ( null !== $request['cover_id'] ) {
			$this->profiles->set_cover( $user_id, (int) $request['cover_id'] );
		}

		if ( null !== $request['message_setting'] ) {
			$this->profiles->set_message_setting( $user_id, (string) $request['message_setting'] );
		}

		return new WP_REST_Response( $this->profiles->view( $user_id, $user_id ) );
	}
}
