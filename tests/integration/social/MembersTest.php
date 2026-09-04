<?php
/**
 * Profiles, fields, directory, presence. Spec: SOC-MEM-*.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Social\Members\Directory;
use ODSI\Social\Members\Presence;
use ODSI\Social\Members\ProfileFields;
use ODSI\Social\Members\Profiles;
use ODSI\Social\Repositories\MemberRepository;
use ODSI\Tests\Integration\TestCase;
use WP_Error;

final class MembersTest extends TestCase {

	private Profiles $profiles;
	private ProfileFields $fields;

	public function set_up(): void {
		parent::set_up();
		$this->profiles = $this->social->service( Profiles::class );
		$this->fields   = $this->social->service( ProfileFields::class );
	}

	public function test_mem_005_006_field_visibility_resolution(): void {
		$owner    = $this->social->member();
		$friend   = $this->social->member();
		$stranger = $this->social->member();
		$this->social->connect( $owner, $friend );

		$group    = $this->fields->create_group( 'About' );
		$employer = $this->fields->create(
			$group,
			'Employer',
			'text',
			array(
				'default_visibility' => 'public',
				'allow_visibility_change' => true,
			)
		);
		$locked   = $this->fields->create(
			$group,
			'Locked',
			'text',
			array(
				'default_visibility' => 'public',
				'allow_visibility_change' => false,
			)
		);
		self::assertSame( 0, $this->fields->create( $group, 'Bad', 'hologram' ), 'Invalid type rejected.' );

		self::assertTrue(
			$this->profiles->update_fields(
				$owner,
				array(
					$employer => array(
						'value' => 'ACME',
						'visibility' => 'connections',
					),
					$locked   => array(
						'value' => 'shown',
						'visibility' => 'only_me',
					),
				)
			)
		);

		$names = static fn ( array $profile ): array => array_column( $profile['field_groups'][0]['fields'] ?? array(), 'name' );

		self::assertEqualsCanonicalizing( array( 'Employer', 'Locked' ), $names( $this->profiles->view( $friend, $owner ) ) );
		self::assertSame( array( 'Locked' ), $names( $this->profiles->view( $stranger, $owner ) ), 'Member choice honoured; locked field ignores the choice.' );
		self::assertSame( array( 'Locked' ), $names( $this->profiles->view( 0, $owner ) ) );
		self::assertEqualsCanonicalizing( array( 'Employer', 'Locked' ), $names( $this->profiles->view( $owner, $owner ) ) );
	}

	public function test_mem_007_required_fields_block_the_form_only(): void {
		$owner = $this->social->member();
		$group = $this->fields->create_group( 'About' );
		$req   = $this->fields->create( $group, 'Name', 'text', array( 'required' => true ) );

		$result = $this->profiles->update_fields( $owner, array( $req => array( 'value' => '' ) ) );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( $req, $result->get_error_data()['field_id'] );

		self::assertNotNull( $this->profiles->view( 0, $owner ), 'The profile still renders.' );
	}

	public function test_field_types_encode_and_decode(): void {
		$owner = $this->social->member();
		$group = $this->fields->create_group( 'Prefs' );
		$multi = $this->fields->create( $group, 'Langs', 'multiselect', array( 'options' => array( 'PHP', 'JS', 'Go' ) ) );
		$check = $this->fields->create( $group, 'Newsletter', 'checkbox' );
		$url   = $this->fields->create( $group, 'Site', 'url' );

		$this->profiles->update_fields(
			$owner,
			array(
				$multi => array( 'value' => array( 'PHP', 'Rust', 'Go' ) ),
				$check => array( 'value' => 'yes' ),
				$url   => array( 'value' => 'javascript:alert(1)' ),
			)
		);

		$fields = array_column( $this->profiles->view( $owner, $owner )['field_groups'][0]['fields'], 'value', 'name' );
		self::assertSame( array( 'PHP', 'Go' ), $fields['Langs'], 'Unknown options are dropped.' );
		self::assertTrue( $fields['Newsletter'] );
		self::assertArrayNotHasKey( 'Site', $fields, 'A dangerous URL sanitises to nothing and is not shown.' );
	}

	public function test_mem_008_009_directory(): void {
		$a = $this->social->member( 'alpha' );
		$b = $this->social->member( 'beta' );
		$presence = $this->social->service( Presence::class );
		$presence->touch( $a, true );
		$presence->touch( $b, true );

		$directory = $this->social->service( Directory::class );
		$result    = $directory->query( 0, array( 'orderby' => 'alphabetical' ) );
		$ids       = array_column( $result['members'], 'id' );

		self::assertContains( $a, $ids );
		self::assertContains( $b, $ids );
		self::assertSame( array( 'alpha' ), array_column( $directory->query( 0, array( 'search' => 'alph' ) )['members'], 'nicename' ) );

		$this->social->service( \ODSI\Social\Support\Settings::class )->update( array( 'public_directory' => false ) );
		self::assertFalse( $directory->can_view( 0 ) );
		self::assertTrue( $directory->can_view( $a ) );
		$this->social->service( \ODSI\Social\Support\Settings::class )->update( array( 'public_directory' => true ) );
	}

	public function test_mem_011_presence_is_throttled(): void {
		$u        = $this->social->member();
		$presence = $this->social->service( Presence::class );
		$members  = $this->social->service( MemberRepository::class );

		$presence->touch( $u, true );
		$first = $members->find( $u )->last_active;

		$recent = gmdate( 'Y-m-d H:i:s', time() - 60 );
		$members->update( $u, array( 'last_active' => $recent ) );
		$presence->touch( $u );
		self::assertSame( $recent, $members->find( $u )->last_active, 'Within five minutes: no write.' );

		$members->update( $u, array( 'last_active' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ) );
		$presence->touch( $u );
		self::assertEqualsWithDelta( time(), strtotime( $members->find( $u )->last_active ), 5 );
	}

	public function test_mem_003_uploaded_avatar_replaces_gravatar(): void {
		$u = $this->social->member();
		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		wp_update_post(
			array(
				'ID'          => $attachment,
				'post_author' => $u,
			)
		);

		self::assertTrue( $this->profiles->set_avatar( $u, $attachment ) );
		self::assertStringContainsString( 'canola', (string) get_avatar_url( $u ) );

		$this->profiles->set_avatar( $u, 0 );
		self::assertStringNotContainsString( 'canola', (string) get_avatar_url( $u ) );
	}
}
