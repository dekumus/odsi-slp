<?php
/**
 * Activation and schema. Spec: LMS-AUT-001 (post types), gap list item 1.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Database\Migrator;
use ODSI\LMS\Database\Schema;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\PostTypes\Taxonomies;
use ODSI\LMS\Support\Capabilities;
use ODSI\Tests\Integration\TestCase;

final class SchemaTest extends TestCase {

	public function test_every_table_exists_after_activation(): void {
		global $wpdb;

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
				self::assertSame( $unique ? '0' : '1', (string) $row['Non_unique'], "Uniqueness of {$index} on {$table}" );
				break;
			}
		}
	}

	public function expected_indexes(): array {
		return array(
			array( 'enrollments', 'user_course', true ),
			array( 'enrollments', 'course_status', false ),
			array( 'enrollments', 'user_status', false ),
			array( 'progress', 'user_object', true ),
			array( 'progress', 'user_course', false ),
			array( 'progress', 'course_status', false ),
			array( 'quiz_attempts', 'user_quiz', false ),
			array( 'quiz_answers', 'attempt_question', true ),
			array( 'certificates', 'code', true ),
		);
	}

	public function test_running_the_migrator_twice_is_a_no_op(): void {
		global $wpdb;

		$before = array();
		foreach ( Schema::all_tables() as $table ) {
			$before[ $table ] = $wpdb->get_var( "SHOW CREATE TABLE {$table}", 1 ); // phpcs:ignore
		}

		Migrator::migrate();
		self::assertFalse( Migrator::needs_migration() );

		foreach ( Schema::all_tables() as $table ) {
			self::assertSame( $before[ $table ], $wpdb->get_var( "SHOW CREATE TABLE {$table}", 1 ), "Schema of {$table} changed on re-run" ); // phpcs:ignore
		}
	}

	public function test_post_types_and_taxonomies_are_registered(): void {
		foreach ( array( PostTypes::COURSE, PostTypes::LESSON, PostTypes::TOPIC, PostTypes::QUIZ, PostTypes::QUESTION, PostTypes::CERTIFICATE, PostTypes::COHORT ) as $type ) {
			self::assertTrue( post_type_exists( $type ), $type );
		}

		foreach ( array( Taxonomies::COURSE_CATEGORY, Taxonomies::COURSE_TAG, Taxonomies::COURSE_LEVEL, Taxonomies::QUESTION_CATEGORY ) as $tax ) {
			self::assertTrue( taxonomy_exists( $tax ), $tax );
		}

		self::assertSame( 'courses', get_post_type_object( PostTypes::COURSE )->has_archive );
		self::assertFalse( get_post_type_object( PostTypes::QUESTION )->public, 'Questions have no public URL (LMS-AUT-001).' );
		self::assertFalse( get_post_type_object( PostTypes::COHORT )->public );
	}

	public function test_roles_and_capabilities_are_installed(): void {
		self::assertInstanceOf( \WP_Role::class, get_role( 'odsi_instructor' ) );
		self::assertInstanceOf( \WP_Role::class, get_role( 'odsi_student' ) );

		$admin = get_role( 'administrator' );
		self::assertTrue( $admin->has_cap( Capabilities::MANAGE ) );
		self::assertTrue( $admin->has_cap( 'edit_others_odsi_courses' ) );

		$instructor = get_role( 'odsi_instructor' );
		self::assertTrue( $instructor->has_cap( 'edit_odsi_courses' ) );
		self::assertTrue( $instructor->has_cap( Capabilities::MANAGE ) );
	}

	public function test_instructor_can_edit_own_course_but_not_anothers(): void {
		$a = $this->lms->instructor();
		$b = $this->lms->instructor();

		$course = $this->lms->course( array( 'post_author' => $a ) );

		self::assertTrue( user_can( $a, 'edit_post', $course ) );
		self::assertTrue( user_can( $a, 'edit_odsi_course', $course ) );

		// The instructor role inherits editor and is granted manage_odsi_lms, so
		// another instructor CAN edit it under the scaffold. LMS-AUT-008 requires
		// that they cannot; see the hardening brief.
		self::assertFalse( user_can( $b, 'edit_post', $course ), 'LMS-AUT-008: instructors edit only their own courses.' );
	}
}
