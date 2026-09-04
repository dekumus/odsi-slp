<?php
/**
 * Issued certificates.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * The immutable award records.
 */
final class CertificateRepository extends AbstractRepository {

	/**
	 * Short schema key for the backing table.
	 */
	protected function table_key(): string {
		return 'certificates';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'user_id'        => '%d',
			'course_id'      => '%d',
			'certificate_id' => '%d',
			'code'           => '%s',
			'percentage'     => '%f',
			'issued_at'      => '%s',
			'expires_at'     => '%s',
			'revoked_at'     => '%s',
		);
	}

	/**
	 * The award for a user on a course, if any.
	 *
	 * @param int $user_id   User.
	 * @param int $course_id Course.
	 */
	public function find_for( int $user_id, int $course_id ): ?object {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND course_id = %d", $user_id, $course_id ) );

		return $row ?: null;
	}

	/**
	 * Look up by public code.
	 *
	 * @param string $code Code.
	 */
	public function find_by_code( string $code ): ?object {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ) );

		return $row ?: null;
	}

	/**
	 * Record an award.
	 *
	 * @param int    $user_id        User.
	 * @param int    $course_id      Course.
	 * @param int    $certificate_id Template post.
	 * @param string $code           Public code.
	 * @param float  $percentage     Score at award.
	 *
	 * @return int Row id.
	 */
	public function issue( int $user_id, int $course_id, int $certificate_id, string $code, float $percentage ): int {
		return $this->insert_row(
			array(
				'user_id'        => $user_id,
				'course_id'      => $course_id,
				'certificate_id' => $certificate_id,
				'code'           => $code,
				'percentage'     => $percentage,
				'issued_at'      => $this->now(),
			)
		);
	}

	/**
	 * Revoke.
	 *
	 * @param int $id Row id.
	 */
	public function revoke( int $id ): bool {
		return $this->update_row( $id, array( 'revoked_at' => $this->now() ) );
	}

	/**
	 * Every award for a user.
	 *
	 * @param int $user_id User.
	 *
	 * @return object[]
	 */
	public function for_user( int $user_id ): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY issued_at DESC", $user_id ) );
	}
}
