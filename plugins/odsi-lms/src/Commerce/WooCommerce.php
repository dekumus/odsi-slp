<?php
/**
 * WooCommerce adapter for the purchase contract.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Commerce;

defined( 'ABSPATH' ) || exit;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Meta;
use WP_Post;
use WP_Query;

/**
 * A course names the WooCommerce product that sells it
 * (`_odsi_wc_product_id`). A paid or processing order containing that
 * product enrolls the buyer; a refunded or cancelled order removes the
 * purchase. Hooks are registered unconditionally (WooCommerce loads after
 * this plugin, alphabetically) and every handler checks that WooCommerce is
 * actually there, so the adapter is inert without it (LMS-COM-004).
 */
final class WooCommerce implements Bootable {

	/**
	 * Constructor.
	 *
	 * @param Purchases $purchases Purchase service.
	 */
	public function __construct( private Purchases $purchases ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		foreach ( array( 'processing', 'completed' ) as $status ) {
			add_action( "woocommerce_order_status_{$status}", array( $this, 'on_order_paid' ), 10, 1 );
		}

		foreach ( array( 'refunded', 'cancelled' ) as $status ) {
			add_action( "woocommerce_order_status_{$status}", array( $this, 'on_order_reversed' ), 10, 1 );
		}

		add_filter( 'odsi_lms_paid_enroll_markup', array( $this, 'enroll_markup' ), 10, 2 );
		add_action( 'odsi_lms_course_settings_box', array( $this, 'settings_field' ) );
		add_action( 'odsi_lms_course_settings_saved', array( $this, 'save_settings_field' ) );
	}

	/**
	 * Whether WooCommerce is loaded.
	 */
	public static function active(): bool {
		return function_exists( 'wc_get_order' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * An order was paid: enroll the buyer on every course its products sell.
	 *
	 * @param int $order_id Order id.
	 */
	public function on_order_paid( int $order_id ): void {
		foreach ( $this->order_courses( $order_id ) as $pair ) {
			$this->purchases->grant(
				$pair['user_id'],
				$pair['course_id'],
				array(
					'order_id'   => $order_id,
					'gateway'    => 'woocommerce',
					'product_id' => $pair['product_id'],
				)
			);
		}
	}

	/**
	 * An order was refunded or cancelled: take the purchases back.
	 *
	 * @param int $order_id Order id.
	 */
	public function on_order_reversed( int $order_id ): void {
		foreach ( $this->order_courses( $order_id ) as $pair ) {
			$this->purchases->revoke(
				$pair['user_id'],
				$pair['course_id'],
				array(
					'order_id'   => $order_id,
					'gateway'    => 'woocommerce',
					'product_id' => $pair['product_id'],
				)
			);
		}
	}

	/**
	 * Buy button in place of the enroll button on a course with a product.
	 *
	 * @param string $html      Current markup.
	 * @param int    $course_id Course.
	 */
	public function enroll_markup( string $html, int $course_id ): string {
		if ( ! self::active() ) {
			return $html;
		}

		$product_id = (int) get_post_meta( $course_id, Meta::WC_PRODUCT_ID, true );
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;

		if ( ! $product || ! $product->is_purchasable() ) {
			return $html;
		}

		$url = add_query_arg( 'add-to-cart', $product_id, wc_get_cart_url() );

		return sprintf(
			'<p class="odsi-lms-enroll__price">%1$s</p><a class="odsi-lms-button odsi-lms-enroll__buy" href="%2$s">%3$s</a>',
			wp_kses_post( $product->get_price_html() ),
			esc_url( $url ),
			esc_html__( 'Buy this course', 'odsi-lms' )
		);
	}

	/**
	 * The product field on the course settings box.
	 *
	 * @param WP_Post $post Course.
	 */
	public function settings_field( WP_Post $post ): void {
		if ( ! self::active() ) {
			return;
		}

		printf(
			'<p><label for="odsi-wc-product">%1$s</label><br /><input type="number" id="odsi-wc-product" name="%2$s" value="%3$d" min="0" step="1" class="small-text" /><br /><span class="description">%4$s</span></p>',
			esc_html__( 'WooCommerce product ID', 'odsi-lms' ),
			esc_attr( Meta::WC_PRODUCT_ID ),
			(int) get_post_meta( $post->ID, Meta::WC_PRODUCT_ID, true ),
			esc_html__( 'Buying this product enrolls the customer. Set the access mode to Paid.', 'odsi-lms' )
		);
	}

	/**
	 * Persist the product field (the box verified nonce and capability).
	 *
	 * @param int $post_id Course.
	 */
	public function save_settings_field( int $post_id ): void {
		if ( ! self::active() || ! isset( $_POST[ Meta::WC_PRODUCT_ID ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by SettingsMetaBoxes::save().
			return;
		}

		update_post_meta( $post_id, Meta::WC_PRODUCT_ID, absint( wp_unslash( $_POST[ Meta::WC_PRODUCT_ID ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Courses sold by a product.
	 *
	 * @param int $product_id Product id.
	 *
	 * @return int[]
	 */
	public function courses_for_product( int $product_id ): array {
		if ( $product_id <= 0 ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'              => PostTypes::COURSE,
				'post_status'            => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page'         => 100,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the lookup is by design keyed on meta; orders are rare.
					array(
						'key'   => Meta::WC_PRODUCT_ID,
						'value' => $product_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Every (buyer, course, product) an order entitles.
	 *
	 * @param int $order_id Order id.
	 *
	 * @return array<int, array{user_id: int, course_id: int, product_id: int}>
	 */
	private function order_courses( int $order_id ): array {
		if ( ! self::active() ) {
			return array();
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return array();
		}

		$user_id = (int) $order->get_user_id();

		// Guest checkout has nobody to enroll. The integration's own account
		// creation setting decides whether this ever happens.
		if ( $user_id <= 0 ) {
			return array();
		}

		$out = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}

			$product_id = (int) $item->get_product_id();

			foreach ( $this->courses_for_product( $product_id ) as $course_id ) {
				$out[] = array(
					'user_id'    => $user_id,
					'course_id'  => $course_id,
					'product_id' => $product_id,
				);
			}
		}

		return $out;
	}
}
