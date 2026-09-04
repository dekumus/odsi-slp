<?php
/**
 * Community blocks render through the shortcode paths.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Social\Blocks\Blocks;
use ODSI\Tests\Integration\TestCase;
use WP_Block_Type_Registry;

final class BlocksTest extends TestCase {

	public function test_every_block_is_registered_as_dynamic(): void {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( Blocks::names() as $name ) {
			$type = $registry->get_registered( $name );
			self::assertNotNull( $type, "{$name} is registered." );
			self::assertTrue( $type->is_dynamic() );
			self::assertContains( Blocks::SCRIPT, (array) $type->editor_script_handles );
		}

		self::assertTrue( wp_script_is( Blocks::SCRIPT, 'registered' ) );
	}

	public function test_blocks_render_feed_and_directories(): void {
		$member = $this->social->member();
		$this->social->update( $member, 'Block feed post' );

		$feed = $this->as_user( $member, static fn (): string => do_blocks( '<!-- wp:odsi-social/activity-feed {"showTabs":true} /-->' ) );
		self::assertStringContainsString( 'odsi-social-feed', $feed );
		self::assertStringContainsString( 'odsi-social-feed__tabs', $feed, 'A boolean attribute reaches the shortcode as an int flag.' );
		self::assertStringContainsString( 'Block feed post', $feed );
		self::assertStringContainsString( 'wp-block-odsi-social-activity-feed', $feed );

		$members = do_blocks( '<!-- wp:odsi-social/member-directory /-->' );
		self::assertStringContainsString( 'odsi-social-directory', $members );

		$groups = do_blocks( '<!-- wp:odsi-social/group-directory /-->' );
		self::assertStringContainsString( 'odsi-social-directory--groups', $groups );
	}
}
