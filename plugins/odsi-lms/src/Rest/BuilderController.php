<?php
/**
 * Course builder REST controller.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Rest;

use ODSI\LMS\Courses\Structure;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Meta;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The tree the builder edits: read it, reorder it, add to it. Every write
 * requires the caller to be able to edit the course, and only touches the
 * relationship meta and `menu_order` that the classic meta boxes also write.
 */
final class BuilderController {

	/**
	 * Constructor.
	 *
	 * @param Structure $structure Outline resolver.
	 */
	public function __construct( private Structure $structure ) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$permission = static fn ( WP_REST_Request $request ): bool => current_user_can( 'edit_post', (int) $request['id'] ) && PostTypes::COURSE === get_post_type( (int) $request['id'] );
		$id         = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/courses/(?P<id>\d+)/builder',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'tree' ),
					'permission_callback' => $permission,
					'args'                => $id,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add' ),
					'permission_callback' => $permission,
					'args'                => $id + array(
						'type'   => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( PostTypes::LESSON, PostTypes::TOPIC, PostTypes::QUIZ ),
						),
						'title'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'parent' => array(
							'type'    => 'integer',
							'default' => 0,
						),
					),
				),
			)
		);

		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/courses/(?P<id>\d+)/builder/reorder',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reorder' ),
				'permission_callback' => $permission,
				'args'                => $id + array(
					'items' => array(
						'type'     => 'array',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/courses/(?P<id>\d+)/builder/(?P<node>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'detach' ),
				'permission_callback' => $permission,
				'args'                => $id + array(
					'node' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * `GET /courses/{id}/builder` — the outline as a tree, drafts included.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function tree( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->build_tree( (int) $request['id'] ) );
	}

	/**
	 * `POST /courses/{id}/builder` — create a node under the course or a lesson/topic.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function add( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$course_id = (int) $request['id'];
		$type      = (string) $request['type'];
		$parent    = (int) $request['parent'];
		$title     = sanitize_text_field( (string) $request['title'] );

		if ( '' === $title ) {
			return new WP_Error( 'odsi_lms_title_required', __( 'Give it a title first.', 'odsi-lms' ), array( 'status' => 400 ) );
		}

		$meta = array( Meta::COURSE_ID => $course_id );

		if ( PostTypes::TOPIC === $type ) {
			if ( PostTypes::LESSON !== get_post_type( $parent ) || (int) get_post_meta( $parent, Meta::COURSE_ID, true ) !== $course_id ) {
				return new WP_Error( 'odsi_lms_invalid_parent', __( 'A topic belongs under a lesson of this course.', 'odsi-lms' ), array( 'status' => 400 ) );
			}

			$meta[ Meta::LESSON_ID ] = $parent;
		} elseif ( PostTypes::QUIZ === $type && $parent > 0 ) {
			if ( ! in_array( get_post_type( $parent ), array( PostTypes::LESSON, PostTypes::TOPIC ), true ) || (int) get_post_meta( $parent, Meta::COURSE_ID, true ) !== $course_id ) {
				return new WP_Error( 'odsi_lms_invalid_parent', __( 'A quiz belongs under this course, or one of its lessons or topics.', 'odsi-lms' ), array( 'status' => 400 ) );
			}

			$meta[ Meta::LESSON_ID ] = $parent;
		}

		$siblings = PostTypes::LESSON === $type ? $this->structure->lessons( $course_id ) : ( PostTypes::TOPIC === $type ? $this->structure->topics( $parent ) : ( $parent > 0 ? $this->structure->quizzes_for( $parent ) : $this->structure->course_quizzes( $course_id ) ) );

		$post_id = wp_insert_post(
			array(
				'post_type'   => $type,
				'post_status' => 'draft',
				'post_title'  => $title,
				'post_author' => get_current_user_id(),
				'menu_order'  => count( $siblings ) + 1,
				'meta_input'  => $meta,
			),
			true
		);

		if ( $post_id instanceof WP_Error ) {
			$post_id->add_data( array( 'status' => 500 ) );

			return $post_id;
		}

		$this->structure->flush();

		return new WP_REST_Response( $this->build_tree( $course_id ), 201 );
	}

	/**
	 * `POST /courses/{id}/builder/reorder` — set parent and order for many nodes.
	 *
	 * Each item: `{ id, parent, order }`. Only nodes already in this course are
	 * touched, so a request cannot steal another course's lessons.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reorder( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request['id'];
		$members   = $this->node_ids( $course_id );

		foreach ( (array) $request['items'] as $item ) {
			$node   = (int) ( $item['id'] ?? 0 );
			$parent = (int) ( $item['parent'] ?? 0 );
			$order  = (int) ( $item['order'] ?? 0 );

			if ( ! in_array( $node, $members, true ) ) {
				continue;
			}

			$type = (string) get_post_type( $node );

			if ( PostTypes::TOPIC === $type && ( PostTypes::LESSON !== get_post_type( $parent ) || ! in_array( $parent, $members, true ) ) ) {
				continue;
			}

			if ( PostTypes::QUIZ === $type && $parent > 0 && ( ! in_array( $parent, $members, true ) || ! in_array( get_post_type( $parent ), array( PostTypes::LESSON, PostTypes::TOPIC ), true ) ) ) {
				continue;
			}

			if ( PostTypes::LESSON !== $type ) {
				update_post_meta( $node, Meta::LESSON_ID, $parent );
			}

			wp_update_post(
				array(
					'ID'         => $node,
					'menu_order' => $order,
				)
			);
		}//end foreach

		$this->structure->flush();

		return new WP_REST_Response( $this->build_tree( $course_id ) );
	}

	/**
	 * `DELETE /courses/{id}/builder/{node}` — detach a node (it is not deleted).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function detach( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$course_id = (int) $request['id'];
		$node      = (int) $request['node'];

		if ( ! in_array( $node, $this->node_ids( $course_id ), true ) ) {
			return new WP_Error( 'odsi_lms_not_in_course', __( 'That item is not part of this course.', 'odsi-lms' ), array( 'status' => 404 ) );
		}

		$descendants = array_merge( $this->structure->quizzes_for( $node ), $this->structure->topics( $node ) );

		foreach ( $this->structure->topics( $node ) as $topic ) {
			$descendants = array_merge( $descendants, $this->structure->quizzes_for( $topic ) );
		}

		delete_post_meta( $node, Meta::COURSE_ID );
		delete_post_meta( $node, Meta::LESSON_ID );

		// Whatever hung below it leaves the course too, or it would linger as
		// an invisible member that reappears on re-attachment.
		foreach ( $descendants as $child ) {
			delete_post_meta( $child, Meta::COURSE_ID );
			delete_post_meta( $child, Meta::LESSON_ID );
		}

		$this->structure->flush();

		return new WP_REST_Response( $this->build_tree( $course_id ) );
	}

	/**
	 * Tree with drafts, for the editor.
	 *
	 * @param int $course_id Course.
	 *
	 * @return array{course_id: int, lessons: array<int, array<string, mixed>>, quizzes: array<int, array<string, mixed>>}
	 */
	private function build_tree( int $course_id ): array {
		$lessons = array();

		foreach ( $this->children( PostTypes::LESSON, Meta::COURSE_ID, $course_id ) as $lesson ) {
			$topics = array();

			foreach ( $this->children( PostTypes::TOPIC, Meta::LESSON_ID, $lesson ) as $topic ) {
				$topics[] = $this->node( $topic ) + array( 'quizzes' => array_map( array( $this, 'node' ), $this->children( PostTypes::QUIZ, Meta::LESSON_ID, $topic ) ) );
			}

			$lessons[] = $this->node( $lesson ) + array(
				'topics'  => $topics,
				'quizzes' => array_map( array( $this, 'node' ), $this->children( PostTypes::QUIZ, Meta::LESSON_ID, $lesson ) ),
			);
		}

		$course_quizzes = array_filter(
			$this->children( PostTypes::QUIZ, Meta::COURSE_ID, $course_id ),
			static fn ( int $quiz ): bool => 0 === (int) get_post_meta( $quiz, Meta::LESSON_ID, true )
		);

		return array(
			'course_id' => $course_id,
			'lessons'   => $lessons,
			'quizzes'   => array_values( array_map( array( $this, 'node' ), $course_quizzes ) ),
		);
	}

	/**
	 * One node's presentation.
	 *
	 * @param int $id Post.
	 *
	 * @return array<string, mixed>
	 */
	private function node( int $id ): array {
		return array(
			'id'     => $id,
			'type'   => (string) get_post_type( $id ),
			'title'  => html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'status' => (string) get_post_status( $id ),
			'order'  => (int) get_post_field( 'menu_order', $id ),
			'edit'   => (string) get_edit_post_link( $id, 'raw' ),
		);
	}

	/**
	 * Ordered children of any status (the editor sees drafts).
	 *
	 * @param string $type      Post type.
	 * @param string $meta_key  Relationship key.
	 * @param int    $parent_id Parent.
	 *
	 * @return int[]
	 */
	private function children( string $type, string $meta_key, int $parent_id ): array {
		$query = new WP_Query(
			array(
				'post_type'              => $type,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded editor query.
				'orderby'                => array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
					'ID'         => 'ASC',
				),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_key'               => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'             => $parent_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Every node id belonging to a course, any status.
	 *
	 * @param int $course_id Course.
	 *
	 * @return int[]
	 */
	private function node_ids( int $course_id ): array {
		$ids = array();

		foreach ( array( PostTypes::LESSON, PostTypes::TOPIC, PostTypes::QUIZ ) as $type ) {
			$ids = array_merge( $ids, $this->children( $type, Meta::COURSE_ID, $course_id ) );
		}

		return $ids;
	}
}
