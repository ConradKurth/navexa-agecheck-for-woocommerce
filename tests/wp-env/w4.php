<?php
/**
 * W4 hardening checks inside a real WordPress + WooCommerce (wp-env):
 *
 *   wp eval-file tests/wp-env/w4.php                      (after prepayment-setup.php)
 *   wp --skip-plugins=navexa-agecheck-for-woocommerce eval-file tests/wp-env/w4-uninstall.php
 *
 * - Express buttons: each gateway's documented filter answers for real
 *   products, a variable product and a real session cart; when WooCommerce
 *   Stripe Gateway is active, its own Express Checkout helper is asked too.
 * - The fallback: express-style orders created outside both checkouts
 *   (PayPal "Pay now" / App Switch create orders with wc_create_order() and
 *   pay them) are held by payment_complete.
 * - Turnstile against Cloudflare's real siteverify with Cloudflare's test
 *   keys (always pass / always fail), and through Start in a CLI cart.
 * - Privacy: the exporter and eraser through WordPress's registries, and
 *   every registered eraser run in order, as wp-admin runs them (this
 *   plugin's before WooCommerce's order eraser, whose anonymized ship-to the
 *   address guard must not undo).
 * - PayPal Payments SDK v6 (the default stack): the renderer hooks it shares
 *   with v5 are forwarded only for unrestricted carts, and its block data
 *   loses the express buttons and wallets. Pay-for-order keeps cart-filtered
 *   buttons (Q11b).
 * - i18n: the block scripts carry the text domain for JS translations.
 * - Leaves fixtures for w4-uninstall.php (which runs with the plugin
 *   skipped, as WordPress runs uninstall.php) in the w4test_fixtures option.
 *
 * Needs the mock engine at NAVEXA_AGECHECK_API_URL and prepayment-setup.php
 * run first. Exits non-zero on any failure.
 *
 * @package Navexa\AgeCheck\Tests
 */

// phpcs:ignoreFile -- test script.

use Navexa\AgeCheck\AddressGuard;
use Navexa\AgeCheck\Express;
use Navexa\AgeCheck\Frontend;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Requirement;
use Navexa\AgeCheck\Start;
use Navexa\AgeCheck\Turnstile;

$GLOBALS['navexa_w4_failures'] = 0;

function navexa_w4_check( $ok, $what ) {
	if ( $ok ) {
		WP_CLI::log( 'ok   ' . $what );
		return;
	}
	++$GLOBALS['navexa_w4_failures'];
	WP_CLI::log( 'FAIL ' . $what );
}

function navexa_w4_product( $name, $meta = '' ) {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	if ( '' !== $meta ) {
		$product->update_meta_data( Requirement::PRODUCT_META, $meta );
	}
	$product->save();
	return $product;
}

function navexa_w4_us_address() {
	return array(
		'first_name' => 'Pat',
		'last_name'  => 'Doe',
		'address_1'  => '123 Main St',
		'address_2'  => '',
		'city'       => 'Springfield',
		'state'      => 'IL',
		'postcode'   => '62701',
		'country'    => 'US',
	);
}

/**
 * Runs a privacy exporter or eraser callback over every page, as WordPress
 * does, and merges the answers.
 */
function navexa_w4_all_pages( $callback, $email ) {
	$merged = array( 'data' => array(), 'items_removed' => false, 'items_retained' => false, 'messages' => array() );
	for ( $page = 1; $page <= 50; $page++ ) {
		$answer                   = call_user_func( $callback, $email, $page );
		$merged['data']           = array_merge( $merged['data'], isset( $answer['data'] ) ? $answer['data'] : array() );
		$merged['items_removed']  = $merged['items_removed'] || ! empty( $answer['items_removed'] );
		$merged['items_retained'] = $merged['items_retained'] || ! empty( $answer['items_retained'] );
		$merged['messages']       = array_merge( $merged['messages'], isset( $answer['messages'] ) ? $answer['messages'] : array() );
		if ( ! empty( $answer['done'] ) ) {
			$merged['pages'] = $page;
			return $merged;
		}
	}
	return $merged;
}

function navexa_w4_cart( $product_id ) {
	wc_load_cart();
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $product_id, 1 );
	$address = navexa_w4_us_address();
	foreach ( $address as $field => $value ) {
		WC()->customer->{'set_billing_' . $field}( $value );
		WC()->customer->{'set_shipping_' . $field}( $value );
	}
	WC()->customer->set_billing_email( 'pat@example.com' );
	WC()->cart->calculate_totals();
}

update_option( Options::RULE, Options::RULE_ALL );
delete_option( Options::HIDE_EXPRESS );
delete_option( Options::TURNSTILE );

$restricted = navexa_w4_product( 'W4 restricted' );
$exempt     = navexa_w4_product( 'W4 exempt', Requirement::PRODUCT_EXEMPT );

// A variable product exempt itself, with one variation that needs a check.
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Size' );
$attribute->set_options( array( 'S', 'M' ) );
$attribute->set_visible( true );
$attribute->set_variation( true );
$variable = new WC_Product_Variable();
$variable->set_name( 'W4 variable' );
$variable->set_status( 'publish' );
$variable->set_attributes( array( $attribute ) );
$variable->update_meta_data( Requirement::PRODUCT_META, Requirement::PRODUCT_EXEMPT );
$variable->save();
foreach ( array( 'S' => '', 'M' => Requirement::PRODUCT_REQUIRE ) as $size => $meta ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $variable->get_id() );
	$variation->set_attributes( array( 'size' => $size ) );
	$variation->set_regular_price( '12.00' );
	if ( '' !== $meta ) {
		$variation->update_meta_data( Requirement::PRODUCT_META, $meta );
	}
	$variation->save();
}
$variable = wc_get_product( $variable->get_id() );

// --- Express buttons: product pages -------------------------------------------
navexa_w4_check( true === Options::hide_express(), 'express buttons are hidden by default' );
navexa_w4_check( false === apply_filters( 'wcpay_payment_request_is_product_supported', true, $restricted ), 'WooPayments ECE: restricted product page hidden' );
navexa_w4_check( true === apply_filters( 'wcpay_payment_request_is_product_supported', true, $exempt ), 'WooPayments ECE: exempt product page kept' );
navexa_w4_check( false === apply_filters( 'wcpay_woopay_button_is_product_supported', true, $restricted ), 'WooPay: restricted product page hidden' );
navexa_w4_check( true === apply_filters( 'wc_stripe_hide_payment_request_on_product_page', false, get_post( $restricted->get_id() ) ), 'Stripe ECE: restricted product page hidden' );
navexa_w4_check( false === apply_filters( 'wc_stripe_hide_payment_request_on_product_page', false, get_post( $exempt->get_id() ) ), 'Stripe ECE: exempt product page kept' );
navexa_w4_check( true === apply_filters( 'woocommerce_paypal_payments_product_buttons_disabled', false, array( 'product' => $restricted ) ), 'PayPal: restricted product buttons disabled' );
navexa_w4_check( false === apply_filters( 'woocommerce_paypal_payments_product_buttons_disabled', false, array( 'product' => $exempt ) ), 'PayPal: exempt product buttons kept' );
navexa_w4_check( true === Express::product_restricted( $variable ), 'a variable product with one restricted variation is restricted' );

// --- Express buttons: the session cart ------------------------------------------
navexa_w4_cart( $restricted->get_id() );
navexa_w4_check( false === apply_filters( 'wcpay_payment_request_is_cart_supported', true, $restricted ), 'WooPayments ECE: restricted cart hidden' );
navexa_w4_check( false === apply_filters( 'wcpay_platform_checkout_button_are_cart_items_supported', true ), 'WooPay: restricted cart hidden' );
navexa_w4_check( false === apply_filters( 'wc_stripe_show_payment_request_on_cart', true ), 'Stripe ECE: restricted cart page hidden' );
navexa_w4_check( false === apply_filters( 'wc_stripe_show_payment_request_on_checkout', true, null ), 'Stripe ECE: restricted checkout page hidden' );
navexa_w4_check( true === apply_filters( 'woocommerce_paypal_payments_buttons_disabled', false, 'mini-cart' ), 'PayPal: mini-cart disabled for a restricted cart' );
navexa_w4_check( true === apply_filters( 'woocommerce_paypal_payments_buttons_disabled', false, 'cart-block' ), 'PayPal: cart block disabled for a restricted cart' );
navexa_w4_check( false === apply_filters( 'woocommerce_paypal_payments_buttons_disabled', false, 'checkout' ), 'PayPal: classic checkout payment button kept (form validated first)' );
$paypal_data = apply_filters( 'woocommerce_paypal_payments_blocks_payment_method_data', array( 'smartButtonsEnabled' => true, 'placeOrderEnabled' => true ) );
navexa_w4_check( false === $paypal_data['smartButtonsEnabled'] && true === $paypal_data['placeOrderEnabled'], 'PayPal blocks: express buttons off, Place order kept' );
// SDK v6 (V6PaymentMethod's data): buttons_enabled and each wallet's styles decide what its block script registers.
$v6_data = apply_filters(
	'woocommerce_paypal_payments_blocks_payment_method_data',
	array(
		'page_context'        => 'cart-block',
		'buttons_enabled'     => true,
		'place_order_enabled' => true,
		'apple_pay'           => array( 'enabled' => true, 'styles' => array( 'cart-block' => array() ) ),
		'google_pay'          => array( 'enabled' => true, 'styles' => array( 'cart-block' => array() ) ),
	)
);
navexa_w4_check( false === $v6_data['buttons_enabled'] && array() === $v6_data['apple_pay']['styles'] && array() === $v6_data['google_pay']['styles'] && true === $v6_data['place_order_enabled'], 'PayPal SDK v6 blocks: express buttons and wallets off, Place order kept' );
// SDK v6 classic pages: the shared renderer hooks, forwarded at render time.
$mini_hook = apply_filters( 'woocommerce_paypal_payments_mini_cart_button_renderer_hook', 'woocommerce_widget_shopping_cart_after_buttons' );
$cart_hook = apply_filters( 'woocommerce_paypal_payments_proceed_to_checkout_button_renderer_hook', 'woocommerce_proceed_to_checkout' );
$prod_hook = apply_filters( 'woocommerce_paypal_payments_single_product_renderer_hook', 'woocommerce_single_product_summary' );
navexa_w4_check( 'navexa_agecheck_paypal_mini_cart' === $mini_hook && 'navexa_agecheck_paypal_cart' === $cart_hook && 'navexa_agecheck_paypal_product' === $prod_hook, 'PayPal renderer hooks taken over (got ' . $mini_hook . ', ' . $cart_hook . ', ' . $prod_hook . ')' );
add_action(
	$mini_hook,
	static function () {
		echo '<span id="ppc-button-minicart"></span>'; // What PayPal prints there.
	},
	30
);
ob_start();
do_action( 'woocommerce_widget_shopping_cart_after_buttons' );
navexa_w4_check( false === strpos( ob_get_clean(), 'ppc-button-minicart' ), 'PayPal mini-cart wrapper not rendered for a restricted cart' );
$GLOBALS['product'] = $restricted;
navexa_w4_check( true === Express::paypal_hidden( 'product' ), 'PayPal product wrapper hidden on a restricted product page' );
$GLOBALS['product'] = $exempt;
navexa_w4_check( false === Express::paypal_hidden( 'product' ), 'PayPal product wrapper kept on an exempt product page' );
unset( $GLOBALS['product'] );
// Pay-for-order (Q11b): Stripe and WooPayments ask their cart filters about the session cart there.
add_filter( 'woocommerce_is_checkout', '__return_true' );
$GLOBALS['wp']->query_vars['order-pay'] = 1;
navexa_w4_check( is_checkout_pay_page() && true === apply_filters( 'wc_stripe_show_payment_request_on_checkout', true, null ) && true === apply_filters( 'wcpay_payment_request_is_cart_supported', true, $restricted ), 'pay-for-order: Stripe and WooPayments buttons as the gateway decides, whatever the session cart holds' );
unset( $GLOBALS['wp']->query_vars['order-pay'] );
remove_filter( 'woocommerce_is_checkout', '__return_true' );

update_option( Options::HIDE_EXPRESS, 'no' );
navexa_w4_check( true === apply_filters( 'wc_stripe_show_payment_request_on_cart', true ) && true === apply_filters( 'wcpay_payment_request_is_product_supported', true, $restricted ), 'setting off: nothing hidden' );
delete_option( Options::HIDE_EXPRESS );

if ( class_exists( 'WC_Stripe_Express_Checkout_Helper' ) ) {
	// WooCommerce Stripe Gateway's own helper, with our filters in place.
	global $post;
	$helper = new WC_Stripe_Express_Checkout_Helper();
	update_option( Options::HIDE_EXPRESS, 'no' );
	$post             = get_post( $restricted->get_id() );
	$baseline_product = $helper->should_show_ece_on_product_pages();
	$baseline_cart    = $helper->should_show_ece_on_cart_page();
	delete_option( Options::HIDE_EXPRESS );
	WP_CLI::log( sprintf( 'info Stripe %s baseline (hiding off): product=%s cart=%s', defined( 'WC_STRIPE_VERSION' ) ? WC_STRIPE_VERSION : '?', var_export( $baseline_product, true ), var_export( $baseline_cart, true ) ) );
	navexa_w4_check( false === $helper->should_show_ece_on_product_pages(), 'Stripe helper: no ECE on a restricted product page' );
	navexa_w4_check( false === $helper->should_show_ece_on_cart_page(), 'Stripe helper: no ECE on the cart page for a restricted cart' );
	navexa_w4_check( false === $helper->should_show_ece_on_checkout_page(), 'Stripe helper: no ECE on the checkout page for a restricted cart' );
	$post = get_post( $exempt->get_id() );
	navexa_w4_check( $baseline_product === $helper->should_show_ece_on_product_pages(), 'Stripe helper: an exempt product page is as Stripe decides' );
	$post = null;
}

// Exempt cart: everything as the gateways decide.
navexa_w4_cart( $exempt->get_id() );
navexa_w4_check( true === apply_filters( 'wc_stripe_show_payment_request_on_cart', true ) && false === apply_filters( 'woocommerce_paypal_payments_buttons_disabled', false, 'cart' ), 'an exempt cart keeps express buttons' );
ob_start();
do_action( 'woocommerce_widget_shopping_cart_after_buttons' );
navexa_w4_check( false !== strpos( ob_get_clean(), 'ppc-button-minicart' ), 'PayPal mini-cart wrapper rendered for an exempt cart' );

// --- The fallback: express orders that bypass both checkout gates are held ------
$fixtures = array( 'held' => array() );
foreach ( array( 'ppcp-gateway' => 'PayPal Pay now / App Switch', 'woocommerce_payments' => 'WooPayments', 'stripe' => 'Stripe' ) as $method => $label ) {
	$order = wc_create_order();
	$order->add_product( $restricted, 1 );
	$order->set_address( navexa_w4_us_address(), 'shipping' );
	$order->set_address( array_merge( navexa_w4_us_address(), array( 'email' => 'w4-privacy@example.com' ) ), 'billing' );
	$order->set_payment_method( $method );
	$order->calculate_totals();
	$order->save();
	$order->payment_complete( 'W4-' . strtoupper( $method ) );
	$order = wc_get_order( $order->get_id() );
	navexa_w4_check( Hold::STATUS === $order->get_status() && 'yes' === Meta::get( $order, Meta::HELD ), "{$label}-style order created outside checkout and paid: held" );
	$fixtures['held'][] = $order->get_id();
}

// One held order bound to a verification: the eraser must leave it alone.
$bound_held = wc_get_order( $fixtures['held'][0] );
Meta::set( $bound_held, Meta::VERIFICATION_ID, 'acv_w4held' );
$bound_held->save_meta_data();

// --- Turnstile: Cloudflare's siteverify with its test keys -----------------------
update_option( Options::TURNSTILE, 'yes' );
update_option( Options::TURNSTILE_SITE_KEY, '1x00000000000000000000AA' );
update_option( Options::TURNSTILE_SECRET, '1x0000000000000000000000000000000AA', false );
$verdict = Turnstile::verify( 'XXXX.DUMMY.TOKEN.XXXX', '198.51.100.7' );
if ( Turnstile::UNAVAILABLE === $verdict ) {
	WP_CLI::warning( 'Cloudflare siteverify unreachable from this container: real-endpoint Turnstile checks skipped' );
} else {
	navexa_w4_check( Turnstile::OK === $verdict, 'Turnstile: Cloudflare\'s always-pass test secret passes' );
	update_option( Options::TURNSTILE_SECRET, '2x0000000000000000000000000000000AA', false );
	navexa_w4_check( Turnstile::FAILED === Turnstile::verify( 'XXXX.DUMMY.TOKEN.XXXX' ), 'Turnstile: Cloudflare\'s always-fail test secret fails closed' );
	update_option( Options::TURNSTILE_SECRET, '1x0000000000000000000000000000000AA', false );

	navexa_w4_cart( $restricted->get_id() );
	Start::reset();
	$refused = Start::answer( array( 'v' => 1, 'action' => 'start' ) );
	navexa_w4_check( 'refused' === $refused['status'] && Start::turnstile_message() === $refused['message'], 'Turnstile on: a start without a token is refused' );
	$started = Start::answer( array( 'v' => 1, 'action' => 'start', 'turnstile' => 'XXXX.DUMMY.TOKEN.XXXX' ) );
	navexa_w4_check( 'pending' === $started['status'] && ! empty( $started['hosted_url'] ), 'Turnstile on: a start with a token Cloudflare accepts reaches AgeCheck (got ' . $started['status'] . ')' );
}
update_option( Options::TURNSTILE, 'no' );
delete_option( Options::TURNSTILE_SECRET );

// --- Privacy: exporter and eraser through WordPress's registries ---------------
$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
navexa_w4_check( isset( $exporters['navexa-agecheck'], $erasers['navexa-agecheck'] ), 'privacy exporter and eraser registered' );
$privacy = wc_create_order();
$privacy->add_product( $restricted, 1 );
$privacy->set_billing_email( 'w4-privacy@example.com' );
$privacy->save();
Meta::set( $privacy, Meta::VERIFICATION_ID, 'acv_w4privacy' );
Meta::set( $privacy, Meta::REQUIRED, 'yes' );
Meta::set( $privacy, Meta::SHIP_TO_HASH, str_repeat( 'b', 64 ) );
Meta::mark_released( $privacy, Meta::STATUS_CLEARED ); // Released first: the backstop refuses completing an unreleased one.
$privacy->set_status( 'completed' );
$privacy->save();
navexa_w4_check( 'completed' === wc_get_order( $privacy->get_id() )->get_status(), 'privacy fixture: a cleared, completed order' );
$export = navexa_w4_all_pages( $exporters['navexa-agecheck']['callback'], 'w4-privacy@example.com' );
$found  = false;
foreach ( $export['data'] as $item ) {
	$found = $found || in_array( 'acv_w4privacy', array_column( $item['data'], 'value' ), true );
}
navexa_w4_check( $found && isset( $export['pages'] ), 'the exporter returns the order\'s verification id (' . ( isset( $export['pages'] ) ? $export['pages'] : '?' ) . ' page(s))' );
update_option( 'woocommerce_erasure_request_removes_order_data', 'no' );
$kept = navexa_w4_all_pages( $erasers['navexa-agecheck']['callback'], 'w4-privacy@example.com' );
navexa_w4_check( $kept['items_retained'] && ! $kept['items_removed'] && 'acv_w4privacy' === Meta::get( wc_get_order( $privacy->get_id() ), Meta::VERIFICATION_ID ), 'the eraser keeps order data while WooCommerce does' );
navexa_w4_check( $kept['items_retained'] && count( $kept['messages'] ) >= 2, 'held orders and the WooCommerce setting are reported as kept' );
update_option( 'woocommerce_erasure_request_removes_order_data', 'yes' );
$erased = navexa_w4_all_pages( $erasers['navexa-agecheck']['callback'], 'w4-privacy@example.com' );
$after  = wc_get_order( $privacy->get_id() );
navexa_w4_check( $erased['items_removed'] && '' === Meta::get( $after, Meta::VERIFICATION_ID ) && '' === Meta::get( $after, Meta::SHIP_TO_HASH ), 'the eraser removes the verification id and ship-to hash' );
navexa_w4_check( Meta::is_released( $after ) && 'yes' === Meta::get( $after, Meta::REQUIRED ), 'the release state survives erasure' );
$held_after = wc_get_order( $fixtures['held'][0] );
navexa_w4_check( Hold::STATUS === $held_after->get_status() && 'acv_w4held' === Meta::get( $held_after, Meta::VERIFICATION_ID ), 'the eraser leaves a held order\'s age check details alone' );
update_option( 'woocommerce_erasure_request_removes_order_data', 'no' );

// --- Privacy: every registered eraser in order, as wp-admin runs them ----------
$erase_email = 'w4-erase@example.com';
$ship_to     = array( 'first_name' => 'Sam', 'last_name' => 'Roe', 'address_1' => '100 Main St', 'address_2' => '', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country' => 'US' );
$erase_order = static function ( $status, $vid ) use ( $restricted, $erase_email, $ship_to ) {
	$order = wc_create_order();
	$order->add_product( $restricted, 1 );
	$order->set_address( $ship_to, 'shipping' );
	$order->set_address( array_merge( $ship_to, array( 'email' => $erase_email ) ), 'billing' );
	$order->calculate_totals();
	$order->save();
	Meta::set( $order, Meta::VERIFICATION_ID, $vid );
	Meta::set( $order, Meta::REQUIRED, 'yes' );
	Meta::set( $order, Meta::SHIP_TO_HASH, str_repeat( 'c', 64 ) );
	Meta::mark_released( $order, Meta::STATUS_CLEARED );
	$order->set_status( $status );
	$order->save();
	return $order->get_id();
};
$erase_done = $erase_order( 'completed', 'acv_w4erase_done' );
$erase_open = $erase_order( 'processing', 'acv_w4erase_open' );
$erase_held = wc_create_order();
$erase_held->add_product( $restricted, 1 );
$erase_held->set_address( $ship_to, 'shipping' );
$erase_held->set_address( array_merge( $ship_to, array( 'email' => $erase_email ) ), 'billing' );
$erase_held->set_payment_method( 'cod' );
$erase_held->calculate_totals();
$erase_held->save();
$erase_held->payment_complete();
$erase_held = wc_get_order( $erase_held->get_id() );
Meta::set( $erase_held, Meta::VERIFICATION_ID, 'acv_w4erase_held' );
$erase_held->save_meta_data();
navexa_w4_check( Hold::STATUS === $erase_held->get_status(), 'erasure fixture: a held order' );

update_option( 'woocommerce_erasure_request_removes_order_data', 'yes' );
$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
$order_of = array_flip( array_keys( $erasers ) );
navexa_w4_check( isset( $order_of['navexa-agecheck'], $order_of['woocommerce-customer-orders'] ) && $order_of['navexa-agecheck'] < $order_of['woocommerce-customer-orders'], 'this eraser runs before WooCommerce\'s order eraser (' . implode( ', ', array_keys( $erasers ) ) . ')' );
$answers = array();
foreach ( $erasers as $eraser_id => $eraser ) {
	$answers[ $eraser_id ] = navexa_w4_all_pages( $eraser['callback'], $erase_email );
}
update_option( 'woocommerce_erasure_request_removes_order_data', 'no' );
$done = wc_get_order( $erase_done );
$open = wc_get_order( $erase_open );
$held = wc_get_order( $erase_held->get_id() );
navexa_w4_check( ! empty( $answers['navexa-agecheck']['items_removed'] ) && ! empty( $answers['navexa-agecheck']['items_retained'] ), 'full erasure: this eraser found the requester\'s orders and reported removed and kept ones' );
navexa_w4_check( '' === Meta::get( $done, Meta::VERIFICATION_ID ) && '' === Meta::get( $done, Meta::SHIP_TO_HASH ) && Meta::is_released( $done ), 'full erasure: a completed order loses its verification id and ship-to hash, keeps its release state' );
navexa_w4_check( '100 Main St' !== $done->get_shipping_address_1() && '78701' !== $done->get_shipping_postcode() && $erase_email !== $done->get_billing_email(), 'full erasure: WooCommerce anonymized the completed order (shipping ' . $done->get_shipping_address_1() . ', ' . $done->get_shipping_postcode() . ')' );
navexa_w4_check( '100 Main St' !== $open->get_shipping_address_1() && 'Austin' !== $open->get_shipping_city() && '78701' !== $open->get_shipping_postcode(), 'full erasure: the address guard did not undo WooCommerce\'s anonymized ship-to of a bound order (shipping ' . $open->get_shipping_address_1() . ', ' . $open->get_shipping_postcode() . ')' );
navexa_w4_check( 'acv_w4erase_open' === Meta::get( $open, Meta::VERIFICATION_ID ) && 'acv_w4erase_held' === Meta::get( $held, Meta::VERIFICATION_ID ) && Hold::STATUS === $held->get_status(), 'full erasure: an order that can still ship and a held order keep their verification' );
$anonymized = $open->get_shipping_address_1();
$open->set_shipping_address_1( '200 Unverified Ave' );
$open->set_shipping_postcode( '10001' );
$open->save();
$open = wc_get_order( $erase_open );
navexa_w4_check( $anonymized === $open->get_shipping_address_1() && AddressGuard::locked( $open ), 'after erasure a ship-to change on the open order is still undone' );

// --- i18n: JS translations wiring ----------------------------------------------
Frontend::register_scripts();
$scripts = wp_scripts();
navexa_w4_check( isset( $scripts->registered[ Frontend::HANDLE_BLOCK ] ) && 'navexa-agecheck-for-woocommerce' === $scripts->registered[ Frontend::HANDLE_BLOCK ]->textdomain, 'checkout block script has the text domain for JS translations' );
$block = WP_Block_Type_Registry::get_instance()->get_registered( 'navexa-agecheck/verify-age' );
$editor_handle = $block && ! empty( $block->editor_script_handles ) ? $block->editor_script_handles[0] : '';
navexa_w4_check( '' !== $editor_handle && isset( $scripts->registered[ $editor_handle ] ) && 'navexa-agecheck-for-woocommerce' === $scripts->registered[ $editor_handle ]->textdomain, 'block editor script has the text domain (block.json textdomain)' );
navexa_w4_check( is_readable( WP_PLUGIN_DIR . '/navexa-agecheck-for-woocommerce/languages/navexa-agecheck-for-woocommerce.pot' ), 'the translation template ships' );

// --- Fixtures for w4-uninstall.php -----------------------------------------------
$exempt->save(); // Keeps its _navexa_agecheck_rule meta.
$fixtures['meta_order'] = $privacy->get_id();
$fixtures['product']    = $exempt->get_id();
$fixtures['hpos']       = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
update_option( 'w4test_fixtures', $fixtures );
navexa_w4_check( (bool) as_get_scheduled_actions( array( 'group' => 'navexa-agecheck', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ), 'ids' ), 'the plugin has pending scheduled actions for the uninstall check' );

if ( $GLOBALS['navexa_w4_failures'] > 0 ) {
	WP_CLI::error( $GLOBALS['navexa_w4_failures'] . ' W4 check(s) failed' );
}
WP_CLI::success( 'W4 checks passed' );
