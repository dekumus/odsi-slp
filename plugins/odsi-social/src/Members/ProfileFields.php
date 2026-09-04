<?php
/**
 * Profile field definitions.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Members;

use ODSI\Social\Repositories\ProfileFieldRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-defined field groups and fields (SOC-MEM-005/006).
 */
final class ProfileFields {

	public const TYPES        = array( 'text', 'textarea', 'select', 'multiselect', 'date', 'url', 'checkbox' );
	public const VISIBILITIES = array( 'public', 'members', 'connections', 'only_me' );

	/**
	 * Per-request memo of the structure; definitions change only from the admin screen.
	 *
	 * @var array<int, array{group: object, fields: object[]}>|null
	 */
	private ?array $structure = null;

	/**
	 * Constructor.
	 *
	 * @param ProfileFieldRepository $fields Storage.
	 */
	public function __construct( private ProfileFieldRepository $fields ) {
	}

	/**
	 * Field groups with their fields, in order. Two queries, memoised per request.
	 *
	 * @return array<int, array{group: object, fields: object[]}>
	 */
	public function structure(): array {
		if ( null !== $this->structure ) {
			return $this->structure;
		}

		$by_group = array();

		foreach ( $this->fields->fields() as $field ) {
			$by_group[ (int) $field->group_id ][] = $field;
		}

		$this->structure = array();

		foreach ( $this->fields->groups() as $group ) {
			$this->structure[] = array(
				'group'  => $group,
				'fields' => $by_group[ (int) $group->id ] ?? array(),
			);
		}

		return $this->structure;
	}

	/**
	 * Every field keyed by id.
	 *
	 * @return array<int, object>
	 */
	public function all(): array {
		$by_id = array();

		foreach ( $this->structure() as $section ) {
			foreach ( $section['fields'] as $field ) {
				$by_id[ (int) $field->id ] = $field;
			}
		}

		return $by_id;
	}

	/**
	 * Forget the memoised structure after a definition changes.
	 */
	public function flush(): void {
		$this->structure = null;
	}

	/**
	 * Create a group.
	 *
	 * @param string $name        Name.
	 * @param string $description Description.
	 * @param int    $sort_order  Order.
	 */
	public function create_group( string $name, string $description = '', int $sort_order = 0 ): int {
		$this->flush();

		return $this->fields->create_group( sanitize_text_field( $name ), sanitize_textarea_field( $description ), $sort_order );
	}

	/**
	 * Delete a group with its fields and data.
	 *
	 * @param int $group_id Group id.
	 */
	public function delete_group( int $group_id ): void {
		$this->flush();
		$this->fields->delete_group( $group_id );
	}

	/**
	 * Create a field.
	 *
	 * @param int                  $group_id Group.
	 * @param string               $name     Name.
	 * @param string               $type     Type.
	 * @param array<string, mixed> $args     Options.
	 *
	 * @return int Field id, or 0 for an invalid type or visibility.
	 */
	public function create( int $group_id, string $name, string $type, array $args = array() ): int {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return 0;
		}

		$visibility = (string) ( $args['default_visibility'] ?? 'public' );

		if ( ! in_array( $visibility, self::VISIBILITIES, true ) ) {
			return 0;
		}

		$this->flush();

		return $this->fields->create( $group_id, sanitize_text_field( $name ), $type, $args );
	}

	/**
	 * Update a field.
	 *
	 * @param int                  $field_id Field.
	 * @param array<string, mixed> $data     Columns.
	 */
	public function update( int $field_id, array $data ): bool {
		if ( isset( $data['type'] ) && ! in_array( $data['type'], self::TYPES, true ) ) {
			return false;
		}

		if ( isset( $data['default_visibility'] ) && ! in_array( $data['default_visibility'], self::VISIBILITIES, true ) ) {
			return false;
		}

		$this->flush();

		return $this->fields->update( $field_id, $data );
	}

	/**
	 * Delete a field and its data.
	 *
	 * @param int $field_id Field.
	 */
	public function delete( int $field_id ): bool {
		$this->flush();

		return $this->fields->delete( $field_id );
	}

	/**
	 * Effective visibility of a field for a member (SOC-MEM-006).
	 *
	 * @param object      $field  Field row.
	 * @param string|null $chosen The member's stored choice, if any.
	 * @param int         $user_id Member.
	 */
	public function effective_visibility( object $field, ?string $chosen, int $user_id ): string {
		$visibility = (string) $field->default_visibility;

		if ( (int) $field->allow_visibility_change && null !== $chosen && in_array( $chosen, self::VISIBILITIES, true ) ) {
			$visibility = $chosen;
		}

		/**
		 * Filters the effective visibility of a profile field value.
		 *
		 * @param string $visibility Visibility.
		 * @param int    $field_id   Field.
		 * @param int    $user_id    Member.
		 */
		return (string) apply_filters( 'odsi_social_profile_field_visibility', $visibility, (int) $field->id, $user_id );
	}

	/**
	 * Decode a field's options.
	 *
	 * @param object $field Field row.
	 *
	 * @return string[]
	 */
	public static function options( object $field ): array {
		$decoded = json_decode( (string) ( $field->options ?? '[]' ), true );

		return is_array( $decoded ) ? array_values( array_map( 'strval', $decoded ) ) : array();
	}
}
