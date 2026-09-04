<?php
/**
 * Profile field values.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * One row per (field, user).
 */
final class ProfileDataRepository extends AbstractRepository {

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'profile_data';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'field_id'   => '%d',
			'user_id'    => '%d',
			'value'      => '%s',
			'visibility' => '%s',
		);
	}

	/**
	 * All of a user's values keyed by field id.
	 *
	 * @param int $user_id User id.
	 *
	 * @return array<int, object>
	 */
	public function for_user( int $user_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) );

		$by_field = array();

		foreach ( $rows as $row ) {
			$by_field[ (int) $row->field_id ] = $row;
		}

		return $by_field;
	}

	/**
	 * Write a value (and optionally a visibility) for a user.
	 *
	 * @param int         $field_id   Field id.
	 * @param int         $user_id    User id.
	 * @param string      $value      Value; arrays are JSON-encoded by the caller.
	 * @param string|null $visibility Visibility, or null to keep the field default.
	 */
	public function put( int $field_id, int $user_id, string $value, ?string $visibility = null ): bool {
		$data = array(
			'field_id'   => $field_id,
			'user_id'    => $user_id,
			'value'      => $value,
			'visibility' => $visibility,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $this->db->replace( $this->table(), $data, array( '%d', '%d', '%s', '%s' ) );
	}

	/**
	 * Delete every value for a user.
	 *
	 * @param int $user_id User id.
	 */
	public function delete_user( int $user_id ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $this->db->delete( $this->table(), array( 'user_id' => $user_id ), array( '%d' ) );
	}
}
