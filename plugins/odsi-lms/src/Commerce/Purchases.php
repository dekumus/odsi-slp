<?php
/**
 * The purchase contract: how any commerce plugin sells a course.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Commerce;

defined( 'ABSPATH' ) || exit;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Courses\Enrollment;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Repositories\EnrollmentRepository;
use ODSI\LMS\Support\Meta;

/**
 * A commerce integration never touches the enrollment table. It fires
 * `odsi_lms_course_purchased` when a learner has paid and
 * `odsi_lms_course_refunded` when the money went back, and this service
 * turns those into enrollments with `source = purchase` (LMS-COM-001..003).
 * The bundled WooCommerce adapter is one such integration; a custom one
 * needs nothing more than those two actions.
 */
final class Purchases implements Bootable {

	public const SOURCE = 'purchase';

	/**
	 * Constructor.
	 *
	 * @param Enrollment $enrollment Enrollment service.
	 */
	public function __construct( private Enrollment $enrollment ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'odsi_lms_course_purchased', array( $this, 'on_purchased' ), 10, 3 );
		add_action( 'odsi_lms_course_refunded', array( $this, 'on_refunded' ), 10, 3 );
	}

	/**
	 * Listener for `odsi_lms_course_purchased`.
	 *
	 * @param int                  $user_id   Buyer.
	 * @param int                  $course_id Course.
	 * @param array<string, mixed> $context   `order_id`, `gateway`, anything the integration adds.
	 */
	public function on_purchased( int $user_id, int $course_id, array $context = array() ): void {
		$this->grant( $user_id, $course_id, $context );
	}

	/**
	 * Listener for `odsi_lms_course_refunded`.
	 *
	 * @param int                  $user_id   Buyer.
	 * @param int                  $course_id Course.
	 * @param array<string, mixed> $context   `order_id`, `gateway`.
	 */
	public function on_refunded( int $user_id, int $course_id, array $context = array() ): void {
		$this->revoke( $user_id, $course_id, $context );
	}

	/**
	 * Enroll a buyer. The course's access mode is not consulted: a paid order
	 * is an entitlement whatever the mode says today.
	 *
	 * @param int                  $user_id   Buyer.
	 * @param int                  $course_id Course.
	 * @param array<string, mixed> $context   Order context.
	 *
	 * @return int Enrollment id, 0 when nothing was granted.
	 */
	public function grant( int $user_id, int $course_id, array $context = array() ): int {
		if ( PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return 0;
		}

		$order_id = (int) ( $context['order_id'] ?? 0 );
		$existing = $this->enrollment->repository()->find_for( $user_id, $course_id );

		// A learner already on the course by another route keeps that row; the
		// purchase is recorded through the action below so the integration can
		// still reconcile it.
		$id = $this->enrollment->enroll(
			$user_id,
			$course_id,
			array(
				'source'    => self::SOURCE,
				'source_id' => $order_id,
			)
		);

		// Buying again while a purchased enrollment is still active renews the
		// access window from today (LMS-COM-002).
		if ( $id > 0 && $existing && self::SOURCE === (string) $existing->source && EnrollmentRepository::STATUS_ACTIVE === (string) $existing->status ) {
			$days = (int) get_post_meta( $course_id, Meta::ACCESS_DAYS, true );

			if ( $days > 0 ) {
				$this->enrollment->repository()->set_expiry( $user_id, $course_id, gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) ) );
			}
		}

		if ( $id > 0 ) {
			/**
			 * Fires once a purchase has been turned into an enrollment.
			 *
			 * @param int                  $user_id       Buyer.
			 * @param int                  $course_id     Course.
			 * @param int                  $enrollment_id Enrollment row.
			 * @param array<string, mixed> $context       Order context.
			 * @param bool                 $was_enrolled  Whether a row already existed.
			 */
			do_action( 'odsi_lms_purchase_granted', $user_id, $course_id, $id, $context, null !== $existing );
		}

		return $id;
	}

	/**
	 * Take a purchase back. Only an enrollment that came from a purchase is
	 * removed, and when the refund names an order only that order's; a row an
	 * administrator or a cohort created is never touched by a refund
	 * (LMS-COM-003). Progress is kept, so a later purchase resumes.
	 *
	 * @param int                  $user_id   Buyer.
	 * @param int                  $course_id Course.
	 * @param array<string, mixed> $context   Order context.
	 *
	 * @return bool Whether an enrollment was removed.
	 */
	public function revoke( int $user_id, int $course_id, array $context = array() ): bool {
		$row = $this->enrollment->repository()->find_for( $user_id, $course_id );

		if ( ! $row || self::SOURCE !== (string) $row->source ) {
			return false;
		}

		$order_id = (int) ( $context['order_id'] ?? 0 );

		if ( $order_id > 0 && (int) $row->source_id > 0 && (int) $row->source_id !== $order_id ) {
			return false;
		}

		$removed = $this->enrollment->unenroll( $user_id, $course_id );

		if ( $removed ) {
			/**
			 * Fires once a refund has removed an enrollment.
			 *
			 * @param int                  $user_id   Buyer.
			 * @param int                  $course_id Course.
			 * @param array<string, mixed> $context   Order context.
			 */
			do_action( 'odsi_lms_purchase_revoked', $user_id, $course_id, $context );
		}

		return $removed;
	}
}
