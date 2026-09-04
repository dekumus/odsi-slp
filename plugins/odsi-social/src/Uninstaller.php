<?php
/**
 * Uninstall routine.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social;

use ODSI\Social\Database\Migrator;
use ODSI\Social\Frontend\Router;
use ODSI\Social\Notifications\Emails;
use ODSI\Social\PostTypes\GroupPostType;
use ODSI\Social\Support\Capabilities;
use ODSI\Social\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Everything `uninstall.php` removes once the owner has opted in: the
 * community's content and settings, not only its tables.
 */
final class Uninstaller {

	/**
	 * Whether the owner asked for data to be removed on uninstall.
	 */
	public static function opted_in(): bool {
		$settings = (array) get_option( Settings::OPTION, array() );

		return ! empty( $settings['reset_data_on_uninstall'] );
	}

	/**
	 * Remove everything: content, then tables, roles and options.
	 */
	public static function run(): void {
		self::purge_content();

		Migrator::drop();
		Capabilities::uninstall();

		delete_option( Settings::OPTION );
	}

	/**
	 * Remove the content the plugin wrote outside its own tables: group
	 * posts and their meta, the images it stored, per-member preferences,
	 * transients, the rewrite flag and the cron event.
	 */
	public static function purge_content(): void {
		global $wpdb;

		// The plugin is not loaded during uninstall, so the post type is
		// registered here for the queries and deletions below.
		if ( ! post_type_exists( GroupPostType::NAME ) ) {
			( new GroupPostType() )->register();
		}

		// Group posts, any status, with their meta and the attachments hanging
		// off them. Everything runs through the core API so caches stay true.
		$groups = get_posts(
			array(
				'post_type'        => GroupPostType::NAME,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'cache_results'    => false,
				'suppress_filters' => true,
			)
		);

		foreach ( $groups as $group_id ) {
			wp_delete_post( (int) $group_id, true );
		}

		// Avatars and covers this plugin stored (marked by `_odsi_social_image`).
		$images = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'cache_results'    => false,
				'suppress_filters' => true,
				'meta_key'         => '_odsi_social_image', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- uninstall only.
			)
		);

		foreach ( $images as $attachment_id ) {
			wp_delete_attachment( (int) $attachment_id, true );
		}

		// Per-member email preference.
		delete_metadata( 'user', 0, Emails::USER_META, '', true );

		// Rate-limit and connection-cooldown transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_odsi_social_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_odsi_social_' ) . '%'
			)
		);

		if ( is_multisite() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
					$wpdb->esc_like( '_site_transient_odsi_social_' ) . '%',
					$wpdb->esc_like( '_site_transient_timeout_odsi_social_' ) . '%'
				)
			);
		}

		delete_option( Router::FLUSH_OPTION );
		wp_clear_scheduled_hook( Installer::CRON_HOOK );
		wp_cache_flush();
	}
}
