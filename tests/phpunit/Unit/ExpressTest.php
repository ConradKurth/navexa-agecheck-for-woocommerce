<?php
/**
 * Express payment buttons hidden on restricted products and carts (W4 PR 15),
 * through each gateway's own documented filters.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Navexa\AgeCheck\Express;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Requirement;
use Navexa\AgeCheck\Tests\TestCase;

final class ExpressTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->products[7] = new \WC_Product( 7 );
		$this->products[8] = new \WC_Product( 8, 0, array( Requirement::PRODUCT_META => Requirement::PRODUCT_EXEMPT ) );
	}

	/** A cart holding only the exempt product. */
	private function exempt_cart(): void {
		self::$wc->cart->items = array(
			'item-1' => array(
				'data'     => $this->products[8],
				'quantity' => 1,
			),
		);
	}

	public function test_registers_each_gateways_documented_filters(): void {
		Express::register();
		$filters = array(
			'wcpay_payment_request_is_product_supported'           => 'supported_for_product',
			'wcpay_payment_request_is_cart_supported'              => 'supported_for_cart',
			'wcpay_woopay_button_is_product_supported'             => 'supported_for_product',
			'wcpay_platform_checkout_button_are_cart_items_supported' => 'supported_for_cart',
			'wc_stripe_hide_payment_request_on_product_page'       => 'stripe_hide_on_product',
			'wc_stripe_show_payment_request_on_cart'               => 'supported_for_cart',
			'wc_stripe_show_payment_request_on_checkout'           => 'supported_for_cart',
			'woocommerce_paypal_payments_product_buttons_disabled' => 'paypal_product_disabled',
			'woocommerce_paypal_payments_buttons_disabled'         => 'paypal_context_disabled',
			'woocommerce_paypal_payments_blocks_payment_method_data' => 'paypal_blocks_data',
			'woocommerce_paypal_payments_single_product_renderer_hook' => 'paypal_product_hook',
			'woocommerce_paypal_payments_proceed_to_checkout_button_renderer_hook' => 'paypal_cart_hook',
			'woocommerce_paypal_payments_mini_cart_button_renderer_hook' => 'paypal_mini_cart_hook',
		);
		foreach ( $filters as $hook => $method ) {
			$this->assertSame( 20, has_filter( $hook, array( Express::class, $method ) ), $hook );
		}
	}

	public function test_restricted_product_hides_woopayments_and_woopay(): void {
		$this->assertFalse( Express::supported_for_product( true, $this->products[7] ) );
		$this->assertTrue( Express::supported_for_product( true, $this->products[8] ), 'an exempt product keeps its buttons' );
		$this->assertFalse( Express::supported_for_product( false, $this->products[8] ), 'the gateway\'s own no stands' );
		$this->assertFalse( Express::supported_for_product( true, null ), 'no product reads as restricted' );
	}

	public function test_the_setting_off_hides_nothing(): void {
		$this->options[ Options::HIDE_EXPRESS ] = 'no';
		$this->assertTrue( Express::supported_for_product( true, $this->products[7] ) );
		$this->assertTrue( Express::supported_for_cart( true ) );
		$this->assertFalse( Express::stripe_hide_on_product( false, new \WP_Post( 7 ) ) );
		$this->assertFalse( Express::paypal_context_disabled( false, 'cart' ) );
		$this->assertSame( array( 'smartButtonsEnabled' => true ), Express::paypal_blocks_data( array( 'smartButtonsEnabled' => true ) ) );
	}

	public function test_include_rule_decides_per_product(): void {
		$this->options[ Options::RULE ]            = Options::RULE_INCLUDE;
		$this->options[ Options::RULE_CATEGORIES ] = array( 11 );
		$this->product_terms[7]['product_cat']     = array( 11 );
		$this->products[9]                         = new \WC_Product( 9 );
		$this->assertTrue( Express::product_restricted( $this->products[7] ) );
		$this->assertFalse( Express::product_restricted( $this->products[9] ), 'outside the chosen categories' );
	}

	public function test_a_variable_product_is_restricted_when_any_variation_is(): void {
		$this->options[ Options::RULE ] = Options::RULE_INCLUDE; // Nothing matches the rule itself.
		$parent                         = new \WC_Product( 20 );
		$parent->children               = array( 21, 22 );
		$this->products[20]             = $parent;
		$this->products[21]             = new \WC_Product( 21, 20 );
		$this->products[22]             = new \WC_Product( 22, 20 );
		$this->assertFalse( Express::product_restricted( $parent ) );

		$this->products[22]->meta[ Requirement::PRODUCT_META ] = Requirement::PRODUCT_REQUIRE;
		$this->assertTrue( Express::product_restricted( $parent ), 'one restricted variation hides the page\'s buttons' );

		unset( $this->products[22]->meta[ Requirement::PRODUCT_META ], $this->products[22] );
		$this->assertTrue( Express::product_restricted( $parent ), 'an unreadable variation counts as restricted' );

		$parent->children = range( 100, 100 + Express::MAX_VARIATIONS );
		$this->assertTrue( Express::product_restricted( $parent ), 'too many variations to read: restricted' );
	}

	public function test_more_variations_than_max_are_restricted_without_reading_them(): void {
		$this->options[ Options::RULE ] = Options::RULE_INCLUDE; // Nothing matches the rule itself.
		$parent                         = new \WC_Product( 20 );
		$this->products[20]             = $parent;
		$parent->children               = range( 100, 99 + Express::MAX_VARIATIONS );
		foreach ( $parent->children as $id ) {
			$this->products[ $id ] = new \WC_Product( $id, 20 ); // Readable, none restricted.
		}
		$this->assertFalse( Express::product_restricted( $parent ), 'MAX_VARIATIONS readable, unrestricted variations: not restricted' );

		$parent->children[]                         = 100 + Express::MAX_VARIATIONS;
		$this->products[ 100 + Express::MAX_VARIATIONS ] = new \WC_Product( 100 + Express::MAX_VARIATIONS, 20 );
		$read = 0;
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) use ( &$read ) {
				++$read;
				return isset( $this->products[ $id ] ) ? $this->products[ $id ] : false;
			}
		);
		$this->assertTrue( Express::product_restricted( $parent ), 'one more than MAX_VARIATIONS: restricted' );
		$this->assertSame( 0, $read, 'without reading a variation' );
	}

	public function test_an_exempt_role_keeps_express_buttons(): void {
		$this->options[ Options::EXEMPT_ROLES ] = array( 'wholesale' );
		$this->users[5]                          = new \WP_User( 5, array( 'wholesale' ) );
		$this->current_user                      = 5;
		$this->assertFalse( Express::product_restricted( $this->products[7] ) );
		$this->assertFalse( Express::cart_restricted() );
		$this->current_user = 0;
		$this->assertTrue( Express::product_restricted( $this->products[7] ) );
	}

	public function test_an_unreadable_product_hides_the_buttons(): void {
		$broken = new class( 30 ) extends \WC_Product {
			public function get_meta( $key, $single = true, $context = 'view' ) {
				throw new \RuntimeException( 'db gone' );
			}
		};
		$this->assertTrue( Express::product_restricted( $broken ) );
		$this->assertStringContainsString( 'buttons hidden', $this->all_logs() );
	}

	public function test_cart_filters(): void {
		$this->assertFalse( Express::supported_for_cart( true ), 'a restricted cart hides WooPayments, WooPay and Stripe' );
		$this->exempt_cart();
		$this->assertTrue( Express::supported_for_cart( true ) );
		self::$wc->cart->empty = true;
		$this->assertTrue( Express::supported_for_cart( true ), 'an empty cart is not restricted' );
		self::$wc->cart = null;
		$this->assertTrue( Express::supported_for_cart( true ), 'no cart: nothing to decide' );
	}

	public function test_pay_for_order_keeps_cart_filtered_buttons(): void {
		Functions\when( 'is_checkout_pay_page' )->justReturn( true );
		$this->assertTrue( Express::supported_for_cart( true ), 'order-pay: Stripe and WooPayments ask about the session cart; the order is paid and held (Q11b)' );
		$this->assertFalse( Express::supported_for_cart( false ), 'the gateway\'s own no stands' );
		$this->assertTrue( Express::paypal_context_disabled( false, 'mini-cart' ), 'the mini-cart still pays the restricted session cart' );
	}

	public function test_local_pickup_does_not_bring_the_buttons_back(): void {
		$this->chosen_methods = array( 'local_pickup' );
		$this->assertTrue( Requirement::cart_is_pickup() );
		$this->assertTrue( Express::cart_restricted(), 'the wallet sheet can switch to a shipping method' );
	}

	public function test_stripe_product_page_filter(): void {
		$this->assertTrue( Express::stripe_hide_on_product( false, new \WP_Post( 7 ) ) );
		$this->assertFalse( Express::stripe_hide_on_product( false, new \WP_Post( 8 ) ) );
		$this->assertTrue( Express::stripe_hide_on_product( true, new \WP_Post( 8 ) ), 'Stripe\'s own hide stands' );
		$this->assertTrue( Express::stripe_hide_on_product( false, null ), 'no post: hidden' );
	}

	public function test_paypal_product_and_context_filters(): void {
		$this->assertTrue( Express::paypal_product_disabled( false, array( 'product' => $this->products[7] ) ) );
		$this->assertFalse( Express::paypal_product_disabled( false, array( 'product' => $this->products[8], 'order_total' => 10.0 ) ) );
		$this->assertTrue( Express::paypal_product_disabled( false, array() ), 'PayPal found no product: hidden' );
		$this->assertTrue( Express::paypal_product_disabled( true, array( 'product' => $this->products[8] ) ) );

		foreach ( Express::PAYPAL_EXPRESS_CONTEXTS as $context ) {
			$this->assertTrue( Express::paypal_context_disabled( false, $context ), $context );
		}
		$this->assertFalse( Express::paypal_context_disabled( false, 'checkout' ), 'classic checkout: PayPal is the payment method, behind the form\'s validation' );
		$this->assertFalse( Express::paypal_context_disabled( false, 'pay-now' ), 'order-pay is accept-and-hold (Q11b)' );
		$this->assertFalse( Express::paypal_context_disabled( false, 'product' ), 'product pages have their own filter' );
		$this->exempt_cart();
		$this->assertFalse( Express::paypal_context_disabled( false, 'cart' ) );
		$this->assertTrue( Express::paypal_context_disabled( true, 'cart' ), 'PayPal\'s own disable stands' );
	}

	public function test_paypal_block_express_buttons(): void {
		$data = array(
			'id'                  => 'ppcp-gateway',
			'smartButtonsEnabled' => true,
			'placeOrderEnabled'   => true,
		);
		$hidden = Express::paypal_blocks_data( $data );
		$this->assertFalse( $hidden['smartButtonsEnabled'], 'SDK v5' );
		$this->assertTrue( $hidden['placeOrderEnabled'], 'the Place order row stays (Store API checkout, gated)' );
		$this->assertSame( 'not an array', Express::paypal_blocks_data( 'not an array' ) );
		$this->exempt_cart();
		$this->assertSame( $data, Express::paypal_blocks_data( $data ) );
	}

	public function test_paypal_sdk_v6_block_express_buttons_and_wallets(): void {
		// V6PaymentMethod::get_payment_method_data(): SdkV6Manager::script_data() + gateway data.
		$wallet = array(
			'enabled' => true,
			'styles'  => array( 'checkout-block' => array( 'color' => 'black' ) ),
			'gateway' => null,
		);
		$data   = array(
			'id'                  => 'ppcp-gateway',
			'page_context'        => 'checkout-block',
			'buttons_enabled'     => true,
			'place_order_enabled' => true,
			'apple_pay'           => $wallet,
			'google_pay'          => $wallet,
			'card_fields'         => array( 'enabled' => true ),
		);
		$hidden = Express::paypal_blocks_data( $data );
		$this->assertFalse( $hidden['buttons_enabled'], 'PayPal, Venmo and Pay Later express buttons' );
		foreach ( array( 'apple_pay', 'google_pay' ) as $key ) {
			$this->assertSame( array(), $hidden[ $key ]['styles'], $key . ': no style for the page, so the block registers no button' );
			$this->assertFalse( $hidden[ $key ]['enabled'], $key );
		}
		$this->assertTrue( $hidden['place_order_enabled'] );
		$this->assertSame( array( 'enabled' => true ), $hidden['card_fields'], 'card fields are a payment method behind the Store API checkout' );
		$this->exempt_cart();
		$this->assertSame( $data, Express::paypal_blocks_data( $data ) );
	}

	public function test_paypal_renderer_hooks_forward_only_unrestricted_renders(): void {
		$hook     = Express::paypal_cart_hook( 'woocommerce_proceed_to_checkout' );
		$this->assertSame( 'navexa_agecheck_paypal_cart', $hook );
		$this->assertSame( $hook, Express::paypal_cart_hook( 'woocommerce_proceed_to_checkout' ), 'stable for the v5 and v6 stacks alike' );
		$this->assertSame( 20, has_action( 'woocommerce_proceed_to_checkout', 'static function ()' ), 'forwarded at PayPal\'s own priority' );
		$this->assertSame( 'navexa_agecheck_paypal_mini_cart', Express::paypal_mini_cart_hook( 'woocommerce_widget_shopping_cart_after_buttons' ) );
		$this->assertSame( 30, has_action( 'woocommerce_widget_shopping_cart_after_buttons', 'static function ()' ) );
		$this->assertSame( 'navexa_agecheck_paypal_product', Express::paypal_product_hook( 'woocommerce_single_product_summary' ) );
		$this->assertSame( 31, has_action( 'woocommerce_single_product_summary', 'static function ()' ) );

		// Decided at render time.
		$this->assertTrue( Express::paypal_hidden( 'cart' ), 'restricted cart' );
		$this->assertTrue( Express::paypal_hidden( 'mini_cart' ) );
		$this->exempt_cart();
		$this->assertFalse( Express::paypal_hidden( 'cart' ), 'an exempt cart keeps PayPal' );
		$this->assertFalse( Express::paypal_hidden( 'mini_cart' ) );
		$GLOBALS['product'] = $this->products[7];
		$this->assertTrue( Express::paypal_hidden( 'product' ), 'a restricted product page' );
		$GLOBALS['product'] = $this->products[8];
		$this->assertFalse( Express::paypal_hidden( 'product' ), 'an exempt product page' );
		unset( $GLOBALS['product'] );
		Functions\when( 'wc_get_product' )->justReturn( false );
		$this->assertTrue( Express::paypal_hidden( 'product' ), 'no product to read: hidden' );
	}

	public function test_the_forwarder_fires_paypals_hook_only_for_an_unrestricted_cart(): void {
		$forward = null;
		Actions\expectAdded( 'woocommerce_proceed_to_checkout' )->once()->whenHappen(
			static function ( $callback ) use ( &$forward ) {
				$forward = $callback;
			}
		);
		Express::paypal_cart_hook( 'woocommerce_proceed_to_checkout' );
		$this->assertIsCallable( $forward );
		$forward();
		$this->assertSame( 0, did_action( 'navexa_agecheck_paypal_cart' ), 'restricted cart: PayPal renders nothing' );
		$this->exempt_cart();
		$forward();
		$this->assertSame( 1, did_action( 'navexa_agecheck_paypal_cart' ), 'exempt cart: PayPal renders' );
	}

	public function test_paypal_renderer_hooks_untouched_when_the_setting_is_off(): void {
		$this->options[ Options::HIDE_EXPRESS ] = 'no';
		$this->assertSame( 'woocommerce_proceed_to_checkout', Express::paypal_cart_hook( 'woocommerce_proceed_to_checkout' ) );
		$this->assertSame( 'woocommerce_single_product_summary', Express::paypal_product_hook( 'woocommerce_single_product_summary' ) );
		$this->assertFalse( has_action( 'woocommerce_proceed_to_checkout', 'static function ()' ) );
		$this->assertSame( 42, Express::paypal_mini_cart_hook( 42 ), 'not a hook name: unchanged' );
	}
}
