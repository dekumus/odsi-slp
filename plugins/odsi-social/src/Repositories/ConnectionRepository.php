<?php
/**
 * Connections.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Symmetric pairs stored once as (low, high) with the initiator recorded.
 */
final class ConnectionRepository extends AbstractRepository {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_ACCEPTED = 'accepted';

	/**
	 * Per-request cache of accepted connection ids by user.
	 *
	 * @var array<int, int[]>
	 */
	private array $accepted_cache = array();

	/**
	 * Per-request pair cache keyed by "low:high". The privacy rule asks about
	 * the viewer and each author on a page.
	 *
	 * @var array<string, object|null>
	 */
	private array $pair_cache = array();

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'connections';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'user_low'     => '%d',
			'user_high'    => '%d',
			'initiator_id' => '%d',
			'status'       => '%s',
			'created_at'   => '%s',
			'accepted_at'  => '%s',
		);
	}

	/**
	 * The row for an unordered pair, in any status.
	 *
	 * @param int $a User id.
	 * @param int $b User id.
	 */
	public function find_pair( int $a, int $b ): ?object {
		[ $low, $high ] = $this->order( $a, $b );
		$key            = "{$low}:{$high}";

		if ( array_key_exists( $key, $this->pair_cache ) ) {
			return $this->pair_cache[ $key ];
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE user_low = %d AND user_high = %d", $low, $high ) );

		$this->pair_cache[ $key ] = $row ?: null;

		return $this->pair_cache[ $key ];
	}

	/**
	 * Warm the pair cache for one member against several others, in one query.
	 *
	 * @param int   $user_id User id.
	 * @param int[] $others  Other user ids.
	 */
	public function prime_pairs( int $user_id, array $others ): void {
		$others = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $others ),
					function ( int $other ) use ( $user_id ): bool {
						[ $low, $high ] = $this->order( $user_id, $other );

						return $other > 0 && $other !== $user_id && ! array_key_exists( "{$low}:{$high}", $this->pair_cache );
					}
				)
			)
		);

		if ( $user_id <= 0 || array() === $others ) {
			return;
		}

		$table = $this->table();
		$in    = implode( ',', array_fill( 0, count( $others ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $this->db->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE (user_low = %d AND user_high IN ({$in})) OR (user_high = %d AND user_low IN ({$in}))",
				array_merge( array( $user_id ), $others, array( $user_id ), $others )
			)
		);

		foreach ( $others as $other ) {
			[ $low, $high ]                       = $this->order( $user_id, $other );
			$this->pair_cache[ "{$low}:{$high}" ] = null;
		}

		foreach ( $rows as $row ) {
			$this->pair_cache[ "{$row->user_low}:{$row->user_high}" ] = $row;
		}
	}

	/**
	 * Drop the per-request caches.
	 */
	public function flush(): void {
		$this->accepted_cache = array();
		$this->pair_cache     = array();
	}

	/**
	 * Create a pending request.
	 *
	 * @param int $initiator_id Requesting user.
	 * @param int $recipient_id Requested user.
	 *
	 * @return int Row id.
	 */
	public function request( int $initiator_id, int $recipient_id ): int {
		[ $low, $high ] = $this->order( $initiator_id, $recipient_id );

		unset( $this->pair_cache[ "{$low}:{$high}" ] );

		return $this->insert_row(
			array(
				'user_low'     => $low,
				'user_high'    => $high,
				'initiator_id' => $initiator_id,
				'status'       => self::STATUS_PENDING,
				'created_at'   => $this->now(),
			)
		);
	}

	/**
	 * Move a pair to accepted.
	 *
	 * @param int $row_id Row id.
	 */
	public function accept( int $row_id ): bool {
		$this->flush();

		return $this->update_row(
			$row_id,
			array(
				'status'      => self::STATUS_ACCEPTED,
				'accepted_at' => $this->now(),
			)
		);
	}

	/**
	 * Delete by id, clearing the cache.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): bool {
		$this->flush();

		return parent::delete( $id );
	}

	/**
	 * Whether two users are connected.
	 *
	 * @param int $a User id.
	 * @param int $b User id.
	 */
	public function are_connected( int $a, int $b ): bool {
		$row = $this->find_pair( $a, $b );

		return $row && self::STATUS_ACCEPTED === $row->status;
	}

	/**
	 * Ids of a user's accepted connections. Cached per request.
	 *
	 * @param int $user_id User id.
	 *
	 * @return int[]
	 */
	public function ids_for( int $user_id ): array {
		if ( isset( $this->accepted_cache[ $user_id ] ) ) {
			return $this->accepted_cache[ $user_id ];
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $this->db->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"(SELECT user_high AS other FROM {$table} WHERE user_low = %d AND status = %s)
				 UNION
				 (SELECT user_low AS other FROM {$table} WHERE user_high = %d AND status = %s)",
				$user_id,
				self::STATUS_ACCEPTED,
				$user_id,
				self::STATUS_ACCEPTED
			)
		);

		$this->accepted_cache[ $user_id ] = array_map( 'intval', (array) $ids );

		return $this->accepted_cache[ $user_id ];
	}

	/**
	 * Pending requests sent to a user.
	 *
	 * @param int $user_id User id.
	 *
	 * @return object[]
	 */
	public function pending_for( int $user_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE (user_low = %d OR user_high = %d) AND status = %s AND initiator_id <> %d ORDER BY created_at DESC",
				$user_id,
				$user_id,
				self::STATUS_PENDING,
				$user_id
			)
		);
	}

	/**
	 * Pending requests a user has sent.
	 *
	 * @param int $user_id User id.
	 *
	 * @return object[]
	 */
	public function sent_by( int $user_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE initiator_id = %d AND status = %s ORDER BY created_at DESC", $user_id, self::STATUS_PENDING ) );
	}

	/**
	 * Delete every row involving a user.
	 *
	 * @param int $user_id User id.
	 *
	 * @return int[] Ids of users who were connected, so counts can be adjusted.
	 */
	public function delete_user( int $user_id ): array {
		$others = $this->ids_for( $user_id );
		$table  = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query( $this->db->prepare( "DELETE FROM {$table} WHERE user_low = %d OR user_high = %d", $user_id, $user_id ) );

		$this->flush();

		return $others;
	}

	/**
	 * Normalise a pair to (low, high).
	 *
	 * @param int $a User id.
	 * @param int $b User id.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function order( int $a, int $b ): array {
		return $a < $b ? array( $a, $b ) : array( $b, $a );
	}
}
