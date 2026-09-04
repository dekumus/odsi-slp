<?php
/**
 * Group index rows.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * The query-serving mirror of each group post (ADR-015).
 */
final class GroupRepository extends AbstractRepository {

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'groups';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'post_id'        => '%d',
			'slug'           => '%s',
			'visibility'     => '%s',
			'member_count'   => '%d',
			'activity_count' => '%d',
			'last_active'    => '%s',
			'created_at'     => '%s',
		);
	}

	/**
	 * Fetch by group post id.
	 *
	 * @param int $id Group post id.
	 */
	public function find( int $id ): ?object {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $id ) );

		return $row ?: null;
	}

	/**
	 * Fetch by slug.
	 *
	 * @param string $slug Group slug.
	 */
	public function find_by_slug( string $slug ): ?object {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ) );

		return $row ?: null;
	}

	/**
	 * Delete by group post id.
	 *
	 * @param int $id Group post id.
	 */
	public function delete( int $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $this->db->delete( $this->table(), array( 'post_id' => $id ), array( '%d' ) );
	}

	/**
	 * Insert or update the mirror row.
	 *
	 * @param int                  $post_id Group post id.
	 * @param array<string, mixed> $data    Columns.
	 */
	public function mirror( int $post_id, array $data ): void {
		$existing = $this->find( $post_id );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->db->update( $this->table(), $data, array( 'post_id' => $post_id ), $this->formats_for( $data ), array( '%d' ) );

			return;
		}

		$data = $data + array(
			'post_id'     => $post_id,
			'last_active' => $this->now(),
			'created_at'  => $this->now(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->insert( $this->table(), $data, $this->formats_for( $data ) );
	}

	/**
	 * Add a delta to a counter.
	 *
	 * @param int    $post_id Group post id.
	 * @param string $column  `member_count` or `activity_count`.
	 * @param int    $delta   Delta.
	 */
	public function adjust( int $post_id, string $column, int $delta ): void {
		if ( ! in_array( $column, array( 'member_count', 'activity_count' ), true ) ) {
			return;
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"UPDATE {$table} SET {$column} = GREATEST(0, CAST({$column} AS SIGNED) + %d), last_active = %s WHERE post_id = %d",
				$delta,
				$this->now(),
				$post_id
			)
		);
	}

	/**
	 * Directory query over visible groups.
	 *
	 * @param array<string, mixed> $args `visibilities` (string[]), `include` ids, `search`, `orderby` (newest|members|active), `per_page`, `page`.
	 *
	 * @return array{ids: int[], total: int}
	 */
	public function directory( array $args ): array {
		$table    = $this->table();
		$posts    = $this->db->posts;
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );
		$params   = array();
		$where    = array( "p.post_status = 'publish'" );

		$visibilities = array_values( array_filter( array_map( 'strval', (array) ( $args['visibilities'] ?? array( 'public', 'private' ) ) ) ) );
		$include      = array_map( 'intval', (array) ( $args['include'] ?? array() ) );

		$clauses = array();

		if ( $visibilities ) {
			$clauses[] = 'g.visibility IN (' . implode( ',', array_fill( 0, count( $visibilities ), '%s' ) ) . ')';
			$params    = array_merge( $params, $visibilities );
		}

		if ( $include ) {
			$clauses[] = 'g.post_id IN (' . implode( ',', array_fill( 0, count( $include ), '%d' ) ) . ')';
			$params    = array_merge( $params, $include );
		}

		if ( $clauses ) {
			$where[] = '(' . implode( ' OR ', $clauses ) . ')';
		} else {
			$where[] = '1=0';
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'p.post_title LIKE %s';
			$params[] = '%' . $this->db->esc_like( (string) $args['search'] ) . '%';
		}

		$order = match ( (string) ( $args['orderby'] ?? 'newest' ) ) {
			'members' => 'g.member_count DESC, g.post_id DESC',
			'active'  => 'g.last_active DESC, g.post_id DESC',
			default   => 'g.created_at DESC, g.post_id DESC',
		};

		$sql = "FROM {$table} g INNER JOIN {$posts} p ON p.ID = g.post_id WHERE " . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) {$sql}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $this->db->get_col( $this->db->prepare( "SELECT g.post_id {$sql} ORDER BY {$order} LIMIT %d OFFSET %d", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'ids'   => array_map( 'intval', (array) $ids ),
			'total' => $total,
		);
	}
}
