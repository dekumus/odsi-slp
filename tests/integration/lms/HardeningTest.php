<?php
/**
 * Reports, grading queue, reset, certificates, cohorts, cache. Spec: LMS-ADM-*, LMS-ENR-007/012.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Certificates\Certificates;
use ODSI\LMS\Courses\Cohorts;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\Plugin;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Quizzes\QuizService;
use ODSI\LMS\Reports\EnrollmentReport;
use ODSI\LMS\Repositories\CertificateRepository;
use ODSI\LMS\Support\Meta;
use ODSI\Tests\Integration\TestCase;

final class HardeningTest extends TestCase {

	private EnrollmentReport $report;
	private Progress $progress;

	public function set_up(): void {
		parent::set_up();
		$this->report   = Plugin::instance()->container()->get( EnrollmentReport::class );
		$this->progress = Plugin::instance()->container()->get( Progress::class );
		Plugin::instance()->container()->get( Structure::class )->flush();
		wp_cache_flush();
	}

	public function test_adm_002_report_rows_and_summary(): void {
		$c = $this->lms->standard_course();
		$a = $this->lms->enrolled_learner( $c['course'] );
		$b = $this->lms->enrolled_learner( $c['course'] );
		$this->progress->complete_step( $a, $c['lesson1'] );
		$this->lms->enrollment()->repository()->set_status( $b, $c['course'], 'expired' );

		$result = $this->report->rows(
			$c['course'],
			array(
				'orderby' => 'percentage',
				'order' => 'DESC',
			)
		);
		self::assertSame( 2, $result['total'] );
		self::assertSame( $a, $result['rows'][0]['user_id'] );
		self::assertSame( 16.67, $result['rows'][0]['percentage'] );

		$active = $this->report->rows( $c['course'], array( 'status' => 'active' ) );
		self::assertSame( 1, $active['total'] );

		$search = $this->report->rows( $c['course'], array( 'search' => get_userdata( $b )->user_email ) );
		self::assertSame( array( $b ), array_column( $search['rows'], 'user_id' ) );

		$summary = $this->report->summary( $c['course'] );
		self::assertSame( 2, $summary['enrolled'] );
		self::assertSame( 1, $summary['active'] );
		self::assertSame( 1, $summary['expired'] );
	}

	public function test_adm_002_instructors_see_own_courses_only(): void {
		$me    = $this->lms->instructor();
		$other = $this->lms->instructor();
		$mine  = $this->lms->course( array( 'post_author' => $me ) );
		$their = $this->lms->course( array( 'post_author' => $other ) );

		self::assertSame( array( $mine ), $this->report->reportable_courses( $me ) );
		self::assertTrue( $this->report->can_report( $me, $mine ) );
		self::assertFalse( $this->report->can_report( $me, $their ) );
		self::assertFalse( $this->report->can_report( $this->lms->learner(), $mine ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::assertTrue( $this->report->can_report( $admin, $their ) );
	}

	public function test_enr_007_reset_wipes_attempts_and_reopens_completion(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );
		$quiz = Plugin::instance()->container()->get( QuizService::class );

		foreach ( array( 'lesson1', 'topic21', 'topic22' ) as $k ) {
			$this->progress->complete_step( $user, $c[ $k ] );
		}
		$quiz->submit( $quiz->start( $user, $c['quiz2'] ), array( $c['question'] => 0 ) );
		$this->progress->complete_step( $user, $c['lesson3'] );

		self::assertSame( 'completed', $this->lms->enrollment()->repository()->find_for( $user, $c['course'] )->status );
		self::assertCount( 1, $quiz->repository()->attempts_for( $user, $c['quiz2'] ) );

		$this->lms->enrollment()->reset_progress( $user, $c['course'] );

		self::assertSame( 0.0, $this->progress->course_percentage( $user, $c['course'] ) );
		self::assertCount( 0, $quiz->repository()->attempts_for( $user, $c['quiz2'] ), 'LMS-ENR-007: attempts wiped.' );
		$row = $this->lms->enrollment()->repository()->find_for( $user, $c['course'] );
		self::assertSame( 'active', $row->status );
		self::assertNull( $row->completed_at );
	}

	public function test_adm_004_grading_queue(): void {
		$course = $this->lms->course( array( 'meta' => array( Meta::LINEAR_PROGRESSION => 0 ) ) );
		$quiz   = $this->lms->quiz( $course, 0 );
		$essay  = $this->lms->question( $quiz, 'essay', array(), 3 );
		$user   = $this->lms->enrolled_learner( $course );
		$svc    = Plugin::instance()->container()->get( QuizService::class );

		$attempt = $svc->start( $user, $quiz );
		$svc->submit( $attempt, array( $essay => 'My essay.' ) );

		$queue = $this->report->grading_queue();
		self::assertSame( 1, $queue['total'] );
		self::assertSame( $essay, (int) $queue['rows'][0]->question_id );
		self::assertSame( 0, $this->report->grading_queue( array( 999999 ) )['total'], 'Restricted to given courses.' );

		$svc->grade_answer( $attempt, $essay, 3.0 );
		self::assertSame( 0, $this->report->grading_queue()['total'] );
	}

	public function test_certificates_issue_once_verify_and_render(): void {
		$template = self::factory()->post->create(
			array(
				'post_type' => PostTypes::CERTIFICATE,
				'post_status' => 'publish',
				'post_title' => 'Certificate of Completion',
				'post_content' => 'Awarded to {name} for {course} on {date}. Code {code}.',
			)
		);
		$c        = $this->lms->standard_course( array( 'meta' => array( Meta::CERTIFICATE_ID => $template ) ) );
		$user     = $this->lms->enrolled_learner( $c['course'] );
		$certs    = Plugin::instance()->container()->get( Certificates::class );
		$quiz     = Plugin::instance()->container()->get( QuizService::class );

		$issued = array();
		add_action(
			'odsi_lms_certificate_issued',
			static function ( int $u, int $co, string $code ) use ( &$issued ): void {
				$issued[] = $code;
			},
			10,
			3
		);

		self::assertNull( Plugin::instance()->container()->get( CertificateRepository::class )->find_for( $user, $c['course'] ) );

		foreach ( array( 'lesson1', 'topic21', 'topic22' ) as $k ) {
			$this->progress->complete_step( $user, $c[ $k ] );
		}
		$quiz->submit( $quiz->start( $user, $c['quiz2'] ), array( $c['question'] => 0 ) );
		$this->progress->complete_step( $user, $c['lesson3'] );

		self::assertCount( 1, $issued );
		$code = $issued[0];
		self::assertMatchesRegularExpression( '/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code );

		self::assertSame( $code, $certs->issue( $user, $c['course'] ), 'Idempotent.' );
		self::assertCount( 1, $issued );

		$verified = $certs->verify( strtolower( $code ) );
		self::assertNotNull( $verified );
		self::assertSame( get_userdata( $user )->display_name, $verified['name'] );
		self::assertNull( $certs->verify( 'NOPE-NOPE-NOPE' ) );

		$row  = Plugin::instance()->container()->get( CertificateRepository::class )->find_by_code( $code );
		$html = $certs->render( $row );
		self::assertStringContainsString( get_userdata( $user )->display_name, $html );
		self::assertStringContainsString( $code, $html );
		self::assertStringNotContainsString( '{name}', $html );

		Plugin::instance()->container()->get( CertificateRepository::class )->revoke( (int) $row->id );
		self::assertNull( $certs->verify( $code ), 'Revoked codes do not verify.' );
	}

	public function test_course_without_certificate_issues_nothing(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );

		self::assertSame( '', Plugin::instance()->container()->get( Certificates::class )->issue( $user, $c['course'] ) );
	}

	public function test_enr_012_cohorts_grant_and_revoke_precisely(): void {
		$cohorts = Plugin::instance()->container()->get( Cohorts::class );
		$c1      = $this->lms->course();
		$c2      = $this->lms->course();
		$cohort  = self::factory()->post->create(
			array(
				'post_type' => PostTypes::COHORT,
				'post_status' => 'publish',
			)
		);
		$user    = $this->lms->learner();
		$self    = $this->lms->learner();
		$repo    = $this->lms->enrollment()->repository();

		$this->lms->enrollment()->enroll( $self, $c1, array( 'source' => 'self' ) );

		$cohorts->set_courses( $cohort, array( $c1, $c2 ) );
		self::assertTrue( $cohorts->add_member( $cohort, $user ) );
		self::assertTrue( $cohorts->add_member( $cohort, $self ) );

		self::assertSame( 'cohort', $repo->find_for( $user, $c1 )->source );
		self::assertSame( $cohort, (int) $repo->find_for( $user, $c1 )->source_id );
		self::assertTrue( $this->lms->enrollment()->is_enrolled( $user, $c2 ) );
		self::assertSame( 'self', $repo->find_for( $self, $c1 )->source, 'Existing enrollment of another source untouched.' );

		$this->progress->touch_step( $user, $this->lms->lesson( $c1 ), 10 );

		self::assertTrue( $cohorts->remove_member( $cohort, $user ) );
		self::assertSame( 'cancelled', $repo->find_for( $user, $c1 )->status );
		self::assertSame( 'cancelled', $repo->find_for( $user, $c2 )->status );
		self::assertNotEmpty( $this->progress->repository()->completed_ids( $user, $c1 ) + array( 'kept' ), 'Progress rows retained.' );

		self::assertTrue( $cohorts->remove_member( $cohort, $self ) );
		self::assertSame( 'active', $repo->find_for( $self, $c1 )->status, 'Only cohort-sourced enrollments are cancelled.' );
		self::assertSame( 'cancelled', $repo->find_for( $self, $c2 )->status );

		$cohorts->add_member( $cohort, $user );
		self::assertSame( 'active', $repo->find_for( $user, $c1 )->status, 'Re-adding reactivates.' );

		$cohorts->set_courses( $cohort, array( $c1 ) );
		self::assertSame( 'cancelled', $repo->find_for( $user, $c2 )->status, 'Removing a course cancels its cohort enrollments.' );

		wp_delete_post( $cohort, true );
		self::assertSame( 'cancelled', $repo->find_for( $user, $c1 )->status, 'Deleting the cohort cancels.' );
	}

	public function test_outline_object_cache_is_used_and_invalidated(): void {
		$c         = $this->lms->standard_course();
		$structure = Plugin::instance()->container()->get( Structure::class );

		$structure->outline( $c['course'] );
		self::assertIsArray( wp_cache_get( "outline_{$c['course']}", 'odsi_lms' ), 'Stored after computing.' );

		$structure->flush();
		global $wpdb;
		$before = $wpdb->num_queries;
		$structure->outline( $c['course'] );
		self::assertSame( $before, $wpdb->num_queries, 'Served from cache with no queries.' );

		$new = $this->lms->lesson( $c['course'], array( 'menu_order' => 9 ) );
		self::assertContains( $new, $structure->step_ids( $c['course'] ), 'Invalidated on save.' );
	}

	public function test_adm_006_csv_export_pages_through_and_neutralises_formulas(): void {
		$c = $this->lms->standard_course();
		$a = $this->lms->enrolled_learner( $c['course'] );
		$b = $this->lms->enrolled_learner( $c['course'] );
		wp_update_user(
			array(
				'ID'           => $b,
				'display_name' => '=HYPERLINK("https://evil.example")',
			)
		);
		$this->progress->complete_step( $a, $c['lesson1'] );

		add_filter( 'odsi_lms_report_csv_columns', static fn ( array $columns ): array => $columns + array( 'source' => 'Source' ) );

		$handle = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$count  = $this->report->export_csv( $c['course'], '', $handle );
		rewind( $handle );
		$lines = array_map( 'str_getcsv', array_filter( explode( "\n", (string) stream_get_contents( $handle ) ) ) );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		self::assertSame( 2, $count );
		self::assertCount( 3, $lines );
		self::assertSame( 'user_id', $lines[0][0] );
		self::assertSame( 'Progress %', $lines[0][8] );
		$by_user = array_column( array_slice( $lines, 1 ), null, 0 );
		self::assertEqualsCanonicalizing( array( $a, $b ), array_keys( $by_user ) );
		self::assertSame( '16.67', $by_user[ $a ][8] );
		self::assertSame( "'=HYPERLINK(\"https://evil.example\")", $by_user[ $b ][1], 'Formula characters are neutralised.' );

		$handle = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		self::assertSame( 0, $this->report->export_csv( $c['course'], 'completed', $handle ) );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}
