<?php
/**
 * Taxonomy registration.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\PostTypes;

use ODSI\LMS\Contracts\Bootable;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the taxonomies used to organise courses and question banks.
 */
final class Taxonomies implements Bootable {

	public const COURSE_CATEGORY   = 'odsi_course_category';
	public const COURSE_TAG        = 'odsi_course_tag';
	public const COURSE_LEVEL      = 'odsi_course_level';
	public const QUESTION_CATEGORY = 'odsi_question_category';

	/**
	 * Register hooks.
	 *
	 * Runs before post type registration so that post types can reference the
	 * taxonomies in their own `taxonomies` argument.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register' ), 4 );
	}

	/**
	 * Register all taxonomies.
	 */
	public function register(): void {
		register_taxonomy(
			self::COURSE_CATEGORY,
			array( PostTypes::COURSE ),
			self::args(
				__( 'Course Categories', 'odsi-lms' ),
				__( 'Course Category', 'odsi-lms' ),
				array(
					'hierarchical' => true,
					'rewrite'      => array( 'slug' => 'course-category' ),
				)
			)
		);

		register_taxonomy(
			self::COURSE_TAG,
			array( PostTypes::COURSE ),
			self::args(
				__( 'Course Tags', 'odsi-lms' ),
				__( 'Course Tag', 'odsi-lms' ),
				array( 'rewrite' => array( 'slug' => 'course-tag' ) )
			)
		);

		register_taxonomy(
			self::COURSE_LEVEL,
			array( PostTypes::COURSE ),
			self::args(
				__( 'Course Levels', 'odsi-lms' ),
				__( 'Course Level', 'odsi-lms' ),
				array(
					'hierarchical' => true,
					'rewrite'      => array( 'slug' => 'course-level' ),
				)
			)
		);

		register_taxonomy(
			self::QUESTION_CATEGORY,
			array( PostTypes::QUESTION ),
			self::args(
				__( 'Question Categories', 'odsi-lms' ),
				__( 'Question Category', 'odsi-lms' ),
				array(
					'hierarchical' => true,
					'public'       => false,
					'show_ui'      => true,
					'rewrite'      => false,
				)
			)
		);
	}

	/**
	 * Build taxonomy args from a label pair plus overrides.
	 *
	 * @param string               $plural    Plural label.
	 * @param string               $singular  Singular label.
	 * @param array<string, mixed> $overrides Args merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function args( string $plural, string $singular, array $overrides = array() ): array {
		$defaults = array(
			'labels'            => array(
				'name'          => $plural,
				'singular_name' => $singular,
				'menu_name'     => $plural,
				/* translators: %s: plural taxonomy label. */
				'search_items'  => sprintf( __( 'Search %s', 'odsi-lms' ), $plural ),
				/* translators: %s: singular taxonomy label. */
				'edit_item'     => sprintf( __( 'Edit %s', 'odsi-lms' ), $singular ),
				/* translators: %s: singular taxonomy label. */
				'add_new_item'  => sprintf( __( 'Add New %s', 'odsi-lms' ), $singular ),
			),
			'public'            => true,
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'query_var'         => true,
		);

		return array_merge( $defaults, $overrides );
	}
}
