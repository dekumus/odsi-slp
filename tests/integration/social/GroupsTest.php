<?php
/**
 * Groups and membership. Spec: SOC-GRP-*.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Social\Groups\Groups;
use ODSI\Social\Groups\Membership;
use ODSI\Social\Repositories\GroupMemberRepository as Members;
use ODSI\Social\Repositories\GroupRepository;
use ODSI\Tests\Integration\TestCase;
use WP_Error;

final class GroupsTest extends TestCase {

	private Groups $groups;
	private Membership $membership;
	private Members $rows;

	public function set_up(): void {
		parent::set_up();
		$this->groups     = $this->social->service( Groups::class );
		$this->membership = $this->social->service( Membership::class );
		$this->rows       = $this->social->service( Members::class );
	}

	public function test_grp_001_create_is_atomic_with_an_organiser_and_mirrored(): void {
		$u  = $this->social->member();
		$id = $this->social->group( $u, 'private', 'Designers' );

		self::assertSame( 'organiser', $this->rows->role_of( $id, $u ) );
		$index = $this->social->service( GroupRepository::class )->find( $id );
		self::assertSame( 'private', $index->visibility );
		self::assertSame( 'designers', $index->slug );
		self::assertSame( 1, (int) $index->member_count );
	}

	public function test_grp_002_creation_setting(): void {
		$u = $this->social->member();
		$this->social->service( \ODSI\Social\Support\Settings::class )->update( array( 'members_can_create_groups' => false ) );

		self::assertInstanceOf( WP_Error::class, $this->groups->create( $u, array( 'name' => 'x' ) ) );
		self::assertIsInt( $this->groups->create( $this->social->admin(), array( 'name' => 'x' ) ) );

		$this->social->service( \ODSI\Social\Support\Settings::class )->update( array( 'members_can_create_groups' => true ) );
	}

	public function test_grp_005_visibility_rules(): void {
		$owner    = $this->social->member();
		$stranger = $this->social->member();
		$public   = $this->social->group( $owner, 'public' );
		$private  = $this->social->group( $owner, 'private' );
		$hidden   = $this->social->group( $owner, 'hidden' );

		self::assertTrue( $this->groups->can_view( 0, $public ) );
		self::assertTrue( $this->groups->can_view_content( 0, $public ) );
		self::assertTrue( $this->groups->can_view( $stranger, $private ) );
		self::assertFalse( $this->groups->can_view_content( $stranger, $private ) );
		self::assertFalse( $this->groups->can_view( $stranger, $hidden ), 'Hidden is 404 to non-members.' );
		self::assertTrue( $this->groups->can_view( $owner, $hidden ) );
		self::assertNull( $this->groups->present( $stranger, $hidden ) );
	}

	public function test_membership_state_machine(): void {
		$owner = $this->social->member();
		$u     = $this->social->member();
		$mod   = $this->social->member();

		$public  = $this->social->group( $owner, 'public' );
		$private = $this->social->group( $owner, 'private' );
		$hidden  = $this->social->group( $owner, 'hidden' );

		// Join public; request private; hidden is invisible.
		self::assertTrue( $this->membership->join( $u, $public ) );
		self::assertSame( 'active', $this->rows->find_for( $public, $u )->status );
		self::assertInstanceOf( WP_Error::class, $this->membership->join( $u, $private ) );
		self::assertTrue( $this->membership->request( $u, $private ) );
		self::assertSame( 'pending', $this->rows->find_for( $private, $u )->status );
		self::assertSame( 404, $this->membership->request( $u, $hidden )->get_error_data()['status'] );

		// Only staff approve; members cannot.
		self::assertInstanceOf( WP_Error::class, $this->membership->approve( $u, $private, $u ) );
		self::assertTrue( $this->membership->approve( $owner, $private, $u ) );
		self::assertSame( 'active', $this->rows->find_for( $private, $u )->status );

		// Invite into hidden; accept via join.
		self::assertTrue( $this->membership->invite( $owner, $hidden, $mod ) );
		self::assertSame( 'invited', $this->rows->find_for( $hidden, $mod )->status );
		self::assertTrue( $this->membership->join( $mod, $hidden ) );
		self::assertSame( 'active', $this->rows->find_for( $hidden, $mod )->status );

		// Promote; moderators cannot act on organisers.
		self::assertTrue( $this->membership->set_role( $owner, $hidden, $mod, 'moderator' ) );
		self::assertInstanceOf( WP_Error::class, $this->membership->remove( $mod, $hidden, $owner ) );
		self::assertInstanceOf( WP_Error::class, $this->membership->ban( $mod, $hidden, $owner ) );
		self::assertInstanceOf( WP_Error::class, $this->membership->set_role( $mod, $hidden, $mod, 'organiser' ), 'Only organisers change roles.' );

		// Ban and unban.
		self::assertTrue( $this->membership->ban( $owner, $private, $u ) );
		self::assertSame( 'banned', $this->rows->find_for( $private, $u )->status );
		self::assertInstanceOf( WP_Error::class, $this->membership->request( $u, $private ) );
		self::assertTrue( $this->membership->unban( $owner, $private, $u ) );
		self::assertNull( $this->rows->find_for( $private, $u ) );
	}

	public function test_last_organiser_invariant(): void {
		$owner = $this->social->member();
		$other = $this->social->member();
		$g     = $this->social->group( $owner, 'public' );
		$this->membership->join( $other, $g );

		self::assertInstanceOf( WP_Error::class, $this->membership->remove( $owner, $g, $owner ), 'Sole organiser cannot leave.' );
		self::assertInstanceOf( WP_Error::class, $this->membership->set_role( $owner, $g, $owner, 'member' ), 'Sole organiser cannot demote themselves.' );

		self::assertTrue( $this->membership->set_role( $owner, $g, $other, 'organiser' ) );
		self::assertTrue( $this->membership->remove( $owner, $g, $owner ) );
		self::assertSame( 1, $this->rows->count( $g, 'active', 'organiser' ) );
	}

	public function test_sole_organiser_deleted_promotes_a_successor(): void {
		$owner = $this->social->member();
		$mod   = $this->social->member();
		$g     = $this->social->group( $owner, 'public' );
		$this->membership->join( $mod, $g );
		$this->membership->set_role( $owner, $g, $mod, 'moderator' );

		wp_delete_user( $owner );

		self::assertSame( 'organiser', $this->rows->role_of( $g, $mod ) );
		self::assertSame( 1, (int) $this->social->service( GroupRepository::class )->find( $g )->member_count );
	}

	public function test_grp_003_visibility_change_hides_history(): void {
		$owner    = $this->social->member();
		$stranger = $this->social->member();
		$g        = $this->social->group( $owner, 'public' );
		$item     = $this->social->update( $owner, 'x', 'group', $g );

		$feed = $this->social->service( \ODSI\Social\Activity\Feed::class );
		self::assertContains( $item, array_column( $feed->page( $stranger, 'group', array( 'group_id' => $g ) )['items'], 'id' ) );

		self::assertTrue( $this->groups->update( $owner, $g, array( 'visibility' => 'private' ) ) );
		self::assertSame( 'private', $this->groups->visibility( $g ) );
		self::assertNotContains( $item, array_column( $feed->page( $stranger, 'group', array( 'group_id' => $g ) )['items'], 'id' ) );
	}

	public function test_grp_007_delete_cascades(): void {
		$owner = $this->social->member();
		$g     = $this->social->group( $owner, 'public' );
		$item  = $this->social->update( $owner, 'x', 'group', $g );
		$fired = 0;
		add_action(
			'odsi_social_group_deleted',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		self::assertTrue( $this->groups->delete( $owner, $g ) );

		self::assertSame( 1, $fired );
		self::assertNull( get_post( $g ) );
		self::assertNull( $this->rows->find_for( $g, $owner ) );
		self::assertNull( $this->social->service( \ODSI\Social\Repositories\ActivityRepository::class )->find( $item ) );
		self::assertNull( $this->social->service( GroupRepository::class )->find( $g ) );
	}

	public function test_grp_008_joining_posts_activity_except_hidden_invites(): void {
		$owner = $this->social->member();
		$u     = $this->social->member();
		$g     = $this->social->group( $owner, 'public' );

		$posted = array();
		add_action(
			'odsi_social_activity_posted',
			static function ( object $item ) use ( &$posted ): void {
				$posted[] = $item->type;
			}
		);

		$this->membership->join( $u, $g );

		self::assertContains( 'joined_group', $posted, 'SOC-GRP-008' );
	}

	public function test_system_add_and_remove_bypass_visibility_but_not_bans_or_roles(): void {
		$owner = $this->social->member();
		$u     = $this->social->member();
		$g     = $this->social->group( $owner, 'hidden' );

		$joined = array();
		add_action(
			'odsi_social_group_member_joined',
			static function ( int $gid, int $uid, string $via ) use ( &$joined ): void {
				$joined[] = $via;
			},
			10,
			3
		);

		self::assertTrue( $this->membership->add( $g, $u, 'course_enrollment' ) );
		self::assertTrue( $this->membership->add( $g, $u, 'course_enrollment' ), 'Idempotent.' );
		self::assertSame( array( 'course_enrollment' ), $joined );
		self::assertSame( 'active', $this->rows->find_for( $g, $u )->status );

		self::assertTrue( $this->membership->remove_member( $g, $u ) );
		self::assertFalse( $this->membership->remove_member( $g, $owner ), 'Organisers are not removed by a process.' );

		$this->membership->ban( $owner, $g, $u );
		self::assertFalse( $this->membership->add( $g, $u ), 'Bans hold.' );
	}
}
