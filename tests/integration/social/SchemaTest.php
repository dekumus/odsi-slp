<?php
/**
 * Activation and schema for the social plugin.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Social\Database\Migrator;
use ODSI\Social\Database\Schema;
use ODSI\Social\PostTypes\GroupPostType;
use ODSI\Social\Support\Capabilities;
use ODSI\Tests\Integration\TestCase;

final class SchemaTest extends TestCase {

	public function test_every_table_exists_after_activation(): void {
		global $wpdb;

		self::assertCount( 17, Schema::all_tables() );

		foreach ( Schema::all_tables() as $table ) {
			self::assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "Missing table {$table}" );
		}

		self::assertSame( Schema::DB_VERSION, get_option( Schema::VERSION_OPTION ) );
	}

	/**
	 * @dataProvider expected_indexes
	 */
	public function test_expected_indexes_exist( string $table_key, string $index, bool $unique ): void {
		global $wpdb;

		$table = Schema::table( $table_key );
		$rows  = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ); // phpcs:ignore
		$names = array_unique( array_column( $rows, 'Key_name' ) );

		self::assertContains( $index, $names, "Index {$index} missing on {$table}" );

		foreach ( $rows as $row ) {
			if ( $row['Key_name'] === $index ) {
				self::assertSame( $unique ? '0' : '1', (string) $row['Non_unique'] );
				break;
			}
		}
	}

	public function expected_indexes(): array {
		return array(
			array( 'activity', 'feed', false ),
			array( 'activity', 'group_feed', false ),
			array( 'activity', 'user_feed', false ),
			array( 'activity', 'comments', false ),
			array( 'activity', 'external', true ),
			array( 'reactions', 'activity_user', true ),
			array( 'connections', 'pair', true ),
			array( 'follows', 'edge', true ),
			array( 'group_members', 'group_user', true ),
			array( 'groups', 'slug', true ),
			array( 'notifications', 'user_collapse', true ),
			array( 'notifications', 'user_new_date', false ),
			array( 'threads', 'pair_key', true ),
			array( 'thread_participants', 'thread_user', true ),
			array( 'messages', 'thread_date', false ),
			array( 'profile_data', 'field_user', true ),
			array( 'blocks', 'pair', true ),
			array( 'blocks', 'blocked_id', false ),
			array( 'reports', 'status_created', false ),
			array( 'reports', 'reporter_object', false ),
		);
	}

	public function test_migrator_is_idempotent(): void {
		global $wpdb;

		$before = array();
		foreach ( Schema::all_tables() as $table ) {
			$before[ $table ] = $wpdb->get_var( "SHOW CREATE TABLE {$table}", 1 ); // phpcs:ignore
		}

		Migrator::migrate();

		foreach ( Schema::all_tables() as $table ) {
			self::assertSame( $before[ $table ], $wpdb->get_var( "SHOW CREATE TABLE {$table}", 1 ) ); // phpcs:ignore
		}
	}

	public function test_post_type_and_capabilities(): void {
		self::assertTrue( post_type_exists( GroupPostType::NAME ) );
		self::assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		self::assertFalse( get_role( 'subscriber' )->has_cap( Capabilities::MANAGE ) );
	}

	public function test_no_reference_to_the_lms_namespace(): void {
		$hits = array();

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/plugins/odsi-social' ) ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source scan.
			if ( 'php' === $file->getExtension() && str_contains( (string) file_get_contents( $file->getPathname() ), 'ODSI\\LMS' ) ) {
				$hits[] = $file->getPathname();
			}
		}

		self::assertSame( array(), $hits, 'ADR-005' );
	}
}
