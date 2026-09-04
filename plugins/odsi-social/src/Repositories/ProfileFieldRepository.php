<?php
/**
 * Profile field definitions and groups.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Repositories;

use ODSI\Social\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-defined field groups and fields.
 */
final class ProfileFieldRepository extends AbstractRepository {

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'profile_fields';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'group_id'                => '%d',
			'name'                    => '%s',
			'type'                    => '%s',
			'options'                 => '%s',
			'required'                => '%d',
			'default_visibility'      => '%s',
			'allow_visibility_change' => '%d',
			'sort_order'              => '%d',
			'created_at'              => '%s',
		);
	}

	/**
	 * Create a field group.
	 *
	 * @param string $name        Name.
	 * @param string $description Description.
	 * @param int    $sort_order  Order.
	 *
	 * @return int Group id.
	 */
	public function create_group( string $name, string $description = '', int $sort_order = 0 ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->insert(
			Schema::table( 'profile_groups' ),
			array(
				'name'        => $name,
				'description' => $description,
				'sort_order'  => $sort_order,
			),
			array( '%s', '%s', '%d' )
		);

		return (int) $this->db->insert_id;
	}

	/**
	 * All field groups in order.
	 *
	 * @return object[]
	 */
	public function groups(): array {
		$table = Schema::table( 'profile_groups' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC" );
	}

	/**
	 * Delete a group, its fields and their data.
	 *
	 * @param int $group_id Group id.
	 */
	public function delete_group( int $group_id ): void {
		foreach ( $this->fields( $group_id ) as $field ) {
			$this->delete( (int) $field->id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->delete( Schema::table( 'profile_groups' ), array( 'id' => $group_id ), array( '%d' ) );
	}

	/**
	 * Create a field.
	 *
	 * @param int                  $group_id Group id.
	 * @param string               $name     Name.
	 * @param string               $type     Type.
	 * @param array<string, mixed> $args     `options`, `required`, `default_visibility`, `allow_visibility_change`, `sort_order`.
	 *
	 * @return int Field id.
	 */
	public function create( int $group_id, string $name, string $type, array $args = array() ): int {
		return $this->insert_row(
			array(
				'group_id'                => $group_id,
				'name'                    => $name,
				'type'                    => $type,
				'options'                 => (string) wp_json_encode( (array) ( $args['options'] ?? array() ) ),
				'required'                => ! empty( $args['required'] ) ? 1 : 0,
				'default_visibility'      => (string) ( $args['default_visibility'] ?? 'public' ),
				'allow_visibility_change' => isset( $args['allow_visibility_change'] ) && ! $args['allow_visibility_change'] ? 0 : 1,
				'sort_order'              => (int) ( $args['sort_order'] ?? 0 ),
				'created_at'              => $this->now(),
			)
		);
	}

	/**
	 * Update a field.
	 *
	 * @param int                  $field_id Field id.
	 * @param array<string, mixed> $data     Columns; `options` is JSON-encoded here.
	 */
	public function update( int $field_id, array $data ): bool {
		if ( isset( $data['options'] ) && is_array( $data['options'] ) ) {
			$data['options'] = (string) wp_json_encode( $data['options'] );
		}

		return $this->update_row( $field_id, $data );
	}

	/**
	 * Delete a field and its data.
	 *
	 * @param int $id Field id.
	 */
	public function delete( int $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->delete( Schema::table( 'profile_data' ), array( 'field_id' => $id ), array( '%d' ) );

		return parent::delete( $id );
	}

	/**
	 * Fields, optionally within one group, in order.
	 *
	 * @param int|null $group_id Group id.
	 *
	 * @return object[]
	 */
	public function fields( ?int $group_id = null ): array {
		$table = $this->table();

		if ( null === $group_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (array) $this->db->get_results( "SELECT * FROM {$table} ORDER BY group_id ASC, sort_order ASC, id ASC" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE group_id = %d ORDER BY sort_order ASC, id ASC", $group_id ) );
	}
}
