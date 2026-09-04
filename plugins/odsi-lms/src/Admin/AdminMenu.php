<?php
/**
 * Admin menu.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Admin;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the top-level LMS menu that every LMS post type hangs off.
 */
final class AdminMenu implements Bootable {

	public const SLUG = 'odsi-lms';

	/**
	 * Constructor.
	 *
	 * @param ReportsScreen  $reports Reports screen.
	 * @param GradingScreen  $grading  Grading screen.
	 * @param SettingsScreen $settings Settings screen.
	 */
	public function __construct(
		private ReportsScreen $reports,
		private GradingScreen $grading,
		private SettingsScreen $settings
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register' ), 9 );
	}

	/**
	 * Register the parent menu.
	 *
	 * Registered at priority 9 so it exists before the post types, which declare
	 * `show_in_menu => 'odsi-lms'`, are added at the default priority.
	 */
	public function register(): void {
		add_menu_page(
			__( 'Learning', 'odsi-lms' ),
			__( 'Learning', 'odsi-lms' ),
			'edit_odsi_courses',
			self::SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-welcome-learn-more',
			30
		);

		add_submenu_page( self::SLUG, __( 'Reports', 'odsi-lms' ), __( 'Reports', 'odsi-lms' ), Capabilities::REPORT, ReportsScreen::SLUG, array( $this->reports, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Grading', 'odsi-lms' ), __( 'Grading', 'odsi-lms' ), Capabilities::REPORT, GradingScreen::SLUG, array( $this->grading, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'odsi-lms' ), __( 'Settings', 'odsi-lms' ), Capabilities::MANAGE, SettingsScreen::SLUG, array( $this->settings, 'render' ) );
	}

	/**
	 * The dashboard is the reports screen.
	 */
	public function render_dashboard(): void {
		$this->reports->render();
	}
}
