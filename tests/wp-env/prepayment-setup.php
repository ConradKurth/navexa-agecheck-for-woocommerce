<?php
/**
 * Prepares a wp-env site for tests/wp-env/prepayment.mjs (W2 pre-payment
 * flow over HTTP):
 *
 *   wp eval-file tests/wp-env/prepayment-setup.php
 *
 * A US store with a simple product and a free one, a US shipping zone (flat
 * rate, local pickup and free shipping), cash on delivery, the live fixture keys from the mock engine,
 * pre-payment mode, and a classic (shortcode) checkout page next to the block
 * one. Prints {product, classic_checkout, block_checkout} as JSON. Idempotent.
 *
 * @package Navexa\AgeCheck\Tests
 */

// phpcs:ignoreFile -- test script.

use Navexa\AgeCheck\Options;

$keys = json_decode( file_get_contents( dirname( __DIR__ ) . '/mock-agecheck/keys.json' ), true );

update_option( 'woocommerce_default_country', 'US:IL' );
update_option( 'woocommerce_allowed_countries', 'all' );
update_option( 'woocommerce_ship_to_countries', '' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );

update_option( Options::START_KEY, $keys['liveStart']['key'], false );
update_option( Options::FULFILMENT_KEY, $keys['liveFulfilment']['key'], false );
update_option( Options::MODE, Options::MODE_PRE_PAYMENT );
// The flow script gives each shopper its own X-Real-IP, so per-IP limits
// apply per shopper and one run cannot exhaust the next run's budget.
update_option( Options::TRUSTED_PROXY_HEADER, 'HTTP_X_REAL_IP' );
\Navexa\AgeCheck\RateLimit::install();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . \Navexa\AgeCheck\RateLimit::table() );
\Navexa\AgeCheck\Mode::flush();

$cod = get_option( 'woocommerce_cod_settings', array() );
$cod = is_array( $cod ) ? $cod : array();
update_option( 'woocommerce_cod_settings', array_merge( $cod, array( 'enabled' => 'yes', 'enable_for_methods' => array(), 'enable_for_virtual' => 'yes' ) ) );

$product_id = (int) get_option( 'navexa_agecheck_smoke_product', 0 );
if ( ! $product_id || ! wc_get_product( $product_id ) ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Age-restricted test product' );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product->set_manage_stock( false );
	$product_id = $product->save();
	update_option( 'navexa_agecheck_smoke_product', $product_id );
}

$free_id = (int) get_option( 'navexa_agecheck_smoke_free_product', 0 );
if ( ! $free_id || ! wc_get_product( $free_id ) ) {
	$free = new WC_Product_Simple();
	$free->set_name( 'Free age-restricted sample' );
	$free->set_regular_price( '0' );
	$free->set_status( 'publish' );
	$free_id = $free->save();
	update_option( 'navexa_agecheck_smoke_free_product', $free_id );
}

$zone_id = (int) get_option( 'navexa_agecheck_smoke_zone', 0 );
$zone    = $zone_id ? WC_Shipping_Zones::get_zone( $zone_id ) : false;
if ( ! $zone ) {
	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( 'US (AgeCheck smoke)' );
	$zone->add_location( 'US', 'country' );
	$zone->add_location( 'PR', 'country' );
	$zone->add_location( 'CA', 'country' );
	$zone->save();
	update_option( 'navexa_agecheck_smoke_zone', $zone->get_id() );
}
$methods = array();
foreach ( $zone->get_shipping_methods() as $method ) {
	$methods[] = $method->id;
}
foreach ( array( 'flat_rate', 'local_pickup', 'free_shipping' ) as $method_id ) {
	if ( ! in_array( $method_id, $methods, true ) ) {
		$zone->add_shipping_method( $method_id );
	}
}
foreach ( $zone->get_shipping_methods() as $instance_id => $method ) {
	if ( 'flat_rate' === $method->id ) {
		update_option( 'woocommerce_flat_rate_' . $instance_id . '_settings', array( 'title' => 'Flat rate', 'cost' => '5', 'tax_status' => 'none' ) );
	}
}

$classic_id = (int) get_option( 'navexa_agecheck_smoke_classic_checkout', 0 );
if ( ! $classic_id || ! get_post( $classic_id ) ) {
	$classic_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Classic checkout',
			'post_name'    => 'classic-checkout',
			'post_content' => '[woocommerce_checkout]',
		)
	);
	update_option( 'navexa_agecheck_smoke_classic_checkout', $classic_id );
}
$block_id = (int) get_option( 'navexa_agecheck_smoke_block_checkout', 0 );
if ( ! $block_id ) {
	$block_id = (int) wc_get_page_id( 'checkout' );
	update_option( 'navexa_agecheck_smoke_block_checkout', $block_id );
}

flush_rewrite_rules();
echo wp_json_encode(
	array(
		'product'          => $product_id,
		'free_product'     => $free_id,
		'classic_checkout' => $classic_id,
		'block_checkout'   => $block_id,
	)
) . "\n";
