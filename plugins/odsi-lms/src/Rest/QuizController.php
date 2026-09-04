<?php
/**
 * Quiz REST controller.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Rest;

use ODSI\LMS\Courses\Access;
use ODSI\LMS\Quizzes\QuizService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Starts and submits quiz attempts.
 */
final class QuizController {

	/**
	 * Constructor.
	 *
	 * @param QuizService $quizzes Quiz lifecycle service.
	 * @param Access      $access  Access rules.
	 */
	public function __construct(
		private QuizService $quizzes,
		private Access $access
	) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/quizzes/(?P<id>\d+)/attempts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start' ),
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

		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/attempts/(?P<id>\d+)/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => static fn (): bool => is_user_logged_in(),
				'args'                => array(
					'id'      => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'answers' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * `POST /quizzes/<id>/attempts`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$quiz_id = (int) $request['id'];
		$user_id = get_current_user_id();

		if ( ! $this->access->can_access_step( $user_id, $quiz_id ) ) {
			return new WP_Error(
				'odsi_lms_quiz_locked',
				__( 'This quiz is not available to you yet.', 'odsi-lms' ),
				array( 'status' => 403 )
			);
		}

		$attempt_id = $this->quizzes->start( $user_id, $quiz_id );

		if ( $attempt_id instanceof WP_Error ) {
			$attempt_id->add_data( array( 'status' => 400 ) );

			return $attempt_id;
		}

		return new WP_REST_Response( array( 'attempt_id' => $attempt_id ), 201 );
	}

	/**
	 * `POST /attempts/<id>/submit`
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$attempt_id = (int) $request['id'];
		$attempt    = $this->quizzes->repository()->find( $attempt_id );

		if ( ! $attempt || (int) $attempt->user_id !== get_current_user_id() ) {
			return new WP_Error(
				'odsi_lms_attempt_not_found',
				__( 'That quiz attempt does not belong to you.', 'odsi-lms' ),
				array( 'status' => 404 )
			);
		}

		$answers = array();

		foreach ( (array) $request['answers'] as $question_id => $answer ) {
			$answers[ (int) $question_id ] = $answer;
		}

		$result = $this->quizzes->submit( $attempt_id, $answers );

		if ( $result instanceof WP_Error ) {
			$result->add_data( array( 'status' => 400 ) );

			return $result;
		}

		return new WP_REST_Response( $result );
	}
}
