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
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Maps /members/, /groups/, /activity/, /notifications/ and /messages/ to
 * virtual pages rendered through the theme's page template (SOC-IF-003).
 *
 * The virtual page is installed into the main query itself, before core
 * resolves a template: the query is flagged as a singular page while it is
 * parsed, and a stand-in post is handed back in place of a database query.
 * Core then does everything a real page gets — the theme's page template
 * (block or classic), the document title, body classes, the loop — and a
 * page that does not exist for the viewer is an ordinary core 404 rendered
 * by the theme's own 404 template.
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
		add_action( 'parse_query', array( $this, 'on_parse_query' ) );
		add_filter( 'posts_pre_query', array( $this, 'supply_virtual_page' ), 10, 2 );
		add_filter( 'redirect_canonical', array( $this, 'filter_canonical_redirect' ) );
		add_filter( 'get_edit_post_link', array( $this, 'filter_edit_link' ), 10, 2 );
		add_filter( 'page_template_hierarchy', array( $this, 'filter_template_hierarchy' ) );
		add_filter( 'document_title_parts', array( $this, 'filter_title' ) );
		add_filter( 'odsi_social_member_url', array( $this, 'member_url' ), 10, 2 );
		add_filter( 'odsi_social_group_url', array( $this, 'group_url' ), 10, 2 );
		add_filter( 'odsi_social_activity_url', array( $this, 'activity_url' ), 10, 2 );
		add_filter( 'odsi_social_thread_url', array( $this, 'thread_url' ), 10, 2 );
		add_filter( 'odsi_social_page_url', array( $this, 'page_url' ), 10, 4 );
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
	 * Flag the main query as a singular page while it is parsed, so core's
	 * template hierarchy (block or classic) resolves the page template and
	 * not the blog index it would otherwise pick for unknown query vars.
	 *
	 * @param WP_Query $query Query being parsed.
	 */
	public function on_parse_query( WP_Query $query ): void {
		if ( ! $query->is_main_query() || '' === $this->section_of( $query ) ) {
			return;
		}

		$query->is_home     = false;
		$query->is_archive  = false;
		$query->is_404      = false;
		$query->is_page     = true;
		$query->is_singular = true;

		// The stand-in post has no terms or meta to warm.
		$query->set( 'update_post_term_cache', false );
		$query->set( 'update_post_meta_cache', false );
		$query->set( 'cache_results', false );
	}

	/**
	 * Hand the main query its stand-in post instead of running a database
	 * query, or an empty result when the page does not exist for the viewer
	 * so core issues its normal 404 (status, no-cache headers, 404 template).
	 *
	 * @param WP_Post[]|int[]|null $posts Posts, null to let core query.
	 * @param WP_Query             $query Query.
	 *
	 * @return WP_Post[]|int[]|null
	 */
	public function supply_virtual_page( ?array $posts, WP_Query $query ): ?array {
		$section = $this->section_of( $query );

		if ( ! $query->is_main_query() || '' === $section ) {
			return $posts;
		}

		$object_slug = sanitize_title( (string) $query->get( self::QV_OBJECT, '' ) );
		$action      = sanitize_key( (string) $query->get( self::QV_ACTION, '' ) );
		$viewer      = get_current_user_id();

		if ( 404 === $this->status_for( $section, $object_slug, $action, $viewer ) ) {
			$query->found_posts   = 0;
			$query->max_num_pages = 0;

			return array();
		}

		$query->found_posts   = 1;
		$query->max_num_pages = 1;

		return array( $this->virtual_post( $section, $object_slug, $action, $viewer ) );
	}

	/**
	 * The HTTP status a community request deserves, decided before any output.
	 *
	 * The page renderer answers `odsi_social_page_exists` — does this member,
	 * group, item or thread exist for this viewer — and the router turns a
	 * "no" into a real 404. A private section a visitor reaches is
	 * redirected earlier, so it is not a 404 here.
	 *
	 * @param string $section     Section.
	 * @param string $object_slug Object slug.
	 * @param string $action      Sub-action.
	 * @param int    $viewer      Viewer.
	 *
	 * @return int 200 or 404.
	 */
	public function status_for( string $section, string $object_slug, string $action, int $viewer ): int {
		/**
		 * Filters whether a community page exists for the viewer (ADR-011:
		 * "does not exist" and "may not see" are the same answer).
		 *
		 * @param bool   $exists  Whether the page exists.
		 * @param string $section Section.
		 * @param string $object  Object slug (nicename, group slug, activity id, thread id).
		 * @param string $action  Sub-action (`edit`, `manage`).
		 * @param int    $viewer  Viewer, 0 for a visitor.
		 */
		$exists = (bool) apply_filters( 'odsi_social_page_exists', true, $section, $object_slug, $action, $viewer );

		return $exists ? 200 : 404;
	}

	/**
	 * Title of a community page: the section name, or what the renderer
	 * says the routed object is called (a member, a group, a conversation).
	 *
	 * @param string $section     Section.
	 * @param string $object_slug Object slug.
	 * @param string $action      Sub-action.
	 * @param int    $viewer      Viewer.
	 */
	public function title_for( string $section, string $object_slug, string $action, int $viewer ): string {
		$titles = array(
			'members'       => __( 'Members', 'odsi-social' ),
			'groups'        => __( 'Groups', 'odsi-social' ),
			'activity'      => __( 'Activity', 'odsi-social' ),
			'notifications' => __( 'Notifications', 'odsi-social' ),
			'messages'      => __( 'Messages', 'odsi-social' ),
		);

		/**
		 * Filters the title of a community page, used as the document title
		 * and printed by the theme as the page's heading.
		 *
		 * @param string $title   Title.
		 * @param string $section Section.
		 * @param string $object  Object slug.
		 * @param string $action  Sub-action.
		 * @param int    $viewer  Viewer.
		 */
		return (string) apply_filters( 'odsi_social_page_title', $titles[ $section ] ?? ucfirst( $section ), $section, $object_slug, $action, $viewer );
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
	 * A virtual page has no canonical permalink, so core must not try to
	 * redirect it to one (it would also rewrite `?paged=` pagination).
	 *
	 * @param string|false $redirect Canonical URL, or false.
	 *
	 * @return string|false
	 */
	public function filter_canonical_redirect( string|false $redirect ): string|false {
		return $this->is_community_page() ? false : $redirect;
	}

	/**
	 * The stand-in post has nothing to edit: without this, core offers
	 * administrators an "Edit Page" admin-bar link to `post.php?post=0`.
	 *
	 * @param string $link    Edit link.
	 * @param int    $post_id Post.
	 */
	public function filter_edit_link( string $link, int $post_id ): string {
		return 0 === $post_id && $this->is_community_page() ? '' : $link;
	}

	/**
	 * Let a theme supply community-specific page templates: `page-odsi-social-{section}`
	 * then `page-odsi-social` are tried before its plain page template, as
	 * `.php` files in a classic theme or `.html` block templates in a block theme.
	 *
	 * @param string[] $templates Candidate templates, most specific first.
	 *
	 * @return string[]
	 */
	public function filter_template_hierarchy( array $templates ): array {
		if ( ! $this->is_community_page() ) {
			return $templates;
		}

		return array_merge(
			array(
				'page-odsi-social-' . $this->section() . '.php',
				'page-odsi-social.php',
			),
			$templates
		);
	}

	/**
	 * Document title for community pages (core derives it from the stand-in
	 * post already; this keeps classic themes that build their own
	 * `wp_title()` honest).
	 *
	 * @param array<string, string> $parts Title parts.
	 *
	 * @return array<string, string>
	 */
	public function filter_title( array $parts ): array {
		if ( $this->is_community_page() && ! is_404() ) {
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

			if ( '' !== $this->object() ) {
				$classes[] = 'odsi-social-page-' . $this->section() . '-single';
			}
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
	 * URL of any community page, for templates (`odsi_social_page_url`).
	 *
	 * @param string $url         Current value.
	 * @param string $section     Section.
	 * @param string $object_slug Object slug.
	 * @param string $action      Action.
	 */
	public function page_url( string $url, string $section, string $object_slug = '', string $action = '' ): string {
		return '' !== $section ? $this->url( $section, $object_slug, $action ) : $url;
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

	/**
	 * Section a query asks for, or ''.
	 *
	 * @param WP_Query $query Query.
	 */
	private function section_of( WP_Query $query ): string {
		return sanitize_key( (string) $query->get( self::QV_PAGE, '' ) );
	}

	/**
	 * The stand-in post the theme's page template renders, with the
	 * community template as its content.
	 *
	 * @param string $section     Section.
	 * @param string $object_slug Object slug.
	 * @param string $action      Sub-action.
	 * @param int    $viewer      Viewer.
	 */
	private function virtual_post( string $section, string $object_slug, string $action, int $viewer ): WP_Post {
		$now     = current_time( 'mysql' );
		$now_gmt = current_time( 'mysql', true );

		return new WP_Post(
			(object) array(
				'ID'                => 0,
				'post_author'       => 0,
				'post_date'         => $now,
				'post_date_gmt'     => $now_gmt,
				'post_content'      => '[odsi_social_page]',
				'post_title'        => $this->title_for( $section, $object_slug, $action, $viewer ),
				'post_excerpt'      => '',
				'post_status'       => 'publish',
				'comment_status'    => 'closed',
				'ping_status'       => 'closed',
				'post_name'         => '' !== $object_slug ? $object_slug : $section,
				'post_type'         => 'page',
				'filter'            => 'raw',
				'post_parent'       => 0,
				'menu_order'        => 0,
				'comment_count'     => 0,
				'post_modified'     => $now,
				'post_modified_gmt' => $now_gmt,
			)
		);
	}
}
