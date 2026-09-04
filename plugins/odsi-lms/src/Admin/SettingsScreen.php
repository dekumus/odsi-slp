<?php
/**
 * Settings screen.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Admin;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Support\Capabilities;
use ODSI\LMS\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The handful of site-wide LMS switches (LMS-ADM-001).
 */
final class SettingsScreen implements Bootable {

	public const SLUG   = 'odsi-lms-settings';
	private const NONCE = 'odsi_lms_settings';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_post_odsi_lms_save_settings', array( $this, 'save' ) );
	}

	/**
	 * Field definitions.
	 *
	 * @return array<string, array{0: string, 1: string, 2?: array<string, string>}>
	 */
	private function fields(): array {
		return array(
			'course_archive_slug'     => array( __( 'Course archive slug', 'odsi-lms' ), 'text' ),
			'default_access_mode'     => array(
				__( 'Default access mode for new courses', 'odsi-lms' ),
				'select',
				array(
					'open'   => __( 'Open', 'odsi-lms' ),
					'free'   => __( 'Free', 'odsi-lms' ),
					'paid'   => __( 'Paid', 'odsi-lms' ),
					'closed' => __( 'Closed', 'odsi-lms' ),
				),
			),
			'default_pass_mark'       => array( __( 'Default quiz pass mark (%)', 'odsi-lms' ), 'number' ),
			'enable_certificates'     => array( __( 'Issue certificates on completion', 'odsi-lms' ), 'checkbox' ),
			'email_notifications'     => array( __( 'Email learners on enrollment, completion and assignment results', 'odsi-lms' ), 'checkbox' ),
			'reset_data_on_uninstall' => array( __( 'Delete all learning data when the plugin is uninstalled', 'odsi-lms' ), 'checkbox' ),
		);
	}

	/**
	 * Render. Called by AdminMenu.
	 */
	public function render(): void {
		$values = $this->settings->all();

		echo '<div class="wrap"><h1>' . esc_html__( 'Learning settings', 'odsi-lms' ) . '</h1>';

		if ( ! empty( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'odsi-lms' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="odsi_lms_save_settings" /><table class="form-table">';

		foreach ( $this->fields() as $key => $field ) {
			$value = $values[ $key ] ?? '';

			echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label></th><td>';

			if ( 'checkbox' === $field[1] ) {
				echo '<input type="checkbox" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="1" ' . checked( (bool) $value, true, false ) . ' />';
			} elseif ( 'select' === $field[1] ) {
				echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';

				foreach ( $field[2] ?? array() as $option => $label ) {
					echo '<option value="' . esc_attr( $option ) . '" ' . selected( (string) $value, $option, false ) . '>' . esc_html( $label ) . '</option>';
				}

				echo '</select>';
			} else {
				echo '<input type="' . esc_attr( $field[1] ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" class="regular-text" />';
			}

			echo '</td></tr>';
		}//end foreach

		echo '</table>';
		submit_button( __( 'Save settings', 'odsi-lms' ) );
		echo '</form></div>';
	}

	/**
	 * Persist.
	 */
	public function save(): void {
		check_admin_referer( self::NONCE );

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You cannot change these settings.', 'odsi-lms' ) );
		}

		$values = array();

		foreach ( $this->fields() as $key => $field ) {
			$raw = wp_unslash( $_POST[ $key ] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per type below.

			$values[ $key ] = match ( $field[1] ) {
				'checkbox' => ! empty( $raw ),
				'number'   => max( 0, min( 100, (int) $raw ) ),
				'select'   => isset( ( $field[2] ?? array() )[ (string) $raw ] ) ? (string) $raw : (string) ( Settings::defaults()[ $key ] ?? '' ),
				default    => sanitize_title( (string) $raw ) ?: (string) ( Settings::defaults()[ $key ] ?? '' ),
			};
		}

		$this->settings->update( $values );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SLUG,
					'updated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
