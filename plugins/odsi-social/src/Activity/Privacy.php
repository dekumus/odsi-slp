<?php
/**
 * The activity privacy rule.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Activity;

use ODSI\Social\Database\Schema;
use ODSI\Social\Repositories\ActivityRepository;
use ODSI\Social\Repositories\ConnectionRepository;
use ODSI\Social\Repositories\GroupMemberRepository;
use ODSI\Social\Repositories\GroupRepository;
use ODSI\Social\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Who may see an activity item. One rule, two representations (ADR-016).
 *
 * `can_view()` answers for a single item; `where_clause()` produces the same
 * rule as a SQL predicate for feed queries. A single test table asserts both.
 * No other class reads an item's `privacy` or a group's visibility.
 */
final class Privacy {

	public const PUBLIC      = 'public';
	public const MEMBERS     = 'members';
	public const CONNECTIONS = 'connections';
	public const ONLY_ME     = 'only_me';
	public const GROUP       = 'group';

	/**
	 * Constructor.
	 *
	 * @param ConnectionRepository  $connections Connections.
	 * @param GroupMemberRepository $members     Group memberships.
	 * @param GroupRepository       $groups      Group index.
	 * @param ActivityRepository    $activity    Activity, for resolving a comment's parent.
	 */
	public function __construct(
		private ConnectionRepository $connections,
		private GroupMemberRepository $members,
		private GroupRepository $groups,
		private ActivityRepository $activity
	) {
	}

	/**
	 * Privacy levels a member may choose for a non-group update.
	 *
	 * @return string[]
	 */
	public static function choices(): array {
		return array( self::PUBLIC, self::MEMBERS, self::CONNECTIONS, self::ONLY_ME );
	}

	/**
	 * Whether the viewer may see the item.
	 *
	 * @param int    $viewer_id Viewer, 0 for a visitor.
	 * @param object $item      Activity row.
	 */
	public function can_view( int $viewer_id, object $item ): bool {
		// Comments inherit their parent's visibility.
		if ( (int) $item->parent_id > 0 ) {
			$parent = $this->activity->find( (int) $item->parent_id );

			return $parent ? $this->can_view( $viewer_id, $parent ) : false;
		}

		$allowed = $this->decide( $viewer_id, $item );

		/**
		 * Filters the final visibility decision for a single item.
		 *
		 * Applies to single-item reads only; feed queries evaluate the rule in
		 * SQL and cannot honour a grant made here.
		 *
		 * @param bool   $allowed   Decision.
		 * @param int    $viewer_id Viewer.
		 * @param object $item      Activity row.
		 */
		return (bool) apply_filters( 'odsi_social_can_view_activity', $allowed, $viewer_id, $item );
	}

	/**
	 * The rule, as the spec's decision table.
	 *
	 * @param int    $viewer_id Viewer.
	 * @param object $item      Top-level activity row.
	 */
	private function decide( int $viewer_id, object $item ): bool {
		$author   = (int) $item->user_id;
		$privacy  = (string) $item->privacy;
		$group_id = (int) $item->group_id;

		if ( $viewer_id > 0 && ( $viewer_id === $author || Capabilities::is_admin( $viewer_id ) ) ) {
			return true;
		}

		if ( ActivityRepository::STATUS_PUBLISHED !== (string) $item->status ) {
			return false;
		}

		// An item in a group is always governed by the group, whatever its
		// privacy column says; an item claiming `group` with no group is private.
		if ( $group_id > 0 ) {
			$privacy = self::GROUP;
		} elseif ( self::GROUP === $privacy ) {
			$privacy = self::ONLY_ME;
		}

		switch ( $privacy ) {
			case self::PUBLIC:
				return true;

			case self::MEMBERS:
				return $viewer_id > 0;

			case self::CONNECTIONS:
				return $viewer_id > 0 && $this->connections->are_connected( $viewer_id, $author );

			case self::GROUP:
				$group = $this->groups->find( $group_id );

				if ( ! $group ) {
					return false;
				}

				if ( 'public' === (string) $group->visibility ) {
					return true;
				}

				return $viewer_id > 0 && $this->members->is_active( $group_id, $viewer_id );

			default:
				return false;
		}//end switch
	}

	/**
	 * The same rule as a SQL predicate over aliases `a` (activity) and `g` (groups index).
	 *
	 * @param int $viewer_id Viewer, 0 for a visitor.
	 *
	 * @return array{sql: string, params: array<int|string>}
	 */
	public function where_clause( int $viewer_id ): array {
		if ( $viewer_id > 0 && Capabilities::is_admin( $viewer_id ) ) {
			return array(
				'sql'    => '1=1',
				'params' => array(),
			);
		}

		$group_public = "(a.group_id > 0 AND g.visibility = 'public')";

		if ( $viewer_id <= 0 ) {
			return array(
				'sql'    => "((a.group_id = 0 AND a.privacy = %s) OR {$group_public})",
				'params' => array( self::PUBLIC ),
			);
		}

		$connections = Schema::table( 'connections' );
		$memberships = Schema::table( 'group_members' );

		$sql = "(
			a.user_id = %d
			OR (a.group_id = 0 AND a.privacy IN (%s, %s))
			OR (a.group_id = 0 AND a.privacy = %s AND a.user_id IN (
				SELECT IF(c.user_low = %d, c.user_high, c.user_low) FROM {$connections} c
				WHERE (c.user_low = %d OR c.user_high = %d) AND c.status = %s
			))
			OR {$group_public}
			OR (a.group_id > 0 AND a.group_id IN (
				SELECT gm.group_id FROM {$memberships} gm WHERE gm.user_id = %d AND gm.status = %s
			))
		)";

		return array(
			'sql'    => $sql,
			'params' => array(
				$viewer_id,
				self::PUBLIC,
				self::MEMBERS,
				self::CONNECTIONS,
				$viewer_id,
				$viewer_id,
				$viewer_id,
				ConnectionRepository::STATUS_ACCEPTED,
				$viewer_id,
				GroupMemberRepository::STATUS_ACTIVE,
			),
		);
	}
}
