<?php
/**
 * Repository contract.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Read/write access to a single custom table.
 *
 * @template TModel of object
 */
interface Repository {

	/**
	 * Fully qualified table name, including the `$wpdb` prefix.
	 */
	public function table(): string;

	/**
	 * Fetch a single row by primary key.
	 *
	 * @param int $id Primary key.
	 *
	 * @return TModel|null
	 */
	public function find( int $id ): ?object;

	/**
	 * Delete a single row by primary key.
	 *
	 * @param int $id Primary key.
	 *
	 * @return bool True when a row was removed.
	 */
	public function delete( int $id ): bool;
}
