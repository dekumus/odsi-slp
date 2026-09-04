<?php
/**
 * Reactions.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Activity;

use ODSI\Social\Repositories\ActivityRepository;
use ODSI\Social\Repositories\ReactionRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Set and remove reactions, keeping the item's count exact (SOC-ACT-005/006).
 */
final class Reactions {

	public const TYPES = array( 'like' );

	/**
	 * Constructor.
	 *
	 * @param ReactionRepository $reactions Reactions.
	 * @param ActivityRepository $activity  Activity.
	 * @param Privacy            $privacy   Privacy rule.
	 */
	public function __construct(
		private ReactionRepository $reactions,
		private ActivityRepository $activity,
		private Privacy $privacy
	) {
	}

	/**
	 * Set the member's reaction.
	 *
	 * @param int    $user_id     Member.
	 * @param int    $activity_id Item or comment.
	 * @param string $type        Reaction type.
	 *
	 * @return true|WP_Error
	 */
	public function set( int $user_id, int $activity_id, string $type = 'like' ): bool|WP_Error {
		$item = $this->activity->find( $activity_id );

		if ( ! $item || ! $this->privacy->can_view( $user_id, $item ) ) {
			return new WP_Error( 'odsi_social_not_found', __( 'That activity does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
		}

		/**
		 * Filters the reaction types available.
		 *
		 * @param string[] $types Types.
		 */
		$types = (array) apply_filters( 'odsi_social_reaction_types', self::TYPES );

		if ( ! in_array( $type, $types, true ) ) {
			return new WP_Error( 'odsi_social_invalid_reaction', __( 'That reaction is not available.', 'odsi-social' ) );
		}

		$outcome = $this->reactions->put( $activity_id, $user_id, $type );

		if ( 'created' === $outcome ) {
			$this->activity->adjust( $activity_id, 'reaction_count', 1 );
		}

		if ( 'unchanged' !== $outcome ) {
			/**
			 * Fires after a reaction is added or replaced.
			 *
			 * @param int    $activity_id Item.
			 * @param int    $user_id     Member.
			 * @param string $type        Reaction.
			 * @param object $item        Activity row.
			 */
			do_action( 'odsi_social_reaction_added', $activity_id, $user_id, $type, $item );
		}

		return true;
	}

	/**
	 * Remove the member's reaction.
	 *
	 * @param int $user_id     Member.
	 * @param int $activity_id Item.
	 *
	 * @return true|WP_Error
	 */
	public function remove( int $user_id, int $activity_id ): bool|WP_Error {
		$item = $this->activity->find( $activity_id );

		if ( ! $item || ! $this->privacy->can_view( $user_id, $item ) ) {
			return new WP_Error( 'odsi_social_not_found', __( 'That activity does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
		}

		if ( $this->reactions->remove( $activity_id, $user_id ) ) {
			$this->activity->adjust( $activity_id, 'reaction_count', -1 );

			/**
			 * Fires after a reaction is removed.
			 *
			 * @param int $activity_id Item.
			 * @param int $user_id     Member.
			 */
			do_action( 'odsi_social_reaction_removed', $activity_id, $user_id );
		}

		return true;
	}
}
