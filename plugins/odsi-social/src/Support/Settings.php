<?php
/**
 * Plugin settings.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the single settings option, with defaults in one place.
 */
final class Settings {

	public const OPTION = 'odsi_social_settings';

	/**
	 * Defaults, and the only place a new setting is introduced.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'slug_members'                => 'members',
			'slug_groups'                 => 'groups',
			'slug_activity'               => 'activity',
			'slug_notifications'          => 'notifications',
			'slug_messages'               => 'messages',
			'public_directory'            => true,
			'members_can_create_groups'   => true,
			'default_privacy'             => 'members',
			'allowed_privacy'             => array( 'public', 'members', 'connections', 'only_me' ),
			'activity_max_length'         => 5000,
			'message_max_length'          => 10000,
			'edit_window_minutes'         => 60,
			'feed_per_page'               => 20,
			'directory_per_page'          => 20,
			'notification_retention_days' => 90,
			'avatar_max_px'               => 2048,
			'avatar_types'                => array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ),
			'delete_content_with_user'    => false,
			'reset_data_on_uninstall'     => false,
		);
	}

	/**
	 * Write defaults on first activation only.
	 */
	public static function seed(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key Setting key.
	 *
	 * @return mixed
	 */
	public function get( string $key ): mixed {
		$stored = (array) get_option( self::OPTION, array() );
		$all    = array_merge( self::defaults(), $stored );

		/**
		 * Filters a single social setting value.
		 *
		 * @param mixed  $value Value.
		 * @param string $key   Setting key.
		 */
		return apply_filters( 'odsi_social_setting', $all[ $key ] ?? null, $key );
	}

	/**
	 * Read a setting as an int.
	 *
	 * @param string $key Setting key.
	 */
	public function int( string $key ): int {
		return (int) $this->get( $key );
	}

	/**
	 * Read a setting as a bool.
	 *
	 * @param string $key Setting key.
	 */
	public function bool( string $key ): bool {
		return (bool) $this->get( $key );
	}

	/**
	 * Read a setting as a string.
	 *
	 * @param string $key Setting key.
	 */
	public function string( string $key ): string {
		return (string) $this->get( $key );
	}

	/**
	 * Base slug for a routed section.
	 *
	 * @param string $section `members`, `groups`, `activity`, `notifications`, `messages`.
	 */
	public function slug( string $section ): string {
		$slugs = array();

		foreach ( array( 'members', 'groups', 'activity', 'notifications', 'messages' ) as $name ) {
			$slugs[ $name ] = sanitize_title( $this->string( 'slug_' . $name ) ) ?: $name;
		}

		/**
		 * Filters the base slugs of the plugin's virtual pages.
		 *
		 * @param array<string, string> $slugs Section => slug.
		 */
		$slugs = (array) apply_filters( 'odsi_social_route_slugs', $slugs );

		return (string) ( $slugs[ $section ] ?? $section );
	}

	/**
	 * Persist a set of values, merged over what is stored.
	 *
	 * @param array<string, mixed> $values Values to write.
	 */
	public function update( array $values ): void {
		$stored = (array) get_option( self::OPTION, array() );

		update_option( self::OPTION, array_merge( self::defaults(), $stored, $values ) );
	}
}
