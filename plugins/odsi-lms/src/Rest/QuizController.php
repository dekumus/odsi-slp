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
			'/quizzes/(?P<id>\d+)/questions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'questions' ),
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

		// Test-only: seed a question's answer key, which is deliberately not
		// writable over the public API. Never define ODSI_E2E on a real site.
		if ( defined( 'ODSI_E2E' ) && ODSI_E2E ) {
			register_rest_route(
				RestServiceProvider::NAMESPACE,
				'/e2e/questions/(?P<id>\d+)/answers',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
						update_post_meta( (int) $request['id'], \ODSI\LMS\Support\Meta::QUESTION_ANSWERS, (array) $request['answers'] );

						return new WP_REST_Response( array( 'ok' => true ) );
					},
					'permission_callback' => static fn (): bool => current_user_can( \ODSI\LMS\Support\Capabilities::MANAGE ),
				)
			);
		}

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

		$resumed    = $this->quizzes->has_open_attempt( $user_id, $quiz_id );
		$attempt_id = $this->quizzes->start( $user_id, $quiz_id );

		if ( $attempt_id instanceof WP_Error ) {
			$attempt_id->add_data( array( 'status' => 400 ) );

			return $attempt_id;
		}

		return new WP_REST_Response(
			array(
				'attempt_id' => $attempt_id,
				'resumed'    => $resumed,
				'time_limit' => (int) get_post_meta( $quiz_id, \ODSI\LMS\Support\Meta::TIME_LIMIT, true ),
			),
			201
		);
	}

	/**
	 * `GET /quizzes/<id>/questions` — for rendering; never includes the key.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function questions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$quiz_id = (int) $request['id'];
		$user_id = get_current_user_id();

		if ( ! $this->access->can_access_step( $user_id, $quiz_id ) ) {
			return new WP_Error(
				'odsi_lms_quiz_locked',
				__( 'This quiz is not available to you yet.', 'odsi-lms' ),
				array( 'status' => 403 )
			);
		}

		$questions = array();

		foreach ( $this->quizzes->questions( $quiz_id ) as $question_id ) {
			$type    = (string) get_post_meta( $question_id, \ODSI\LMS\Support\Meta::QUESTION_TYPE, true ) ?: 'single';
			$answers = (array) get_post_meta( $question_id, \ODSI\LMS\Support\Meta::QUESTION_ANSWERS, true );
			$options = array();

			if ( ! in_array( $type, array( 'fill_blank', 'essay' ), true ) ) {
				foreach ( array_values( $answers ) as $index => $answer ) {
					$options[] = array(
						'index' => $index,
						'text'  => (string) ( $answer['text'] ?? '' ),
					);
				}
			}

			$questions[] = array(
				'id'      => $question_id,
				'title'   => html_entity_decode( (string) get_the_title( $question_id ), ENT_QUOTES, 'UTF-8' ),
				// phpcs:ignore WordPress.NamingConventions.ValidHookName.NotLowercase, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
				'content' => apply_filters( 'the_content', (string) get_post_field( 'post_content', $question_id ) ),
				'type'    => $type,
				'points'  => (float) get_post_meta( $question_id, \ODSI\LMS\Support\Meta::QUESTION_POINTS, true ) ?: 1.0,
				'options' => $options,
			);
		}//end foreach

		return new WP_REST_Response(
			array(
				'quiz_id'            => $quiz_id,
				'questions'          => $questions,
				'pass_mark'          => (float) get_post_meta( $quiz_id, \ODSI\LMS\Support\Meta::PASS_MARK, true ),
				'time_limit'         => (int) get_post_meta( $quiz_id, \ODSI\LMS\Support\Meta::TIME_LIMIT, true ),
				'attempts_remaining' => $this->quizzes->attempts_remaining( $user_id, $quiz_id ),
			)
		);
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
