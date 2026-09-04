<?php
/**
 * Front-end rendering.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Frontend;

use ODSI\Social\Activity\Feed;
use ODSI\Social\Container;
use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Groups\Groups;
use ODSI\Social\Members\Directory;
use ODSI\Social\Members\Profiles;
use ODSI\Social\Messages\Messages;
use ODSI\Social\Notifications\Notifications;
use ODSI\Social\Repositories\GroupRepository;
use ODSI\Social\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the community virtual pages through the template loader, plus the
 * standalone shortcodes themes can drop anywhere.
 */
final class Shortcodes implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param Container $container Container, resolved lazily per render.
	 */
	public function __construct( private Container $container ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_shortcode( 'odsi_social_page', array( $this, 'render_page' ) );
		add_shortcode( 'odsi_activity_feed', array( $this, 'render_feed' ) );
		add_shortcode( 'odsi_member_directory', array( $this, 'render_directory' ) );
		add_shortcode( 'odsi_group_directory', array( $this, 'render_groups' ) );
	}

	/**
	 * `[odsi_social_page]` — dispatch on the router's section.
	 */
	public function render_page(): string {
		$router  = $this->container->get( Router::class );
		$section = $router->section();
		$object  = $router->object();
		$viewer  = get_current_user_id();

		switch ( $section ) {
			case 'members':
				return '' === $object ? $this->render_directory() : $this->render_profile( $object, $viewer );

			case 'groups':
				return '' === $object ? $this->render_groups() : $this->render_group( $object, $viewer );

			case 'activity':
				return '' === $object ? $this->render_feed( array( 'scope' => $viewer > 0 ? Feed::SCOPE_PERSONAL : Feed::SCOPE_SITE ) ) : $this->render_single_activity( (int) $object, $viewer );

			case 'notifications':
				return $this->render_notifications( $viewer );

			case 'messages':
				return '' === $object ? $this->render_inbox( $viewer ) : $this->render_thread( (int) $object, $viewer );

			default:
				return '';
		}
	}

	/**
	 * `[odsi_activity_feed scope="site|personal|group|profile" group_id="" user_id=""]`
	 *
	 * @param array<string, string|int>|string $atts Attributes.
	 */
	public function render_feed( array|string $atts = array() ): string {
		$atts   = shortcode_atts(
			array(
				'scope'    => Feed::SCOPE_SITE,
				'group_id' => 0,
				'user_id'  => 0,
				'per_page' => 0,
			),
			(array) $atts,
			'odsi_activity_feed'
		);
		$viewer = get_current_user_id();
		$feed   = $this->container->get( Feed::class );

		$page = $feed->page(
			$viewer,
			(string) $atts['scope'],
			array_filter(
				array(
					'group_id' => (int) $atts['group_id'],
					'user_id'  => (int) $atts['user_id'],
					'per_page' => (int) $atts['per_page'],
				)
			)
		);

		return $this->templates()->render(
			'activity/feed',
			array(
				'scope'           => (string) $atts['scope'],
				'group_id'        => (int) $atts['group_id'],
				'user_id'         => (int) $atts['user_id'],
				'items'           => $page['items'],
				'next_cursor'     => $page['next_cursor'],
				'viewer_id'       => $viewer,
				'can_post'        => $viewer > 0 && in_array( $atts['scope'], array( Feed::SCOPE_SITE, Feed::SCOPE_PERSONAL, Feed::SCOPE_GROUP ), true ),
				'privacy_choices' => \ODSI\Social\Activity\Privacy::choices(),
			)
		);
	}

	/**
	 * `[odsi_member_directory]`
	 */
	public function render_directory(): string {
		$viewer    = get_current_user_id();
		$directory = $this->container->get( Directory::class );

		if ( ! $directory->can_view( $viewer ) ) {
			return $this->templates()->render( 'parts/login-required' );
		}

		$args = array(
			'search'  => sanitize_text_field( wp_unslash( (string) ( $_GET['search'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
			'orderby' => sanitize_key( (string) ( $_GET['orderby'] ?? 'newest' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'page'    => max( 1, (int) get_query_var( 'paged', 1 ) ),
		);

		return $this->templates()->render( 'members/directory', $directory->query( $viewer, $args ) + array( 'args' => $args ) );
	}

	/**
	 * A member's profile page.
	 *
	 * @param string $nicename Member slug.
	 * @param int    $viewer   Viewer.
	 */
	private function render_profile( string $nicename, int $viewer ): string {
		$user = get_user_by( 'slug', $nicename );

		if ( ! $user ) {
			return $this->not_found();
		}

		$profile = $this->container->get( Profiles::class )->view( $viewer, (int) $user->ID );

		if ( ! $profile ) {
			return $this->not_found();
		}

		return $this->templates()->render(
			'members/profile',
			array(
				'profile'      => $profile,
				'viewer_id'    => $viewer,
				'feed'         => $this->render_feed(
					array(
						'scope'   => Feed::SCOPE_PROFILE,
						'user_id' => (int) $user->ID,
					)
				),
				'is_following' => $viewer > 0 && $this->container->get( \ODSI\Social\Connections\Follows::class )->is_following( $viewer, (int) $user->ID ),
			)
		);
	}

	/**
	 * `[odsi_group_directory]`
	 */
	public function render_groups(): string {
		$viewer = get_current_user_id();
		$groups = $this->container->get( Groups::class );
		$index  = $this->container->get( GroupRepository::class );

		$args = array(
			'search'       => sanitize_text_field( wp_unslash( (string) ( $_GET['search'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'orderby'      => sanitize_key( (string) ( $_GET['orderby'] ?? 'newest' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'page'         => max( 1, (int) get_query_var( 'paged', 1 ) ),
			'per_page'     => 20,
			'visibilities' => Capabilities::is_admin( $viewer ) ? array( 'public', 'private', 'hidden' ) : array( 'public', 'private' ),
			'include'      => $viewer > 0 ? $this->container->get( \ODSI\Social\Groups\Membership::class )->groups_of( $viewer ) : array(),
		);

		$result = $index->directory( $args );

		return $this->templates()->render(
			'groups/directory',
			array(
				'groups'     => array_values( array_filter( array_map( static fn ( int $id ): ?array => $groups->present( $viewer, $id ), $result['ids'] ) ) ),
				'total'      => $result['total'],
				'args'       => $args,
				'can_create' => $groups->can_create( $viewer ),
			)
		);
	}

	/**
	 * A single group page.
	 *
	 * @param string $slug   Group slug.
	 * @param int    $viewer Viewer.
	 */
	private function render_group( string $slug, int $viewer ): string {
		$row    = $this->container->get( GroupRepository::class )->find_by_slug( $slug );
		$groups = $this->container->get( Groups::class );

		if ( ! $row ) {
			return $this->not_found();
		}

		$group = $groups->present( $viewer, (int) $row->post_id );

		if ( null === $group ) {
			return $this->not_found();
		}

		$can_view_content = $groups->can_view_content( $viewer, (int) $row->post_id );

		return $this->templates()->render(
			'groups/single',
			array(
				'group'            => $group,
				'viewer_id'        => $viewer,
				'can_view_content' => $can_view_content,
				'is_moderator'     => $groups->is_moderator( $viewer, (int) $row->post_id ),
				'feed'             => $can_view_content ? $this->render_feed(
					array(
						'scope'    => Feed::SCOPE_GROUP,
						'group_id' => (int) $row->post_id,
					)
				) : '',
			)
		);
	}

	/**
	 * A single activity item with all comments.
	 *
	 * @param int $id     Activity id.
	 * @param int $viewer Viewer.
	 */
	private function render_single_activity( int $id, int $viewer ): string {
		$item = $this->container->get( Feed::class )->item( $viewer, $id );

		if ( null === $item ) {
			return $this->not_found();
		}

		return $this->templates()->render(
			'activity/single',
			array(
				'item'      => $item,
				'viewer_id' => $viewer,
			)
		);
	}

	/**
	 * Notifications page.
	 *
	 * @param int $viewer Viewer.
	 */
	private function render_notifications( int $viewer ): string {
		if ( $viewer <= 0 ) {
			return $this->templates()->render( 'parts/login-required' );
		}

		$notifications = $this->container->get( Notifications::class );

		return $this->templates()->render(
			'notifications/list',
			array(
				'notifications' => $notifications->list( $viewer, false, max( 1, (int) get_query_var( 'paged', 1 ) ) ),
				'unread_count'  => $notifications->unread_count( $viewer ),
			)
		);
	}

	/**
	 * Inbox page.
	 *
	 * @param int $viewer Viewer.
	 */
	private function render_inbox( int $viewer ): string {
		if ( $viewer <= 0 ) {
			return $this->templates()->render( 'parts/login-required' );
		}

		$messages = $this->container->get( Messages::class );

		return $this->templates()->render(
			'messages/inbox',
			array(
				'threads'      => $messages->inbox( $viewer, max( 1, (int) get_query_var( 'paged', 1 ) ) ),
				'unread_total' => $messages->unread_total( $viewer ),
			)
		);
	}

	/**
	 * Thread page.
	 *
	 * @param int $thread_id Thread.
	 * @param int $viewer    Viewer.
	 */
	private function render_thread( int $thread_id, int $viewer ): string {
		if ( $viewer <= 0 ) {
			return $this->templates()->render( 'parts/login-required' );
		}

		$thread = $this->container->get( Messages::class )->thread( $viewer, $thread_id );

		if ( $thread instanceof \WP_Error ) {
			return $this->not_found();
		}

		return $this->templates()->render(
			'messages/thread',
			array(
				'thread'    => $thread,
				'viewer_id' => $viewer,
			)
		);
	}

	/**
	 * Not-found markup (ADR-011: no distinction from non-existence).
	 */
	private function not_found(): string {
		status_header( 404 );

		return $this->templates()->render( 'parts/not-found' );
	}

	/**
	 * Template loader.
	 */
	private function templates(): Templates {
		return $this->container->get( Templates::class );
	}
}
