<?php
/**
 * Reports REST controller.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Rest;

use ODSI\Social\Moderation\Reports;
use ODSI\Social\Repositories\ReportRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Filing reports (members) and resolving them (admins).
 */
final class ReportsController {

	/**
	 * Constructor.
	 *
	 * @param Reports $reports Reports.
	 */
	public function __construct( private Reports $reports ) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$ns    = RestServiceProvider::NAMESPACE;
		$in    = array( RestServiceProvider::class, 'logged_in' );
		$admin = fn (): bool => $this->reports->can_moderate( get_current_user_id() );
		$id    = array( 'id' => RestServiceProvider::int_arg() );

		register_rest_route(
			$ns,
			'/reports',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $in,
					'args'                => array(
						'object_type' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => Reports::TYPES,
						),
						'object_id'   => RestServiceProvider::int_arg(),
						'reason'      => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => Reports::REASONS,
						),
						'details'     => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list' ),
					'permission_callback' => $admin,
					'args'                => array(
						'status'   => array(
							'type'    => 'string',
							'default' => ReportRepository::STATUS_OPEN,
							'enum'    => array( ReportRepository::STATUS_OPEN, ReportRepository::STATUS_DISMISSED, ReportRepository::STATUS_ACTIONED ),
						),
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
			)
		);

		register_rest_route(
			$ns,
			'/reports/(?P<id>\d+)/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss' ),
				'permission_callback' => $admin,
				'args'                => $id,
			)
		);

		register_rest_route(
			$ns,
			'/reports/(?P<id>\d+)/action',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'action' ),
				'permission_callback' => $admin,
				'args'                => $id + array(
					'action' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => Reports::ACTIONS,
					),
				),
			)
		);
	}

	/**
	 * `POST /reports`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->reports->report(
			get_current_user_id(),
			(string) $request['object_type'],
			(int) $request['object_id'],
			(string) $request['reason'],
			(string) $request['details']
		);

		if ( $result instanceof WP_Error ) {
			return RestServiceProvider::respond( $result );
		}

		return new WP_REST_Response( array( 'id' => $result ), 201 );
	}

	/**
	 * `GET /reports?status=open`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$status   = (string) $request['status'];
		$page     = max( 1, (int) $request['page'] );
		$per_page = max( 1, min( 100, (int) $request['per_page'] ) );
		$reports  = $this->reports->list( get_current_user_id(), $status, $page, $per_page );

		if ( $reports instanceof WP_Error ) {
			return RestServiceProvider::respond( $reports );
		}

		return new WP_REST_Response(
			array(
				'reports'  => $reports,
				'total'    => $this->reports->count( $status ),
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * `POST /reports/{id}/dismiss`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function dismiss( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->reports->dismiss( get_current_user_id(), (int) $request['id'] );

		return RestServiceProvider::respond( true === $result ? array( 'status' => ReportRepository::STATUS_DISMISSED ) : $result );
	}

	/**
	 * `POST /reports/{id}/action`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->reports->action( get_current_user_id(), (int) $request['id'], (string) $request['action'] );

		return RestServiceProvider::respond(
			true === $result ? array(
				'status'     => ReportRepository::STATUS_ACTIONED,
				'resolution' => (string) $request['action'],
			) : $result
		);
	}
}
