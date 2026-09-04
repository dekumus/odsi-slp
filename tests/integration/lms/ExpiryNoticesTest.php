<?php
/**
 * LMS-ENR-015/016: warnings before access expires and notice when it has.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Courses\Maintenance;
use ODSI\LMS\Installer;
use ODSI\LMS\Plugin;
use ODSI\LMS\Repositories\EnrollmentRepository;
use ODSI\Tests\Integration\TestCase;

final class ExpiryNoticesTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		update_option( Installer::SETTINGS_OPTION, array( 'expiry_warning_days' => 7 ) );
	}

	public function tear_down(): void {
		reset_phpmailer_instance();
		parent::tear_down();
	}

	/**
	 * @return string[]
	 */
	private function subjects(): array {
		return array_map( static fn ( array $m ): string => (string) $m['subject'], tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_enr_015_a_learner_is_warned_once_per_expiry_date_inside_the_window(): void {
		$course      = $this->lms->course( array( 'post_title' => 'Expiring course' ) );
		$soon        = $this->lms->learner();
		$later       = $this->lms->learner();
		$enrollment  = $this->lms->enrollment();
		$maintenance = Plugin::instance()->container()->get( Maintenance::class );
		$events      = array();
		add_action(
			'odsi_lms_enrollment_expiring',
			static function ( int $u, int $c, string $at ) use ( &$events ): void {
				$events[] = array( $u, $c, $at );
			},
			10,
			3
		);

		$enrollment->enroll( $soon, $course, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ) ) );
		$enrollment->enroll( $later, $course, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ) );
		reset_phpmailer_instance();

		self::assertSame( 1, $maintenance->warn_expiring() );
		self::assertCount( 1, $events );
		self::assertSame( $soon, $events[0][0] );
		self::assertSame( array( 'Your access to Expiring course ends soon' ), $this->subjects() );

		self::assertSame( 0, $maintenance->warn_expiring(), 'The same expiry date is not announced twice.' );

		// Renewed access with a new date inside the window warns again.
		Plugin::instance()->container()->get( EnrollmentRepository::class )->set_expiry( $soon, $course, gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) );
		self::assertSame( 1, $maintenance->warn_expiring() );

		update_option( Installer::SETTINGS_OPTION, array( 'expiry_warning_days' => 0 ) );
		self::assertSame( 0, $maintenance->warn_expiring(), 'Zero disables the warning.' );
	}

	public function test_enr_016_expiry_emails_the_learner_and_the_daily_job_runs_both_steps(): void {
		$course      = $this->lms->course( array( 'post_title' => 'Lapsed course' ) );
		$user        = $this->lms->learner();
		$maintenance = Plugin::instance()->container()->get( Maintenance::class );

		$this->lms->enrollment()->enroll( $user, $course, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		reset_phpmailer_instance();

		$maintenance->run();

		self::assertSame( 'expired', $this->lms->enrollment()->repository()->find_for( $user, $course )->status );
		self::assertSame( array( 'Your access to Lapsed course has ended' ), $this->subjects() );

		$body = (string) tests_retrieve_phpmailer_instance()->mock_sent[0]['body'];
		self::assertStringContainsString( 'progress is kept', $body );

		update_option( Installer::SETTINGS_OPTION, array( 'email_notifications' => false ) );
		reset_phpmailer_instance();
		do_action( 'odsi_lms_enrollment_expired', $user, $course, 0 );
		self::assertSame( array(), $this->subjects(), 'The email switch silences expiry mail too.' );
	}
}
