<?php
/**
 * Regressions for the security review. Spec: LMS-ACC-002/007/008, LMS-AUT-008, LMS-ASN-010, LMS-ADM-006.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Admin\CourseBuilder;
use ODSI\LMS\Assignments\Assignments;
use ODSI\LMS\Courses\Access;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\Plugin;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Reports\EnrollmentReport;
use ODSI\LMS\Support\Meta;
use ODSI\Tests\Integration\TestCase;

final class SecurityTest extends TestCase {

	private Access $access;

	public function set_up(): void {
		parent::set_up();
		$this->access = Plugin::instance()->container()->get( Access::class );
		Plugin::instance()->container()->get( Structure::class )->flush();
		do_action( 'rest_api_init' );
	}

	public function test_acc_008_core_rest_feeds_and_excerpts_do_not_leak_locked_content(): void {
		$c = $this->lms->standard_course( array( 'meta' => array( Meta::ACCESS_MODE => 'closed' ) ) );
		wp_update_post(
			array(
				'ID'           => $c['lesson1'],
				'post_content' => 'SECRET LESSON BODY',
				'post_excerpt' => 'SECRET EXCERPT',
			)
		);

		$visitor = $this->rest( 'GET', "/wp/v2/odsi_lesson/{$c['lesson1']}" );
		self::assertSame( 200, $visitor->get_status() );
		self::assertStringNotContainsString( 'SECRET', (string) $visitor->get_data()['content']['rendered'] );
		self::assertSame( '', $visitor->get_data()['excerpt']['rendered'] );
		self::assertTrue( $visitor->get_data()['content']['protected'] );

		$list = $this->rest( 'GET', '/wp/v2/odsi_lesson', array( 'search' => 'SECRET' ) );
		foreach ( $list->get_data() as $item ) {
			self::assertStringNotContainsString( 'SECRET', (string) $item['content']['rendered'] );
		}

		// the_content outside a singular query (feeds, query loops, search excerpts).
		$GLOBALS['post'] = get_post( $c['lesson1'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		self::assertStringNotContainsString( 'SECRET', (string) apply_filters( 'the_content', 'SECRET LESSON BODY' ) );
		self::assertStringNotContainsString( 'SECRET', get_the_excerpt( $c['lesson1'] ) );
		wp_reset_postdata();

		self::assertTrue( get_post_type_object( PostTypes::LESSON )->exclude_from_search );

		// The learner who may open it still gets the body.
		$user = $this->lms->enrolled_learner( $c['course'] );
		$mine = $this->as_user( $user, fn () => $this->rest( 'GET', "/wp/v2/odsi_lesson/{$c['lesson1']}" ) );
		self::assertStringContainsString( 'SECRET LESSON BODY', (string) $mine->get_data()['content']['rendered'] );
	}

	public function test_questions_and_cohorts_are_not_readable_anonymously(): void {
		$c = $this->lms->standard_course();

		self::assertSame( 401, $this->rest( 'GET', "/wp/v2/odsi_question/{$c['question']}" )->get_status() );
		self::assertSame( 401, $this->rest( 'GET', '/wp/v2/odsi_question' )->get_status() );
		self::assertSame( 401, $this->rest( 'GET', '/wp/v2/odsi_cohort' )->get_status() );

		$learner = $this->lms->enrolled_learner( $c['course'] );
		self::assertSame( 403, $this->as_user( $learner, fn () => $this->rest( 'GET', "/wp/v2/odsi_question/{$c['question']}" ) )->get_status() );

		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		self::assertSame( 200, $this->as_user( $admin, fn () => $this->rest( 'GET', "/wp/v2/odsi_question/{$c['question']}" ) )->get_status() );
	}

	public function test_drafts_cannot_be_opened_completed_or_attempted(): void {
		$c        = $this->lms->standard_course();
		$user     = $this->lms->enrolled_learner( $c['course'] );
		$progress = Plugin::instance()->container()->get( Progress::class );
		$draft    = $this->lms->lesson(
			$c['course'],
			array(
				'post_status' => 'draft',
				'menu_order'  => 0,
			)
		);

		self::assertFalse( $this->access->can_access_step( $user, $draft ) );
		self::assertSame( 'enroll', $this->access->lock_reason( $user, $draft ) );
		self::assertSame( 403, $this->as_user( $user, fn () => $this->rest( 'POST', "/odsi-lms/v1/steps/{$draft}/complete" ) )->get_status() );
		self::assertFalse( $progress->repository()->is_completed( $user, $draft ) );

		$draft_quiz = $this->lms->quiz( $c['course'], 0, array( 'post_status' => 'draft' ) );
		self::assertSame( 403, $this->as_user( $user, fn () => $this->rest( 'GET', "/odsi-lms/v1/quizzes/{$draft_quiz}/questions" ) )->get_status() );
		self::assertSame( 403, $this->as_user( $user, fn () => $this->rest( 'POST', "/odsi-lms/v1/quizzes/{$draft_quiz}/attempts" ) )->get_status() );

		$author = $this->lms->instructor();
		wp_update_post(
			array(
				'ID'          => $c['course'],
				'post_author' => $author,
			)
		);
		self::assertTrue( $this->access->can_access_step( $author, $draft ), 'The author previews drafts.' );
	}

	public function test_self_enrollment_needs_a_published_course(): void {
		$draft = $this->lms->course( array( 'post_status' => 'draft' ) );
		$user  = $this->lms->learner();

		self::assertSame( 404, $this->as_user( $user, fn () => $this->rest( 'POST', "/odsi-lms/v1/courses/{$draft}/enroll" ) )->get_status() );
		self::assertFalse( $this->lms->enrollment()->is_enrolled( $user, $draft ) );
		self::assertFalse( $this->access->can_access_course( $user, $draft ) );

		$open_draft = $this->lms->course(
			array(
				'post_status' => 'draft',
				'meta'        => array( Meta::ACCESS_MODE => 'open' ),
			)
		);
		self::assertFalse( $this->access->can_access_course( $user, $open_draft ), 'Open mode does not publish a draft.' );
		self::assertFalse( $this->lms->enrollment()->is_enrolled( $user, $open_draft ), 'And records no enrollment on read.' );
	}

	public function test_aut_008_instructor_cannot_place_nodes_in_another_authors_course(): void {
		$a = $this->lms->instructor();
		$b = $this->lms->instructor();

		$course_b = $this->lms->course( array( 'post_author' => $b ) );
		$course_a = $this->lms->course( array( 'post_author' => $a ) );
		$lesson   = $this->lms->lesson( $course_a, array( 'post_author' => $a ) );

		$builder = Plugin::instance()->container()->get( CourseBuilder::class );

		$_POST = array(
			'odsi_lms_relationships_nonce' => wp_create_nonce( 'odsi_lms_save_relationships' ),
			Meta::COURSE_ID                => (string) $course_b,
		);
		$this->as_user( $a, static fn () => $builder->save( $lesson, get_post( $lesson ) ) );
		$_POST = array();

		self::assertSame( $course_a, (int) get_post_meta( $lesson, Meta::COURSE_ID, true ), "A's lesson stays in A's course." );

		$_POST = array(
			'odsi_lms_relationships_nonce' => wp_create_nonce( 'odsi_lms_save_relationships' ),
			Meta::COURSE_ID                => (string) $course_b,
		);
		$this->as_user( $b, static fn () => $builder->save( $lesson, get_post( $lesson ) ) );
		$_POST = array();
		self::assertSame( $course_a, (int) get_post_meta( $lesson, Meta::COURSE_ID, true ), "B cannot edit A's lesson either." );
	}

	public function test_asn_010_learner_uploads_are_hidden_from_other_instructors_and_the_public(): void {
		$c = $this->lms->standard_course();
		update_post_meta( $c['lesson1'], Meta::ASSIGNMENT_REQUIRED, true );
		$user = $this->lms->enrolled_learner( $c['course'] );
		add_filter( 'odsi_lms_assignment_upload_handler', static fn (): string => 'wp_handle_sideload' );

		$path = wp_tempnam( 'essay.txt' );
		file_put_contents( $path, 'my essay' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$id  = Plugin::instance()->container()->get( Assignments::class )->submit(
			$user,
			$c['lesson1'],
			'',
			array(
				'name'     => 'essay.txt',
				'type'     => 'text/plain',
				'tmp_name' => $path,
				'error'    => UPLOAD_ERR_OK,
				'size'     => 8,
			)
		);
		$row = Plugin::instance()->container()->get( Assignments::class )->repository()->find( $id );
		$att = (int) $row->attachment_id;

		self::assertStringNotContainsString( 'essay', wp_basename( (string) get_attached_file( $att ) ), 'The stored name is unguessable.' );

		self::assertSame( 401, $this->rest( 'GET', "/wp/v2/media/{$att}" )->get_status() );
		$anon_list = $this->rest( 'GET', '/wp/v2/media', array( 'parent' => $c['lesson1'] ) );
		self::assertSame( array(), array_filter( (array) $anon_list->get_data(), static fn ( array $m ): bool => (int) $m['id'] === $att ) );

		$other = $this->lms->instructor();
		self::assertSame( 403, $this->as_user( $other, fn () => $this->rest( 'GET', "/wp/v2/media/{$att}" ) )->get_status() );
		$library = $this->as_user( $other, static fn (): array => (array) apply_filters( 'ajax_query_attachments_args', array() ) );
		self::assertSame( '_odsi_submission_user', $library['meta_query'][0]['key'] );

		self::assertSame( 200, $this->as_user( $user, fn () => $this->rest( 'GET', "/wp/v2/media/{$att}" ) )->get_status(), 'The learner sees their own file.' );
	}

	public function test_assignment_text_keeps_formatting_but_drops_images_and_links(): void {
		$c = $this->lms->standard_course();
		update_post_meta( $c['lesson1'], Meta::ASSIGNMENT_REQUIRED, true );
		$user = $this->lms->enrolled_learner( $c['course'] );
		$svc  = Plugin::instance()->container()->get( Assignments::class );

		$id  = $svc->submit( $user, $c['lesson1'], '<p><strong>Bold</strong> <img src="https://evil.example/pixel.gif"> <a href="https://evil.example">x</a></p>' );
		$row = $svc->repository()->find( $id );
		self::assertSame( '<p><strong>Bold</strong>  x</p>', $row->content );
	}

	public function test_adm_006_csv_neutralises_whitespace_led_formulas(): void {
		$c = $this->lms->standard_course();
		$this->lms->enrolled_learner( $c['course'] );
		// WordPress trims display names, so feed the raw cell through the row filter.
		add_filter( 'odsi_lms_report_csv_row', static fn ( array $row ): array => array( 'display_name' => "\t=CMD()" ) + $row );

		$handle = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		Plugin::instance()->container()->get( EnrollmentReport::class )->export_csv( $c['course'], '', $handle );
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		self::assertStringContainsString( "'\t=CMD()", $csv );
	}
}
