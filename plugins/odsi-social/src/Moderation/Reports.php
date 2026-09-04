<?php
/**
 * Content reports and the moderation queue.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Moderation;

use ODSI\Social\Activity\Activity;
use ODSI\Social\Activity\Privacy;
use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Groups\Groups;
use ODSI\Social\Groups\Membership;
use ODSI\Social\Members\Profiles;
use ODSI\Social\Messages\Messages;
use ODSI\Social\Notifications\Notifications;
use ODSI\Social\Notifications\Renderers as NotificationRenderers;
use ODSI\Social\Repositories\ActivityRepository;
use ODSI\Social\Repositories\MemberRepository;
use ODSI\Social\Repositories\MessageRepository;
use ODSI\Social\Repositories\ReportRepository;
use ODSI\Social\Support\Capabilities;
use ODSI\Social\Support\RateLimiter;
use stdClass;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Members report what they can see; admins dismiss or act on the report
 * (SOC-MOD-010..016). Every action here re-uses an existing service, so a
 * report never grants a power the admin did not already have.
 */
final class Reports implements Bootable {

	public const COMPONENT = 'moderation';

	public const TYPE_ACTIVITY = 'activity';
	public const TYPE_COMMENT  = 'comment';
	public const TYPE_MEMBER   = 'member';
	public const TYPE_GROUP    = 'group';
	public const TYPE_MESSAGE  = 'message';

	public const TYPES   = array( self::TYPE_ACTIVITY, self::TYPE_COMMENT, self::TYPE_MEMBER, self::TYPE_GROUP, self::TYPE_MESSAGE );
	public const REASONS = array( 'spam', 'harassment', 'inappropriate', 'other' );

	public const ACTION_DELETE_CONTENT = 'delete_content';
	public const ACTION_BAN_FROM_GROUP = 'ban_from_group';
	public const ACTIONS               = array( self::ACTION_DELETE_CONTENT, self::ACTION_BAN_FROM_GROUP );

	/**
	 * Longest `details` text kept.
	 */
	public const DETAILS_MAX = 2000;

	/**
	 * Report ids an admin is acting on right now, so the deletion the action
	 * causes does not close the report a second time with a different resolution.
	 *
	 * @var array<int, true>
	 */
	private array $in_progress = array();

	/**
	 * Constructor.
	 *
	 * @param ReportRepository      $reports       Storage.
	 * @param ActivityRepository    $activity      Activity rows, for visibility and excerpts.
	 * @param Activity              $writer        Activity service, for admin deletion.
	 * @param Privacy               $privacy       Visibility of items and comments.
	 * @param Profiles              $profiles      Visibility of members.
	 * @param Groups                $groups        Visibility of groups.
	 * @param Membership            $membership    Group bans.
	 * @param Messages              $messages      Visibility of messages.
	 * @param MessageRepository     $message_rows  Message rows, to resolve a thread.
	 * @param Notifications         $notifications Reporter notifications.
	 * @param NotificationRenderers $renderers     Renderer registry.
	 * @param MemberRepository      $members       Member index, for reporter display data.
	 */
	public function __construct(
		private ReportRepository $reports,
		private ActivityRepository $activity,
		private Activity $writer,
		private Privacy $privacy,
		private Profiles $profiles,
		private Groups $groups,
		private Membership $membership,
		private Messages $messages,
		private MessageRepository $message_rows,
		private Notifications $notifications,
		private NotificationRenderers $renderers,
		private MemberRepository $members
	) {
	}

	/**
	 * Register hooks: the reporter's notification sentence, and closing open
	 * reports whose content is gone.
	 */
	public function boot(): void {
		$this->renderers->register( self::COMPONENT, 'resolved', new ReportNotificationRenderer() );

		add_action( 'odsi_social_activity_deleted', array( $this, 'on_activity_deleted' ) );
		add_action( 'odsi_social_group_deleted', array( $this, 'on_group_deleted' ) );
	}

	/**
	 * Reason keys with their labels.
	 *
	 * @return array<string, string>
	 */
	public static function reason_labels(): array {
		return array(
			'spam'          => __( 'Spam', 'odsi-social' ),
			'harassment'    => __( 'Harassment', 'odsi-social' ),
			'inappropriate' => __( 'Inappropriate content', 'odsi-social' ),
			'other'         => __( 'Something else', 'odsi-social' ),
		);
	}

	/**
	 * Whether the actor may review reports (SOC-MOD-013).
	 *
	 * @param int $actor_id Actor.
	 */
	public function can_moderate( int $actor_id ): bool {
		return Capabilities::is_admin( $actor_id );
	}

	/**
	 * File a report (SOC-MOD-010..012).
	 *
	 * @param int    $actor_id    Reporter.
	 * @param string $object_type One of the TYPE_* constants.
	 * @param int    $object_id   Object id.
	 * @param string $reason      One of REASONS.
	 * @param string $details     Free text.
	 *
	 * @return int|WP_Error Report id; an existing open report's id on a repeat.
	 */
	public function report( int $actor_id, string $object_type, int $object_id, string $reason, string $details = '' ): int|WP_Error {
		if ( $actor_id <= 0 ) {
			return new WP_Error( 'odsi_social_login_required', __( 'Please log in to report content.', 'odsi-social' ), array( 'status' => 401 ) );
		}

		if ( ! in_array( $object_type, self::TYPES, true ) || $object_id <= 0 ) {
			return new WP_Error( 'odsi_social_invalid_report', __( 'That cannot be reported.', 'odsi-social' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $reason, self::REASONS, true ) ) {
			return new WP_Error( 'odsi_social_invalid_reason', __( 'Please choose a reason.', 'odsi-social' ), array( 'status' => 400 ) );
		}

		$target = $this->resolve( $actor_id, $object_type, $object_id );

		if ( $target instanceof WP_Error ) {
			return $target;
		}

		if ( $target['author_id'] === $actor_id ) {
			return new WP_Error( 'odsi_social_cannot_report_self', __( 'You cannot report your own content.', 'odsi-social' ), array( 'status' => 400 ) );
		}

		$object_type = $target['type'];
		$existing    = $this->reports->find_open( $actor_id, $object_type, $object_id );

		if ( $existing ) {
			return (int) $existing->id;
		}

		$limited = RateLimiter::check( 'report', $actor_id );

		if ( $limited instanceof WP_Error ) {
			return $limited;
		}

		$details = mb_substr( sanitize_textarea_field( $details ), 0, self::DETAILS_MAX );
		$id      = $this->reports->create( $actor_id, $object_type, $object_id, $reason, $details );

		if ( $id <= 0 ) {
			return new WP_Error( 'odsi_social_report_failed', __( 'The report could not be saved.', 'odsi-social' ) );
		}

		$row = $this->reports->find( $id );

		/**
		 * Fires after a member reports content.
		 *
		 * @param object $report Report row.
		 */
		do_action( 'odsi_social_content_reported', $row );

		return $id;
	}

	/**
	 * Dismiss an open report (SOC-MOD-013).
	 *
	 * @param int $admin_id Admin.
	 * @param int $id       Report id.
	 *
	 * @return true|WP_Error
	 */
	public function dismiss( int $admin_id, int $id ): bool|WP_Error {
		$row = $this->open_report_for( $admin_id, $id );

		if ( $row instanceof WP_Error ) {
			return $row;
		}

		$this->close( $row, ReportRepository::STATUS_DISMISSED, $admin_id, 'dismissed' );

		return true;
	}

	/**
	 * Act on an open report (SOC-MOD-014): delete the content, or ban its
	 * author from the group it was posted in.
	 *
	 * @param int    $admin_id Admin.
	 * @param int    $id       Report id.
	 * @param string $action   One of ACTIONS.
	 *
	 * @return true|WP_Error
	 */
	public function action( int $admin_id, int $id, string $action ): bool|WP_Error {
		$row = $this->open_report_for( $admin_id, $id );

		if ( $row instanceof WP_Error ) {
			return $row;
		}

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new WP_Error( 'odsi_social_invalid_action', __( 'Unknown action.', 'odsi-social' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( (string) $row->object_type, array( self::TYPE_ACTIVITY, self::TYPE_COMMENT ), true ) ) {
			return new WP_Error( 'odsi_social_action_unavailable', __( 'That action does not apply to this report.', 'odsi-social' ), array( 'status' => 400 ) );
		}

		$item = $this->activity->find( (int) $row->object_id );

		$this->in_progress[ (int) $row->id ] = true;

		try {
			if ( self::ACTION_DELETE_CONTENT === $action ) {
				// Content already gone (the author or another admin removed it)
				// leaves nothing to do but close the report.
				$result = $item ? $this->writer->delete( $admin_id, (int) $item->id ) : true;
			} else {
				if ( ! $item ) {
					return new WP_Error( 'odsi_social_content_gone', __( 'That content no longer exists.', 'odsi-social' ), array( 'status' => 404 ) );
				}

				if ( (int) $item->group_id <= 0 ) {
					return new WP_Error( 'odsi_social_action_unavailable', __( 'That content is not in a group.', 'odsi-social' ), array( 'status' => 400 ) );
				}

				$result = $this->membership->ban( $admin_id, (int) $item->group_id, (int) $item->user_id );
			}

			if ( $result instanceof WP_Error ) {
				return $result;
			}
		} finally {
			unset( $this->in_progress[ (int) $row->id ] );
		}//end try

		$this->close( $row, ReportRepository::STATUS_ACTIONED, $admin_id, $action );

		return true;
	}

	/**
	 * Open reports, for the menu badge.
	 */
	public function open_count(): int {
		return $this->reports->count( ReportRepository::STATUS_OPEN );
	}

	/**
	 * How many reports are in a status.
	 *
	 * @param string $status Status.
	 */
	public function count( string $status ): int {
		return $this->reports->count( $status );
	}

	/**
	 * A page of reports, presented for review (SOC-MOD-015).
	 *
	 * @param int    $admin_id Admin.
	 * @param string $status   Status.
	 * @param int    $page     Page.
	 * @param int    $per_page Page size.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function list( int $admin_id, string $status = ReportRepository::STATUS_OPEN, int $page = 1, int $per_page = 20 ): array|WP_Error {
		if ( ! $this->can_moderate( $admin_id ) ) {
			return $this->forbidden();
		}

		$status = in_array( $status, array( ReportRepository::STATUS_OPEN, ReportRepository::STATUS_DISMISSED, ReportRepository::STATUS_ACTIONED ), true ) ? $status : ReportRepository::STATUS_OPEN;
		$rows   = $this->reports->list( $status, max( 1, $per_page ), max( 0, $page - 1 ) * max( 1, $per_page ) );

		// Everything a row shows — the reported content, its author, the
		// reporter and the resolver — is fetched once for the page.
		$activity_ids = array();
		$user_ids     = array();
		$group_ids    = array();

		foreach ( $rows as $row ) {
			$user_ids[] = (int) $row->reporter_id;
			$user_ids[] = (int) $row->resolved_by;

			switch ( (string) $row->object_type ) {
				case self::TYPE_ACTIVITY:
				case self::TYPE_COMMENT:
					$activity_ids[] = (int) $row->object_id;
					break;

				case self::TYPE_MEMBER:
					$user_ids[] = (int) $row->object_id;
					break;

				case self::TYPE_GROUP:
					$group_ids[] = (int) $row->object_id;
					break;
			}
		}

		$items = $this->activity->find_many( $activity_ids );

		foreach ( $items as $item ) {
			$user_ids[] = (int) $item->user_id;

			if ( (int) $item->group_id > 0 ) {
				$group_ids[] = (int) $item->group_id;
			}
		}

		$this->members->prime_display( $user_ids );

		if ( array() !== $group_ids ) {
			_prime_post_caches( array_values( array_unique( $group_ids ) ), false, false );
		}

		return array_map( fn ( object $row ): array => $this->present( $row, $items ), $rows );
	}

	/**
	 * Presentation shape of one report.
	 *
	 * @param object             $row   Report row.
	 * @param array<int, object> $items Activity rows keyed by id, when already fetched.
	 *
	 * @return array<string, mixed>
	 */
	public function present( object $row, array $items = array() ): array {
		$reporter  = get_userdata( (int) $row->reporter_id );
		$resolver  = (int) $row->resolved_by > 0 ? get_userdata( (int) $row->resolved_by ) : null;
		$described = $this->describe( $row, $items );

		return array(
			'id'           => (int) $row->id,
			'object_type'  => (string) $row->object_type,
			'object_id'    => (int) $row->object_id,
			'object'       => $described,
			'reporter'     => array(
				'id'   => (int) $row->reporter_id,
				'name' => $reporter ? $reporter->display_name : __( 'A former member', 'odsi-social' ),
				'url'  => $reporter ? (string) apply_filters( 'odsi_social_member_url', '', (int) $row->reporter_id ) : '',
			),
			'reason'       => (string) $row->reason,
			'reason_label' => self::reason_labels()[ (string) $row->reason ] ?? (string) $row->reason,
			'details'      => (string) $row->details,
			'status'       => (string) $row->status,
			'created'      => (string) $row->created_at,
			'age'          => sprintf(
				/* translators: %s: human time difference. */
				__( '%s ago', 'odsi-social' ),
				human_time_diff( (int) strtotime( (string) $row->created_at ) )
			),
			'resolved_at'  => (string) ( $row->resolved_at ?? '' ),
			'resolved_by'  => $resolver ? $resolver->display_name : '',
			'resolution'   => (string) $row->resolution,
			'actions'      => ReportRepository::STATUS_OPEN === (string) $row->status ? $this->available_actions( $row, $described ) : array(),
		);
	}

	/**
	 * Close open reports about a deleted item or comment: the content is gone,
	 * so the complaint is answered (SOC-MOD-014a).
	 *
	 * @param object $item Activity row.
	 */
	public function on_activity_deleted( object $item ): void {
		foreach ( $this->reports->open_for_object( array( self::TYPE_ACTIVITY, self::TYPE_COMMENT ), (int) $item->id ) as $row ) {
			if ( isset( $this->in_progress[ (int) $row->id ] ) ) {
				continue;
			}

			$this->close( $row, ReportRepository::STATUS_ACTIONED, get_current_user_id(), 'content_deleted' );
		}
	}

	/**
	 * Close open reports about a deleted group.
	 *
	 * @param int $group_id Group.
	 */
	public function on_group_deleted( int $group_id ): void {
		foreach ( $this->reports->open_for_object( array( self::TYPE_GROUP ), $group_id ) as $row ) {
			$this->close( $row, ReportRepository::STATUS_ACTIONED, get_current_user_id(), 'content_deleted' );
		}
	}

	/**
	 * Retention sweep (SOC-MOD-016): resolved reports older than the cutoff.
	 *
	 * @param int $days Days to keep resolved reports.
	 */
	public function purge_resolved_older_than( int $days ): int {
		return $this->reports->purge_resolved_before( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Check the object exists and the reporter may see it (SOC-MOD-011),
	 * returning its author and canonical type.
	 *
	 * @param int    $actor_id    Reporter.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 *
	 * @return array{type: string, author_id: int}|WP_Error
	 */
	private function resolve( int $actor_id, string $object_type, int $object_id ): array|WP_Error {
		switch ( $object_type ) {
			case self::TYPE_ACTIVITY:
			case self::TYPE_COMMENT:
				$item = $this->activity->find( $object_id );

				if ( ! $item || ! $this->privacy->can_view( $actor_id, $item ) ) {
					return $this->not_found();
				}

				return array(
					'type'      => (int) $item->parent_id > 0 ? self::TYPE_COMMENT : self::TYPE_ACTIVITY,
					'author_id' => (int) $item->user_id,
				);

			case self::TYPE_MEMBER:
				if ( ! $this->profiles->is_visible( $actor_id, $object_id ) ) {
					return $this->not_found();
				}

				return array(
					'type'      => self::TYPE_MEMBER,
					'author_id' => $object_id,
				);

			case self::TYPE_GROUP:
				if ( ! $this->groups->can_view( $actor_id, $object_id ) ) {
					return $this->not_found();
				}

				return array(
					'type'      => self::TYPE_GROUP,
					'author_id' => 0,
				);

			case self::TYPE_MESSAGE:
				$message = $this->message_rows->find( $object_id );

				if ( ! $message || ! $this->messages->can_read( $actor_id, (int) $message->thread_id ) ) {
					return $this->not_found();
				}

				return array(
					'type'      => self::TYPE_MESSAGE,
					'author_id' => (int) $message->sender_id,
				);

			default:
				return $this->not_found();
		}//end switch
	}

	/**
	 * What the report is about, for the queue: a label, an excerpt, a link
	 * and the author. Message content is never shown (SOC-MSG-007).
	 *
	 * @param object             $row   Report row.
	 * @param array<int, object> $items Activity rows keyed by id.
	 *
	 * @return array{label: string, excerpt: string, url: string, author_id: int, author: string, group_id: int, exists: bool}
	 */
	private function describe( object $row, array $items ): array {
		$out = array(
			'label'     => '',
			'excerpt'   => '',
			'url'       => '',
			'author_id' => 0,
			'author'    => '',
			'group_id'  => 0,
			'exists'    => false,
		);

		switch ( (string) $row->object_type ) {
			case self::TYPE_ACTIVITY:
			case self::TYPE_COMMENT:
				$out['label'] = self::TYPE_COMMENT === (string) $row->object_type ? __( 'Comment', 'odsi-social' ) : __( 'Post', 'odsi-social' );
				$item         = $items[ (int) $row->object_id ] ?? $this->activity->find( (int) $row->object_id );

				if ( $item ) {
					$out['exists']    = true;
					$out['excerpt']   = wp_trim_words( wp_strip_all_tags( (string) $item->content ), 30 );
					$out['url']       = (string) apply_filters( 'odsi_social_activity_url', '', (int) ( $item->parent_id ?: $item->id ) );
					$out['author_id'] = (int) $item->user_id;
					$out['group_id']  = (int) $item->group_id;
				} else {
					$out['excerpt'] = __( '(deleted)', 'odsi-social' );
				}
				break;

			case self::TYPE_MEMBER:
				$out['label']     = __( 'Member', 'odsi-social' );
				$out['author_id'] = (int) $row->object_id;
				$out['exists']    = (bool) get_userdata( (int) $row->object_id );
				$out['url']       = $out['exists'] ? (string) apply_filters( 'odsi_social_member_url', '', (int) $row->object_id ) : '';
				break;

			case self::TYPE_GROUP:
				$out['label']   = __( 'Group', 'odsi-social' );
				$post           = get_post( (int) $row->object_id );
				$out['exists']  = (bool) $post;
				$out['excerpt'] = $post ? html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ) : __( '(deleted)', 'odsi-social' );
				$out['url']     = $post ? (string) apply_filters( 'odsi_social_group_url', '', (int) $row->object_id ) : '';
				break;

			case self::TYPE_MESSAGE:
				$out['label']   = __( 'Private message', 'odsi-social' );
				$out['excerpt'] = __( 'Message content is only visible to the conversation.', 'odsi-social' );
				$out['exists']  = true;
				break;
		}//end switch

		if ( $out['author_id'] > 0 ) {
			$author        = get_userdata( $out['author_id'] );
			$out['author'] = $author ? $author->display_name : __( 'A former member', 'odsi-social' );
		}

		return $out;
	}

	/**
	 * Which resolutions an admin may pick for a row.
	 *
	 * @param object               $row       Report row.
	 * @param array<string, mixed> $described Described object.
	 *
	 * @return string[]
	 */
	private function available_actions( object $row, array $described ): array {
		$actions = array( 'dismiss' );

		if ( in_array( (string) $row->object_type, array( self::TYPE_ACTIVITY, self::TYPE_COMMENT ), true ) ) {
			$actions[] = self::ACTION_DELETE_CONTENT;

			if ( ! empty( $described['exists'] ) && (int) $described['group_id'] > 0 ) {
				$actions[] = self::ACTION_BAN_FROM_GROUP;
			}
		}

		return $actions;
	}

	/**
	 * Resolve a row, tell the reporter and announce it.
	 *
	 * @param object $row         Report row.
	 * @param string $status      New status.
	 * @param int    $resolved_by Admin, or the current user for a system close.
	 * @param string $resolution  What was done.
	 */
	private function close( object $row, string $status, int $resolved_by, string $resolution ): void {
		$this->reports->resolve( (int) $row->id, $status, $resolved_by, $resolution );

		$updated = $this->reports->find( (int) $row->id ) ?? $row;

		// The reporter hears the outcome, never who decided it (SOC-MOD-015).
		$this->notifications->notify( (int) $row->reporter_id, $resolved_by, self::COMPONENT, 'resolved', (int) $row->id );

		/**
		 * Fires after a report is dismissed or actioned.
		 *
		 * @param object $report     Report row, resolved.
		 * @param string $resolution `dismissed`, `delete_content`, `ban_from_group` or `content_deleted`.
		 */
		do_action( 'odsi_social_report_resolved', $updated, $resolution );
	}

	/**
	 * An open report the admin may act on.
	 *
	 * @param int $admin_id Admin.
	 * @param int $id       Report id.
	 *
	 * @return stdClass|WP_Error
	 */
	private function open_report_for( int $admin_id, int $id ): stdClass|WP_Error {
		if ( ! $this->can_moderate( $admin_id ) ) {
			return $this->forbidden();
		}

		$row = $this->reports->find( $id );

		if ( ! $row instanceof stdClass ) {
			return new WP_Error( 'odsi_social_report_not_found', __( 'That report does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
		}

		if ( ReportRepository::STATUS_OPEN !== (string) $row->status ) {
			return new WP_Error( 'odsi_social_report_closed', __( 'That report has already been resolved.', 'odsi-social' ), array( 'status' => 409 ) );
		}

		return $row;
	}

	/**
	 * 404-style error (ADR-011).
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'odsi_social_not_found', __( 'That does not exist.', 'odsi-social' ), array( 'status' => 404 ) );
	}

	/**
	 * 403-style error.
	 */
	private function forbidden(): WP_Error {
		return new WP_Error( 'odsi_social_forbidden', __( 'You cannot review reports.', 'odsi-social' ), array( 'status' => 403 ) );
	}
}
