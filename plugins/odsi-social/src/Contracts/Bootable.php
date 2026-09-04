<?php
/**
 * Bootable contract.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * A service that attaches its own WordPress hooks when the plugin boots.
 */
interface Bootable {

	/**
	 * Register hooks. Called once, on `plugins_loaded`.
	 */
	public function boot(): void;
}
