<?php
/**
 * Front-end rendering.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Frontend;

use ODSI\Social\Activity\Activity;
use ODSI\Social\Activity\Feed;
use ODSI\Social\Container;
use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Groups\Groups;
use ODSI\Social\Members\Blocks;
use ODSI\Social\Members\Directory;
use ODSI\Social\Members\Profiles;
use ODSI\Social\Members\Uploads;
use ODSI\Social\Messages\Messages;
use ODSI\Social\Groups\Membership;
use ODSI\Social\Notifications\Notifications;
use ODSI\Social\Repositories\GroupMemberRepository;
use ODSI\Social\Repositories\GroupRepository;
use ODSI\Social\Repositories\MemberRepository;
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
		add_filter( 'odsi_social_page_exists', array( $this, 'page_exists' ), 10, 5 );
	}

	/**
	 * Whether a routed page exists for the viewer, answered before any output
	 * so the router can send a real 404 (ADR-011).
	 *
	 * @param bool   $exists      Current answer.
	 * @param string $section     Section.
	 * @param string $object_slug Object slug.
	 * @param string $action      Sub-action.
	 * @param int    $viewer      Viewer.
	 */
	public function page_exists( bool $exists, string $section, string $object_slug, string $action, int $viewer ): bool {
		if ( ! $exists || '' === $object_slug ) {
			return $exists;
		}

		switch ( $section ) {
			case 'members':
				$user     = get_user_by( 'slug', $object_slug );
				$profiles = $this->container->get( Profiles::class );

				// A blocked pair's profiles do not exist for each other (SOC-MOD-005).
				if ( ! $user || ! $profiles->is_visible( $viewer, (int) $user->ID ) ) {
					return false;
				}

				// A visitor who may not browse members is asked to log in, not told "no such page".
				return 'edit' !== $action || $viewer <= 0 || $profiles->can_edit( $viewer, (int) $user->ID );

			case 'groups':
				$row    = $this->container->get( GroupRepository::class )->find_by_slug( $object_slug );
				$groups = $this->container->get( Groups::class );

				if ( ! $row || ! $groups->can_view( $viewer, (int) $row->post_id ) ) {
					return false;
				}

				return 'manage' !== $action || $viewer <= 0 || $groups->is_organiser( $viewer, (int) $row->post_id );

			case 'activity':
				$item = $this->container->get( Activity::class )->get( (int) $object_slug );

				return null !== $item && $this->container->get( \ODSI\Social\Activity\Privacy::class )->can_view( $viewer, $item );

			case 'messages':
				return $viewer <= 0 || $this->container->get( Messages::class )->can_read( $viewer, (int) $object_slug );

			default:
				return $exists;
		}//end switch
	}

	/**
	 * `[odsi_social_page]` — dispatch on the router's section.
	 */
	public function render_page(): string {
		$router  = $this->container->get( Router::class );
		$section = $router->section();
		$object  = $router->object();
		$action  = $router->action();
		$viewer  = get_current_user_id();

		switch ( $section ) {
			case 'members':
				if ( '' === $object ) {
					return $this->render_directory();
				}

				return 'edit' === $action ? $this->render_profile_edit( $object, $viewer ) : $this->render_profile( $object, $viewer );

			case 'groups':
				if ( '' === $object ) {
					return $this->render_groups();
				}

				return 'manage' === $action ? $this->render_group_manage( $object, $viewer ) : $this->render_group( $object, $viewer );

			case 'activity':
				if ( '' !== $object ) {
					return $this->render_single_activity( (int) $object, $viewer );
				}

				// The site feed is the default; members switch to "Following" (the
				// personal feed) with ?scope=personal.
				$wants_personal = $viewer > 0 && 'personal' === sanitize_key( (string) ( $_GET['scope'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.

				return $this->render_feed(
					array(
						'scope'     => $wants_personal ? Feed::SCOPE_PERSONAL : Feed::SCOPE_SITE,
						'show_tabs' => 1,
					)
				);

			case 'notifications':
				return $this->render_notifications( $viewer );

			case 'messages':
				return '' === $object ? $this->render_inbox( $viewer ) : $this->render_thread( (int) $object, $viewer );

			default:
				return '';
		}//end switch
	}

	/**
	 * `[odsi_activity_feed scope="site|personal|group|profile" group_id="" user_id=""]`
	 *
	 * @param array<string, string|int>|string $atts Attributes.
	 */
	public function render_feed( array|string $atts = array() ): string {
		$atts   = shortcode_atts(
			array(
				'scope'     => Feed::SCOPE_SITE,
				'group_id'  => 0,
				'user_id'   => 0,
				'per_page'  => 0,
				'show_tabs' => 0,
			),
			(array) $atts,
			'odsi_activity_feed'
		);
		$viewer = get_current_user_id();
		$feed   = $this->container->get( Feed::class );
		$writer = $this->container->get( Activity::class );
		$scope  = (string) $atts['scope'];

		// With tabs, a member switches between "Everyone" (the site feed) and
		// "Following" (the personal feed) with ?scope=personal.
		if ( ! empty( $atts['show_tabs'] ) && $viewer > 0 && in_array( $scope, array( Feed::SCOPE_SITE, Feed::SCOPE_PERSONAL ), true ) ) {
			$wanted = sanitize_key( (string) ( $_GET['scope'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.

			if ( in_array( $wanted, array( Feed::SCOPE_SITE, Feed::SCOPE_PERSONAL ), true ) ) {
				$scope = $wanted;
			}
		}

		$page = $feed->page(
			$viewer,
			$scope,
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
				'scope'           => $scope,
				'group_id'        => (int) $atts['group_id'],
				'user_id'         => (int) $atts['user_id'],
				'items'           => $page['items'],
				'next_cursor'     => $page['next_cursor'],
				'viewer_id'       => $viewer,
				'show_tabs'       => (bool) $atts['show_tabs'],
				'can_post'        => $viewer > 0 && in_array( $scope, array( Feed::SCOPE_SITE, Feed::SCOPE_PERSONAL, Feed::SCOPE_GROUP ), true ),
				'privacy_choices' => $viewer > 0 ? $writer->privacy_choices( $viewer ) : array(),
				'default_privacy' => $viewer > 0 ? $writer->default_privacy( $viewer ) : '',
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
		if ( ! $this->container->get( Directory::class )->can_view( $viewer ) ) {
			return $this->templates()->render( 'parts/login-required' );
		}

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
				'can_moderate' => $viewer > 0 && ! \ODSI\Social\Support\Capabilities::is_admin( (int) $user->ID ),
			)
		);
	}

	/**
	 * A member's own settings page (SOC-MEM-003/004/007).
	 *
	 * @param string $nicename Member slug.
	 * @param int    $viewer   Viewer.
	 */
	private function render_profile_edit( string $nicename, int $viewer ): string {
		$user = get_user_by( 'slug', $nicename );

		if ( ! $user ) {
			return $this->not_found();
		}

		if ( $viewer <= 0 ) {
			return $this->templates()->render( 'parts/login-required' );
		}

		$profiles = $this->container->get( Profiles::class );

		if ( ! $profiles->can_edit( $viewer, (int) $user->ID ) ) {
			return $this->not_found();
		}

		return $this->templates()->render(
			'members/edit',
			array(
				'profile'             => $profiles->view( (int) $user->ID, (int) $user->ID ),
				'form'                => $profiles->edit_form( (int) $user->ID ),
				'message_setting'     => $profiles->message_setting( (int) $user->ID ),
				'email_notifications' => \ODSI\Social\Notifications\Emails::wants_email( (int) $user->ID ),
				'visibilities'        => array(
					'public'      => __( 'Everyone', 'odsi-social' ),
					'members'     => __( 'Members', 'odsi-social' ),
					'connections' => __( 'My connections', 'odsi-social' ),
					'only_me'     => __( 'Only me', 'odsi-social' ),
				),
				'accept'              => $this->image_accept(),
				'notice'              => $this->notice(),
				'blocked'             => $this->container->get( Blocks::class )->blocking( (int) $user->ID ),
			)
		);
	}

	/**
	 * Group management page for organisers (SOC-GRP-006).
	 *
	 * @param string $slug   Group slug.
	 * @param int    $viewer Viewer.
	 */
	private function render_group_manage( string $slug, int $viewer ): string {
		$row    = $this->container->get( GroupRepository::class )->find_by_slug( $slug );
		$groups = $this->container->get( Groups::class );

		if ( ! $row || ! $groups->can_view( $viewer, (int) $row->post_id ) ) {
			return $this->not_found();
		}

		if ( $viewer <= 0 ) {
			return $this->templates()->render( 'parts/login-required' );
		}

		if ( ! $groups->is_organiser( $viewer, (int) $row->post_id ) ) {
			return $this->not_found();
		}

		$forms = $this->container->get( Forms::class );

		return $this->templates()->render(
			'groups/manage',
			array(
				'group'     => $groups->present( $viewer, (int) $row->post_id ),
				'viewer_id' => $viewer,
				'accept'    => $this->image_accept(),
				'notice'    => $this->notice(),
			) + $forms->group_lists( (int) $row->post_id )
		);
	}

	/**
	 * Accepted image extensions for file inputs.
	 */
	private function image_accept(): string {
		return implode( ',', array_map( static fn ( string $ext ): string => '.' . $ext, array_keys( $this->container->get( Uploads::class )->allowed_mimes() ) ) );
	}

	/**
	 * Feedback carried back from a form handler in the query string.
	 *
	 * @return array{type: string, text: string}|null
	 */
	private function notice(): ?array {
		$notice = sanitize_key( (string) ( $_GET['notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only feedback flag.

		if ( 'saved' === $notice ) {
			return array(
				'type' => 'success',
				'text' => __( 'Saved.', 'odsi-social' ),
			);
		}

		if ( 'error' === $notice ) {
			return array(
				'type' => 'error',
				'text' => sanitize_text_field( rawurldecode( wp_unslash( (string) ( $_GET['message'] ?? '' ) ) ) ) ?: __( 'Something went wrong.', 'odsi-social' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised inline.
			);
		}

		return null;
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
		$groups->prime( $viewer, $result['ids'] );

		return $this->templates()->render(
			'groups/directory',
			array(
				'groups'     => array_values( array_filter( array_map( static fn ( int $id ): ?array => $groups->present( $viewer, $id ), $result['ids'] ) ) ),
				'total'      => $result['total'],
				'args'       => $args,
				'can_create' => $groups->can_create( $viewer ),
				'mine'       => $viewer > 0 ? $this->container->get( Membership::class )->mine( $viewer ) : array(),
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
				'members'          => $can_view_content ? $this->group_members( (int) $row->post_id ) : array(),
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
	 * The first 50 active members of a group, organisers first, for the group page.
	 *
	 * @param int $group_id Group.
	 *
	 * @return array<int, array{id: int, name: string, avatar: string, url: string, role: string}>
	 */
	private function group_members( int $group_id ): array {
		$rows = $this->container->get( GroupMemberRepository::class )->for_group( $group_id, GroupMemberRepository::STATUS_ACTIVE, null, 50 );

		$this->container->get( MemberRepository::class )->prime_display( array_map( static fn ( object $r ): int => (int) $r->user_id, $rows ) );

		$out = array();

		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row->user_id );

			if ( ! $user ) {
				continue;
			}

			$out[] = array(
				'id'     => (int) $row->user_id,
				'name'   => $user->display_name,
				'avatar' => (string) get_avatar_url( (int) $row->user_id, array( 'size' => 48 ) ),
				'url'    => (string) apply_filters( 'odsi_social_member_url', '', (int) $row->user_id ),
				'role'   => (string) $row->role,
			);
		}

		return $out;
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
		$page          = max( 1, (int) get_query_var( 'paged', 1 ) );
		$per_page      = 20;

		return $this->templates()->render(
			'notifications/list',
			array(
				'notifications' => $notifications->list( $viewer, false, $page, $per_page ),
				'unread_count'  => $notifications->unread_count( $viewer ),
				'total'         => $notifications->count( $viewer ),
				'page'          => $page,
				'per_page'      => $per_page,
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
		$page     = max( 1, (int) get_query_var( 'paged', 1 ) );
		$per_page = 20;

		return $this->templates()->render(
			'messages/inbox',
			array(
				'threads'      => $messages->inbox( $viewer, $page, $per_page ),
				'unread_total' => $messages->unread_total( $viewer ),
				'total'        => $messages->inbox_count( $viewer ),
				'page'         => $page,
				'per_page'     => $per_page,
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
	 * Not-found markup (ADR-011: no distinction from non-existence). The
	 * status code was sent by the router before output began, from the
	 * same answer `page_exists()` gives.
	 */
	private function not_found(): string {
		return $this->templates()->render( 'parts/not-found' );
	}

	/**
	 * Template loader.
	 */
	private function templates(): Templates {
		return $this->container->get( Templates::class );
	}
}
