<?php
/**
 * Sets up the entry age gate for tests/e2e/gate-browser.mjs:
 *
 *   wp eval-file tests/wp-env/gate-setup.php <on|off> [site|restricted] [days] [exit|noexit]
 *
 * Turns the gate on or off with the given scope (site), remember-days (30)
 * and exit URL (exit: a "Too young" page on this site; noexit: none). Uses
 * the live fixture keys from the mock engine and asks it once for the
 * minimum age and display name (Mode, which stores them for the gate). Makes
 * an age-restricted product, an exempt one and a product category once.
 * Prints {home, exit_url, product_url, exempt_url, category_url, login_url,
 * return_url, rest_url} as JSON.
 *
 * @package Navexa\AgeCheck\Tests
 */

use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Requirement;
use Navexa\AgeCheck\ReturnPage;

$args  = isset( $args ) && is_array( $args ) ? $args : array();
$on    = isset( $args[0] ) && 'on' === $args[0];
$scope = isset( $args[1] ) && 'restricted' === $args[1] ? Options::GATE_SCOPE_RESTRICTED : Options::GATE_SCOPE_SITE;
$days  = isset( $args[2] ) ? (string) (int) $args[2] : '30';
$exit  = ! isset( $args[3] ) || 'exit' === $args[3];

$keys = json_decode( file_get_contents( dirname( __DIR__ ) . '/mock-agecheck/keys.json' ), true );
update_option( Options::START_KEY, $keys['liveStart']['key'], false );
update_option( Options::FULFILMENT_KEY, $keys['liveFulfilment']['key'], false );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
delete_option( Options::RULE ); // Every product needs a check unless the product says otherwise.

$category = term_exists( 'Gate test vapes', 'product_cat' );
if ( ! $category ) {
	$category = wp_insert_term( 'Gate test vapes', 'product_cat' );
}
$category_id = (int) ( is_array( $category ) ? $category['term_id'] : $category );

$product_id = (int) get_option( 'navexa_agecheck_gate_product', 0 );
if ( ! $product_id || ! wc_get_product( $product_id ) ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Gate restricted product' );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product->set_category_ids( array( $category_id ) );
	$product_id = $product->save();
	update_option( 'navexa_agecheck_gate_product', $product_id );
}
$exempt_id = (int) get_option( 'navexa_agecheck_gate_exempt_product', 0 );
if ( ! $exempt_id || ! wc_get_product( $exempt_id ) ) {
	$exempt = new WC_Product_Simple();
	$exempt->set_name( 'Gate exempt product' );
	$exempt->set_regular_price( '5.00' );
	$exempt->set_status( 'publish' );
	$exempt->update_meta_data( Requirement::PRODUCT_META, Requirement::PRODUCT_EXEMPT );
	$exempt_id = $exempt->save();
	update_option( 'navexa_agecheck_gate_exempt_product', $exempt_id );
}
$exit_page = (int) get_option( 'navexa_agecheck_gate_exit_page', 0 );
if ( ! $exit_page || ! get_post( $exit_page ) ) {
	$exit_page = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Too young',
			'post_content' => 'Come back when you are older.',
		)
	);
	update_option( 'navexa_agecheck_gate_exit_page', $exit_page );
}

update_option( Options::ENTRY_GATE, $on ? 'yes' : 'no' );
update_option( Options::ENTRY_GATE_SCOPE, $scope );
update_option( Options::ENTRY_GATE_DAYS, $days );
update_option( Options::ENTRY_GATE_EXIT_URL, $exit ? Options::exit_url( (string) get_permalink( $exit_page ) ) : '' );

// The minimum age and display name the gate shows: the mock's answer.
Mode::flush();
$info = Mode::info( Client::KEY_START );
if ( null === $info ) {
	fwrite( STDERR, "GetIntegrationInfo failed: is the mock running?\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
}

echo wp_json_encode(
	array(
		'home'         => home_url( '/' ),
		'exit_url'     => Options::entry_gate_exit_url(),
		'product_url'  => get_permalink( $product_id ),
		'exempt_url'   => get_permalink( $exempt_id ),
		'category_url' => get_term_link( $category_id, 'product_cat' ),
		'login_url'    => wp_login_url(),
		'return_url'   => ReturnPage::url( true ),
		'rest_url'     => rest_url(),
		'minimum_age'  => Options::store_minimum_age(),
		'display_name' => Options::store_display_name(),
	)
) . "\n";
