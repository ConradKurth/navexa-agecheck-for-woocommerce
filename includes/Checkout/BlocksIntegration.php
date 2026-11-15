<?php
/**
 * Checkout block integration.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck\Checkout;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Navexa\AgeCheck\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the Verify age block's front-end script (and, through its
 * dependencies, the loader) wherever the checkout block renders. Referenced
 * only from Blocks::register_integration(), which runs when WooCommerce
 * Blocks is loaded, so the interface always exists.
 */
final class BlocksIntegration implements IntegrationInterface {

	/**
	 * Integration name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'navexa-agecheck';
	}

	/**
	 * Registers the scripts.
	 *
	 * @return void
	 */
	public function initialize() {
		Frontend::register_scripts();
	}

	/**
	 * Front-end script handles.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return Gate::enforces() ? array( Frontend::HANDLE_BLOCK ) : array();
	}

	/**
	 * Editor script handles (the block registers its own editor script).
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * Data for the block's script (wc.wcSettings 'navexa-agecheck_data').
	 *
	 * @return array<string, mixed>
	 */
	public function get_script_data() {
		return Frontend::script_data();
	}
}
