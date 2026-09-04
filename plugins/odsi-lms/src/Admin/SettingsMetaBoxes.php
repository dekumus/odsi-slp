<?php
/**
 * Classic-editor settings boxes for courses, lessons, topics and quizzes.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Admin;

defined( 'ABSPATH' ) || exit;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Meta;
use ODSI\LMS\Support\Settings;
use WP_Post;
use WP_Query;

/**
 * Every setting the runtime reads from post meta can be written here without
 * the block editor's sidebar (LMS-AUT-008). Values are also exposed to the
 * block editor through `show_in_rest`, so both editors agree.
 */
final class SettingsMetaBoxes implements Bootable {

	private const NONCE_ACTION = 'odsi_lms_settings_box';
	private const NONCE_FIELD  = 'odsi_lms_settings_nonce';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings (defaults).
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Add the boxes.
	 */
	public function register(): void {
		add_meta_box( 'odsi-lms-course-settings', __( 'Course settings', 'odsi-lms' ), array( $this, 'render_course' ), PostTypes::COURSE, 'side' );
		add_meta_box( 'odsi-lms-step-settings', __( 'Release and duration', 'odsi-lms' ), array( $this, 'render_step' ), array( PostTypes::LESSON, PostTypes::TOPIC ), 'side' );
		add_meta_box( 'odsi-lms-quiz-settings', __( 'Quiz settings', 'odsi-lms' ), array( $this, 'render_quiz' ), PostTypes::QUIZ, 'side' );
	}

	/**
	 * Course box.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_course( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$mode  = (string) get_post_meta( $post->ID, Meta::ACCESS_MODE, true );
		$modes = array(
			'open'   => __( 'Open: anyone may view, visitors are enrolled on first visit', 'odsi-lms' ),
			'free'   => __( 'Free: logged-in learners enroll themselves', 'odsi-lms' ),
			'paid'   => __( 'Paid: enrollment through a commerce integration', 'odsi-lms' ),
			'closed' => __( 'Closed: an administrator enrolls learners', 'odsi-lms' ),
		);

		echo '<p><label for="odsi-access-mode"><strong>' . esc_html__( 'Access mode', 'odsi-lms' ) . '</strong></label><br />';
		echo '<select id="odsi-access-mode" name="' . esc_attr( Meta::ACCESS_MODE ) . '" style="width:100%">';
		foreach ( $modes as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $mode ?: 'free', $value, false ), esc_html( $label ) );
		}
		echo '</select></p>';

		$this->number( Meta::PRICE, __( 'Price', 'odsi-lms' ), (string) get_post_meta( $post->ID, Meta::PRICE, true ), '0.01', __( 'Shown on paid courses; charged by the integration.', 'odsi-lms' ) );
		$this->number( Meta::ACCESS_DAYS, __( 'Access expires after (days)', 'odsi-lms' ), (string) (int) get_post_meta( $post->ID, Meta::ACCESS_DAYS, true ), '1', __( 'Zero keeps access indefinitely.', 'odsi-lms' ) );
		$this->number( Meta::DURATION, __( 'Duration (minutes)', 'odsi-lms' ), (string) (int) get_post_meta( $post->ID, Meta::DURATION, true ), '1', '' );

		printf(
			'<p><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label></p>',
			esc_attr( Meta::LINEAR_PROGRESSION ),
			checked( (bool) get_post_meta( $post->ID, Meta::LINEAR_PROGRESSION, true ), true, false ),
			esc_html__( 'Steps must be completed in order', 'odsi-lms' )
		);

		$certificates = new WP_Query(
			array(
				'post_type'              => PostTypes::CERTIFICATE,
				'post_status'            => array( 'publish', 'private' ),
				'posts_per_page'         => 100,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$current      = (int) get_post_meta( $post->ID, Meta::CERTIFICATE_ID, true );

		echo '<p><label for="odsi-certificate"><strong>' . esc_html__( 'Certificate', 'odsi-lms' ) . '</strong></label><br />';
		echo '<select id="odsi-certificate" name="' . esc_attr( Meta::CERTIFICATE_ID ) . '" style="width:100%">';
		echo '<option value="0">' . esc_html__( 'None', 'odsi-lms' ) . '</option>';
		foreach ( $certificates->posts as $certificate ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $certificate->ID, selected( $current, (int) $certificate->ID, false ), esc_html( get_the_title( $certificate ) ) );
		}
		echo '</select></p>';

		$courses  = new WP_Query(
			array(
				'post_type'              => PostTypes::COURSE,
				'post_status'            => 'publish',
				'post__not_in'           => array( $post->ID ),
				'posts_per_page'         => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded admin/outline read.
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$required = array_map( 'intval', (array) get_post_meta( $post->ID, Meta::PREREQUISITES, true ) );

		echo '<p><label for="odsi-prerequisites"><strong>' . esc_html__( 'Prerequisites', 'odsi-lms' ) . '</strong></label><br />';
		echo '<select id="odsi-prerequisites" name="' . esc_attr( Meta::PREREQUISITES ) . '[]" multiple size="4" style="width:100%">';
		foreach ( $courses->posts as $candidate ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $candidate->ID, selected( in_array( (int) $candidate->ID, $required, true ), true, false ), esc_html( get_the_title( $candidate ) ) );
		}
		echo '</select><br /><span class="description">' . esc_html__( 'Learners must complete these before they can open this course.', 'odsi-lms' ) . '</span></p>';

		/**
		 * Fires at the end of the course settings box, for integrations that
		 * add a field (the WooCommerce adapter adds its product id here).
		 *
		 * @param WP_Post $post Course.
		 */
		do_action( 'odsi_lms_course_settings_box', $post );
	}

	/**
	 * Lesson / topic box.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_step( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$type  = (string) get_post_meta( $post->ID, Meta::DRIP_TYPE, true );
		$value = (string) get_post_meta( $post->ID, Meta::DRIP_VALUE, true );
		$types = array(
			''     => __( 'Available immediately', 'odsi-lms' ),
			'days' => __( 'Days after enrollment', 'odsi-lms' ),
			'date' => __( 'On a fixed date', 'odsi-lms' ),
		);

		echo '<p><label for="odsi-drip-type"><strong>' . esc_html__( 'Release', 'odsi-lms' ) . '</strong></label><br />';
		echo '<select id="odsi-drip-type" name="' . esc_attr( Meta::DRIP_TYPE ) . '" style="width:100%">';
		foreach ( $types as $key => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $key ), selected( $type, $key, false ), esc_html( $label ) );
		}
		echo '</select></p>';
		printf(
			'<p><label for="odsi-drip-value">%1$s</label><br /><input type="text" id="odsi-drip-value" name="%2$s" value="%3$s" class="regular-text" style="width:100%%" /><br /><span class="description">%4$s</span></p>',
			esc_html__( 'Release value', 'odsi-lms' ),
			esc_attr( Meta::DRIP_VALUE ),
			esc_attr( $value ),
			esc_html__( 'A number of days, or a date such as 2026-09-30 (site timezone).', 'odsi-lms' )
		);
		$this->number( Meta::DURATION, __( 'Duration (minutes)', 'odsi-lms' ), (string) (int) get_post_meta( $post->ID, Meta::DURATION, true ), '1', '' );
	}

	/**
	 * Quiz box.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_quiz( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$pass = get_post_meta( $post->ID, Meta::PASS_MARK, true );
		$pass = '' === $pass ? (int) $this->settings->get( 'default_pass_mark' ) : (int) $pass;

		$this->number( Meta::PASS_MARK, __( 'Pass mark (%)', 'odsi-lms' ), (string) $pass, '1', '' );
		$this->number( Meta::MAX_ATTEMPTS, __( 'Maximum attempts', 'odsi-lms' ), (string) (int) get_post_meta( $post->ID, Meta::MAX_ATTEMPTS, true ), '1', __( 'Zero means unlimited.', 'odsi-lms' ) );
		$this->number( Meta::TIME_LIMIT, __( 'Time limit (minutes)', 'odsi-lms' ), (string) (int) get_post_meta( $post->ID, Meta::TIME_LIMIT, true ), '1', __( 'Zero means no limit.', 'odsi-lms' ) );
	}

	/**
	 * Persist.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$int = static fn ( string $key ): int => absint( wp_unslash( $_POST[ $key ] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

		switch ( $post->post_type ) {
			case PostTypes::COURSE:
				$mode = sanitize_key( wp_unslash( (string) ( $_POST[ Meta::ACCESS_MODE ] ?? 'free' ) ) );
				update_post_meta( $post_id, Meta::ACCESS_MODE, in_array( $mode, array( 'open', 'free', 'paid', 'closed' ), true ) ? $mode : 'free' );
				update_post_meta( $post_id, Meta::PRICE, (string) max( 0, (float) sanitize_text_field( wp_unslash( (string) ( $_POST[ Meta::PRICE ] ?? '0' ) ) ) ) );
				update_post_meta( $post_id, Meta::ACCESS_DAYS, $int( Meta::ACCESS_DAYS ) );
				update_post_meta( $post_id, Meta::DURATION, $int( Meta::DURATION ) );
				update_post_meta( $post_id, Meta::LINEAR_PROGRESSION, ! empty( $_POST[ Meta::LINEAR_PROGRESSION ] ) );

				$required = array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST[ Meta::PREREQUISITES ] ?? array() ) ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint sanitises each id.
				update_post_meta( $post_id, Meta::PREREQUISITES, array_values( array_filter( $required, static fn ( int $id ): bool => $id !== $post_id && PostTypes::COURSE === get_post_type( $id ) ) ) );

				$certificate = $int( Meta::CERTIFICATE_ID );
				update_post_meta( $post_id, Meta::CERTIFICATE_ID, PostTypes::CERTIFICATE === get_post_type( $certificate ) ? $certificate : 0 );

				/**
				 * Fires after the course settings box saved its own fields; the
				 * nonce and `edit_post` were already verified.
				 *
				 * @param int $post_id Course.
				 */
				do_action( 'odsi_lms_course_settings_saved', $post_id );
				break;

			case PostTypes::LESSON:
			case PostTypes::TOPIC:
				$type = sanitize_key( wp_unslash( (string) ( $_POST[ Meta::DRIP_TYPE ] ?? '' ) ) );
				update_post_meta( $post_id, Meta::DRIP_TYPE, in_array( $type, array( 'days', 'date' ), true ) ? $type : '' );
				update_post_meta( $post_id, Meta::DRIP_VALUE, sanitize_text_field( wp_unslash( (string) ( $_POST[ Meta::DRIP_VALUE ] ?? '' ) ) ) );
				update_post_meta( $post_id, Meta::DURATION, $int( Meta::DURATION ) );
				break;

			case PostTypes::QUIZ:
				update_post_meta( $post_id, Meta::PASS_MARK, min( 100, $int( Meta::PASS_MARK ) ) );
				update_post_meta( $post_id, Meta::MAX_ATTEMPTS, $int( Meta::MAX_ATTEMPTS ) );
				update_post_meta( $post_id, Meta::TIME_LIMIT, $int( Meta::TIME_LIMIT ) );
				break;
		}//end switch
	}

	/**
	 * A labelled number input.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Label.
	 * @param string $value       Current value.
	 * @param string $step        Step.
	 * @param string $description Help text.
	 */
	private function number( string $name, string $label, string $value, string $step, string $description ): void {
		printf(
			'<p><label for="%1$s">%2$s</label><br /><input type="number" id="%1$s" name="%1$s" value="%3$s" min="0" step="%4$s" class="small-text" />%5$s</p>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			esc_attr( $step ),
			'' !== $description ? '<br /><span class="description">' . esc_html( $description ) . '</span>' : ''
		);
	}
}
