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
use Navexa\AgeCheck\Plugin;
use Navexa\AgeCheck\Settings;
use Navexa\AgeCheck\Tests\TestCase;

final class PluginTest extends TestCase {

	public function test_boot_registers_activation_and_woocommerce_hooks(): void {
		Functions\expect( 'register_activation_hook' )
			->once()
			->with( '/plugins/navexa-agecheck-for-woocommerce/navexa-agecheck-for-woocommerce.php', array( Install::class, 'activate' ) );

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
