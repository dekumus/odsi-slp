<?php
/**
 * Regressions for the security review. Spec: ADR-011, SOC-GRP-005, SOC-MEM-004a, SOC-EDGE abuse.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Social\Activity\Feed;
use ODSI\Social\Activity\Reactions;
use ODSI\Social\Connections\Connections;
use ODSI\Social\Groups\Groups;
use ODSI\Social\Members\Profiles;
use ODSI\Social\Notifications\Notifications;
use ODSI\Social\PostTypes\GroupPostType;
use ODSI\Social\Repositories\MemberRepository;
use ODSI\Social\Support\RateLimiter;
use ODSI\Social\Support\Sanitizer;
use ODSI\Social\Support\Settings;
use ODSI\Tests\Integration\TestCase;
use WP_Error;

final class SecurityTest extends TestCase {

	private const NS = '/odsi-social/v1';

	public function set_up(): void {
		parent::set_up();
		do_action( 'rest_api_init' );
	}

	public function test_hidden_groups_are_absent_from_core_surfaces(): void {
		$organiser = $this->social->member();
		$hidden    = $this->social->group( $organiser, 'hidden', 'Secret society' );

		$type = get_post_type_object( GroupPostType::NAME );
		self::assertFalse( $type->public );
		self::assertFalse( $type->publicly_queryable );
		self::assertFalse( $type->show_in_rest );
		self::assertFalse( is_post_type_viewable( $type ) );

		self::assertSame( 404, $this->rest( 'GET', '/wp/v2/odsi_social_group' )->get_status() );
		self::assertSame( 404, $this->rest( 'GET', "/wp/v2/odsi_social_group/{$hidden}" )->get_status() );

		$stranger = $this->social->member();
		self::assertNull( $this->social->service( Groups::class )->present( $stranger, $hidden ) );
	}

	public function test_group_descriptions_never_execute_shortcodes_or_blocks(): void {
		$organiser = $this->social->member();
		$ran       = 0;
		add_shortcode(
			'odsi_test_probe',
			static function () use ( &$ran ): string {
				++$ran;

				return 'RAN';
			}
		);

		$group = $this->social->service( Groups::class )->create(
			$organiser,
			array(
				'name'        => 'Probe',
				'description' => 'Hello [odsi_test_probe] <!-- wp:latest-comments /--> [odsi_social_page]',
			)
		);

		$presented = $this->social->service( Groups::class )->present( $organiser, $group );
		self::assertSame( 0, $ran );
		self::assertStringContainsString( '[odsi_test_probe]', $presented['description'] );
		self::assertStringNotContainsString( 'RAN', $presented['description'] );
	}

	public function test_members_cannot_point_images_at_attachments_they_do_not_own(): void {
		$victim   = $this->social->member();
		$attacker = $this->social->member();
		$foreign  = $this->factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', 0 );
		wp_update_post(
			array(
				'ID'          => $foreign,
				'post_author' => $victim,
			)
		);

		$profiles = $this->social->service( Profiles::class );
		self::assertFalse( $profiles->set_avatar( $attacker, $foreign ) );
		self::assertSame( 0, (int) $this->social->service( MemberRepository::class )->ensure( $attacker )->avatar_id );
		self::assertTrue( $profiles->set_avatar( $victim, $foreign ), 'The uploader may use their own file.' );

		$patched = $this->as_user( $attacker, fn () => $this->rest( 'PATCH', self::NS . '/members/me', array( 'cover_id' => $foreign ) ) );
		self::assertSame( '', $patched->get_data()['cover'] );

		$group = $this->social->group( $attacker, 'public', 'Mine' );
		$this->social->service( Groups::class )->update( $attacker, $group, array( 'cover_id' => $foreign ) );
		self::assertSame( '', $this->social->service( Groups::class )->present( $attacker, $group )['cover'] );
	}

	public function test_rate_limits_and_connection_cooldown(): void {
		$a = $this->social->member();
		$b = $this->social->member();
		add_filter( 'odsi_social_rate_limits', static fn ( array $l ): array => array( 'activity_post' => array( 2, 60 ) ) + $l );

		self::assertSame( 201, $this->as_user( $a, fn () => $this->rest( 'POST', self::NS . '/activity', array( 'content' => 'one' ) ) )->get_status() );
		self::assertSame( 201, $this->as_user( $a, fn () => $this->rest( 'POST', self::NS . '/activity', array( 'content' => 'two' ) ) )->get_status() );
		$third = $this->as_user( $a, fn () => $this->rest( 'POST', self::NS . '/activity', array( 'content' => 'three' ) ) );
		self::assertSame( 429, $third->get_status() );
		self::assertSame( 201, $this->as_user( $b, fn () => $this->rest( 'POST', self::NS . '/activity', array( 'content' => 'other member' ) ) )->get_status() );

		RateLimiter::reset( $a );
		self::assertSame( 201, $this->as_user( $a, fn () => $this->rest( 'POST', self::NS . '/activity', array( 'content' => 'after reset' ) ) )->get_status() );

		$connections = $this->social->service( Connections::class );
		self::assertTrue( $connections->request( $a, $b ) );
		self::assertTrue( $connections->remove( $a, $b ) );
		$again = $connections->request( $a, $b );
		self::assertInstanceOf( WP_Error::class, $again );
		self::assertSame( 'odsi_social_connection_cooldown', $again->get_error_code() );
		self::assertSame( 'odsi_social_connection_cooldown', $connections->request( $b, $a )->get_error_code(), 'Neither side can restart the loop.' );
	}

	public function test_mentions_are_not_rewritten_inside_attributes(): void {
		$this->social->member( 'attacker' );

		$html = Sanitizer::render( '<a href="https://example.com/" title="hi @attacker">link</a> and @attacker in text' );
		self::assertStringContainsString( 'title="hi @attacker"', $html );
		self::assertSame( 1, substr_count( $html, 'odsi-social-mention' ) );
	}

	public function test_rest_feed_content_is_filtered_like_the_template(): void {
		$author = $this->social->member();
		$post   = $this->social->update( $author, 'plain' );
		add_filter( 'odsi_social_activity_content', static fn (): string => '<p>ok</p><script>alert(1)</script>' );

		$item = $this->social->service( Feed::class )->item( $author, $post );
		self::assertStringNotContainsString( '<script', $item['content'] );
	}

	public function test_trashed_groups_disappear_from_feeds(): void {
		$organiser = $this->social->member();
		$group     = $this->social->group( $organiser, 'public', 'Gone soon' );
		$post      = $this->social->update( $organiser, 'inside', 'public', $group );
		$viewer    = $this->social->member();

		self::assertNotNull( $this->social->service( Feed::class )->item( $viewer, $post ) );

		wp_trash_post( $group );
		self::assertNull( $this->social->service( Feed::class )->item( $viewer, $post ) );

		wp_untrash_post( $group );
		wp_publish_post( $group );
		self::assertNotNull( $this->social->service( Feed::class )->item( $viewer, $post ), 'Restoring the group restores its posts.' );
	}

	public function test_profiles_follow_the_directory_setting_for_visitors(): void {
		$member = $this->social->member();

		self::assertSame( 200, $this->rest( 'GET', self::NS . "/members/{$member}" )->get_status() );
		self::assertSame( '', $this->rest( 'GET', self::NS . "/members/{$member}" )->get_data()['last_active'], 'Visitors get no activity timestamps.' );

		$this->social->service( Settings::class )->update( array( 'public_directory' => false ) );
		self::assertSame( 401, $this->rest( 'GET', self::NS . "/members/{$member}" )->get_status() );
		self::assertSame( 200, $this->as_user( $member, fn () => $this->rest( 'GET', self::NS . "/members/{$member}" ) )->get_status() );
		$this->social->service( Settings::class )->update( array( 'public_directory' => true ) );
	}

	public function test_also_commented_skips_members_who_can_no_longer_see_the_post(): void {
		$organiser = $this->social->member();
		$removed   = $this->social->member();
		$stayer    = $this->social->member();
		$group     = $this->social->group( $organiser, 'private', 'Closed' );
		$this->social->add_to_group( $group, $removed );
		$this->social->add_to_group( $group, $stayer );

		$post = $this->social->update( $organiser, 'topic', 'public', $group );
		$this->social->comment( $removed, $post, 'first' );
		$this->social->service( \ODSI\Social\Groups\Membership::class )->remove( $organiser, $group, $removed );

		$before = $this->social->service( Notifications::class )->unread_count( $removed );
		$this->social->comment( $stayer, $post, 'second' );

		self::assertSame( $before, $this->social->service( Notifications::class )->unread_count( $removed ) );
	}
}
