<?php
/**
 * Minimal stand-in for WC_Order_Item(_Product), used by FakeOrder's
 * get_items() - Integrations\WooCommerce::maybe_track() only ever calls
 * get_name()/get_quantity() on each line item.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

/**
 * Duck-types the two WC_Order_Item methods Integrations\WooCommerce calls.
 */
final class FakeOrderItem {

	/**
	 * @var string
	 */
	private $name;

	/**
	 * @var int
	 */
	private $quantity;

	/**
	 * @param string $name     Product name.
	 * @param int    $quantity Line-item quantity.
	 */
	public function __construct( $name, $quantity ) {
		$this->name     = $name;
		$this->quantity = $quantity;
	}

	/**
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * @return int
	 */
	public function get_quantity() {
		return $this->quantity;
	}
}
