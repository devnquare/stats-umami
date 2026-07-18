<?php
/**
 * Uninstall handler: opt-in cleanup only.
 *
 * WordPress core defines WP_UNINSTALL_PLUGIN and requires it before this
 * file (never main plugin file) so this runs standalone, without our
 * autoloader/bootstrap - deliberately no StatsUmami\ classes are used here.
 *
 * @package StatsUmami
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$stats_umami_options = get_option( 'stats_umami_options' );

// Opt-in only; default is OFF. If the site owner never checked "Delete
// data on uninstall", leave every trace of settings/meta in place so a
// reinstall (or accidental delete-then-reinstall) doesn't lose config.
if ( empty( $stats_umami_options['delete_data_on_uninstall'] ) ) {
	return;
}

delete_post_meta_by_key( '_stats_umami_cf7_event' );
delete_post_meta_by_key( '_stats_umami_cf7_data' );

// The WooCommerce idempotency flag (_stats_umami_woo_tracked) is written via
// WooCommerce's order-CRUD API (see docs/DECISIONS.md [D4]); the call below
// only reaches the legacy (non-HPOS) case, where orders are wp_posts rows.
delete_post_meta_by_key( '_stats_umami_woo_tracked' );

// HPOS-aware cleanup: under High-Performance Order Storage, the same
// idempotency flag lives in WooCommerce's own wc_orders_meta table, not
// wp_postmeta, so the delete_post_meta_by_key() call above never reaches it.
// A single table-existence-guarded DELETE mirrors the legacy path's own
// single delete_post_meta_by_key() call above, replacing what used to be an
// unbounded wc_get_orders(limit=-1) fetch followed by a PER-ORDER
// wc_get_order()+save() loop - one full order hydration plus a CRUD save()
// round-trip (firing WooCommerce's own save hooks/cache invalidation) for
// EVERY tracked order, i.e. thousands of sequential round-trips on a large
// store, comfortably past max_execution_time before completion. Guarded ONLY
// by the table actually existing (previously ALSO gated on
// OrderUtil::custom_orders_table_usage_is_enabled(), i.e. HPOS being the
// CURRENTLY active storage; but the table - and any rows still in it - can
// exist regardless of which backend is active right now, so a store that ran
// HPOS, wrote these rows, then switched back to legacy storage had them
// orphaned forever by that extra gate, violating this plugin's one explicit
// data-deletion promise). A store on legacy storage that never enabled HPOS
// simply has no such table, so the SHOW TABLES check alone is a correct,
// sufficient no-op guard - class_exists('...OrderUtil') is no longer needed
// either, since neither $wpdb call below depends on WooCommerce's classes
// being loaded at uninstall time.
global $wpdb;

$stats_umami_hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-shot uninstall cleanup: no WP API covers either a table-existence check or a bulk delete-by-meta_key across every HPOS order, and no caching applies to a table being torn down/rows being permanently removed. The table name is a literal, re-validated against the live schema immediately below before it is used in the DELETE; the SHOW TABLES and meta_key values are both passed through $wpdb->prepare()/$wpdb->delete()'s own parameterization, never concatenated.
$stats_umami_hpos_table_exists = $wpdb->get_var(
	$wpdb->prepare( 'SHOW TABLES LIKE %s', $stats_umami_hpos_meta_table )
);

if ( $stats_umami_hpos_table_exists === $stats_umami_hpos_meta_table ) {
	$wpdb->delete(
		$stats_umami_hpos_meta_table,
		array( 'meta_key' => '_stats_umami_woo_tracked' ),
		array( '%s' )
	);
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key

// Batch-N first-run "Set up Stats Umami" notice: a per-user dismissal flag
// (SetupNotice::DISMISSED_META_KEY, written per-user). delete_metadata()
// with $user_id 0 + $delete_all true removes it for EVERY user in one call.
// Literal key string on purpose - this standalone uninstall script uses no
// StatsUmami\ classes (see the file docblock), so the SetupNotice constant
// is deliberately not referenced here.
delete_metadata( 'user', 0, 'stats_umami_setup_notice_dismissed', '', true );

// Deliberately NOT deleted (documented, not an oversight):
// - Gutenberg: the INJECTED data-umami-event* attribute is added at
// render_block time only (see docs/DECISIONS.md [D3]), never written into
// post_content, so there is nothing to clean up there. (The block's own
// umamiEvent/umamiDataPairs attribute JSON - the user's block config, not
// anything this plugin injects - does live in post_content like any other
// block attribute; that is expected and not something to clean up either.)
// - WPForms: form settings live inside WPForms' own post_content/postmeta;
// this plugin never rewrites that content and won't touch it.

// The option itself is deleted LAST, only after every associated-data
// cleanup step above has run: this is the opt-in marker AND (via
// $stats_umami_options above) the gate that got us into this file's cleanup
// branch in the first place, so if any earlier step fails/fatals, leaving it
// in place lets a retried uninstall detect there is still cleanup to do,
// rather than silently leaving the site half-cleaned with no marker left to
// notice it.
delete_option( 'stats_umami_options' );
