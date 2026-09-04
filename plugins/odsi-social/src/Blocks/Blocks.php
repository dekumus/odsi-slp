<?php
/**
 * Block registration.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Blocks;

use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Frontend\Shortcodes;
use ODSI\Social\Plugin;

use const ODSI\Social\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic blocks rendered by the shortcode code paths, so both share every
 * template override.
 */
final class Blocks implements Bootable {

	public const SCRIPT = 'odsi-social-blocks';

	/**
	 * Block slug => renderer and attribute map.
	 *
	 * @var array<string, array{method: string, atts: array<string, string>}>
	 */
	private const BLOCKS = array(
		'activity-feed'    => array(
			'method' => 'render_feed',
			'atts'   => array(
				'scope'    => 'scope',
				'perPage'  => 'per_page',
				'showTabs' => 'show_tabs',
			),
		),
		'member-directory' => array(
			'method' => 'render_directory',
			'atts'   => array(),
		),
		'group-directory'  => array(
			'method' => 'render_groups',
			'atts'   => array(),
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
		add_filter( 'odsi_social_enqueue_frontend_assets', array( $this, 'enqueue_when_present' ) );
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
			(array) ( $asset['dependencies'] ?? array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ) ),
			(string) ( $asset['version'] ?? VERSION ),
			true
		);
		wp_set_script_translations( self::SCRIPT, 'odsi-social', Plugin::path() . 'languages' );

		foreach ( self::BLOCKS as $slug => $config ) {
			register_block_type_from_metadata(
				Plugin::path() . 'blocks/' . $slug,
				array(
					'render_callback' => function ( array $attributes ) use ( $config ): string {
						$atts = array();

						foreach ( $config['atts'] as $attribute => $shortcode_att ) {
							if ( isset( $attributes[ $attribute ] ) ) {
								$atts[ $shortcode_att ] = is_bool( $attributes[ $attribute ] ) ? (int) $attributes[ $attribute ] : $attributes[ $attribute ];
							}
						}

						$html = array() === $config['atts']
							? (string) call_user_func( array( $this->shortcodes, $config['method'] ) )
							: (string) call_user_func( array( $this->shortcodes, $config['method'] ), $atts );

						return '' === $html ? '' : '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
					},
				)
			);
		}//end foreach
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
			'title' => __( 'ODSI', 'odsi-social' ),
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
			if ( has_block( 'odsi-social/' . $slug ) ) {
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
		return array_map( static fn ( string $slug ): string => 'odsi-social/' . $slug, array_keys( self::BLOCKS ) );
	}
}
