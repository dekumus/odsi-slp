<?php
/**
 * Blocks render through the shortcode paths. Spec: LMS-IF-004.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Blocks\Blocks;
use ODSI\Tests\Integration\TestCase;
use WP_Block_Type_Registry;

final class BlocksTest extends TestCase {

	public function test_every_block_is_registered_with_a_renderer_and_the_editor_script(): void {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( Blocks::names() as $name ) {
			$type = $registry->get_registered( $name );
			self::assertNotNull( $type, "{$name} is registered." );
			self::assertTrue( $type->is_dynamic(), "{$name} is dynamic." );
			self::assertContains( Blocks::SCRIPT, (array) $type->editor_script_handles );
			self::assertSame( 'odsi', $type->category );
		}

		self::assertTrue( wp_script_is( Blocks::SCRIPT, 'registered' ) );
	}

	public function test_blocks_render_the_same_markup_as_the_shortcodes(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );

		$grid = do_blocks( '<!-- wp:odsi-lms/course-grid {"perPage":3} /-->' );
		self::assertStringContainsString( 'odsi-lms-grid', $grid );
		self::assertStringContainsString( 'wp-block-odsi-lms-course-grid', $grid, 'The block wrapper carries the block class.' );
		self::assertStringContainsString( get_the_title( $c['course'] ), $grid );

		$outline = do_blocks( '<!-- wp:odsi-lms/course-outline {"courseId":' . $c['course'] . '} /-->' );
		self::assertStringContainsString( 'Lesson 1', $outline );
		self::assertStringContainsString( do_shortcode( '[odsi_course_outline course_id="' . $c['course'] . '"]' ), $outline );

		self::assertSame( '', do_blocks( '<!-- wp:odsi-lms/course-outline /-->' ), 'No course in context renders nothing.' );

		$mine = $this->as_user( $user, static fn (): string => do_blocks( '<!-- wp:odsi-lms/my-courses /-->' ) );
		self::assertStringContainsString( get_the_title( $c['course'] ), $mine );

		$visitor = do_blocks( '<!-- wp:odsi-lms/my-courses /-->' );
		self::assertStringContainsString( 'odsi-lms-login-required', $visitor );

		$button = do_blocks( '<!-- wp:odsi-lms/enroll-button {"courseId":' . $c['course'] . '} /-->' );
		self::assertStringContainsString( 'odsi-lms-enroll', $button );
	}

	public function test_block_category_is_added_once(): void {
		$categories = get_block_categories( get_post( $this->factory()->post->create() ) );
		$slugs      = array_column( $categories, 'slug' );

		self::assertSame( 1, count( array_keys( $slugs, 'odsi', true ) ) );
	}
}
