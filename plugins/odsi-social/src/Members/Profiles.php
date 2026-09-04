<?php
/**
 * Profiles.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Members;

use ODSI\Social\Connections\Connections;
use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Repositories\MemberRepository;
use ODSI\Social\Repositories\ProfileDataRepository;
use ODSI\Social\Support\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reading a profile for a viewer, updating one's own, avatars and covers.
 */
final class Profiles implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param MemberRepository      $members     Member index.
	 * @param ProfileDataRepository $data        Field values.
	 * @param ProfileFields         $fields      Field definitions.
	 * @param Connections           $connections Connections, for `connections` visibility.
	 */
	public function __construct(
		private MemberRepository $members,
		private ProfileDataRepository $data,
		private ProfileFields $fields,
		private Connections $connections
	) {
	}

	/**
	 * Register hooks: uploaded avatars replace Gravatar everywhere (SOC-MEM-003).
	 */
	public function boot(): void {
		add_filter( 'pre_get_avatar_data', array( $this, 'filter_avatar_data' ), 10, 2 );
	}

	/**
	 * Supply the uploaded avatar to `get_avatar()`.
	 *
	 * @param array<string, mixed> $args        Avatar args.
	 * @param mixed                $id_or_email User reference.
	 *
	 * @return array<string, mixed>
	 */
	public function filter_avatar_data( array $args, mixed $id_or_email ): array {
		$user_id = $this->resolve_user( $id_or_email );

		if ( $user_id <= 0 ) {
			return $args;
		}

		$row = $this->members->find( $user_id );

		if ( ! $row || (int) $row->avatar_id <= 0 ) {
			return $args;
		}

		$size = (int) ( $args['size'] ?? 96 );
		$url  = wp_get_attachment_image_url( (int) $row->avatar_id, array( $size, $size ) );

		if ( $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
		}

		return $args;
	}

	/**
	 * Warm every cache `view()` reads for a list of members — users, index
	 * rows, avatars and covers, field values, field definitions and the
	 * viewer's connections — in a fixed number of queries.
	 *
	 * @param int   $viewer_id Viewer.
	 * @param int[] $user_ids  Members.
	 */
	public function prime( int $viewer_id, array $user_ids ): void {
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );

		if ( array() === $user_ids ) {
			return;
		}

		$this->members->prime_display( $user_ids );
		$this->data->prime( $user_ids );
		$this->fields->structure();

		if ( $viewer_id > 0 ) {
			$this->connections->prime_pairs( $viewer_id, $user_ids );
		}
	}

	/**
	 * A member's profile as the viewer may see it (SOC-MEM-006).
	 *
	 * Reading a profile never writes: a member who has not logged in yet has
	 * no index row and is shown with zero counts (SOC-MEM-001/008).
	 *
	 * @param int $viewer_id Viewer.
	 * @param int $user_id   Member.
	 *
	 * @return array<string, mixed>|null Null when the user does not exist.
	 */
	public function view( int $viewer_id, int $user_id ): ?array {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return null;
		}

		$row     = $this->members->find( $user_id ) ?? (object) array(
			'last_active'      => '',
			'activity_count'   => 0,
			'connection_count' => 0,
			'follower_count'   => 0,
			'following_count'  => 0,
			'avatar_id'        => 0,
			'cover_id'         => 0,
		);
		$values  = $this->data->for_user( $user_id );
		$visible = array();

		foreach ( $this->fields->structure() as $section ) {
			$group_fields = array();

			foreach ( $section['fields'] as $field ) {
				$value = $values[ (int) $field->id ] ?? null;

				if ( ! $value || self::is_empty( $field, (string) $value->value ) ) {
					continue;
				}

				$visibility = $this->fields->effective_visibility( $field, $value->visibility, $user_id );

				if ( ! $this->viewer_passes( $viewer_id, $user_id, $visibility ) ) {
					continue;
				}

				$group_fields[] = array(
					'id'    => (int) $field->id,
					'name'  => (string) $field->name,
					'type'  => (string) $field->type,
					'value' => self::decode_value( $field, (string) $value->value ),
				);
			}

			if ( $group_fields ) {
				$visible[] = array(
					'group'  => (string) $section['group']->name,
					'fields' => $group_fields,
				);
			}
		}//end foreach

		return array(
			'id'           => $user_id,
			'name'         => $user->display_name,
			'nicename'     => $user->user_nicename,
			'avatar'       => get_avatar_url( $user_id, array( 'size' => 192 ) ),
			'cover'        => (int) $row->cover_id > 0 ? ( wp_get_attachment_image_url( (int) $row->cover_id, 'large' ) ?: '' ) : '',
			'url'          => (string) apply_filters( 'odsi_social_member_url', '', $user_id ),
			'last_active'  => $viewer_id > 0 ? (string) $row->last_active : '',
			'registered'   => $viewer_id > 0 ? $user->user_registered : '',
			'counts'       => array(
				'activity'    => (int) $row->activity_count,
				'connections' => (int) $row->connection_count,
				'followers'   => (int) $row->follower_count,
				'following'   => (int) $row->following_count,
			),
			'field_groups' => $visible,
			'viewer'       => array(
				'is_self'    => $viewer_id === $user_id,
				'connection' => $viewer_id > 0 ? $this->connections->status( $viewer_id, $user_id ) : '',
			),
		);
	}

	/**
	 * Every field with the member's own value and visibility, for the edit form.
	 *
	 * @param int $user_id Member.
	 *
	 * @return array<int, array{group: string, fields: array<int, array<string, mixed>>}>
	 */
	public function edit_form( int $user_id ): array {
		$values = $this->data->for_user( $user_id );
		$out    = array();

		foreach ( $this->fields->structure() as $section ) {
			$group_fields = array();

			foreach ( $section['fields'] as $field ) {
				$value = $values[ (int) $field->id ] ?? null;

				$group_fields[] = array(
					'id'                      => (int) $field->id,
					'name'                    => (string) $field->name,
					'type'                    => (string) $field->type,
					'required'                => (bool) $field->required,
					'options'                 => ProfileFields::options( $field ),
					'value'                   => $value ? self::decode_value( $field, (string) $value->value ) : ( 'multiselect' === (string) $field->type ? array() : '' ),
					'visibility'              => $this->fields->effective_visibility( $field, $value->visibility ?? null, $user_id ),
					'allow_visibility_change' => (bool) $field->allow_visibility_change,
				);
			}

			if ( $group_fields ) {
				$out[] = array(
					'group'  => (string) $section['group']->name,
					'fields' => $group_fields,
				);
			}
		}//end foreach

		return $out;
	}

	/**
	 * The member's "who may message me" preference.
	 *
	 * @param int $user_id Member.
	 */
	public function message_setting( int $user_id ): string {
		$row = $this->members->find( $user_id );

		return $row ? (string) $row->message_setting : 'anyone';
	}

	/**
	 * Whether a viewer may edit a member's profile: themselves, or an admin.
	 *
	 * @param int $viewer_id Viewer.
	 * @param int $user_id   Member.
	 */
	public function can_edit( int $viewer_id, int $user_id ): bool {
		return $viewer_id > 0 && ( $viewer_id === $user_id || Capabilities::is_admin( $viewer_id ) );
	}

	/**
	 * Update the member's own field values and visibilities (SOC-MEM-007).
	 *
	 * @param int                                                   $user_id Member.
	 * @param array<int, array{value?: mixed, visibility?: string}> $fields  Field id => data.
	 *
	 * @return true|WP_Error An error naming the first missing required field.
	 */
	public function update_fields( int $user_id, array $fields ): bool|WP_Error {
		foreach ( $fields as $submitted ) {
			if ( ! is_array( $submitted ) ) {
				return new WP_Error( 'odsi_social_invalid_field', __( 'Each field must be an object with a value and/or a visibility.', 'odsi-social' ), array( 'status' => 400 ) );
			}
		}

		$definitions = $this->fields->all();
		$current     = $this->data->for_user( $user_id );

		foreach ( $definitions as $id => $field ) {
			$submitted = $fields[ $id ] ?? null;
			$value     = null === $submitted || ! array_key_exists( 'value', $submitted ) ? ( isset( $current[ $id ] ) ? (string) $current[ $id ]->value : '' ) : self::encode_value( $field, $submitted['value'] );

			if ( (int) $field->required && self::is_empty( $field, $value ) ) {
				return new WP_Error(
					'odsi_social_required_field',
					sprintf(
						/* translators: %s: field name. */
						__( '%s is required.', 'odsi-social' ),
						(string) $field->name
					),
					array( 'field_id' => $id )
				);
			}
		}

		foreach ( $fields as $id => $submitted ) {
			$field = $definitions[ (int) $id ] ?? null;

			if ( ! $field ) {
				continue;
			}

			$visibility = null;

			if ( isset( $submitted['visibility'] ) && (int) $field->allow_visibility_change && in_array( $submitted['visibility'], ProfileFields::VISIBILITIES, true ) ) {
				$visibility = (string) $submitted['visibility'];
			} elseif ( isset( $current[ (int) $id ] ) ) {
				$visibility = $current[ (int) $id ]->visibility;
			}

			$value = array_key_exists( 'value', $submitted ) ? self::encode_value( $field, $submitted['value'] ) : ( isset( $current[ (int) $id ] ) ? (string) $current[ (int) $id ]->value : '' );

			$this->data->put( (int) $id, $user_id, $value, $visibility );
		}

		return true;
	}

	/**
	 * Set or clear the avatar attachment.
	 *
	 * @param int $user_id       Member.
	 * @param int $attachment_id Attachment, or 0 to restore Gravatar.
	 */
	public function set_avatar( int $user_id, int $attachment_id ): bool {
		return $this->set_image( $user_id, 'avatar_id', $attachment_id );
	}

	/**
	 * Set or clear the cover attachment.
	 *
	 * @param int $user_id       Member.
	 * @param int $attachment_id Attachment, or 0.
	 */
	public function set_cover( int $user_id, int $attachment_id ): bool {
		return $this->set_image( $user_id, 'cover_id', $attachment_id );
	}

	/**
	 * Set the member's "who may message me" preference.
	 *
	 * @param int    $user_id Member.
	 * @param string $setting `anyone`, `connections`, `no_one`.
	 */
	public function set_message_setting( int $user_id, string $setting ): bool {
		if ( ! in_array( $setting, array( 'anyone', 'connections', 'no_one' ), true ) ) {
			return false;
		}

		$this->members->ensure( $user_id );

		return $this->members->update( $user_id, array( 'message_setting' => $setting ) );
	}

	/**
	 * Delete a member's profile data (SOC-MEM-010).
	 *
	 * @param int $user_id Member.
	 */
	public function purge_user( int $user_id ): void {
		$this->data->delete_user( $user_id );
		$this->members->delete( $user_id );
	}

	/**
	 * Whether a viewer passes a visibility level for an owner.
	 *
	 * @param int    $viewer_id  Viewer.
	 * @param int    $owner_id   Owner.
	 * @param string $visibility Level.
	 */
	private function viewer_passes( int $viewer_id, int $owner_id, string $visibility ): bool {
		if ( $viewer_id === $owner_id || Capabilities::is_admin( $viewer_id ) ) {
			return true;
		}

		return match ( $visibility ) {
			'public'      => true,
			'members'     => $viewer_id > 0,
			'connections' => $viewer_id > 0 && $this->connections->are_connected( $viewer_id, $owner_id ),
			default       => false,
		};
	}

	/**
	 * Store an attachment id on the index row after checking it is an image.
	 *
	 * @param int    $user_id       Member.
	 * @param string $column        Column.
	 * @param int    $attachment_id Attachment.
	 */
	private function set_image( int $user_id, string $column, int $attachment_id ): bool {
		if ( $attachment_id > 0 && ! Uploads::owned_by( $attachment_id, $user_id ) ) {
			return false;
		}

		$row      = $this->members->ensure( $user_id );
		$previous = (int) $row->{$column};

		if ( ! $this->members->update( $user_id, array( $column => $attachment_id ) ) ) {
			return false;
		}

		if ( $previous > 0 && $previous !== $attachment_id ) {
			Uploads::reclaim( $previous );
		}

		return true;
	}

	/**
	 * Resolve `get_avatar()`'s reference to a user id.
	 *
	 * @param mixed $id_or_email Reference.
	 */
	private function resolve_user( mixed $id_or_email ): int {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}

		if ( $id_or_email instanceof \WP_User ) {
			return (int) $id_or_email->ID;
		}

		if ( $id_or_email instanceof \WP_Comment ) {
			return (int) $id_or_email->user_id;
		}

		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );

			return $user ? (int) $user->ID : 0;
		}

		return 0;
	}

	/**
	 * Encode a submitted value for storage.
	 *
	 * @param object $field Field row.
	 * @param mixed  $value Submitted value.
	 */
	private static function encode_value( object $field, mixed $value ): string {
		switch ( (string) $field->type ) {
			case 'multiselect':
				$allowed = ProfileFields::options( $field );
				$chosen  = array_values( array_intersect( array_map( 'strval', (array) $value ), $allowed ) );

				return (string) wp_json_encode( $chosen );

			case 'select':
				$allowed = ProfileFields::options( $field );

				return in_array( (string) $value, $allowed, true ) ? (string) $value : '';

			case 'checkbox':
				return $value ? '1' : '';

			case 'url':
				return esc_url_raw( (string) $value );

			case 'date':
				$time = strtotime( (string) $value );

				return $time ? gmdate( 'Y-m-d', $time ) : '';

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			default:
				return sanitize_text_field( (string) $value );
		}//end switch
	}

	/**
	 * Whether a stored value counts as empty: nothing, or no selection.
	 *
	 * @param object $field Field row.
	 * @param string $value Stored value.
	 */
	private static function is_empty( object $field, string $value ): bool {
		if ( '' === trim( $value ) ) {
			return true;
		}

		return 'multiselect' === (string) $field->type && array() === (array) json_decode( $value, true );
	}

	/**
	 * Decode a stored value for display.
	 *
	 * @param object $field Field row.
	 * @param string $value Stored value.
	 *
	 * @return mixed
	 */
	private static function decode_value( object $field, string $value ): mixed {
		return match ( (string) $field->type ) {
			'multiselect' => (array) json_decode( $value, true ),
			'checkbox'    => '1' === $value,
			default       => $value,
		};
	}
}
