<?php
/**
 * Notifications. Spec: SOC-NOT-*.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Social\Activity\Reactions;
use ODSI\Social\Connections\Connections;
use ODSI\Social\Notifications\Notifications;
use ODSI\Social\Repositories\NotificationRepository;
use ODSI\Tests\Integration\TestCase;

final class NotificationsTest extends TestCase {

	private Notifications $notifications;
	private NotificationRepository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->notifications = $this->social->service( Notifications::class );
		$this->repo          = $this->social->service( NotificationRepository::class );
	}

	public function test_not_002_never_self(): void {
		$u = $this->social->member();
		self::assertSame( 0, $this->notifications->notify( $u, $u, 'x', 'y', 1 ) );
		self::assertSame( 0, $this->notifications->unread_count( $u ) );
	}

	public function test_not_003_connection_triggers(): void {
		$a = $this->social->member();
		$b = $this->social->member();
		$c = $this->social->service( Connections::class );

		$c->request( $a, $b );
		self::assertSame( 1, $this->notifications->unread_count( $b ) );
		self::assertSame( 0, $this->notifications->unread_count( $a ) );

		$c->accept( $b, $a );
		self::assertSame( 1, $this->notifications->unread_count( $a ) );

		$list = $this->notifications->list( $a );
		self::assertSame( 'accepted', $list[0]['action'] );
		self::assertStringContainsString( 'accepted', $list[0]['text'] );

		$c->remove( $a, $b );
		self::assertSame( 1, $this->notifications->unread_count( $a ), 'Removal notifies no one.' );
	}

	public function test_not_003_comment_recipients(): void {
		$author = $this->social->member();
		$x      = $this->social->member();
		$y      = $this->social->member();
		$item   = $this->social->update( $author );

		$this->social->comment( $x, $item );
		self::assertSame( 1, $this->notifications->unread_count( $author ) );
		self::assertSame( 0, $this->notifications->unread_count( $x ) );

		$this->social->comment( $y, $item );
		self::assertSame( 1, $this->notifications->unread_count( $author ), 'SOC-NOT-004: collapsed per item.' );
		self::assertSame( 1, $this->notifications->unread_count( $x ), 'Other commenters are told.' );
		self::assertSame( 0, $this->notifications->unread_count( $y ) );

		$author_rows = $this->repo->for_user( $author );
		self::assertCount( 1, $author_rows );
		self::assertSame( 2, (int) $author_rows[0]->actor_count );
		self::assertSame( $y, (int) $author_rows[0]->actor_id, 'Most recent actor.' );
		self::assertStringContainsString( '1 other', $this->notifications->list( $author )[0]['text'] );
	}

	public function test_not_004_reading_reopens_collapse(): void {
		$author    = $this->social->member();
		$a         = $this->social->member();
		$b         = $this->social->member();
		$item      = $this->social->update( $author );
		$reactions = $this->social->service( Reactions::class );

		$reactions->set( $a, $item );
		$this->notifications->mark_read( $author );
		self::assertSame( 0, $this->notifications->unread_count( $author ) );

		$reactions->set( $b, $item );
		self::assertSame( 1, $this->notifications->unread_count( $author ) );
		self::assertCount( 2, $this->repo->for_user( $author ), 'A read row and a fresh unread row coexist.' );
	}

	public function test_not_006_deleting_the_item_deletes_notifications(): void {
		$author = $this->social->member();
		$x      = $this->social->member();
		$item   = $this->social->update( $author );
		$this->social->comment( $x, $item );
		self::assertSame( 1, $this->notifications->unread_count( $author ) );

		$this->social->service( \ODSI\Social\Activity\Activity::class )->delete( $author, $item );
		self::assertSame( 0, $this->notifications->unread_count( $author ) );
	}

	public function test_not_003_mention_and_group_triggers(): void {
		$author = $this->social->member( 'mentioner' );
		$target = $this->social->member( 'target' );
		$this->social->update( $author, 'hey @target', 'public' );
		self::assertSame( 'mentioned', $this->notifications->list( $target )[0]['action'] );

		$owner = $this->social->member();
		$u     = $this->social->member();
		$g     = $this->social->group( $owner, 'private' );
		$m     = $this->social->service( \ODSI\Social\Groups\Membership::class );

		$m->request( $u, $g );
		self::assertSame( 'requested', $this->notifications->list( $owner )[0]['action'] );

		$m->approve( $owner, $g, $u );
		self::assertSame( 'approved', $this->notifications->list( $u )[0]['action'] );
	}

	public function test_not_008_retention(): void {
		$u = $this->social->member();
		$this->notifications->notify( $u, $this->social->member(), 'x', 'old', 1 );
		$this->notifications->mark_read( $u );

		global $wpdb;
		$wpdb->query( "UPDATE {$this->repo->table()} SET date_notified = '2020-01-01 00:00:00'" ); // phpcs:ignore

		$this->notifications->notify( $u, $this->social->member(), 'x', 'unread_old', 2 );
		$wpdb->query( "UPDATE {$this->repo->table()} SET date_notified = '2020-01-01 00:00:00'" ); // phpcs:ignore

		self::assertSame( 1, $this->notifications->purge_read_older_than( 90 ) );
		self::assertCount( 1, $this->repo->for_user( $u ), 'Unread rows are kept.' );
	}

	public function test_not_001_unknown_pair_renders_a_fallback(): void {
		$u = $this->social->member();
		$this->notifications->notify( $u, $this->social->member(), 'weird', 'thing', 1 );
		self::assertNotSame( '', $this->notifications->list( $u )[0]['text'] );
	}
}
