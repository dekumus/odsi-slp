<?php
/**
 * Enrollment list table.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Admin;

use ODSI\LMS\Reports\EnrollmentReport;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Paginated, sortable, filterable enrollment rows (LMS-ADM-002).
 */
final class EnrollmentListTable extends \WP_List_Table {

	/**
	 * Constructor.
	 *
	 * @param EnrollmentReport $report    Report queries.
	 * @param int              $course_id Course.
	 */
	public function __construct( private EnrollmentReport $report, private int $course_id ) {
		parent::__construct(
			array(
				'singular' => 'enrollment',
				'plural'   => 'enrollments',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'display_name' => __( 'Learner', 'odsi-lms' ),
			'status'       => __( 'Status', 'odsi-lms' ),
			'percentage'   => __( 'Progress', 'odsi-lms' ),
			'enrolled_at'  => __( 'Enrolled', 'odsi-lms' ),
			'completed_at' => __( 'Completed', 'odsi-lms' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns(): array {
		return array(
			'display_name' => array( 'display_name', false ),
			'status'       => array( 'status', false ),
			'percentage'   => array( 'percentage', false ),
			'enrolled_at'  => array( 'enrolled_at', true ),
			'completed_at' => array( 'completed_at', false ),
		);
	}

	/**
	 * Load the page.
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$per_page = 20;
		$page     = $this->get_pagenum();
		$result   = $this->report->rows(
			$this->course_id,
			array(
				'status'   => sanitize_key( (string) ( $_GET['status'] ?? '' ) ),
				'search'   => sanitize_text_field( wp_unslash( (string) ( $_GET['s'] ?? '' ) ) ),
				'orderby'  => sanitize_key( (string) ( $_GET['orderby'] ?? 'enrolled_at' ) ),
				'order'    => sanitize_key( (string) ( $_GET['order'] ?? 'desc' ) ),
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
		// phpcs:enable

		$this->items           = $result['rows'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Status filter above the table.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$current = sanitize_key( (string) ( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="alignleft actions"><select name="status">';
		printf( '<option value="">%s</option>', esc_html__( 'All statuses', 'odsi-lms' ) );

		foreach ( array( 'active', 'completed', 'expired', 'cancelled', 'pending' ) as $status ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $status ), selected( $current, $status, false ), esc_html( ucfirst( $status ) ) );
		}

		echo '</select>';
		submit_button( __( 'Filter', 'odsi-lms' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Default cell.
	 *
	 * @param array<string, mixed> $item        Row.
	 * @param string               $column_name Column.
	 */
	protected function column_default( $item, $column_name ): string {
		return match ( $column_name ) {
			'status'       => esc_html( ucfirst( (string) $item['status'] ) ),
			'percentage'   => esc_html( (string) $item['percentage'] ) . '%',
			'enrolled_at'  => esc_html( (string) $item['enrolled_at'] ),
			'completed_at' => esc_html( (string) $item['completed_at'] ?: '—' ),
			default        => '',
		};
	}

	/**
	 * Learner cell with row actions.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_display_name( array $item ): string {
		$actions = array();

		foreach ( array(
			'reset'  => __( 'Reset progress', 'odsi-lms' ),
			'remove' => __( 'Remove', 'odsi-lms' ),
		) as $operation => $label ) {
			$actions[ $operation ] = sprintf(
				'<form method="post" action="%1$s" style="display:inline">%2$s<input type="hidden" name="action" value="odsi_lms_report_action" /><input type="hidden" name="do" value="%3$s" /><input type="hidden" name="course_id" value="%4$d" /><input type="hidden" name="user_id" value="%5$d" /><button type="submit" class="button-link">%6$s</button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				wp_nonce_field( ReportsScreen::nonce_action(), '_wpnonce', true, false ),
				esc_attr( $operation ),
				$this->course_id,
				(int) $item['user_id'],
				esc_html( $label )
			);
		}

		return sprintf( '<strong>%s</strong><br /><span class="description">%s</span>%s', esc_html( (string) $item['display_name'] ), esc_html( (string) $item['email'] ), $this->row_actions( $actions ) );
	}
}
