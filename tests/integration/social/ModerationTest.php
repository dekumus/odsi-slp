<?php
/**
 * Blocking, reporting and the moderation queue. Spec: SOC-MOD-*.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Social\Activity\Activity;
use ODSI\Social\Activity\Feed;
use ODSI\Social\Activity\Privacy;
use ODSI\Social\Activity\Reactions;
use ODSI\Social\Admin\ModerationScreen;
use ODSI\Social\Connections\Connections;
use ODSI\Social\Connections\Follows;
use ODSI\Social\Database\Schema;
use ODSI\Social\Frontend\Forms;
use ODSI\Social\Frontend\Router;
use ODSI\Social\Frontend\Shortcodes;
use ODSI\Social\Frontend\Templates;
use ODSI\Social\Maintenance;
use ODSI\Social\Members\Blocks;
use ODSI\Social\Members\Directory;
use ODSI\Social\Members\Profiles;
use ODSI\Social\Messages\Messages;
use ODSI\Social\Moderation\Reports;
use ODSI\Social\Notifications\Emails;
use ODSI\Social\Notifications\Notifications;
use ODSI\Social\Repositories\ActivityRepository;
use ODSI\Social\Repositories\BlockRepository;
use ODSI\Social\Repositories\ConnectionRepository;
use ODSI\Social\Repositories\GroupMemberRepository;
use ODSI\Social\Repositories\GroupRepository;
use ODSI\Social\Repositories\MemberRepository;
use ODSI\Social\Repositories\NotificationRepository;
use ODSI\Social\Repositories\ReportRepository;
use ODSI\Social\Support\RateLimiter;
use ODSI\Tests\Integration\TestCase;
use WP_Error;

final class ModerationTest extends TestCase {

	private const NS = '/odsi-social/v1';

	private Blocks $blocks;
	private Reports $reports;
	private Privacy $privacy;
	private Feed $feed;

	public function set_up(): void {
		parent::set_up();
		do_action( 'rest_api_init' );
		reset_phpmailer_instance();

		$this->blocks  = $this->social->service( Blocks::class );
		$this->reports = $this->social->service( Reports::class );
		$this->privacy = $this->social->service( Privacy::class );
		$this->feed    = $this->social->service( Feed::class );
	}

	public function tear_down(): void {
		reset_phpmailer_instance();
		$this->route( '', '', '' );
		parent::tear_down();
	}

	// ---------------------------------------------------------------- schema

	public function test_schema_has_the_two_moderation_tables_and_a_bumped_version(): void {
		global $wpdb;

		self::assertTrue( version_compare( Schema::DB_VERSION, '1.0.0', '>' ), 'New tables mean a DB_VERSION bump.' );
		self::assertSame( Schema::DB_VERSION, get_option( Schema::VERSION_OPTION ) );

		foreach ( array( 'blocks', 'reports' ) as $key ) {
			$table = Schema::table( $key );
			self::assertSame( $wpdb->prefix . 'odsi_social_' . $key, $table );
			self::assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "Missing table {$table}" );
			self::assertContains( $table, Schema::all_tables(), 'Uninstall drops every table in all_tables().' );
		}

		$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . Schema::table( 'blocks' ), ARRAY_A ); // phpcs:ignore
		$pair    = array_values( array_filter( $indexes, static fn ( array $r ): bool => 'pair' === $r['Key_name'] ) );
		self::assertCount( 2, $pair, 'The pair index covers blocker and blocked.' );
		self::assertSame( '0', (string) $pair[0]['Non_unique'] );
	}

	// -------------------------------------------------------------- blocking

	public function test_mod_001_block_rules_and_hooks(): void {
		$a     = $this->social->member();
		$b     = $this->social->member();
		$admin = $this->social->admin();
		$fired = array();
		add_action(
			'odsi_social_member_blocked',
			static function ( int $x, int $y ) use ( &$fired ): void {
				$fired[] = "blocked:{$x}:{$y}";
			},
			10,
			2
		);
		add_action(
			'odsi_social_member_unblocked',
			static function ( int $x, int $y ) use ( &$fired ): void {
				$fired[] = "unblocked:{$x}:{$y}";
			},
			10,
			2
		);

		self::assertInstanceOf( WP_Error::class, $this->blocks->block( $a, $a ), 'Cannot block yourself.' );
		self::assertInstanceOf( WP_Error::class, $this->blocks->block( $a, 999999 ), 'Cannot block a ghost.' );
		self::assertInstanceOf( WP_Error::class, $this->blocks->block( 0, $b ) );

		$refused = $this->blocks->block( $a, $admin );
		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertSame( 'odsi_social_cannot_block_admin', $refused->get_error_code() );

		self::assertTrue( $this->blocks->block( $a, $b ) );
		self::assertTrue( $this->blocks->block( $a, $b ), 'Idempotent.' );
		self::assertTrue( $this->blocks->is_blocked( $a, $b ) );
		self::assertTrue( $this->blocks->is_blocked( $b, $a ), 'Either direction.' );
		self::assertSame( array( $b ), $this->blocks->blocked_ids( $a ) );
		self::assertSame( array( $a ), $this->blocks->blocked_ids( $b ) );
		self::assertSame( array( $b ), array_column( $this->blocks->blocking( $a ), 'id' ) );
		self::assertSame( array(), $this->blocks->blocking( $b ), 'The blocked member has blocked no one.' );
		self::assertSame( array( "blocked:{$a}:{$b}" ), $fired );

		self::assertTrue( $this->blocks->unblock( $a, $b ) );
		self::assertTrue( $this->blocks->unblock( $a, $b ), 'Idempotent, and fires once.' );
		self::assertFalse( $this->blocks->is_blocked( $a, $b ) );
		self::assertSame( array( "blocked:{$a}:{$b}", "unblocked:{$a}:{$b}" ), $fired );
	}

	public function test_mod_002_block_severs_connections_requests_and_follows_both_ways(): void {
		$connections = $this->social->service( Connections::class );
		$follows     = $this->social->service( Follows::class );
		$members     = $this->social->service( MemberRepository::class );

		$a = $this->social->member();
		$b = $this->social->member();
		$this->social->connect( $a, $b );
		$this->social->follow( $a, $b );
		$this->social->follow( $b, $a );
		self::assertSame( 1, (int) $members->find( $a )->connection_count );

		$this->social->block( $a, $b );

		self::assertFalse( $connections->are_connected( $a, $b ) );
		self::assertSame( Connections::NONE, $connections->status( $b, $a ) );
		self::assertFalse( $follows->is_following( $a, $b ) );
		self::assertFalse( $follows->is_following( $b, $a ) );
		self::assertSame( 0, (int) $members->find( $a )->connection_count, 'Counts follow the removal.' );
		self::assertSame( 0, (int) $members->find( $b )->follower_count );

		// A pending request in either direction goes too.
		$c = $this->social->member();
		$d = $this->social->member();
		self::assertTrue( $connections->request( $d, $c ) );
		$this->social->block( $c, $d );
		self::assertSame( array(), $connections->pending_received( $c ) );
		self::assertSame( array(), $connections->pending_sent( $d ) );
	}

	public function test_mod_003_blocked_pair_cannot_connect_follow_or_message(): void {
		$connections = $this->social->service( Connections::class );
		$follows     = $this->social->service( Follows::class );
		$messages    = $this->social->service( Messages::class );

		$a = $this->social->member();
		$b = $this->social->member();
		$c = $this->social->member();

		$thread = (int) $messages->send( $b, $a, 'before' )->thread_id;
		self::assertTrue( $connections->request( $c, $a ), 'A request from a stranger is unaffected below.' );

		$this->social->block( $a, $b );
		// The cooldown from the severed connection is not what we are testing.
		delete_transient( "odsi_social_conn_cooldown_{$a}_{$b}" );
		delete_transient( "odsi_social_conn_cooldown_{$b}_{$a}" );

		foreach ( array( array( $a, $b ), array( $b, $a ) ) as [ $x, $y ] ) {
			$request = $connections->request( $x, $y );
			self::assertInstanceOf( WP_Error::class, $request );
			self::assertSame( 'odsi_social_blocked', $request->get_error_code() );

			$follow = $follows->follow( $x, $y );
			self::assertInstanceOf( WP_Error::class, $follow );
			self::assertSame( 'odsi_social_blocked', $follow->get_error_code() );

			self::assertFalse( $messages->can_message( $x, $y ) );
			$send = $messages->send( $x, $y, 'hello?' );
			self::assertInstanceOf( WP_Error::class, $send );
			self::assertSame( 'odsi_social_cannot_message', $send->get_error_code() );
		}

		$reply = $messages->reply( $b, $thread, 'still there?' );
		self::assertInstanceOf( WP_Error::class, $reply, 'Replies in the existing thread are refused too.' );
		self::assertTrue( $messages->can_read( $a, $thread ), 'The old thread stays readable.' );

		self::assertTrue( $connections->accept( $a, $c ), 'Third parties are unaffected.' );
		self::assertSame( 201, $this->as_user( $b, fn () => $this->rest( 'POST', self::NS . "/messages/to/{$c}", array( 'content' => 'hi c' ) ) )->get_status() );
		self::assertSame( 403, $this->as_user( $b, fn () => $this->rest( 'POST', self::NS . "/messages/to/{$a}", array( 'content' => 'hi a' ) ) )->get_status() );
		self::assertSame( 403, $this->as_user( $b, fn () => $this->rest( 'POST', self::NS . "/connections/{$a}" ) )->get_status() );
		self::assertSame( 403, $this->as_user( $a, fn () => $this->rest( 'PUT', self::NS . "/follows/{$b}" ) )->get_status() );
	}

	public function test_mod_004_blocked_pair_cannot_comment_on_react_to_or_see_each_others_items(): void {
		$activity  = $this->social->service( Activity::class );
		$reactions = $this->social->service( Reactions::class );

		$a = $this->social->member();
		$b = $this->social->member();
		$c = $this->social->member();

		$by_a         = $this->social->update( $a, 'from a', 'public' );
		$by_b         = $this->social->update( $b, 'from b', 'public' );
		$by_c         = $this->social->update( $c, 'from c', 'public' );
		$b_comment    = $this->social->comment( $b, $by_c, 'b on c' );
		$a_on_b_early = $this->social->comment( $a, $by_b, 'a on b before the block' );

		self::assertTrue( $this->privacy->can_view( $b, $this->social->service( ActivityRepository::class )->find( $by_a ) ) );

		$this->social->block( $a, $b );

		$repo = $this->social->service( ActivityRepository::class );
		self::assertFalse( $this->privacy->can_view( $b, $repo->find( $by_a ) ), 'The blocked member cannot see the blocker.' );
		self::assertFalse( $this->privacy->can_view( $a, $repo->find( $by_b ) ), 'The blocker cannot see the blocked member.' );
		self::assertFalse( $this->privacy->can_view( $a, $repo->find( $b_comment ) ), "The blocked member's comment on a third party's post is hidden." );
		self::assertTrue( $this->privacy->can_view( $a, $repo->find( $by_c ) ), 'Third parties are unaffected.' );
		self::assertTrue( $this->privacy->can_view( $c, $repo->find( $by_a ) ) );
		self::assertTrue( $this->privacy->can_view( $c, $repo->find( $by_b ) ) );

		foreach ( array( array( $b, $by_a ), array( $a, $by_b ) ) as [ $actor, $item ] ) {
			$comment = $activity->comment( $actor, $item, 'x' );
			self::assertInstanceOf( WP_Error::class, $comment );
			self::assertSame( 'odsi_social_not_found', $comment->get_error_code(), 'ADR-011: 404, never 403.' );

			$react = $reactions->set( $actor, $item );
			self::assertInstanceOf( WP_Error::class, $react );
			self::assertSame( 'odsi_social_not_found', $react->get_error_code() );
		}

		self::assertInstanceOf( WP_Error::class, $reactions->set( $a, $b_comment ), 'Nor on the blocked member\'s comments elsewhere.' );

		// Single-item pages and the REST item route agree.
		self::assertNull( $this->feed->item( $b, $by_a ) );
		self::assertNull( $this->feed->item( $a, $by_b ) );
		self::assertSame( 404, $this->as_user( $b, fn () => $this->rest( 'GET', self::NS . "/activity/{$by_a}" ) )->get_status() );
		self::assertSame( 404, $this->as_user( $b, fn () => $this->rest( 'POST', self::NS . "/activity/{$by_a}/comments", array( 'content' => 'x' ) ) )->get_status() );
		self::assertSame( 404, $this->as_user( $a, fn () => $this->rest( 'PUT', self::NS . "/activity/{$by_b}/reaction" ) )->get_status() );
		self::assertSame( 404, $this->as_user( $b, fn () => $this->rest( 'GET', self::NS . "/activity/{$a_on_b_early}" ) )->get_status(), 'A comment by the blocker is gone for the blocked member.' );

		// The blocked member's comment vanishes from the third party's item for the blocker, not for others.
		$item_for_a = $this->feed->item( $a, $by_c );
		self::assertSame( array(), array_column( $item_for_a['comments'], 'id' ) );
		self::assertSame( array( $b_comment ), array_column( $this->feed->item( $c, $by_c )['comments'], 'id' ) );

		// Admins cannot be blocked and see everything, even when they blocked someone.
		$admin = $this->social->admin();
		$this->social->block( $admin, $b );
		self::assertTrue( $this->privacy->can_view( $admin, $repo->find( $by_b ) ) );
		self::assertFalse( $this->privacy->can_view( $b, $repo->find( $this->social->update( $admin, 'admin post', 'public' ) ) ) );
	}

	public function test_mod_004_feeds_hide_the_pair_in_sql_and_php_at_a_constant_query_cost(): void {
		$viewer  = $this->social->member();
		$viewer2 = $this->social->member();
		$authors = array();

		for ( $i = 0; $i < 6; $i++ ) {
			$authors[] = $this->social->member();
		}

		$posted = array();

		foreach ( $authors as $i => $author ) {
			for ( $j = 0; $j < 4; $j++ ) {
				$posted[ $author ][] = $this->social->update( $author, "a{$i} p{$j}", 'public' );
			}
		}

		// Every author comments on the first author's posts, so blocked
		// comments must be dropped from hydrated pages without a query each.
		foreach ( $posted[ $authors[0] ] as $item ) {
			foreach ( $authors as $author ) {
				$this->social->comment( $author, $item, 'c' );
			}
		}

		$this->social->block( $viewer, $authors[1] );
		$this->social->block( $authors[2], $viewer );

		foreach ( array( $authors[1], $authors[2], $authors[3], $authors[4], $authors[5] ) as $author ) {
			$this->social->block( $viewer2, $author );
		}

		// Cold repository caches, but the viewer's own user row, capabilities
		// and the autoloaded options warm, as on any real request.
		$flush = function ( int $viewer ): void {
			foreach ( array( GroupRepository::class, GroupMemberRepository::class, ConnectionRepository::class, MemberRepository::class, BlockRepository::class ) as $repo ) {
				$this->social->service( $repo )->flush();
			}
			wp_cache_flush();
			wp_load_alloptions();
			user_can( $viewer, 'read' );
		};

		$flush( $viewer );
		$page = null;
		$used = $this->social->count_queries(
			function () use ( &$page, $viewer ): void {
				$page = $this->feed->page( $viewer, Feed::SCOPE_SITE, array( 'per_page' => 20 ) );
			}
		);

		$ids     = array_column( $page['items'], 'id' );
		$hidden  = array_merge( $posted[ $authors[1] ], $posted[ $authors[2] ] );
		$visible = $posted[ $authors[0] ];

		self::assertCount( 16, $page['items'], 'Four authors × four posts; the SQL predicate excludes both blocked directions, so the page is full.' );
		self::assertSame( array(), array_intersect( $ids, $hidden ) );
		self::assertSame( array(), array_diff( $visible, $ids ) );

		foreach ( $page['items'] as $item ) {
			foreach ( $item['comments'] as $comment ) {
				self::assertNotContains( (int) $comment['author']['id'], array( $authors[1], $authors[2] ), 'Blocked commenters are dropped.' );
			}
		}

		self::assertLessThanOrEqual( 9, $used, "SOC-MOD-004: {$used} queries for a page with blocked authors and commenters (the feed budget plus one block lookup)." );

		$flush( $viewer2 );
		$used2 = $this->social->count_queries(
			function () use ( $viewer2 ): void {
				$this->feed->page( $viewer2, Feed::SCOPE_SITE, array( 'per_page' => 20 ) );
			}
		);
		self::assertSame( $used, $used2, 'The cost does not grow with the number of blocks.' );

		// The PHP representation agrees with SQL for a page the filter would otherwise pass.
		$flush( $viewer );
		$rows = $this->social->service( ActivityRepository::class )->find_many( $hidden );
		foreach ( $rows as $row ) {
			self::assertFalse( $this->privacy->can_view( $viewer, $row ) );
		}

		// The personal and profile scopes are filtered the same way.
		$profile = $this->feed->page( $viewer, Feed::SCOPE_PROFILE, array( 'user_id' => $authors[1] ) );
		self::assertSame( array(), $profile['items'] );
	}

	public function test_mod_005_profiles_are_404_both_ways_and_the_directory_excludes_both_directions(): void {
		$router    = $this->social->service( Router::class );
		$profiles  = $this->social->service( Profiles::class );
		$directory = $this->social->service( Directory::class );

		$a = $this->social->member( 'mod-blocker' );
		$b = $this->social->member( 'mod-blocked' );
		$c = $this->social->member( 'mod-bystander' );
		$this->social->block( $a, $b );

		self::assertSame( 404, $router->status_for( 'members', 'mod-blocked', '', $a ) );
		self::assertSame( 404, $router->status_for( 'members', 'mod-blocker', '', $b ) );
		self::assertSame( 200, $router->status_for( 'members', 'mod-blocker', '', $c ) );
		self::assertSame( 200, $router->status_for( 'members', 'mod-blocked', '', 0 ), 'Visitors are not party to a block.' );
		self::assertSame( 200, $router->status_for( 'members', 'mod-blocked', '', $this->social->admin() ) );

		self::assertNull( $profiles->view( $a, $b ) );
		self::assertNull( $profiles->view( $b, $a ) );
		self::assertNotNull( $profiles->view( $c, $a ) );
		self::assertSame( 404, $this->as_user( $b, fn () => $this->rest( 'GET', self::NS . "/members/{$a}" ) )->get_status() );
		self::assertSame( 404, $this->as_user( $a, fn () => $this->rest( 'GET', self::NS . "/members/{$b}" ) )->get_status() );
		self::assertSame( 200, $this->as_user( $c, fn () => $this->rest( 'GET', self::NS . "/members/{$b}" ) )->get_status() );

		$this->route( 'members', 'mod-blocker', '' );
		self::assertStringContainsString( 'odsi-social-notice--not-found', $this->as_user( $b, fn (): string => $this->social->service( Shortcodes::class )->render_page() ) );

		$ids = fn ( int $viewer, string $search ): array => array_column( $directory->query( $viewer, array( 'search' => $search ) )['members'], 'id' );
		self::assertSame( array(), $ids( $a, 'mod-blocked' ) );
		self::assertSame( array(), $ids( $b, 'mod-blocker' ) );
		self::assertSame( array( $b ), $ids( $c, 'mod-blocked' ) );
		self::assertSame( array( $b ), $ids( 0, 'mod-blocked' ) );
		self::assertSame( 0, $directory->query( $a, array( 'search' => 'mod-blocked' ) )['total'], 'The total excludes them too.' );
		self::assertSame( 2, $directory->query( $a, array( 'search' => 'mod-' ) )['total'] );
	}

	public function test_mod_006_mentions_and_notifications_from_a_blocked_member_are_suppressed(): void {
		$notifications = $this->social->service( Notifications::class );

		$a = $this->social->member( 'mod-target' );
		$b = $this->social->member( 'mod-mentioner' );
		$c = $this->social->member();
		$this->social->block( $a, $b );

		$before = $notifications->unread_count( $a );
		$item   = $this->social->update( $b, 'hey @mod-target look', 'public' );
		self::assertSame( $before, $notifications->unread_count( $a ), 'No mention notification.' );

		$html = (string) $this->feed->item( $c, $item )['content'];
		self::assertStringNotContainsString( 'odsi-social-mention', $html, 'The mention renders as text: the member cannot see the item.' );
		self::assertStringContainsString( '@mod-target', $html );

		// A shared thread elsewhere: b comments after a on c's post.
		$post = $this->social->update( $c, 'topic', 'public' );
		$this->social->comment( $a, $post, 'first' );
		$before = $notifications->unread_count( $a );
		$this->social->comment( $b, $post, 'second' );
		self::assertSame( $before, $notifications->unread_count( $a ), 'No "also commented" from a blocked member.' );

		self::assertSame( 0, $notifications->notify( $a, $b, 'connections', 'requested', $b ), 'The writer itself refuses.' );
		self::assertSame( 0, $notifications->notify( $b, $a, 'connections', 'requested', $a ), 'Either direction.' );
		self::assertGreaterThan( 0, $notifications->notify( $a, $c, 'connections', 'requested', $c ) );
		self::assertGreaterThan( 0, $notifications->notify( $a, $this->social->admin(), 'moderation', 'resolved', 1 ), 'Admins are never on the blocked side.' );
	}

	public function test_mod_001_unblocking_restores_visibility_but_not_relationships(): void {
		$a = $this->social->member();
		$b = $this->social->member();
		$this->social->connect( $a, $b );
		$item = $this->social->update( $b, 'hello', 'public' );

		$this->social->block( $a, $b );
		self::assertNull( $this->feed->item( $a, $item ) );

		$this->blocks->unblock( $a, $b );
		self::assertNotNull( $this->feed->item( $a, $item ) );
		self::assertTrue( $this->social->service( Messages::class )->can_message( $a, $b ) );
		self::assertFalse( $this->social->service( Connections::class )->are_connected( $a, $b ), 'The severed connection is not restored.' );
	}

	public function test_block_rest_routes_settings_page_and_profile_controls(): void {
		$a     = $this->social->member( 'rest-blocker' );
		$b     = $this->social->member( 'rest-blocked' );
		$admin = $this->social->admin();

		self::assertSame( 401, $this->rest( 'PUT', self::NS . "/members/{$b}/block" )->get_status() );

		$put = $this->as_user( $a, fn () => $this->rest( 'PUT', self::NS . "/members/{$b}/block" ) );
		self::assertSame( 200, $put->get_status() );
		self::assertTrue( $put->get_data()['blocked'] );
		self::assertSame( 403, $this->as_user( $a, fn () => $this->rest( 'PUT', self::NS . "/members/{$admin}/block" ) )->get_status() );
		self::assertSame( 400, $this->as_user( $a, fn () => $this->rest( 'PUT', self::NS . "/members/{$a}/block" ) )->get_status() );

		$list = $this->as_user( $a, fn () => $this->rest( 'GET', self::NS . '/members/me/blocks' ) );
		self::assertSame( array( $b ), array_column( $list->get_data()['members'], 'id' ) );
		self::assertSame( get_userdata( $b )->display_name, $list->get_data()['members'][0]['name'] );

		// The settings page lists the block with an unblock control that works without JavaScript.
		$this->route( 'members', 'rest-blocker', 'edit' );
		$html = $this->as_user( $a, fn (): string => $this->social->service( Shortcodes::class )->render_page() );
		self::assertStringContainsString( 'odsi-social-settings__blocked', $html );
		self::assertStringContainsString( get_userdata( $b )->display_name, $html );
		self::assertStringContainsString( 'name="action" value="odsi_social_unblock"', $html );
		self::assertStringContainsString( 'name="member_id" value="' . $b . '"', $html );

		$forms = $this->social->service( Forms::class );
		self::assertInstanceOf( WP_Error::class, $forms->process_unblock( $b, $a, $b ), 'Only the owner (or an admin) edits a block list.' );
		self::assertTrue( $this->blocks->is_blocked( $a, $b ) );
		self::assertTrue( $forms->process_unblock( $a, $a, $b ) );
		self::assertFalse( $this->blocks->is_blocked( $a, $b ) );

		$this->as_user( $a, fn () => $this->rest( 'PUT', self::NS . "/members/{$b}/block" ) );
		$delete = $this->as_user( $a, fn () => $this->rest( 'DELETE', self::NS . "/members/{$b}/block" ) );
		self::assertFalse( $delete->get_data()['blocked'] );
		self::assertFalse( $this->blocks->is_blocked( $a, $b ) );

		// Profile actions: block and report for members, neither on an admin's profile, nothing for visitors.
		$this->route( 'members', 'rest-blocked', '' );
		$html = $this->as_user( $a, fn (): string => $this->social->service( Shortcodes::class )->render_page() );
		self::assertStringContainsString( 'odsi-social-hero__block" data-user-id="' . $b . '"', $html );
		self::assertStringContainsString( 'data-object-type="member" data-object-id="' . $b . '"', $html );
		self::assertStringContainsString( 'odsi-social-report-form', $html, 'The profile feed carries the report form.' );

		$profile = $this->social->service( Profiles::class )->view( $a, $admin );
		$html    = $this->social->service( Templates::class )->render(
			'members/profile',
			array(
				'profile'      => $profile,
				'viewer_id'    => $a,
				'feed'         => '',
				'is_following' => false,
				'can_moderate' => false,
			)
		);
		self::assertStringNotContainsString( 'odsi-social-hero__block', $html );
		self::assertStringNotContainsString( 'odsi-social-hero__report', $html );
		self::assertStringNotContainsString( 'odsi-social-hero__block', $this->social->service( Shortcodes::class )->render_page() );
	}

	public function test_mem_010_deleting_a_user_removes_their_blocks(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$a = $this->social->member();
		$b = $this->social->member();
		$c = $this->social->member();
		$this->social->block( $a, $b );
		$this->social->block( $c, $a );

		wp_delete_user( $a );

		$repo = $this->social->service( BlockRepository::class );
		$repo->flush();
		self::assertSame( array(), $repo->ids_for( $b ) );
		self::assertSame( array(), $repo->ids_for( $c ) );
	}

	// ------------------------------------------------------------- reporting

	public function test_mod_010_report_rules_duplicates_and_hook(): void {
		$a        = $this->social->member();
		$b        = $this->social->member();
		$stranger = $this->social->member();
		$fired    = array();
		add_action(
			'odsi_social_content_reported',
			static function ( object $report ) use ( &$fired ): void {
				$fired[] = (int) $report->id;
			}
		);

		$item    = $this->social->update( $a, 'reportable', 'public' );
		$comment = $this->social->comment( $b, $item, 'a comment' );
		$secret  = $this->social->update( $a, 'mine only', 'only_me' );
		$hidden  = $this->social->group( $a, 'hidden' );
		$thread  = $this->social->service( Messages::class )->send( $a, $b, 'psst' );

		self::assertSame( 401, $this->reports->report( 0, 'activity', $item, 'spam' )->get_error_data()['status'] );
		self::assertSame( 'odsi_social_invalid_report', $this->reports->report( $b, 'course', $item, 'spam' )->get_error_code() );
		self::assertSame( 'odsi_social_invalid_reason', $this->reports->report( $b, 'activity', $item, 'ugly' )->get_error_code() );
		self::assertSame( 'odsi_social_cannot_report_self', $this->reports->report( $a, 'activity', $item, 'spam' )->get_error_code() );
		self::assertSame( 'odsi_social_cannot_report_self', $this->reports->report( $a, 'member', $a, 'spam' )->get_error_code() );

		// Visibility: what the reporter cannot see does not exist (ADR-011).
		self::assertSame( 'odsi_social_not_found', $this->reports->report( $b, 'activity', $secret, 'spam' )->get_error_code() );
		self::assertSame( 'odsi_social_not_found', $this->reports->report( $b, 'activity', 999999, 'spam' )->get_error_code() );
		self::assertSame( 'odsi_social_not_found', $this->reports->report( $b, 'group', $hidden, 'spam' )->get_error_code() );
		self::assertSame( 'odsi_social_not_found', $this->reports->report( $stranger, 'message', (int) $thread->id, 'spam' )->get_error_code() );
		self::assertSame( 'odsi_social_not_found', $this->reports->report( $b, 'member', 999999, 'spam' )->get_error_code() );

		$id = $this->reports->report( $b, 'activity', $item, 'harassment', "  Rude <b>stuff</b>\n\nhere  " );
		self::assertIsInt( $id );
		self::assertSame( $id, $this->reports->report( $b, 'activity', $item, 'spam' ), 'A repeat returns the open report.' );
		self::assertSame( array( $id ), $fired, 'The hook fires once.' );

		$row = $this->social->service( ReportRepository::class )->find( $id );
		self::assertSame( 'open', $row->status );
		self::assertSame( 'harassment', $row->reason );
		self::assertSame( "Rude stuff\n\nhere", $row->details, 'Details are plain text.' );

		self::assertNotSame( $id, $this->reports->report( $stranger, 'activity', $item, 'spam' ), 'Another member files their own.' );
		$as_comment = $this->reports->report( $stranger, 'activity', $comment, 'spam' );
		self::assertSame( 'comment', $this->social->service( ReportRepository::class )->find( $as_comment )->object_type, 'The stored type follows the row, not the caller.' );
		self::assertSame( $as_comment, $this->reports->report( $stranger, 'comment', $comment, 'other' ) );

		self::assertIsInt( $this->reports->report( $b, 'message', (int) $thread->id, 'harassment' ) );
		self::assertIsInt( $this->reports->report( $b, 'member', $a, 'harassment' ) );
		self::assertIsInt( $this->reports->report( $b, 'group', $this->social->group( $a, 'public' ), 'spam' ) );
		self::assertSame( 6, $this->reports->open_count() );

		// After dismissal the same member may report again.
		$this->reports->dismiss( $this->social->admin(), $id );
		self::assertNotSame( $id, $this->reports->report( $b, 'activity', $item, 'spam' ) );
	}

	public function test_mod_012_reports_are_rate_limited(): void {
		$reporter = $this->social->member();
		$author   = $this->social->member();
		add_filter( 'odsi_social_rate_limits', static fn ( array $l ): array => array( 'report' => array( 2, 60 ) ) + $l );

		self::assertArrayHasKey( 'report', RateLimiter::limits() );

		$first = $this->social->report( $reporter, 'activity', $this->social->update( $author, 'one' ) );
		$this->social->report( $reporter, 'activity', $this->social->update( $author, 'two' ) );
		$third = $this->reports->report( $reporter, 'activity', $this->social->update( $author, 'three' ), 'spam' );

		self::assertInstanceOf( WP_Error::class, $third );
		self::assertSame( 'odsi_social_rate_limited', $third->get_error_code() );
		self::assertSame( $first, $this->reports->report( $reporter, 'activity', (int) $this->social->service( ReportRepository::class )->find( $first )->object_id, 'spam' ), 'A repeat costs nothing.' );

		RateLimiter::reset( $reporter );
		self::assertIsInt( $this->reports->report( $reporter, 'activity', $this->social->update( $author, 'four' ), 'spam' ) );
	}

	public function test_mod_013_only_admins_review_reports(): void {
		$reporter = $this->social->member();
		$author   = $this->social->member();
		$admin    = $this->social->admin();
		$item     = $this->social->update( $author, 'meh' );
		$id       = $this->social->report( $reporter, 'activity', $item );

		foreach ( array( $reporter, $author, 0 ) as $who ) {
			self::assertSame( 'odsi_social_forbidden', $this->reports->dismiss( $who, $id )->get_error_code() );
			self::assertSame( 'odsi_social_forbidden', $this->reports->action( $who, $id, 'delete_content' )->get_error_code() );
			self::assertInstanceOf( WP_Error::class, $this->reports->list( $who ) );
		}

		self::assertSame( 'open', $this->social->service( ReportRepository::class )->find( $id )->status );
		self::assertSame( 403, $this->as_user( $reporter, fn () => $this->rest( 'GET', self::NS . '/reports' ) )->get_status() );
		self::assertSame( 403, $this->as_user( $reporter, fn () => $this->rest( 'POST', self::NS . "/reports/{$id}/dismiss" ) )->get_status() );
		self::assertSame( 403, $this->as_user( $reporter, fn () => $this->rest( 'POST', self::NS . "/reports/{$id}/action", array( 'action' => 'delete_content' ) ) )->get_status() );
		self::assertSame( 401, $this->rest( 'GET', self::NS . '/reports' )->get_status() );

		$list = $this->as_user( $admin, fn () => $this->rest( 'GET', self::NS . '/reports', array( 'status' => 'open' ) ) );
		self::assertSame( 200, $list->get_status() );
		self::assertSame( array( $id ), array_column( $list->get_data()['reports'], 'id' ) );
		self::assertSame( 1, $list->get_data()['total'] );

		self::assertSame( 'odsi_social_report_not_found', $this->reports->dismiss( $admin, 999999 )->get_error_code() );
		self::assertTrue( $this->reports->dismiss( $admin, $id ) );
		self::assertSame( 'odsi_social_report_closed', $this->reports->dismiss( $admin, $id )->get_error_code(), 'Resolved once.' );
		self::assertSame( 'odsi_social_report_closed', $this->reports->action( $admin, $id, 'delete_content' )->get_error_code() );
		self::assertSame( 409, $this->as_user( $admin, fn () => $this->rest( 'POST', self::NS . "/reports/{$id}/dismiss" ) )->get_status() );
	}

	public function test_mod_015_resolution_notifies_and_emails_the_reporter(): void {
		$reporter = $this->social->member();
		$muted    = $this->social->member();
		$author   = $this->social->member();
		$admin    = $this->social->admin();
		$item     = $this->social->update( $author, 'meh' );
		$first    = $this->social->report( $reporter, 'activity', $item );
		$second   = $this->social->report( $muted, 'activity', $item );
		Emails::set_wants_email( $muted, false );
		$resolved = array();
		add_action(
			'odsi_social_report_resolved',
			static function ( object $r, string $how ) use ( &$resolved ): void {
				$resolved[ (int) $r->id ] = $how . ':' . $r->status;
			},
			10,
			2
		);

		self::assertTrue( $this->reports->dismiss( $admin, $first ) );
		self::assertTrue( $this->reports->dismiss( $admin, $second ) );

		$row = $this->social->service( ReportRepository::class )->find( $first );
		self::assertSame( 'dismissed', $row->status );
		self::assertSame( 'dismissed', $row->resolution );
		self::assertSame( $admin, (int) $row->resolved_by );
		self::assertNotEmpty( $row->resolved_at );
		self::assertSame(
			array(
				$first => 'dismissed:dismissed',
				$second => 'dismissed:dismissed',
			),
			$resolved
		);

		$notes = $this->social->service( Notifications::class )->list( $reporter, true );
		self::assertCount( 1, $notes );
		self::assertSame( 'moderation', $notes[0]['component'] );
		self::assertSame( 'resolved', $notes[0]['action'] );
		self::assertSame( $first, $notes[0]['item_id'] );
		self::assertSame( 'A moderator has reviewed your report.', $notes[0]['text'] );

		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		self::assertCount( 1, $sent, 'One email to the reporter who wants them; none to the one who opted out.' );
		self::assertSame( get_userdata( $reporter )->user_email, $sent[0]['to'][0][0] );
		self::assertStringContainsString( 'reviewed your report', $sent[0]['subject'] );
		self::assertCount( 1, $this->social->service( Notifications::class )->list( $muted, true ), 'The in-app notification still lands.' );
	}

	public function test_mod_014_delete_content_removes_the_item_and_closes_the_report(): void {
		$reporter = $this->social->member();
		$author   = $this->social->member();
		$admin    = $this->social->admin();
		$item     = $this->social->update( $author, 'gone soon' );
		$comment  = $this->social->comment( $reporter, $item, 'seen' );
		$rid      = $this->social->report( $reporter, 'activity', $item );
		$other    = $this->social->report( $this->social->member(), 'comment', $comment );

		self::assertSame( 'odsi_social_invalid_action', $this->reports->action( $admin, $rid, 'nuke' )->get_error_code() );

		$done = $this->as_user( $admin, fn () => $this->rest( 'POST', self::NS . "/reports/{$rid}/action", array( 'action' => 'delete_content' ) ) );
		self::assertSame( 200, $done->get_status() );
		self::assertSame( 'delete_content', $done->get_data()['resolution'] );

		$repo = $this->social->service( ReportRepository::class );
		self::assertNull( $this->social->service( ActivityRepository::class )->find( $item ) );
		self::assertNull( $this->social->service( ActivityRepository::class )->find( $comment ), 'Comments cascade.' );
		self::assertSame( 'actioned', $repo->find( $rid )->status );
		self::assertSame( 'delete_content', $repo->find( $rid )->resolution );
		self::assertSame( 'actioned', $repo->find( $other )->status, 'Other open reports on the deleted content close with it.' );
		self::assertSame( 'content_deleted', $repo->find( $other )->resolution );
		self::assertCount( 1, $this->social->service( Notifications::class )->list( $reporter, true ) );

		// Content already gone by the time an admin acts: the report still closes.
		$again = $this->social->update( $author, 'twice' );
		$rid2  = $this->social->report( $reporter, 'activity', $again );
		$this->social->service( Activity::class )->delete( $author, $again );
		self::assertSame( 'actioned', $repo->find( $rid2 )->status, "The author's own deletion answers the report." );
		self::assertSame( 'content_deleted', $repo->find( $rid2 )->resolution );

		$rid3 = $this->social->report( $reporter, 'member', $author );
		self::assertSame( 'odsi_social_action_unavailable', $this->reports->action( $admin, $rid3, 'delete_content' )->get_error_code(), 'Only items and comments can be deleted this way.' );
	}

	public function test_mod_014_ban_from_group_bans_the_author_of_a_group_item(): void {
		$organiser = $this->social->member();
		$member    = $this->social->member();
		$reporter  = $this->social->member();
		$admin     = $this->social->admin();
		$group     = $this->social->group( $organiser, 'public' );
		$this->social->add_to_group( $group, $member );
		$this->social->add_to_group( $group, $reporter );

		$post    = $this->social->update( $organiser, 'topic', 'group', $group );
		$comment = $this->social->comment( $member, $post, 'nasty' );
		$rid     = $this->social->report( $reporter, 'comment', $comment );

		$presented = $this->reports->list( $admin )[0];
		self::assertSame( array( 'dismiss', 'delete_content', 'ban_from_group' ), $presented['actions'] );

		self::assertTrue( $this->reports->action( $admin, $rid, 'ban_from_group' ) );
		$members = $this->social->service( GroupMemberRepository::class );
		self::assertSame( 'banned', $members->find_for( $group, $member )->status );
		self::assertNotNull( $this->social->service( ActivityRepository::class )->find( $comment ), 'Banning does not delete.' );
		self::assertSame( 'ban_from_group', $this->social->service( ReportRepository::class )->find( $rid )->resolution );

		// An organiser cannot be banned; the report stays open.
		$rid_org = $this->social->report( $reporter, 'activity', $post );
		$refused = $this->reports->action( $admin, $rid_org, 'ban_from_group' );
		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertSame( 'open', $this->social->service( ReportRepository::class )->find( $rid_org )->status );

		// Nor does the action apply outside a group.
		$loose = $this->social->report( $reporter, 'activity', $this->social->update( $member, 'loose' ) );
		self::assertSame( 'odsi_social_action_unavailable', $this->reports->action( $admin, $loose, 'ban_from_group' )->get_error_code() );
		self::assertNotContains( 'ban_from_group', array_column( $this->reports->list( $admin ), 'actions', 'id' )[ $loose ] );
	}

	public function test_mod_016_resolved_reports_are_pruned_after_the_retention_period(): void {
		global $wpdb;

		$reporter = $this->social->member();
		$author   = $this->social->member();
		$admin    = $this->social->admin();
		$repo     = $this->social->service( ReportRepository::class );

		$old_done  = $this->social->report( $reporter, 'activity', $this->social->update( $author, 'a' ) );
		$new_done  = $this->social->report( $reporter, 'activity', $this->social->update( $author, 'b' ) );
		$old_open  = $this->social->report( $reporter, 'activity', $this->social->update( $author, 'c' ) );
		$this->reports->dismiss( $admin, $old_done );
		$this->reports->dismiss( $admin, $new_done );

		$ancient = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );
		$wpdb->update( $repo->table(), array( 'resolved_at' => $ancient ), array( 'id' => $old_done ) );
		$wpdb->update( $repo->table(), array( 'created_at' => $ancient ), array( 'id' => $old_open ) );

		$this->social->service( Maintenance::class )->run();

		self::assertNull( $repo->find( $old_done ), 'Resolved and older than the retention setting.' );
		self::assertNotNull( $repo->find( $new_done ) );
		self::assertNotNull( $repo->find( $old_open ), 'Open reports are never pruned.' );
	}

	public function test_mod_015_admin_screen_lists_open_reports_with_actions_and_a_badge(): void {
		$reporter = $this->social->member();
		$author   = $this->social->member();
		$admin    = $this->social->admin();
		$item     = $this->social->update( $author, 'Something objectionable was written here' );
		$rid      = $this->social->report( $reporter, 'activity', $item, 'inappropriate', 'Please look' );
		$screen   = $this->social->service( ModerationScreen::class );

		self::assertSame( 1, $this->reports->open_count() );

		$html = $this->as_user(
			$admin,
			static function () use ( $screen ): string {
				ob_start();
				$screen->render();

				return (string) ob_get_clean();
			}
		);

		self::assertStringContainsString( 'Something objectionable was written here', $html );
		self::assertStringContainsString( get_userdata( $reporter )->display_name, $html );
		self::assertStringContainsString( get_userdata( $author )->display_name, $html );
		self::assertStringContainsString( 'Inappropriate content', $html );
		self::assertStringContainsString( 'Please look', $html );
		self::assertStringContainsString( 'ago', $html );
		self::assertStringContainsString( 'name="do" value="dismiss"', $html );
		self::assertStringContainsString( 'name="do" value="delete_content"', $html );
		self::assertStringNotContainsString( 'value="ban_from_group"', $html, 'Not in a group.' );
		self::assertStringContainsString( 'name="report_id" value="' . $rid . '"', $html );
		self::assertStringContainsString( ModerationScreen::NONCE, $html );
		self::assertStringContainsString( 'status=dismissed', $html, 'Status filters.' );

		$this->as_user(
			$admin,
			static function () use ( $screen ): void {
				$screen->register();
			}
		);
		$labels = array_column( (array) ( $GLOBALS['submenu']['odsi-social'] ?? array() ), 0, 2 );
		self::assertArrayHasKey( ModerationScreen::SLUG, $labels );
		self::assertStringContainsString( 'awaiting-mod count-1', $labels[ ModerationScreen::SLUG ] );

		self::assertSame( 'odsi_social_forbidden', $screen->process( $reporter, $rid, 'dismiss' )->get_error_code() );
		self::assertSame( 'odsi_social_invalid_action', $screen->process( $admin, $rid, 'explode' )->get_error_code() );
		self::assertTrue( $screen->process( $admin, $rid, 'dismiss' ) );
		self::assertSame( 0, $this->reports->open_count() );

		$_GET['status'] = 'dismissed';
		$html           = $this->as_user(
			$admin,
			static function () use ( $screen ): string {
				ob_start();
				$screen->render();

				return (string) ob_get_clean();
			}
		);
		unset( $_GET['status'] );
		self::assertStringContainsString( 'Dismissed', $html );
		self::assertStringContainsString( get_userdata( $admin )->display_name, $html, 'The resolver is named.' );
		self::assertStringNotContainsString( 'name="do" value="dismiss"', $html );
	}

	public function test_report_controls_render_on_items_comments_and_groups_and_post_over_rest(): void {
		$author = $this->social->member();
		$viewer = $this->social->member();
		$group  = $this->social->group( $author, 'private', 'Reportable' );
		$item   = $this->social->update( $author, 'reportable', 'public' );
		$this->social->comment( $author, $item, 'own comment' );
		$mine = $this->social->update( $viewer, 'mine', 'public' );

		$html = $this->as_user( $viewer, fn (): string => $this->social->service( Shortcodes::class )->render_feed( array() ) );
		self::assertStringContainsString( 'data-object-type="activity" data-object-id="' . $item . '"', $html );
		self::assertStringContainsString( 'data-object-type="comment"', $html );
		self::assertStringNotContainsString( 'data-object-type="activity" data-object-id="' . $mine . '"', $html, 'Never on your own post.' );
		self::assertSame( 1, substr_count( $html, '<dialog class="odsi-social-report-dialog"' ), 'One form per page.' );
		self::assertStringContainsString( '<option value="harassment">', $html );

		self::assertStringNotContainsString( 'odsi-social-report', $this->social->service( Shortcodes::class )->render_feed( array() ), 'Visitors cannot report.' );

		$this->route( 'groups', 'reportable', '' );
		$html = $this->as_user( $viewer, fn (): string => $this->social->service( Shortcodes::class )->render_page() );
		self::assertStringContainsString( 'data-object-type="group" data-object-id="' . $group . '"', $html );
		self::assertSame( 1, substr_count( $html, '<dialog class="odsi-social-report-dialog"' ), 'A non-member still gets the form without the feed.' );

		self::assertSame(
			401,
			$this->rest(
				'POST',
				self::NS . '/reports',
				array(
					'object_type' => 'activity',
					'object_id' => $item,
					'reason' => 'spam',
				)
			)->get_status()
		);
		self::assertSame(
			400,
			$this->as_user(
				$viewer,
				fn () => $this->rest(
					'POST',
					self::NS . '/reports',
					array(
						'object_type' => 'activity',
						'object_id' => $item,
						'reason' => 'bogus',
					)
				)
			)->get_status()
		);

		$created = $this->as_user(
			$viewer,
			fn () => $this->rest(
				'POST',
				self::NS . '/reports',
				array(
					'object_type' => 'activity',
					'object_id'   => $item,
					'reason'      => 'spam',
					'details'     => 'links everywhere',
				)
			)
		);
		self::assertSame( 201, $created->get_status() );
		self::assertSame( 'links everywhere', $this->social->service( ReportRepository::class )->find( $created->get_data()['id'] )->details );
		self::assertSame(
			404,
			$this->as_user(
				$viewer,
				fn () => $this->rest(
					'POST',
					self::NS . '/reports',
					array(
						'object_type' => 'activity',
						'object_id' => 999999,
						'reason' => 'spam',
					)
				)
			)->get_status()
		);
	}

	/**
	 * Point the router at a community page.
	 */
	private function route( string $section, string $object_slug, string $action ): void {
		set_query_var( Router::QV_PAGE, $section );
		set_query_var( Router::QV_OBJECT, $object_slug );
		set_query_var( Router::QV_ACTION, $action );
	}
}
