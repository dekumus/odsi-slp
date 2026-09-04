<?php
/**
 * Roles and capabilities.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The social capability map. Members act through services that check group
 * roles and ownership; site-level capabilities exist only for administration.
 */
final class Capabilities {

	public const MANAGE   = 'manage_odsi_social';
	public const MODERATE = 'moderate_odsi_social';

	/**
	 * Post type capability map for the group post type.
	 *
	 * @return array<string, string>
	 */
	public static function group_post_type_caps(): array {
		return array(
			'edit_post'              => 'edit_odsi_social_group',
			'read_post'              => 'read_odsi_social_group',
			'delete_post'            => 'delete_odsi_social_group',
			'edit_posts'             => 'edit_odsi_social_groups',
			'edit_others_posts'      => 'edit_others_odsi_social_groups',
			'delete_posts'           => 'delete_odsi_social_groups',
			'publish_posts'          => 'publish_odsi_social_groups',
			'read_private_posts'     => 'read_private_odsi_social_groups',
			'delete_private_posts'   => 'delete_private_odsi_social_groups',
			'delete_published_posts' => 'delete_published_odsi_social_groups',
			'delete_others_posts'    => 'delete_others_odsi_social_groups',
			'edit_private_posts'     => 'edit_private_odsi_social_groups',
			'edit_published_posts'   => 'edit_published_odsi_social_groups',
			'create_posts'           => 'edit_odsi_social_groups',
		);
	}

	/**
	 * Every capability an administrator holds.
	 *
	 * @return string[]
	 */
	public static function manager_caps(): array {
		return array_values( array_unique( array_merge( array( self::MANAGE, self::MODERATE ), array_values( self::group_post_type_caps() ) ) ) );
	}

	/**
	 * Grant capabilities to administrators.
	 */
	public static function install(): void {
		$role = get_role( 'administrator' );

		if ( ! $role instanceof \WP_Role ) {
			return;
		}

		foreach ( self::manager_caps() as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Remove capabilities. Called from uninstall only.
	 */
	public static function uninstall(): void {
		$role = get_role( 'administrator' );

		if ( ! $role instanceof \WP_Role ) {
			return;
		}

		foreach ( self::manager_caps() as $cap ) {
			$role->remove_cap( $cap );
		}
	}

	/**
	 * Whether a user administers the community.
	 *
	 * @param int $user_id User id.
	 */
	public static function is_admin( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, self::MANAGE );
	}
}
