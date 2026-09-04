<?php
/**
 * Bridge settings.
 *
 * @package ODSI\Bridge
 */

declare( strict_types = 1 );

namespace ODSI\Bridge\Admin;

use ODSI\Bridge\Contracts\Bootable;
use ODSI\Bridge\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One screen, three switches.
 */
final class SettingsScreen implements Bootable {

	private const NONCE = 'odsi_bridge_settings';

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
		add_action( 'admin_menu', array( $this, 'register' ), 20 );
		add_action( 'admin_post_odsi_bridge_save', array( $this, 'save' ) );
	}

	/**
	 * Add under the Learning menu.
	 */
	public function register(): void {
		add_submenu_page( 'odsi-lms', __( 'Community bridge', 'odsi-bridge' ), __( 'Community bridge', 'odsi-bridge' ), 'manage_odsi_lms', 'odsi-bridge', array( $this, 'render' ) );
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$modules = array(
			'course_activity'         => __( 'Post course events (enrolled, completed, passed a quiz) to the activity feed', 'odsi-bridge' ),
			'group_linkage'           => __( 'Link courses to groups and keep membership in step with enrollment', 'odsi-bridge' ),
			'progress_visibility'     => __( 'Let members of a linked group see each other\'s progress', 'odsi-bridge' ),
			'reset_data_on_uninstall' => __( 'Delete course-to-group links when the plugin is uninstalled', 'odsi-bridge' ),
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Community bridge', 'odsi-bridge' ) . '</h1><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="odsi_bridge_save" /><table class="form-table">';

		foreach ( $modules as $key => $label ) {
			printf(
				'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s" value="1" %3$s /> %4$s</label></td></tr>',
				esc_html( ucwords( str_replace( '_', ' ', $key ) ) ),
				esc_attr( $key ),
				checked( $this->settings->enabled( $key ), true, false ),
				esc_html( $label )
			);
		}

		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Save.
	 */
	public function save(): void {
		check_admin_referer( self::NONCE );

		if ( ! current_user_can( 'manage_odsi_lms' ) ) {
			wp_die( esc_html__( 'You cannot change these settings.', 'odsi-bridge' ) );
		}

		$this->settings->update(
			array(
				'course_activity'         => ! empty( $_POST['course_activity'] ),
				'group_linkage'           => ! empty( $_POST['group_linkage'] ),
				'progress_visibility'     => ! empty( $_POST['progress_visibility'] ),
				'reset_data_on_uninstall' => ! empty( $_POST['reset_data_on_uninstall'] ),
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'odsi-bridge',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
