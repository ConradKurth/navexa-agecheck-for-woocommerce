<?php
/**
 * wp-env smoke test for the W0 scaffold, the W2 pre-payment pieces and the
 * W1 hold core (smoke-hold.php), run inside WordPress:
 *
 *   npm run smoke   (wp eval-file .../tests/wp-env/smoke.php)
 *
 * Needs the plugin active, WooCommerce active and the mock AgeCheck engine
 * (tests/mock-agecheck/server.mjs --host 0.0.0.0) reachable at
 * NAVEXA_AGECHECK_API_URL. Set NAVEXA_AGECHECK_SMOKE_HPOS=yes|no to also
 * assert which order storage is active. Exits non-zero on the first failure.
 *
 * Checks what the unit suite can only stub: activation state and autoload
 * flags, the HPOS and block-checkout declarations, WC_Admin_Settings saving
 * and rendering the key fields, the REST settings API not exposing keys,
 * GetIntegrationInfo over the real WordPress HTTP API, and (W2) the rate-limit
 * table, the Store API extension, the return page and the checkout block.
 * tests/wp-env/prepayment.mjs runs the W2 flow itself over HTTP, and
 * tests/wp-env/prepayment-orders.php then follows the orders it placed
 * through binding, the hold and release.
 *
 * @package Navexa\AgeCheck\Tests
 */

// phpcs:ignoreFile -- test script.

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;

// wp eval-file includes this file inside a function, so the counter lives in $GLOBALS.
$GLOBALS['navexa_smoke_failures'] = 0;

function navexa_smoke_check( $ok, $what ) {
	if ( $ok ) {
		WP_CLI::log( 'ok   ' . $what );
		return;
	}
	++$GLOBALS['navexa_smoke_failures'];
	WP_CLI::log( 'FAIL ' . $what );
}

function navexa_smoke_autoload( $name ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
}

function navexa_smoke_mock( $path, $body ) {
	$response = wp_remote_post(
		rtrim( NAVEXA_AGECHECK_API_URL, '/' ) . $path,
		array(
			'timeout' => 5,
			'body'    => wp_json_encode( $body ),
		)
	);
	navexa_smoke_check( 200 === wp_remote_retrieve_response_code( $response ), "mock control {$path}" );
}

$keys = json_decode( file_get_contents( dirname( __DIR__ ) . '/mock-agecheck/keys.json' ), true );
$plugin_basename = 'navexa-agecheck-for-woocommerce/navexa-agecheck-for-woocommerce.php';
wp_set_current_user( 1 );

// Activation state.
navexa_smoke_check( is_plugin_active( $plugin_basename ), 'plugin active' );
navexa_smoke_check( 1 === preg_match( '/^[0-9a-f]{32}$/', Install::install_id() ), 'install_id is 128-bit hex' );
navexa_smoke_check( 32 === strlen( Install::guard_secret() ), 'guard secret is 32 bytes' );
navexa_smoke_check( in_array( navexa_smoke_autoload( Install::OPTION_GUARD_SECRET ), array( 'no', 'off' ), true ), 'guard secret not autoloaded' );
navexa_smoke_check( Install::activated_at() > 0, 'activated_at set' );
navexa_smoke_check( ! Install::is_paused(), 'not paused on the activated site' );

// Feature declarations.
foreach ( array( 'custom_order_tables', 'cart_checkout_blocks' ) as $feature ) {
	$plugins = FeaturesUtil::get_compatible_plugins_for_feature( $feature );
	navexa_smoke_check( in_array( $plugin_basename, $plugins['compatible'] ?? array(), true ), "declared compatible with {$feature}" );
}
$hpos = getenv( 'NAVEXA_AGECHECK_SMOKE_HPOS' );
if ( in_array( $hpos, array( 'yes', 'no' ), true ) ) {
	$enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	navexa_smoke_check( ( 'yes' === $hpos ) === $enabled, "HPOS usage is {$hpos}" );
}

// Settings: save through WC_Admin_Settings, then render.
include_once WC()->plugin_path() . '/includes/admin/class-wc-admin-settings.php';
$page = null;
foreach ( WC_Admin_Settings::get_settings_pages() as $candidate ) {
	if ( 'navexa_agecheck' === $candidate->get_id() ) {
		$page = $candidate;
	}
}
navexa_smoke_check( null !== $page, 'AgeCheck settings tab registered' );
if ( null === $page ) {
	WP_CLI::halt( 1 );
}
$fields = $page->get_settings_for_section( '' );

delete_option( Options::START_KEY );
delete_option( Options::FULFILMENT_KEY );
WC_Admin_Settings::save_fields(
	$fields,
	array(
		Options::START_KEY      => $keys['liveStart']['key'],
		Options::FULFILMENT_KEY => $keys['liveFulfilment']['key'],
		Options::MODE           => Options::MODE_ACCEPT_AND_HOLD,
	)
);
navexa_smoke_check( $keys['liveStart']['key'] === get_option( Options::START_KEY ), 'start key saved' );
navexa_smoke_check( in_array( navexa_smoke_autoload( Options::START_KEY ), array( 'no', 'off' ), true ), 'start key not autoloaded' );
navexa_smoke_check( Options::MODE_ACCEPT_AND_HOLD === Options::mode(), 'mode saved' );
navexa_smoke_check( false === get_option( 'navexa_agecheck_status' ), 'status pseudo-field not saved' );

WC_Admin_Settings::save_fields(
	$fields,
	array(
		Options::START_KEY      => '',
		Options::FULFILMENT_KEY => 'not-a-key',
		Options::MODE           => 'fail_open',
	)
);
navexa_smoke_check( $keys['liveStart']['key'] === get_option( Options::START_KEY ), 'blank submission keeps the start key' );
navexa_smoke_check( $keys['liveFulfilment']['key'] === get_option( Options::FULFILMENT_KEY ), 'malformed key refused' );
navexa_smoke_check( Options::DEFAULT_MODE === Options::mode(), 'unknown mode falls back to the default' );

ob_start();
WC_Admin_Settings::output_fields( $fields );
$html = ob_get_clean();
navexa_smoke_check( false === strpos( $html, $keys['liveStart']['key'] ) && false === strpos( $html, $keys['liveFulfilment']['key'] ), 'rendered settings never contain a key' );
navexa_smoke_check( false !== strpos( $html, 'txk_1111…' ), 'rendered settings show the masked key' );

$rest     = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/settings/navexa_agecheck' ) );
$rest_ids = 200 === $rest->get_status() ? wp_list_pluck( $rest->get_data(), 'id' ) : array();
navexa_smoke_check( in_array( Options::MODE, $rest_ids, true ), 'REST settings group exists (' . $rest->get_status() . ': ' . implode( ',', $rest_ids ) . ')' );
navexa_smoke_check( false === strpos( wp_json_encode( $rest->get_data() ), $keys['liveStart']['key'] ) && ! in_array( Options::START_KEY, $rest_ids, true ), 'REST settings API does not expose keys' );

// GetIntegrationInfo over the WordPress HTTP API, against the mock.
navexa_smoke_mock( '/__mock/reset', new stdClass() );
navexa_smoke_mock( '/__mock/config', array( 'org' => array( 'allowedOrigins' => array( Mode::store_origin() ) ) ) );
Mode::flush();
$result = Mode::lookup( Client::KEY_FULFILMENT, null, true );
navexa_smoke_check( null !== $result['info'], 'GetIntegrationInfo answered (' . $result['error'] . ')' );
if ( null !== $result['info'] ) {
	navexa_smoke_check( $result['info']->livemode, 'live key reads as live' );
	navexa_smoke_check( $result['info']->origin_allowed, 'store origin allowed' );
	navexa_smoke_check( array() === $result['info']->missing_scopes( Client::KEY_FULFILMENT ), 'fulfilment scopes present' );
	navexa_smoke_check( 21 === $result['info']->minimum_age, 'minimum age 21' );
}
update_option( Options::START_KEY, $keys['testStart']['key'], false );
Mode::flush();
$navexa_smoke_test_info = Mode::info( Client::KEY_START );
navexa_smoke_check( null !== $navexa_smoke_test_info && false === $navexa_smoke_test_info->livemode, 'test key reads as test mode' );
navexa_smoke_check( Mode::is_test_mode(), 'TEST MODE banner condition' );
navexa_smoke_check( in_array( navexa_smoke_autoload( Mode::OPTION_LAST_MODE_PREFIX . Client::KEY_START ), array( 'yes', 'on' ), true ), 'last known mode autoloaded' );
// The hour is up: the cached answers expire, the banner stays.
$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE %s", $GLOBALS['wpdb']->esc_like( '_transient_' ) . '%' . $GLOBALS['wpdb']->esc_like( Mode::TRANSIENT_PREFIX ) . '%' ) );
wp_cache_flush();
navexa_smoke_check( Mode::is_test_mode(), 'TEST MODE banner outlives the cache' );

navexa_smoke_mock( '/__mock/config', array( 'integrationInfo' => 'absent' ) );
Mode::flush();
navexa_smoke_check( 'unimplemented' === Mode::lookup( Client::KEY_FULFILMENT )['error'], 'engine without the RPC fails closed as unimplemented' );
navexa_smoke_mock( '/__mock/reset', new stdClass() );

// Clone detection. Multilingual plugins (TranslatePress, WPML) filter home_url
// per request; that is not a move.
$navexa_smoke_origin   = Mode::store_origin();
$navexa_smoke_language = static function ( $url ) {
	return rtrim( $url, '/' ) . '/es';
};
add_filter( 'home_url', $navexa_smoke_language );
navexa_smoke_check( ! Install::is_paused(), 'a home_url filter (language path) does not pause' );
navexa_smoke_check( $navexa_smoke_origin === Mode::store_origin(), 'a home_url filter does not change the store origin' );
remove_filter( 'home_url', $navexa_smoke_language );

// A copied site changes the home option itself (search-replace or WP_HOME).
$navexa_smoke_copy = static function () {
	return 'https://copy.example.test';
};
add_filter( 'pre_option_home', $navexa_smoke_copy );
navexa_smoke_check( Install::is_paused(), 'a changed home option pauses' );
$before = Install::install_id();
try {
	Client::for_key( Client::KEY_FULFILMENT )->call( 'GetIntegrationInfo' );
	navexa_smoke_check( false, 'paused client refuses' );
} catch ( \Navexa\AgeCheck\ConnectError $e ) {
	navexa_smoke_check( 'install_unconfirmed' === $e->local_reason(), 'paused client refuses' );
}

// The confirm handler refuses a bad nonce before rotating anything.
$navexa_smoke_die = static function () {
	return static function () {
		throw new RuntimeException( 'wp_die' );
	};
};
add_filter( 'wp_die_handler', $navexa_smoke_die, PHP_INT_MAX );
$_REQUEST['_wpnonce'] = 'not-a-nonce';
try {
	Install::handle_confirm();
	navexa_smoke_check( false, 'confirm handler refuses a bad nonce' );
} catch ( RuntimeException $e ) {
	navexa_smoke_check( 'wp_die' === $e->getMessage() && $before === Install::install_id() && Install::is_paused(), 'confirm handler refuses a bad nonce' );
}
unset( $_REQUEST['_wpnonce'] );
remove_filter( 'wp_die_handler', $navexa_smoke_die, PHP_INT_MAX );

navexa_smoke_check( Install::confirm_site() && $before !== Install::install_id() && ! Install::is_paused(), 'confirming rotates the install_id and unpauses' );
remove_filter( 'pre_option_home', $navexa_smoke_copy );
navexa_smoke_check( Install::is_paused() && Install::confirm_site() && ! Install::is_paused(), 'moving back pauses again; re-confirmed for the next run' );

// W2: the rate-limit table and its atomic counter in MySQL.
navexa_smoke_check( \Navexa\AgeCheck\RateLimit::DB_VERSION === get_option( \Navexa\AgeCheck\RateLimit::OPTION_DB_VERSION ), 'rate-limit table version recorded' );
navexa_smoke_check( \Navexa\AgeCheck\RateLimit::table() === $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', \Navexa\AgeCheck\RateLimit::table() ) ), 'rate-limit table exists' );
navexa_smoke_check( \Navexa\AgeCheck\RateLimit::table_exists(), 'RateLimit::table_exists() finds it (escaped LIKE)' );
$navexa_smoke_subject = 'smoke-' . wp_generate_password( 12, false );
$navexa_smoke_hits    = array();
for ( $i = 0; $i < 3; $i++ ) {
	$navexa_smoke_hits[] = \Navexa\AgeCheck\RateLimit::hit( 'smoke', $navexa_smoke_subject, 2, 3600 );
}
navexa_smoke_check( array( true, true, false ) === $navexa_smoke_hits, 'rate limit counts in MySQL' );

// W2: the Store API extension, the return page and the checkout block.
$navexa_smoke_extend = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get( \Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema::class );
navexa_smoke_check( is_callable( $navexa_smoke_extend->get_update_callback( 'navexa-agecheck' ) ), 'Store API update callback registered' );
$navexa_smoke_cart = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/cart' ) );
$navexa_smoke_data = 200 === $navexa_smoke_cart->get_status() ? json_decode( wp_json_encode( $navexa_smoke_cart->get_data() ), true ) : array();
navexa_smoke_check( isset( $navexa_smoke_data['extensions']['navexa-agecheck']['required'] ), 'cart response carries navexa-agecheck data (' . $navexa_smoke_cart->get_status() . ')' );
navexa_smoke_check( 0 === strpos( \Navexa\AgeCheck\ReturnPage::url( true ), Install::home() ) && false !== strpos( \Navexa\AgeCheck\ReturnPage::url( true ), 'wc-api=navexa_agecheck_return&popup=1' ), 'return page URL on the store address' );
navexa_smoke_check( WP_Block_Type_Registry::get_instance()->is_registered( 'navexa-agecheck/verify-age' ), 'Verify age block registered' );
\Navexa\AgeCheck\Frontend::register_scripts();
foreach ( array( \Navexa\AgeCheck\Frontend::HANDLE_LOADER, \Navexa\AgeCheck\Frontend::HANDLE_CLASSIC, \Navexa\AgeCheck\Frontend::HANDLE_BLOCK ) as $navexa_smoke_handle ) {
	navexa_smoke_check( wp_script_is( $navexa_smoke_handle, 'registered' ), "script {$navexa_smoke_handle} registered" );
}
navexa_smoke_check( has_action( 'woocommerce_checkout_validate_order_before_payment', array( \Navexa\AgeCheck\Checkout\Blocks::class, 'validate' ) ) && has_action( 'woocommerce_after_checkout_validation', array( \Navexa\AgeCheck\Checkout\Classic::class, 'validate' ) ), 'checkout validation hooks wired' );
navexa_smoke_check( has_action( 'woocommerce_checkout_validate_order_before_payment', array( \Navexa\AgeCheck\Checkout\OrderPay::class, 'validate_existing_order' ) ), 'order-pay validation wired next to the block checkout gate' );

// W1: holds, binding, release, webhook, sweep, shipment.
require __DIR__ . '/smoke-hold.php';

delete_option( Options::START_KEY );
delete_option( Options::FULFILMENT_KEY );
delete_option( Options::MODE );

if ( $GLOBALS['navexa_smoke_failures'] > 0 ) {
	WP_CLI::error( $GLOBALS['navexa_smoke_failures'] . ' smoke check(s) failed' );
}
WP_CLI::success( 'smoke checks passed' );
