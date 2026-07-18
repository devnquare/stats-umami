<?php
/**
 * Minimal stand-in for CF7's WPCF7_ContactForm, used by
 * ContactForm7IntegrationTest - save_meta() only ever calls ->id(), and
 * render_panel() only ever calls ->id()/->title(). CF7 isn't installed in
 * the integration bootstrap, so its real class can't be used.
 *
 * @package StatsUmami
 */

namespace StatsUmami\Tests\Integration;

/**
 * Duck-types the two WPCF7_ContactForm methods Integrations\ContactForm7 calls.
 */
final class FakeContactForm {

	/**
	 * @var int
	 */
	private $post_id;

	/**
	 * @var string
	 */
	private $post_title;

	/**
	 * @param int    $post_id    The wpcf7_contact_form post ID this stands in for.
	 * @param string $post_title The form's title.
	 */
	public function __construct( $post_id, $post_title = '' ) {
		$this->post_id    = $post_id;
		$this->post_title = $post_title;
	}

	/**
	 * @return int
	 */
	public function id() {
		return $this->post_id;
	}

	/**
	 * @return string
	 */
	public function title() {
		return $this->post_title;
	}
}
