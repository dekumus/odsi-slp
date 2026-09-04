<?php
/**
 * Avatar and cover uploads, profile settings, group management. Spec: SOC-MEM-003/004/007, SOC-GRP-006.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Social\Frontend\Forms;
use ODSI\Social\Groups\Groups;
use ODSI\Social\Members\ProfileFields;
use ODSI\Social\Members\Profiles;
use ODSI\Social\Members\Uploads;
use ODSI\Social\Repositories\GroupMemberRepository;
use ODSI\Social\Repositories\MemberRepository;
use ODSI\Tests\Integration\TestCase;
use WP_Error;

final class SettingsFormsTest extends TestCase {

	private const NS = '/odsi-social/v1';

	private Forms $forms;
	private Profiles $profiles;
	private Uploads $uploads;

	public function set_up(): void {
		parent::set_up();
		$this->forms    = $this->social->service( Forms::class );
		$this->profiles = $this->social->service( Profiles::class );
		$this->uploads  = $this->social->service( Uploads::class );
		add_filter( 'odsi_social_upload_handler', static fn (): string => 'wp_handle_sideload' );
		do_action( 'rest_api_init' );
	}

	public function test_mem_003_uploads_accept_only_images_and_shrink_large_ones(): void {
		$member = $this->social->member();

		$text = $this->uploads->store( $member, $this->staged_file( 'notes.txt', 'plain text' ), 'avatar' );
		self::assertInstanceOf( WP_Error::class, $text );
		self::assertSame( 'odsi_social_upload_failed', $text->get_error_code() );

		$fake = $this->uploads->store( $member, $this->staged_file( 'fake.png', 'not really a png' ), 'avatar' );
		self::assertInstanceOf( WP_Error::class, $fake );

		$this->social->service( \ODSI\Social\Support\Settings::class )->update( array( 'avatar_max_px' => 100 ) );

		$id = $this->uploads->store( $member, $this->staged_png( 'big.png', 300, 200 ), 'avatar' );
		self::assertIsInt( $id );
		self::assertSame( 'attachment', get_post_type( $id ) );
		self::assertSame( $member, (int) get_post_field( 'post_author', $id ) );
		self::assertSame( 'avatar', get_post_meta( $id, '_odsi_social_image', true ) );

		$size = wp_getimagesize( (string) get_attached_file( $id ) );
		self::assertSame( 100, (int) $size[0], 'Shrunk to the admin maximum.' );
		self::assertSame( 67, (int) $size[1] );
	}

	public function test_mem_003_004_profile_form_sets_and_clears_images(): void {
		$member = $this->social->member();
		$other  = $this->social->member();

		$forbidden = $this->forms->process_profile( $other, $member, array(), array() );
		self::assertInstanceOf( WP_Error::class, $forbidden );
		self::assertSame( 'odsi_social_forbidden', $forbidden->get_error_code() );

		$saved = $this->forms->process_profile(
			$member,
			$member,
			array( 'message_setting' => 'connections' ),
			array(
				'avatar' => $this->staged_png( 'me.png', 64, 64 ),
				'cover'  => $this->staged_png( 'cover.png', 120, 40 ),
			)
		);
		self::assertTrue( $saved );

		$row = $this->social->service( MemberRepository::class )->find( $member );
		self::assertGreaterThan( 0, (int) $row->avatar_id );
		self::assertGreaterThan( 0, (int) $row->cover_id );
		self::assertSame( 'connections', $row->message_setting );
		self::assertStringContainsString( 'me', (string) get_avatar_url( $member ), 'The uploaded avatar replaces Gravatar.' );

		$view = $this->profiles->view( $member, $member );
		self::assertNotSame( '', $view['cover'] );

		self::assertTrue(
			$this->forms->process_profile(
				$member,
				$member,
				array(
					'remove_avatar' => '1',
					'remove_cover'  => '1',
				),
				array()
			)
		);
		$row = $this->social->service( MemberRepository::class )->find( $member );
		self::assertSame( 0, (int) $row->avatar_id );
		self::assertSame( 0, (int) $row->cover_id );
		self::assertStringContainsString( 'gravatar', (string) get_avatar_url( $member ) );
	}

	public function test_mem_007_profile_form_saves_fields_and_visibility(): void {
		$member = $this->social->member();
		$fields = $this->social->service( ProfileFields::class );
		$group  = $fields->create_group( 'About' );
		$city   = $fields->create(
			$group,
			'City',
			'text',
			array(
				'required'                => true,
				'default_visibility'      => 'public',
				'allow_visibility_change' => true,
			)
		);
		$langs  = $fields->create(
			$group,
			'Languages',
			'multiselect',
			array(
				'options'                 => array( 'English', 'Hindi', 'Tamil' ),
				'default_visibility'      => 'members',
				'allow_visibility_change' => false,
			)
		);

		$missing = $this->forms->process_profile( $member, $member, array( 'fields' => array( $city => array( 'value' => '' ) ) ), array() );
		self::assertInstanceOf( WP_Error::class, $missing );
		self::assertSame( 'odsi_social_required_field', $missing->get_error_code() );

		self::assertTrue(
			$this->forms->process_profile(
				$member,
				$member,
				array(
					'fields' => array(
						$city  => array(
							'value'      => ' Mumbai <b>x</b> ',
							'visibility' => 'connections',
						),
						$langs => array(
							'value'      => array( 'Hindi', 'Klingon' ),
							'visibility' => 'public',
						),
					),
				),
				array()
			)
		);

		$form = $this->profiles->edit_form( $member );
		self::assertSame( 'About', $form[0]['group'] );
		$by_id = array_column( $form[0]['fields'], null, 'id' );
		self::assertSame( 'Mumbai x', $by_id[ $city ]['value'] );
		self::assertSame( 'connections', $by_id[ $city ]['visibility'] );
		self::assertSame( array( 'Hindi' ), $by_id[ $langs ]['value'], 'Unknown options are dropped.' );
		self::assertSame( 'members', $by_id[ $langs ]['visibility'], 'A locked visibility ignores the member choice.' );
		self::assertFalse( $by_id[ $langs ]['allow_visibility_change'] );

		$stranger = $this->social->member();
		$seen     = $this->profiles->view( $stranger, $member );
		self::assertSame( array( 'Languages' ), array_column( $seen['field_groups'][0]['fields'], 'name' ), 'City is connections-only now.' );
	}

	public function test_grp_006_group_form_and_member_actions(): void {
		$organiser = $this->social->member();
		$member    = $this->social->member();
		$applicant = $this->social->member();
		$group     = $this->social->group( $organiser, 'private', 'Study circle' );
		$this->social->add_to_group( $group, $member );
		$this->social->service( \ODSI\Social\Groups\Membership::class )->request( $applicant, $group );

		$forbidden = $this->forms->process_group( $member, $group, array( 'name' => 'Hijacked' ), array( 'avatar' => $this->staged_png( 'g.png', 10, 10 ) ) );
		self::assertInstanceOf( WP_Error::class, $forbidden );
		self::assertSame( 'odsi_social_forbidden', $forbidden->get_error_code() );
		self::assertSame( 'Study circle', get_the_title( $group ) );

		self::assertTrue(
			$this->forms->process_group(
				$organiser,
				$group,
				array(
					'name'        => 'Study circle 2',
					'description' => '<p>Weekly</p><script>x()</script>',
					'visibility'  => 'public',
				),
				array(
					'avatar' => $this->staged_png( 'g.png', 10, 10 ),
					'cover'  => $this->staged_png( 'c.png', 20, 10 ),
				)
			)
		);

		$groups    = $this->social->service( Groups::class );
		$presented = $groups->present( $organiser, $group );
		self::assertSame( 'Study circle 2', $presented['name'] );
		self::assertSame( 'public', $presented['visibility'] );
		self::assertStringContainsString( 'Weekly', $presented['description'] );
		self::assertStringNotContainsString( '<script', $presented['description'] );
		self::assertNotSame( '', $presented['avatar'] );
		self::assertNotSame( '', $presented['cover'] );

		$lists = $this->forms->group_lists( $group );
		self::assertSame( array( $applicant ), array_column( $lists['pending'], 'id' ) );
		self::assertEqualsCanonicalizing( array( $organiser, $member ), array_column( $lists['members'], 'id' ) );

		self::assertTrue( $this->forms->process_group_member( $organiser, $group, $applicant, 'approve' ) );
		self::assertTrue( $this->forms->process_group_member( $organiser, $group, $member, 'promote' ) );
		self::assertSame( GroupMemberRepository::ROLE_MODERATOR, $this->social->service( GroupMemberRepository::class )->role_of( $group, $member ) );
		self::assertTrue( $this->forms->process_group_member( $organiser, $group, $applicant, 'ban' ) );

		$lists = $this->forms->group_lists( $group );
		self::assertSame( array( $applicant ), array_column( $lists['banned'], 'id' ) );
		self::assertSame( array(), $lists['pending'] );

		$denied = $this->forms->process_group_member( $member, $group, $organiser, 'remove' );
		self::assertInstanceOf( WP_Error::class, $denied, 'A moderator cannot remove the organiser.' );

		self::assertSame( 'odsi_social_invalid_action', $this->forms->process_group_member( $organiser, $group, $member, 'explode' )->get_error_code() );
	}

	public function test_rest_image_routes(): void {
		$member    = $this->social->member();
		$organiser = $this->social->member();
		$group     = $this->social->group( $organiser, 'public', 'Photo club' );

		self::assertSame( 401, $this->rest( 'POST', self::NS . '/members/me/avatar' )->get_status() );

		$uploaded = $this->as_user( $member, fn () => $this->rest_upload( 'POST', self::NS . '/members/me/avatar', $this->staged_png( 'a.png', 16, 16 ) ) );
		self::assertSame( 201, $uploaded->get_status() );
		self::assertStringContainsString( 'a', (string) $uploaded->get_data()['avatar'] );

		$empty = $this->as_user( $member, fn () => $this->rest( 'POST', self::NS . '/members/me/cover' ) );
		self::assertSame( 400, $empty->get_status() );
		self::assertSame( 'odsi_social_no_file', $empty->get_data()['code'] );

		$removed = $this->as_user( $member, fn () => $this->rest( 'DELETE', self::NS . '/members/me/avatar' ) );
		self::assertSame( 200, $removed->get_status() );
		self::assertStringContainsString( 'gravatar', (string) $removed->get_data()['avatar'] );

		$not_organiser = $this->as_user( $member, fn () => $this->rest_upload( 'POST', self::NS . "/groups/{$group}/avatar", $this->staged_png( 'g.png', 16, 16 ) ) );
		self::assertSame( 403, $not_organiser->get_status() );

		$group_avatar = $this->as_user( $organiser, fn () => $this->rest_upload( 'POST', self::NS . "/groups/{$group}/cover", $this->staged_png( 'g.png', 16, 16 ) ) );
		self::assertSame( 201, $group_avatar->get_status() );
		self::assertNotSame( '', $group_avatar->get_data()['cover'] );

		$cleared = $this->as_user( $organiser, fn () => $this->rest( 'DELETE', self::NS . "/groups/{$group}/cover" ) );
		self::assertSame( '', $cleared->get_data()['cover'] );
	}

	/**
	 * Dispatch a multipart REST request with one `file` field.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $file   Staged file.
	 */
	private function rest_upload( string $method, string $route, array $file ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		$request->set_file_params( array( 'file' => $file ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Stage a real PNG of the given size.
	 *
	 * @param string $name   Name.
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 *
	 * @return array<string, mixed>
	 */
	private function staged_png( string $name, int $width, int $height ): array {
		$image = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $image, 0, 0, $width, $height, (int) imagecolorallocate( $image, 30, 90, 200 ) );
		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();
		imagedestroy( $image );

		return $this->staged_file( $name, $bytes );
	}

	/**
	 * Stage a file the way PHP would for an upload.
	 *
	 * @param string $name    File name.
	 * @param string $content Content.
	 *
	 * @return array<string, mixed>
	 */
	private function staged_file( string $name, string $content ): array {
		$path = wp_tempnam( $name );
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return array(
			'name'     => $name,
			'type'     => 'application/octet-stream',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $content ),
		);
	}
}
