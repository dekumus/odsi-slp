<?php
/**
 * Assignment submissions REST controller.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Rest;

use ODSI\LMS\Assignments\Assignments;
use ODSI\LMS\Reports\EnrollmentReport;
use ODSI\LMS\Repositories\SubmissionRepository;
use ODSI\LMS\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Learners hand in and read their own submissions; graders list and grade.
 */
final class SubmissionController {

	/**
	 * Constructor.
	 *
	 * @param Assignments      $assignments Service.
	 * @param EnrollmentReport $report      Course scoping for graders.
	 */
	public function __construct(
		private Assignments $assignments,
		private EnrollmentReport $report
	) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$id = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/steps/(?P<id>\d+)/submissions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'mine' ),
					'permission_callback' => static fn (): bool => is_user_logged_in(),
					'args'                => $id,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit' ),
					'permission_callback' => static fn (): bool => is_user_logged_in(),
					'args'                => $id + array(
						'content' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);

		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/submissions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'queue' ),
				'permission_callback' => static fn (): bool => current_user_can( Capabilities::REPORT ),
				'args'                => array(
					'status'   => array(
						'type'    => 'string',
						'default' => SubmissionRepository::STATUS_PENDING,
						'enum'    => array( SubmissionRepository::STATUS_PENDING, SubmissionRepository::STATUS_APPROVED, SubmissionRepository::STATUS_REJECTED ),
					),
					'course'   => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/submissions/(?P<id>\d+)/grade',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'grade' ),
				'permission_callback' => static fn (): bool => current_user_can( Capabilities::REPORT ),
				'args'                => $id + array(
					'status'   => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( SubmissionRepository::STATUS_APPROVED, SubmissionRepository::STATUS_REJECTED ),
					),
					'points'   => array(
						'type'    => 'number',
						'default' => 0,
					),
					'feedback' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * `GET /steps/{id}/submissions` — the caller's history for a step.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function mine( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$step_id = (int) $request['id'];

		if ( ! $this->assignments->requires_assignment( $step_id ) ) {
			return new WP_Error( 'odsi_lms_no_assignment', __( 'This step has no assignment.', 'odsi-lms' ), array( 'status' => 404 ) );
		}

		$user_id = get_current_user_id();
		$rows    = $this->assignments->repository()->history( $user_id, $step_id );

		return new WP_REST_Response(
			array(
				'step_id'         => $step_id,
				'points_possible' => $this->assignments->points( $step_id ),
				'approved'        => $this->assignments->repository()->has_approved( $user_id, $step_id ),
				'submissions'     => array_map( array( $this->assignments, 'present' ), $rows ),
			)
		);
	}

	/**
	 * `POST /steps/{id}/submissions` — hand in text and/or a `file` field.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : array();
		$id    = $this->assignments->submit( get_current_user_id(), (int) $request['id'], (string) $request['content'], $file );

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$row = $this->assignments->repository()->find( $id );

		return new WP_REST_Response( $row ? $this->assignments->present( $row ) : array( 'id' => $id ), 201 );
	}

	/**
	 * `GET /submissions` — grading queue scoped to the caller's courses.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function queue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$course  = (int) $request['course'];
		$courses = user_can( $user_id, Capabilities::MANAGE ) ? array() : $this->report->reportable_courses( $user_id );

		if ( $course > 0 ) {
			if ( ! $this->report->can_report( $user_id, $course ) ) {
				return new WP_Error( 'odsi_lms_forbidden', __( 'You cannot grade this course.', 'odsi-lms' ), array( 'status' => 403 ) );
			}

			$courses = array( $course );
		} elseif ( ! user_can( $user_id, Capabilities::MANAGE ) && array() === $courses ) {
			return new WP_REST_Response(
				array(
					'total' => 0,
					'rows'  => array(),
				)
			);
		}

		$per_page = (int) $request['per_page'];
		$queue    = $this->assignments->repository()->queue( (string) $request['status'], $courses, $per_page, $per_page * ( (int) $request['page'] - 1 ) );

		return new WP_REST_Response(
			array(
				'total' => $queue['total'],
				'rows'  => array_map( array( $this->assignments, 'present' ), $queue['rows'] ),
			)
		);
	}

	/**
	 * `POST /submissions/{id}/grade`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function grade( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id  = (int) $request['id'];
		$row = $this->assignments->repository()->find( $id );

		if ( ! $row ) {
			return new WP_Error( 'odsi_lms_submission_not_found', __( 'That submission does not exist.', 'odsi-lms' ), array( 'status' => 404 ) );
		}

		$grader = get_current_user_id();

		if ( ! $this->assignments->can_grade( $grader, $row ) ) {
			return new WP_Error( 'odsi_lms_forbidden', __( 'You cannot grade this submission.', 'odsi-lms' ), array( 'status' => 403 ) );
		}

		$ok = SubmissionRepository::STATUS_APPROVED === (string) $request['status']
			? $this->assignments->approve( $id, (float) $request['points'], (string) $request['feedback'], $grader )
			: $this->assignments->reject( $id, (string) $request['feedback'], $grader );

		if ( ! $ok ) {
			return new WP_Error( 'odsi_lms_grading_failed', __( 'The grade could not be saved.', 'odsi-lms' ), array( 'status' => 500 ) );
		}

		$row = $this->assignments->repository()->find( $id );

		return new WP_REST_Response( $row ? $this->assignments->present( $row ) : array( 'id' => $id ) );
	}
}
