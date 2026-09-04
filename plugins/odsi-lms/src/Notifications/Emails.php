<?php
/**
 * Learner emails.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Notifications;

use ODSI\LMS\Certificates\Certificates;
use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Repositories\CertificateRepository;
use ODSI\LMS\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text emails on the three moments a learner cares about: being
 * enrolled, finishing a course, and hearing back on an assignment
 * (LMS-ADM-007). Everything passes through `odsi_lms_email` so a site can
 * reword or suppress any of them.
 */
final class Emails implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param Settings              $settings     Settings.
	 * @param CertificateRepository $certificates Issued certificates.
	 * @param Certificates          $service      Certificate URLs.
	 */
	public function __construct(
		private Settings $settings,
		private CertificateRepository $certificates,
		private Certificates $service
	) {
	}

	/**
	 * Register hooks. Completion runs after Certificates (10) has issued.
	 */
	public function boot(): void {
		add_action( 'odsi_lms_user_enrolled', array( $this, 'on_enrolled' ), 10, 4 );
		add_action( 'odsi_lms_course_completed', array( $this, 'on_completed' ), 20, 2 );
		add_action( 'odsi_lms_submission_graded', array( $this, 'on_submission_graded' ), 10, 5 );
	}

	/**
	 * Whether emails go out at all.
	 */
	public function enabled(): bool {
		/**
		 * Filters whether the LMS sends learner emails.
		 *
		 * @param bool $enabled Setting value.
		 */
		return (bool) apply_filters( 'odsi_lms_emails_enabled', $this->settings->bool( 'email_notifications' ) );
	}

	/**
	 * Enrollment confirmation. Open-course auto-enrollments are silent.
	 *
	 * @param int                  $user_id       Learner.
	 * @param int                  $course_id     Course.
	 * @param int                  $enrollment_id Row.
	 * @param array<string, mixed> $args          Enrollment args.
	 */
	public function on_enrolled( int $user_id, int $course_id, int $enrollment_id, array $args ): void {
		if ( 'open' === (string) ( $args['source'] ?? '' ) ) {
			return;
		}

		$title = (string) get_the_title( $course_id );

		$this->send(
			'enrolled',
			$user_id,
			/* translators: %s: course title. */
			sprintf( __( 'You are enrolled on %s', 'odsi-lms' ), $title ),
			sprintf(
				/* translators: 1: course title, 2: course URL. */
				__( "You now have access to %1\$s.\n\nStart here: %2\$s", 'odsi-lms' ),
				$title,
				(string) get_permalink( $course_id )
			),
			array( 'course_id' => $course_id )
		);
	}

	/**
	 * Completion, with the certificate link when one was issued.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 */
	public function on_completed( int $user_id, int $course_id ): void {
		$title       = (string) get_the_title( $course_id );
		$certificate = $this->certificates->find_for( $user_id, $course_id );
		$body        = sprintf(
			/* translators: %s: course title. */
			__( 'Congratulations, you have completed %s.', 'odsi-lms' ),
			$title
		);

		if ( $certificate && empty( $certificate->revoked_at ) ) {
			$body .= "\n\n" . sprintf(
				/* translators: %s: certificate URL. */
				__( 'Your certificate: %s', 'odsi-lms' ),
				$this->service->url( (string) $certificate->code )
			);
		}

		$this->send(
			'completed',
			$user_id,
			/* translators: %s: course title. */
			sprintf( __( 'You completed %s', 'odsi-lms' ), $title ),
			$body,
			array( 'course_id' => $course_id )
		);
	}

	/**
	 * Assignment approved or returned.
	 *
	 * @param int    $id        Submission.
	 * @param string $status    approved or rejected.
	 * @param int    $user_id   Learner.
	 * @param int    $step_id   Step.
	 * @param int    $course_id Course.
	 */
	public function on_submission_graded( int $id, string $status, int $user_id, int $step_id, int $course_id ): void {
		$title = (string) get_the_title( $step_id );

		$this->send(
			'submission_' . $status,
			$user_id,
			'approved' === $status
				/* translators: %s: step title. */
				? sprintf( __( 'Your assignment for %s was approved', 'odsi-lms' ), $title )
				/* translators: %s: step title. */
				: sprintf( __( 'Your assignment for %s needs another look', 'odsi-lms' ), $title ),
			sprintf(
				/* translators: %s: step URL. */
				__( 'See the feedback and your result here: %s', 'odsi-lms' ),
				(string) get_permalink( $step_id )
			),
			array(
				'submission_id' => $id,
				'course_id'     => $course_id,
			)
		);
	}

	/**
	 * Send one email unless disabled or filtered away.
	 *
	 * @param string               $kind    Email kind.
	 * @param int                  $user_id Recipient.
	 * @param string               $subject Subject.
	 * @param string               $body    Plain-text body.
	 * @param array<string, mixed> $context Extra context for the filter.
	 */
	private function send( string $kind, int $user_id, string $subject, string $body, array $context ): void {
		$user = get_userdata( $user_id );

		if ( ! $user || ! $this->enabled() ) {
			return;
		}

		/**
		 * Filters an outgoing learner email. Return an empty array to suppress it.
		 *
		 * @param array<string, mixed> $email   `to`, `subject`, `body`, `headers`.
		 * @param string               $kind    enrolled, completed, submission_approved, submission_rejected.
		 * @param int                  $user_id Recipient.
		 * @param array<string, mixed> $context Ids relevant to the email.
		 */
		$email = (array) apply_filters(
			'odsi_lms_email',
			array(
				'to'      => $user->user_email,
				'subject' => $subject,
				'body'    => $body,
				'headers' => array(),
			),
			$kind,
			$user_id,
			$context
		);

		if ( array() === $email || empty( $email['to'] ) ) {
			return;
		}

		wp_mail( (string) $email['to'], (string) $email['subject'], (string) $email['body'], (array) ( $email['headers'] ?? array() ) );
	}
}
