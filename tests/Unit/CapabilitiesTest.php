<?php
/**
 * Unit tests for StatsUmami\Support\Capabilities.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Unit;

use Brain\Monkey\Functions;
use StatsUmami\Support\Capabilities;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \StatsUmami\Support\Capabilities
 */
final class CapabilitiesTest extends TestCase {

	// ---------------------------------------------------------------
	// can_view(): pure role-intersection logic, no WP calls involved.
	// ---------------------------------------------------------------

	public function test_can_view_true_when_a_user_role_is_in_the_allowed_list() {
		$this->assertTrue( Capabilities::can_view( array( 'editor' ), array( 'editor', 'author' ) ) );
	}

	public function test_can_view_true_when_only_one_of_several_roles_matches() {
		$this->assertTrue( Capabilities::can_view( array( 'subscriber', 'author' ), array( 'author' ) ) );
	}

	public function test_can_view_false_when_no_role_matches() {
		$this->assertFalse( Capabilities::can_view( array( 'subscriber' ), array( 'editor', 'author' ) ) );
	}

	public function test_can_view_false_when_allowed_list_is_empty() {
		$this->assertFalse( Capabilities::can_view( array( 'editor' ), array() ) );
	}

	public function test_can_view_false_when_user_has_no_roles() {
		$this->assertFalse( Capabilities::can_view( array(), array( 'editor' ) ) );
	}

	// ---------------------------------------------------------------
	// current_user_can_view(): thin WP wrapper.
	// ---------------------------------------------------------------

	public function test_current_user_can_view_true_for_administrator_regardless_of_allowed_list() {
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertTrue( Capabilities::current_user_can_view( array() ) );
	}

	public function test_current_user_can_view_true_for_non_admin_with_an_allowed_role() {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'roles' => array( 'editor' ) ) );

		$this->assertTrue( Capabilities::current_user_can_view( array( 'editor' ) ) );
	}

	public function test_current_user_can_view_false_for_non_admin_without_an_allowed_role() {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'roles' => array( 'subscriber' ) ) );

		$this->assertFalse( Capabilities::current_user_can_view( array( 'editor', 'author' ) ) );
	}
}
