<?php
/**
 * Settings-page notices: the settings-saved success notice and any
 * validation errors registered via add_settings_error().
 *
 * @package StatsUmami
 */

namespace StatsUmami\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the Settings API's own error/notice queue. Errors are
 * registered elsewhere (e.g. SettingsPage::sanitize() on an invalid website
 * ID); WordPress core itself registers the generic "Settings saved." success
 * message whenever a submission produced no errors. This class only renders
 * whatever is already queued - it never adds messages of its own.
 */
class Notices {

	/**
	 * Render the settings-errors notice area. Call once, near the top of
	 * the settings page, after the tabs.
	 */
	public static function render() {
		settings_errors();
	}
}
