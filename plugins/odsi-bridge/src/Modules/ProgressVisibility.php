<?php
/**
 * Group members' course progress.
 *
 * @package ODSI\Bridge
 */

declare( strict_types = 1 );

namespace ODSI\Bridge\Modules;

use ODSI\Bridge\Contracts\Bootable;
use ODSI\Bridge\Repositories\LinkRepository;
use ODSI\Bridge\Support\Settings;
use ODSI\LMS\Courses\Progress;
use ODSI\Social\Groups\Groups;
use ODSI\Social\Repositories\GroupMemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /odsi-bridge/v1/groups/{id}/progress` and `[odsi_group_progress]` (contract § 5).
 */
final class ProgressVisibility implements Bootable {

	public const NAMESPACE = 'odsi-bridge/v1';

	/**
	 * Constructor.
	 *
	 * @param LinkRepository $links    Links.
	 * @param Settings       $settings Settings.
	 */
	public function __construct(
		private LinkRepository $links,
		private Settings $settings
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		// The shortcode is always known, so a page keeping it does not print
		// the literal tag when the module is switched off.
		add_shortcode( 'odsi_group_progress', array( $this, 'render_shortcode' ) );

		if ( ! $this->settings->enabled( 'progress_visibility' ) ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/groups/(?P<id>\d+)/progress',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_progress' ),
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
	 * Members of a linked group with their percentage on the linked course.
	 *
	 * @param int $viewer_id Viewer.
	 * @param int $group_id  Group.
	 *
	 * @return array<string, mixed>|WP_Error 404 for outsiders or unlinked groups.
	 */
	public function progress( int $viewer_id, int $group_id ): array|WP_Error {
		$social    = \ODSI\Social\Plugin::instance()->container();
		$groups    = $social->get( Groups::class );
		$members   = $social->get( GroupMemberRepository::class );
		$course_id = $this->links->course_for( $group_id );

		$is_member = $members->is_active( $group_id, $viewer_id ) || \ODSI\Social\Support\Capabilities::is_admin( $viewer_id );

		if ( $course_id <= 0 || ! $groups->can_view( $viewer_id, $group_id ) || ! $is_member ) {
			return new WP_Error( 'odsi_bridge_not_found', __( 'That group does not exist.', 'odsi-bridge' ), array( 'status' => 404 ) );
		}

		$progress = \ODSI\LMS\Plugin::instance()->container()->get( Progress::class );
		$rows     = $members->for_group( $group_id, GroupMemberRepository::STATUS_ACTIVE, null, 500 );
		$by_user  = $progress->course_percentages( array_map( static fn ( object $r ): int => (int) $r->user_id, $rows ), $course_id );

		/**
		 * Members with their percentage.
		 *
		 * @var array<int, array{id: int, name: string, avatar: string, role: string, percentage: float}> $out
		 */
		$out = array();

		cache_users( array_map( static fn ( object $r ): int => (int) $r->user_id, $rows ) );

		foreach ( $rows as $row ) {
			$user  = get_userdata( (int) $row->user_id );
			$out[] = array(
				'id'         => (int) $row->user_id,
				'name'       => $user ? $user->display_name : __( 'A former member', 'odsi-bridge' ),
				'avatar'     => $user ? get_avatar_url( (int) $row->user_id, array( 'size' => 48 ) ) : '',
				'role'       => (string) $row->role,
				'percentage' => $by_user[ (int) $row->user_id ] ?? 0.0,
			);
		}

		$percentages = array_column( $out, 'percentage' );
		array_multisort( $percentages, SORT_DESC, SORT_NUMERIC, $out );

		return array(
			'group_id'  => $group_id,
			'course_id' => $course_id,
			'course'    => html_entity_decode( (string) get_the_title( $course_id ), ENT_QUOTES, 'UTF-8' ),
			'members'   => $out,
		);
	}

	/**
	 * REST handler.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_progress( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->progress( get_current_user_id(), (int) $request['id'] );

		return $result instanceof WP_Error ? $result : new WP_REST_Response( $result );
	}

	/**
	 * `[odsi_group_progress group_id=""]` — defaults to the current group page.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function render_shortcode( array|string $atts = array() ): string {
		if ( ! $this->settings->enabled( 'progress_visibility' ) ) {
			return '';
		}

		$atts     = shortcode_atts( array( 'group_id' => 0 ), (array) $atts, 'odsi_group_progress' );
		$group_id = (int) $atts['group_id'];

		if ( $group_id <= 0 ) {
			$router   = \ODSI\Social\Plugin::instance()->container()->get( \ODSI\Social\Frontend\Router::class );
			$row      = 'groups' === $router->section() ? \ODSI\Social\Plugin::instance()->container()->get( \ODSI\Social\Repositories\GroupRepository::class )->find_by_slug( $router->object() ) : null;
			$group_id = $row ? (int) $row->post_id : 0;
		}

		$result = $this->progress( get_current_user_id(), $group_id );

		if ( $result instanceof WP_Error ) {
			return '';
		}

		$html = '<div class="odsi-bridge-progress"><h3>' . esc_html( sprintf( /* translators: %s: course title. */ __( 'Progress on %s', 'odsi-bridge' ), $result['course'] ) ) . '</h3><ul>';

		foreach ( $result['members'] as $member ) {
			$html .= sprintf(
				'<li><img src="%1$s" alt="" width="32" height="32" /> <span>%2$s</span> <progress max="100" value="%3$s"></progress> <span>%3$s%%</span></li>',
				esc_url( (string) $member['avatar'] ),
				esc_html( (string) $member['name'] ),
				esc_html( (string) $member['percentage'] )
			);
		}

		return $html . '</ul></div>';
	}
}
