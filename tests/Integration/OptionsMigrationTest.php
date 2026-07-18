<?php
/**
 * DB-backed integration test proving Options::maybe_migrate() (hooked on
 * plugins_loaded via Plugin::boot(), see docs/DECISIONS.md [D5]) actually
 * rewrites a stored v1 option against a real WP option row - this boot-time
 * migration machinery has never had a real migration to exercise before
 * the schema_version 1 -> 2 bump (disable_umami_auto_track removal).
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

use StatsUmami\Settings\Options;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * @covers \StatsUmami\Settings\Options
 */
final class OptionsMigrationTest extends TestCase {

	public function set_up() {
		parent::set_up();

		delete_option( Options::OPTION_KEY );
	}

	/**
	 * A real v1 option row (schema_version 1, disable_umami_auto_track
	 * still present) must be rewritten in place: schema_version bumped to
	 * the current SCHEMA_VERSION, disable_umami_auto_track gone, and every
	 * unrelated stored value left exactly as it was.
	 */
	public function test_maybe_migrate_rewrites_a_stored_v1_option() {
		$v1_stored = array_merge(
			Options::defaults(),
			array(
				'schema_version'           => 1,
				'disable_umami_auto_track' => true,
				'host_url'                 => 'https://analytics.example.com',
				'enabled'                  => true,
			)
		);

		update_option( Options::OPTION_KEY, $v1_stored );

		Options::maybe_migrate();

		$stored = get_option( Options::OPTION_KEY );

		$this->assertSame( Options::SCHEMA_VERSION, $stored['schema_version'] );
		$this->assertArrayNotHasKey( 'disable_umami_auto_track', $stored );
		$this->assertSame( 'https://analytics.example.com', $stored['host_url'] );
		$this->assertTrue( $stored['enabled'] );
	}

	/**
	 * A real option row already at the current schema is left byte-for-byte
	 * unchanged - maybe_migrate() must be a cheap no-op, not an
	 * unconditional rewrite, since this runs on every single boot.
	 */
	public function test_maybe_migrate_is_a_noop_on_a_real_option_already_current() {
		$current = array_merge( Options::defaults(), array( 'schema_version' => Options::SCHEMA_VERSION ) );

		update_option( Options::OPTION_KEY, $current );

		Options::maybe_migrate();

		$this->assertSame( $current, get_option( Options::OPTION_KEY ) );
	}
}
