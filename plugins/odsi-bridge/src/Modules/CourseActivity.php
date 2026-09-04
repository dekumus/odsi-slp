<?php
/**
 * Course events in the activity feed.
 *
 * @package ODSI\Bridge
 */

declare( strict_types = 1 );

namespace ODSI\Bridge\Modules;

use ODSI\Bridge\Contracts\Bootable;
use ODSI\Bridge\Repositories\LinkRepository;
use ODSI\Bridge\Support\Settings;
use ODSI\Social\Activity\Activity;
use ODSI\Social\Activity\Privacy;
use ODSI\Social\Activity\Renderers;
use ODSI\Social\Contracts\ActivityRenderer;

defined( 'ABSPATH' ) || exit;

/**
 * Posts enrolled / completed / passed-quiz items, idempotently, into the
 * linked group when there is one (contract § 2).
 */
final class CourseActivity implements Bootable, ActivityRenderer {

	public const COMPONENT = 'learning';

	/**
	 * Constructor.
	 *
	 * @param LinkRepository $links    Links.
	 * @param Settings       $settings Settings.
	 */
	public function __construct(
		private LinkRepository $links,
		private Settings $settings
	) {
	}

	/**
	 * Register hooks at priority 20, after the owning plugins' own listeners.
	 */
	public function boot(): void {
		if ( ! $this->settings->enabled( 'course_activity' ) ) {
			return;
		}

		add_action( 'odsi_lms_user_enrolled', array( $this, 'on_enrolled' ), 20, 2 );
		add_action( 'odsi_lms_course_completed', array( $this, 'on_completed' ), 20, 2 );
		add_action( 'odsi_lms_quiz_completed', array( $this, 'on_quiz_completed' ), 20, 3 );

		$renderers = \ODSI\Social\Plugin::instance()->container()->get( Renderers::class );

		foreach ( array( 'enrolled', 'completed', 'passed_quiz' ) as $type ) {
			$renderers->register( $type, $this, self::COMPONENT );
		}
	}

	/**
	 * Enrolled.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 */
	public function on_enrolled( int $user_id, int $course_id ): void {
		$this->post( 'enrolled', $user_id, $course_id, $course_id );
	}

	/**
	 * Completed.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 */
	public function on_completed( int $user_id, int $course_id ): void {
		$this->post( 'completed', $user_id, $course_id, $course_id );
	}

	/**
	 * Quiz passed (first pass only, by idempotency key).
	 *
	 * @param array<string, mixed> $result  Result.
	 * @param int                  $user_id Learner.
	 * @param int                  $quiz_id Quiz.
	 */
	public function on_quiz_completed( array $result, int $user_id, int $quiz_id ): void {
		if ( empty( $result['passed'] ) ) {
			return;
		}

		$course_id = (int) get_post_meta( $quiz_id, '_odsi_course_id', true );

		$this->post( 'passed_quiz', $user_id, $course_id, $quiz_id );
	}

	/**
	 * Write the item through the social plugin's public service.
	 *
	 * @param string $type      Type.
	 * @param int    $user_id   Learner.
	 * @param int    $course_id Course.
	 * @param int    $item_id   Course or quiz the item is about.
	 */
	private function post( string $type, int $user_id, int $course_id, int $item_id ): void {
		/**
		 * Filters which course events become activity.
		 *
		 * @param string[] $events Event types.
		 */
		$events = (array) apply_filters( 'odsi_bridge_activity_events', array( 'enrolled', 'completed', 'passed_quiz' ) );

		if ( ! in_array( $type, $events, true ) ) {
			return;
		}

		// A draft or private course is not public knowledge; the feed must not
		// announce its title or who is on it.
		if ( 'publish' !== get_post_status( $course_id ) || 'publish' !== get_post_status( $item_id ) ) {
			return;
		}

		$group_id = $this->settings->enabled( 'group_linkage' ) ? $this->links->group_for( $course_id ) : 0;

		\ODSI\Social\Plugin::instance()->container()->get( Activity::class )->post(
			array(
				'user_id'           => $user_id,
				'component'         => self::COMPONENT,
				'type'              => $type,
				'content'           => '',
				'group_id'          => $group_id,
				'primary_item_id'   => $item_id,
				'secondary_item_id' => $course_id,
				'privacy'           => $group_id > 0 ? Privacy::GROUP : Privacy::MEMBERS,
				'external_id'       => "{$type}:{$item_id}:{$user_id}",
			)
		);

		/**
		 * Fires after the bridge posts a course event.
		 *
		 * @param string $type      Type.
		 * @param int    $user_id   Learner.
		 * @param int    $course_id Course.
		 * @param int    $item_id   Item.
		 */
		do_action( 'odsi_bridge_activity_posted', $type, $user_id, $course_id, $item_id );
	}

	/**
	 * Action sentence.
	 *
	 * @param object $item Activity row.
	 */
	public function action( object $item ): string {
		$user  = get_userdata( (int) $item->user_id );
		$name  = esc_html( $user ? $user->display_name : __( 'A former member', 'odsi-bridge' ) );
		$post  = get_post( (int) $item->primary_item_id );
		$title = $post ? $post->post_title : __( 'a course', 'odsi-bridge' );
		$link  = sprintf( '<a href="%s">%s</a>', esc_url( $post ? (string) get_permalink( $post ) : '' ), esc_html( $title ) );

		return match ( (string) $item->type ) {
			/* translators: 1: member name, 2: course link. */
			'completed'   => sprintf( esc_html__( '%1$s completed the course %2$s', 'odsi-bridge' ), $name, $link ),
			/* translators: 1: member name, 2: quiz link. */
			'passed_quiz' => sprintf( esc_html__( '%1$s passed the quiz %2$s', 'odsi-bridge' ), $name, $link ),
			/* translators: 1: member name, 2: course link. */
			default       => sprintf( esc_html__( '%1$s enrolled on %2$s', 'odsi-bridge' ), $name, $link ),
		};
	}

	/**
	 * No body.
	 *
	 * @param object $item Activity row.
	 */
	public function body( object $item ): string {
		return '';
	}
}
