<?php
/**
 * Member index rows.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * The per-member index row: presence, denormalised counts, media, settings.
 */
final class MemberRepository extends AbstractRepository {

	/**
	 * Per-request row cache keyed by user id. Every feed row asks for its
	 * author's avatar, so this lookup must not cost a query per row.
	 *
	 * @var array<int, object|null>
	 */
	private array $cache = array();

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'members';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'user_id'          => '%d',
			'last_active'      => '%s',
			'activity_count'   => '%d',
			'connection_count' => '%d',
			'follower_count'   => '%d',
			'following_count'  => '%d',
			'avatar_id'        => '%d',
			'cover_id'         => '%d',
			'message_setting'  => '%s',
			'created_at'       => '%s',
		);
	}

	/**
	 * Fetch by user id (the primary key here, not `id`).
	 *
	 * @param int $id User id.
	 */
	public function find( int $id ): ?object {
		if ( array_key_exists( $id, $this->cache ) ) {
			return $this->cache[ $id ];
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $id ) );

		$this->cache[ $id ] = $row ?: null;

		return $this->cache[ $id ];
	}

	/**
	 * Warm the cache for several users in one query.
	 *
	 * @param int[] $ids User ids.
	 */
	public function prime( array $ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), fn ( int $id ): bool => ! array_key_exists( $id, $this->cache ) ) ) );

		if ( array() === $ids ) {
			return;
		}

		$table = $this->table();
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE user_id IN ({$in})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $ids as $id ) {
			$this->cache[ $id ] = null;
		}

		foreach ( $rows as $row ) {
			$this->cache[ (int) $row->user_id ] = $row;
		}
	}

	/**
	 * Warm everything a member card needs — the user object, the index row and
	 * the uploaded avatar and cover attachments — in at most four queries, so a
	 * list of members costs no lookup per row.
	 *
	 * @param int[] $ids User ids.
	 */
	public function prime_display( array $ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( array() === $ids ) {
			return;
		}

		cache_users( $ids );
		$this->prime( $ids );

		$attachments = array();

		foreach ( $ids as $id ) {
			$row = $this->cache[ $id ] ?? null;

			if ( $row ) {
				$attachments[] = (int) $row->avatar_id;
				$attachments[] = (int) $row->cover_id;
			}
		}

		$attachments = array_values( array_unique( array_filter( $attachments ) ) );

		if ( array() !== $attachments ) {
			_prime_post_caches( $attachments, false, true );
		}
	}

	/**
	 * Drop the per-request cache.
	 */
	public function flush(): void {
		$this->cache = array();
	}

	/**
	 * Forget a cached row.
	 *
	 * @param int $id User id.
	 */
	private function forget( int $id ): void {
		unset( $this->cache[ $id ] );
	}

	/**
	 * Delete by user id.
	 *
	 * @param int $id User id.
	 */
	public function delete( int $id ): bool {
		$this->forget( $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $this->db->delete( $this->table(), array( 'user_id' => $id ), array( '%d' ) );
	}

	/**
	 * Fetch or create the row for a user.
	 *
	 * @param int $user_id User id.
	 */
	public function ensure( int $user_id ): object {
		$row = $this->find( $user_id );

		if ( $row ) {
			return $row;
		}

		$now = $this->now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->insert(
			$this->table(),
			array(
				'user_id'     => $user_id,
				'last_active' => $now,
				'created_at'  => $now,
			),
			array( '%d', '%s', '%s' )
		);

		$this->forget( $user_id );

		return $this->find( $user_id ) ?? (object) array(
			'user_id'     => $user_id,
			'last_active' => $now,
		);
	}

	/**
	 * Update columns for a user. A row that does not exist is not created:
	 * callers that need one (a member editing their own settings) call
	 * `ensure()` first, so a purged member is never resurrected by a count.
	 *
	 * @param int                  $user_id User id.
	 * @param array<string, mixed> $data    Column => value.
	 */
	public function update( int $user_id, array $data ): bool {
		$this->forget( $user_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $this->db->update( $this->table(), $data, array( 'user_id' => $user_id ), $this->formats_for( $data ), array( '%d' ) );

		return false !== $updated;
	}

	/**
	 * Add a delta to a counter column, never below zero.
	 *
	 * @param int    $user_id User id.
	 * @param string $column  Counter column.
	 * @param int    $delta   Positive or negative.
	 */
	public function adjust( int $user_id, string $column, int $delta ): void {
		if ( ! in_array( $column, array( 'activity_count', 'connection_count', 'follower_count', 'following_count' ), true ) ) {
			return;
		}

		$this->forget( $user_id );
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"UPDATE {$table} SET {$column} = GREATEST(0, CAST({$column} AS SIGNED) + %d) WHERE user_id = %d",
				$delta,
				$user_id
			)
		);
	}

	/**
	 * Recount every denormalised counter from its source table (maintenance).
	 */
	public function recount(): void {
		$table       = $this->table();
		$activity    = \ODSI\Social\Database\Schema::table( 'activity' );
		$connections = \ODSI\Social\Database\Schema::table( 'connections' );
		$follows     = \ODSI\Social\Database\Schema::table( 'follows' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"UPDATE {$table} m SET
					activity_count = (SELECT COUNT(*) FROM {$activity} a WHERE a.user_id = m.user_id AND a.parent_id = 0 AND a.status = %s),
					connection_count = (SELECT COUNT(*) FROM {$connections} c WHERE (c.user_low = m.user_id OR c.user_high = m.user_id) AND c.status = %s),
					follower_count = (SELECT COUNT(*) FROM {$follows} f WHERE f.following_id = m.user_id),
					following_count = (SELECT COUNT(*) FROM {$follows} f WHERE f.follower_id = m.user_id)",
				ActivityRepository::STATUS_PUBLISHED,
				ConnectionRepository::STATUS_ACCEPTED
			)
		);

		$this->flush();
	}

	/**
	 * Directory query. Only members who have been active at least once are
	 * listed (SOC-MEM-008); an index row alone is not enough.
	 *
	 * @param array<string, mixed> $args `search`, `orderby` (newest|active|alphabetical), `per_page`, `page`, `include` ids, `exclude` ids.
	 *
	 * @return array{ids: int[], total: int}
	 */
	public function directory( array $args ): array {
		$table    = $this->table();
		$users    = $this->db->users;
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );
		$where    = array( "m.last_active > '0000-00-00 00:00:00'" );
		$params   = array();

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $this->db->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(u.display_name LIKE %s OR u.user_nicename LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $args['include'] ) ) {
			$ids     = array_map( 'intval', (array) $args['include'] );
			$where[] = 'u.ID IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $ids );
		}

		if ( ! empty( $args['exclude'] ) ) {
			$ids     = array_map( 'intval', (array) $args['exclude'] );
			$where[] = 'u.ID NOT IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $ids );
		}

		$order = match ( (string) ( $args['orderby'] ?? 'newest' ) ) {
			'active'       => 'm.last_active DESC, u.ID DESC',
			'alphabetical' => 'u.display_name ASC, u.ID ASC',
			default        => 'm.created_at DESC, u.ID DESC',
		};

		$sql = "FROM {$table} m INNER JOIN {$users} u ON u.ID = m.user_id WHERE " . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $this->db->get_var( $params ? $this->db->prepare( "SELECT COUNT(*) {$sql}", $params ) : "SELECT COUNT(*) {$sql}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $this->db->get_col( $this->db->prepare( "SELECT u.ID {$sql} ORDER BY {$order} LIMIT %d OFFSET %d", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'ids'   => array_map( 'intval', (array) $ids ),
			'total' => $total,
		);
	}
}
