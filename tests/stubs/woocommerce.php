<?php
/**
 * Minimal WooCommerce signatures for static analysis. Not loaded at runtime.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

// phpcs:ignoreFile

interface WC_Order_Item_Product_Like {
	public function get_product_id(): int;
}

class WC_Order_Item {
}

class WC_Order_Item_Product extends WC_Order_Item {
	public function get_product_id(): int {
		return 0;
	}
}

class WC_Order {
	public function get_user_id(): int {
		return 0;
	}

	/**
	 * @return array<int, WC_Order_Item>
	 */
	public function get_items(): array {
		return array();
	}
}

class WC_Product {
	public function is_purchasable(): bool {
		return true;
	}

	public function get_price_html(): string {
		return '';
	}
}

/**
 * @return WC_Order|false
 */
function wc_get_order( int|string $order_id ) {
	return false;
}

/**
 * @return WC_Product|false
 */
function wc_get_product( int|string $product_id ) {
	return false;
}

function wc_get_cart_url(): string {
	return '';
}
