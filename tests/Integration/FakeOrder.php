<?php
/**
 * Minimal stand-in for WC_Order, used by WooCommerceIntegrationTest -
 * maybe_track() only ever calls get_meta()/get_items()/get_total()/
 * get_currency()/update_meta_data()/save() on the order it loads via
 * wc_get_order(). WooCommerce isn't installed in the integration bootstrap,
 * so its real WC_Order/order-CRUD/data-store machinery can't be used; this
 * extends the empty global WC_Order stub (wc-order-stub.php) so
 * Integrations\WooCommerce's `instanceof \WC_Order` guard still holds.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

require_once __DIR__ . '/wc-order-stub.php';

/**
 * Duck-types the WC_Order methods Integrations\WooCommerce calls, recording
 * whether save() was called so tests can assert on it.
 */
final class FakeOrder extends \WC_Order {

	/**
	 * @var array<string, string>
	 */
	private $meta;

	/**
	 * @var float
	 */
	private $total;

	/**
	 * @var string
	 */
	private $currency;

	/**
	 * @var array<int, FakeOrderItem>
	 */
	private $items;

	/**
	 * @var bool
	 */
	public $saved = false;

	/**
	 * @var bool
	 */
	private $paid;

	/**
	 * @param float                     $total    Order total.
	 * @param string                    $currency ISO 4217 currency code.
	 * @param array<int, FakeOrderItem> $items    Line items.
	 * @param array<string, string>     $meta     Pre-seeded order meta (e.g. an already-set tracked flag).
	 * @param bool                      $paid     Whether is_paid() should report true (default: true - most
	 *                                            existing test scenarios exercise an already-paid order;
	 *                                            tests for the paid-only gate pass false explicitly).
	 */
	public function __construct( $total, $currency, array $items = array(), array $meta = array(), $paid = true ) {
		$this->total    = $total;
		$this->currency = $currency;
		$this->items    = $items;
		$this->meta     = $meta;
		$this->paid     = $paid;
	}

	/**
	 * Duck-types WC_Order::is_paid() - Integrations\WooCommerce::maybe_track()
	 * bails on a non-paid order (DECISIONS 2026-07-04 "WooCommerce
	 * purchase event fires on PAID statuses only") before ever reading/writing
	 * meta, so this double just returns whatever the test configured.
	 *
	 * @return bool
	 */
	public function is_paid() {
		return $this->paid;
	}

	/**
	 * Test-only mutator simulating a real order's status changing between
	 * two "visits" of the order-received page (e.g. on-hold -> processing,
	 * or processing -> refunded) - see WooCommerceIntegrationTest's order-status
	 * lifecycle tests. The real WC_Order derives is_paid() from
	 * get_status(); this double models only the derived boolean, which is
	 * all Integrations\WooCommerce ever reads.
	 *
	 * @param bool $paid New is_paid() return value.
	 */
	public function set_paid( $paid ) {
		$this->paid = $paid;
	}

	/**
	 * @param string $key Meta key.
	 * @param bool   $single Unused - always returns a single value, matching this test double's needs.
	 * @param string $context Unused.
	 * @return string
	 */
	public function get_meta( $key = '', $single = true, $context = 'view' ) {
		unset( $single, $context );

		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	/**
	 * @param string $key     Meta key.
	 * @param mixed  $value   Meta value.
	 * @param int    $meta_id Unused.
	 */
	public function update_meta_data( $key, $value, $meta_id = 0 ) {
		unset( $meta_id );

		$this->meta[ $key ] = $value;
	}

	/**
	 * @return int
	 */
	public function save() {
		$this->saved = true;

		return 0;
	}

	/**
	 * @param string $context Unused.
	 * @return float
	 */
	public function get_total( $context = 'view' ) {
		unset( $context );

		return $this->total;
	}

	/**
	 * @param string $context Unused.
	 * @return string
	 */
	public function get_currency( $context = 'view' ) {
		unset( $context );

		return $this->currency;
	}

	/**
	 * @param string $types Unused.
	 * @return array<int, FakeOrderItem>
	 */
	public function get_items( $types = 'line_item' ) {
		unset( $types );

		return $this->items;
	}

	/**
	 * Read-only accessor so tests can assert on the final meta state without
	 * a getter that doesn't exist on the real WC_Order (get_meta() requires a
	 * key).
	 *
	 * @return array<string, string>
	 */
	public function all_meta() {
		return $this->meta;
	}
}
