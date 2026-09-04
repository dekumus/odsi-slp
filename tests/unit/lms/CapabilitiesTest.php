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

	public function test_instructor_caps_are_own_content_only(): void {
		$caps = Capabilities::instructor_caps();

		self::assertNotContains( Capabilities::MANAGE, $caps, 'LMS-AUT-008: instructors do not manage the LMS.' );
		self::assertContains( Capabilities::REPORT, $caps );
		self::assertContains( 'upload_files', $caps );

		foreach ( Capabilities::capability_bases() as $singular => $plural ) {
			self::assertContains( "edit_{$singular}", $caps );
			self::assertContains( "publish_{$plural}", $caps );
			self::assertNotContains( "edit_others_{$plural}", $caps, 'LMS-AUT-008' );
			self::assertNotContains( "delete_others_{$plural}", $caps );
			self::assertNotContains( "read_private_{$plural}", $caps );
		}

		self::assertSame( array_values( array_unique( $caps ) ), $caps, 'No duplicates.' );
	}

	public function test_manager_caps_cover_everything(): void {
		$caps = Capabilities::manager_caps();

		self::assertContains( Capabilities::MANAGE, $caps );

		foreach ( Capabilities::capability_bases() as $singular => $plural ) {
			foreach ( Capabilities::post_type_caps( $singular, $plural ) as $cap ) {
				self::assertContains( $cap, $caps );
			}
		}
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

		self::assertSame( 'subscriber', $roles['odsi_instructor']['inherits'], 'LMS-AUT-008: instructors get no site-content capabilities.' );
		self::assertSame( 'subscriber', $roles['odsi_student']['inherits'] );
	}
}
