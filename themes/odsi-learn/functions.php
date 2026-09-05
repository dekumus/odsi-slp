<?php
/**
 * ODSI Learn theme bootstrap.
 *
 * A block theme that gives WordPress, the ODSI Learning plugin and the ODSI
 * Community plugin one visual language. It never calls a plugin class: every
 * integration point is a public hook, a post type or a shortcode check, so
 * the theme keeps working with either plugin absent.
 *
 * @package odsi-learn
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'ODSI_LEARN_VERSION', '0.1.0' );

/**
 * Theme supports and editor styles.
 */
function odsi_learn_setup(): void {
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'editor-styles' );
	add_editor_style( 'assets/css/odsi-learn.css' );
	load_theme_textdomain( 'odsi-learn', get_template_directory() . '/languages' );
}
add_action( 'after_setup_theme', 'odsi_learn_setup' );

/**
 * Front-end stylesheet: loads after both plugins' stylesheets so the theme
 * layer wins on equal specificity.
 */
function odsi_learn_enqueue_assets(): void {
	$deps = array_values(
		array_filter(
			array( 'odsi-lms', 'odsi-social' ),
			static fn( string $handle ): bool => wp_style_is( $handle, 'registered' )
		)
	);

	wp_enqueue_style(
		'odsi-learn',
		get_template_directory_uri() . '/assets/css/odsi-learn.css',
		$deps,
		ODSI_LEARN_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'odsi_learn_enqueue_assets', 20 );

/**
 * Register the platform menu block and its editor script.
 */
function odsi_learn_register_blocks(): void {
	wp_register_script(
		'odsi-learn-platform-menu-editor',
		get_template_directory_uri() . '/blocks/platform-menu/editor.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
		ODSI_LEARN_VERSION,
		true
	);
	wp_set_script_translations( 'odsi-learn-platform-menu-editor', 'odsi-learn', get_template_directory() . '/languages' );

	register_block_type( get_template_directory() . '/blocks/platform-menu' );
}
add_action( 'init', 'odsi_learn_register_blocks' );

/**
 * Pattern category for the theme's starter sections.
 */
function odsi_learn_register_pattern_category(): void {
	register_block_pattern_category(
		'odsi-learn',
		array(
			'label'       => __( 'ODSI Learn', 'odsi-learn' ),
			'description' => __( 'Hero, course and community sections for a learning site.', 'odsi-learn' ),
		)
	);
}
add_action( 'init', 'odsi_learn_register_pattern_category', 9 );

/**
 * Block styles that map onto the plugins' card and button conventions.
 */
function odsi_learn_register_block_styles(): void {
	register_block_style(
		'core/group',
		array(
			'name'  => 'odsi-card',
			'label' => __( 'Card', 'odsi-learn' ),
		)
	);
	register_block_style(
		'core/button',
		array(
			'name'  => 'odsi-quiet',
			'label' => __( 'Quiet', 'odsi-learn' ),
		)
	);
}
add_action( 'init', 'odsi_learn_register_block_styles' );

/**
 * Whether the community plugin answers for a section.
 *
 * `odsi_social_page_url` is the community plugin's public URL filter; an
 * empty answer means the plugin is inactive or the section unknown.
 *
 * @param string $section Community section, for example `activity`.
 */
function odsi_learn_community_url( string $section ): string {
	return (string) apply_filters( 'odsi_social_page_url', '', $section );
}

/**
 * The page that hosts the learner dashboard, if the owner created one.
 *
 * Looks for the `[odsi_my_courses]` shortcode or the `odsi-lms/my-courses`
 * block in any published page. The result is cached for an hour and
 * invalidated whenever a page is saved.
 */
function odsi_learn_my_courses_url(): string {
	if ( ! shortcode_exists( 'odsi_my_courses' ) ) {
		return '';
	}

	$cached = get_transient( 'odsi_learn_my_courses_page' );
	if ( is_string( $cached ) && '' !== $cached ) {
		return '-' === $cached ? '' : $cached;
	}

	$url = '';
	foreach ( array( '[odsi_my_courses', 'wp:odsi-lms/my-courses' ) as $needle ) {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				's'              => $needle,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		if ( array() !== $pages ) {
			$url = (string) get_permalink( (int) $pages[0] );
			break;
		}
	}

	set_transient( 'odsi_learn_my_courses_page', '' === $url ? '-' : $url, HOUR_IN_SECONDS );

	return $url;
}

/**
 * Forget the cached dashboard page whenever a page changes.
 *
 * @param int $post_id Saved post.
 */
function odsi_learn_flush_my_courses_cache( int $post_id ): void {
	if ( 'page' === get_post_type( $post_id ) ) {
		delete_transient( 'odsi_learn_my_courses_page' );
	}
}
add_action( 'save_post', 'odsi_learn_flush_my_courses_cache' );
add_action( 'deleted_post', 'odsi_learn_flush_my_courses_cache' );

/**
 * Items for the platform menu, in display order.
 *
 * @param bool $show_account Include notifications, messages and log in / out.
 * @return array<int, array{key: string, label: string, url: string, current: bool}>
 */
function odsi_learn_platform_menu_items( bool $show_account = true ): array {
	$items = array();

	if ( post_type_exists( 'odsi_course' ) ) {
		$archive = get_post_type_archive_link( 'odsi_course' );
		if ( is_string( $archive ) && '' !== $archive ) {
			$items[] = array(
				'key'     => 'courses',
				'label'   => __( 'Courses', 'odsi-learn' ),
				'url'     => $archive,
				'current' => is_post_type_archive( 'odsi_course' ) || is_singular( array( 'odsi_course', 'odsi_lesson', 'odsi_topic', 'odsi_quiz' ) ),
			);
		}
	}

	$my_courses = is_user_logged_in() ? odsi_learn_my_courses_url() : '';
	if ( '' !== $my_courses ) {
		$items[] = array(
			'key'     => 'my-courses',
			'label'   => __( 'My courses', 'odsi-learn' ),
			'url'     => $my_courses,
			'current' => is_page() && get_permalink() === $my_courses,
		);
	}

	$community = array(
		'activity' => __( 'Activity', 'odsi-learn' ),
		'members'  => __( 'Members', 'odsi-learn' ),
		'groups'   => __( 'Groups', 'odsi-learn' ),
	);
	if ( $show_account && is_user_logged_in() ) {
		$community['notifications'] = __( 'Notifications', 'odsi-learn' );
		$community['messages']      = __( 'Messages', 'odsi-learn' );
	}

	$request = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path only, compared not output.
	foreach ( $community as $section => $label ) {
		$url = odsi_learn_community_url( $section );
		if ( '' === $url ) {
			continue;
		}
		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		$items[] = array(
			'key'     => $section,
			'label'   => $label,
			'url'     => $url,
			'current' => '' !== $path && 0 === strpos( trailingslashit( $request ), trailingslashit( $path ) ),
		);
	}

	if ( $show_account ) {
		if ( is_user_logged_in() ) {
			$items[] = array(
				'key'     => 'logout',
				'label'   => __( 'Log out', 'odsi-learn' ),
				'url'     => wp_logout_url( home_url( '/' ) ),
				'current' => false,
			);
		} else {
			$items[] = array(
				'key'     => 'login',
				'label'   => __( 'Log in', 'odsi-learn' ),
				'url'     => wp_login_url( (string) ( isset( $_SERVER['REQUEST_URI'] ) ? home_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' ) ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through esc_url on output.
				'current' => false,
			);
		}
	}

	/**
	 * Filter the platform menu items.
	 *
	 * @param array<int, array{key: string, label: string, url: string, current: bool}> $items        Items.
	 * @param bool                                                                     $show_account Whether account links were requested.
	 */
	return (array) apply_filters( 'odsi_learn_platform_menu_items', $items, $show_account );
}

/**
 * Body classes that let the stylesheet lay out plugin pages.
 *
 * @param string[] $classes Classes.
 * @return string[]
 */
function odsi_learn_body_class( array $classes ): array {
	if ( is_singular( array( 'odsi_lesson', 'odsi_topic', 'odsi_quiz' ) ) ) {
		$classes[] = 'odsi-learn-focus';
	}
	if ( '' !== (string) get_query_var( 'odsi_social_page', '' ) ) {
		$classes[] = 'odsi-learn-community';
	}

	return $classes;
}
add_filter( 'body_class', 'odsi_learn_body_class' );
