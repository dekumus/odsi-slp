<?php
/**
 * Course REST controller.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Rest;

use ODSI\LMS\Courses\Access;
use ODSI\LMS\Courses\Enrollment;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only course outline plus self-service enrollment.
 */
final class CourseController {

	/**
	 * Constructor.
	 *
	 * @param Structure  $structure  Course outline resolver.
	 * @param Progress   $progress   Progress service.
	 * @param Enrollment $enrollment Enrollment service.
	 * @param Access     $access     Access rules.
	 */
	public function __construct(
		private Structure $structure,
		private Progress $progress,
		private Enrollment $enrollment,
		private Access $access
	) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/courses/(?P<id>\d+)/outline',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_outline' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/courses/(?P<id>\d+)/enroll',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'enroll' ),
				'permission_callback' => static fn (): bool => is_user_logged_in(),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * `GET /courses/<id>/outline`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_outline( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request['id'];
		$user_id   = get_current_user_id();
		$completed = $this->progress->repository()->completed_ids( $user_id, $course_id );

		$steps = array_map(
			fn ( array $step ): array => array(
				'id'        => $step['id'],
				'type'      => $step['type'],
				'parent'    => $step['parent'],
				'depth'     => $step['depth'],
				'title'     => html_entity_decode( (string) get_the_title( $step['id'] ), ENT_QUOTES, 'UTF-8' ),
				'permalink' => (string) get_permalink( $step['id'] ),
				'completed' => in_array( $step['id'], $completed, true ),
				'locked'    => ! $this->access->can_access_step( $user_id, $step['id'] ),
			),
			$this->structure->outline( $course_id )
		);

		return new WP_REST_Response(
			array(
				'course_id'  => $course_id,
				'steps'      => $steps,
				'enrolled'   => $user_id > 0 && $this->enrollment->is_enrolled( $user_id, $course_id ),
				'percentage' => $user_id > 0 ? $this->progress->course_percentage( $user_id, $course_id ) : 0.0,
			)
		);
	}

	/**
	 * `POST /courses/<id>/enroll`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function enroll( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$course_id = (int) $request['id'];
		$user_id   = get_current_user_id();

		$mode = (string) get_post_meta( $course_id, \ODSI\LMS\Support\Meta::ACCESS_MODE, true );

		// Only self-serve modes are open to this route; paid enrollment has to go
		// through the commerce integration so nobody can enroll for free.
		if ( ! in_array( $mode, array( 'free', 'open' ), true ) ) {
			return new WP_Error(
				'odsi_lms_enrollment_not_self_serve',
				__( 'This course cannot be joined directly.', 'odsi-lms' ),
				array( 'status' => 403 )
			);
		}

		$enrollment_id = $this->enrollment->enroll( $user_id, $course_id, array( 'source' => 'self' ) );

		if ( $enrollment_id <= 0 ) {
			return new WP_Error(
				'odsi_lms_enrollment_failed',
				__( 'You could not be enrolled on this course.', 'odsi-lms' ),
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response(
			array(
				'enrolled'      => true,
				'enrollment_id' => $enrollment_id,
			),
			201
		);
	}
}
