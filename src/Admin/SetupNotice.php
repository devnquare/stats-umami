<?php
/**
 * First-run "Set up Stats Umami" admin notice: a dismissible pointer to the
 * settings page, shown only while the plugin is genuinely unconfigured, so a
 * user who has just activated the plugin isn't left with no clue where
 * setup lives.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Admin;

use StatsUmami\Settings\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * No schema/option/activation-hook change: visibility is derived entirely
 * from the LIVE SettingsPage::connection_state() plus a per-user dismissal
 * flag stored in user meta (never in stats_umami_options). Once the site
 * connects, state leaves 'neutral' and the notice stops on its own; the
 * dismissal mainly covers the "activated but not connecting right now" user.
 *
 * Dismissal is a simple nonced admin_post handler (the same nonce+capability
 * shape as SettingsPage::maybe_handle_reset()), not WordPress core's
 * JS-only is-dismissible auto-hide - that click never reaches the server, so
 * it cannot persist across a page load, which is the whole point of this
 * notice.
 */
class SetupNotice {

	/**
	 * Per-user meta key recording that this user dismissed the notice.
	 *
	 * @var string
	 */
	const DISMISSED_META_KEY = 'stats_umami_setup_notice_dismissed';

	/**
	 * Admin_post_{action} action name, also used as the nonce action.
	 *
	 * @var string
	 */
	const DISMISS_ACTION = 'stats_umami_dismiss_setup_notice';

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * Whether the notice should show for the CURRENT user/request: a
	 * manage_options user, genuinely unconfigured (connection_state() ===
	 * 'neutral'), who hasn't dismissed it, and not already on the plugin's
	 * own settings screen (redundant there). Public + side-effect-free
	 * (aside from the reads it depends on) so it can be exercised directly
	 * by a test.
	 *
	 * @return bool
	 */
	public static function should_show() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( 'neutral' !== SettingsPage::connection_state( Options::get() ) ) {
			return false;
		}

		if ( self::is_dismissed_by_current_user() ) {
			return false;
		}

		if ( self::is_own_settings_screen() ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the CURRENT user has dismissed this notice.
	 *
	 * @return bool
	 */
	private static function is_dismissed_by_current_user() {
		return (bool) get_user_meta( get_current_user_id(), self::DISMISSED_META_KEY, true );
	}

	/**
	 * Whether the current admin screen is the plugin's own settings page -
	 * add_menu_page() with no parent slug gives it the hook suffix
	 * 'toplevel_page_' . PAGE_SLUG.
	 *
	 * @return bool
	 */
	private static function is_own_settings_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && 'toplevel_page_' . SettingsPage::PAGE_SLUG === $screen->id;
	}

	/**
	 * Hooked on admin_notices.
	 */
	public static function maybe_render() {
		if ( ! self::should_show() ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG );
		$dismiss_url  = wp_nonce_url(
			add_query_arg( 'action', self::DISMISS_ACTION, admin_url( 'admin-post.php' ) ),
			self::DISMISS_ACTION
		);
		?>
		<div class="notice notice-info">
			<p>
				<?php esc_html_e( 'Stats Umami is installed. Connect it to your Umami server to start tracking.', 'stats-umami' ); ?>
				<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary" style="margin-left:8px"><?php esc_html_e( 'Set up Stats Umami', 'stats-umami' ); ?></a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:8px"><?php esc_html_e( 'Dismiss', 'stats-umami' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Hooked on admin_post_{DISMISS_ACTION}: verify capability + nonce, then
	 * persist the per-user dismissal and redirect back where the user came
	 * from.
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'stats-umami' ) );
		}

		check_admin_referer( self::DISMISS_ACTION );

		update_user_meta( get_current_user_id(), self::DISMISSED_META_KEY, 1 );

		$redirect = wp_get_referer();

		wp_safe_redirect( $redirect ? $redirect : admin_url() );
		exit;
	}
}
