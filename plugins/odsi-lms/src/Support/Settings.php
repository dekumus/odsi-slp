<?php
/**
 * Plugin settings.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Support;

use ODSI\LMS\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the single settings option.
 */
final class Settings {

	/**
	 * Defaults, merged under whatever is stored.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'course_archive_slug'     => 'courses',
			'default_access_mode'     => 'free',
			'default_pass_mark'       => 80,
			'enable_certificates'     => true,
			'expiry_warning_days'     => 7,
			'enable_social_bridge'    => true,
			'email_notifications'     => true,
			'reset_data_on_uninstall' => false,
		);
	}

	/**
	 * Every setting with defaults applied.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return array_merge( self::defaults(), (array) get_option( Installer::SETTINGS_OPTION, array() ) );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): mixed {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * A boolean setting.
	 *
	 * @param string $key Key.
	 */
	public function bool( string $key ): bool {
		return (bool) $this->get( $key );
	}

	/**
	 * Merge and store values.
	 *
	 * @param array<string, mixed> $values Values.
	 */
	public function update( array $values ): void {
		update_option( Installer::SETTINGS_OPTION, array_merge( $this->all(), $values ) );
	}
}
