<?php
/**
 * Guards on WordPress core surfaces.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Rest;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Courses\Access;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Capabilities;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's own routes check access; this closes the doors WordPress core
 * opens on the same data: the `wp/v2` post endpoints, the media endpoints and
 * the Media Library (LMS-ACC-008, LMS-ASN-010).
 */
final class CoreGuards implements Bootable {

	public const SUBMISSION_META = '_odsi_submission_user';

	/**
	 * Constructor.
	 *
	 * @param Access $access Access rules.
	 */
	public function __construct( private Access $access ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		foreach ( PostTypes::trackable() as $type ) {
			add_filter( "rest_prepare_{$type}", array( $this, 'lock_rest_step' ), 10, 3 );
		}

		add_filter( 'rest_request_before_callbacks', array( $this, 'guard_core_routes' ), 10, 3 );
		add_filter( 'rest_attachment_query', array( $this, 'hide_submissions_from_query' ), 10, 2 );
		add_filter( 'ajax_query_attachments_args', array( $this, 'hide_submissions_from_library' ) );
		add_action( 'pre_get_posts', array( $this, 'hide_submissions_from_list' ) );
	}

	/**
	 * A locked step's REST representation carries no content or excerpt.
	 *
	 * `content.rendered` is already the locked notice because `the_content`
	 * is filtered, but a hand-written excerpt bypasses that filter.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_Post          $post     Step.
	 * @param WP_REST_Request  $request  Request.
	 */
	public function lock_rest_step( WP_REST_Response $response, WP_Post $post, WP_REST_Request $request ): WP_REST_Response {
		if ( current_user_can( 'edit_post', $post->ID ) || $this->access->can_access_step( get_current_user_id(), $post->ID ) ) {
			return $response;
		}

		$data = $response->get_data();

		if ( isset( $data['excerpt'] ) && is_array( $data['excerpt'] ) ) {
			$data['excerpt']['rendered'] = '';
		}

		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			$data['content']['rendered']  = $this->access->filter_content_for( $post->ID );
			$data['content']['protected'] = true;
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Questions and cohorts are editor data; learner uploads are private.
	 *
	 * @param WP_REST_Response|WP_Error|mixed $response Response so far.
	 * @param array<string, mixed>            $handler  Handler.
	 * @param WP_REST_Request                 $request  Request.
	 *
	 * @return WP_REST_Response|WP_Error|mixed
	 */
	public function guard_core_routes( mixed $response, array $handler, WP_REST_Request $request ): mixed {
		if ( null !== $response ) {
			return $response;
		}

		$route = $request->get_route();

		foreach ( array(
			PostTypes::QUESTION => 'edit_odsi_questions',
			PostTypes::COHORT   => 'edit_odsi_cohorts',
		) as $type => $cap ) {
			if ( str_starts_with( $route, "/wp/v2/{$type}" ) && ! current_user_can( $cap ) ) {
				return new WP_Error( 'odsi_lms_forbidden', __( 'You cannot read this content.', 'odsi-lms' ), array( 'status' => rest_authorization_required_code() ) );
			}
		}

		if ( 1 === preg_match( '#^/wp/v2/media/(\d+)$#', $route, $m ) ) {
			$owner = (int) get_post_meta( (int) $m[1], self::SUBMISSION_META, true );

			if ( $owner > 0 && ! $this->may_see_submission( $owner ) ) {
				return new WP_Error( 'odsi_lms_forbidden', __( 'You cannot read this file.', 'odsi-lms' ), array( 'status' => rest_authorization_required_code() ) );
			}
		}

		return $response;
	}

	/**
	 * `GET /wp/v2/media` never lists learner uploads to anyone but managers.
	 *
	 * @param array<string, mixed> $args    Query args.
	 * @param WP_REST_Request      $request Request.
	 *
	 * @return array<string, mixed>
	 */
	public function hide_submissions_from_query( array $args, WP_REST_Request $request ): array {
		return $this->without_submissions( $args );
	}

	/**
	 * The Media Library modal.
	 *
	 * @param array<string, mixed> $args Query args.
	 *
	 * @return array<string, mixed>
	 */
	public function hide_submissions_from_library( array $args ): array {
		return $this->without_submissions( $args );
	}

	/**
	 * The Media Library list view.
	 *
	 * @param WP_Query $query Query.
	 */
	public function hide_submissions_from_list( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		$args = $this->without_submissions( array( 'meta_query' => (array) $query->get( 'meta_query' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		$query->set( 'meta_query', $args['meta_query'] );
	}

	/**
	 * Add the exclusion clause unless the user manages the LMS.
	 *
	 * @param array<string, mixed> $args Query args.
	 *
	 * @return array<string, mixed>
	 */
	private function without_submissions( array $args ): array {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			return $args;
		}

		$meta_query   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$meta_query[] = array(
			'key'     => self::SUBMISSION_META,
			'compare' => 'NOT EXISTS',
		);

		$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed key.

		return $args;
	}

	/**
	 * Managers and the learner themselves may see a submission file.
	 *
	 * @param int $owner Learner who uploaded it.
	 */
	private function may_see_submission( int $owner ): bool {
		return current_user_can( Capabilities::MANAGE ) || get_current_user_id() === $owner;
	}
}
