<?php
/**
 * W3 PRs 12 and 14 inside a real WordPress + WooCommerce (wp-env):
 *
 *   wp eval-file tests/wp-env/w3b.php        (after prepayment-setup.php)
 *
 * - Verification links (E8c) on real held orders against the mock engine:
 *   the release run's send (link id, expiry, return URL without the order
 *   key, an order note, once per verification), staff's resend and withdraw
 *   through the Order actions handlers with a real nonce and capability,
 *   the 5-a-day budget, the refusals (links off for the account, no store
 *   display name) recorded once for the store with the merchant notice and
 *   no further calls, the return step queueing a release run, and the
 *   wp-config kill switch last (a constant cannot be undefined).
 * - customer_ref (E12a): an account's order-bound create carries the keyed
 *   reference and a guest's none; the CLI cart's start for a signed-in
 *   shopper carries it; with the mock's reuse on, a returning customer's
 *   next order passes as PASS_CLASS_REUSED, clears and is released, and the
 *   admin panel says "Returning customer (reused verification)"; with reuse
 *   off (production) the same order takes a new check.
 *
 * Needs the mock engine at NAVEXA_AGECHECK_API_URL and prepayment-setup.php
 * run first. Exits non-zero on any failure.
 *
 * @package Navexa\AgeCheck\Tests
 */

// phpcs:ignoreFile -- test script.

use Navexa\AgeCheck\Admin\Notices;
use Navexa\AgeCheck\Admin\OrderPanel;
use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\CustomerRef;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Links;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\OrderVerify;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\Start;

$GLOBALS['navexa_w3b_failures'] = 0;

function navexa_w3b_check( $ok, $what ) {
	if ( $ok ) {
		WP_CLI::log( 'ok   ' . $what );
		return;
	}
	++$GLOBALS['navexa_w3b_failures'];
	WP_CLI::log( 'FAIL ' . $what );
}

function navexa_w3b_mock( $path, $body = null ) {
	$url      = rtrim( NAVEXA_AGECHECK_API_URL, '/' ) . $path;
	$response = null === $body ? wp_remote_get( $url, array( 'timeout' => 5 ) ) : wp_remote_post( $url, array( 'timeout' => 5, 'body' => wp_json_encode( $body ) ) );
	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		navexa_w3b_check( false, "mock {$path}" );
	}
	return json_decode( wp_remote_retrieve_body( $response ), true );
}

/** Calls of $rpc the mock recorded, in order. */
function navexa_w3b_calls( $rpc ) {
	return array_values(
		array_filter(
			navexa_w3b_mock( '/__mock/requests' ),
			static function ( $request ) use ( $rpc ) {
				return $rpc === $request['rpc'];
			}
		)
	);
}

function navexa_w3b_reload( $order ) {
	wp_cache_flush();
	return wc_get_order( is_object( $order ) ? $order->get_id() : $order );
}

function navexa_w3b_has_note( $order, $needle ) {
	foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
		if ( false !== strpos( $note->content, $needle ) ) {
			return true;
		}
	}
	return false;
}

/** A paid, held order of $product for $customer (0: a guest). */
function navexa_w3b_held( $product, $customer = 0, $email = 'ada@example.com' ) {
	$order   = wc_create_order( array( 'status' => 'pending', 'customer_id' => $customer ) );
	$order->add_product( $product, 1 );
	$address = array(
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'address_1'  => '1 Main St',
		'city'       => 'Springfield',
		'state'      => 'IL',
		'postcode'   => '62701',
		'country'    => 'US',
		'email'      => $email,
	);
	$order->set_address( $address, 'billing' );
	$order->set_address( $address, 'shipping' );
	$order->set_payment_method( 'bacs' );
	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_id( 'flat_rate' );
	$shipping->set_total( '5' );
	$order->add_item( $shipping );
	$order->calculate_totals();
	$order->save();
	$order->payment_complete( 'txn_w3b_' . wp_generate_password( 6, false ) );
	return navexa_w3b_reload( $order );
}

/** Runs an Order actions handler as WooCommerce does after saving the order form. */
function navexa_w3b_order_action( $handler, $order, $nonce = true ) {
	$_POST = $nonce ? array( 'woocommerce_meta_nonce' => wp_create_nonce( 'woocommerce_save_data' ) ) : array();
	call_user_func( array( OrderPanel::class, $handler ), navexa_w3b_reload( $order ) );
	$_POST   = array();
	$flashed = get_transient( Notices::FLASH_PREFIX . get_current_user_id() );
	delete_transient( Notices::FLASH_PREFIX . get_current_user_id() );
	return is_array( $flashed ) ? (string) end( $flashed ) : '';
}

$keys    = json_decode( file_get_contents( dirname( __DIR__ ) . '/mock-agecheck/keys.json' ), true );
$product = wc_get_product( (int) get_option( 'navexa_agecheck_smoke_product', 0 ) );
if ( ! $product ) {
	WP_CLI::error( 'run prepayment-setup.php first' );
}
update_option( Options::START_KEY, $keys['liveStart']['key'], false );
update_option( Options::FULFILMENT_KEY, $keys['liveFulfilment']['key'], false );
update_option( Options::MODE, Options::MODE_ACCEPT_AND_HOLD );
delete_option( Links::OPTION_BLOCK );
navexa_w3b_mock( '/__mock/reset', new stdClass() );
navexa_w3b_mock( '/__mock/config', array( 'org' => array( 'allowedOrigins' => array( Mode::store_origin() ) ), 'links' => 'on' ) );
Mode::flush();

// Order actions run as a shop manager on an order screen (Notices::flash needs is_admin()).
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen( 'edit-shop_order' );

WP_CLI::log( '--- W3 PR 12: verification links ---' );

// 1. The release run emails the shopper once, with a return URL that carries no order key.
$a = navexa_w3b_held( $product );
navexa_w3b_check( Hold::STATUS === $a->get_status(), 'a paid order is held (' . $a->get_status() . ')' );
$outcome = Release::run( $a->get_id(), Release::TRIGGER_HOLD );
$a       = navexa_w3b_reload( $a );
$vid_a   = $a->get_meta( Meta::VERIFICATION_ID );
navexa_w3b_check( Release::PENDING === $outcome && 'sent:' . $vid_a === $a->get_meta( Meta::LINK ), 'the release run emailed a link (' . $outcome . ', ' . $a->get_meta( Meta::LINK ) . ')' );
navexa_w3b_check( 1 === preg_match( '/^acl_mock\d{6}$/', $a->get_meta( Meta::LINK_ID ) ), 'link id recorded (' . $a->get_meta( Meta::LINK_ID ) . ')' );
navexa_w3b_check( abs( (int) $a->get_meta( Meta::LINK_EXPIRES_AT ) - ( time() + 7 * DAY_IN_SECONDS ) ) < 120, 'link expiry recorded, 7 days out' );
navexa_w3b_check( '' === $a->get_meta( Meta::LINK_KEY ), 'the idempotency key goes once the answer is recorded' );
navexa_w3b_check( navexa_w3b_has_note( $a, 'emailed the shopper a link to verify their age (' . $a->get_meta( Meta::LINK_ID ) ), 'order note names the link' );
$sends = navexa_w3b_calls( 'SendVerificationLink' );
$send  = end( $sends );
navexa_w3b_check( 1 === count( $sends ) && 'liveFulfilment' === $send['key'] && $vid_a === $send['body']['verificationId'], 'SendVerificationLink with the fulfilment key' );
navexa_w3b_check( 0 === strpos( $send['body']['returnUrl'], OrderVerify::endpoint() . '&step=return&order=' . $a->get_id() . '&t=' ) && false === strpos( $send['body']['returnUrl'], 'wc_order_' ), 'its return URL is the plugin\'s return step, never the order key' );
$rows = OrderPanel::rows( $a );
navexa_w3b_check( isset( $rows['Verification link'] ) && 0 === strpos( $rows['Verification link'], 'Emailed to the shopper on ' ), 'the panel shows the link (' . ( $rows['Verification link'] ?? '' ) . ')' );
Release::run( $a->get_id(), Release::TRIGGER_SWEEP );
navexa_w3b_check( 1 === count( navexa_w3b_calls( 'SendVerificationLink' ) ), 'the next run emails nobody again' );

// 2. Staff resend: nonce and capability, a new link each time, then the 5-a-day budget.
navexa_w3b_order_action( 'action_resend', $a, false );
navexa_w3b_check( 1 === count( navexa_w3b_calls( 'SendVerificationLink' ) ), 'resend without the order form nonce: no call' );
$navexa_subscriber = wp_insert_user( array( 'user_login' => 'w3b_sub_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $navexa_subscriber );
navexa_w3b_order_action( 'action_resend', $a );
wp_set_current_user( 1 );
navexa_w3b_check( 1 === count( navexa_w3b_calls( 'SendVerificationLink' ) ), 'resend without manage_woocommerce: no call' );
$actions = OrderPanel::order_actions( array(), navexa_w3b_reload( $a ) );
navexa_w3b_check( isset( $actions[ OrderPanel::ACTION_RESEND ], $actions[ OrderPanel::ACTION_WITHDRAW ] ), 'Order actions offer resend and withdraw' );
$first_link = navexa_w3b_reload( $a )->get_meta( Meta::LINK_ID );
$flash      = navexa_w3b_order_action( 'action_resend', $a );
$a          = navexa_w3b_reload( $a );
navexa_w3b_check( false !== strpos( $flash, 'new verification link' ) && $first_link !== $a->get_meta( Meta::LINK_ID ), 'resend emails a new link (' . $flash . ')' );
$state = navexa_w3b_mock( '/__mock/verifications' );
$rows  = array_values( array_filter( $state['links'], static function ( $l ) use ( $vid_a ) { return $l['verificationId'] === $vid_a; } ) );
navexa_w3b_check( 2 === count( $rows ) && 'superseded' === ( $rows[0]['revokedReason'] ?? '' ), 'the earlier link stopped working' );
navexa_w3b_check( navexa_w3b_has_note( $a, 'at a staff member\'s request' ), 'resend noted' );
for ( $i = 0; $i < 3; $i++ ) {
	navexa_w3b_order_action( 'action_resend', $a );
}
$flash = navexa_w3b_order_action( 'action_resend', $a );
navexa_w3b_check( false !== strpos( $flash, 'already emailed this shopper 5 verification links' ), 'the sixth send in a day is refused, nothing sent (' . $flash . ')' );
navexa_w3b_check( 5 === count( array_filter( navexa_w3b_mock( '/__mock/verifications' )['links'], static function ( $l ) use ( $vid_a ) { return $l['verificationId'] === $vid_a; } ) ), 'AgeCheck made five links' );
navexa_w3b_check( 'sent:' . $vid_a === navexa_w3b_reload( $a )->get_meta( Meta::LINK ), 'the order still records the last link' );
navexa_w3b_mock( '/__mock/config', array( 'links' => 'off' ) );
$flash = navexa_w3b_order_action( 'action_resend', $a );
$a     = navexa_w3b_reload( $a );
navexa_w3b_check( false !== strpos( $flash, 'not sending verification emails yet' ) && 'sent:' . $vid_a === $a->get_meta( Meta::LINK ), 'a refused resend keeps the link already emailed on record (' . $flash . ')' );
navexa_w3b_check( isset( OrderPanel::order_actions( array(), $a )[ OrderPanel::ACTION_WITHDRAW ] ) && navexa_w3b_has_note( $a, 'The link emailed earlier still works' ), 'Withdraw stays offered, and the note says why' );
delete_option( Links::OPTION_BLOCK );
navexa_w3b_mock( '/__mock/config', array( 'links' => 'on' ) );

// 3. The return step (where the link brings the shopper back) queues a release run.
$navexa_links = navexa_w3b_mock( '/__mock/verifications' )['links'];
$return       = (string) end( $navexa_links )['returnUrl'];
navexa_w3b_check( OrderVerify::return_url( navexa_w3b_reload( $a ) ) === $return, 'AgeCheck holds the order\'s return step for its link' );
parse_str( (string) wp_parse_url( $return, PHP_URL_QUERY ), $navexa_fields );
as_unschedule_all_actions( '', array(), Release::GROUP ); // Group only: with a hook and no args AS matches args [] alone.
$answer = OrderVerify::respond( 'GET', $navexa_fields );
navexa_w3b_check( 200 === $answer['status'] && ! isset( $answer['redirect'] ), 'an emailed link\'s return shows the finished page, never the order page' );
navexa_w3b_check( ! empty( as_get_scheduled_actions( array( 'hook' => Release::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING, 'args' => array( $a->get_id(), 0, Release::TRIGGER_SHOPPER ) ), 'ids' ) ), 'and queued a release run' );
$navexa_fields['t'] = str_repeat( '0', 32 );
as_unschedule_all_actions( '', array(), Release::GROUP ); // Group only: with a hook and no args AS matches args [] alone.
OrderVerify::respond( 'GET', $navexa_fields );
navexa_w3b_check( empty( as_get_scheduled_actions( array( 'hook' => Release::HOOK, 'group' => Release::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) ), 'a forged return queues nothing' );

// 4. Withdraw.
$flash = navexa_w3b_order_action( 'action_withdraw', $a );
$a     = navexa_w3b_reload( $a );
navexa_w3b_check( false !== strpos( $flash, 'withdrew the emailed verification link' ) && 'withdrawn:' . $vid_a === $a->get_meta( Meta::LINK ), 'withdraw (' . $flash . ')' );
$revokes = navexa_w3b_calls( 'RevokeVerificationLinks' );
navexa_w3b_check( 1 === count( $revokes ) && $vid_a === $revokes[0]['body']['verificationId'], 'RevokeVerificationLinks called' );
$live = array_filter( navexa_w3b_mock( '/__mock/verifications' )['links'], static function ( $l ) use ( $vid_a ) { return $l['verificationId'] === $vid_a && empty( $l['revokedAt'] ); } );
navexa_w3b_check( 0 === count( $live ), 'no link of the verification opens any more' );
navexa_w3b_check( navexa_w3b_has_note( $a, 'withdrew the emailed verification link (1 was still open)' ), 'withdraw noted' );
$before = count( navexa_w3b_calls( 'SendVerificationLink' ) );
Release::run( $a->get_id(), Release::TRIGGER_SWEEP );
navexa_w3b_check( $before === count( navexa_w3b_calls( 'SendVerificationLink' ) ), 'a release run never re-sends a withdrawn link' );

// 5. The shopper passes: the decision revokes links and the order is released.
navexa_w3b_mock( '/__mock/verifications', array( 'id' => $vid_a, 'status' => 'passed' ) );
navexa_w3b_check( Release::RELEASED === Release::run( $a->get_id(), Release::TRIGGER_WEBHOOK ) && 'processing' === navexa_w3b_reload( $a )->get_status(), 'cleared and released' );
navexa_w3b_check( ! isset( OrderPanel::order_actions( array(), navexa_w3b_reload( $a ) )[ OrderPanel::ACTION_RESEND ] ), 'no resend for a released order' );

// 6. Links off at AgeCheck (production today, one switch for every store): held, noted, one call for the store, the notice.
navexa_w3b_mock( '/__mock/config', array( 'links' => 'off' ) );
$b = navexa_w3b_held( $product );
Release::run( $b->get_id(), Release::TRIGGER_HOLD );
$b = navexa_w3b_reload( $b );
navexa_w3b_check( Hold::STATUS === $b->get_status() && 0 === strpos( $b->get_meta( Meta::LINK ), 'links_off:' ), 'links off: held, recorded (' . $b->get_meta( Meta::LINK ) . ')' );
navexa_w3b_check( navexa_w3b_has_note( $b, 'AgeCheck is not sending verification emails yet' ), 'links off noted on the order' );
navexa_w3b_check( '' === (string) $b->get_meta( Meta::NEXT_CHECK_AT ), 'still polled every sweep: the shopper can pass from Verify now' );
$text = Notices::links_text();
navexa_w3b_check( false !== strpos( $text, 'AgeCheck is not sending verification emails yet' ) && false === strpos( $text, 'your account' ), 'merchant notice: ' . $text );
$before = count( navexa_w3b_calls( 'SendVerificationLink' ) );
$c      = navexa_w3b_held( $product );
Release::run( $c->get_id(), Release::TRIGGER_HOLD );
navexa_w3b_check( $before === count( navexa_w3b_calls( 'SendVerificationLink' ) ) && 0 === strpos( navexa_w3b_reload( $c )->get_meta( Meta::LINK ), 'links_off:' ), 'the next held order asks nothing for an hour: no retry storm' );
$flash = navexa_w3b_order_action( 'action_resend', $c );
navexa_w3b_check( false !== strpos( $flash, 'AgeCheck is not sending verification emails yet' ) && $before + 1 === count( navexa_w3b_calls( 'SendVerificationLink' ) ), 'a staff resend still asks, and says why (' . $flash . ')' );

// 7. No store display name.
navexa_w3b_mock( '/__mock/config', array( 'links' => 'on', 'org' => array( 'displayName' => '' ) ) );
delete_option( Links::OPTION_BLOCK );
Mode::flush();
$d = navexa_w3b_held( $product );
Release::run( $d->get_id(), Release::TRIGGER_HOLD );
$d = navexa_w3b_reload( $d );
navexa_w3b_check( 0 === strpos( $d->get_meta( Meta::LINK ), 'no_display_name:' ) && Hold::STATUS === $d->get_status(), 'no display name: held, recorded (' . $d->get_meta( Meta::LINK ) . ')' );
navexa_w3b_check( false !== strpos( Notices::links_text(), 'set your store name in the AgeCheck dashboard' ), 'merchant notice asks for the store name' );
navexa_w3b_mock( '/__mock/config', array( 'org' => array( 'displayName' => 'Mock Store' ) ) );
Mode::flush();
Release::run( $d->get_id(), Release::TRIGGER_ADMIN );
$d = navexa_w3b_reload( $d );
navexa_w3b_check( 0 === strpos( $d->get_meta( Meta::LINK ), 'sent:' ) && array() === Links::store_block(), 'once AgeCheck reports a name the email goes out and the notice goes (' . $d->get_meta( Meta::LINK ) . ')' );
navexa_w3b_check( '' === Notices::links_text(), 'notice gone' );

WP_CLI::log( '--- W3 PR 14: customer_ref and per-store reuse ---' );

// 8. An account's order carries the keyed reference; a guest's none.
$navexa_customer = wp_insert_user( array( 'user_login' => 'w3b_cust_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'customer', 'user_email' => 'w3b-' . wp_generate_password( 6, false ) . '@example.com' ) );
$ref             = CustomerRef::for_user( $navexa_customer );
navexa_w3b_check( 1 === preg_match( '/^wpc1_[0-9a-f]{64}$/', $ref ), 'a keyed reference (' . substr( $ref, 0, 12 ) . '...)' );
$e = navexa_w3b_held( $product, $navexa_customer );
Release::run( $e->get_id(), Release::TRIGGER_HOLD );
$e       = navexa_w3b_reload( $e );
$creates = navexa_w3b_calls( 'CreateVerification' );
$create  = end( $creates );
navexa_w3b_check( $ref === ( $create['body']['customerRef'] ?? '' ) && $e->get_meta( Meta::EXTERNAL_ID ) === $create['body']['orderExternalId'], 'the account\'s order-bound create carries customer_ref' );
$guest   = navexa_w3b_held( $product );
Release::run( $guest->get_id(), Release::TRIGGER_HOLD );
$creates = navexa_w3b_calls( 'CreateVerification' );
navexa_w3b_check( ! isset( end( $creates )['body']['customerRef'] ), 'a guest order carries none' );

// 9. The CLI cart's start for a signed-in shopper (pre-payment) carries it too.
update_option( Options::MODE, Options::MODE_PRE_PAYMENT );
wp_set_current_user( $navexa_customer );
wc_load_cart();
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $product->get_id(), 1 );
foreach ( array( 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'address_1' => '1 Main St', 'city' => 'Springfield', 'state' => 'IL', 'postcode' => '62701', 'country' => 'US' ) as $navexa_field => $navexa_value ) {
	WC()->customer->{'set_billing_' . $navexa_field}( $navexa_value );
	WC()->customer->{'set_shipping_' . $navexa_field}( $navexa_value );
}
WC()->cart->calculate_totals();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . \Navexa\AgeCheck\RateLimit::table() );
Start::reset();
$started = Start::answer( array( 'v' => 1, 'action' => 'start' ) );
$creates = navexa_w3b_calls( 'CreateVerification' );
navexa_w3b_check( 'pending' === $started['status'] && $ref === ( end( $creates )['body']['customerRef'] ?? '' ), 'a signed-in shopper\'s start carries customer_ref (' . $started['status'] . ')' );
wp_set_current_user( 1 );
update_option( Options::MODE, Options::MODE_ACCEPT_AND_HOLD );

// 10. Reuse off (production): the returning customer takes a new check.
navexa_w3b_mock( '/__mock/verifications', array( 'id' => $e->get_meta( Meta::VERIFICATION_ID ), 'status' => 'passed', 'passClass' => 'doc_only' ) );
navexa_w3b_check( Release::RELEASED === Release::run( $e->get_id(), Release::TRIGGER_WEBHOOK ), 'the customer\'s first order passes and is released' );
$f = navexa_w3b_held( $product, $navexa_customer );
Release::run( $f->get_id(), Release::TRIGGER_HOLD );
$f = navexa_w3b_reload( $f );
navexa_w3b_check( Hold::STATUS === $f->get_status() && 'needs_doc' === $f->get_meta( Meta::VERIFICATION_STATUS ), 'reuse off: the next order needs a new check and stays held (' . $f->get_meta( Meta::VERIFICATION_STATUS ) . ')' );

// 11. Reuse on: a returning customer's next order passes at once as REUSED and is released.
navexa_w3b_mock( '/__mock/config', array( 'reuse' => 'on' ) );
$g       = navexa_w3b_held( $product, $navexa_customer );
$outcome = Release::run( $g->get_id(), Release::TRIGGER_HOLD );
$g       = navexa_w3b_reload( $g );
navexa_w3b_check( Release::RELEASED === $outcome && 'processing' === $g->get_status(), 'reuse on: released at once (' . $outcome . ', ' . $g->get_status() . ')' );
navexa_w3b_check( 'REUSED' === $g->get_meta( Meta::PASS_CLASS ) && '' === $g->get_meta( Meta::LINK ), 'recorded as REUSED, nobody emailed' );
$rows = OrderPanel::rows( $g );
navexa_w3b_check( 'Returning customer (reused verification)' === ( $rows['Passed by'] ?? '' ), 'the panel says: ' . ( $rows['Passed by'] ?? '(none)' ) );
$state = navexa_w3b_mock( '/__mock/verifications' );
$found = null;
foreach ( $state['verifications'] as $navexa_v ) {
	if ( $navexa_v['id'] === $g->get_meta( Meta::VERIFICATION_ID ) ) {
		$found = $navexa_v;
	}
}
navexa_w3b_check( null !== $found && $e->get_meta( Meta::VERIFICATION_ID ) === ( $found['reusedFrom'] ?? '' ), 'AgeCheck reused the first order\'s check' );
$h = navexa_w3b_held( $product );
Release::run( $h->get_id(), Release::TRIGGER_HOLD );
navexa_w3b_check( Hold::STATUS === navexa_w3b_reload( $h )->get_status(), 'a guest is never reused' );
navexa_w3b_mock( '/__mock/config', array( 'reuse' => 'off' ) );

// 12. The kill switch (last: a constant cannot be undefined).
define( 'NAVEXA_AGECHECK_EMAIL_LINKS', false );
delete_option( Links::OPTION_BLOCK );
$before = count( navexa_w3b_calls( 'SendVerificationLink' ) );
$k      = navexa_w3b_held( $product );
Release::run( $k->get_id(), Release::TRIGGER_HOLD );
$k = navexa_w3b_reload( $k );
navexa_w3b_check( 0 === strpos( $k->get_meta( Meta::LINK ), 'disabled:' ) && $before === count( navexa_w3b_calls( 'SendVerificationLink' ) ), 'NAVEXA_AGECHECK_EMAIL_LINKS false: no call, held (' . $k->get_meta( Meta::LINK ) . ')' );

// Clean up what later runs would trip over.
as_unschedule_all_actions( '', array(), Release::GROUP );
delete_option( Links::OPTION_BLOCK );
navexa_w3b_mock( '/__mock/reset', new stdClass() );
update_option( Options::MODE, Options::MODE_PRE_PAYMENT );

if ( $GLOBALS['navexa_w3b_failures'] > 0 ) {
	WP_CLI::error( $GLOBALS['navexa_w3b_failures'] . ' W3b check(s) failed' );
}
WP_CLI::success( 'W3b checks passed' );
