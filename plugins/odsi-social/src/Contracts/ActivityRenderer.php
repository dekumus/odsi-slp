<?php
/**
 * Activity renderer contract.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an activity row of a given type into the sentence and body shown in a feed.
 */
interface ActivityRenderer {

	/**
	 * The action sentence, e.g. "Ana joined the group Designers". HTML allowed, escaped by the implementer.
	 *
	 * @param object $item Activity row.
	 */
	public function action( object $item ): string;

	/**
	 * The body markup. Empty string for items with no body.
	 *
	 * @param object $item Activity row.
	 */
	public function body( object $item ): string;
}
