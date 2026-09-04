<?php
/**
 * Template loading.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Frontend;

use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Loads front-end templates, letting themes override any of them.
 *
 * A theme overrides a template by dropping a file of the same name into
 * `wp-content/themes/<theme>/odsi-social/`, which is the convention WooCommerce
 * popularised and most WordPress developers already expect.
 */
final class Templates implements Bootable {

	/**
	 * Directory themes place overrides in.
	 */
	public const THEME_DIR = 'odsi-social';

	/**
	 * Register hooks. Routing lives in Router; this class only locates and renders.
	 */
	public function boot(): void {
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
		$plugin = (string) apply_filters( 'odsi_social_locate_template', $plugin, $name );

		return is_readable( $plugin ) ? $plugin : '';
	}
}
