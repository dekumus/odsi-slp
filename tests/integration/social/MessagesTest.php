<?php
/**
 * Private messages. Spec: SOC-MSG-*.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Social\Members\Profiles;
use ODSI\Social\Messages\Messages;
use ODSI\Social\Notifications\Notifications;
use ODSI\Tests\Integration\TestCase;
use WP_Error;

final class MessagesTest extends TestCase {

	private Messages $messages;

	public function set_up(): void {
		parent::set_up();
		$this->messages = $this->social->service( Messages::class );
	}

	public function test_msg_001_one_thread_per_pair(): void {
		$a = $this->social->member();
		$b = $this->social->member();

		$m1 = $this->messages->send( $a, $b, 'hi' );
		$m2 = $this->messages->send( $b, $a, 'hello' );

		self::assertSame( (int) $m1->thread_id, (int) $m2->thread_id );
		self::assertCount( 1, $this->messages->inbox( $a ) );
		self::assertCount( 2, $this->messages->thread( $a, (int) $m1->thread_id )['messages'] );
	}

	public function test_msg_002_recipient_setting(): void {
		$a = $this->social->member();
		$b = $this->social->member();
		$profiles = $this->social->service( Profiles::class );

		$profiles->set_message_setting( $b, 'no_one' );
		self::assertInstanceOf( WP_Error::class, $this->messages->send( $a, $b, 'x' ) );

		$profiles->set_message_setting( $b, 'connections' );
		self::assertInstanceOf( WP_Error::class, $this->messages->send( $a, $b, 'x' ) );
		$this->social->connect( $a, $b );
		self::assertIsObject( $this->messages->send( $a, $b, 'x' ) );

		self::assertInstanceOf( WP_Error::class, $this->messages->send( $a, $a, 'x' ) );
		self::assertInstanceOf( WP_Error::class, $this->messages->send( $a, $b, '   ' ) );
	}

	public function test_msg_004_unread_counts(): void {
		$a = $this->social->member();
		$b = $this->social->member();

		$m = $this->messages->send( $a, $b, 'one' );
		$this->messages->send( $a, $b, 'two' );

		self::assertSame( 2, $this->messages->unread_total( $b ) );
		self::assertSame( 0, $this->messages->unread_total( $a ) );
		self::assertSame( 1, $this->social->service( Notifications::class )->unread_count( $b ), 'Collapsed per thread.' );

		$this->messages->thread( $b, (int) $m->thread_id );
		self::assertSame( 0, $this->messages->unread_total( $b ) );
	}

	public function test_msg_005_per_participant_delete_and_restore(): void {
		$a = $this->social->member();
		$b = $this->social->member();
		$m = $this->messages->send( $a, $b, 'one' );
		$t = (int) $m->thread_id;

		self::assertTrue( $this->messages->delete( $a, $t ) );
		self::assertCount( 0, $this->messages->inbox( $a ) );
		self::assertCount( 1, $this->messages->inbox( $b ), 'The other side still sees it.' );
		self::assertInstanceOf( WP_Error::class, $this->messages->thread( $a, $t ) );

		$this->messages->send( $b, $a, 'are you there?' );
		self::assertCount( 1, $this->messages->inbox( $a ), 'A new message restores the thread.' );
		self::assertCount( 2, $this->messages->thread( $a, $t )['messages'], 'With full history.' );

		$this->messages->delete( $a, $t );
		$this->messages->delete( $b, $t );
		self::assertSame( 1, $this->messages->purge_fully_deleted() );
	}

	public function test_msg_007_and_adr_011_outsiders_get_404(): void {
		$a = $this->social->member();
		$b = $this->social->member();
		$c = $this->social->member();
		$m = $this->messages->send( $a, $b, 'private' );

		$result = $this->messages->thread( $c, (int) $m->thread_id );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 404, $result->get_error_data()['status'] );
		self::assertInstanceOf( WP_Error::class, $this->messages->reply( $c, (int) $m->thread_id, 'intrude' ) );
	}
}
