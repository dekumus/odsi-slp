<?php
/**
 * Bridge settings.
 *
 * @package ODSI\Bridge
 */

declare( strict_types = 1 );

namespace ODSI\Bridge\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Which integrations are on.
 */
final class Settings {

	public const OPTION = 'odsi_bridge_settings';

	/**
	 * Defaults.
	 *
	 * @return array<string, bool>
	 */
	public static function defaults(): array {
		return array(
			'course_activity'         => true,
			'group_linkage'           => true,
			'progress_visibility'     => true,
			'reset_data_on_uninstall' => false,
		);
	}

	/**
	 * Seed on first activation.
	 */
	public static function seed(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	/**
	 * Whether a module is enabled.
	 *
	 * @param string $module Module key.
	 */
	public function enabled( string $module ): bool {
		$stored  = (array) get_option( self::OPTION, array() );
		$all     = array_merge( self::defaults(), $stored );
		$enabled = ! empty( $all[ $module ] );

		/**
		 * Filters which bridge modules run.
		 *
		 * @param bool   $enabled Whether the module runs.
		 * @param string $module  Module key.
		 */
		return (bool) apply_filters( 'odsi_bridge_modules', $enabled, $module );
	}

	/**
	 * Persist.
	 *
	 * @param array<string, bool> $values Values.
	 */
	public function update( array $values ): void {
		update_option( self::OPTION, array_merge( self::defaults(), (array) get_option( self::OPTION, array() ), $values ) );
	}
}
