<?php
/**
 * Mentions.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Activity;

use ODSI\Social\Contracts\Bootable;

defined( 'ABSPATH' ) || exit;

/**
 * Finds `@nicename` mentions in new activity and announces each visible one (SOC-ACT-007).
 */
final class Mentions implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param Privacy $privacy Privacy rule.
	 */
	public function __construct( private Privacy $privacy ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'odsi_social_activity_posted', array( $this, 'on_posted' ) );
	}

	/**
	 * Mentioned user ids in a piece of content, excluding the author.
	 *
	 * @param string $content   Content.
	 * @param int    $author_id Author, excluded.
	 *
	 * @return int[]
	 */
	public function parse( string $content, int $author_id = 0 ): array {
		/**
		 * Filters the mention pattern. Group 1 must capture the nicename.
		 *
		 * @param string $pattern Regex.
		 */
		$pattern = (string) apply_filters( 'odsi_social_mention_pattern', '/(?<![\w\/])@([A-Za-z0-9_\-\.]+)/u' );

		if ( ! preg_match_all( $pattern, wp_strip_all_tags( $content ), $matches ) ) {
			return array();
		}

		$ids = array();

		foreach ( array_unique( $matches[1] ) as $nicename ) {
			$user = get_user_by( 'slug', rtrim( $nicename, '.' ) );

			if ( $user && (int) $user->ID !== $author_id ) {
				$ids[] = (int) $user->ID;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Announce mentions in a newly posted item.
	 *
	 * @param object $item Activity row.
	 */
	public function on_posted( object $item ): void {
		foreach ( $this->parse( (string) $item->content, (int) $item->user_id ) as $mentioned_id ) {
			if ( ! $this->privacy->can_view( $mentioned_id, $item ) ) {
				continue;
			}

			/**
			 * Fires once per member mentioned in an item they can see.
			 *
			 * @param int    $mentioned_id Mentioned member.
			 * @param object $item         Activity row.
			 * @param int    $author_id    Author.
			 */
			do_action( 'odsi_social_mentioned', $mentioned_id, $item, (int) $item->user_id );
		}
	}
}
