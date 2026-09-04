<?php
/**
 * Shared custom-table repository behaviour.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Repositories;

use ODSI\LMS\Contracts\Repository;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for repositories backed by a single custom table.
 *
 * All plugin SQL is funnelled through subclasses of this so that table names are
 * never built at call sites and every value goes through `$wpdb->prepare()`.
 */
abstract class AbstractRepository implements Repository {

	/**
	 * WordPress database handle.
	 */
	protected wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null $db Database handle. Defaults to the global `$wpdb`.
	 */
	public function __construct( ?wpdb $db = null ) {
		global $wpdb;

		$this->db = $db ?? $wpdb;
	}

	/**
	 * Short schema key for the backing table, e.g. `enrollments`.
	 */
	abstract protected function table_key(): string;

	/**
	 * Column formats keyed by column name, for `$wpdb->insert()`.
	 *
	 * @return array<string, string>
	 */
	abstract protected function formats(): array;

	/**
	 * Fully qualified table name.
	 */
	public function table(): string {
		return \ODSI\LMS\Database\Schema::table( $this->table_key() );
	}

	/**
	 * Fetch a single row by primary key.
	 *
	 * @param int $id Primary key.
	 */
	public function find( int $id ): ?object {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);

		return $row ?: null;
	}

	/**
	 * Delete a single row by primary key.
	 *
	 * @param int $id Primary key.
	 */
	public function delete( int $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $this->db->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Insert a row and return its new id.
	 *
	 * @param array<string, mixed> $data Column => value.
	 *
	 * @return int New primary key, or 0 on failure.
	 */
	protected function insert_row( array $data ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $this->db->insert( $this->table(), $data, $this->formats_for( $data ) );

		return $inserted ? (int) $this->db->insert_id : 0;
	}

	/**
	 * Update a row by primary key.
	 *
	 * @param int                  $id   Primary key.
	 * @param array<string, mixed> $data Column => value.
	 */
	protected function update_row( int $id, array $data ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $this->db->update(
			$this->table(),
			$data,
			array( 'id' => $id ),
			$this->formats_for( $data ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Ordered format specifiers matching the given data array.
	 *
	 * @param array<string, mixed> $data Column => value.
	 *
	 * @return string[]
	 */
	protected function formats_for( array $data ): array {
		$formats = $this->formats();

		return array_map(
			static fn ( string $column ): string => $formats[ $column ] ?? '%s',
			array_keys( $data )
		);
	}

	/**
	 * Current time in MySQL format, in UTC.
	 */
	protected function now(): string {
		return current_time( 'mysql', true );
	}
}
