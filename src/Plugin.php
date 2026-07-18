<?php
/**
 * Plugin orchestrator: wires up boot-time behaviour.
 *
 * @package StatsUmami
 */

namespace StatsUmami;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use StatsUmami\Admin\DashboardWidget;
use StatsUmami\Admin\SettingsPage;
use StatsUmami\Admin\SetupNotice;
use StatsUmami\Frontend\DeveloperApi;
use StatsUmami\Frontend\Tracker;
use StatsUmami\Integrations\Manager as IntegrationsManager;
use StatsUmami\Settings\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entry point hooked on plugins_loaded. Later phases add Admin/
 * Integrations wiring here.
 *
 * No load_plugin_textdomain() call here: the old plugin's missing call was
 * a real defect against a "Full i18n" claim (docs/research/OLD-PLUGIN-INVENTORY.md
 * §12), but the *correct* fix, confirmed by a live Plugin Check run, is to add
 * no call at all. WordPress core has auto-loaded translations JIT since 4.6
 * for any plugin whose Text Domain matches its WordPress.org slug (ours does:
 * `stats-umami`); an explicit call is redundant and Plugin Check flags it as
 * discouraged.
 */
class Plugin {

	/**
	 * Boot the plugin. Registered on the plugins_loaded action.
	 */
	public static function boot() {
		// Boot-time schema check, not activation-only: WordPress.org
		// auto-updates never re-fire register_activation_hook, so a user
		// who updates without deactivating must still get migrated. This
		// is a cheap no-op once schema_version is already current.
		Options::maybe_migrate();

		// HPOS compatibility declaration: unconditional - registered
		// regardless of admin/front context, the master `enabled` switch, or
		// the `enable_woocommerce` toggle (see declare_hpos_compatibility()
		// for why). before_woocommerce_init never fires when WooCommerce
		// isn't active, so this add_action() is a safe no-op then.
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_hpos_compatibility' ) );

		// Front-end only: Tracker::should_output() also refuses to fire in
		// wp-admin, but there is no reason to even register the wp_head
		// hook there.
		if ( ! is_admin() ) {
			Tracker::register();
			DeveloperApi::register();
		}

		// Admin-only: the settings screen and dashboard widget have no
		// front-end footprint.
		if ( is_admin() ) {
			SettingsPage::register();
			DashboardWidget::register();
			SetupNotice::register();
		}

		// Not context-gated: each integration's OWN hooks are what decide
		// where they run (e.g. Gutenberg's editor-assets hook only ever
		// fires in wp-admin, its render_block filter only ever fires on the
		// front end), so the registrar itself must be reachable in both
		// contexts. Manager::register() re-derives its own master-switch +
		// per-integration gate from Options on every boot.
		IntegrationsManager::register();
	}

	/**
	 * Hooked on before_woocommerce_init (see boot()): declare compatibility
	 * with WooCommerce's High-Performance Order Storage (custom_order_tables)
	 * feature.
	 *
	 * This is a statement about the PLUGIN'S CODE being HPOS-safe
	 * (Integrations\WooCommerce reads/writes its idempotency flag via the
	 * order CRUD API - `$order->get_meta()`/`update_meta_data()`/`save()` -
	 * never raw post meta; see docs/DECISIONS.md [D4]), not about whether
	 * tracking is currently configured or on. Registering it unconditionally,
	 * rather than gating it behind the master `enabled` switch or the
	 * `enable_woocommerce` toggle like Integrations\Manager does for the
	 * tracking hook itself, matters: if it were gated, turning tracking off
	 * (or never having configured it yet) would make WooCommerce's Features
	 * screen report this plugin as incompatible with HPOS, which is false -
	 * the code's storage behaviour never changes based on those settings.
	 */
	public static function declare_hpos_compatibility() {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}

		FeaturesUtil::declare_compatibility( 'custom_order_tables', STATS_UMAMI_FILE, true );
	}
}
