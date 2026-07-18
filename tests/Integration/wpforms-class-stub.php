<?php
/**
 * Minimal global-namespace WPForms class stub, required on-demand by
 * WPFormsIntegrationTest to drive Integrations\Manager's
 * class_exists('WPForms') dependency predicate to true - the phpunit
 * integration bootstrap does not install/load the real WPForms plugin.
 * Test-only: never required outside tests/.
 *
 * @package StatsUmami
 */

if ( ! class_exists( 'WPForms', false ) ) {
	class WPForms {
	}
}
