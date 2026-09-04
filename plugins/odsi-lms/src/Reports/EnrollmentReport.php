<?php
/**
 * Enrollment and progress reporting queries.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Reports;

use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Database\Schema;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Capabilities;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The queries behind the enrollment report (LMS-ADM-002) and the course
 * summary, kept out of the screen class so they can be tested and reused by
 * REST or CSV export.
 */
final class EnrollmentReport {

	/**
	 * Database.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param Progress  $progress Progress service.
	 * @param wpdb|null $db       Database.
	 */
	public function __construct( private Progress $progress, ?wpdb $db = null ) {
		global $wpdb;

		$this->db = $db ?? $wpdb;
	}

	/**
	 * Courses a user may report on: all for managers, own for instructors.
	 *
	 * @param int $user_id User.
	 *
	 * @return int[] Course ids.
	 */
	public function reportable_courses( int $user_id ): array {
		$args = array(
			'post_type'      => PostTypes::COURSE,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- admin picker.
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		);

		if ( ! user_can( $user_id, Capabilities::MANAGE ) ) {
			$args['author'] = $user_id;
		}

		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * Whether a user may see a course's report.
	 *
	 * @param int $user_id   User.
	 * @param int $course_id Course.
	 */
	public function can_report( int $user_id, int $course_id ): bool {
		if ( ! user_can( $user_id, Capabilities::REPORT ) ) {
			return false;
		}

		return user_can( $user_id, Capabilities::MANAGE ) || (int) get_post_field( 'post_author', $course_id ) === $user_id;
	}

	/**
	 * A page of enrollments with progress.
	 *
	 * @param int                  $course_id Course.
	 * @param array<string, mixed> $args      `status`, `search`, `orderby` (enrolled_at|status|display_name|percentage), `order`, `page`, `per_page`.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function rows( int $course_id, array $args = array() ): array {
		$args = array_merge(
			array(
				'status'   => '',
				'search'   => '',
				'orderby'  => 'enrolled_at',
				'order'    => 'DESC',
				'page'     => 1,
				'per_page' => 20,
			),
			$args
		);

		$table    = Schema::table( 'enrollments' );
		$users    = $this->db->users;
		$where    = array( 'e.course_id = %d' );
		$params   = array( $course_id );
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		if ( '' !== $args['status'] ) {
			$where[]  = 'e.status = %s';
			$params[] = (string) $args['status'];
		}

		if ( '' !== $args['search'] ) {
			$like     = '%' . $this->db->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$orderby = match ( (string) $args['orderby'] ) {
			'status'       => 'e.status',
			'display_name' => 'u.display_name',
			'completed_at' => 'e.completed_at',
			default        => 'e.enrolled_at',
		};
		$order = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$sql   = "FROM {$table} e INNER JOIN {$users} u ON u.ID = e.user_id WHERE " . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) {$sql}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$by_percentage = 'percentage' === (string) $args['orderby'];

		if ( $by_percentage ) {
			// Percentages are derived, so the sort has to see the whole roster
			// (bounded, then sliced) rather than one page of it.
			$params[] = 5000;
			$params[] = 0;
		} else {
			$params[] = $per_page;
			$params[] = $offset;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $this->db->get_results( $this->db->prepare( "SELECT e.*, u.display_name, u.user_email {$sql} ORDER BY {$orderby} {$order}, e.id DESC LIMIT %d OFFSET %d", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out         = array();
		$percentages = $this->progress->course_percentages( array_map( static fn ( object $r ): int => (int) $r->user_id, $rows ), $course_id );

		foreach ( $rows as $row ) {
			$out[] = array(
				'user_id'      => (int) $row->user_id,
				'display_name' => (string) $row->display_name,
				'email'        => (string) $row->user_email,
				'status'       => (string) $row->status,
				'source'       => (string) $row->source,
				'enrolled_at'  => (string) $row->enrolled_at,
				'completed_at' => (string) ( $row->completed_at ?? '' ),
				'expires_at'   => (string) ( $row->expires_at ?? '' ),
				'percentage'   => $percentages[ (int) $row->user_id ] ?? 0.0,
			);
		}

		if ( $by_percentage ) {
			$percentages = array_column( $out, 'percentage' );
			array_multisort( $percentages, 'ASC' === $order ? SORT_ASC : SORT_DESC, SORT_NUMERIC, $out );
			$out = array_slice( $out, $offset, $per_page );
		}

		return array(
			'rows'  => $out,
			'total' => $total,
		);
	}

	/**
	 * Write a course's enrollment report as CSV to an open stream (LMS-ADM-006).
	 *
	 * Rows are fetched in pages so a large course never lands in memory at
	 * once. Cells that start with a formula character are prefixed with an
	 * apostrophe so a spreadsheet does not execute a learner's display name.
	 *
	 * @param int      $course_id Course.
	 * @param string   $status    Restrict to a status; empty for all.
	 * @param resource $handle    Writable stream.
	 *
	 * @return int Rows written, excluding the header.
	 */
	public function export_csv( int $course_id, string $status, $handle ): int {
		$columns = array(
			'user_id'      => 'user_id',
			'display_name' => __( 'Name', 'odsi-lms' ),
			'email'        => __( 'Email', 'odsi-lms' ),
			'status'       => __( 'Status', 'odsi-lms' ),
			'source'       => __( 'Source', 'odsi-lms' ),
			'enrolled_at'  => __( 'Enrolled', 'odsi-lms' ),
			'completed_at' => __( 'Completed', 'odsi-lms' ),
			'expires_at'   => __( 'Expires', 'odsi-lms' ),
			'percentage'   => __( 'Progress %', 'odsi-lms' ),
		);

		/**
		 * Filters the columns of the enrollment CSV export.
		 *
		 * @param array<string, string> $columns   Row key => header label.
		 * @param int                   $course_id Course.
		 */
		$columns = (array) apply_filters( 'odsi_lms_report_csv_columns', $columns, $course_id );

		fputcsv( $handle, array_values( $columns ) );

		$page    = 1;
		$written = 0;

		do {
			$result = $this->rows(
				$course_id,
				array(
					'status'   => $status,
					'orderby'  => 'enrolled_at',
					'order'    => 'ASC',
					'page'     => $page,
					'per_page' => 200,
				)
			);

			foreach ( $result['rows'] as $row ) {
				/**
				 * Filters one row of the enrollment CSV export.
				 *
				 * @param array<string, mixed> $row       Row.
				 * @param int                  $course_id Course.
				 */
				$row  = (array) apply_filters( 'odsi_lms_report_csv_row', $row, $course_id );
				$cell = array();

				foreach ( array_keys( $columns ) as $key ) {
					$cell[] = self::csv_safe( (string) ( $row[ $key ] ?? '' ) );
				}

				fputcsv( $handle, $cell );
				++$written;
			}

			++$page;
		} while ( array() !== $result['rows'] && $written < $result['total'] );

		return $written;
	}

	/**
	 * Neutralise spreadsheet formula injection.
	 *
	 * @param string $value Cell.
	 */
	private static function csv_safe( string $value ): string {
		return '' !== $value && str_contains( "=+-@\t\r\n", $value[0] ) ? "'" . $value : $value;
	}

	/**
	 * Headline numbers for a course.
	 *
	 * @param int $course_id Course.
	 *
	 * @return array{enrolled: int, active: int, completed: int, expired: int, completion_rate: float}
	 */
	public function summary( int $course_id ): array {
		$table = Schema::table( 'enrollments' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $this->db->get_results( $this->db->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE course_id = %d GROUP BY status", $course_id ) );

		$counts = array(
			'active'    => 0,
			'completed' => 0,
			'expired'   => 0,
			'cancelled' => 0,
			'pending'   => 0,
		);

		foreach ( $rows as $row ) {
			$counts[ (string) $row->status ] = (int) $row->n;
		}

		$enrolled = $counts['active'] + $counts['completed'] + $counts['expired'];

		return array(
			'enrolled'        => $enrolled,
			'active'          => $counts['active'],
			'completed'       => $counts['completed'],
			'expired'         => $counts['expired'],
			'completion_rate' => $enrolled > 0 ? round( $counts['completed'] / $enrolled * 100, 2 ) : 0.0,
		);
	}

	/**
	 * Answers awaiting manual grading, oldest first (LMS-ADM-004).
	 *
	 * @param int[] $course_ids Restrict to these courses; empty for all.
	 * @param int   $limit      Limit.
	 * @param int   $offset     Offset.
	 *
	 * @return array{rows: object[], total: int}
	 */
	public function grading_queue( array $course_ids = array(), int $limit = 20, int $offset = 0 ): array {
		$answers  = Schema::table( 'quiz_answers' );
		$attempts = Schema::table( 'quiz_attempts' );
		$where    = array( 'a.needs_grading = 1', 'q.status = %s' );
		$params   = array( 'completed' );

		if ( array() !== $course_ids ) {
			$ids     = array_map( 'intval', $course_ids );
			$where[] = 'q.course_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $ids );
		}

		$sql = "FROM {$answers} a INNER JOIN {$attempts} q ON q.id = a.attempt_id WHERE " . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) {$sql}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $this->db->get_results( $this->db->prepare( "SELECT a.*, q.user_id, q.quiz_id, q.course_id {$sql} ORDER BY a.answered_at ASC, a.id ASC LIMIT %d OFFSET %d", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}
}
