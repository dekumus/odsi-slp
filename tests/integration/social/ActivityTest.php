<?php
/**
 * Activity writes, feeds, reactions, mentions. Spec: SOC-ACT-*.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Social\Activity\Activity;
use ODSI\Social\Activity\Feed;
use ODSI\Social\Activity\Reactions;
use ODSI\Social\Repositories\ActivityRepository;
use ODSI\Social\Support\Cursor;
use ODSI\Tests\Integration\TestCase;
use WP_Error;

final class ActivityTest extends TestCase {

	private Activity $activity;
	private Feed $feed;
	private ActivityRepository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->activity = $this->social->service( Activity::class );
		$this->feed     = $this->social->service( Feed::class );
		$this->repo     = $this->social->service( ActivityRepository::class );
	}

	public function test_act_002_empty_and_oversized_content(): void {
		$u = $this->social->member();

		self::assertInstanceOf( WP_Error::class, $this->activity->post_update( $u, '   ' ) );
		self::assertInstanceOf( WP_Error::class, $this->activity->post_update( $u, '<img src=x onerror=alert(1)>' ), 'Markup-only content strips to nothing.' );
		self::assertStringNotContainsString( '<script', $this->activity->post_update( $u, 'hi <script>alert(1)</script>', 'public' )->content );

		add_filter( 'odsi_social_activity_max_length', static fn (): int => 5 );
		$item = $this->activity->post_update( $u, 'Hello world', 'public' );
		self::assertSame( 'Hello', $item->content );
	}

	public function test_act_003_privacy_choices_and_group_override(): void {
		$u     = $this->social->member();
		$group = $this->social->group( $u, 'private' );

		self::assertInstanceOf( WP_Error::class, $this->activity->post_update( $u, 'x', 'nonsense' ) );

		$item = $this->activity->post_update( $u, 'x', 'public', $group );
		self::assertSame( 'group', $item->privacy, 'SOC-ACT-003: items in a group are always group-private.' );

		$outsider = $this->social->member();
		self::assertInstanceOf( WP_Error::class, $this->activity->post_update( $outsider, 'x', '', $group ) );
	}

	public function test_act_004_comments_reparent_and_inherit(): void {
		$u       = $this->social->member();
		$item    = $this->social->update( $u, 'root', 'members' );
		$c1      = $this->social->comment( $u, $item );
		$c2      = $this->social->comment( $u, $c1 );
		$c2_row  = $this->repo->find( $c2 );

		self::assertSame( $item, (int) $c2_row->parent_id, 'A reply to a comment attaches to the item.' );
		self::assertSame( 'members', $c2_row->privacy );
		self::assertSame( 2, (int) $this->repo->find( $item )->comment_count );
	}

	public function test_act_004_cannot_comment_on_invisible_item(): void {
		$author   = $this->social->member();
		$stranger = $this->social->member();
		$item     = $this->social->update( $author, 'secret', 'only_me' );

		$result = $this->activity->comment( $stranger, $item, 'hi' );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 404, $result->get_error_data()['status'], 'ADR-011' );
	}

	public function test_act_005_006_reactions_are_one_per_member_and_counted(): void {
		$a         = $this->social->member();
		$b         = $this->social->member();
		$item      = $this->social->update( $a );
		$reactions = $this->social->service( Reactions::class );

		self::assertTrue( $reactions->set( $b, $item ) );
		self::assertTrue( $reactions->set( $b, $item ), 'Idempotent.' );
		self::assertSame( 1, (int) $this->repo->find( $item )->reaction_count );

		self::assertTrue( $reactions->remove( $b, $item ) );
		self::assertSame( 0, (int) $this->repo->find( $item )->reaction_count );

		self::assertInstanceOf( WP_Error::class, $reactions->set( $b, $item, 'rocket' ) );
	}

	public function test_act_007_mentions_notify_only_those_who_can_see(): void {
		$author   = $this->social->member( 'author' );
		$friend   = $this->social->member( 'friend' );
		$outsider = $this->social->member( 'outsider' );
		$this->social->connect( $author, $friend );

		$mentioned = array();
		add_action(
			'odsi_social_mentioned',
			static function ( int $id ) use ( &$mentioned ): void {
				$mentioned[] = $id;
			}
		);

		$this->social->update( $author, 'Hi @friend and @outsider and @author', 'connections' );

		self::assertSame( array( $friend ), $mentioned, 'SOC-ACT-007: invisible and self mentions send nothing.' );
	}

	public function test_act_008_edit_window(): void {
		$u    = $this->social->member();
		$item = $this->social->update( $u, 'before' );

		$edited = $this->activity->edit( $u, $item, 'after' );
		self::assertSame( 'after', $edited->content );
		self::assertSame( 1, (int) $edited->is_edited );

		global $wpdb;
		$wpdb->update( $this->repo->table(), array( 'date_recorded' => '2020-01-01 00:00:00' ), array( 'id' => $item ) );

		self::assertInstanceOf( WP_Error::class, $this->activity->edit( $u, $item, 'too late' ) );

		$other = $this->social->member();
		self::assertInstanceOf( WP_Error::class, $this->activity->edit( $other, $this->social->update( $u ), 'not mine' ) );
	}

	public function test_act_009_delete_permissions_and_cascade(): void {
		$author    = $this->social->member();
		$stranger  = $this->social->member();
		$admin     = $this->social->admin();
		$organiser = $this->social->member();
		$group     = $this->social->group( $organiser, 'public' );
		$this->social->add_to_group( $group, $author );
		$reactions = $this->social->service( Reactions::class );

		$item = $this->social->update( $author, 'x', 'group', $group );
		$c    = $this->social->comment( $stranger, $item );
		$reactions->set( $stranger, $item );

		self::assertInstanceOf( WP_Error::class, $this->activity->delete( $stranger, $item ) );

		self::assertTrue( $this->activity->delete( $organiser, $item ), 'Organisers delete anything in their group.' );
		self::assertNull( $this->repo->find( $item ) );
		self::assertNull( $this->repo->find( $c ), 'Comments cascade.' );
		self::assertNull( $this->social->service( \ODSI\Social\Repositories\ReactionRepository::class )->find_for( $item, $stranger ), 'Reactions cascade.' );

		$item2 = $this->social->update( $author );
		self::assertTrue( $this->activity->delete( $admin, $item2 ) );
	}

	public function test_act_012_external_id_is_idempotent(): void {
		$u = $this->social->member();

		$a = $this->activity->post(
			array(
				'user_id' => $u,
				'component' => 'bridge',
				'type' => 'completed',
				'content' => 'done',
				'external_id' => 'course:1:user:' . $u,
			)
		);
		$b = $this->activity->post(
			array(
				'user_id' => $u,
				'component' => 'bridge',
				'type' => 'completed',
				'content' => 'done again',
				'external_id' => 'course:1:user:' . $u,
			)
		);

		self::assertSame( (int) $a->id, (int) $b->id );
		self::assertSame( 'done', $b->content );
	}

	public function test_act_030_031_feed_scopes(): void {
		$me       = $this->social->member();
		$followed = $this->social->member();
		$friend   = $this->social->member();
		$random   = $this->social->member();
		$group    = $this->social->group( $random, 'public' );
		$this->social->add_to_group( $group, $me );
		$this->social->follow( $me, $followed );
		$this->social->connect( $me, $friend );

		$mine     = $this->social->update( $me );
		$f1       = $this->social->update( $followed );
		$f2       = $this->social->update( $friend, 'x', 'connections' );
		$r        = $this->social->update( $random );
		$in_group = $this->social->update( $random, 'x', 'group', $group );

		$site     = array_column( $this->feed->page( $me, Feed::SCOPE_SITE, array( 'type' => 'update' ) )['items'], 'id' );
		$personal = array_column( $this->feed->page( $me, Feed::SCOPE_PERSONAL, array( 'type' => 'update' ) )['items'], 'id' );

		self::assertEqualsCanonicalizing( array( $mine, $f1, $f2, $r, $in_group ), $site );
		self::assertEqualsCanonicalizing( array( $mine, $f1, $f2, $in_group ), $personal, 'SOC-ACT-031' );

		$profile = array_column(
			$this->feed->page(
				$me,
				Feed::SCOPE_PROFILE,
				array(
					'user_id' => $random,
					'type' => 'update',
				)
			)['items'],
			'id'
		);
		self::assertEqualsCanonicalizing( array( $r, $in_group ), $profile );

		$g = array_column(
			$this->feed->page(
				0,
				Feed::SCOPE_GROUP,
				array(
					'group_id' => $group,
					'type' => 'update',
				)
			)['items'],
			'id'
		);
		self::assertSame( array( $in_group ), $g );
	}

	public function test_act_034_cursor_pagination_never_repeats_or_skips(): void {
		$u   = $this->social->member();
		$ids = array();

		global $wpdb;
		for ( $i = 0; $i < 7; $i++ ) {
			$ids[] = $this->social->update( $u, "post {$i}" );
		}
		// Force identical timestamps so the id tiebreaker is exercised.
		$wpdb->query( "UPDATE {$this->repo->table()} SET date_recorded = '2026-01-01 00:00:00'" ); // phpcs:ignore

		$seen   = array();
		$cursor = '';

		do {
			$page = $this->feed->page(
				$u,
				Feed::SCOPE_SITE,
				array(
					'per_page' => 3,
					'cursor' => $cursor,
				)
			);
			$seen = array_merge( $seen, array_column( $page['items'], 'id' ) );

			// A new post arriving mid-pagination must not disturb older pages.
			$this->social->update( $u, 'late arrival' );

			$cursor = $page['next_cursor'];
		} while ( '' !== $cursor );

		self::assertSame( array_reverse( $ids ), $seen );
		self::assertSame( count( $ids ), count( array_unique( $seen ) ) );
	}

	public function test_act_035_page_query_budget_holds_for_group_and_connections_items(): void {
		$me     = $this->social->member();
		$friend = $this->social->member();
		$owner  = $this->social->member();
		$this->social->connect( $me, $friend );
		$private = $this->social->group( $owner, 'private', 'Closed circle' );
		$public  = $this->social->group( $owner, 'public', 'Open circle' );
		$this->social->add_to_group( $private, $me );
		$this->social->service( \ODSI\Social\Groups\Membership::class )->join( $me, $public );

		for ( $i = 0; $i < 6; $i++ ) {
			$this->social->update( $friend, "friends only {$i}", 'connections' );
			$this->social->update( $owner, "in private {$i}", 'group', $private );
			$this->social->update( $owner, "in public {$i}", 'group', $public );
		}

		foreach ( array( \ODSI\Social\Repositories\GroupRepository::class, \ODSI\Social\Repositories\GroupMemberRepository::class, \ODSI\Social\Repositories\ConnectionRepository::class, \ODSI\Social\Repositories\MemberRepository::class ) as $repo ) {
			$this->social->service( $repo )->flush();
		}
		wp_cache_flush();

		global $wpdb;
		$before = $wpdb->num_queries;
		$page   = $this->feed->page( $me, Feed::SCOPE_SITE, array( 'per_page' => 20 ) );
		$used   = $wpdb->num_queries - $before;

		self::assertCount( 20, $page['items'] );
		self::assertContains( 'joined_group', array_column( $page['items'], 'type' ), 'A group event is on the page and rendered with its group.' );
		self::assertStringContainsString( 'Open circle', implode( ' ', array_column( $page['items'], 'action' ) ) );
		self::assertLessThanOrEqual( 14, $used, "SOC-ACT-035: {$used} queries for a mixed page (constant: viewer, page, three priming lookups, comments, reactions, users, members, group posts)." );
	}

	public function test_act_034_cursor_continues_past_a_page_the_filter_empties(): void {
		$u      = $this->social->member();
		$ids    = array();
		$hidden = array();

		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->social->update( $u, "post {$i}" );
		}

		// The two newest rows survive the SQL rule but a filter denies them,
		// so the first page of two shows nothing.
		$hidden = array_slice( $ids, 3 );
		add_filter( 'odsi_social_can_view_activity', static fn ( bool $allowed, int $viewer, object $item ): bool => $allowed && ! in_array( (int) $item->id, $hidden, true ), 10, 3 );

		$seen   = array();
		$cursor = '';
		$pages  = 0;

		do {
			$page = $this->feed->page(
				$u,
				Feed::SCOPE_SITE,
				array(
					'per_page' => 2,
					'cursor'   => $cursor,
				)
			);
			$seen   = array_merge( $seen, array_column( $page['items'], 'id' ) );
			$cursor = $page['next_cursor'];
			++$pages;
		} while ( '' !== $cursor && $pages < 10 );

		self::assertSame( array( $ids[2], $ids[1], $ids[0] ), $seen );
	}

	public function test_act_009_feed_marks_deletable_items_for_group_staff(): void {
		$owner  = $this->social->member();
		$mod    = $this->social->member();
		$member = $this->social->member();
		$group  = $this->social->group( $owner, 'public' );
		$this->social->add_to_group( $group, $mod, 'moderator' );
		$this->social->add_to_group( $group, $member );
		$item = $this->social->update( $member, 'moderate me', 'group', $group );

		$page = fn ( int $viewer ): array => array_column(
			$this->feed->page(
				$viewer,
				Feed::SCOPE_GROUP,
				array(
					'group_id' => $group,
					'type' => 'update',
				)
			)['items'],
			'can_delete',
			'id'
		);

		self::assertTrue( $page( $mod )[ $item ], 'Moderators see the Delete button.' );
		self::assertTrue( $page( $owner )[ $item ] );
		self::assertTrue( $page( $member )[ $item ], 'Authors do too.' );
		self::assertFalse( $page( $this->social->member() )[ $item ] );
	}

	public function test_act_002_backslashes_survive_a_round_trip(): void {
		$u    = $this->social->member();
		$item = $this->activity->post_update( $u, 'Path C:\\temp\\file and \\o/', 'public' );

		self::assertSame( 'Path C:\\temp\\file and \\o/', $item->content );
		self::assertSame( 'Path C:\\temp\\file and \\o/', $this->feed->item( $u, (int) $item->id )['raw_content'] );
	}

	public function test_act_004_single_item_carries_up_to_five_hundred_comments(): void {
		$u    = $this->social->member();
		$item = $this->social->update( $u );

		for ( $i = 0; $i < 105; $i++ ) {
			$this->social->comment( $u, $item, "c{$i}" );
		}

		self::assertCount( 105, $this->feed->item( $u, $item )['comments'] );
	}

	public function test_act_035_page_query_budget_is_constant(): void {
		$u = $this->social->member();

		for ( $i = 0; $i < 20; $i++ ) {
			$item = $this->social->update( $u, "p{$i}" );
			$this->social->comment( $u, $item );
			$this->social->comment( $u, $item );
		}

		global $wpdb;
		$before = $wpdb->num_queries;
		$page   = $this->feed->page( $u, Feed::SCOPE_SITE, array( 'per_page' => 20 ) );
		$used   = $wpdb->num_queries - $before;

		self::assertCount( 20, $page['items'] );
		self::assertCount( 2, $page['items'][0]['comments'] );
		self::assertLessThanOrEqual( 8, $used, "SOC-ACT-035: {$used} queries for one page." );
	}

	public function test_cursor_roundtrip(): void {
		$c = Cursor::encode( '2026-01-02 03:04:05', 42 );
		self::assertSame(
			array(
				'timestamp' => '2026-01-02 03:04:05',
				'id' => 42,
			),
			Cursor::decode( $c )
		);
		self::assertNull( Cursor::decode( 'garbage' ) );
		self::assertNull( Cursor::decode( '' ) );
	}

	public function test_act_037_reactors_list_respects_visibility(): void {
		$author = $this->social->member();
		$fan    = $this->social->member();
		$other  = $this->social->member();
		$post   = $this->social->update( $author, 'Liked post', 'connections' );
		$this->social->connect( $author, $fan );
		$this->social->service( \ODSI\Social\Activity\Reactions::class )->set( $fan, $post );

		$feed = $this->social->service( \ODSI\Social\Activity\Feed::class );
		self::assertSame( array( $fan ), array_column( (array) $feed->reactors( $author, $post ), 'id' ) );
		self::assertNull( $feed->reactors( $other, $post ), 'Strangers cannot list reactors on a connections-only post.' );
		self::assertSame( array( $fan ), array_column( (array) $feed->item( $author, $post )['reactors'], 'id' ) );

		do_action( 'rest_api_init' );
		self::assertSame( 404, $this->as_user( $other, fn () => $this->rest( 'GET', "/odsi-social/v1/activity/{$post}/reactions" ) )->get_status() );
		$ok = $this->as_user( $fan, fn () => $this->rest( 'GET', "/odsi-social/v1/activity/{$post}/reactions" ) );
		self::assertSame( 200, $ok->get_status() );
		self::assertSame( get_userdata( $fan )->display_name, $ok->get_data()['members'][0]['name'] );
	}
}
