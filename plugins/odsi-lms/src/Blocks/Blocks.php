<?php
/**
 * Block registration.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Blocks;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Frontend\Shortcodes;
use ODSI\LMS\Plugin;

use const ODSI\LMS\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic blocks that render through the same code as the shortcodes, so a
 * page built in the editor and a page built with shortcodes look identical
 * and share every template override (LMS-IF-004).
 */
final class Blocks implements Bootable {

	public const SCRIPT = 'odsi-lms-blocks';

	/**
	 * Block slug => shortcode renderer and attribute map.
	 *
	 * @var array<string, array{method: string, atts: array<string, string>}>
	 */
	private const BLOCKS = array(
		'course-outline'  => array(
			'method' => 'render_outline',
			'atts'   => array( 'courseId' => 'course_id' ),
		),
		'course-progress' => array(
			'method' => 'render_progress',
			'atts'   => array( 'courseId' => 'course_id' ),
		),
		'enroll-button'   => array(
			'method' => 'render_enroll_button',
			'atts'   => array( 'courseId' => 'course_id' ),
		),
		'my-courses'      => array(
			'method' => 'render_my_courses',
			'atts'   => array( 'status' => 'status' ),
		),
		'course-grid'     => array(
			'method' => 'render_course_grid',
			'atts'   => array(
				'perPage'  => 'per_page',
				'category' => 'category',
			),
		),
	);

	/**
	 * Constructor.
	 *
	 * @param Shortcodes $shortcodes Renderers.
	 */
	public function __construct( private Shortcodes $shortcodes ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register' ), 20 );
		add_filter( 'block_categories_all', array( $this, 'category' ) );
		add_filter( 'odsi_lms_enqueue_frontend_assets', array( $this, 'enqueue_when_present' ) );
	}

	/**
	 * Register the editor script and every block.
	 */
	public function register(): void {
		$asset_file = Plugin::path() . 'assets/build/blocks.asset.php';
		$asset      = is_readable( $asset_file ) ? (array) include $asset_file : array();

		wp_register_script(
			self::SCRIPT,
			Plugin::url() . 'assets/build/blocks.js',
			(array) ( $asset['dependencies'] ?? array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-data', 'wp-i18n' ) ),
			(string) ( $asset['version'] ?? VERSION ),
			true
		);
		wp_set_script_translations( self::SCRIPT, 'odsi-lms', Plugin::path() . 'languages' );

		foreach ( self::BLOCKS as $slug => $config ) {
			register_block_type_from_metadata(
				Plugin::path() . 'blocks/' . $slug,
				array(
					'render_callback' => function ( array $attributes ) use ( $config ): string {
						$atts = array();

						foreach ( $config['atts'] as $attribute => $shortcode_att ) {
							if ( isset( $attributes[ $attribute ] ) ) {
								$atts[ $shortcode_att ] = $attributes[ $attribute ];
							}
						}

						$html = (string) call_user_func( array( $this->shortcodes, $config['method'] ), $atts );

						return '' === $html ? '' : '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
					},
				)
			);
		}
	}

	/**
	 * Add the ODSI block category once, whichever plugin registers first.
	 *
	 * @param array<int, array<string, mixed>> $categories Categories.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function category( array $categories ): array {
		foreach ( $categories as $category ) {
			if ( 'odsi' === ( $category['slug'] ?? '' ) ) {
				return $categories;
			}
		}

		$categories[] = array(
			'slug'  => 'odsi',
			'title' => __( 'ODSI', 'odsi-lms' ),
			'icon'  => null,
		);

		return $categories;
	}

	/**
	 * Load the front-end script and styles on any singular post using a block.
	 *
	 * @param bool $load Whether assets load already.
	 */
	public function enqueue_when_present( bool $load ): bool {
		if ( $load || ! is_singular() ) {
			return $load;
		}

		foreach ( array_keys( self::BLOCKS ) as $slug ) {
			if ( has_block( 'odsi-lms/' . $slug ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Registered block names.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return array_map( static fn ( string $slug ): string => 'odsi-lms/' . $slug, array_keys( self::BLOCKS ) );
	}
}
