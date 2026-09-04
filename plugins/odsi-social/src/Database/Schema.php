<?php
/**
 * Custom table definitions.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Declarative schema for the plugin's custom tables.
 *
 * Every index here is justified by a named query in docs/specs/21-social-schema.md.
 * Add a column or index there first, then here, then bump DB_VERSION.
 */
final class Schema {

	/**
	 * Bumped whenever any definition below changes.
	 */
	public const DB_VERSION = '1.0.0';

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'odsi_social_db_version';

	/**
	 * Short keys mapped to unprefixed table names.
	 *
	 * @var array<string, string>
	 */
	private const TABLES = array(
		'groups'              => 'odsi_social_groups',
		'group_members'       => 'odsi_social_group_members',
		'members'             => 'odsi_social_members',
		'profile_groups'      => 'odsi_social_profile_groups',
		'profile_fields'      => 'odsi_social_profile_fields',
		'profile_data'        => 'odsi_social_profile_data',
		'connections'         => 'odsi_social_connections',
		'follows'             => 'odsi_social_follows',
		'activity'            => 'odsi_social_activity',
		'activity_meta'       => 'odsi_social_activity_meta',
		'reactions'           => 'odsi_social_reactions',
		'notifications'       => 'odsi_social_notifications',
		'threads'             => 'odsi_social_threads',
		'thread_participants' => 'odsi_social_thread_participants',
		'messages'            => 'odsi_social_messages',
	);

	/**
	 * Resolve a prefixed table name from its short key.
	 *
	 * @param string $key One of the keys in self::TABLES.
	 */
	public static function table( string $key ): string {
		global $wpdb;

		return isset( self::TABLES[ $key ] ) ? $wpdb->prefix . self::TABLES[ $key ] : '';
	}

	/**
	 * Every prefixed table name the plugin owns.
	 *
	 * @return string[]
	 */
	public static function all_tables(): array {
		return array_map( array( self::class, 'table' ), array_keys( self::TABLES ) );
	}

	/**
	 * The dbDelta-compatible CREATE TABLE statements.
	 *
	 * @return string[]
	 */
	public static function statements(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();
		$zero    = "'0000-00-00 00:00:00'";
		$s       = array();

		$t   = self::table( 'groups' );
		$s[] = "CREATE TABLE {$t} (
			post_id bigint(20) unsigned NOT NULL,
			slug varchar(191) NOT NULL DEFAULT '',
			visibility varchar(16) NOT NULL DEFAULT 'public',
			member_count int(10) unsigned NOT NULL DEFAULT 0,
			activity_count int(10) unsigned NOT NULL DEFAULT 0,
			last_active datetime NOT NULL DEFAULT {$zero},
			created_at datetime NOT NULL DEFAULT {$zero},
			PRIMARY KEY  (post_id),
			UNIQUE KEY slug (slug),
			KEY visibility_created (visibility,created_at),
			KEY visibility_members (visibility,member_count),
			KEY visibility_active (visibility,last_active)
		) {$collate};";

		$t   = self::table( 'group_members' );
		$s[] = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			group_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			role varchar(16) NOT NULL DEFAULT 'member',
			status varchar(16) NOT NULL DEFAULT 'active',
			inviter_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT {$zero},
			updated_at datetime NOT NULL DEFAULT {$zero},
			PRIMARY KEY  (id),
			UNIQUE KEY group_user (group_id,user_id),
			KEY group_status_role (group_id,status,role),
			KEY user_status (user_id,status)
		) {$collate};";

		$t   = self::table( 'members' );
		$s[] = "CREATE TABLE {$t} (
			user_id bigint(20) unsigned NOT NULL,
			last_active datetime NOT NULL DEFAULT {$zero},
			activity_count int(10) unsigned NOT NULL DEFAULT 0,
			connection_count int(10) unsigned NOT NULL DEFAULT 0,
			follower_count int(10) unsigned NOT NULL DEFAULT 0,
			following_count int(10) unsigned NOT NULL DEFAULT 0,
			avatar_id bigint(20) unsigned NOT NULL DEFAULT 0,
			cover_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message_setting varchar(16) NOT NULL DEFAULT 'anyone',
			created_at datetime NOT NULL DEFAULT {$zero},
			PRIMARY KEY  (user_id),
			KEY last_active (last_active)
		) {$collate};";

		$t   = self::table( 'profile_groups' );
		$s[] = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			description text NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY sort_order (sort_order)
		) {$collate};";

		$t   = self::table( 'profile_fields' );
		$s[] = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			group_id bigint(20) unsigned NOT NULL,
			name varchar(191) NOT NULL DEFAULT '',
			type varchar(16) NOT NULL DEFAULT 'text',
			options longtext NULL,
			required tinyint(1) NOT NULL DEFAULT 0,
			default_visibility varchar(16) NOT NULL DEFAULT 'public',
			allow_visibility_change tinyint(1) NOT NULL DEFAULT 1,
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT {$zero},
			PRIMARY KEY  (id),
			KEY group_sort (group_id,sort_order)
		) {$collate};";

		$t   = self::table( 'profile_data' );
		$s[] = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			field_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			value longtext NULL,
			visibility varchar(16) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY field_user (field_id,user_id),
			KEY user_id (user_id)
		) {$collate};";

		$t   = self::table( 'connections' );
		$s[] = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_low bigint(20) unsigned NOT NULL,
			user_high bigint(20) unsigned NOT NULL,
			initiator_id bigint(20) unsigned NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL DEFAULT {$zero},
			accepted_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY pair (user_low,user_high),
			KEY low_status (user_low,status),
			KEY high_status (user_high,status)
		) {$collate};";

		$t   = self::table( 'follows' );
		$s[] = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			follower_id bigint(20) unsigned NOT NULL,
			following_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT {$zero},
			PRIMARY KEY  (id),
			UNIQUE KEY edge (follower_id,following_id),
			KEY following_id (following_id)
		) {$collate};";

		$t   = self::table( 'activity' );
		$s[] = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			component varchar(32) NOT NULL DEFAULT 'activity',
			type varchar(32) NOT NULL DEFAULT 'update',
			content longtext NULL,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			group_id bigint(20) unsigned NOT NULL DEFAULT 0,
			primary_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			secondary_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			privacy varchar(16) NOT NULL DEFAULT 'members',
			status varchar(16) NOT NULL DEFAULT 'published',
			external_id varchar(191) NULL,
			comment_count int(10) unsigned NOT NULL DEFAULT 0,
			reaction_count int(10) unsigned NOT NULL DEFAULT 0,
			is_edited tinyint(1) NOT NULL DEFAULT 0,
			date_recorded datetime NOT NULL DEFAULT {$zero},
			date_updated datetime NOT NULL DEFAULT {$zero},
			PRIMARY KEY  (id),
			KEY feed (parent_id,status,date_recorded,id),
			KEY group_feed (group_id,parent_id,status,date_recorded,id),
			KEY user_feed (user_id,parent_id,status,date_recorded,id),
			KEY comments (parent_id,date_recorded,id),
			KEY type_feed (type,parent_id,date_recorded,id),
			UNIQUE KEY external (component,external_id),
			KEY item (component,primary_item_id)
		) {$collate};";

		$t   = self::table( 'activity_meta' );
		$s[] = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			activity_id bigint(20) unsigned NOT NULL,
			meta_key varchar(191) NOT NULL DEFAULT '',
			meta_value longtext NULL,
			PRIMARY KEY  (id),
			KEY activity_key (activity_id,meta_key(64)),
			KEY meta_key (meta_key(64))
		) {$collate};";

		$t   = self::table( 'reactions' );
		$s[] = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			activity_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			reaction varchar(16) NOT NULL DEFAULT 'like',
			created_at datetime NOT NULL DEFAULT {$zero},
			PRIMARY KEY  (id),
			UNIQUE KEY activity_user (activity_id,user_id),
			KEY user_id (user_id)
		) {$collate};";

		$t   = self::table( 'notifications' );
		$s[] = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			component varchar(32) NOT NULL DEFAULT '',
			action varchar(32) NOT NULL DEFAULT '',
			item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			secondary_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			collapse_key char(32) NULL,
			actor_count int(10) unsigned NOT NULL DEFAULT 1,
			is_new tinyint(1) NOT NULL DEFAULT 1,
			date_notified datetime NOT NULL DEFAULT {$zero},
			date_read datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_collapse (user_id,collapse_key),
			KEY user_new_date (user_id,is_new,date_notified),
			KEY component_item (component,item_id),
			KEY new_date (is_new,date_notified)
		) {$collate};";

		$t   = self::table( 'threads' );
		$s[] = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pair_key varchar(41) NULL,
			last_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
			last_message_at datetime NOT NULL DEFAULT {$zero},
			message_count int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT {$zero},
			PRIMARY KEY  (id),
			UNIQUE KEY pair_key (pair_key),
			KEY last_message_at (last_message_at)
		) {$collate};";

		$t   = self::table( 'thread_participants' );
		$s[] = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			thread_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			unread_count int(10) unsigned NOT NULL DEFAULT 0,
			is_deleted tinyint(1) NOT NULL DEFAULT 0,
			last_read_at datetime NULL,
			deleted_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY thread_user (thread_id,user_id),
			KEY user_deleted_thread (user_id,is_deleted,thread_id),
			KEY is_deleted (is_deleted)
		) {$collate};";

		$t   = self::table( 'messages' );
		$s[] = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			thread_id bigint(20) unsigned NOT NULL,
			sender_id bigint(20) unsigned NOT NULL,
			content longtext NULL,
			date_sent datetime NOT NULL DEFAULT {$zero},
			PRIMARY KEY  (id),
			KEY thread_date (thread_id,date_sent,id)
		) {$collate};";

		return $s;
	}
}
