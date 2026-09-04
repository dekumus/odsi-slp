<?php
/**
 * Group post type.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\PostTypes;

use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Groups are posts (name, description, avatar) with an index row beside them (ADR-015).
 */
final class GroupPostType implements Bootable {

	public const NAME = 'odsi_social_group';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register' ), 5 );
	}

	/**
	 * Register the post type.
	 */
	public function register(): void {
		$labels = array(
			'name'               => __( 'Groups', 'odsi-social' ),
			'singular_name'      => __( 'Group', 'odsi-social' ),
			'menu_name'          => __( 'Groups', 'odsi-social' ),
			'all_items'          => __( 'Groups', 'odsi-social' ),
			'add_new_item'       => __( 'Add New Group', 'odsi-social' ),
			'edit_item'          => __( 'Edit Group', 'odsi-social' ),
			'new_item'           => __( 'New Group', 'odsi-social' ),
			'view_item'          => __( 'View Group', 'odsi-social' ),
			'search_items'       => __( 'Search Groups', 'odsi-social' ),
			'not_found'          => __( 'No groups found.', 'odsi-social' ),
			'not_found_in_trash' => __( 'No groups found in Trash.', 'odsi-social' ),
		);

		$args = array(
			'labels'              => $labels,
			// Groups are reached only through the community router, which
			// applies the visibility rules (ADR-011). As a public post type
			// WordPress would list hidden groups in core REST, sitemaps, feeds
			// and ?odsi_social_group= queries.
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => 'odsi-social',
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'map_meta_cap'        => true,
			'capabilities'        => Capabilities::group_post_type_caps(),
			'supports'            => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			'menu_icon'           => 'dashicons-groups',
		);

		/**
		 * Filters the group post type arguments.
		 *
		 * @param array<string, mixed> $args Post type args.
		 */
		register_post_type( self::NAME, (array) apply_filters( 'odsi_social_group_post_type_args', $args ) );
	}
}
