<?php
/**
 * Capability map unit tests.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Unit\LMS;

use ODSI\LMS\Support\Capabilities;
use ODSI\Tests\Unit\TestCase;

final class CapabilitiesTest extends TestCase {

	public function test_post_type_caps_cover_every_primitive_wordpress_expects(): void {
		$caps = Capabilities::post_type_caps( 'odsi_course', 'odsi_courses' );

		$expected_keys = array(
			'edit_post',
			'read_post',
			'delete_post',
			'edit_posts',
			'edit_others_posts',
			'delete_posts',
			'publish_posts',
			'read_private_posts',
			'delete_private_posts',
			'delete_published_posts',
			'delete_others_posts',
			'edit_private_posts',
			'edit_published_posts',
			'create_posts',
		);

		self::assertSame( $expected_keys, array_keys( $caps ) );
		self::assertSame( 'edit_odsi_course', $caps['edit_post'] );
		self::assertSame( 'edit_others_odsi_courses', $caps['edit_others_posts'] );
		self::assertSame( 'edit_odsi_courses', $caps['create_posts'], 'Creating maps onto editing so instructors need no extra grant.' );
	}

	public function test_instructor_caps_include_manage_report_and_every_post_type(): void {
		$caps = Capabilities::instructor_caps();

		self::assertContains( Capabilities::MANAGE, $caps );
		self::assertContains( Capabilities::REPORT, $caps );

		foreach ( Capabilities::capability_bases() as $singular => $plural ) {
			self::assertContains( "edit_{$singular}", $caps );
			self::assertContains( "publish_{$plural}", $caps );
		}

		self::assertSame( array_values( array_unique( $caps ) ), $caps, 'No duplicates.' );
	}

	public function test_capability_bases_cover_every_registered_post_type(): void {
		$bases = Capabilities::capability_bases();

		foreach ( array( 'odsi_course', 'odsi_lesson', 'odsi_topic', 'odsi_quiz', 'odsi_question', 'odsi_certificate', 'odsi_cohort' ) as $type ) {
			self::assertArrayHasKey( $type, $bases );
			self::assertLessThanOrEqual( 20, strlen( $type ), 'WordPress caps post type names at 20 characters.' );
		}
	}

	public function test_roles_inherit_from_core_roles(): void {
		$roles = Capabilities::roles();

		self::assertSame( 'editor', $roles['odsi_instructor']['inherits'] );
		self::assertSame( 'subscriber', $roles['odsi_student']['inherits'] );
	}
}
