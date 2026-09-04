<?php
/**
 * Activity meta.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Key/value pairs attached to an activity row. This is the plugin's own table,
 * not postmeta, so the slow-query sniff does not apply.
 *
 * phpcs:disable WordPress.DB.SlowDBQuery
 */
final class ActivityMetaRepository extends AbstractRepository {

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'activity_meta';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'activity_id' => '%d',
			'meta_key'    => '%s',
			'meta_value'  => '%s',
		);
	}

	/**
	 * Read one value.
	 *
	 * @param int    $activity_id Activity id.
	 * @param string $key         Key.
	 *
	 * @return mixed Unserialised value, or null.
	 */
	public function get( int $activity_id, string $key ): mixed {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $this->db->get_var( $this->db->prepare( "SELECT meta_value FROM {$table} WHERE activity_id = %d AND meta_key = %s LIMIT 1", $activity_id, $key ) );

		return null === $value ? null : maybe_unserialize( (string) $value );
	}

	/**
	 * Write one value, replacing any existing.
	 *
	 * @param int    $activity_id Activity id.
	 * @param string $key         Key.
	 * @param mixed  $value       Value; serialised when not scalar.
	 */
	public function set( int $activity_id, string $key, mixed $value ): void {
		$this->remove( $activity_id, $key );

		$this->insert_row(
			array(
				'activity_id' => $activity_id,
				'meta_key'    => $key,
				'meta_value'  => maybe_serialize( $value ),
			)
		);
	}

	/**
	 * Delete one key, or every key when none is given.
	 *
	 * @param int         $activity_id Activity id.
	 * @param string|null $key         Key.
	 */
	public function remove( int $activity_id, ?string $key = null ): void {
		$where   = array( 'activity_id' => $activity_id );
		$formats = array( '%d' );

		if ( null !== $key ) {
			$where['meta_key'] = $key;
			$formats[]         = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->delete( $this->table(), $where, $formats );
	}
}
