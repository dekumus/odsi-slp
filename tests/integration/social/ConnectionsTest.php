<?php
/**
 * Connections and follows. Spec: SOC-CON-*.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Social\Connections\Connections;
use ODSI\Social\Connections\Follows;
use ODSI\Social\Repositories\MemberRepository;
use ODSI\Tests\Integration\TestCase;
use WP_Error;

final class ConnectionsTest extends TestCase {

	private Connections $connections;
	private Follows $follows;

	public function set_up(): void {
		parent::set_up();
		$this->connections = $this->social->service( Connections::class );
		$this->follows     = $this->social->service( Follows::class );
	}

	public function test_state_machine_happy_path_and_symmetry(): void {
		$a = $this->social->member();
		$b = $this->social->member();

		self::assertSame( '', $this->connections->status( $a, $b ) );
		self::assertTrue( $this->connections->request( $a, $b ) );
		self::assertSame( 'pending_sent', $this->connections->status( $a, $b ) );
		self::assertSame( 'pending_received', $this->connections->status( $b, $a ) );
		self::assertSame( array( $a ), $this->connections->pending_received( $b ) );
		self::assertSame( array( $b ), $this->connections->pending_sent( $a ) );

		self::assertInstanceOf( WP_Error::class, $this->connections->accept( $a, $b ), 'The initiator cannot accept.' );
		self::assertTrue( $this->connections->accept( $b, $a ) );

		self::assertTrue( $this->connections->are_connected( $a, $b ) );
		self::assertTrue( $this->connections->are_connected( $b, $a ), 'SOC-CON-001: symmetric.' );
		self::assertSame( array( $b ), $this->connections->ids_for( $a ) );

		$members = $this->social->service( MemberRepository::class );
		self::assertSame( 1, (int) $members->find( $a )->connection_count );
		self::assertSame( 1, (int) $members->find( $b )->connection_count );
	}

	public function test_rejected_transitions(): void {
		$a = $this->social->member();
		$b = $this->social->member();

		self::assertInstanceOf( WP_Error::class, $this->connections->request( $a, $a ), 'Self.' );
		self::assertInstanceOf( WP_Error::class, $this->connections->request( $a, 999999 ), 'Unknown user.' );
		self::assertInstanceOf( WP_Error::class, $this->connections->accept( $b, $a ), 'Nothing to accept.' );

		$this->connections->request( $a, $b );
		self::assertInstanceOf( WP_Error::class, $this->connections->request( $b, $a ), 'A row already exists for the pair.' );
		self::assertInstanceOf( WP_Error::class, $this->connections->request( $a, $b ) );
	}

	public function test_withdraw_decline_and_remove(): void {
		$a = $this->social->member();
		$b = $this->social->member();
		$events = array();
		add_action(
			'odsi_social_connection_removed',
			static function ( int $x, int $y, string $prev ) use ( &$events ): void {
				$events[] = $prev;
			},
			10,
			3
		);

		// The state machine allows a new request after a withdrawal; the abuse
		// cooldown that normally spaces them out is covered by SecurityTest.
		add_filter( 'odsi_social_connection_cooldown', '__return_zero' );

		$this->connections->request( $a, $b );
		self::assertTrue( $this->connections->remove( $a, $b ) );
		self::assertSame( '', $this->connections->status( $a, $b ) );

		$this->connections->request( $a, $b );
		self::assertTrue( $this->connections->remove( $b, $a ) );

		$this->social->connect( $a, $b );
		self::assertTrue( $this->connections->remove( $b, $a ) );
		self::assertFalse( $this->connections->are_connected( $a, $b ) );

		self::assertSame( array( 'withdrawn', 'declined', 'accepted' ), $events );
		self::assertSame( 0, (int) $this->social->service( MemberRepository::class )->find( $a )->connection_count, 'SOC-CON-006: counts stay exact.' );
	}

	public function test_con_002_003_follows_are_directed_and_independent(): void {
		$a = $this->social->member();
		$b = $this->social->member();

		self::assertTrue( $this->follows->follow( $a, $b ) );
		self::assertTrue( $this->follows->follow( $a, $b ), 'Idempotent.' );
		self::assertTrue( $this->follows->is_following( $a, $b ) );
		self::assertFalse( $this->follows->is_following( $b, $a ), 'Directed.' );
		self::assertInstanceOf( WP_Error::class, $this->follows->follow( $a, $a ) );

		$this->social->connect( $a, $b );
		self::assertFalse( $this->follows->is_following( $b, $a ), 'SOC-CON-003: connecting does not follow.' );

		$members = $this->social->service( MemberRepository::class );
		self::assertSame( 1, (int) $members->find( $a )->following_count );
		self::assertSame( 1, (int) $members->find( $b )->follower_count );

		$this->follows->unfollow( $a, $b );
		self::assertSame( 0, (int) $members->find( $b )->follower_count );
	}

	public function test_deleting_a_user_cleans_edges_and_counts(): void {
		$a = $this->social->member();
		$b = $this->social->member();
		$this->social->connect( $a, $b );
		$this->social->follow( $b, $a );

		wp_delete_user( $a );

		self::assertFalse( $this->connections->are_connected( $a, $b ) );
		$members = $this->social->service( MemberRepository::class );
		self::assertSame( 0, (int) $members->find( $b )->connection_count );
		self::assertSame( 0, (int) $members->find( $b )->following_count );
		self::assertNull( $members->find( $a ), 'SOC-MEM-010: index row removed.' );
	}
}
