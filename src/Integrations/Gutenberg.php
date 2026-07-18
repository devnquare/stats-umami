<?php
/**
 * Gutenberg core/button integration: enqueues the editor JS build and
 * injects the tracked event's data-umami-event* attributes server-side.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Integrations;

use StatsUmami\Support\EventAttributes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render_block is the SOLE source of truth for data-umami-event* (see
 * docs/DECISIONS.md [D3]): the editor never writes the attribute into saved
 * markup (blocks/src/index.js only ever registers the umamiEvent/
 * umamiDataPairs block attributes, never blocks.getSaveContent.extraProps),
 * so this filter is the one and only place the attribute can appear. That
 * makes the old plugin's duplicate-attribute defect (OLD-PLUGIN-INVENTORY
 * §12 defect #3/#4) structurally impossible rather than merely avoided.
 */
class Gutenberg {

	/**
	 * Editor script handle.
	 *
	 * @var string
	 */
	const EDITOR_HANDLE = 'stats-umami-editor';

	/**
	 * Register this integration's hooks. Called by Integrations\Manager only
	 * when the master switch + enable_gutenberg + the dependency predicate
	 * all pass. Registers in BOTH admin (editor assets) and front-end
	 * (render_block) contexts - see Manager's docblock for why it is not
	 * itself wrapped in an is_admin() check.
	 */
	public static function register() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
		add_filter( 'render_block', array( __CLASS__, 'inject_event_attributes' ), 10, 2 );
	}

	/**
	 * Enqueue the @wordpress/scripts editor build (blocks/build/index.js),
	 * using the generated .asset.php for its dependency array + content-hash
	 * version rather than hand-maintaining either.
	 */
	public static function enqueue_editor_assets() {
		$asset_file = STATS_UMAMI_DIR . 'blocks/build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::EDITOR_HANDLE,
			STATS_UMAMI_URL . 'blocks/build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( self::EDITOR_HANDLE, 'stats-umami' );
	}

	/**
	 * Render_block filter (2 args, per docs/DECISIONS.md [D3]): for a
	 * core/button block carrying a non-empty umamiEvent stored attribute,
	 * inject data-umami-event + one data-umami-event-<key> per stored data
	 * pair into the button's anchor - exactly once. Fast-returns $block_content
	 * unchanged for every other block, or when no umamiEvent is set.
	 *
	 * The event name/data come from $block['attrs'] (the block's own stored
	 * attributes), never by scraping $block_content - that is what makes
	 * this the single, authoritative injection point.
	 *
	 * @param string               $block_content The block's rendered HTML.
	 * @param array<string, mixed> $block         Parsed block, incl. 'blockName' and 'attrs'.
	 * @return string
	 */
	public static function inject_event_attributes( $block_content, $block ) {
		if ( ! isset( $block['blockName'] ) || 'core/button' !== $block['blockName'] ) {
			return $block_content;
		}

		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$name  = isset( $attrs['umamiEvent'] ) ? EventAttributes::sanitize_event_name( $attrs['umamiEvent'] ) : '';

		if ( '' === $name ) {
			return $block_content;
		}

		$data_pairs     = isset( $attrs['umamiDataPairs'] ) && is_array( $attrs['umamiDataPairs'] ) ? $attrs['umamiDataPairs'] : array();
		$injected_attrs = EventAttributes::build_attribute_string( $name, $data_pairs );

		// core/button's own `tagName` block attribute (enum a|button,
		// default a) lets it save as either element - confirmed present in
		// WP core's own block-library bundle from ~6.4+ (absent on our 6.0
		// floor, which only ever saves <a>) and still within our supported
		// 6.0-7.0 range, so both tags need the dedupe-guarded injection here.
		$injected = preg_replace_callback(
			'/<(?:a|button)\b[^>]*\bclass="[^"]*\bwp-block-button__link\b[^"]*"[^>]*>/i',
			static function ( $matches ) use ( $injected_attrs ) {
				$tag = $matches[0];

				// Dedupe guard: this anchor already carries the attribute
				// (e.g. content saved by the pre-render_block old plugin,
				// or a second pass over already-injected content) - leave
				// it as the single source of truth rather than adding a
				// second one.
				if ( false !== strpos( $tag, 'data-umami-event=' ) ) {
					return $tag;
				}

				return substr( $tag, 0, -1 ) . ' ' . $injected_attrs . '>';
			},
			$block_content,
			1
		);

		return null !== $injected ? $injected : $block_content;
	}
}
