<?php
/**
 * REST routes. Spec: SOC-IF section 7, ADR-011.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Tests\Integration\TestCase;

final class RestTest extends TestCase {

	private const NS = '/odsi-social/v1';

	public function set_up(): void {
		parent::set_up();
		do_action( 'rest_api_init' );
	}

	public function test_activity_post_read_comment_react_delete(): void {
		$u = $this->social->member();
		$v = $this->social->member();

		self::assertSame( 401, $this->rest( 'POST', self::NS . '/activity', array( 'content' => 'x' ) )->get_status() );

		$post = $this->as_user(
			$u,
			fn () => $this->rest(
				'POST',
				self::NS . '/activity',
				array(
					'content' => 'Hello <b>world</b>',
					'privacy' => 'public',
				)
			)
		);
		self::assertSame( 201, $post->get_status() );
		$id = $post->get_data()['id'];
		self::assertStringContainsString( '<b>world</b>', $post->get_data()['content'] );

		$feed = $this->rest( 'GET', self::NS . '/activity' );
		self::assertSame( 200, $feed->get_status() );
		self::assertContains( $id, array_column( $feed->get_data()['items'], 'id' ) );

		$comment = $this->as_user( $v, fn () => $this->rest( 'POST', self::NS . "/activity/{$id}/comments", array( 'content' => 'nice' ) ) );
		self::assertSame( 201, $comment->get_status() );

		$react = $this->as_user( $v, fn () => $this->rest( 'PUT', self::NS . "/activity/{$id}/reaction", array( 'type' => 'like' ) ) );
		self::assertSame( 200, $react->get_status() );

		$single = $this->as_user( $v, fn () => $this->rest( 'GET', self::NS . "/activity/{$id}" ) );
		self::assertSame( 1, $single->get_data()['reaction_count'] );
		self::assertSame( 'like', $single->get_data()['viewer_reaction'] );
		self::assertCount( 1, $single->get_data()['comments'] );

		self::assertSame( 403, $this->as_user( $v, fn () => $this->rest( 'DELETE', self::NS . "/activity/{$id}" ) )->get_status() );
		self::assertSame( 200, $this->as_user( $u, fn () => $this->rest( 'DELETE', self::NS . "/activity/{$id}" ) )->get_status() );
		self::assertSame( 404, $this->rest( 'GET', self::NS . "/activity/{$id}" )->get_status() );
	}

	public function test_hidden_content_is_404_not_403(): void {
		$u        = $this->social->member();
		$stranger = $this->social->member();
		$private  = $this->social->update( $u, 'secret', 'only_me' );
		$hidden   = $this->social->group( $u, 'hidden' );

		self::assertSame( 404, $this->as_user( $stranger, fn () => $this->rest( 'GET', self::NS . "/activity/{$private}" ) )->get_status() );
		self::assertSame( 404, $this->as_user( $stranger, fn () => $this->rest( 'POST', self::NS . "/activity/{$private}/comments", array( 'content' => 'x' ) ) )->get_status() );
		self::assertSame( 404, $this->as_user( $stranger, fn () => $this->rest( 'GET', self::NS . "/groups/{$hidden}" ) )->get_status() );
		self::assertSame( 404, $this->as_user( $stranger, fn () => $this->rest( 'POST', self::NS . "/groups/{$hidden}/membership" ) )->get_status() );
		self::assertSame( 200, $this->as_user( $u, fn () => $this->rest( 'GET', self::NS . "/groups/{$hidden}" ) )->get_status() );
	}

	public function test_groups_flow(): void {
		$owner = $this->social->member();
		$u     = $this->social->member();

		$create = $this->as_user(
			$owner,
			fn () => $this->rest(
				'POST',
				self::NS . '/groups',
				array(
					'name' => 'Readers',
					'visibility' => 'private',
				)
			)
		);
		self::assertSame( 201, $create->get_status() );
		$gid = $create->get_data()['id'];
		self::assertSame( 'organiser', $create->get_data()['viewer']['role'] );

		$dir = $this->rest( 'GET', self::NS . '/groups' );
		self::assertContains( $gid, array_column( $dir->get_data()['groups'], 'id' ) );

		$req = $this->as_user( $u, fn () => $this->rest( 'POST', self::NS . "/groups/{$gid}/membership" ) );
		self::assertSame( 'pending', $req->get_data()['viewer']['status'] );

		self::assertSame( 403, $this->as_user( $u, fn () => $this->rest( 'GET', self::NS . "/groups/{$gid}/members", array( 'status' => 'pending' ) ) )->get_status() );

		$approve = $this->as_user( $owner, fn () => $this->rest( 'POST', self::NS . "/groups/{$gid}/members/{$u}", array( 'action' => 'approve' ) ) );
		self::assertSame( 'active', $approve->get_data()['status'] );

		$members = $this->as_user( $u, fn () => $this->rest( 'GET', self::NS . "/groups/{$gid}/members" ) );
		self::assertSame( 2, $members->get_data()['total'] );

		self::assertSame( 403, $this->as_user( $u, fn () => $this->rest( 'PATCH', self::NS . "/groups/{$gid}", array( 'name' => 'Hijacked' ) ) )->get_status() );
		self::assertSame( 200, $this->as_user( $u, fn () => $this->rest( 'DELETE', self::NS . "/groups/{$gid}/membership" ) )->get_status() );
	}

	public function test_connections_and_follows(): void {
		$a = $this->social->member();
		$b = $this->social->member();

		self::assertSame( 201, $this->as_user( $a, fn () => $this->rest( 'POST', self::NS . "/connections/{$b}" ) )->get_status() );
		self::assertSame( 'pending_received', $this->as_user( $b, fn () => $this->rest( 'GET', self::NS . '/connections' ) )->get_data()['pending_received'] ? 'pending_received' : '' );
		self::assertSame( 'accepted', $this->as_user( $b, fn () => $this->rest( 'POST', self::NS . "/connections/{$a}/accept" ) )->get_data()['status'] );
		self::assertSame( 200, $this->as_user( $a, fn () => $this->rest( 'PUT', self::NS . "/follows/{$b}" ) )->get_status() );
		self::assertSame( array( $b ), $this->as_user( $a, fn () => $this->rest( 'GET', self::NS . '/connections' ) )->get_data()['following'] );
	}

	public function test_notifications_and_messages(): void {
		$a = $this->social->member();
		$b = $this->social->member();

		$send = $this->as_user( $a, fn () => $this->rest( 'POST', self::NS . "/messages/to/{$b}", array( 'content' => 'hi' ) ) );
		self::assertSame( 201, $send->get_status() );
		$thread = $send->get_data()['thread_id'];

		$inbox = $this->as_user( $b, fn () => $this->rest( 'GET', self::NS . '/messages' ) );
		self::assertSame( 1, $inbox->get_data()['unread_total'] );

		$notes = $this->as_user( $b, fn () => $this->rest( 'GET', self::NS . '/notifications' ) );
		self::assertSame( 1, $notes->get_data()['unread_count'] );
		self::assertSame( 'new', $notes->get_data()['notifications'][0]['action'] );

		self::assertSame( 0, $this->as_user( $b, fn () => $this->rest( 'POST', self::NS . '/notifications/read' ) )->get_data()['unread_count'] );

		$c = $this->social->member();
		self::assertSame( 404, $this->as_user( $c, fn () => $this->rest( 'GET', self::NS . "/messages/{$thread}" ) )->get_status() );
		self::assertSame( 201, $this->as_user( $b, fn () => $this->rest( 'POST', self::NS . "/messages/{$thread}", array( 'content' => 'yo' ) ) )->get_status() );
	}

	public function test_members_directory_and_profile_update(): void {
		$u = $this->social->member( 'restuser' );
		$this->social->service( \ODSI\Social\Members\Presence::class )->touch( $u, true );

		$dir = $this->rest( 'GET', self::NS . '/members', array( 'search' => 'restuser' ) );
		self::assertSame( 200, $dir->get_status() );
		self::assertSame( $u, $dir->get_data()['members'][0]['id'] );

		$me = $this->as_user( $u, fn () => $this->rest( 'PATCH', self::NS . '/members/me', array( 'message_setting' => 'no_one' ) ) );
		self::assertSame( 200, $me->get_status() );
		self::assertSame( 'no_one', $this->social->service( \ODSI\Social\Repositories\MemberRepository::class )->find( $u )->message_setting );

		self::assertSame( 404, $this->rest( 'GET', self::NS . '/members/999999' )->get_status() );
	}
}
