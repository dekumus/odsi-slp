<?php
/**
 * Front-end settings forms.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Frontend;

use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Groups\Groups;
use ODSI\Social\Groups\Membership;
use ODSI\Social\Members\Profiles;
use ODSI\Social\Members\Uploads;
use ODSI\Social\Repositories\GroupMemberRepository;
use ODSI\Social\Repositories\MemberRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Plain HTML forms for editing a profile and managing a group. They post
 * to admin-post.php so they work without JavaScript (SOC-IF-002) and carry
 * multipart uploads natively. Every handler re-checks who may do what; the
 * `process_*` methods hold the logic so tests can call them directly.
 */
final class Forms implements Bootable {

	public const NONCE_PROFILE = 'odsi_social_profile_save';
	public const NONCE_GROUP   = 'odsi_social_group_save';
	public const NONCE_MEMBER  = 'odsi_social_group_member';

	/**
	 * Constructor.
	 *
	 * @param Profiles              $profiles   Profiles.
	 * @param Uploads               $uploads    Image uploads.
	 * @param Groups                $groups     Groups.
	 * @param Membership            $membership Membership actions.
	 * @param GroupMemberRepository $members    Membership rows.
	 * @param Router                $router     URLs.
	 * @param MemberRepository      $index      Member index, for avatars.
	 */
	public function __construct(
		private Profiles $profiles,
		private Uploads $uploads,
		private Groups $groups,
		private Membership $membership,
		private GroupMemberRepository $members,
		private Router $router,
		private MemberRepository $index
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_post_odsi_social_profile_save', array( $this, 'handle_profile' ) );
		add_action( 'admin_post_odsi_social_group_save', array( $this, 'handle_group' ) );
		add_action( 'admin_post_odsi_social_group_member', array( $this, 'handle_group_member' ) );
	}

	/**
	 * Profile edit form submission.
	 */
	public function handle_profile(): void {
		check_admin_referer( self::NONCE_PROFILE );

		$user_id = absint( $_POST['user_id'] ?? 0 );
		$result  = $this->process_profile( get_current_user_id(), $user_id, wp_unslash( $_POST ), $_FILES ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised per field in process_profile.
		$user    = get_userdata( $user_id );

		$this->finish( $user ? $this->router->url( 'members', $user->user_nicename, 'edit' ) : home_url( '/' ), $result );
	}

	/**
	 * Group settings form submission.
	 */
	public function handle_group(): void {
		check_admin_referer( self::NONCE_GROUP );

		$group_id = absint( $_POST['group_id'] ?? 0 );
		$result   = $this->process_group( get_current_user_id(), $group_id, wp_unslash( $_POST ), $_FILES ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised per field in process_group.

		$this->finish( $this->manage_url( $group_id ), $result );
	}

	/**
	 * Group member action (approve, remove, promote, ...).
	 */
	public function handle_group_member(): void {
		check_admin_referer( self::NONCE_MEMBER );

		$group_id = absint( $_POST['group_id'] ?? 0 );
		$result   = $this->process_group_member(
			get_current_user_id(),
			$group_id,
			absint( $_POST['member_id'] ?? 0 ),
			sanitize_key( (string) ( $_POST['member_action'] ?? '' ) )
		);

		$this->finish( $this->manage_url( $group_id ), $result );
	}

	/**
	 * Apply a profile edit (SOC-MEM-003/004/007).
	 *
	 * @param int                  $actor_id Who submits.
	 * @param int                  $user_id  Whose profile.
	 * @param array<string, mixed> $post     Submitted fields, unslashed.
	 * @param array<string, mixed> $files    `$_FILES`.
	 *
	 * @return true|WP_Error
	 */
	public function process_profile( int $actor_id, int $user_id, array $post, array $files ): bool|WP_Error {
		if ( ! $this->profiles->can_edit( $actor_id, $user_id ) ) {
			return new WP_Error( 'odsi_social_forbidden', __( 'You cannot edit this profile.', 'odsi-social' ), array( 'status' => 403 ) );
		}

		if ( isset( $post['fields'] ) && is_array( $post['fields'] ) ) {
			$fields = array();

			foreach ( $post['fields'] as $id => $data ) {
				if ( ! is_array( $data ) ) {
					continue;
				}

				$entry = array();

				if ( array_key_exists( 'value', $data ) ) {
					$entry['value'] = is_array( $data['value'] ) ? array_map( 'sanitize_text_field', array_map( 'strval', $data['value'] ) ) : (string) $data['value'];
				}

				if ( isset( $data['visibility'] ) ) {
					$entry['visibility'] = sanitize_key( (string) $data['visibility'] );
				}

				$fields[ (int) $id ] = $entry;
			}

			$saved = $this->profiles->update_fields( $user_id, $fields );

			if ( $saved instanceof WP_Error ) {
				return $saved;
			}
		}//end if

		if ( isset( $post['message_setting'] ) ) {
			$this->profiles->set_message_setting( $user_id, sanitize_key( (string) $post['message_setting'] ) );
		}

		if ( isset( $post['email_notifications'] ) ) {
			\ODSI\Social\Notifications\Emails::set_wants_email( $user_id, '1' === (string) $post['email_notifications'] );
		}

		foreach ( array( 'avatar', 'cover' ) as $kind ) {
			if ( ! empty( $post[ 'remove_' . $kind ] ) ) {
				$this->set_member_image( $user_id, $kind, 0 );
			}

			if ( ! empty( $files[ $kind ]['name'] ) ) {
				$stored = $this->uploads->store( $user_id, (array) $files[ $kind ], $kind );

				if ( $stored instanceof WP_Error ) {
					return $stored;
				}

				$this->set_member_image( $user_id, $kind, $stored );
			}
		}

		return true;
	}

	/**
	 * Apply group settings (SOC-GRP-006).
	 *
	 * @param int                  $actor_id Who submits.
	 * @param int                  $group_id Group.
	 * @param array<string, mixed> $post     Submitted fields, unslashed.
	 * @param array<string, mixed> $files    `$_FILES`.
	 *
	 * @return true|WP_Error
	 */
	public function process_group( int $actor_id, int $group_id, array $post, array $files ): bool|WP_Error {
		$args = array();

		foreach ( array( 'name', 'description', 'visibility' ) as $key ) {
			if ( isset( $post[ $key ] ) ) {
				$args[ $key ] = 'description' === $key ? (string) $post[ $key ] : sanitize_text_field( (string) $post[ $key ] );
			}
		}

		foreach ( array( 'avatar', 'cover' ) as $kind ) {
			if ( ! empty( $post[ 'remove_' . $kind ] ) ) {
				$args[ $kind . '_id' ] = 0;
			}

			if ( ! empty( $files[ $kind ]['name'] ) ) {
				// The settings check runs first so a stranger cannot fill the library.
				if ( ! $this->groups->is_organiser( $actor_id, $group_id ) ) {
					return new WP_Error( 'odsi_social_forbidden', __( 'Only organisers can change group settings.', 'odsi-social' ), array( 'status' => 403 ) );
				}

				$stored = $this->uploads->store( $actor_id, (array) $files[ $kind ], 'group_' . $kind, $group_id );

				if ( $stored instanceof WP_Error ) {
					return $stored;
				}

				$args[ $kind . '_id' ] = $stored;
			}
		}

		return $this->groups->update( $actor_id, $group_id, $args );
	}

	/**
	 * Apply a membership action from the manage page.
	 *
	 * @param int    $actor_id Who submits.
	 * @param int    $group_id Group.
	 * @param int    $user_id  Member acted on.
	 * @param string $action   approve, reject, remove, ban, unban, promote, demote,
	 *                         promote_organiser, demote_organiser (organiser → moderator).
	 *
	 * @return true|WP_Error
	 */
	public function process_group_member( int $actor_id, int $group_id, int $user_id, string $action ): bool|WP_Error {
		return match ( $action ) {
			'approve' => $this->membership->approve( $actor_id, $group_id, $user_id ),
			'reject', 'remove' => $this->membership->remove( $actor_id, $group_id, $user_id ),
			'ban'     => $this->membership->ban( $actor_id, $group_id, $user_id ),
			'unban'   => $this->membership->unban( $actor_id, $group_id, $user_id ),
			'promote' => $this->membership->set_role( $actor_id, $group_id, $user_id, GroupMemberRepository::ROLE_MODERATOR ),
			'demote'  => $this->membership->set_role( $actor_id, $group_id, $user_id, GroupMemberRepository::ROLE_MEMBER ),
			'promote_organiser' => $this->membership->set_role( $actor_id, $group_id, $user_id, GroupMemberRepository::ROLE_ORGANISER ),
			'demote_organiser'  => $this->membership->set_role( $actor_id, $group_id, $user_id, GroupMemberRepository::ROLE_MODERATOR ),
			default   => new WP_Error( 'odsi_social_invalid_action', __( 'Unknown action.', 'odsi-social' ), array( 'status' => 400 ) ),
		};
	}

	/**
	 * Everything the manage page needs.
	 *
	 * @param int $group_id Group.
	 *
	 * @return array{members: array<int, array<string, mixed>>, pending: array<int, array<string, mixed>>, banned: array<int, array<string, mixed>>}
	 */
	public function group_lists( int $group_id ): array {
		$lists = array();

		foreach ( array(
			'members' => GroupMemberRepository::STATUS_ACTIVE,
			'pending' => GroupMemberRepository::STATUS_PENDING,
			'banned'  => GroupMemberRepository::STATUS_BANNED,
		) as $key => $status ) {
			$rows = $this->members->for_group( $group_id, $status, null, 200, 0 );
			$this->index->prime_display( array_map( static fn ( object $r ): int => (int) $r->user_id, $rows ) );

			$lists[ $key ] = array_map(
				static function ( object $r ): array {
					$user = get_userdata( (int) $r->user_id );

					return array(
						'id'     => (int) $r->user_id,
						'name'   => $user ? $user->display_name : __( 'A former member', 'odsi-social' ),
						'avatar' => $user ? get_avatar_url( (int) $r->user_id, array( 'size' => 48 ) ) : '',
						'role'   => (string) $r->role,
						'status' => (string) $r->status,
					);
				},
				$rows
			);
		}

		return $lists;
	}

	/**
	 * Manage page URL for a group.
	 *
	 * @param int $group_id Group.
	 */
	public function manage_url( int $group_id ): string {
		$post = get_post( $group_id );

		return $post ? $this->router->url( 'groups', $post->post_name, 'manage' ) : home_url( '/' );
	}

	/**
	 * Store or clear a member image.
	 *
	 * @param int    $user_id       Member.
	 * @param string $kind          avatar or cover.
	 * @param int    $attachment_id Attachment, or 0.
	 */
	private function set_member_image( int $user_id, string $kind, int $attachment_id ): void {
		if ( 'avatar' === $kind ) {
			$this->profiles->set_avatar( $user_id, $attachment_id );
		} else {
			$this->profiles->set_cover( $user_id, $attachment_id );
		}
	}

	/**
	 * Redirect back with a notice.
	 *
	 * @param string        $url    Destination.
	 * @param bool|WP_Error $result Outcome.
	 */
	private function finish( string $url, bool|WP_Error $result ): void {
		$args = $result instanceof WP_Error
			? array(
				'notice'  => 'error',
				'message' => rawurlencode( $result->get_error_message() ),
			)
			: array( 'notice' => 'saved' );

		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}
}
