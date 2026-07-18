<?php
/**
 * Pins the version string to a single source of truth.
 * stats-umami.php states the version TWICE - the `Version:` plugin header
 * and the `STATS_UMAMI_VERSION` constant - with nothing enforcing agreement,
 * so a future release can bump one and display the other. A plain regex
 * read of the files is enough; no WP bootstrap is needed for this check.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class VersionConsistencyTest extends TestCase {

	public function test_plugin_header_constant_and_readme_stable_tag_all_agree() {
		$plugin_file = dirname( __DIR__, 2 ) . '/stats-umami.php';
		$readme_file = dirname( __DIR__, 2 ) . '/readme.txt';

		$plugin_source = file_get_contents( $plugin_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file read in a test, not a remote URL; no WP runtime is bootstrapped here for wp_remote_get()/the filesystem API.
		$readme_source = file_get_contents( $readme_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file read in a test, not a remote URL; no WP runtime is bootstrapped here for wp_remote_get()/the filesystem API.

		$this->assertNotFalse( $plugin_source, 'stats-umami.php must be readable.' );
		$this->assertNotFalse( $readme_source, 'readme.txt must be readable.' );

		$this->assertSame(
			1,
			preg_match( '/^\s*\*\s*Version:\s*([0-9][^\s]*)/mi', $plugin_source, $header_match ),
			'stats-umami.php must have a plugin-header "Version:" line.'
		);

		$this->assertSame(
			1,
			preg_match( '/define\(\s*\'STATS_UMAMI_VERSION\',\s*\'([^\']+)\'\s*\)/', $plugin_source, $constant_match ),
			'stats-umami.php must define the STATS_UMAMI_VERSION constant as a literal string.'
		);

		$this->assertSame(
			1,
			preg_match( '/^Stable tag:\s*([0-9][^\s]*)/mi', $readme_source, $readme_match ),
			'readme.txt must have a "Stable tag:" line.'
		);

		$header_version   = $header_match[1];
		$constant_version = $constant_match[1];
		$readme_version   = $readme_match[1];

		$this->assertSame(
			$header_version,
			$constant_version,
			'The plugin header "Version:" and the STATS_UMAMI_VERSION constant must agree.'
		);

		$this->assertSame(
			$header_version,
			$readme_version,
			'The plugin header "Version:" and readme.txt\'s "Stable tag:" must agree.'
		);
	}
}
