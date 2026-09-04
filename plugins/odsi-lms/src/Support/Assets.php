<?php
/**
 * Asset registration.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Support;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Plugin;
use ODSI\LMS\PostTypes\PostTypes;
use const ODSI\LMS\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and conditionally enqueues the plugin's scripts and styles.
 */
final class Assets implements Bootable {

	public const FRONTEND_STYLE  = 'odsi-lms';
	public const FRONTEND_SCRIPT = 'odsi-lms';
	public const QUIZ_SCRIPT     = 'odsi-lms-quiz-player';
	public const BUILDER_SCRIPT  = 'odsi-lms-course-builder';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_admin' ) );
	}

	/**
	 * Register front-end assets and enqueue them on LMS screens only.
	 */
	public function register_frontend(): void {
		wp_register_style(
			self::FRONTEND_STYLE,
			Plugin::url() . 'assets/css/frontend.css',
			array(),
			VERSION
		);

		wp_register_script(
			self::FRONTEND_SCRIPT,
			Plugin::url() . 'assets/js/frontend.js',
			array( 'wp-api-fetch' ),
			VERSION,
			true
		);

		if ( ! $this->is_lms_screen() ) {
			return;
		}

		wp_enqueue_style( self::FRONTEND_STYLE );
		wp_enqueue_script( self::FRONTEND_SCRIPT );

		wp_localize_script(
			self::FRONTEND_SCRIPT,
			'odsiLms',
			array(
				'restUrl' => esc_url_raw( rest_url( 'odsi-lms/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'userId'  => get_current_user_id(),
				'i18n'    => array(
					'markingComplete'   => __( 'Saving…', 'odsi-lms' ),
					'completed'         => __( 'Completed', 'odsi-lms' ),
					'error'             => __( 'Something went wrong. Please try again.', 'odsi-lms' ),
					'start'             => __( 'Start quiz', 'odsi-lms' ),
					'submit'            => __( 'Submit answers', 'odsi-lms' ),
					'tryAgain'          => __( 'Back to the quiz', 'odsi-lms' ),
					'passed'            => __( 'You passed!', 'odsi-lms' ),
					'failed'            => __( 'Not this time.', 'odsi-lms' ),
					'needsGrading'      => __( 'Awaiting grading', 'odsi-lms' ),
					'correct'           => __( 'Correct', 'odsi-lms' ),
					'incorrect'         => __( 'Incorrect', 'odsi-lms' ),
					'minutes'           => __( 'minutes', 'odsi-lms' ),
					'attemptsRemaining' => __( 'Attempts remaining:', 'odsi-lms' ),
					'passMark'          => __( 'Pass mark:', 'odsi-lms' ),
					'timeLeft'          => __( 'Time left:', 'odsi-lms' ),
				),
			)
		);

		if ( is_singular( PostTypes::QUIZ ) ) {
			wp_enqueue_script( self::QUIZ_SCRIPT, Plugin::url() . 'assets/js/quiz-player.js', array( self::FRONTEND_SCRIPT ), VERSION, true );
		}
	}

	/**
	 * Enqueue the React course builder on the relevant admin screens.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function register_admin( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->post_type, array( PostTypes::COURSE, PostTypes::QUIZ ), true ) ) {
			return;
		}

		$asset_file = Plugin::path() . 'assets/build/course-builder.asset.php';

		// The builder is compiled with @wordpress/scripts. Until it is built, the
		// classic meta boxes still work, so a missing bundle is not fatal.
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::BUILDER_SCRIPT,
			Plugin::url() . 'assets/build/course-builder.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? VERSION,
			true
		);

		wp_set_script_translations( self::BUILDER_SCRIPT, 'odsi-lms', Plugin::path() . 'languages' );

		$style = is_rtl() ? 'style-course-builder-rtl.css' : 'style-course-builder.css';

		if ( is_readable( Plugin::path() . 'assets/build/' . $style ) ) {
			wp_enqueue_style( self::BUILDER_SCRIPT, Plugin::url() . 'assets/build/' . $style, array( 'wp-components' ), $asset['version'] ?? VERSION );
		}
	}

	/**
	 * Whether the current request renders LMS content.
	 */
	private function is_lms_screen(): bool {
		$types = array_merge( array( PostTypes::COURSE ), PostTypes::trackable() );

		$is_lms = is_singular( $types ) || is_post_type_archive( $types );

		/**
		 * Filters whether LMS front-end assets should load on this request.
		 *
		 * Themes that render course content through a shortcode on a normal page
		 * need to return true here.
		 *
		 * @param bool $is_lms Whether to enqueue assets.
		 */
		return (bool) apply_filters( 'odsi_lms_enqueue_frontend_assets', $is_lms );
	}
}
