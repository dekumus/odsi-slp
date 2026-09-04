<?php
/**
 * Virtual page routing.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Frontend;

use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Maps /members/, /groups/, /activity/, /notifications/ and /messages/ to
 * virtual pages rendered through the theme's page template (SOC-IF-003).
 */
final class Router implements Bootable {

	public const QV_PAGE   = 'odsi_social_page';
	public const QV_OBJECT = 'odsi_social_object';
	public const QV_ACTION = 'odsi_social_action';

	/**
	 * Option set by the installer when it could not flush rewrite rules itself.
	 */
	public const FLUSH_OPTION = 'odsi_social_flush_rewrites';

	/**
	 * Sections that require a logged-in viewer.
	 */
	private const PRIVATE_SECTIONS = array( 'notifications', 'messages' );

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings, for slugs.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register_rewrites' ), 10 );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'parse_request', array( $this, 'on_parse_request' ) );
		add_filter( 'template_include', array( $this, 'filter_template' ), 99 );
		add_filter( 'document_title_parts', array( $this, 'filter_title' ) );
		add_filter( 'odsi_social_member_url', array( $this, 'member_url' ), 10, 2 );
		add_filter( 'odsi_social_group_url', array( $this, 'group_url' ), 10, 2 );
		add_filter( 'odsi_social_activity_url', array( $this, 'activity_url' ), 10, 2 );
		add_filter( 'odsi_social_thread_url', array( $this, 'thread_url' ), 10, 2 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Rewrite rules for every section.
	 */
	public function register_rewrites(): void {
		if ( ! isset( $GLOBALS['wp_rewrite'] ) ) {
			return;
		}

		foreach ( array( 'members', 'groups', 'activity', 'notifications', 'messages' ) as $section ) {
			$slug = $this->settings->slug( $section );

			add_rewrite_rule( "^{$slug}/?$", 'index.php?' . self::QV_PAGE . '=' . $section, 'top' );
			add_rewrite_rule( "^{$slug}/page/(\\d+)/?$", 'index.php?' . self::QV_PAGE . '=' . $section . '&paged=$matches[1]', 'top' );
			add_rewrite_rule( "^{$slug}/([^/]+)/?$", 'index.php?' . self::QV_PAGE . '=' . $section . '&' . self::QV_OBJECT . '=$matches[1]', 'top' );
			add_rewrite_rule( "^{$slug}/([^/]+)/([^/]+)/?$", 'index.php?' . self::QV_PAGE . '=' . $section . '&' . self::QV_OBJECT . '=$matches[1]&' . self::QV_ACTION . '=$matches[2]', 'top' );
		}

		if ( get_option( self::FLUSH_OPTION ) ) {
			delete_option( self::FLUSH_OPTION );
			flush_rewrite_rules();
		}
	}

	/**
	 * Expose the query vars.
	 *
	 * @param string[] $vars Query vars.
	 *
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		return array_merge( $vars, array( self::QV_PAGE, self::QV_OBJECT, self::QV_ACTION ) );
	}

	/**
	 * Redirect logged-out visitors away from private sections.
	 *
	 * @param \WP $wp Request.
	 */
	public function on_parse_request( \WP $wp ): void {
		$section = (string) ( $wp->query_vars[ self::QV_PAGE ] ?? '' );

		if ( '' !== $section && in_array( $section, self::PRIVATE_SECTIONS, true ) && ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $this->url( $section ) ) );
			exit;
		}
	}

	/**
	 * Whether the current request is a community virtual page.
	 */
	public function is_community_page(): bool {
		return '' !== $this->section();
	}

	/**
	 * Current section, or ''.
	 */
	public function section(): string {
		return (string) get_query_var( self::QV_PAGE, '' );
	}

	/**
	 * Current object slug (member nicename, group slug, activity id, thread id), or ''.
	 */
	public function object(): string {
		return sanitize_title( (string) get_query_var( self::QV_OBJECT, '' ) );
	}

	/**
	 * Current sub-action, or ''.
	 */
	public function action(): string {
		return sanitize_key( (string) get_query_var( self::QV_ACTION, '' ) );
	}

	/**
	 * Render the community template through the theme's page template.
	 *
	 * @param string $template Resolved template.
	 */
	public function filter_template( string $template ): string {
		if ( ! $this->is_community_page() ) {
			return $template;
		}

		global $wp_query;

		$wp_query->is_404      = false;
		$wp_query->is_page     = true;
		$wp_query->is_singular = true;
		$wp_query->is_home     = false;
		$wp_query->is_archive  = false;

		status_header( 200 );

		$this->install_virtual_post();

		$page = locate_template( array( 'page.php', 'singular.php', 'index.php' ) );

		return $page ?: $template;
	}

	/**
	 * Put a stand-in post into the loop so page templates have something to render,
	 * with the community template as its content.
	 */
	private function install_virtual_post(): void {
		global $wp_query, $post;

		$section = $this->section();
		$titles  = array(
			'members'       => __( 'Members', 'odsi-social' ),
			'groups'        => __( 'Groups', 'odsi-social' ),
			'activity'      => __( 'Activity', 'odsi-social' ),
			'notifications' => __( 'Notifications', 'odsi-social' ),
			'messages'      => __( 'Messages', 'odsi-social' ),
		);

		$virtual = new \WP_Post(
			(object) array(
				'ID'                => 0,
				'post_author'       => 0,
				'post_date'         => current_time( 'mysql' ),
				'post_date_gmt'     => current_time( 'mysql', true ),
				'post_content'      => '[odsi_social_page]',
				'post_title'        => $titles[ $section ] ?? ucfirst( $section ),
				'post_excerpt'      => '',
				'post_status'       => 'publish',
				'comment_status'    => 'closed',
				'ping_status'       => 'closed',
				'post_name'         => $section,
				'post_type'         => 'page',
				'filter'            => 'raw',
				'post_parent'       => 0,
				'menu_order'        => 0,
				'comment_count'     => 0,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			)
		);

		$wp_query->posts          = array( $virtual );
		$wp_query->post           = $virtual;
		$wp_query->post_count     = 1;
		$wp_query->found_posts    = 1;
		$wp_query->max_num_pages  = 1;
		$wp_query->queried_object = $virtual;
		$post                     = $virtual; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional virtual post.
	}

	/**
	 * Document title for community pages.
	 *
	 * @param array<string, string> $parts Title parts.
	 *
	 * @return array<string, string>
	 */
	public function filter_title( array $parts ): array {
		if ( $this->is_community_page() ) {
			$parts['title'] = get_the_title();
		}

		return $parts;
	}

	/**
	 * Body classes for styling.
	 *
	 * @param string[] $classes Classes.
	 *
	 * @return string[]
	 */
	public function body_class( array $classes ): array {
		if ( $this->is_community_page() ) {
			$classes[] = 'odsi-social';
			$classes[] = 'odsi-social-page-' . $this->section();
		}

		return $classes;
	}

	/**
	 * URL of a section, optionally with an object and action.
	 *
	 * @param string $section Section.
	 * @param string $object_slug Object slug.
	 * @param string $action      Action.
	 */
	public function url( string $section, string $object_slug = '', string $action = '' ): string {
		$path = $this->settings->slug( $section );

		if ( '' !== $object_slug ) {
			$path .= '/' . rawurlencode( $object_slug );

			if ( '' !== $action ) {
				$path .= '/' . rawurlencode( $action );
			}
		}

		return home_url( user_trailingslashit( $path ) );
	}

	/**
	 * Member profile URL.
	 *
	 * @param string $url     Current value.
	 * @param int    $user_id Member.
	 */
	public function member_url( string $url, int $user_id ): string {
		$user = get_userdata( $user_id );

		return $user ? $this->url( 'members', $user->user_nicename ) : $url;
	}

	/**
	 * Group URL.
	 *
	 * @param string $url      Current value.
	 * @param int    $group_id Group.
	 */
	public function group_url( string $url, int $group_id ): string {
		$post = get_post( $group_id );

		return $post ? $this->url( 'groups', $post->post_name ) : $url;
	}

	/**
	 * Single activity URL.
	 *
	 * @param string $url         Current value.
	 * @param int    $activity_id Activity.
	 */
	public function activity_url( string $url, int $activity_id ): string {
		return $activity_id > 0 ? $this->url( 'activity', (string) $activity_id ) : $url;
	}

	/**
	 * Thread URL.
	 *
	 * @param string $url       Current value.
	 * @param int    $thread_id Thread.
	 */
	public function thread_url( string $url, int $thread_id ): string {
		return $thread_id > 0 ? $this->url( 'messages', (string) $thread_id ) : $url;
	}
}
