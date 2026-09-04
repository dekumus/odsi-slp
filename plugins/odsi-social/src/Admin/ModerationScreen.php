<?php
/**
 * Moderation queue screen.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Admin;

use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Moderation\Reports;
use ODSI\Social\Repositories\ReportRepository;
use ODSI\Social\Support\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Community → Moderation: open reports with their content, reporter, reason
 * and age, and a row action per resolution (SOC-MOD-015). Actions post to
 * admin-post with a nonce and go through the `Reports` service, which
 * re-checks the capability.
 */
final class ModerationScreen implements Bootable {

	public const SLUG  = 'odsi-social-moderation';
	public const NONCE = 'odsi_social_moderate';

	private const PER_PAGE = 20;

	/**
	 * Constructor.
	 *
	 * @param Reports $reports Reports.
	 */
	public function __construct( private Reports $reports ) {
	}

	/**
	 * Register hooks. The submenu registers after `AdminMenu` (priority 9).
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register' ), 10 );
		add_action( 'admin_post_' . self::NONCE, array( $this, 'handle' ) );
	}

	/**
	 * Register the submenu with an open-report badge, like Comments.
	 */
	public function register(): void {
		$open  = $this->reports->open_count();
		$label = __( 'Moderation', 'odsi-social' );

		if ( $open > 0 ) {
			$label .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
				$open,
				number_format_i18n( $open ),
				sprintf(
					/* translators: %s: number of open reports. */
					_n( '%s open report', '%s open reports', $open, 'odsi-social' ),
					number_format_i18n( $open )
				)
			);
		}

		add_submenu_page( AdminMenu::SLUG, __( 'Moderation', 'odsi-social' ), $label, Capabilities::MANAGE, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Render the queue.
	 */
	public function render(): void {
		$viewer = get_current_user_id();
		$status = sanitize_key( (string) ( $_GET['status'] ?? ReportRepository::STATUS_OPEN ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$status = in_array( $status, array( ReportRepository::STATUS_OPEN, ReportRepository::STATUS_DISMISSED, ReportRepository::STATUS_ACTIONED ), true ) ? $status : ReportRepository::STATUS_OPEN;
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$total  = $this->reports->count( $status );
		$rows   = $this->reports->list( $viewer, $status, $page, self::PER_PAGE );
		$rows   = $rows instanceof WP_Error ? array() : $rows;

		echo '<div class="wrap"><h1>' . esc_html__( 'Moderation', 'odsi-social' ) . '</h1>';

		$this->notice();

		echo '<ul class="subsubsub">';

		$labels = array(
			ReportRepository::STATUS_OPEN      => __( 'Open', 'odsi-social' ),
			ReportRepository::STATUS_DISMISSED => __( 'Dismissed', 'odsi-social' ),
			ReportRepository::STATUS_ACTIONED  => __( 'Actioned', 'odsi-social' ),
		);

		$links = array();

		foreach ( $labels as $key => $label ) {
			$links[] = sprintf(
				'<li><a href="%1$s" class="%2$s">%3$s <span class="count">(%4$s)</span></a></li>',
				esc_url( $this->url( array( 'status' => $key ) ) ),
				$key === $status ? 'current' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $this->reports->count( $key ) ) )
			);
		}

		echo implode( ' | ', $links ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.

		echo '<table class="widefat striped odsi-social-moderation"><thead><tr>';
		echo '<th>' . esc_html__( 'Reported', 'odsi-social' ) . '</th>';
		echo '<th>' . esc_html__( 'Reporter', 'odsi-social' ) . '</th>';
		echo '<th>' . esc_html__( 'Reason', 'odsi-social' ) . '</th>';
		echo '<th>' . esc_html__( 'Age', 'odsi-social' ) . '</th>';
		echo '<th>' . ( ReportRepository::STATUS_OPEN === $status ? esc_html__( 'Actions', 'odsi-social' ) : esc_html__( 'Resolution', 'odsi-social' ) ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Nothing to review.', 'odsi-social' ) . '</td></tr>';
		}

		foreach ( $rows as $report ) {
			$this->row( $report );
		}

		echo '</tbody></table>';

		echo wp_kses_post(
			(string) paginate_links(
				array(
					'total'   => (int) ceil( $total / self::PER_PAGE ),
					'current' => $page,
					'base'    => $this->url(
						array(
							'status' => $status,
							'paged'  => '%#%',
						)
					),
				)
			)
		);

		echo '</div>';
	}

	/**
	 * One table row.
	 *
	 * @param array<string, mixed> $report Presented report.
	 */
	private function row( array $report ): void {
		$object = (array) $report['object'];

		echo '<tr>';
		echo '<td><strong>' . esc_html( (string) $object['label'] ) . '</strong>';

		if ( '' !== (string) $object['author'] ) {
			echo ' <span class="description">' . esc_html( sprintf( /* translators: %s: member name. */ __( 'by %s', 'odsi-social' ), (string) $object['author'] ) ) . '</span>';
		}

		echo '<br />';

		if ( '' !== (string) $object['url'] ) {
			echo '<a href="' . esc_url( (string) $object['url'] ) . '">' . esc_html( (string) $object['excerpt'] ) . '</a>';
		} else {
			echo esc_html( (string) $object['excerpt'] );
		}

		if ( '' !== (string) $report['details'] ) {
			echo '<p class="description">' . esc_html( (string) $report['details'] ) . '</p>';
		}

		echo '</td>';

		$reporter = (array) $report['reporter'];
		echo '<td>' . ( '' !== (string) $reporter['url'] ? '<a href="' . esc_url( (string) $reporter['url'] ) . '">' . esc_html( (string) $reporter['name'] ) . '</a>' : esc_html( (string) $reporter['name'] ) ) . '</td>';
		echo '<td>' . esc_html( (string) $report['reason_label'] ) . '</td>';
		echo '<td>' . esc_html( (string) $report['age'] ) . '</td>';
		echo '<td>';

		if ( ReportRepository::STATUS_OPEN === (string) $report['status'] ) {
			$labels = array(
				'dismiss'                      => __( 'Dismiss', 'odsi-social' ),
				Reports::ACTION_DELETE_CONTENT => __( 'Delete content', 'odsi-social' ),
				Reports::ACTION_BAN_FROM_GROUP => __( 'Ban from group', 'odsi-social' ),
			);

			foreach ( (array) $report['actions'] as $action ) {
				$this->action_form( (int) $report['id'], (string) $action, $labels[ $action ] ?? (string) $action );
			}
		} else {
			echo esc_html( self::resolution_label( (string) $report['resolution'] ) );

			if ( '' !== (string) $report['resolved_by'] ) {
				echo ' <span class="description">' . esc_html( sprintf( /* translators: %s: admin name. */ __( 'by %s', 'odsi-social' ), (string) $report['resolved_by'] ) ) . '</span>';
			}
		}

		echo '</td></tr>';
	}

	/**
	 * Human label for a resolution.
	 *
	 * @param string $resolution Resolution key.
	 */
	public static function resolution_label( string $resolution ): string {
		return match ( $resolution ) {
			'dismissed'                    => __( 'Dismissed', 'odsi-social' ),
			Reports::ACTION_DELETE_CONTENT => __( 'Content deleted', 'odsi-social' ),
			Reports::ACTION_BAN_FROM_GROUP => __( 'Banned from group', 'odsi-social' ),
			'content_deleted'              => __( 'Content was deleted', 'odsi-social' ),
			default                        => $resolution,
		};
	}

	/**
	 * Inline row-action form.
	 *
	 * @param int    $report_id Report.
	 * @param string $operation dismiss, delete_content or ban_from_group.
	 * @param string $label     Button label.
	 */
	private function action_form( int $report_id, string $operation, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;margin-right:.5em">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::NONCE ) . '" />';
		echo '<input type="hidden" name="do" value="' . esc_attr( $operation ) . '" />';
		echo '<input type="hidden" name="report_id" value="' . esc_attr( (string) $report_id ) . '" />';
		submit_button( $label, 'dismiss' === $operation ? 'secondary small' : 'link-delete small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Feedback after a redirect.
	 */
	private function notice(): void {
		$notice = sanitize_key( (string) ( $_GET['notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only feedback flag.

		if ( 'done' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Report resolved.', 'odsi-social' ) . '</p></div>';
		} elseif ( 'error' === $notice ) {
			$message = sanitize_text_field( rawurldecode( wp_unslash( (string) ( $_GET['message'] ?? '' ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised inline.
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( '' !== $message ? $message : __( 'Something went wrong.', 'odsi-social' ) ) . '</p></div>';
		}
	}

	/**
	 * Handle a row action.
	 */
	public function handle(): void {
		check_admin_referer( self::NONCE );

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You cannot review reports.', 'odsi-social' ) );
		}

		$result = $this->process(
			get_current_user_id(),
			absint( $_POST['report_id'] ?? 0 ),
			sanitize_key( (string) ( $_POST['do'] ?? '' ) )
		);

		$args = $result instanceof WP_Error
			? array(
				'notice'  => 'error',
				'message' => rawurlencode( $result->get_error_message() ),
			)
			: array( 'notice' => 'done' );

		wp_safe_redirect( $this->url( $args ) );
		exit;
	}

	/**
	 * Apply a row action; the service re-checks the capability.
	 *
	 * @param int    $actor_id  Admin.
	 * @param int    $report_id Report.
	 * @param string $operation dismiss, delete_content or ban_from_group.
	 *
	 * @return true|WP_Error
	 */
	public function process( int $actor_id, int $report_id, string $operation ): bool|WP_Error {
		return match ( $operation ) {
			'dismiss' => $this->reports->dismiss( $actor_id, $report_id ),
			Reports::ACTION_DELETE_CONTENT, Reports::ACTION_BAN_FROM_GROUP => $this->reports->action( $actor_id, $report_id, $operation ),
			default   => new WP_Error( 'odsi_social_invalid_action', __( 'Unknown action.', 'odsi-social' ), array( 'status' => 400 ) ),
		};
	}

	/**
	 * Screen URL with query args.
	 *
	 * @param array<string, string|int> $args Query args.
	 */
	private function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + $args, admin_url( 'admin.php' ) );
	}
}
