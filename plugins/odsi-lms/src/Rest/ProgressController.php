<?php
/**
 * Progress REST controller.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Rest;

use ODSI\LMS\Courses\Access;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the course player record step completion.
 */
final class ProgressController {

	/**
	 * Constructor.
	 *
	 * @param Progress  $progress  Progress service.
	 * @param Access    $access    Access rules.
	 * @param Structure $structure Outline, for the step that follows.
	 */
	public function __construct(
		private Progress $progress,
		private Access $access,
		private Structure $structure
	) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/steps/(?P<id>\d+)/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete' ),
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
	 * `POST /steps/<id>/complete`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function complete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$object_id = (int) $request['id'];
		$user_id   = get_current_user_id();
		$type      = (string) get_post_type( $object_id );

		if ( ! in_array( $type, \ODSI\LMS\PostTypes\PostTypes::trackable(), true ) ) {
			return new WP_Error( 'odsi_lms_step_not_found', __( 'That step does not exist.', 'odsi-lms' ), array( 'status' => 404 ) );
		}

		// A learner must be able to open a step before they can complete it,
		// otherwise linear progression could be skipped by calling the API.
		if ( ! $this->access->can_access_step( $user_id, $object_id ) ) {
			return new WP_Error(
				'odsi_lms_step_locked',
				__( 'This step is not available to you yet.', 'odsi-lms' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->progress->complete_step( $user_id, $object_id ) ) {
			return new WP_Error(
				'odsi_lms_completion_failed',
				__( 'That step cannot be marked complete directly.', 'odsi-lms' ),
				array( 'status' => 400 )
			);
		}

		$course_id = (int) get_post_meta( $object_id, \ODSI\LMS\Support\Meta::COURSE_ID, true );
		$next      = $this->structure->next_step( $course_id, $object_id );
		$next_open = $next && $this->access->can_access_step( $user_id, (int) $next['id'] );

		// Enough for the page to repaint itself without a reload: the bar, its
		// label, the outline and the "next" link that may have just unlocked.
		return new WP_REST_Response(
			array(
				'completed'       => true,
				'course_id'       => $course_id,
				'percentage'      => $this->progress->course_percentage( $user_id, $course_id ),
				'completed_count' => $this->progress->completed_count( $user_id, $course_id ),
				'total'           => $this->structure->total_steps( $course_id ),
				'course_complete' => $this->progress->has_completed_course( $user_id, $course_id ),
				'course_url'      => (string) get_permalink( $course_id ),
				'next_id'         => $next_open ? (int) $next['id'] : 0,
				'next_url'        => $next_open ? (string) get_permalink( (int) $next['id'] ) : '',
			)
		);
	}
}
