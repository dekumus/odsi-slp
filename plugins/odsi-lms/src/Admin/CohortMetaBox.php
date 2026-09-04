<?php
/**
 * Cohort meta boxes.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Admin;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Courses\Cohorts;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Capabilities;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Courses and members of a cohort.
 */
final class CohortMetaBox implements Bootable {

	private const NONCE = 'odsi_lms_cohort';

	/**
	 * Constructor.
	 *
	 * @param Cohorts $cohorts Cohort service.
	 */
	public function __construct( private Cohorts $cohorts ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'add_meta_boxes_' . PostTypes::COHORT, array( $this, 'register' ) );
		add_action( 'save_post_' . PostTypes::COHORT, array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Register the boxes.
	 */
	public function register(): void {
		add_meta_box( 'odsi-lms-cohort-courses', __( 'Courses', 'odsi-lms' ), array( $this, 'render_courses' ), PostTypes::COHORT, 'normal' );
		add_meta_box( 'odsi-lms-cohort-members', __( 'Members', 'odsi-lms' ), array( $this, 'render_members' ), PostTypes::COHORT, 'normal' );
	}

	/**
	 * Course checklist.
	 *
	 * @param WP_Post $post Cohort.
	 */
	public function render_courses( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );

		$selected = $this->cohorts->courses( $post->ID );
		$courses  = get_posts(
			array(
				'post_type'      => PostTypes::COURSE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- admin picker.
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $courses as $course ) {
			printf(
				'<label style="display:block"><input type="checkbox" name="odsi_cohort_courses[]" value="%1$d" %2$s /> %3$s</label>',
				(int) $course->ID,
				checked( in_array( (int) $course->ID, $selected, true ), true, false ),
				esc_html( $course->post_title )
			);
		}

		if ( array() === $courses ) {
			echo '<p>' . esc_html__( 'Create a course first.', 'odsi-lms' ) . '</p>';
		}
	}

	/**
	 * Member list and add form.
	 *
	 * @param WP_Post $post Cohort.
	 */
	public function render_members( WP_Post $post ): void {
		$members = $this->cohorts->members( $post->ID );
		cache_users( $members );

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Member', 'odsi-lms' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $members as $user_id ) {
			$user = get_userdata( $user_id );
			printf(
				'<tr><td>%1$s</td><td><label><input type="checkbox" name="odsi_cohort_remove[]" value="%2$d" /> %3$s</label></td></tr>',
				esc_html( $user ? $user->display_name . ' (' . $user->user_email . ')' : '#' . $user_id ),
				(int) $user_id,
				esc_html__( 'Remove', 'odsi-lms' )
			);
		}

		echo '</tbody></table>';
		echo '<p><label>' . esc_html__( 'Add members (usernames or emails, comma separated)', 'odsi-lms' ) . '<br /><input type="text" name="odsi_cohort_add" class="large-text" /></label></p>';
	}

	/**
	 * Persist.
	 *
	 * @param int     $post_id Cohort.
	 * @param WP_Post $post    Cohort.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! isset( $_POST[ self::NONCE ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE ] ) ), self::NONCE ) || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		$this->cohorts->set_courses( $post_id, array_map( 'absint', (array) ( $_POST['odsi_cohort_courses'] ?? array() ) ) );

		foreach ( array_map( 'absint', (array) ( $_POST['odsi_cohort_remove'] ?? array() ) ) as $user_id ) {
			$this->cohorts->remove_member( $post_id, $user_id );
		}

		$needles = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( (string) ( $_POST['odsi_cohort_add'] ?? '' ) ) ) ) ) );

		foreach ( $needles as $needle ) {
			$user = is_email( $needle ) ? get_user_by( 'email', $needle ) : get_user_by( 'login', $needle );

			if ( $user ) {
				$this->cohorts->add_member( $post_id, (int) $user->ID );
			}
		}
	}
}
