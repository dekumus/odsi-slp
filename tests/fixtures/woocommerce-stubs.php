<?php
/**
 * WooCommerce is not installed in the harness; the adapter only needs these
 * three functions, stubbed in the global namespace and driven by globals
 * the tests fill in.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * Test double for wc_get_order().
	 *
	 * @param int|string $order_id Order.
	 * @return object|false
	 */
	function wc_get_order( int|string $order_id ): object|false {
		return $GLOBALS['odsi_test_orders'][ (int) $order_id ] ?? false;
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * Test double for wc_get_product().
	 *
	 * @param int|string $product_id Product.
	 * @return object|false
	 */
	function wc_get_product( int|string $product_id ): object|false {
		return $GLOBALS['odsi_test_products'][ (int) $product_id ] ?? false;
	}
}

if ( ! function_exists( 'wc_get_cart_url' ) ) {
	/**
	 * Test double for wc_get_cart_url().
	 */
	function wc_get_cart_url(): string {
		return 'http://example.org/cart/';
	}
}
