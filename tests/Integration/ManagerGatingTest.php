<?php
/**
 * Consolidated DB-backed integration test closing the TRACEABILITY
 * `integ-gating` row (OLD-PLUGIN-INVENTORY §12 defect #4): with all five
 * integrations' dependency predicates stubbed present, proves in ONE place
 * that Integrations\Manager::register() (a) registers none of the five when
 * the master `enabled` switch is off, (b) registers all five when master +
 * every per-toggle are on, and (c) each per-toggle off independently removes
 * only its own integration's hooks while the other four stay registered.
 *
 * This does not re-prove the per-integration injection behaviour itself
 * (covered by GutenbergIntegrationTest/ContactForm7IntegrationTest/
 * WPFormsIntegrationTest/WooCommerceIntegrationTest/ElementorIntegrationTest
 * in 3.6-3.8 and the 1.1.0 Elementor feature round), nor the "dependency
 * currently absent" half of the gate (each of those files already proves its
 * own integration stays unregistered while its class_exists()/defined()
 * predicate is false) - it only asserts hook presence/absence via
 * has_action()/has_filter(), matching the style those files already use.
 *
 * ORDERING NOTE: ContactForm7IntegrationTest / WPFormsIntegrationTest /
 * WooCommerceIntegrationTest / ElementorIntegrationTest each have their own
 * gating test that requires WPCF7_VERSION/WPForms/WooCommerce/
 * ELEMENTOR_VERSION to be genuinely undefined at one point mid-test - and PHP
 * can't undefine a constant or class once declared. This file deliberately
 * stubs all four dependencies PRESENT, so it MUST run after those four tests
 * in the same PHPUnit process, or it would corrupt their "still undefined"
 * assertions. phpunit-integration.xml.dist enforces this via a separate,
 * later-declared <testsuite> (see that file's comment).
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

use StatsUmami\Integrations\ContactForm7;
use StatsUmami\Integrations\Elementor;
use StatsUmami\Integrations\Gutenberg;
use StatsUmami\Integrations\Manager;
use StatsUmami\Integrations\WooCommerce;
use StatsUmami\Integrations\WPForms;
use StatsUmami\Settings\Options;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * @covers \StatsUmami\Integrations\Manager
 */
final class ManagerGatingTest extends TestCase {

	/**
	 * The real hook each integration registers, as (type, hook, callback)
	 * triples - matching exactly what Manager::register() wires and what the
	 * per-integration tests already assert on. Type picks has_action() vs
	 * has_filter() for the check (WordPress stores both in the same
	 * registry, so either would technically work, but using the matching
	 * one keeps the assertion readable).
	 *
	 * @var array<string, array<int, array{0:string,1:string,2:array{0:string,1:string}}>>
	 */
	private const INTEGRATION_HOOKS = array(
		'enable_gutenberg'   => array(
			array( 'filter', 'render_block', array( Gutenberg::class, 'inject_event_attributes' ) ),
		),
		'enable_cf7'         => array(
			array( 'filter', 'do_shortcode_tag', array( ContactForm7::class, 'inject_attributes' ) ),
		),
		'enable_wpforms'     => array(
			array( 'filter', 'the_content', array( WPForms::class, 'inject_in_content' ) ),
		),
		'enable_woocommerce' => array(
			array( 'action', 'woocommerce_thankyou', array( WooCommerce::class, 'maybe_track' ) ),
			array( 'action', 'wp_footer', array( WooCommerce::class, 'print_pending_event' ) ),
		),
		'enable_elementor'   => array(
			array( 'action', 'elementor/frontend/before_render', array( Elementor::class, 'mark_button_widget' ) ),
		),
	);

	public function set_up() {
		parent::set_up();

		delete_option( Options::OPTION_KEY );

		// Stub all four dependency predicates PRESENT, exactly as the
		// per-integration gating tests do in their own final step - but
		// deferred to set_up() (test-execution time), NOT file scope, so
		// merely discovering/loading this file can't fire these side
		// effects before ContactForm7IntegrationTest/WPFormsIntegrationTest/
		// WooCommerceIntegrationTest/ElementorIntegrationTest's own "still
		// undefined" assertions run (see the ORDERING NOTE above +
		// phpunit-integration.xml.dist).
		require_once __DIR__ . '/wpforms-class-stub.php';
		require_once __DIR__ . '/woocommerce-class-stub.php';

		if ( ! defined( 'WPCF7_VERSION' ) ) {
			define( 'WPCF7_VERSION', '5.9' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulating Contact Form 7's own real constant, exactly as the per-integration gating tests already do.
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.1.4' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulating Elementor's own real constant, exactly as the per-integration gating tests already do.
		}
	}

	/**
	 * A fully-configured, all-four-integrations-enabled options array, with
	 * the given overrides layered on top.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function all_enabled_options( array $overrides = array() ) {
		$options                       = Options::defaults();
		$options['enabled']            = true;
		$options['enable_gutenberg']   = true;
		$options['enable_cf7']         = true;
		$options['enable_wpforms']     = true;
		$options['enable_woocommerce'] = true;
		$options['enable_elementor']   = true;
		$options['schema_version']     = Options::SCHEMA_VERSION;

		return array_merge( $options, $overrides );
	}

	/**
	 * Assert every (hook, callback) pair for one integration's toggle key is
	 * registered (or not).
	 *
	 * @param string $toggle   Toggle key into self::INTEGRATION_HOOKS.
	 * @param bool   $expected True to assert registered, false to assert absent.
	 */
	private function assert_integration_registered( $toggle, $expected ) {
		foreach ( self::INTEGRATION_HOOKS[ $toggle ] as list( $type, $hook, $callback ) ) {
			$is_registered = 'action' === $type
				? false !== has_action( $hook, $callback )
				: false !== has_filter( $hook, $callback );

			$this->assertSame(
				$expected,
				$is_registered,
				sprintf( 'Expected %s hook "%s" registration to be %s.', $toggle, $hook, $expected ? 'true' : 'false' )
			);
		}
	}

	public function test_master_switch_off_registers_none_of_the_five_integrations() {
		Options::update( $this->all_enabled_options( array( 'enabled' => false ) ) );

		Manager::register();

		foreach ( array_keys( self::INTEGRATION_HOOKS ) as $toggle ) {
			$this->assert_integration_registered( $toggle, false );
		}
	}

	public function test_master_switch_on_with_all_toggles_on_registers_all_five_integrations() {
		Options::update( $this->all_enabled_options() );

		Manager::register();

		foreach ( array_keys( self::INTEGRATION_HOOKS ) as $toggle ) {
			$this->assert_integration_registered( $toggle, true );
		}
	}

	/**
	 * @dataProvider provide_toggle_keys
	 *
	 * @param string $toggle_under_test The single toggle to switch off.
	 */
	public function test_each_toggle_off_independently_removes_only_its_own_integration( $toggle_under_test ) {
		Options::update( $this->all_enabled_options( array( $toggle_under_test => false ) ) );

		Manager::register();

		foreach ( array_keys( self::INTEGRATION_HOOKS ) as $toggle ) {
			$this->assert_integration_registered( $toggle, $toggle !== $toggle_under_test );
		}
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function provide_toggle_keys() {
		return array(
			'gutenberg'   => array( 'enable_gutenberg' ),
			'cf7'         => array( 'enable_cf7' ),
			'wpforms'     => array( 'enable_wpforms' ),
			'woocommerce' => array( 'enable_woocommerce' ),
			'elementor'   => array( 'enable_elementor' ),
		);
	}
}
