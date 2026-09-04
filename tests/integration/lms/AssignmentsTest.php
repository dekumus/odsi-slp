<?php
/**
 * Assignments. Spec: LMS-ASN-001..009.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Assignments\Assignments;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\Plugin;
use ODSI\LMS\Repositories\SubmissionRepository;
use ODSI\LMS\Support\Meta;
use ODSI\Tests\Integration\TestCase;
use WP_Error;
use WP_REST_Request;

final class AssignmentsTest extends TestCase {

	private const NS = '/odsi-lms/v1';

	private Assignments $assignments;
	private Progress $progress;

	public function set_up(): void {
		parent::set_up();
		$this->assignments = Plugin::instance()->container()->get( Assignments::class );
		$this->progress    = Plugin::instance()->container()->get( Progress::class );
		Plugin::instance()->container()->get( Structure::class )->flush();
	}

	/**
	 * A standard course whose first lesson requires an assignment.
	 *
	 * @param array<string, mixed> $meta Extra meta on the lesson.
	 *
	 * @return array{course: int, lesson1: int, lesson2: int, topic21: int, topic22: int, quiz2: int, question: int, lesson3: int}
	 */
	private function course_with_assignment( array $meta = array() ): array {
		$c = $this->lms->standard_course();
		update_post_meta( $c['lesson1'], Meta::ASSIGNMENT_REQUIRED, true );
		update_post_meta( $c['lesson1'], Meta::ASSIGNMENT_POINTS, 10 );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $c['lesson1'], $key, $value );
		}

		return $c;
	}

	public function test_asn_001_only_leaf_lessons_and_topics_carry_assignments(): void {
		$c = $this->course_with_assignment();
		update_post_meta( $c['lesson2'], Meta::ASSIGNMENT_REQUIRED, true );

		self::assertTrue( $this->assignments->requires_assignment( $c['lesson1'] ) );
		self::assertFalse( $this->assignments->requires_assignment( $c['lesson2'] ), 'A section cannot carry an assignment.' );
		self::assertFalse( $this->assignments->requires_assignment( $c['lesson3'] ) );
		self::assertFalse( $this->assignments->requires_assignment( $c['quiz2'] ) );
	}

	public function test_asn_002_to_005_submit_rules_and_completion_gate(): void {
		$c    = $this->course_with_assignment();
		$user = $this->lms->enrolled_learner( $c['course'] );

		self::assertFalse( $this->progress->complete_step( $user, $c['lesson1'] ), 'LMS-ASN-005: no completion without an approved submission.' );

		$empty = $this->assignments->submit( $user, $c['lesson1'], '   ' );
		self::assertInstanceOf( WP_Error::class, $empty );
		self::assertSame( 'odsi_lms_submission_empty', $empty->get_error_code() );

		$none = $this->assignments->submit( $user, $c['lesson3'], 'hello' );
		self::assertSame( 'odsi_lms_no_assignment', $none->get_error_code() );

		$stranger = $this->lms->learner();
		$locked   = $this->assignments->submit( $stranger, $c['lesson1'], 'hello' );
		self::assertSame( 'odsi_lms_step_locked', $locked->get_error_code(), 'LMS-ASN-003: must be able to open the step.' );

		$events = array();
		add_action(
			'odsi_lms_submission_created',
			static function ( int $id, int $user_id, int $step_id ) use ( &$events ): void {
				$events[] = array( $id, $user_id, $step_id );
			},
			10,
			3
		);

		$id = $this->assignments->submit( $user, $c['lesson1'], '<p>My essay</p><script>x</script>' );
		self::assertIsInt( $id );
		self::assertSame( array( array( $id, $user, $c['lesson1'] ) ), $events );

		$row = $this->assignments->repository()->find( $id );
		self::assertSame( SubmissionRepository::STATUS_PENDING, $row->status );
		self::assertSame( '<p>My essay</p>x', $row->content, 'Content is filtered with wp_kses_post.' );
		self::assertSame( 10.0, (float) $row->points_possible );

		$again = $this->assignments->submit( $user, $c['lesson1'], 'again' );
		self::assertSame( 'odsi_lms_submission_pending', $again->get_error_code(), 'LMS-ASN-004: one pending submission at a time.' );

		self::assertFalse( $this->progress->complete_step( $user, $c['lesson1'] ), 'Pending is not approved.' );
	}

	public function test_asn_006_approval_completes_the_step_and_caps_points(): void {
		$c          = $this->course_with_assignment();
		$user       = $this->lms->enrolled_learner( $c['course'] );
		$instructor = $this->lms->instructor();
		$id         = $this->assignments->submit( $user, $c['lesson1'], 'done' );

		$graded = array();
		add_action(
			'odsi_lms_submission_graded',
			static function ( int $id, string $status ) use ( &$graded ): void {
				$graded[] = array( $id, $status );
			},
			10,
			2
		);

		self::assertTrue( $this->assignments->approve( $id, 99.0, 'Nice work', $instructor ) );

		$row = $this->assignments->repository()->find( $id );
		self::assertSame( SubmissionRepository::STATUS_APPROVED, $row->status );
		self::assertSame( 10.0, (float) $row->points_earned, 'Points are capped at the assignment maximum.' );
		self::assertSame( 'Nice work', $row->feedback );
		self::assertSame( $instructor, (int) $row->graded_by );
		self::assertSame( array( array( $id, 'approved' ) ), $graded );

		self::assertTrue( $this->progress->repository()->is_completed( $user, $c['lesson1'] ), 'Approval completes the step.' );
		self::assertSame( 16.67, $this->progress->course_percentage( $user, $c['course'] ) );

		$again = $this->assignments->submit( $user, $c['lesson1'], 'more' );
		self::assertSame( 'odsi_lms_already_approved', $again->get_error_code() );
	}

	public function test_asn_007_rejection_allows_resubmission(): void {
		$c    = $this->course_with_assignment();
		$user = $this->lms->enrolled_learner( $c['course'] );
		$id   = $this->assignments->submit( $user, $c['lesson1'], 'first try' );

		self::assertTrue( $this->assignments->reject( $id, 'Try again', 1 ) );
		self::assertFalse( $this->progress->repository()->is_completed( $user, $c['lesson1'] ) );

		$second = $this->assignments->submit( $user, $c['lesson1'], 'second try' );
		self::assertIsInt( $second );
		self::assertCount( 2, $this->assignments->repository()->history( $user, $c['lesson1'] ) );

		$this->assignments->approve( $second, 5.0, '', 1 );
		self::assertTrue( $this->progress->repository()->is_completed( $user, $c['lesson1'] ) );
	}

	public function test_asn_002_auto_approve_completes_on_receipt(): void {
		$c    = $this->course_with_assignment( array( Meta::ASSIGNMENT_AUTO_APPROVE => true ) );
		$user = $this->lms->enrolled_learner( $c['course'] );
		$id   = $this->assignments->submit( $user, $c['lesson1'], 'auto' );

		$row = $this->assignments->repository()->find( $id );
		self::assertSame( SubmissionRepository::STATUS_APPROVED, $row->status );
		self::assertSame( 10.0, (float) $row->points_earned );
		self::assertTrue( $this->progress->repository()->is_completed( $user, $c['lesson1'] ) );
	}

	public function test_asn_009_progress_reset_clears_submissions(): void {
		$c    = $this->course_with_assignment();
		$user = $this->lms->enrolled_learner( $c['course'] );
		$this->assignments->submit( $user, $c['lesson1'], 'x' );

		$this->lms->enrollment()->reset_progress( $user, $c['course'] );

		self::assertSame( array(), $this->assignments->repository()->history( $user, $c['lesson1'] ) );
	}

	public function test_file_uploads_respect_the_allow_list(): void {
		$c    = $this->course_with_assignment();
		$user = $this->lms->enrolled_learner( $c['course'] );

		add_filter( 'odsi_lms_assignment_upload_handler', static fn (): string => 'wp_handle_sideload' );

		$bad = $this->assignments->submit( $user, $c['lesson1'], '', $this->staged_file( 'evil.php', '<?php echo 1;' ) );
		self::assertInstanceOf( WP_Error::class, $bad );
		self::assertSame( 'odsi_lms_upload_failed', $bad->get_error_code() );

		$id = $this->assignments->submit( $user, $c['lesson1'], '', $this->staged_file( 'essay.txt', 'plain text essay' ) );
		self::assertIsInt( $id );

		$row = $this->assignments->repository()->find( $id );
		self::assertGreaterThan( 0, (int) $row->attachment_id );
		self::assertSame( 'attachment', get_post_type( (int) $row->attachment_id ) );
		self::assertSame( $user, (int) get_post_field( 'post_author', (int) $row->attachment_id ) );
		self::assertStringEndsWith( '.txt', $this->assignments->present( $row )['attachment_url'] );
	}

	public function test_rest_learner_routes(): void {
		$c    = $this->course_with_assignment();
		$user = $this->lms->enrolled_learner( $c['course'] );

		self::assertSame( 401, $this->rest( 'POST', self::NS . "/steps/{$c['lesson1']}/submissions", array( 'content' => 'x' ) )->get_status() );

		$created = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/steps/{$c['lesson1']}/submissions", array( 'content' => 'over rest' ) ) );
		self::assertSame( 201, $created->get_status() );
		self::assertSame( 'pending', $created->get_data()['status'] );

		$mine = $this->as_user( $user, fn () => $this->rest( 'GET', self::NS . "/steps/{$c['lesson1']}/submissions" ) );
		self::assertSame( 200, $mine->get_status() );
		self::assertCount( 1, $mine->get_data()['submissions'] );
		self::assertFalse( $mine->get_data()['approved'] );

		$none = $this->as_user( $user, fn () => $this->rest( 'GET', self::NS . "/steps/{$c['lesson3']}/submissions" ) );
		self::assertSame( 404, $none->get_status() );

		$complete = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/steps/{$c['lesson1']}/complete" ) );
		self::assertSame( 400, $complete->get_status(), 'Mark-complete over REST cannot skip the assignment.' );
	}

	public function test_rest_grading_routes_are_scoped_to_the_course_author(): void {
		$instructor = $this->lms->instructor();
		$other      = $this->lms->instructor();
		$c          = $this->course_with_assignment();
		wp_update_post(
			array(
				'ID'          => $c['course'],
				'post_author' => $instructor,
			)
		);
		$user = $this->lms->enrolled_learner( $c['course'] );
		$id   = $this->assignments->submit( $user, $c['lesson1'], 'grade me' );

		self::assertSame( 403, $this->as_user( $user, fn () => $this->rest( 'GET', self::NS . '/submissions' ) )->get_status() );

		$queue = $this->as_user( $instructor, fn () => $this->rest( 'GET', self::NS . '/submissions' ) );
		self::assertSame( 200, $queue->get_status() );
		self::assertSame( 1, $queue->get_data()['total'] );

		$elsewhere = $this->as_user( $other, fn () => $this->rest( 'GET', self::NS . '/submissions' ) );
		self::assertSame( 0, $elsewhere->get_data()['total'], 'Another instructor sees nothing from this course.' );
		self::assertSame( 403, $this->as_user( $other, fn () => $this->rest( 'GET', self::NS . '/submissions', array( 'course' => $c['course'] ) ) )->get_status() );

		$forbidden = $this->as_user(
			$other,
			fn () => $this->rest(
				'POST',
				self::NS . "/submissions/{$id}/grade",
				array( 'status' => 'approved' )
			)
		);
		self::assertSame( 403, $forbidden->get_status() );

		$graded = $this->as_user(
			$instructor,
			fn () => $this->rest(
				'POST',
				self::NS . "/submissions/{$id}/grade",
				array(
					'status'   => 'approved',
					'points'   => 8,
					'feedback' => 'Good',
				)
			)
		);
		self::assertSame( 200, $graded->get_status() );
		self::assertSame( 8.0, $graded->get_data()['points_earned'] );
		self::assertTrue( $this->progress->repository()->is_completed( $user, $c['lesson1'] ) );

		self::assertSame( 404, $this->as_user( $instructor, fn () => $this->rest( 'POST', self::NS . '/submissions/999999/grade', array( 'status' => 'rejected' ) ) )->get_status() );
	}

	/**
	 * Stage a file the way PHP would for an upload.
	 *
	 * @param string $name    File name.
	 * @param string $content Content.
	 *
	 * @return array<string, mixed>
	 */
	private function staged_file( string $name, string $content ): array {
		$path = wp_tempnam( $name );
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return array(
			'name'     => $name,
			'type'     => 'application/octet-stream',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $content ),
		);
	}
}
