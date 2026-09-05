<?php
/**
 * The odsi-learn block theme against both plugins.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Theme;

use ODSI\Tests\Integration\TestCase;
use WP_Block_Patterns_Registry;
use WP_Block_Type_Registry;

/**
 * Runs with ODSI_TEST_THEME=odsi-learn (composer test:theme). The theme's
 * functions.php only loads at bootstrap, so the suite cannot switch theme
 * per test; it skips itself when a different theme is active.
 *
 * @group theme
 */
final class ThemeTest extends TestCase {

	/**
	 * Skip unless the theme is the active one.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( 'odsi-learn' !== get_stylesheet() ) {
			$this->markTestSkipped( 'Run with ODSI_TEST_THEME=odsi-learn.' );
		}
	}

	public function test_is_a_block_theme_with_the_shared_palette(): void {
		$this->assertTrue( wp_is_block_theme() );

		$palette = wp_get_global_settings( array( 'color', 'palette', 'theme' ) );
		$slugs   = array_column( $palette, 'slug' );

		foreach ( array( 'base', 'contrast', 'accent', 'accent-dark', 'surface', 'border', 'muted', 'success', 'warning', 'danger' ) as $slug ) {
			$this->assertContains( $slug, $slugs, "Palette is missing {$slug}." );
		}

		$css = (string) file_get_contents( get_template_directory() . '/assets/css/odsi-learn.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.

		foreach ( array( '--odsi-accent', '--odsi-accent-contrast', '--odsi-surface', '--odsi-border', '--odsi-muted', '--odsi-radius' ) as $token ) {
			$this->assertStringContainsString( $token . ':', $css, "Theme does not define the shared token {$token}." );
		}
	}

	public function test_every_template_and_part_uses_only_registered_blocks(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$files    = array_merge(
			glob( get_template_directory() . '/templates/*.html' ) ?: array(),
			glob( get_template_directory() . '/parts/*.html' ) ?: array()
		);

		$this->assertNotEmpty( $files );

		foreach ( $files as $file ) {
			$blocks = parse_blocks( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
			foreach ( $this->flatten( $blocks ) as $name ) {
				$this->assertTrue( $registry->is_registered( $name ), basename( $file ) . " uses unregistered block {$name}." );
			}
		}
	}

	public function test_custom_post_type_templates_resolve(): void {
		foreach ( array( 'single-odsi_course', 'single-odsi_lesson', 'single-odsi_topic', 'single-odsi_quiz', 'archive-odsi_course', 'front-page', 'page', 'single', 'index', '404', 'search', 'archive' ) as $slug ) {
			$this->assertNotNull( get_block_template( 'odsi-learn//' . $slug ), "Template {$slug} missing." );
		}
		foreach ( array( 'header', 'footer' ) as $part ) {
			$this->assertNotNull( get_block_template( 'odsi-learn//' . $part, 'wp_template_part' ), "Part {$part} missing." );
		}
	}

	public function test_patterns_are_registered(): void {
		$registry = WP_Block_Patterns_Registry::get_instance();

		foreach ( array( 'odsi-learn/hero', 'odsi-learn/courses', 'odsi-learn/community', 'odsi-learn/cta', 'odsi-learn/query-cards' ) as $pattern ) {
			$this->assertTrue( $registry->is_registered( $pattern ), "Pattern {$pattern} missing." );
		}

		$hero = do_blocks( '<!-- wp:pattern {"slug":"odsi-learn/hero"} /-->' );
		$this->assertStringContainsString( 'odsi-learn-hero', $hero );
		$this->assertStringContainsString( get_post_type_archive_link( 'odsi_course' ), $hero );
	}

	public function test_platform_menu_follows_the_visitor_and_the_active_plugins(): void {
		wp_set_current_user( 0 );
		$html = do_blocks( '<!-- wp:odsi-learn/platform-menu /-->' );

		$this->assertStringContainsString( 'odsi-learn-menu--header', $html );
		$this->assertStringContainsString( esc_url( get_post_type_archive_link( 'odsi_course' ) ), $html );
		$this->assertStringContainsString( esc_url( apply_filters( 'odsi_social_page_url', '', 'activity' ) ), $html );
		$this->assertStringContainsString( 'odsi-learn-menu__item--login', $html );
		$this->assertStringNotContainsString( 'odsi-learn-menu__item--notifications', $html, 'Private sections are not offered to visitors.' );
		$this->assertStringNotContainsString( 'odsi-learn-menu__item--my-courses', $html );

		wp_set_current_user( $this->lms->learner() );
		delete_transient( 'odsi_learn_my_courses_page' );
		$html = do_blocks( '<!-- wp:odsi-learn/platform-menu /-->' );

		$this->assertStringContainsString( 'odsi-learn-menu__item--logout', $html );
		$this->assertStringContainsString( 'odsi-learn-menu__item--notifications', $html );
		$this->assertStringContainsString( 'odsi-learn-menu__item--messages', $html );
		$this->assertStringNotContainsString( 'odsi-learn-menu__item--my-courses', $html, 'No dashboard page exists yet.' );

		$page = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_content' => '<!-- wp:shortcode -->[odsi_my_courses]<!-- /wp:shortcode -->',
			)
		);
		$html = do_blocks( '<!-- wp:odsi-learn/platform-menu /-->' );

		$this->assertStringContainsString( esc_url( (string) get_permalink( $page ) ), $html, 'Saving a page with the dashboard shortcode adds it to the menu.' );

		$html = do_blocks( '<!-- wp:odsi-learn/platform-menu {"variant":"footer","showAccount":false} /-->' );
		$this->assertStringContainsString( 'odsi-learn-menu--footer', $html );
		$this->assertStringNotContainsString( 'odsi-learn-menu__item--logout', $html );
		$this->assertStringNotContainsString( 'odsi-learn-menu__item--notifications', $html );
	}

	public function test_platform_menu_items_are_filterable(): void {
		add_filter(
			'odsi_learn_platform_menu_items',
			static function ( array $items ): array {
				$items[] = array(
					'key' => 'help',
					'label' => 'Help',
					'url' => 'https://example.org/help',
					'current' => false,
				);
				return $items;
			}
		);

		$html = do_blocks( '<!-- wp:odsi-learn/platform-menu /-->' );
		$this->assertStringContainsString( 'odsi-learn-menu__item--help', $html );
		$this->assertStringContainsString( 'https://example.org/help', $html );
	}

	public function test_theme_stylesheet_loads_after_both_plugins(): void {
		$this->go_to( get_post_type_archive_link( 'odsi_course' ) );
		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_style_is( 'odsi-learn', 'enqueued' ) );
		$deps = wp_styles()->registered['odsi-learn']->deps;
		$this->assertContains( 'odsi-lms', $deps );
		$this->assertContains( 'odsi-social', $deps );
	}

	public function test_course_step_pages_get_the_focus_body_class(): void {
		$course = $this->lms->standard_course();
		$this->go_to( (string) get_permalink( $course['lesson1'] ) );

		$this->assertContains( 'odsi-learn-focus', get_body_class() );

		$this->go_to( (string) get_permalink( $course['course'] ) );
		$this->assertNotContains( 'odsi-learn-focus', get_body_class() );
	}

	/**
	 * Block names in a parsed tree, recursively.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return string[]
	 */
	private function flatten( array $blocks ): array {
		$names = array();
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$names[] = (string) $block['blockName'];
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$names = array_merge( $names, $this->flatten( (array) $block['innerBlocks'] ) );
			}
		}
		return $names;
	}
}
