<?php
/**
 * WP-admin Dashboard status widget (`umami_stats_widget`): a compact
 * "is tracking working?" panel matching handoff/design/returned/
 * DESIGN_HANDOFF.md §F and the already-shipped assets/css/admin.css section
 * 13 `#umami_stats_widget .us-w-*` rules.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Admin;

use StatsUmami\Settings\Options;
use StatsUmami\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the widget on wp_dashboard_setup, gated by the dashboard_widget
 * switch AND the widget-viewer capability (Support\Capabilities, gated by
 * share_url_roles - the ONLY surface that option gates: not the settings
 * page, not the General-tab "View your stats" link, both of which already
 * sit behind manage_options); renders
 * the 3-state connection status (reusing SettingsPage::connection_state()/
 * state_label() as the single source of truth), a short meta list, and the
 * Open-settings / View-your-stats quick links. Read-only - no writes, no
 * nonce needed (see docs/PLAN.md §6 security model).
 */
class DashboardWidget {

	/**
	 * Widget id. Kept as `umami_stats_widget` - an internal DOM/CSS hook
	 * matching the already-shipped admin.css `#umami_stats_widget`
	 * selectors, the same treatment as the `.umami-stats` scope class that
	 * predates the "Stats Umami" rename (CODER_IMPLEMENTATION_PLAN.md §2).
	 * The human-facing widget TITLE is "Stats Umami".
	 *
	 * @var string
	 */
	const WIDGET_ID = 'umami_stats_widget';

	/**
	 * Register the widget-add hook and the dashboard-screen CSS enqueue.
	 */
	public static function register() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'maybe_add_widget' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add the widget for the current user - unless the dashboard_widget
	 * switch is off, or the current user may not view it (Capabilities).
	 */
	public static function maybe_add_widget() {
		$options = Options::get();

		if ( empty( $options['dashboard_widget'] ) ) {
			return;
		}

		if ( ! Capabilities::current_user_can_view( $options['share_url_roles'] ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Stats Umami', 'stats-umami' ),
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Enqueue the SAME stats-umami-admin stylesheet SettingsPage enqueues on
	 * its own screen (admin.css section 13's `#umami_stats_widget .us-w-*`
	 * rules), scoped to the dashboard screen ONLY (hook suffix `index.php`)
	 * so it never loads on unrelated admin pages. No JS needed - the widget
	 * is static.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'index.php' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'stats-umami-admin', STATS_UMAMI_URL . 'assets/css/admin.css', array(), STATS_UMAMI_VERSION );
	}

	/**
	 * Render the widget body: status row, meta list, quick links.
	 */
	public static function render() {
		$options = Options::get();
		$state   = SettingsPage::connection_state( $options );
		?>
		<div class="us-w-status<?php echo esc_attr( self::status_modifier_class( $state ) ); ?>">
			<?php self::render_status_icon( $state ); ?>
			<span style="font-size:13px;font-weight:650;color:<?php echo esc_attr( self::status_label_color( $state ) ); ?>"><?php echo esc_html( SettingsPage::state_label( $state ) ); ?></span>
		</div>
		<?php if ( 'ok' === $state ) : ?>
			<p class="us-muted" style="margin:4px 0 0;font-size:11.5px"><?php esc_html_e( 'Administrators are excluded by default - to check it, browse logged out or in a private/incognito window.', 'stats-umami' ); ?></p>
		<?php endif; ?>

		<ul class="us-w-meta">
			<li>
				<span><?php esc_html_e( 'Host', 'stats-umami' ); ?></span>
				<span class="val"><?php echo esc_html( self::display_value( $options['host_url'] ) ); ?></span>
			</li>
			<li>
				<span><?php esc_html_e( 'Website ID', 'stats-umami' ); ?></span>
				<span class="val"><?php echo esc_html( self::truncated_website_id( $options['website_id'] ) ); ?></span>
			</li>
		</ul>

		<div class="us-w-links">
			<a class="button button-primary" style="flex:1;text-align:center" href="<?php echo esc_url( admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG ) ); ?>">
				<?php esc_html_e( 'Open settings', 'stats-umami' ); ?>
			</a>
			<?php if ( '' !== $options['share_url'] ) : ?>
				<a class="button" style="flex:1;text-align:center" href="<?php echo esc_url( $options['share_url'] ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'View your stats', 'stats-umami' ); ?> &#8599;
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * CSS modifier class for a connection state: '' (connected/ok has no
	 * modifier), ' is-off' (warn), or ' is-disconn' (neutral, and
	 * defensively crit - see SettingsPage::connection_state()'s docblock on
	 * why crit never actually reaches this widget). Includes its own
	 * leading space, matching the concatenation pattern SettingsPage uses
	 * for its `.is-unavailable`/`.is-master-off` modifiers.
	 *
	 * @param string $state ok/warn/neutral/crit.
	 * @return string
	 */
	private static function status_modifier_class( $state ) {
		if ( 'ok' === $state ) {
			return '';
		}

		return 'warn' === $state ? ' is-off' : ' is-disconn';
	}

	/**
	 * Label text color for a connection state, matching the exact hex
	 * values used in the accepted design (handoff/design/returned/
	 * styleguide.html C2 + the widget-specific demo). admin.css section 13
	 * only styles the row background/border, not the icon/label, so these
	 * are inline here exactly like the header's inline-SVG brand mark
	 * already is (see SettingsPage::render_page()).
	 *
	 * @param string $state ok/warn/neutral/crit.
	 * @return string
	 */
	private static function status_label_color( $state ) {
		$colors = array(
			'ok'      => '#14633a',
			'warn'    => '#8a5e16',
			'neutral' => '#3d4448',
			'crit'    => '#9e372e',
		);

		return isset( $colors[ $state ] ) ? $colors[ $state ] : $colors['neutral'];
	}

	/**
	 * Render the 22px status icon-circle for a connection state: a
	 * checkmark (ok), pause bars (warn/off), or a hollow dot (neutral/
	 * disconnected) - matching the accepted design (styleguide.html C2 +
	 * the widget-specific demo). Static markup only, no request data, so
	 * not an escaping concern.
	 *
	 * @param string $state ok/warn/neutral/crit.
	 */
	private static function render_status_icon( $state ) {
		if ( 'ok' === $state ) {
			?>
			<span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#2fa66a;flex:none" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 16 16"><path d="M13 4.5l-6 6.5L3 8" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
			<?php
			return;
		}

		if ( 'warn' === $state ) {
			?>
			<span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#c98a2a;flex:none" aria-hidden="true"><svg width="11" height="11" viewBox="0 0 16 16"><rect x="4.5" y="3.5" width="2.5" height="9" rx="1" fill="#fff"/><rect x="9" y="3.5" width="2.5" height="9" rx="1" fill="#fff"/></svg></span>
			<?php
			return;
		}
		?>
		<span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#f0eee9;border:1.5px solid #9aa0a4;flex:none" aria-hidden="true"><span style="width:7px;height:7px;border-radius:50%;background:#9aa0a4;display:block"></span></span>
		<?php
	}

	/**
	 * A meta-list value, or a graceful "Not set" placeholder for an empty
	 * one.
	 *
	 * @param string $value Raw option value.
	 * @return string
	 */
	private static function display_value( $value ) {
		return '' !== $value ? $value : __( 'Not set', 'stats-umami' );
	}

	/**
	 * The Website ID for DISPLAY only, truncated to first-block…last-block
	 * (e.g. "b8f3a1c0…9a83") to match the accepted design (screenshots/
	 * 09-dashboard-widget.png, styleguide.html widget demo); the full value
	 * is never used for anything beyond display here. "Not set" when empty.
	 *
	 * @param string $website_id Raw website_id option value.
	 * @return string
	 */
	private static function truncated_website_id( $website_id ) {
		$website_id = (string) $website_id;

		if ( '' === $website_id ) {
			return __( 'Not set', 'stats-umami' );
		}

		if ( mb_strlen( $website_id ) <= 16 ) {
			return $website_id;
		}

		return mb_substr( $website_id, 0, 8 ) . '…' . mb_substr( $website_id, -4 );
	}
}
