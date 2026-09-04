<?php
/**
 * Groups REST controller.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Rest;

use ODSI\Social\Groups\Groups;
use ODSI\Social\Groups\Membership;
use ODSI\Social\Members\Uploads;
use ODSI\Social\Repositories\GroupMemberRepository;
use ODSI\Social\Repositories\GroupRepository;
use ODSI\Social\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Directory, single group, settings and membership actions.
 */
final class GroupsController {

	/**
	 * Constructor.
	 *
	 * @param Groups                $groups     Groups.
	 * @param Membership            $membership Membership.
	 * @param GroupMemberRepository $members    Membership rows.
	 * @param GroupRepository       $index      Index rows.
	 * @param Uploads               $uploads    Image uploads.
	 */
	public function __construct(
		private Groups $groups,
		private Membership $membership,
		private GroupMemberRepository $members,
		private GroupRepository $index,
		private Uploads $uploads
	) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$ns = RestServiceProvider::NAMESPACE;
		$in = array( RestServiceProvider::class, 'logged_in' );
		$id = array( 'id' => RestServiceProvider::int_arg() );

		register_rest_route(
			$ns,
			'/groups',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'directory' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'search'   => array( 'type' => 'string' ),
						'orderby'  => array( 'type' => 'string' ),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $in,
					'args'                => array(
						'name'        => array(
							'type'     => 'string',
							'required' => true,
						),
						'description' => array(
							'type'    => 'string',
							'default' => '',
						),
						'visibility'  => array(
							'type'    => 'string',
							'default' => 'public',
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/groups/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'single' ),
					'permission_callback' => '__return_true',
					'args'                => $id,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $in,
					'args'                => $id,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => $in,
					'args'                => $id,
				),
			)
		);

		register_rest_route(
			$ns,
			'/groups/(?P<id>\d+)/(?P<kind>avatar|cover)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_image' ),
					'permission_callback' => $in,
					'args'                => $id,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_image' ),
					'permission_callback' => $in,
					'args'                => $id,
				),
			)
		);

		register_rest_route(
			$ns,
			'/groups/(?P<id>\d+)/members',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_members' ),
				'permission_callback' => '__return_true',
				'args'                => $id + array(
					'status' => array(
						'type'    => 'string',
						'default' => 'active',
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
			'/groups/(?P<id>\d+)/membership',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'join_or_request' ),
					'permission_callback' => $in,
					'args'                => $id,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'leave' ),
					'permission_callback' => $in,
					'args'                => $id,
				),
			)
		);

		register_rest_route(
			$ns,
			'/groups/(?P<id>\d+)/members/(?P<user>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'manage_member' ),
				'permission_callback' => $in,
				'args'                => $id + array(
					'user'   => RestServiceProvider::int_arg(),
					'action' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'invite', 'approve', 'reject', 'remove', 'ban', 'unban', 'promote', 'demote' ),
					),
					'role'   => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * `GET /groups`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function directory( WP_REST_Request $request ): WP_REST_Response {
		$viewer = get_current_user_id();
		$args   = array(
			'search'       => (string) $request['search'],
			'orderby'      => (string) $request['orderby'],
			'page'         => (int) $request['page'],
			'per_page'     => (int) $request['per_page'],
			'visibilities' => array( 'public', 'private' ),
			'include'      => $viewer > 0 ? $this->membership->groups_of( $viewer ) : array(),
		);

		if ( Capabilities::is_admin( $viewer ) ) {
			$args['visibilities'][] = 'hidden';
		}

		$result = $this->index->directory( $args );

		return new WP_REST_Response(
			array(
				'groups'   => array_values( array_filter( array_map( fn ( int $id ): ?array => $this->groups->present( $viewer, $id ), $result['ids'] ) ) ),
				'total'    => $result['total'],
				'page'     => (int) $request['page'],
				'per_page' => (int) $request['per_page'],
			)
		);
	}

	/**
	 * `POST /groups`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->groups->create(
			get_current_user_id(),
			array(
				'name'        => (string) $request['name'],
				'description' => (string) $request['description'],
				'visibility'  => (string) $request['visibility'],
			)
		);

		if ( $result instanceof WP_Error ) {
			return RestServiceProvider::respond( $result );
		}

		return new WP_REST_Response( $this->groups->present( get_current_user_id(), $result ), 201 );
	}

	/**
	 * `GET /groups/{id}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function single( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$group = $this->groups->present( get_current_user_id(), (int) $request['id'] );

		if ( null === $group ) {
			return new WP_Error( 'odsi_social_group_not_found', __( 'That group does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $group );
	}

	/**
	 * `PATCH /groups/{id}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$args = array_filter(
			array(
				'name'        => $request['name'],
				'description' => $request['description'],
				'visibility'  => $request['visibility'],
				'avatar_id'   => $request['avatar_id'],
				'cover_id'    => $request['cover_id'],
			),
			static fn ( $v ): bool => null !== $v
		);

		$result = $this->groups->update( get_current_user_id(), (int) $request['id'], $args );

		if ( $result instanceof WP_Error ) {
			return RestServiceProvider::respond( $result );
		}

		return new WP_REST_Response( $this->groups->present( get_current_user_id(), (int) $request['id'] ) );
	}

	/**
	 * `POST /groups/{id}/{avatar|cover}` — multipart `file`, organisers only.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function upload_image( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$actor    = get_current_user_id();
		$group_id = (int) $request['id'];
		$kind     = (string) $request['kind'];

		if ( ! $this->groups->exists( $group_id ) || ! $this->groups->can_view( $actor, $group_id ) ) {
			return new WP_Error( 'odsi_social_group_not_found', __( 'That group does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
		}

		if ( ! $this->groups->is_organiser( $actor, $group_id ) ) {
			return new WP_Error( 'odsi_social_forbidden', __( 'Only organisers can change group settings.', 'odsi-social' ), array( 'status' => 403 ) );
		}

		$files  = $request->get_file_params();
		$stored = $this->uploads->store( $actor, isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : array(), 'group_' . $kind, $group_id );

		if ( $stored instanceof WP_Error ) {
			return $stored;
		}

		$result = $this->groups->update( $actor, $group_id, array( $kind . '_id' => $stored ) );

		if ( $result instanceof WP_Error ) {
			return RestServiceProvider::respond( $result );
		}

		return new WP_REST_Response( $this->groups->present( $actor, $group_id ), 201 );
	}

	/**
	 * `DELETE /groups/{id}/{avatar|cover}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function remove_image( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$actor  = get_current_user_id();
		$result = $this->groups->update( $actor, (int) $request['id'], array( (string) $request['kind'] . '_id' => 0 ) );

		if ( $result instanceof WP_Error ) {
			return RestServiceProvider::respond( $result );
		}

		return new WP_REST_Response( $this->groups->present( $actor, (int) $request['id'] ) );
	}

	/**
	 * `DELETE /groups/{id}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->groups->delete( get_current_user_id(), (int) $request['id'] );

		return RestServiceProvider::respond( true === $result ? array( 'deleted' => true ) : $result );
	}

	/**
	 * `GET /groups/{id}/members`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_members( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$viewer   = get_current_user_id();
		$group_id = (int) $request['id'];
		$status   = (string) $request['status'];

		if ( ! $this->groups->can_view( $viewer, $group_id ) ) {
			return new WP_Error( 'odsi_social_group_not_found', __( 'That group does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
		}

		if ( ! $this->groups->can_view_content( $viewer, $group_id ) ) {
			return new WP_Error( 'odsi_social_forbidden', __( 'Join this group to see its members.', 'odsi-social' ), array( 'status' => 403 ) );
		}

		if ( 'active' !== $status && ! $this->groups->is_moderator( $viewer, $group_id ) ) {
			return new WP_Error( 'odsi_social_forbidden', __( 'Only moderators can see that list.', 'odsi-social' ), array( 'status' => 403 ) );
		}

		$per_page = 50;
		$rows     = $this->members->for_group( $group_id, $status, null, $per_page, ( max( 1, (int) $request['page'] ) - 1 ) * $per_page );

		cache_users( array_map( static fn ( object $r ): int => (int) $r->user_id, $rows ) );

		return new WP_REST_Response(
			array(
				'members' => array_map(
					static function ( object $r ): array {
						$user = get_userdata( (int) $r->user_id );

						return array(
							'id'     => (int) $r->user_id,
							'name'   => $user ? $user->display_name : __( 'A former member', 'odsi-social' ),
							'avatar' => $user ? get_avatar_url( (int) $r->user_id, array( 'size' => 64 ) ) : '',
							'role'   => (string) $r->role,
							'status' => (string) $r->status,
							'since'  => (string) $r->created_at,
						);
					},
					$rows
				),
				'total'   => $this->members->count( $group_id, $status ),
			)
		);
	}

	/**
	 * `POST /groups/{id}/membership` — join or request per visibility.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function join_or_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$viewer   = get_current_user_id();
		$group_id = (int) $request['id'];
		$result   = 'public' === $this->groups->visibility( $group_id ) ? $this->membership->join( $viewer, $group_id ) : $this->membership->request( $viewer, $group_id );

		if ( $result instanceof WP_Error ) {
			return RestServiceProvider::respond( $result );
		}

		return new WP_REST_Response( $this->groups->present( $viewer, $group_id ) );
	}

	/**
	 * `DELETE /groups/{id}/membership` — leave or withdraw.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function leave( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$viewer = get_current_user_id();
		$result = $this->membership->remove( $viewer, (int) $request['id'], $viewer );

		if ( $result instanceof WP_Error ) {
			return RestServiceProvider::respond( $result );
		}

		return new WP_REST_Response( $this->groups->present( $viewer, (int) $request['id'] ) ?? array( 'left' => true ) );
	}

	/**
	 * `POST /groups/{id}/members/{user}` with `action`.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function manage_member( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$actor    = get_current_user_id();
		$group_id = (int) $request['id'];
		$user_id  = (int) $request['user'];

		$result = match ( (string) $request['action'] ) {
			'invite'  => $this->membership->invite( $actor, $group_id, $user_id ),
			'approve' => $this->membership->approve( $actor, $group_id, $user_id ),
			'reject', 'remove' => $this->membership->remove( $actor, $group_id, $user_id ),
			'ban'     => $this->membership->ban( $actor, $group_id, $user_id ),
			'unban'   => $this->membership->unban( $actor, $group_id, $user_id ),
			'promote' => $this->membership->set_role( $actor, $group_id, $user_id, (string) ( $request['role'] ?: GroupMemberRepository::ROLE_MODERATOR ) ),
			'demote'  => $this->membership->set_role( $actor, $group_id, $user_id, (string) ( $request['role'] ?: GroupMemberRepository::ROLE_MEMBER ) ),
			default   => new WP_Error( 'odsi_social_invalid_action', __( 'Unknown action.', 'odsi-social' ), array( 'status' => 400 ) ),
		};

		if ( $result instanceof WP_Error ) {
			return RestServiceProvider::respond( $result );
		}

		$row = $this->members->find_for( $group_id, $user_id );

		return new WP_REST_Response(
			array(
				'user_id' => $user_id,
				'role'    => $row ? (string) $row->role : '',
				'status'  => $row ? (string) $row->status : '',
			)
		);
	}
}
