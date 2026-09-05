<?php
/**
 * Activity REST controller.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Rest;

use ODSI\Social\Activity\Activity;
use ODSI\Social\Activity\Feed;
use ODSI\Social\Activity\Reactions;
use ODSI\Social\Frontend\Templates;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Feeds, posting, comments, reactions.
 */
final class ActivityController {

	/**
	 * Constructor.
	 *
	 * @param Activity  $activity  Writer.
	 * @param Feed      $feed      Reader.
	 * @param Reactions $reactions Reactions.
	 * @param Templates $templates Template loader, for server-rendered items.
	 */
	public function __construct(
		private Activity $activity,
		private Feed $feed,
		private Reactions $reactions,
		private Templates $templates
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
			'/activity',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'feed' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'scope'     => array(
							'type'    => 'string',
							'default' => Feed::SCOPE_SITE,
							'enum'    => array( Feed::SCOPE_SITE, Feed::SCOPE_PERSONAL, Feed::SCOPE_GROUP, Feed::SCOPE_PROFILE ),
						),
						'group_id'  => array( 'type' => 'integer' ),
						'user_id'   => array( 'type' => 'integer' ),
						'type'      => array( 'type' => 'string' ),
						'component' => array( 'type' => 'string' ),
						'cursor'    => array(
							'type'    => 'string',
							'default' => '',
						),
						'per_page'  => array( 'type' => 'integer' ),
						'render'    => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post' ),
					'permission_callback' => $in,
					'args'                => array(
						'content'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'privacy'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'group_id' => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'render'   => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/activity/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'item' ),
					'permission_callback' => '__return_true',
					'args'                => $id,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'edit' ),
					'permission_callback' => $in,
					'args'                => $id + array(
						'content' => array( 'type' => 'string' ),
						'privacy' => array( 'type' => 'string' ),
					),
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
			'/activity/(?P<id>\d+)/comments',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'comment' ),
				'permission_callback' => $in,
				'args'                => $id + array(
					'content' => array(
						'type'     => 'string',
						'required' => true,
					),
					'render'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/activity/(?P<id>\d+)/reactions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'reactions' ),
				'permission_callback' => '__return_true',
				'args'                => $id,
			)
		);

		register_rest_route(
			$ns,
			'/activity/(?P<id>\d+)/reaction',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'react' ),
					'permission_callback' => $in,
					'args'                => $id + array(
						'type' => array(
							'type'    => 'string',
							'default' => 'like',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unreact' ),
					'permission_callback' => $in,
					'args'                => $id,
				),
			)
		);
	}

	/**
	 * `GET /activity`
	 *
	 * With `render=1` every item also carries `html`: the same
	 * `parts/activity-item` template the page renders (theme overrides
	 * included), so "Load more" appends items identical to the first page.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function feed( WP_REST_Request $request ): WP_REST_Response {
		$viewer = get_current_user_id();
		$page   = $this->feed->page(
			$viewer,
			(string) $request['scope'],
			array(
				'group_id'  => (int) $request['group_id'],
				'user_id'   => (int) $request['user_id'],
				'type'      => (string) $request['type'],
				'component' => (string) $request['component'],
				'cursor'    => (string) $request['cursor'],
				'per_page'  => (int) $request['per_page'],
			)
		);

		if ( $request['render'] ) {
			foreach ( $page['items'] as $i => $item ) {
				$page['items'][ $i ]['html'] = $this->templates->render(
					'parts/activity-item',
					array(
						'item'      => $item,
						'viewer_id' => $viewer,
					)
				);
			}
		}

		return new WP_REST_Response( $page );
	}

	/**
	 * `POST /activity`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limited = \ODSI\Social\Support\RateLimiter::check( 'activity_post', get_current_user_id() );

		if ( $limited instanceof WP_Error ) {
			return $limited;
		}

		$item = $this->activity->post_update( get_current_user_id(), (string) $request['content'], (string) $request['privacy'], (int) $request['group_id'] );

		if ( $item instanceof WP_Error ) {
			return RestServiceProvider::respond( $item );
		}

		$presented = $this->feed->hydrate( array( $item ), get_current_user_id() )[0];

		// As with the feed, `render=1` adds the item as the page renders it,
		// so the script can show the new post without reloading.
		if ( $request['render'] ) {
			$presented['html'] = $this->templates->render(
				'parts/activity-item',
				array(
					'item'      => $presented,
					'viewer_id' => get_current_user_id(),
				)
			);
		}

		return new WP_REST_Response( $presented, 201 );
	}

	/**
	 * `GET /activity/{id}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$item = $this->feed->item( get_current_user_id(), (int) $request['id'] );

		if ( null === $item ) {
			return new WP_Error( 'odsi_social_not_found', __( 'That activity does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $item );
	}

	/**
	 * `PATCH /activity/{id}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function edit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$result  = null;

		if ( null !== $request['content'] ) {
			$result = $this->activity->edit( $user_id, (int) $request['id'], (string) $request['content'] );

			if ( $result instanceof WP_Error ) {
				return RestServiceProvider::respond( $result );
			}
		}

		if ( null !== $request['privacy'] ) {
			$result = $this->activity->set_privacy( $user_id, (int) $request['id'], (string) $request['privacy'] );

			if ( $result instanceof WP_Error ) {
				return RestServiceProvider::respond( $result );
			}
		}

		if ( null === $result ) {
			return new WP_Error( 'odsi_social_nothing_to_change', __( 'Nothing to change.', 'odsi-social' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( $this->feed->hydrate( array( $result ), $user_id, false )[0] );
	}

	/**
	 * `DELETE /activity/{id}`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->activity->delete( get_current_user_id(), (int) $request['id'] );

		return RestServiceProvider::respond( true === $result ? array( 'deleted' => true ) : $result );
	}

	/**
	 * `POST /activity/{id}/comments`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function comment( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limited = \ODSI\Social\Support\RateLimiter::check( 'activity_comment', get_current_user_id() );

		if ( $limited instanceof WP_Error ) {
			return $limited;
		}

		$comment = $this->activity->comment( get_current_user_id(), (int) $request['id'], (string) $request['content'] );

		if ( $comment instanceof WP_Error ) {
			return RestServiceProvider::respond( $comment );
		}

		$presented = $this->feed->present( $comment, get_current_user_id(), array() );

		if ( $request['render'] ) {
			$presented['html'] = $this->templates->render(
				'parts/activity-comment',
				array(
					'comment'   => $presented,
					'item_id'   => (int) $request['id'],
					'viewer_id' => get_current_user_id(),
				)
			);
		}

		return new WP_REST_Response( $presented, 201 );
	}

	/**
	 * `GET /activity/{id}/reactions` — who reacted (SOC-ACT-037). 404 when not visible.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reactions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$list = $this->feed->reactors( get_current_user_id(), (int) $request['id'], 50 );

		if ( null === $list ) {
			return new WP_Error( 'odsi_social_not_found', __( 'That post does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( array( 'members' => $list ) );
	}

	/**
	 * `PUT /activity/{id}/reaction`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function react( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->reactions->set( get_current_user_id(), (int) $request['id'], (string) $request['type'] );

		return RestServiceProvider::respond( true === $result ? array( 'reacted' => true ) : $result );
	}

	/**
	 * `DELETE /activity/{id}/reaction`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function unreact( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->reactions->remove( get_current_user_id(), (int) $request['id'] );

		return RestServiceProvider::respond( true === $result ? array( 'reacted' => false ) : $result );
	}
}
