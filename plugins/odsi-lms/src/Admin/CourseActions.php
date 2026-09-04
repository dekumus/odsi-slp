<?php
/**
 * Row actions on the courses list: duplicate.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Admin;

defined( 'ABSPATH' ) || exit;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Courses\Cloner;
use ODSI\LMS\PostTypes\PostTypes;
use WP_Post;

/**
 * "Duplicate" on a course row copies the course and its outline as drafts
 * and opens the copy (LMS-AUT-013).
 */
final class CourseActions implements Bootable {

	private const ACTION = 'odsi_lms_duplicate_course';

	/**
	 * Constructor.
	 *
	 * @param Cloner $cloner Cloner.
	 */
	public function __construct( private Cloner $cloner ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Add the link.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Post               $post    Post.
	 *
	 * @return array<string, string>
	 */
	public function row_actions( array $actions, WP_Post $post ): array {
		if ( PostTypes::COURSE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$actions['odsi_duplicate'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::url( $post->ID ) ),
			esc_html__( 'Duplicate', 'odsi-lms' )
		);

		return $actions;
	}

	/**
	 * Nonce-protected action URL.
	 *
	 * @param int $course_id Course.
	 */
	public static function url( int $course_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => self::ACTION,
					'course_id' => $course_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $course_id
		);
	}

	/**
	 * Copy and redirect to the editor of the copy.
	 */
	public function handle(): void {
		$course_id = absint( $_GET['course_id'] ?? 0 );
		check_admin_referer( self::ACTION . '_' . $course_id );

		$new_id = $this->cloner->duplicate( $course_id, get_current_user_id() );

		if ( $new_id <= 0 ) {
			wp_die( esc_html__( 'That course could not be duplicated.', 'odsi-lms' ), '', array( 'response' => 403 ) );
		}

		wp_safe_redirect( add_query_arg( array( 'odsi_duplicated' => 1 ), (string) get_edit_post_link( $new_id, 'raw' ) ) );
		exit;
	}

	/**
	 * Confirmation on the copy's edit screen.
	 */
	public function notice(): void {
		if ( empty( $_GET['odsi_duplicated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Course duplicated. This copy and every step in it are drafts.', 'odsi-lms' ) . '</p></div>';
	}
}
