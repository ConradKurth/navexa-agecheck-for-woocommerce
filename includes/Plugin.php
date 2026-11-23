<?php
/**
 * Plugin wiring.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the activation hook, the WooCommerce feature declarations and,
 * once WooCommerce has loaded, every component.
 *
 * Nothing here talks to AgeCheck. Components that need WooCommerce classes are
 * wired on woocommerce_loaded, so a site without WooCommerce runs none of them.
 */
final class Plugin {

	/**
	 * Oldest WooCommerce the plugin supports. The block-checkout validation hook,
	 * woocommerce_checkout_validate_order_before_payment, is @since 9.9.0.
	 */
	const MIN_WC_VERSION = '9.9';

	/**
	 * Id of the WooCommerce > Settings tab.
	 */
	const SETTINGS_TAB = 'navexa_agecheck';

	/**
	 * Absolute path of the main plugin file.
	 *
	 * @var string
	 */
	private static $file = '';

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Registers the plugin's top-level hooks. Called once from the main file.
	 *
	 * @param string $file Absolute path of the main plugin file.
	 */
	public static function boot( string $file ): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		self::$file   = $file;

		register_activation_hook( $file, array( Install::class, 'activate' ) );
		register_activation_hook( $file, array( RateLimit::class, 'install' ) );
		register_deactivation_hook( $file, array( Sweep::class, 'unschedule' ) );
		add_action( 'before_woocommerce_init', array( self::class, 'declare_compatibility' ) );
		add_action( 'woocommerce_loaded', array( self::class, 'wire' ) );
		add_action( 'admin_notices', array( self::class, 'woocommerce_notice' ) );
	}

	/**
	 * Absolute path of the main plugin file.
	 */
	public static function file(): string {
		return self::$file;
	}

	/**
	 * URL of the AgeCheck settings tab.
	 */
	public static function settings_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=' . self::SETTINGS_TAB );
	}

	/**
	 * Declares HPOS (custom order tables) and block checkout compatibility.
	 *
	 * Every order read and write in this plugin goes through wc_get_order(),
	 * order CRUD meta and wc_get_orders(), never post meta or WP_Query.
	 */
	public static function declare_compatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}
		FeaturesUtil::declare_compatibility( 'custom_order_tables', self::$file, true );
		FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', self::$file, true );
	}

	/**
	 * Wires every component. Runs on woocommerce_loaded.
	 */
	public static function wire(): void {
		Install::register();
		Mode::register();
		add_filter( 'woocommerce_get_settings_pages', array( self::class, 'add_settings_page' ) );

		// Pre-payment: the shopper verifies before the order is placed
		// (Start and ReturnPage), and checkout refuses an order that has not
		// passed (Classic, Blocks).
		RateLimit::register();
		Start::register();
		ReturnPage::register();
		Checkout\Classic::register();
		Checkout\Blocks::register();
		// Express payment buttons hidden on restricted products and carts
		// (each gateway's own filters); the gates and the hold still apply.
		Express::register();
		// The optional entry age gate: self-attestation, not verification.
		EntryGate::register();

		// Holds: nothing below makes a network call in a shopper's request
		// except Binding's AttachOrder after an order is placed.
		OrderQuery::register();
		Hold::register();
		AddressGuard::register();
		Binding::register();
		Release::register();
		// Emailed links withdrawn once an order will not ship (W5).
		LinkRevoke::register();
		Denial::register();
		Webhook::register();
		Sweep::register();
		Shipment::register();
		Subscriptions::register();
		Checkout\OrderPay::register();
		// "Verify now" on a held order's own pages, and AgeCheck's way back.
		OrderVerify::register();
		Privacy::register();
		if ( is_admin() ) {
			Admin\OrderPanel::register();
			Admin\OrdersFilter::register();
			Admin\Notices::register();
			Admin\ProductField::register();
		}
	}

	/**
	 * Adds the AgeCheck tab to WooCommerce > Settings.
	 *
	 * Settings extends WC_Settings_Page, which WooCommerce loads only for its
	 * settings screens, so the class is referenced only from this filter.
	 *
	 * @param mixed $pages WooCommerce settings pages.
	 * @return array<int|string, mixed>
	 */
	public static function add_settings_page( $pages ) {
		if ( ! is_array( $pages ) ) {
			$pages = array();
		}
		$pages[] = new Settings();
		return $pages;
	}

	/**
	 * Whether the running WooCommerce is older than MIN_WC_VERSION.
	 */
	public static function woocommerce_too_old(): bool {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, self::MIN_WC_VERSION, '<' );
	}

	/**
	 * Admin notice when WooCommerce is missing or too old, on the Plugins
	 * screen only. The "Requires Plugins" header normally prevents the first
	 * case; a deleted WooCommerce directory does not.
	 */
	public static function woocommerce_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! current_user_can( 'activate_plugins' ) || ! $screen instanceof \WP_Screen || 'plugins' !== $screen->id ) {
			return;
		}
		if ( ! did_action( 'woocommerce_loaded' ) ) {
			$message = __( 'Navexa AgeCheck for WooCommerce needs WooCommerce to be installed and active. Age checks are off until it is.', 'navexa-agecheck-for-woocommerce' );
		} elseif ( self::woocommerce_too_old() ) {
			$message = sprintf(
				/* translators: %s: minimum WooCommerce version, e.g. 9.9. */
				__( 'Navexa AgeCheck for WooCommerce needs WooCommerce %s or later. Update WooCommerce before relying on age checks.', 'navexa-agecheck-for-woocommerce' ),
				self::MIN_WC_VERSION
			);
		} else {
			return;
		}
		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}
}
