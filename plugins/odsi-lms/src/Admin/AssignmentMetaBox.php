<?php
/**
 * Assignment settings meta box.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Admin;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Capabilities;
use ODSI\LMS\Support\Meta;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an author require an assignment on a lesson or topic.
 */
final class AssignmentMetaBox implements Bootable {

	private const NONCE_ACTION = 'odsi_lms_assignment';
	private const NONCE_FIELD  = 'odsi_lms_assignment_nonce';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Add the box.
	 */
	public function register(): void {
		foreach ( array( PostTypes::LESSON, PostTypes::TOPIC ) as $type ) {
			add_meta_box( 'odsi-lms-assignment', __( 'Assignment', 'odsi-lms' ), array( $this, 'render' ), $type, 'side' );
		}
	}

	/**
	 * Render.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$required = (bool) get_post_meta( $post->ID, Meta::ASSIGNMENT_REQUIRED, true );
		$points   = (int) get_post_meta( $post->ID, Meta::ASSIGNMENT_POINTS, true );
		$auto     = (bool) get_post_meta( $post->ID, Meta::ASSIGNMENT_AUTO_APPROVE, true );

		printf(
			'<p><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label></p>',
			esc_attr( Meta::ASSIGNMENT_REQUIRED ),
			checked( $required, true, false ),
			esc_html__( 'Learners must hand in an assignment', 'odsi-lms' )
		);
		printf(
			'<p><label>%1$s <input type="number" name="%2$s" value="%3$d" min="0" step="1" class="small-text" /></label><br /><span class="description">%4$s</span></p>',
			esc_html__( 'Points', 'odsi-lms' ),
			esc_attr( Meta::ASSIGNMENT_POINTS ),
			(int) $points,
			esc_html__( 'Zero means approve or reject only.', 'odsi-lms' )
		);
		printf(
			'<p><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label></p>',
			esc_attr( Meta::ASSIGNMENT_AUTO_APPROVE ),
			checked( $auto, true, false ),
			esc_html__( 'Approve automatically on receipt', 'odsi-lms' )
		);
		printf( '<p class="description">%s</p>', esc_html__( 'The step completes once a submission is approved. A lesson with topics cannot carry one.', 'odsi-lms' ) );
	}

	/**
	 * Save.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! in_array( $post->post_type, array( PostTypes::LESSON, PostTypes::TOPIC ), true ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE ) && ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, Meta::ASSIGNMENT_REQUIRED, ! empty( $_POST[ Meta::ASSIGNMENT_REQUIRED ] ) );
		update_post_meta( $post_id, Meta::ASSIGNMENT_POINTS, absint( wp_unslash( $_POST[ Meta::ASSIGNMENT_POINTS ] ?? 0 ) ) );
		update_post_meta( $post_id, Meta::ASSIGNMENT_AUTO_APPROVE, ! empty( $_POST[ Meta::ASSIGNMENT_AUTO_APPROVE ] ) );
	}
}
