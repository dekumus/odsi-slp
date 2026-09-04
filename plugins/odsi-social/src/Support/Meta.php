<?php
/**
 * Meta key registry.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Support;

use ODSI\Social\PostTypes\GroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Post meta keys the plugin owns, and their REST schemas.
 */
final class Meta {

	/** Group visibility: public, private, hidden. Mirrored into the groups index. */
	public const GROUP_VISIBILITY = '_odsi_visibility';

	/** Group cover image attachment id. */
	public const GROUP_COVER_ID = '_odsi_cover_id';

	/** User who created the group. */
	public const GROUP_CREATOR_ID = '_odsi_creator_id';

	/**
	 * Register meta so the REST API exposes and sanitises it.
	 */
	public static function register(): void {
		register_post_meta(
			GroupPostType::NAME,
			self::GROUP_VISIBILITY,
			array(
				'single'            => true,
				'type'              => 'string',
				'default'           => 'public',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( $value ): string => in_array( $value, array( 'public', 'private', 'hidden' ), true ) ? (string) $value : 'public',
				'auth_callback'     => static fn (): bool => current_user_can( Capabilities::MANAGE ),
			)
		);

		register_post_meta(
			GroupPostType::NAME,
			self::GROUP_COVER_ID,
			array(
				'single'        => true,
				'type'          => 'integer',
				'default'       => 0,
				'show_in_rest'  => false,
				'auth_callback' => static fn (): bool => current_user_can( Capabilities::MANAGE ),
			)
		);

		register_post_meta(
			GroupPostType::NAME,
			self::GROUP_CREATOR_ID,
			array(
				'single'        => true,
				'type'          => 'integer',
				'default'       => 0,
				'show_in_rest'  => true,
				'auth_callback' => static fn (): bool => current_user_can( Capabilities::MANAGE ),
			)
		);
	}
}
