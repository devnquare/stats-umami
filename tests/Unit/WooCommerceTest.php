<?php
/**
 * Unit tests for StatsUmami\Integrations\WooCommerce's pure logic:
 * build_event_data()'s revenue/currency/product-info shaping from plain
 * scalar/array inputs. maybe_track() itself (order CRUD reads/writes, the
 * idempotency decision, the wp_add_inline_script() output) is exercised by
 * the DB-backed WooCommerceIntegrationTest instead.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Unit;

use StatsUmami\Integrations\WooCommerce;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \StatsUmami\Integrations\WooCommerce
 */
final class WooCommerceTest extends TestCase {

	public function test_builds_revenue_as_a_float_not_a_string() {
		$data = WooCommerce::build_event_data( '49.99', 'USD', array() );

		$this->assertSame( 49.99, $data['revenue'] );
		$this->assertIsFloat( $data['revenue'] );
	}

	public function test_builds_currency_as_a_string() {
		$data = WooCommerce::build_event_data( 10, 'EUR', array() );

		$this->assertSame( 'EUR', $data['currency'] );
	}

	public function test_empty_items_yield_zeroed_product_info() {
		$data = WooCommerce::build_event_data( 10, 'EUR', array() );

		$this->assertSame( 0, $data['product_count'] );
		$this->assertSame( 0, $data['quantity_total'] );
		$this->assertSame( '', $data['product_names'] );
	}

	public function test_aggregates_product_count_quantity_total_and_names_across_items() {
		$items = array(
			array(
				'name'     => 'Widget',
				'quantity' => 2,
			),
			array(
				'name'     => 'Gadget',
				'quantity' => 1,
			),
		);

		$data = WooCommerce::build_event_data( 29.5, 'GBP', $items );

		$this->assertSame( 2, $data['product_count'] );
		$this->assertSame( 3, $data['quantity_total'] );
		$this->assertSame( 'Widget, Gadget', $data['product_names'] );
	}

	public function test_ignores_items_with_a_blank_name_for_the_names_string_but_still_counts_the_line() {
		$items = array(
			array(
				'name'     => '',
				'quantity' => 1,
			),
			array(
				'name'     => 'Widget',
				'quantity' => 1,
			),
		);

		$data = WooCommerce::build_event_data( 10, 'EUR', $items );

		$this->assertSame( 2, $data['product_count'] );
		$this->assertSame( 2, $data['quantity_total'] );
		$this->assertSame( 'Widget', $data['product_names'] );
	}

	public function test_missing_quantity_defaults_to_zero() {
		$data = WooCommerce::build_event_data( 10, 'EUR', array( array( 'name' => 'Widget' ) ) );

		$this->assertSame( 0, $data['quantity_total'] );
	}
}
