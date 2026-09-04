<?php
/**
 * The integration contract, end to end.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Bridge;

use ODSI\Bridge\Database\Schema;
use ODSI\Bridge\Modules\GroupLinkage;
use ODSI\Bridge\Modules\ProgressVisibility;
use ODSI\Bridge\Plugin as Bridge;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Plugin as LMS;
use ODSI\Social\Activity\Feed;
use ODSI\Social\Repositories\ActivityRepository;
use ODSI\Social\Repositories\GroupMemberRepository;
use ODSI\Tests\Integration\TestCase;
use WP_Error;

final class BridgeTest extends TestCase {

	private GroupLinkage $linkage;
	private ActivityRepository $activity;

	public function set_up(): void {
		parent::set_up();
		$this->linkage  = Bridge::instance()->container()->get( GroupLinkage::class );
		$this->activity = $this->social->service( ActivityRepository::class );
		LMS::instance()->container()->get( \ODSI\LMS\Courses\Structure::class )->flush();
	}

	public function test_bridge_booted_with_its_table(): void {
		global $wpdb;

		$table = Schema::table( 'course_groups' );
		self::assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		self::assertSame( array(), \ODSI\Bridge\dependency_errors() );
	}

	public function test_enrollment_and_completion_post_activity_once(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->learner();

		$this->lms->enrollment()->enroll( $user, $c['course'] );
		$this->lms->enrollment()->enroll( $user, $c['course'], array( 'source' => 'again' ) );

		$items = $this->learning_items( $user );
		self::assertCount( 1, $items, 'One enrolled item, whatever the hook does.' );
		self::assertSame( 'enrolled', $items[0]->type );
		self::assertSame( 'members', $items[0]->privacy, 'Unlinked course: members-only.' );

		// Re-fire the hook by hand: idempotent.
		do_action( 'odsi_lms_user_enrolled', $user, $c['course'], 1, array() );
		self::assertCount( 1, $this->learning_items( $user ) );

		$progress = LMS::instance()->container()->get( Progress::class );
		foreach ( array( 'lesson1', 'topic21', 'topic22' ) as $k ) {
			$progress->complete_step( $user, $c[ $k ] );
		}
		$quiz    = LMS::instance()->container()->get( \ODSI\LMS\Quizzes\QuizService::class );
		$attempt = $quiz->start( $user, $c['quiz2'] );
		$quiz->submit( $attempt, array( $c['question'] => 0 ) );
		$progress->complete_step( $user, $c['lesson3'] );

		$types = array_map( static fn ( object $i ): string => $i->type, $this->learning_items( $user ) );
		self::assertEqualsCanonicalizing( array( 'enrolled', 'passed_quiz', 'completed' ), $types );

		// A second pass at the same quiz posts nothing new.
		$attempt = $quiz->start( $user, $c['quiz2'] );
		$quiz->submit( $attempt, array( $c['question'] => 0 ) );
		self::assertCount( 3, $this->learning_items( $user ) );

		$feed = $this->social->service( Feed::class )->page( $user, Feed::SCOPE_SITE, array( 'component' => 'learning' ) );
		self::assertStringContainsString( 'completed the course', $feed['items'][0]['action'] );
	}

	public function test_unpublished_courses_never_reach_the_feed(): void {
		$c    = $this->lms->standard_course( array( 'post_status' => 'private' ) );
		$user = $this->lms->learner();
		$this->lms->enrollment()->enroll( $user, $c['course'], array( 'source' => 'manual' ) );

		self::assertSame( array(), $this->learning_items( $user ), 'A private course title must not be announced to every member.' );

		wp_update_post(
			array(
				'ID'          => $c['course'],
				'post_status' => 'publish',
			)
		);
		$second = $this->lms->learner();
		$this->lms->enrollment()->enroll( $second, $c['course'] );
		self::assertCount( 1, $this->learning_items( $second ), 'Once published, enrollment is announced.' );
	}

	public function test_link_syncs_every_enrollment_not_just_the_first_page(): void {
		$organiser = $this->social->member();
		$group     = $this->social->group( $organiser, 'private', 'Big cohort' );
		$course    = $this->lms->course();
		$learners  = array();

		add_filter( 'odsi_lms_emails_enabled', '__return_false' );

		for ( $i = 0; $i < 205; $i++ ) {
			$learners[] = $this->lms->enrolled_learner( $course );
		}

		self::assertTrue( Bridge::instance()->container()->get( GroupLinkage::class )->link( $course, $group ) );

		$members = $this->social->service( GroupMemberRepository::class );
		self::assertSame( 206, $members->count( $group ), 'Organiser plus all 205 learners.' );
		self::assertTrue( $members->is_active( $group, $learners[204] ) );
	}

	public function test_failed_quiz_posts_nothing(): void {
		$c    = $this->lms->standard_course( array( 'meta' => array( '_odsi_linear_progression' => 0 ) ) );
		$user = $this->lms->enrolled_learner( $c['course'] );
		$quiz = LMS::instance()->container()->get( \ODSI\LMS\Quizzes\QuizService::class );
		$quiz->submit( $quiz->start( $user, $c['quiz2'] ), array( $c['question'] => 1 ) );

		self::assertSame( array( 'enrolled' ), array_map( static fn ( object $i ): string => $i->type, $this->learning_items( $user ) ) );
	}

	public function test_linkage_syncs_membership_both_ways(): void {
		$c        = $this->lms->standard_course();
		$owner    = $this->social->member();
		$group    = $this->social->group( $owner, 'hidden' );
		$existing = $this->lms->enrolled_learner( $c['course'] );
		$members  = $this->social->service( GroupMemberRepository::class );

		self::assertTrue( $this->linkage->link( $c['course'], $group ) );
		self::assertSame( $group, $this->linkage->group_for( $c['course'] ) );
		self::assertSame( $c['course'], $this->linkage->course_for( $group ) );
		self::assertTrue( $members->is_active( $group, $existing ), 'Existing enrollments are synced in.' );

		$newcomer = $this->lms->enrolled_learner( $c['course'] );
		self::assertTrue( $members->is_active( $group, $newcomer ), 'New enrollments join.' );

		$item = $this->learning_items( $newcomer )[0];
		self::assertSame( $group, (int) $item->group_id, 'Activity goes into the linked group.' );
		self::assertSame( 'group', $item->privacy );

		$this->lms->enrollment()->unenroll( $newcomer, $c['course'] );
		self::assertFalse( $members->is_active( $group, $newcomer ), 'Unenrolling leaves the group.' );

		$this->lms->enrollment()->unenroll( $owner, $c['course'] );
		self::assertTrue( $members->is_active( $group, $owner ), 'Organisers are never removed by the bridge.' );

		$this->social->service( \ODSI\Social\Groups\Membership::class )->remove( $existing, $group, $existing );
		self::assertTrue( $this->lms->enrollment()->is_enrolled( $existing, $c['course'] ), 'Leaving the group never changes enrollment.' );
	}

	public function test_link_is_one_to_one_and_removed_with_either_side(): void {
		$c1    = $this->lms->course();
		$c2    = $this->lms->course();
		$owner = $this->social->member();
		$g1    = $this->social->group( $owner );
		$g2    = $this->social->group( $owner );

		$this->linkage->link( $c1, $g1 );
		$this->linkage->link( $c2, $g1 );
		self::assertSame( 0, $this->linkage->group_for( $c1 ), 'A group links to one course.' );
		self::assertSame( $g1, $this->linkage->group_for( $c2 ) );

		$this->linkage->link( $c2, $g2 );
		self::assertSame( 0, $this->linkage->course_for( $g1 ), 'A course links to one group.' );

		wp_delete_post( $g2, true );
		self::assertSame( 0, $this->linkage->group_for( $c2 ), 'Group deleted → link gone.' );
		self::assertTrue( (bool) get_post( $c2 ), 'Course untouched.' );

		$this->linkage->link( $c1, $g1 );
		wp_delete_post( $c1, true );
		self::assertSame( 0, $this->linkage->course_for( $g1 ), 'Course deleted → link gone.' );
		self::assertTrue( (bool) get_post( $g1 ), 'Group untouched.' );

		self::assertFalse( $this->linkage->link( 999999, $g1 ) );
	}

	public function test_progress_visibility_for_members_only(): void {
		$c        = $this->lms->standard_course();
		$owner    = $this->social->member();
		$group    = $this->social->group( $owner, 'private' );
		$learner  = $this->lms->enrolled_learner( $c['course'] );
		$outsider = $this->social->member();
		$this->linkage->link( $c['course'], $group );
		LMS::instance()->container()->get( Progress::class )->complete_step( $learner, $c['lesson1'] );

		$visibility = Bridge::instance()->container()->get( ProgressVisibility::class );

		$result = $visibility->progress( $learner, $group );
		self::assertIsArray( $result );
		$by_id = array_column( $result['members'], 'percentage', 'id' );
		self::assertSame( 16.67, $by_id[ $learner ] );
		self::assertSame( 0.0, $by_id[ $owner ] );

		$denied = $visibility->progress( $outsider, $group );
		self::assertInstanceOf( WP_Error::class, $denied );
		self::assertSame( 404, $denied->get_error_data()['status'] );

		do_action( 'rest_api_init' );
		self::assertSame( 200, $this->as_user( $learner, fn () => $this->rest( 'GET', "/odsi-bridge/v1/groups/{$group}/progress" ) )->get_status() );
		self::assertSame( 404, $this->as_user( $outsider, fn () => $this->rest( 'GET', "/odsi-bridge/v1/groups/{$group}/progress" ) )->get_status() );
		self::assertInstanceOf( WP_Error::class, $visibility->progress( $owner, $this->social->group( $owner ) ), 'Unlinked group: 404.' );
	}

	public function test_modules_can_be_switched_off(): void {
		add_filter( 'odsi_bridge_modules', static fn ( bool $on, string $module ): bool => 'course_activity' !== $module && $on, 10, 2 );

		$fresh = new \ODSI\Bridge\Modules\CourseActivity( Bridge::instance()->container()->get( \ODSI\Bridge\Repositories\LinkRepository::class ), Bridge::instance()->container()->get( \ODSI\Bridge\Support\Settings::class ) );
		remove_all_actions( 'odsi_lms_user_enrolled' );
		$fresh->boot();

		self::assertFalse( has_action( 'odsi_lms_user_enrolled', array( $fresh, 'on_enrolled' ) ) );
	}

	public function test_neither_plugin_references_the_bridge(): void {
		foreach ( array( 'odsi-lms', 'odsi-social' ) as $slug ) {
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . "/plugins/{$slug}" ) ) as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local scan.
				if ( 'php' === $file->getExtension() && str_contains( (string) file_get_contents( $file->getPathname() ), 'ODSI\\Bridge' ) ) {
					self::fail( "{$slug} references the bridge: " . $file->getPathname() );
				}
			}
		}

		self::assertTrue( true );
	}

	/**
	 * Learning-component rows by a user, oldest first.
	 *
	 * @return object[]
	 */
	private function learning_items( int $user_id ): array {
		return array_values(
			array_filter(
				$this->activity->find_many( $this->activity->ids_by_user( $user_id ) ),
				static fn ( object $i ): bool => 'learning' === $i->component
			)
		);
	}
}
