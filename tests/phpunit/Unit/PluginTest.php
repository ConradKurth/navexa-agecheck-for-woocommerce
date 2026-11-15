<?php
/**
 * Plugin wiring tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Brain\Monkey\Functions;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Checkout\Blocks;
use Navexa\AgeCheck\Checkout\Classic;
use Navexa\AgeCheck\Plugin;
use Navexa\AgeCheck\RateLimit;
use Navexa\AgeCheck\ReturnPage;
use Navexa\AgeCheck\Start;
use Navexa\AgeCheck\Settings;
use Navexa\AgeCheck\Tests\TestCase;

final class PluginTest extends TestCase {

	public function test_boot_registers_activation_and_woocommerce_hooks(): void {
		Functions\expect( 'register_activation_hook' )
			->once()
			->with( '/plugins/navexa-agecheck-for-woocommerce/navexa-agecheck-for-woocommerce.php', array( Install::class, 'activate' ) );
		Functions\expect( 'register_deactivation_hook' )
			->once()
			->with( '/plugins/navexa-agecheck-for-woocommerce/navexa-agecheck-for-woocommerce.php', array( \Navexa\AgeCheck\Sweep::class, 'unschedule' ) );
		Functions\expect( 'register_activation_hook' )
			->once()
			->with( '/plugins/navexa-agecheck-for-woocommerce/navexa-agecheck-for-woocommerce.php', array( RateLimit::class, 'install' ) );

		Plugin::boot( '/plugins/navexa-agecheck-for-woocommerce/navexa-agecheck-for-woocommerce.php' );

		$this->assertNotFalse( has_action( 'before_woocommerce_init', array( Plugin::class, 'declare_compatibility' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_loaded', array( Plugin::class, 'wire' ) ) );
		$this->assertSame( '/plugins/navexa-agecheck-for-woocommerce/navexa-agecheck-for-woocommerce.php', Plugin::file() );
	}

	public function test_declares_hpos_and_block_checkout_compatibility(): void {
		FeaturesUtil::$declared = array();
		Plugin::declare_compatibility();
		$this->assertSame(
			array(
				array( 'custom_order_tables', Plugin::file(), true ),
				array( 'cart_checkout_blocks', Plugin::file(), true ),
			),
			FeaturesUtil::$declared
		);
	}

	public function test_wire_registers_components(): void {
		Plugin::wire();
		$this->assertNotFalse( has_action( 'init', array( Install::class, 'ensure' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( Install::class, 'paused_notice' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . Install::CONFIRM_ACTION, array( Install::class, 'handle_confirm' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( Mode::class, 'test_mode_notice' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_get_settings_pages', array( Plugin::class, 'add_settings_page' ) ) );

		// W1 hold core.
		$this->assertNotFalse( has_action( 'woocommerce_before_order_object_save', array( \Navexa\AgeCheck\Hold::class, 'backstop' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_before_order_object_save', array( \Navexa\AgeCheck\AddressGuard::class, 'guard' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_checkout_order_processed', array( \Navexa\AgeCheck\Binding::class, 'on_classic_order_processed' ) ) );
		$this->assertNotFalse( has_action( \Navexa\AgeCheck\Release::HOOK, array( \Navexa\AgeCheck\Release::class, 'run_job' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_status_refunded', array( \Navexa\AgeCheck\Denial::class, 'resolved' ) ) );
		$this->assertNotFalse( has_action( 'rest_api_init', array( \Navexa\AgeCheck\Webhook::class, 'register_route' ) ) );
		$this->assertNotFalse( has_action( \Navexa\AgeCheck\Sweep::HOOK, array( \Navexa\AgeCheck\Sweep::class, 'run_job' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_status_completed', array( \Navexa\AgeCheck\Shipment::class, 'on_completed' ) ) );
		$this->assertNotFalse( has_filter( 'wcs_renewal_order_created', array( \Navexa\AgeCheck\Subscriptions::class, 'on_renewal_created' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_before_pay_action', array( \Navexa\AgeCheck\Checkout\OrderPay::class, 'before_pay' ) ) );
		$this->assertFalse( has_action( 'add_meta_boxes', array( \Navexa\AgeCheck\Admin\OrderPanel::class, 'add_box' ) ), 'admin screens only' );

		// W2 pre-payment.
		$this->assertNotFalse( has_action( 'init', array( RateLimit::class, 'maybe_install' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_blocks_loaded', array( Start::class, 'register_store_api' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_api_navexa_agecheck_return', array( ReturnPage::class, 'render' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_after_checkout_validation', array( Classic::class, 'validate' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_review_order_before_submit', array( Classic::class, 'render_button' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_checkout_validate_order_before_payment', array( Blocks::class, 'validate' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_store_api_checkout_update_order_meta', array( Blocks::class, 'mark_cart_checkout' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_blocks_checkout_block_registration', array( Blocks::class, 'register_integration' ) ) );
	}

	public function test_wire_registers_admin_components_in_the_admin(): void {
		$this->is_admin = true;
		Plugin::wire();
		$this->assertNotFalse( has_action( 'add_meta_boxes', array( \Navexa\AgeCheck\Admin\OrderPanel::class, 'add_box' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( \Navexa\AgeCheck\Admin\OrdersFilter::class, 'filter_hpos_args' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( \Navexa\AgeCheck\Admin\Notices::class, 'render' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_product_options_general_product_data', array( \Navexa\AgeCheck\Admin\ProductField::class, 'render' ) ) );
	}

	public function test_settings_page_is_appended(): void {
		$pages = Plugin::add_settings_page( array( 'existing' ) );
		$this->assertCount( 2, $pages );
		$this->assertInstanceOf( Settings::class, $pages[1] );
	}

	public function test_settings_url(): void {
		$this->assertSame( 'https://shop.example/wp-admin/admin.php?page=wc-settings&tab=navexa_agecheck', Plugin::settings_url() );
	}

	public function test_woocommerce_notice_when_woocommerce_is_missing(): void {
		Functions\when( 'did_action' )->justReturn( 0 );
		ob_start();
		Plugin::woocommerce_notice();
		$this->assertStringContainsString( 'needs WooCommerce to be installed', ob_get_clean() );
	}

	public function test_woocommerce_notice_is_for_plugin_admins_only(): void {
		$this->caps = array( 'manage_woocommerce' => true );
		Functions\when( 'did_action' )->justReturn( 0 );
		ob_start();
		Plugin::woocommerce_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_no_woocommerce_notice_when_woocommerce_is_loaded(): void {
		Functions\when( 'did_action' )->justReturn( 1 );
		ob_start();
		Plugin::woocommerce_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_woocommerce_notice_when_woocommerce_is_too_old(): void {
		define( 'WC_VERSION', '9.8.5' );
		Functions\when( 'did_action' )->justReturn( 1 );
		$this->assertTrue( Plugin::woocommerce_too_old() );
		ob_start();
		Plugin::woocommerce_notice();
		$this->assertStringContainsString( 'needs WooCommerce 9.9 or later', ob_get_clean() );
	}
}
