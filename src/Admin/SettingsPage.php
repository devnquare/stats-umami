<?php
/**
 * Admin settings screen: the 4-tab Settings API page, tab-save [D2],
 * reset-to-defaults, asset enqueueing, and the plugins-list action link.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Admin;

use StatsUmami\Settings\Options;
use StatsUmami\Settings\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders admin.php?page=stats-umami with its 4 tab forms. Every tab form
 * shares ONE register_setting()/settings_fields('stats_umami') group against
 * the ONE stats_umami_options option, per the [D2] MUST-REPLICATE pattern
 * documented on Settings\Sanitizer.
 */
class SettingsPage {

	/**
	 * Page slug (also used as the query-arg value and menu slug).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'stats-umami';

	/**
	 * The Settings API option group shared by all 4 tab forms.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'stats_umami';

	/**
	 * Hook suffix returned by add_menu_page(), used to gate asset
	 * enqueueing and the reset handler to this screen only.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Register all WordPress hooks for this screen.
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( STATS_UMAMI_FILE ),
			array( __CLASS__, 'action_links' )
		);
	}

	/**
	 * Add the top-level admin menu item + page, and hook the reset handler
	 * and asset enqueueing to this specific screen.
	 */
	public static function register_menu() {
		self::$hook_suffix = add_menu_page(
			__( 'Stats Umami', 'stats-umami' ),
			__( 'Stats Umami', 'stats-umami' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-bar'
		);

		add_action( 'load-' . self::$hook_suffix, array( __CLASS__, 'maybe_handle_reset' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register the ONE shared Settings API group/option used by all 4 tabs.
	 */
	public static function register_setting() {
		register_setting(
			self::OPTION_GROUP,
			Options::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Options::defaults(),
			)
		);
	}

	/**
	 * The single sanitize_callback for the whole stats_umami_options option
	 * (see Sanitizer's class docblock for the full [D2] rationale).
	 *
	 * Unslash boundary: $input arrives here as the raw *slashed* value
	 * WordPress read straight from $_POST - this is the ONE place it gets
	 * wp_unslash()'d, per docs/DECISIONS.md. Sanitizer::sanitize() and every
	 * field sanitizer downstream assume they are already handed unslashed
	 * data.
	 *
	 * Sanitizer stays pure (no add_settings_error()); this layer independently
	 * re-checks a submitted website_id and surfaces a rejection notice to the
	 * user when it's invalid, matching design state screenshots/04-general-
	 * invalid-id.png. The rejected value itself is never persisted - Sanitizer
	 * keeps the previously-stored (valid) website_id, so the field will show
	 * that prior value again once the page reloads, alongside this notice.
	 *
	 * @param mixed $input Raw submission for the stats_umami_options option.
	 * @return array<string, mixed> Sanitized settings array to persist.
	 */
	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			return Options::defaults();
		}

		// A quirk of the Settings API worth documenting: register_setting()
		// attaches this method to a GLOBAL WordPress filter hook that fires
		// on every write to the stats_umami_options row, not only on a real
		// tab form submission. Our own internal writes - the boot-time
		// migration and the reset-to-defaults handler further below - go
		// through that exact same global hook, but they hand over an
		// already-valid, already-complete settings array with no _tab key.
		// Treat that shape as a trusted passthrough rather than re-running
		// it through the tab-aware Sanitizer, which would otherwise fall
		// back to its "no recognized tab" sweep and re-sanitize every
		// field. Concretely, that sweep would re-intersect the role-list
		// fields against whichever roles are registered at that moment,
		// silently dropping a role such as the WooCommerce shop-manager
		// one whenever WooCommerce happens to be inactive on this request.
		// Still run the type-only Options::coerce_types() over it before
		// persisting: this passthrough is also reachable by
		// anything else that writes this option through the same global
		// hook - WP-CLI, a DB import/migration, another plugin, or a
		// nonce-valid options.php POST missing _tab - so a wrong TYPE
		// (e.g. share_url_roles stored as a string) is never persisted,
		// even though Options::get()'s own read-time coercion is the real
		// safety net against any of it ever reaching a consumer.
		if ( ! array_key_exists( '_tab', $input ) ) {
			return Options::coerce_types( $input );
		}

		$input   = wp_unslash( $input );
		$current = Options::get();

		$tab = isset( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : '';

		if ( 'general' === $tab && array_key_exists( 'website_id', $input ) ) {
			$submitted_id = trim( (string) $input['website_id'] );

			if ( '' !== $submitted_id && ! Sanitizer::is_valid_uuid( $submitted_id ) ) {
				add_settings_error(
					Options::OPTION_KEY,
					'stats_umami_invalid_website_id',
					__( 'Website ID looks invalid - that change was not saved. Check the ID format: it should be a UUID from your Umami dashboard.', 'stats-umami' ),
					'error'
				);
			}
		}

		return Sanitizer::sanitize( $input, $current );
	}

	/**
	 * Handle the reset-to-defaults submission, hooked on load-{hook_suffix}
	 * so a redirect can still happen (runs before any HTML is sent).
	 * Guarded by its OWN nonce + manage_options, independent of the shared
	 * Settings API group used by the 4 tab forms.
	 */
	public static function maybe_handle_reset() {
		if ( empty( $_POST['stats_umami_do_reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'stats-umami' ) );
		}

		check_admin_referer( 'stats_umami_reset_defaults', 'stats_umami_reset_nonce' );

		$current  = Options::get();
		$defaults = Options::defaults();

		$defaults['schema_version'] = isset( $current['schema_version'] )
			? $current['schema_version']
			: Options::SCHEMA_VERSION;

		Options::update( $defaults );

		add_settings_error(
			Options::OPTION_KEY,
			'stats_umami_reset',
			__( 'All settings have been reset to their defaults.', 'stats-umami' ),
			'success'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		$redirect = add_query_arg(
			array(
				'page'             => self::PAGE_SLUG,
				'tab'              => self::current_tab(),
				'settings-updated' => 'true',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Enqueue the settings-screen CSS/JS, ONLY on this plugin's own screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( self::$hook_suffix !== $hook ) {
			return;
		}

		wp_enqueue_style( 'stats-umami-admin', STATS_UMAMI_URL . 'assets/css/admin.css', array(), STATS_UMAMI_VERSION );
		wp_enqueue_script( 'stats-umami-admin', STATS_UMAMI_URL . 'assets/js/admin.js', array(), STATS_UMAMI_VERSION, true );

		wp_localize_script(
			'stats-umami-admin',
			'statsUmamiAdmin',
			array(
				'validHint'    => __( 'Valid Website ID.', 'stats-umami' ),
				'invalidHint'  => __( 'Website ID looks invalid - expected a UUID.', 'stats-umami' ),
				'confirmReset' => __( 'Reset every Stats Umami setting to its default value? This cannot be undone.', 'stats-umami' ),
				'switchOn'     => __( 'On', 'stats-umami' ),
				'switchOff'    => __( 'Off', 'stats-umami' ),
			)
		);
	}

	/**
	 * Prepend a "Settings" link to this plugin's row on the Plugins list.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::tab_url( 'general' ) ),
			esc_html__( 'Settings', 'stats-umami' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * The 4 tabs, slug => label.
	 *
	 * @return array<string, string>
	 */
	private static function tabs() {
		return array(
			'general'  => __( 'General', 'stats-umami' ),
			'events'   => __( 'Events & integrations', 'stats-umami' ),
			'advanced' => __( 'Advanced', 'stats-umami' ),
			'tools'    => __( 'Tools & Support', 'stats-umami' ),
		);
	}

	/**
	 * The current tab, from ?tab=, constrained to the known tab slugs.
	 *
	 * @return string
	 */
	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array_key_exists( $tab, self::tabs() ) ? $tab : 'general';
	}

	/**
	 * Build the URL for a given tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private static function tab_url( $tab ) {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Whether the invalid-website-ID settings error is queued for this
	 * request (drives the crit connection-status state).
	 *
	 * @return bool
	 */
	private static function has_invalid_uuid_error() {
		foreach ( get_settings_errors( Options::OPTION_KEY ) as $error ) {
			if ( 'stats_umami_invalid_website_id' === $error['code'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Connection status: ok / warn / neutral / crit (see DESIGN_HANDOFF §9).
	 * Public: reused by Admin\DashboardWidget, whose 3 states (connected/
	 * off/disconnected) map onto ok/warn/neutral. The widget can never
	 * observe crit - has_invalid_uuid_error() only reflects a settings-page
	 * form submission just processed on THIS request, which never happens
	 * on a dashboard-screen request.
	 *
	 * @param array<string, mixed> $options Current plugin settings.
	 * @return string
	 */
	public static function connection_state( array $options ) {
		if ( self::has_invalid_uuid_error() ) {
			return 'crit';
		}

		if ( empty( $options['host_url'] ) || empty( $options['website_id'] ) ) {
			return 'neutral';
		}

		if ( empty( $options['enabled'] ) ) {
			return 'warn';
		}

		return 'ok';
	}

	/**
	 * Render the full settings page: header, tabs, notices, active tab.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = Options::get();
		$tab     = self::current_tab();
		$state   = self::connection_state( $options );
		$tabs    = self::tabs();
		?>
		<div class="wrap umami-stats stats-umami">
			<div class="us-header">
				<div class="us-title-lockup">
					<svg class="us-mark" width="30" height="30" viewBox="0 0 100 100" aria-hidden="true"><rect x="6" y="6" width="88" height="88" rx="22" fill="#41c97a"></rect></svg>
					<div>
						<h1 class="us-title"><?php esc_html_e( 'Stats Umami', 'stats-umami' ); ?></h1>
						<div class="us-maker">
							<?php
							echo wp_kses(
								/* translators: %s: maker name, "nquare". */
								sprintf( __( 'by %s', 'stats-umami' ), '<strong>nquare</strong>' ),
								array( 'strong' => array() )
							);
							?>
						</div>
					</div>
				</div>
				<?php self::render_status_chip( $state ); ?>
			</div>

			<?php
			/*
			 * WordPress core relocates admin notices at runtime
			 * (wp-admin/js/common.js): with no .wp-header-end anchor present,
			 * it falls back to the first `.wrap h1, .wrap h2` - which here is
			 * the <h1> nested INSIDE .us-header's flex lockup, right before
			 * .us-maker ("by nquare"). Without this marker, saving a tab
			 * shoves a "Settings saved." notice between the title and the
			 * maker line, moving the brand mark. Core styles this element
			 * invisible; it only exists as a landmark for that relocation.
			 */
			?>
			<hr class="wp-header-end" />

			<h2 class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Stats Umami settings', 'stats-umami' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( self::tab_url( $slug ) ); ?>"
						class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php Notices::render(); ?>

			<?php
			switch ( $tab ) {
				case 'events':
					self::render_events_tab( $options );
					break;

				case 'advanced':
					self::render_advanced_tab( $options );
					break;

				case 'tools':
					self::render_tools_tab( $options );
					break;

				default:
					self::render_general_tab( $options, $state );
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * The 4-state connection status TITLE label (ok/warn/neutral/crit) -
	 * single source of truth reused by the header status chip, the General
	 * tab status panel, and Admin\DashboardWidget (see connection_state()'s
	 * docblock for why the widget only ever sees ok/warn/neutral).
	 *
	 * @param string $state One of ok/warn/neutral/crit.
	 * @return string
	 */
	public static function state_label( $state ) {
		$labels = array(
			'ok'      => __( 'Connected & tracking', 'stats-umami' ),
			'warn'    => __( 'Configured - tracking is off', 'stats-umami' ),
			'neutral' => __( 'Not connected yet', 'stats-umami' ),
			'crit'    => __( 'Website ID looks invalid', 'stats-umami' ),
		);

		return isset( $labels[ $state ] ) ? $labels[ $state ] : '';
	}

	/**
	 * Compact connection-status chip in the page header (C2, 4 states).
	 *
	 * @param string $state One of ok/warn/neutral/crit.
	 */
	private static function render_status_chip( $state ) {
		?>
		<span class="us-chip us-chip--<?php echo esc_attr( $state ); ?>">
			<span class="us-chip__dot" aria-hidden="true"></span>
			<?php echo esc_html( self::state_label( $state ) ); ?>
		</span>
		<?php
	}

	/**
	 * General tab: the guided connect flow + secondary display/access settings.
	 *
	 * @param array<string, mixed> $options Current plugin settings.
	 * @param string               $state   Connection state (ok/warn/neutral/crit).
	 */
	private static function render_general_tab( array $options, $state ) {
		$status_copy  = array(
			'ok'      => __( 'Your site is sending page views to your own Umami server. Administrators are excluded by default - to check it, open your site logged out or in a private/incognito window.', 'stats-umami' ),
			'warn'    => __( 'Your details are saved. Turn on tracking to go live.', 'stats-umami' ),
			'neutral' => __( 'Add your Umami host and Website ID below to begin.', 'stats-umami' ),
			'crit'    => __( 'Check the ID format - it should be a UUID from your Umami dashboard.', 'stats-umami' ),
		);
		$uuid_invalid = 'crit' === $state;
		?>
		<form method="post" action="options.php" class="us-stack">
			<?php settings_fields( self::OPTION_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[_tab]" value="general" />

			<div class="us-panel">
				<div class="us-status us-status--<?php echo esc_attr( $state ); ?>">
					<span class="us-status__icon" aria-hidden="true"></span>
					<div>
						<div class="us-status__title"><?php echo esc_html( self::state_label( $state ) ); ?></div>
						<div class="us-status__detail"><?php echo esc_html( $status_copy[ $state ] ); ?></div>
					</div>
					<?php if ( 'ok' === $state && ! empty( $options['share_url'] ) ) : ?>
						<a class="us-row" style="margin-left:auto" href="<?php echo esc_url( $options['share_url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'View your stats', 'stats-umami' ); ?> &rarr;
						</a>
					<?php endif; ?>
				</div>

				<div style="padding:20px 22px">
					<div class="us-step">
						<div class="us-step__rail">
							<span class="us-step__num<?php echo '' !== $options['host_url'] ? ' us-step__num--done' : ''; ?>">1</span>
							<span class="us-step__line"></span>
						</div>
						<div class="us-step__body">
							<label class="us-step__label" for="stats_umami_host_url"><?php esc_html_e( 'Add your Umami host', 'stats-umami' ); ?></label>
							<div class="us-step__help"><?php esc_html_e( 'The web address where your Umami runs. If Umami is installed in a subfolder, or the tracker script has been renamed, enter the full script URL instead (e.g. https://example.com/analytics/script.js).', 'stats-umami' ); ?></div>
							<input
								type="url"
								id="stats_umami_host_url"
								class="us-input"
								name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[host_url]"
								value="<?php echo esc_attr( $options['host_url'] ); ?>"
								placeholder="https://analytics.yoursite.com"
							/>
						</div>
					</div>

					<div class="us-step">
						<div class="us-step__rail">
							<span class="us-step__num<?php echo Sanitizer::is_valid_uuid( $options['website_id'] ) ? ' us-step__num--done' : ''; ?>">2</span>
							<span class="us-step__line"></span>
						</div>
						<div class="us-step__body">
							<label class="us-step__label" for="stats_umami_website_id"><?php esc_html_e( 'Paste your Website ID', 'stats-umami' ); ?></label>
							<div class="us-step__help"><?php esc_html_e( 'The Website ID from your Umami dashboard (a UUID).', 'stats-umami' ); ?></div>
							<div class="us-field">
								<input
									type="text"
									id="stats_umami_website_id"
									class="us-input us-input--mono<?php echo $uuid_invalid ? ' us-input--invalid' : ( '' !== $options['website_id'] ? ' us-input--valid' : '' ); ?>"
									name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[website_id]"
									value="<?php echo esc_attr( $options['website_id'] ); ?>"
									placeholder="b8f3a1c0-9d44-4e21-a7c5-1f0e2d6b9a83"
									<?php echo $uuid_invalid ? 'aria-invalid="true"' : ''; ?>
								/>
							</div>
							<?php if ( $uuid_invalid ) : ?>
								<div class="us-hint us-hint--error"><?php esc_html_e( 'Website ID looks invalid - that change was not saved. The value shown is your last valid one.', 'stats-umami' ); ?></div>
							<?php elseif ( '' !== $options['website_id'] ) : ?>
								<div class="us-hint us-hint--ok"><?php esc_html_e( 'Valid Website ID.', 'stats-umami' ); ?></div>
							<?php else : ?>
								<div class="us-hint"></div>
							<?php endif; ?>
						</div>
					</div>

					<div class="us-step">
						<div class="us-step__rail">
							<span class="us-step__num<?php echo ! empty( $options['enabled'] ) ? ' us-step__num--done' : ''; ?>">3</span>
						</div>
						<div class="us-step__body">
							<label class="us-step__label" for="stats_umami_enabled"><?php esc_html_e( 'Turn on tracking', 'stats-umami' ); ?></label>
							<div class="us-step__help"><?php esc_html_e( 'Saves and starts recording on your site.', 'stats-umami' ); ?></div>
							<?php self::render_switch( 'enabled', 'stats_umami_enabled', ! empty( $options['enabled'] ) ); ?>
						</div>
					</div>
				</div>

				<div class="us-panel__footer">
					<span class="us-privacy"><?php esc_html_e( 'Your data stays on your own Umami server.', 'stats-umami' ); ?></span>
					<button type="submit" class="button button-primary us-btn--primary"><?php esc_html_e( 'Save changes', 'stats-umami' ); ?></button>
				</div>
			</div>

			<div class="us-perf">
				<div class="us-row--between">
					<div>
						<label for="stats_umami_performance_tracking" style="font-weight:650"><?php esc_html_e( 'Performance tracking', 'stats-umami' ); ?></label>
						<p class="description" style="margin:2px 0 8px"><?php esc_html_e( 'Record Core Web Vitals alongside page views.', 'stats-umami' ); ?></p>
					</div>
					<?php self::render_switch( 'performance_tracking', 'stats_umami_performance_tracking', ! empty( $options['performance_tracking'] ) ); ?>
				</div>
				<div>
					<span class="us-metric">LCP</span>
					<span class="us-metric">INP</span>
					<span class="us-metric">CLS</span>
					<span class="us-metric">FCP</span>
					<span class="us-metric">TTFB</span>
				</div>
			</div>

			<table class="form-table us-settings" role="presentation">
				<tr>
					<th scope="row"><label for="stats_umami_share_url"><?php esc_html_e( 'Share URL', 'stats-umami' ); ?></label></th>
					<td>
						<input
							type="url"
							id="stats_umami_share_url"
							class="us-input"
							name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[share_url]"
							value="<?php echo esc_attr( $options['share_url'] ); ?>"
							placeholder="https://analytics.yoursite.com/share/xxxxx"
							style="max-width:480px"
						/>
						<p class="description"><?php esc_html_e( 'Optional. Your Umami dashboard\'s public share link. When set, a "View your stats" link appears here and on your dashboard widget. Anyone with this link can view the shared dashboard.', 'stats-umami' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="stats_umami_dashboard_widget"><?php esc_html_e( 'Dashboard widget', 'stats-umami' ); ?></label></th>
					<td><?php self::render_switch( 'dashboard_widget', 'stats_umami_dashboard_widget', ! empty( $options['dashboard_widget'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Dashboard widget visible to', 'stats-umami' ); ?></th>
					<td>
						<?php self::render_role_checkboxes( 'share_url_roles', $options['share_url_roles'], true ); ?>
						<p class="description"><?php esc_html_e( 'Which roles can see the Stats Umami widget on the WordPress dashboard. Administrators can always see it.', 'stats-umami' ); ?></p>
					</td>
				</tr>
			</table>

			<p><button type="submit" class="button button-primary us-btn--primary"><?php esc_html_e( 'Save changes', 'stats-umami' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Events & Integrations tab.
	 *
	 * @param array<string, mixed> $options Current plugin settings.
	 */
	private static function render_events_tab( array $options ) {
		$master_off = empty( $options['enabled'] );

		$autotrack_fields = array(
			'autotrack_links'    => array( __( 'Link clicks', 'stats-umami' ), __( 'Record clicks on links on your site.', 'stats-umami' ) ),
			'autotrack_outbound' => array( __( 'Outbound links', 'stats-umami' ), __( 'Record clicks on links that leave your site.', 'stats-umami' ) ),
			'autotrack_buttons'  => array( __( 'Button clicks', 'stats-umami' ), __( 'Record clicks on buttons.', 'stats-umami' ) ),
			'autotrack_forms'    => array( __( 'Form submissions', 'stats-umami' ), __( 'Record native form submissions. Contact Form 7 and WPForms are handled by their integrations below.', 'stats-umami' ) ),
			'track_comments'     => array( __( 'Comment submissions', 'stats-umami' ), __( 'Record when a visitor posts a comment.', 'stats-umami' ) ),
		);

		$integrations = array(
			'enable_gutenberg'   => array(
				'name'      => __( 'Gutenberg', 'stats-umami' ),
				'desc'      => __( 'Track block button/link clicks as events.', 'stats-umami' ),
				'available' => true,
			),
			'enable_cf7'         => array(
				'name'      => __( 'Contact Form 7', 'stats-umami' ),
				'desc'      => __( 'Track successful form submissions as events.', 'stats-umami' ),
				'available' => defined( 'WPCF7_VERSION' ),
			),
			'enable_wpforms'     => array(
				'name'      => __( 'WPForms', 'stats-umami' ),
				'desc'      => __( 'Track successful form submissions as events.', 'stats-umami' ),
				'available' => class_exists( 'WPForms' ),
			),
			'enable_woocommerce' => array(
				'name'      => __( 'WooCommerce', 'stats-umami' ),
				'desc'      => __( 'Track paid orders (Processing or Completed), with revenue.', 'stats-umami' ),
				'available' => class_exists( 'WooCommerce' ),
			),
			'enable_elementor'   => array(
				'name'      => __( 'Elementor', 'stats-umami' ),
				'desc'      => __( 'Track Elementor button clicks as events.', 'stats-umami' ),
				'available' => defined( 'ELEMENTOR_VERSION' ),
			),
		);
		?>
		<form method="post" action="options.php" class="us-stack">
			<?php settings_fields( self::OPTION_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[_tab]" value="events" />

			<?php if ( $master_off ) : ?>
				<div class="us-notice us-notice--warning">
					<?php esc_html_e( 'Turn on tracking in the General tab to activate these options.', 'stats-umami' ); ?>
				</div>
			<?php endif; ?>

			<div class="us-panel<?php echo $master_off ? ' is-master-off' : ''; ?>" style="padding:20px 22px">
				<div class="us-eyebrow" style="margin-bottom:12px"><?php esc_html_e( 'Automatic tracking', 'stats-umami' ); ?></div>
				<div class="us-stack">
					<?php foreach ( $autotrack_fields as $field => $copy ) : ?>
						<?php self::render_checkbox( $field, 'stats_umami_' . $field, ! empty( $options[ $field ] ), $copy[0], $copy[1], $master_off ); ?>
					<?php endforeach; ?>
				</div>
				<?php if ( $master_off ) : ?>
					<div class="us-disabled-hint" style="margin-top:10px"><?php esc_html_e( 'Turn on tracking in General to enable.', 'stats-umami' ); ?></div>
				<?php endif; ?>
			</div>

			<div class="us-panel<?php echo $master_off ? ' is-master-off' : ''; ?>" style="padding:20px 22px">
				<div class="us-eyebrow" style="margin-bottom:12px"><?php esc_html_e( 'Plugin integrations', 'stats-umami' ); ?></div>
				<div class="us-stack">
					<?php foreach ( $integrations as $field => $info ) : ?>
						<?php self::render_integration_card( $field, $info, ! empty( $options[ $field ] ), $master_off ); ?>
					<?php endforeach; ?>
				</div>
			</div>

			<p><button type="submit" class="button button-primary us-btn--primary"><?php esc_html_e( 'Save changes', 'stats-umami' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Advanced tab.
	 *
	 * @param array<string, mixed> $options Current plugin settings.
	 */
	private static function render_advanced_tab( array $options ) {
		$tracking_behavior = array(
			'auto_pageview'  => array(
				__( 'Automatic page views', 'stats-umami' ),
				__( 'Record a page view automatically on each page load. Turn this off only if your theme or app sends page views itself.', 'stats-umami' ),
			),
			'exclude_search' => array( __( 'Exclude search parameters from URLs', 'stats-umami' ), '' ),
			'exclude_hash'   => array( __( 'Exclude hash (#) from URLs', 'stats-umami' ), '' ),
			'do_not_track'   => array( __( 'Respect "Do Not Track" browser setting', 'stats-umami' ), '' ),
		);
		?>
		<form method="post" action="options.php" class="us-stack">
			<?php settings_fields( self::OPTION_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[_tab]" value="advanced" />

			<table class="form-table us-settings" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Exclude roles', 'stats-umami' ); ?></th>
					<td>
						<?php self::render_role_checkboxes( 'excluded_roles', $options['excluded_roles'] ); ?>
						<p class="description"><?php esc_html_e( 'Logged-in users with any of the ticked roles are never tracked - regardless of what they click or visit. This is why your own visits as an administrator do not show up in Umami.', 'stats-umami' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="stats_umami_script_loading"><?php esc_html_e( 'Script loading', 'stats-umami' ); ?></label></th>
					<td>
						<select id="stats_umami_script_loading" class="us-input" name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[script_loading]" style="width:auto">
							<option value="defer" <?php selected( $options['script_loading'], 'defer' ); ?>><?php esc_html_e( 'defer (recommended)', 'stats-umami' ); ?></option>
							<option value="async" <?php selected( $options['script_loading'], 'async' ); ?>><?php esc_html_e( 'async', 'stats-umami' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="stats_umami_host_url_override"><?php esc_html_e( 'Host URL override', 'stats-umami' ); ?></label></th>
					<td>
						<input
							type="url"
							id="stats_umami_host_url_override"
							class="us-input"
							name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[host_url_override]"
							value="<?php echo esc_attr( $options['host_url_override'] ); ?>"
							style="max-width:480px"
						/>
						<p class="description"><?php esc_html_e( 'Only needed when the tracker script loads from one address but should send its data to a different one (e.g. a CDN or a nested custom path).', 'stats-umami' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="stats_umami_domains"><?php esc_html_e( 'Allowed domains', 'stats-umami' ); ?></label></th>
					<td>
						<input
							type="text"
							id="stats_umami_domains"
							class="us-input us-input--mono"
							name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[domains]"
							value="<?php echo esc_attr( $options['domains'] ); ?>"
							style="max-width:480px"
						/>
						<p class="description"><?php esc_html_e( 'Limit tracking to these hostnames (comma-separated). Leave blank to allow any. Useful when a staging copy shares the same Website ID - list only the hostnames you want counted.', 'stats-umami' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="stats_umami_tag"><?php esc_html_e( 'Global tag', 'stats-umami' ); ?></label></th>
					<td>
						<input
							type="text"
							id="stats_umami_tag"
							class="us-input us-input--mono"
							name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[tag]"
							value="<?php echo esc_attr( $options['tag'] ); ?>"
							style="max-width:480px"
						/>
						<p class="description"><?php esc_html_e( 'Label every event Umami records from this site with this tag - including page views and, if enabled below, performance events - so you can filter by it in Umami.', 'stats-umami' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tracking behavior', 'stats-umami' ); ?></th>
					<td>
						<div class="us-stack">
							<?php foreach ( $tracking_behavior as $field => $copy ) : ?>
								<?php self::render_checkbox( $field, 'stats_umami_' . $field, ! empty( $options[ $field ] ), $copy[0], $copy[1] ); ?>
							<?php endforeach; ?>
						</div>
					</td>
				</tr>
			</table>

			<p><button type="submit" class="button button-primary us-btn--primary"><?php esc_html_e( 'Save changes', 'stats-umami' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Tools & Support tab.
	 *
	 * @param array<string, mixed> $options Current plugin settings.
	 */
	private static function render_tools_tab( array $options ) {
		?>
		<div class="us-stack">
			<form method="post" action="options.php" class="us-stack">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[_tab]" value="tools" />

				<table class="form-table us-settings" role="presentation">
					<tr>
						<th scope="row"><label for="stats_umami_delete_data_on_uninstall"><?php esc_html_e( 'Delete data on uninstall', 'stats-umami' ); ?></label></th>
						<td>
							<?php self::render_switch( 'delete_data_on_uninstall', 'stats_umami_delete_data_on_uninstall', ! empty( $options['delete_data_on_uninstall'] ) ); ?>
							<p class="description"><?php esc_html_e( "Deletes only this plugin's own settings when you uninstall it. Your analytics stored in Umami are never touched.", 'stats-umami' ); ?></p>
						</td>
					</tr>
				</table>

				<p><button type="submit" class="button button-primary us-btn--primary"><?php esc_html_e( 'Save changes', 'stats-umami' ); ?></button></p>
			</form>

			<form method="post" id="stats-umami-reset-form">
				<?php wp_nonce_field( 'stats_umami_reset_defaults', 'stats_umami_reset_nonce' ); ?>
				<input type="hidden" name="stats_umami_do_reset" value="1" />
				<p class="description"><?php esc_html_e( 'Resets all Stats Umami settings to their defaults. Your Umami connection details will be cleared.', 'stats-umami' ); ?></p>
				<button type="submit" class="button us-btn--danger"><?php esc_html_e( 'Reset to defaults', 'stats-umami' ); ?></button>
			</form>

			<div class="us-panel" style="padding:20px 22px">
				<div class="us-eyebrow" style="margin-bottom:10px"><?php esc_html_e( 'Developer API', 'stats-umami' ); ?></div>
				<p class="description"><?php esc_html_e( 'Fire a custom event from your own JavaScript:', 'stats-umami' ); ?></p>
				<div class="us-code">window.statsUmami.track( <span class="tok-str">'newsletter_signup'</span>, { location: <span class="tok-str">'footer'</span> } );</div>
				<p class="description"><?php esc_html_e( 'Available on the front end to non-excluded visitors - test while logged out. Call it after the page has loaded or on a user interaction; events fired before the Umami tracker is ready are not queued. Event data is sent to Umami and is subject to Umami\'s own limits.', 'stats-umami' ); ?></p>
			</div>

			<div class="us-panel" style="padding:20px 22px">
				<div class="us-eyebrow" style="margin-bottom:10px"><?php esc_html_e( 'Support & resources', 'stats-umami' ); ?></div>
				<ul class="us-stack">
					<li><a href="https://umamiwp.com" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Plugin website', 'stats-umami' ); ?> &#8599;</a></li>
					<li><a href="https://wordpress.org/support/plugin/stats-umami/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Support forum', 'stats-umami' ); ?> &#8599;</a></li>
					<li><a href="https://wordpress.org/support/plugin/stats-umami/reviews/#new-post" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Rate Stats Umami', 'stats-umami' ); ?> &#8599;</a></li>
				</ul>
			</div>

			<div class="us-credit">
				<div>
					<div class="us-credit__title"><?php esc_html_e( 'Built by nquare', 'stats-umami' ); ?></div>
					<p>
						<?php
						esc_html_e(
							'With thanks to the Umami project. Built on the open-source Umami project (umami.is), with gratitude. Not affiliated with or endorsed by Umami.',
							'stats-umami'
						);
						?>
					</p>
					<p><small><?php echo esc_html( sprintf( /* translators: %s: plugin version. */ __( 'Version: %s', 'stats-umami' ), STATS_UMAMI_VERSION ) ); ?></small></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a group of role checkboxes against the site's real roles.
	 *
	 * @param string   $field              Option field name (array-valued).
	 * @param string[] $selected           Currently-selected role slugs.
	 * @param bool     $lock_administrator When true (opt-in; default false leaves every other
	 *                                     caller, e.g. `excluded_roles`, unaffected), any role whose
	 *                                     capabilities include `manage_options` renders checked,
	 *                                     `disabled`, and WITHOUT a `name` attribute - purely
	 *                                     decorative, never submitted, never stored. Detected from
	 *                                     the real role's own capabilities, not a hardcoded slug, so
	 *                                     any role granted `manage_options` is locked the same way an
	 *                                     "Administrator" role is. Used by the "Dashboard widget
	 *                                     visible to" row (share_url_roles) - see docs/DECISIONS.md
	 *                                     for why: Administrators can always see the
	 *                                     widget regardless of what is stored, so showing the box
	 *                                     unchecked/editable there would be a lie.
	 */
	private static function render_role_checkboxes( $field, array $selected, $lock_administrator = false ) {
		$roles = function_exists( 'wp_roles' ) ? wp_roles()->roles : array();

		foreach ( $roles as $slug => $role ) {
			$id        = 'stats_umami_' . $field . '_' . $slug;
			$is_locked = $lock_administrator && ! empty( $role['capabilities']['manage_options'] );
			?>
			<label class="us-check" for="<?php echo esc_attr( $id ); ?>" style="display:inline-flex;margin-right:16px">
				<input
					type="checkbox"
					id="<?php echo esc_attr( $id ); ?>"
					<?php if ( ! $is_locked ) : ?>
						name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[<?php echo esc_attr( $field ); ?>][]"
					<?php endif; ?>
					value="<?php echo esc_attr( $slug ); ?>"
					<?php checked( $is_locked || in_array( $slug, $selected, true ) ); ?>
					<?php disabled( $is_locked ); ?>
				/>
				<span class="us-check__box"></span>
				<span><?php echo esc_html( translate_user_role( $role['name'] ) ); ?></span>
			</label>
			<?php
		}
	}

	/**
	 * Render one auto-tracking checkbox.
	 *
	 * When $inert is true the visible control gets a REAL disabled attribute
	 * (the only way to also block keyboard toggling, not just clicks) which
	 * means it won't be present in $_POST at all; a parallel hidden input
	 * carrying the same name+current value is emitted alongside it whenever
	 * the stored value is true, so a disabled control never silently flips a
	 * true value to false on save - see the "Saved values are preserved"
	 * requirement on the Events tab's master-off state.
	 *
	 * @param string $field   Option field name.
	 * @param string $id      Element id.
	 * @param bool   $checked Current value.
	 * @param string $label   Visible label.
	 * @param string $help    Optional one-line help text.
	 * @param bool   $inert   True to render disabled + aria-disabled (master-off state).
	 */
	private static function render_checkbox( $field, $id, $checked, $label, $help = '', $inert = false ) {
		$name = Options::OPTION_KEY . '[' . $field . ']';
		?>
		<?php if ( $inert && $checked ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="1" />
		<?php endif; ?>
		<label class="us-check" for="<?php echo esc_attr( $id ); ?>"<?php echo $inert ? ' aria-disabled="true"' : ''; ?>>
			<input
				type="checkbox"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value="1"
				<?php checked( $checked ); ?>
				<?php disabled( $inert ); ?>
			/>
			<span class="us-check__box"></span>
			<span>
				<?php echo esc_html( $label ); ?>
				<?php if ( '' !== $help ) : ?>
					<br /><span class="us-muted" style="font-size:11.5px"><?php echo esc_html( $help ); ?></span>
				<?php endif; ?>
			</span>
		</label>
		<?php
	}

	/**
	 * Render one on/off switch + an adjacent "On"/"Off" text label (state is
	 * never colour-only). See render_checkbox() for the disabled+hidden-
	 * input value-preservation pattern used when $inert is true.
	 *
	 * @param string $field   Option field name.
	 * @param string $id      Element id.
	 * @param bool   $checked Current value.
	 * @param bool   $inert   True to render disabled + aria-disabled (master-off or dependency-unavailable state).
	 */
	private static function render_switch( $field, $id, $checked, $inert = false ) {
		$name = Options::OPTION_KEY . '[' . $field . ']';
		?>
		<?php if ( $inert && $checked ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="1" />
		<?php endif; ?>
		<span class="us-row">
			<label class="us-switch" for="<?php echo esc_attr( $id ); ?>"<?php echo $inert ? ' aria-disabled="true"' : ''; ?>>
				<input
					type="checkbox"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="1"
					<?php checked( $checked ); ?>
					<?php disabled( $inert ); ?>
				/>
				<span class="us-switch__track"></span>
				<span class="us-switch__knob"></span>
			</label>
			<span><?php echo $checked ? esc_html__( 'On', 'stats-umami' ) : esc_html__( 'Off', 'stats-umami' ); ?></span>
		</span>
		<?php
	}

	/**
	 * Render one integration card (detected vs not-installed vs master-off).
	 * Both "not installed" and "master enable is off" use the same disabled
	 * + value-preserving switch (render_switch()) - the dependency-missing
	 * case additionally gets the .is-unavailable card styling + copy.
	 *
	 * @param string               $field      Option field name.
	 * @param array<string, mixed> $info       'name', 'desc', 'available'.
	 * @param bool                 $checked    Current value.
	 * @param bool                 $master_off Whether the General-tab master Enable is off.
	 */
	private static function render_integration_card( $field, array $info, $checked, $master_off ) {
		$available = ! empty( $info['available'] );
		$classes   = 'us-integration' . ( $available ? '' : ' is-unavailable' );
		$id        = 'stats_umami_' . $field;
		$inert     = $master_off || ! $available;
		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<span class="us-integration__logo" aria-hidden="true"><?php echo esc_html( strtoupper( substr( $info['name'], 0, 2 ) ) ); ?></span>
			<div style="flex:1;min-width:0">
				<div class="us-integration__name">
					<?php echo esc_html( $info['name'] ); ?>
					<span class="us-pill <?php echo $available ? 'us-pill--ok' : 'us-pill--off'; ?>" style="margin-left:6px">
						<?php echo $available ? esc_html__( 'Detected', 'stats-umami' ) : esc_html__( 'Not installed', 'stats-umami' ); ?>
					</span>
				</div>
				<div class="us-integration__desc">
					<?php echo esc_html( $info['desc'] ); ?>
					<?php if ( ! $available ) : ?>
						<?php
						/* translators: %s: integration name, e.g. "WPForms". */
						echo esc_html( sprintf( __( 'Install %s to enable this.', 'stats-umami' ), $info['name'] ) );
						?>
					<?php elseif ( $master_off ) : ?>
						<span class="us-disabled-hint"><?php esc_html_e( 'Turn on tracking in General to enable.', 'stats-umami' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<?php self::render_switch( $field, $id, $checked, $inert ); ?>
		</div>
		<?php
	}
}
