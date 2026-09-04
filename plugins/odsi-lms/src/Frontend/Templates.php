<?php
/**
 * Template loading.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Frontend;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Loads front-end templates, letting themes override any of them.
 *
 * A theme overrides a template by dropping a file of the same name into
 * `wp-content/themes/<theme>/odsi-lms/`, which is the convention WooCommerce
 * popularised and most WordPress developers already expect.
 */
final class Templates implements Bootable {

	/**
	 * Directory themes place overrides in.
	 */
	public const THEME_DIR = 'odsi-lms';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'template_include', array( $this, 'filter_template_include' ) );
	}

	/**
	 * Render a template and return its markup.
	 *
	 * @param string               $name Template file name, without `.php`.
	 * @param array<string, mixed> $vars Variables extracted into the template scope.
	 */
	public function render( string $name, array $vars = array() ): string {
		$file = $this->locate( $name );

		if ( '' === $file ) {
			return '';
		}

		ob_start();

		// Templates are plugin-authored PHP; the variables are the documented
		// contract between the caller and the template.
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );

		include $file;

		return (string) ob_get_clean();
	}

	/**
	 * Find a template, preferring the active theme's override.
	 *
	 * @param string $name Template file name, without `.php`.
	 *
	 * @return string Absolute path, or an empty string when not found.
	 */
	public function locate( string $name ): string {
		$name = ltrim( str_replace( array( '..', "\0" ), '', $name ), '/' );
		$file = $name . '.php';

		$theme = locate_template( array( self::THEME_DIR . '/' . $file ) );

		if ( $theme ) {
			return $theme;
		}

		$plugin = Plugin::path() . 'templates/' . $file;

		/**
		 * Filters the resolved path of an LMS template.
		 *
		 * @param string $plugin Absolute path to the template.
		 * @param string $name   Template name without extension.
		 */
		$plugin = (string) apply_filters( 'odsi_lms_locate_template', $plugin, $name );

		return is_readable( $plugin ) ? $plugin : '';
	}

	/**
	 * Fall back to the plugin's own single/archive templates when the theme has none.
	 *
	 * @param string $template Template WordPress resolved.
	 *
	 * @return string
	 */
	public function filter_template_include( string $template ): string {
		$candidate = $this->template_for_query();

		// Block themes render through the template canvas; the LMS UI reaches
		// them through the_content (ContentDecorator), never by swapping templates.
		if ( '' === $candidate || ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ) {
			return $template;
		}

		// A theme that already provides its own template wins; only fill the gap.
		if ( $this->theme_provides( $template ) ) {
			return $template;
		}

		$located = $this->locate( $candidate );

		return '' !== $located ? $located : $template;
	}

	/**
	 * Template name matching the current query, if any.
	 */
	private function template_for_query(): string {
		if ( is_post_type_archive( \ODSI\LMS\PostTypes\PostTypes::COURSE ) ) {
			return 'archive-course';
		}

		if ( is_singular( \ODSI\LMS\PostTypes\PostTypes::COURSE ) ) {
			return 'single-course';
		}

		if ( is_singular( array( \ODSI\LMS\PostTypes\PostTypes::LESSON, \ODSI\LMS\PostTypes\PostTypes::TOPIC ) ) ) {
			return 'single-lesson';
		}

		if ( is_singular( \ODSI\LMS\PostTypes\PostTypes::QUIZ ) ) {
			return 'single-quiz';
		}

		return '';
	}

	/**
	 * Whether the resolved template already lives in the theme.
	 *
	 * @param string $template Template path WordPress resolved.
	 */
	private function theme_provides( string $template ): bool {
		$basename = basename( $template );

		// `index.php` and `singular.php` are the generic fallbacks: reaching them
		// means the theme has nothing specific for this content type.
		return ! in_array( $basename, array( 'index.php', 'singular.php', 'single.php', 'archive.php' ), true );
	}
}
