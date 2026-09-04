<?php
/**
 * The privacy decision table, through both representations (ADR-016, SOC-ACT-020).
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Social\Activity\Feed;
use ODSI\Social\Activity\Privacy;
use ODSI\Tests\Integration\TestCase;

final class PrivacyTest extends TestCase {

	private int $author;
	private int $connection;
	private int $stranger;
	private int $admin;
	private int $public_group;
	private int $private_group;
	private int $hidden_group;
	private int $group_member;

	public function set_up(): void {
		parent::set_up();

		$this->author       = $this->social->member();
		$this->connection   = $this->social->member();
		$this->stranger     = $this->social->member();
		$this->admin        = $this->social->admin();
		$this->group_member = $this->social->member();

		$this->social->connect( $this->author, $this->connection );

		$this->public_group  = $this->social->group( $this->author, 'public' );
		$this->private_group = $this->social->group( $this->author, 'private' );
		$this->hidden_group  = $this->social->group( $this->author, 'hidden' );

		foreach ( array( $this->public_group, $this->private_group, $this->hidden_group ) as $g ) {
			$this->social->add_to_group( $g, $this->group_member );
		}
	}

	/**
	 * Every row of the spec's table: [privacy, group, viewer role => expected].
	 */
	public function table(): array {
		return array(
			'public / visitor'              => array( 'public', 'none', 'visitor', true ),
			'public / stranger'             => array( 'public', 'none', 'stranger', true ),
			'members / visitor'             => array( 'members', 'none', 'visitor', false ),
			'members / stranger'            => array( 'members', 'none', 'stranger', true ),
			'connections / stranger'        => array( 'connections', 'none', 'stranger', false ),
			'connections / connection'      => array( 'connections', 'none', 'connection', true ),
			'connections / visitor'         => array( 'connections', 'none', 'visitor', false ),
			'only_me / connection'          => array( 'only_me', 'none', 'connection', false ),
			'only_me / author'              => array( 'only_me', 'none', 'author', true ),
			'only_me / admin'               => array( 'only_me', 'none', 'admin', true ),
			'group public / visitor'        => array( 'group', 'public', 'visitor', true ),
			'group public / stranger'       => array( 'group', 'public', 'stranger', true ),
			'group private / stranger'      => array( 'group', 'private', 'stranger', false ),
			'group private / member'        => array( 'group', 'private', 'group_member', true ),
			'group private / visitor'       => array( 'group', 'private', 'visitor', false ),
			'group hidden / stranger'       => array( 'group', 'hidden', 'stranger', false ),
			'group hidden / member'         => array( 'group', 'hidden', 'group_member', true ),
			'group hidden / admin'          => array( 'group', 'hidden', 'admin', true ),
			'group hidden / connection'     => array( 'group', 'hidden', 'connection', false ),
		);
	}

	/**
	 * @dataProvider table
	 */
	public function test_can_view_matches_the_table( string $privacy, string $group, string $viewer_role, bool $expected ): void {
		$item = $this->make_item( $privacy, $group );
		$row  = $this->social->service( \ODSI\Social\Repositories\ActivityRepository::class )->find( $item );

		self::assertSame( $expected, $this->social->service( Privacy::class )->can_view( $this->viewer( $viewer_role ), $row ) );
	}

	/**
	 * @dataProvider table
	 */
	public function test_site_feed_matches_the_table( string $privacy, string $group, string $viewer_role, bool $expected ): void {
		$item = $this->make_item( $privacy, $group );
		$page = $this->social->service( Feed::class )->page( $this->viewer( $viewer_role ), Feed::SCOPE_SITE, array( 'per_page' => 50 ) );
		$ids  = array_column( $page['items'], 'id' );

		self::assertSame( $expected, in_array( $item, $ids, true ), 'SQL representation must agree with can_view().' );
	}

	public function test_comments_inherit_the_parent(): void {
		$item    = $this->make_item( 'connections', 'none' );
		$comment = $this->social->comment( $this->connection, $item );
		$privacy = $this->social->service( Privacy::class );
		$repo    = $this->social->service( \ODSI\Social\Repositories\ActivityRepository::class );

		self::assertTrue( $privacy->can_view( $this->connection, $repo->find( $comment ) ) );
		self::assertFalse( $privacy->can_view( $this->stranger, $repo->find( $comment ) ) );
	}

	public function test_invalid_combinations_fail_closed(): void {
		$repo = $this->social->service( \ODSI\Social\Repositories\ActivityRepository::class );

		// `group` privacy with no group → only_me.
		$id = $repo->insert(
			array(
				'user_id' => $this->author,
				'privacy' => 'group',
				'group_id' => 0,
				'content' => 'x',
			)
		);
		self::assertFalse( $this->social->service( Privacy::class )->can_view( $this->stranger, $repo->find( $id ) ) );

		// `public` privacy inside a private group → governed by the group.
		$id = $repo->insert(
			array(
				'user_id' => $this->author,
				'privacy' => 'public',
				'group_id' => $this->private_group,
				'content' => 'x',
			)
		);
		self::assertFalse( $this->social->service( Privacy::class )->can_view( $this->stranger, $repo->find( $id ) ) );
		self::assertTrue( $this->social->service( Privacy::class )->can_view( $this->group_member, $repo->find( $id ) ) );
	}

	public function test_deny_filter_applies_to_single_items_and_feeds(): void {
		$item = $this->make_item( 'public', 'none' );
		add_filter( 'odsi_social_can_view_activity', '__return_false' );

		$row = $this->social->service( \ODSI\Social\Repositories\ActivityRepository::class )->find( $item );
		self::assertFalse( $this->social->service( Privacy::class )->can_view( $this->stranger, $row ) );

		$page = $this->social->service( Feed::class )->page( $this->stranger, Feed::SCOPE_SITE );
		self::assertNotContains( $item, array_column( $page['items'], 'id' ) );
	}

	private function make_item( string $privacy, string $group ): int {
		$group_id = match ( $group ) {
			'public'  => $this->public_group,
			'private' => $this->private_group,
			'hidden'  => $this->hidden_group,
			default   => 0,
		};

		return $this->social->update( $this->author, 'Row ' . wp_rand(), $privacy, $group_id );
	}

	private function viewer( string $role ): int {
		return match ( $role ) {
			'visitor'      => 0,
			'author'       => $this->author,
			'connection'   => $this->connection,
			'admin'        => $this->admin,
			'group_member' => $this->group_member,
			default        => $this->stranger,
		};
	}
}
