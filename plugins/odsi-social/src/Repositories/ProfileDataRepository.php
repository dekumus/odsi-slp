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
	 * Per-request cache of values by user id.
	 *
	 * @var array<int, array<int, object>>
	 */
	private array $cache = array();

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'profile_data';
	}

	/**
	 * Warm the cache for several users in one query.
	 *
	 * @param int[] $user_ids User ids.
	 */
	public function prime( array $user_ids ): void {
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ), fn ( int $id ): bool => $id > 0 && ! array_key_exists( $id, $this->cache ) ) ) );

		if ( array() === $user_ids ) {
			return;
		}

		$table = $this->table();
		$in    = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE user_id IN ({$in})", $user_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $user_ids as $id ) {
			$this->cache[ $id ] = array();
		}

		foreach ( $rows as $row ) {
			$this->cache[ (int) $row->user_id ][ (int) $row->field_id ] = $row;
		}
	}

	/**
	 * Drop the per-request cache.
	 */
	public function flush(): void {
		$this->cache = array();
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
		if ( ! array_key_exists( $user_id, $this->cache ) ) {
			$this->prime( array( $user_id ) );
		}

		return $this->cache[ $user_id ] ?? array();
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
		unset( $this->cache[ $user_id ] );

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
		unset( $this->cache[ $user_id ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $this->db->delete( $this->table(), array( 'user_id' => $user_id ), array( '%d' ) );
	}
}
