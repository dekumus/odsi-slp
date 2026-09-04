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

		add_submenu_page(
			self::SLUG,
			__( 'Reports', 'odsi-lms' ),
			__( 'Reports', 'odsi-lms' ),
			Capabilities::REPORT,
			'odsi-lms-reports',
			array( $this, 'render_reports' )
		);
	}

	/**
	 * Placeholder dashboard screen.
	 */
	public function render_dashboard(): void {
		printf(
			'<div class="wrap"><h1>%s</h1><p>%s</p></div>',
			esc_html__( 'Learning', 'odsi-lms' ),
			esc_html__( 'Course, enrollment and completion summaries will appear here.', 'odsi-lms' )
		);
	}

	/**
	 * Placeholder reports screen.
	 */
	public function render_reports(): void {
		printf(
			'<div class="wrap"><h1>%s</h1><p>%s</p></div>',
			esc_html__( 'Reports', 'odsi-lms' ),
			esc_html__( 'Enrollment, progress and quiz reporting will appear here.', 'odsi-lms' )
		);
	}
}
