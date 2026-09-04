<?php
/**
 * LMS-COM: the purchase contract and the WooCommerce adapter.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Commerce\Purchases;
use ODSI\LMS\Commerce\WooCommerce;
use ODSI\LMS\Plugin;
use ODSI\LMS\Repositories\EnrollmentRepository;
use ODSI\LMS\Support\Meta;
use ODSI\Tests\Integration\TestCase;

require_once dirname( __DIR__, 2 ) . '/fixtures/woocommerce-stubs.php';

final class CommerceTest extends TestCase {

	private Purchases $purchases;
	private EnrollmentRepository $enrollments;

	public function set_up(): void {
		parent::set_up();
		$this->purchases   = Plugin::instance()->container()->get( Purchases::class );
		$this->enrollments = Plugin::instance()->container()->get( EnrollmentRepository::class );
		$GLOBALS['odsi_test_orders']   = array();
		$GLOBALS['odsi_test_products'] = array();
	}

	public function test_com_001_a_purchase_enrolls_with_its_order_and_a_refund_removes_it(): void {
		$course = $this->lms->course( array( 'meta' => array( Meta::ACCESS_MODE => 'paid' ) ) );
		$user   = $this->lms->learner();
		$events = array();
		add_action(
			'odsi_lms_purchase_granted',
			static function ( int $u, int $c, int $id, array $ctx ) use ( &$events ): void {
				$events[] = array( 'granted', $u, $c, $ctx['order_id'] );
			},
			10,
			4
		);
		add_action(
			'odsi_lms_purchase_revoked',
			static function ( int $u, int $c ) use ( &$events ): void {
				$events[] = array( 'revoked', $u, $c );
			},
			10,
			2
		);

		do_action(
			'odsi_lms_course_purchased',
			$user,
			$course,
			array(
				'order_id' => 501,
				'gateway' => 'test',
			)
		);

		$row = $this->enrollments->find_for( $user, $course );
		self::assertSame( 'active', $row->status );
		self::assertSame( 'purchase', $row->source );
		self::assertSame( 501, (int) $row->source_id );
		self::assertTrue( Plugin::instance()->container()->get( \ODSI\LMS\Courses\Access::class )->can_access_course( $user, $course ), 'A buyer opens a paid course.' );

		do_action( 'odsi_lms_course_refunded', $user, $course, array( 'order_id' => 501 ) );
		self::assertNull( $this->enrollments->find_for( $user, $course ) );
		self::assertSame( array( array( 'granted', $user, $course, 501 ), array( 'revoked', $user, $course ) ), $events );
	}

	public function test_com_003_a_refund_never_touches_a_manual_or_other_order_enrollment(): void {
		$course = $this->lms->course();
		$user   = $this->lms->enrolled_learner( $course );

		self::assertFalse( $this->purchases->revoke( $user, $course, array( 'order_id' => 9 ) ) );
		self::assertSame( 'manual', $this->enrollments->find_for( $user, $course )->source );

		$buyer = $this->lms->learner();
		$this->purchases->grant( $buyer, $course, array( 'order_id' => 10 ) );
		self::assertFalse( $this->purchases->revoke( $buyer, $course, array( 'order_id' => 11 ) ), 'Another order\'s refund does not remove this purchase.' );
		self::assertTrue( $this->purchases->revoke( $buyer, $course, array( 'order_id' => 10 ) ) );
	}

	public function test_com_002_a_purchase_by_an_enrolled_learner_keeps_the_row_and_reports_it(): void {
		$course = $this->lms->course();
		$user   = $this->lms->enrolled_learner( $course );
		$seen   = null;
		add_action(
			'odsi_lms_purchase_granted',
			static function ( int $u, int $c, int $id, array $ctx, bool $was ) use ( &$seen ): void {
				$seen = $was;
			},
			10,
			5
		);

		$this->purchases->grant( $user, $course, array( 'order_id' => 77 ) );

		self::assertTrue( $seen );
		self::assertSame( 'manual', $this->enrollments->find_for( $user, $course )->source, 'The earlier row wins (LMS-ENR-002).' );
	}

	public function test_com_004_woocommerce_orders_enroll_and_reverse_through_the_product_link(): void {
		$course = $this->lms->course(
			array(
				'meta' => array(
					Meta::ACCESS_MODE => 'paid',
					Meta::WC_PRODUCT_ID => 4242,
				),
			)
		);
		$other  = $this->lms->course( array( 'meta' => array( Meta::WC_PRODUCT_ID => 4242 ) ) );
		$user   = $this->lms->learner();

		$GLOBALS['odsi_test_orders'][900] = new class( $user ) {
			public function __construct( private int $user ) {}
			public function get_user_id(): int {
				return $this->user; }
			public function get_items(): array {
				return array(
					new class() { public function get_product_id(): int {
							return 4242; } },
					new class() { public function get_product_id(): int {
							return 1; } },
				);
			}
		};
		$GLOBALS['odsi_test_orders'][901] = new class() {
			public function get_user_id(): int {
				return 0; }
			public function get_items(): array {
				return array(); }
		};

		do_action( 'woocommerce_order_status_completed', 900 );
		self::assertSame( 900, (int) $this->enrollments->find_for( $user, $course )->source_id );
		self::assertSame( 'purchase', $this->enrollments->find_for( $user, $other )->source, 'Every course sold by the product.' );

		do_action( 'woocommerce_order_status_completed', 901 );
		do_action( 'woocommerce_order_status_completed', 902 );

		do_action( 'woocommerce_order_status_refunded', 900 );
		self::assertNull( $this->enrollments->find_for( $user, $course ) );
		self::assertNull( $this->enrollments->find_for( $user, $other ) );

		$adapter = Plugin::instance()->container()->get( WooCommerce::class );
		self::assertSame( array(), $adapter->courses_for_product( 0 ) );
		self::assertEqualsCanonicalizing( array( $course, $other ), $adapter->courses_for_product( 4242 ) );
	}

	public function test_com_004_the_enroll_button_becomes_a_buy_button_for_a_purchasable_product(): void {
		$course = $this->lms->course(
			array(
				'meta' => array(
					Meta::ACCESS_MODE => 'paid',
					Meta::WC_PRODUCT_ID => 55,
				),
			)
		);
		$plain  = $this->lms->course( array( 'meta' => array( Meta::ACCESS_MODE => 'paid' ) ) );

		$GLOBALS['odsi_test_products'][55] = new class() {
			public function is_purchasable(): bool {
				return true; }
			public function get_price_html(): string {
				return '<span class="amount">$40</span>'; }
		};

		$user = $this->lms->learner();
		$html = $this->as_user( $user, static fn (): string => do_shortcode( '[odsi_enroll_button course_id="' . $course . '"]' ) );
		self::assertStringContainsString( 'add-to-cart=55', $html );
		self::assertStringContainsString( '$40', $html );
		self::assertStringContainsString( 'Buy this course', $html );

		$fallback = $this->as_user( $user, static fn (): string => do_shortcode( '[odsi_enroll_button course_id="' . $plain . '"]' ) );
		self::assertStringContainsString( 'requires a purchase', $fallback );
		self::assertStringNotContainsString( 'add-to-cart', $fallback );
	}
}
