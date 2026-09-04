<?php
/**
 * Content reports.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * One row per (reporter, object) complaint and its resolution (SOC-MOD-010).
 */
final class ReportRepository extends AbstractRepository {

	public const STATUS_OPEN      = 'open';
	public const STATUS_DISMISSED = 'dismissed';
	public const STATUS_ACTIONED  = 'actioned';

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'reports';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'reporter_id' => '%d',
			'object_type' => '%s',
			'object_id'   => '%d',
			'reason'      => '%s',
			'details'     => '%s',
			'status'      => '%s',
			'created_at'  => '%s',
			'resolved_at' => '%s',
			'resolved_by' => '%d',
			'resolution'  => '%s',
		);
	}

	/**
	 * Insert an open report.
	 *
	 * @param int    $reporter_id Reporter.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param string $reason      Reason key.
	 * @param string $details     Free text.
	 *
	 * @return int New id.
	 */
	public function create( int $reporter_id, string $object_type, int $object_id, string $reason, string $details ): int {
		return $this->insert_row(
			array(
				'reporter_id' => $reporter_id,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'reason'      => $reason,
				'details'     => $details,
				'status'      => self::STATUS_OPEN,
				'created_at'  => $this->now(),
			)
		);
	}

	/**
	 * The reporter's open report on an object, if any (one per actor per object).
	 *
	 * @param int    $reporter_id Reporter.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 */
	public function find_open( int $reporter_id, string $object_type, int $object_id ): ?object {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE reporter_id = %d AND object_type = %s AND object_id = %d AND status = %s ORDER BY id DESC LIMIT 1",
				$reporter_id,
				$object_type,
				$object_id,
				self::STATUS_OPEN
			)
		);

		return $row ?: null;
	}

	/**
	 * Open reports about an object.
	 *
	 * @param string[] $object_types Object types.
	 * @param int      $object_id    Object id.
	 *
	 * @return object[]
	 */
	public function open_for_object( array $object_types, int $object_id ): array {
		$object_types = array_values( array_map( 'strval', $object_types ) );

		if ( array() === $object_types ) {
			return array();
		}

		$table = $this->table();
		$in    = implode( ',', array_fill( 0, count( $object_types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE object_type IN ({$in}) AND object_id = %d AND status = %s",
				array_merge( $object_types, array( $object_id, self::STATUS_OPEN ) )
			)
		);
	}

	/**
	 * A page of reports in a status, newest first.
	 *
	 * @param string $status Status.
	 * @param int    $limit  Limit.
	 * @param int    $offset Offset.
	 *
	 * @return object[]
	 */
	public function list( string $status, int $limit = 20, int $offset = 0 ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $status, $limit, $offset )
		);
	}

	/**
	 * How many reports are in a status.
	 *
	 * @param string $status Status.
	 */
	public function count( string $status ): int {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
	}

	/**
	 * Close a report.
	 *
	 * @param int    $id          Report id.
	 * @param string $status      `dismissed` or `actioned`.
	 * @param int    $resolved_by Admin, or 0 for the system.
	 * @param string $resolution  What was done.
	 */
	public function resolve( int $id, string $status, int $resolved_by, string $resolution ): bool {
		return $this->update_row(
			$id,
			array(
				'status'      => $status,
				'resolved_at' => $this->now(),
				'resolved_by' => $resolved_by,
				'resolution'  => $resolution,
			)
		);
	}

	/**
	 * Delete resolved reports older than a cutoff (retention, SOC-MOD-016).
	 *
	 * @param string $before MySQL datetime.
	 *
	 * @return int Rows removed.
	 */
	public function purge_resolved_before( string $before ): int {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->query( $this->db->prepare( "DELETE FROM {$table} WHERE status <> %s AND resolved_at IS NOT NULL AND resolved_at < %s", self::STATUS_OPEN, $before ) );
	}
}
