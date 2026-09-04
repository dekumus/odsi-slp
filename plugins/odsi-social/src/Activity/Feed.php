<?php
/**
 * Feed reads.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Activity;

use ODSI\Social\Database\Schema;
use ODSI\Social\Repositories\ActivityRepository;
use ODSI\Social\Repositories\ConnectionRepository;
use ODSI\Social\Repositories\GroupMemberRepository;
use ODSI\Social\Repositories\MemberRepository;
use ODSI\Social\Repositories\ReactionRepository;
use ODSI\Social\Support\Cursor;
use ODSI\Social\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The four feed scopes, cursor-paginated, hydrated in a fixed number of queries.
 */
final class Feed {

	public const SCOPE_SITE     = 'site';
	public const SCOPE_PERSONAL = 'personal';
	public const SCOPE_GROUP    = 'group';
	public const SCOPE_PROFILE  = 'profile';

	/**
	 * Constructor.
	 *
	 * @param ActivityRepository $activity  Activity storage.
	 * @param ReactionRepository $reactions Reactions.
	 * @param MemberRepository   $members   Member index, primed per page.
	 * @param Privacy            $privacy   Privacy rule.
	 * @param Renderers          $renderers Type renderers.
	 * @param Settings           $settings  Settings.
	 */
	public function __construct(
		private ActivityRepository $activity,
		private ReactionRepository $reactions,
		private MemberRepository $members,
		private Privacy $privacy,
		private Renderers $renderers,
		private Settings $settings
	) {
	}

	/**
	 * Fetch a page.
	 *
	 * @param int                  $viewer_id Viewer, 0 for a visitor.
	 * @param string               $scope     One of the SCOPE_* constants.
	 * @param array<string, mixed> $args      `group_id`, `user_id`, `type`, `component`, `cursor`, `per_page`.
	 *
	 * @return array{items: array<int, array<string, mixed>>, next_cursor: string}
	 */
	public function page( int $viewer_id, string $scope, array $args = array() ): array {
		$per_page = (int) apply_filters( 'odsi_social_feed_per_page', $this->settings->int( 'feed_per_page' ) );
		$per_page = max( 1, min( 50, (int) ( $args['per_page'] ?? $per_page ) ) );

		[ $where, $params ] = $this->scope_predicate( $viewer_id, $scope, $args );

		if ( null === $where ) {
			return array(
				'items'       => array(),
				'next_cursor' => '',
			);
		}

		$privacy = $this->privacy->where_clause( $viewer_id );
		$where  .= ' AND ' . $privacy['sql'];
		$params  = array_merge( $params, $privacy['params'] );

		if ( ! empty( $args['type'] ) ) {
			$where   .= ' AND a.type = %s';
			$params[] = sanitize_key( (string) $args['type'] );
		}

		if ( ! empty( $args['component'] ) ) {
			$where   .= ' AND a.component = %s';
			$params[] = sanitize_key( (string) $args['component'] );
		}

		/**
		 * Filters the assembled feed predicate before it runs.
		 *
		 * @param array{where: string, params: array<mixed>} $query     Predicate and params.
		 * @param string                                       $scope     Scope.
		 * @param int                                          $viewer_id Viewer.
		 */
		$query = (array) apply_filters(
			'odsi_social_feed_query_args',
			array(
				'where'  => $where,
				'params' => $params,
			),
			$scope,
			$viewer_id
		);

		$rows = $this->activity->page( (string) $query['where'], (array) $query['params'], $per_page, (string) ( $args['cursor'] ?? '' ) );

		$has_more = count( $rows ) > $per_page;
		$rows     = array_slice( $rows, 0, $per_page );

		// Grant-style overrides from the single-item filter cannot be expressed
		// in SQL; deny-style ones are honoured here by post-filtering the page.
		$rows = array_values( array_filter( $rows, fn ( object $row ): bool => $this->privacy->can_view( $viewer_id, $row ) ) );

		$last = end( $rows );

		return array(
			'items'       => $this->hydrate( $rows, $viewer_id ),
			'next_cursor' => $has_more && $last ? Cursor::encode( (string) $last->date_recorded, (int) $last->id ) : '',
		);
	}

	/**
	 * A single item with all its comments, or null when not visible.
	 *
	 * @param int $viewer_id Viewer.
	 * @param int $id        Activity id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function item( int $viewer_id, int $id ): ?array {
		$row = $this->activity->find( $id );

		if ( ! $row || ! $this->privacy->can_view( $viewer_id, $row ) ) {
			return null;
		}

		if ( (int) $row->parent_id > 0 ) {
			$row = $this->activity->find( (int) $row->parent_id );

			if ( ! $row ) {
				return null;
			}
		}

		$hydrated = $this->hydrate( array( $row ), $viewer_id, false );
		$item     = $hydrated[0];

		$comments         = $this->activity->comments( (int) $row->id );
		$item['comments'] = array_map( fn ( object $c ): array => $this->present( $c, $viewer_id, array() ), $comments );

		return $item;
	}

	/**
	 * Attach authors, reactions and latest comments to a page of rows.
	 *
	 * @param object[] $rows          Top-level rows.
	 * @param int      $viewer_id     Viewer.
	 * @param bool     $with_comments Whether to fetch the latest three comments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function hydrate( array $rows, int $viewer_id, bool $with_comments = true ): array {
		if ( array() === $rows ) {
			return array();
		}

		$ids      = array_map( static fn ( object $r ): int => (int) $r->id, $rows );
		$comments = $with_comments ? $this->activity->latest_comments( $ids, 3 ) : array();

		$all_rows = $rows;

		foreach ( $comments as $list ) {
			$all_rows = array_merge( $all_rows, $list );
		}

		$reacted = $this->reactions->for_viewer( array_map( static fn ( object $r ): int => (int) $r->id, $all_rows ), $viewer_id );

		// Prime the user cache for every author in one query.
		$author_ids = array_values( array_unique( array_map( static fn ( object $r ): int => (int) $r->user_id, $all_rows ) ) );
		cache_users( $author_ids );
		$this->members->prime( $author_ids );

		$out = array();

		foreach ( $rows as $row ) {
			$item             = $this->present( $row, $viewer_id, $reacted );
			$item['comments'] = array_map( fn ( object $c ): array => $this->present( $c, $viewer_id, $reacted ), array_reverse( $comments[ (int) $row->id ] ?? array() ) );
			$out[]            = $item;
		}

		return $out;
	}

	/**
	 * The presentation shape of one row.
	 *
	 * @param object             $row       Activity row.
	 * @param int                $viewer_id Viewer.
	 * @param array<int, string> $reacted   Viewer's reactions by activity id.
	 *
	 * @return array<string, mixed>
	 */
	public function present( object $row, int $viewer_id, array $reacted ): array {
		$author = get_userdata( (int) $row->user_id );

		return array(
			'id'              => (int) $row->id,
			'component'       => (string) $row->component,
			'type'            => (string) $row->type,
			'parent_id'       => (int) $row->parent_id,
			'group_id'        => (int) $row->group_id,
			'primary_item_id' => (int) $row->primary_item_id,
			'privacy'         => (string) $row->privacy,
			'author'          => array(
				'id'       => (int) $row->user_id,
				'name'     => $author ? $author->display_name : __( 'A former member', 'odsi-social' ),
				'nicename' => $author ? $author->user_nicename : '',
				'avatar'   => $author ? get_avatar_url( (int) $row->user_id, array( 'size' => 96 ) ) : '',
				'url'      => $author ? (string) apply_filters( 'odsi_social_member_url', '', (int) $row->user_id ) : '',
			),
			'action'          => $this->renderers->action( $row ),
			'content'         => $this->renderers->body( $row ),
			'raw_content'     => (string) $row->content,
			'comment_count'   => (int) $row->comment_count,
			'reaction_count'  => (int) $row->reaction_count,
			'viewer_reaction' => $reacted[ (int) $row->id ] ?? '',
			'is_edited'       => (bool) $row->is_edited,
			'can_delete'      => $viewer_id > 0 && ( (int) $row->user_id === $viewer_id || user_can( $viewer_id, \ODSI\Social\Support\Capabilities::MANAGE ) ),
			'date'            => (string) $row->date_recorded,
			'date_relative'   => sprintf(
				/* translators: %s: human time difference. */
				__( '%s ago', 'odsi-social' ),
				human_time_diff( (int) strtotime( (string) $row->date_recorded ) )
			),
		);
	}

	/**
	 * The scope predicate over alias `a`.
	 *
	 * @param int                  $viewer_id Viewer.
	 * @param string               $scope     Scope.
	 * @param array<string, mixed> $args      Args.
	 *
	 * @return array{0: string|null, 1: array<mixed>} Null SQL means "nothing".
	 */
	private function scope_predicate( int $viewer_id, string $scope, array $args ): array {
		switch ( $scope ) {
			case self::SCOPE_GROUP:
				$group_id = (int) ( $args['group_id'] ?? 0 );

				return $group_id > 0 ? array( 'a.group_id = %d', array( $group_id ) ) : array( null, array() );

			case self::SCOPE_PROFILE:
				$user_id = (int) ( $args['user_id'] ?? 0 );

				return $user_id > 0 ? array( 'a.user_id = %d', array( $user_id ) ) : array( null, array() );

			case self::SCOPE_PERSONAL:
				if ( $viewer_id <= 0 ) {
					return array( null, array() );
				}

				$follows     = Schema::table( 'follows' );
				$connections = Schema::table( 'connections' );
				$memberships = Schema::table( 'group_members' );

				$sql = "(
					a.user_id = %d
					OR a.user_id IN (SELECT f.following_id FROM {$follows} f WHERE f.follower_id = %d)
					OR a.user_id IN (SELECT IF(c.user_low = %d, c.user_high, c.user_low) FROM {$connections} c WHERE (c.user_low = %d OR c.user_high = %d) AND c.status = %s)
					OR (a.group_id > 0 AND a.group_id IN (SELECT gm.group_id FROM {$memberships} gm WHERE gm.user_id = %d AND gm.status = %s))
				)";

				return array(
					$sql,
					array( $viewer_id, $viewer_id, $viewer_id, $viewer_id, $viewer_id, ConnectionRepository::STATUS_ACCEPTED, $viewer_id, GroupMemberRepository::STATUS_ACTIVE ),
				);

			default:
				return array( '1=1', array() );
		}//end switch
	}
}
