<?php
/**
 * Learner emails. Spec: LMS-ADM-007.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Assignments\Assignments;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\Plugin;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Meta;
use ODSI\LMS\Support\Settings;
use ODSI\Tests\Integration\TestCase;

final class EmailsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		Plugin::instance()->container()->get( Structure::class )->flush();
	}

	public function tear_down(): void {
		reset_phpmailer_instance();
		parent::tear_down();
	}

	/**
	 * Subjects of every email sent so far.
	 *
	 * @return string[]
	 */
	private function subjects(): array {
		return array_map( static fn ( array $m ): string => (string) $m['subject'], tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_enrollment_and_completion_emails(): void {
		$c    = $this->lms->standard_course( array( 'post_title' => 'Email course' ) );
		$user = $this->lms->learner();
		$this->lms->enrollment()->enroll( $user, $c['course'], array( 'source' => 'manual' ) );

		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		self::assertCount( 1, $sent );
		self::assertSame( get_userdata( $user )->user_email, $sent[0]['to'][0][0] );
		self::assertSame( 'You are enrolled on Email course', $sent[0]['subject'] );
		self::assertStringContainsString( get_permalink( $c['course'] ), $sent[0]['body'] );

		$this->lms->enrollment()->enroll( $user, $c['course'] );
		self::assertCount( 1, tests_retrieve_phpmailer_instance()->mock_sent, 'Re-enrolling an active learner sends nothing.' );

		$progress = Plugin::instance()->container()->get( Progress::class );

		foreach ( array( $c['lesson1'], $c['topic21'], $c['topic22'], $c['lesson3'] ) as $step ) {
			$progress->complete_step( $user, $step );
		}

		$progress->complete_quiz( $user, $c['quiz2'] );

		self::assertSame( array( 'You are enrolled on Email course', 'You completed Email course' ), $this->subjects() );
	}

	public function test_completion_email_links_the_certificate(): void {
		$template = $this->factory()->post->create( array( 'post_type' => PostTypes::CERTIFICATE ) );
		$c        = $this->lms->standard_course(
			array(
				'post_title' => 'Certified',
				'meta'       => array( Meta::CERTIFICATE_ID => $template ),
			)
		);
		$user     = $this->lms->enrolled_learner( $c['course'] );
		$progress = Plugin::instance()->container()->get( Progress::class );

		foreach ( array( $c['lesson1'], $c['topic21'], $c['topic22'], $c['lesson3'] ) as $step ) {
			$progress->complete_step( $user, $step );
		}

		$progress->complete_quiz( $user, $c['quiz2'] );

		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$last = end( $sent );
		self::assertSame( 'You completed Certified', $last['subject'] );
		self::assertStringContainsString( '/certificate/', $last['body'] );
	}

	public function test_open_courses_setting_and_filter_can_silence(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->learner();

		$this->lms->enrollment()->enroll( $user, $c['course'], array( 'source' => 'open' ) );
		self::assertSame( array(), $this->subjects(), 'Open-course auto enrollment is silent.' );

		Plugin::instance()->container()->get( Settings::class )->update( array( 'email_notifications' => false ) );
		$this->lms->enrollment()->enroll( $this->lms->learner(), $c['course'] );
		self::assertSame( array(), $this->subjects(), 'The setting turns emails off.' );
		Plugin::instance()->container()->get( Settings::class )->update( array( 'email_notifications' => true ) );

		add_filter( 'odsi_lms_email', static fn ( array $email, string $kind ): array => 'enrolled' === $kind ? array() : $email, 10, 2 );
		$this->lms->enrollment()->enroll( $this->lms->learner(), $c['course'] );
		self::assertSame( array(), $this->subjects(), 'The filter suppresses one kind.' );
	}

	public function test_assignment_decision_email(): void {
		$c = $this->lms->standard_course();
		update_post_meta( $c['lesson1'], Meta::ASSIGNMENT_REQUIRED, true );
		$user        = $this->lms->enrolled_learner( $c['course'] );
		$assignments = Plugin::instance()->container()->get( Assignments::class );
		$id          = $assignments->submit( $user, $c['lesson1'], 'work' );
		reset_phpmailer_instance();

		$assignments->reject( $id, 'More detail please', 1 );
		self::assertSame( array( 'Your assignment for Lesson 1 needs another look' ), $this->subjects() );
	}
}
