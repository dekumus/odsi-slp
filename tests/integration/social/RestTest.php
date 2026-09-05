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

	public function test_feed_defaults_to_the_site_page_size_and_can_render_items(): void {
		$u = $this->social->member();

		for ( $i = 0; $i < 4; $i++ ) {
			$this->social->update( $u, "p{$i}" );
		}

		$this->social->service( \ODSI\Social\Support\Settings::class )->update( array( 'feed_per_page' => 3 ) );

		try {
			$feed = $this->rest( 'GET', self::NS . '/activity', array( 'type' => 'update' ) );
			self::assertCount( 3, $feed->get_data()['items'], 'Absent per_page means the site default, not one.' );
			self::assertNotSame( '', $feed->get_data()['next_cursor'] );
			self::assertArrayNotHasKey( 'html', $feed->get_data()['items'][0] );

			$rendered = $this->as_user(
				$u,
				fn () => $this->rest(
					'GET',
					self::NS . '/activity',
					array(
						'type'     => 'update',
						'per_page' => 0,
						'render'   => '1',
					)
				)
			);
			$item     = $rendered->get_data()['items'][0];
			self::assertCount( 3, $rendered->get_data()['items'] );
			self::assertStringContainsString( '<article class="odsi-social-item" data-activity-id="' . $item['id'] . '"', $item['html'] );
			self::assertStringContainsString( 'odsi-social-item__react', $item['html'], 'Rendered items carry their buttons.' );
			self::assertStringContainsString( 'odsi-social-item__delete', $item['html'] );
			self::assertStringContainsString( 'odsi-social-comment-form', $item['html'] );
		} finally {
			$this->social->service( \ODSI\Social\Support\Settings::class )->update( array( 'feed_per_page' => 20 ) );
		}
	}

	public function test_invitees_can_accept_or_decline_a_hidden_group(): void {
		$owner    = $this->social->member();
		$accepter = $this->social->member();
		$decliner = $this->social->member();
		$hidden   = $this->social->group( $owner, 'hidden' );
		$this->social->invite( $hidden, $owner, $accepter );
		$this->social->invite( $hidden, $owner, $decliner );

		$page = $this->as_user( $accepter, fn () => $this->rest( 'GET', self::NS . "/groups/{$hidden}" ) );
		self::assertSame( 200, $page->get_status() );
		self::assertSame( 'invited', $page->get_data()['viewer']['status'] );

		$mine = $this->as_user( $accepter, fn () => $this->rest( 'GET', self::NS . '/groups/mine' ) );
		self::assertSame( array( $hidden ), array_column( $mine->get_data()['invited'], 'id' ), 'SOC-GRP-010' );

		$joined = $this->as_user( $accepter, fn () => $this->rest( 'POST', self::NS . "/groups/{$hidden}/membership" ) );
		self::assertSame( 200, $joined->get_status() );
		self::assertSame( 'active', $joined->get_data()['viewer']['status'] );
		self::assertSame( array( $hidden ), array_column( $this->as_user( $accepter, fn () => $this->rest( 'GET', self::NS . '/groups/mine' ) )->get_data()['active'], 'id' ) );

		$declined = $this->as_user( $decliner, fn () => $this->rest( 'DELETE', self::NS . "/groups/{$hidden}/membership" ) );
		self::assertSame( 200, $declined->get_status() );
		self::assertNull( $this->social->service( \ODSI\Social\Repositories\GroupMemberRepository::class )->find_for( $hidden, $decliner ) );
		self::assertSame( 404, $this->as_user( $decliner, fn () => $this->rest( 'GET', self::NS . "/groups/{$hidden}" ) )->get_status() );
	}

	public function test_profile_update_rejects_malformed_fields(): void {
		$u = $this->social->member();

		$bad = $this->as_user( $u, fn () => $this->rest( 'PATCH', self::NS . '/members/me', array( 'fields' => array( '3' => 'x' ) ) ) );
		self::assertSame( 400, $bad->get_status() );
		self::assertSame( 'odsi_social_invalid_field', $bad->get_data()['code'] );

		$worse = $this->as_user( $u, fn () => $this->rest( 'PATCH', self::NS . '/members/me', array( 'fields' => 'nope' ) ) );
		self::assertSame( 400, $worse->get_status() );
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
