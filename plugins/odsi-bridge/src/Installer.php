<?php
/**
 * Activation and deactivation.
 *
 * @package ODSI\Bridge
 */

declare( strict_types = 1 );

namespace ODSI\Bridge;

use ODSI\Bridge\Database\Migrator;
use ODSI\Bridge\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One-off work.
 */
final class Installer {

	/**
	 * Create the link table and seed settings.
	 */
	public static function activate(): void {
		Migrator::migrate();
		Settings::seed();
	}

	/**
	 * Nothing to clean up: the link table is kept so reactivation resumes.
	 */
	public static function deactivate(): void {
	}
}
