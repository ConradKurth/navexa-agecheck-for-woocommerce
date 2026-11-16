<?php
/**
 * wp-env smoke checks for W1 (hold core), included by smoke.php. Runs real
 * WooCommerce order flows against the mock engine: the hold on payment and
 * on a direct status change, the order-bound verification, webhook-driven
 * release, a lost CLEARED answer, the refused manual move and the logged
 * override, denial to the refund list, local pickup, an order with no
 * shipping line checked at its shipping address, the ship-to guard and
 * order-pay refusal, ReportShipment, the sweep, per-order queueing on the
 * bundled Action Scheduler, and a copied order, on whichever order storage
 * is active.
 *
 * @package Navexa\AgeCheck\Tests
 */

// phpcs:ignoreFile -- test script.

use Navexa\AgeCheck\Admin\OrdersFilter;
use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\Requirement;
use Navexa\AgeCheck\Shipment;
use Navexa\AgeCheck\Sweep;
use Navexa\AgeCheck\Webhook;

WP_CLI::log( '--- W1 hold core ---' );

function navexa_smoke_get( $path ) {
	$response = wp_remote_get( rtrim( NAVEXA_AGECHECK_API_URL, '/' ) . $path, array( 'timeout' => 5 ) );
	return json_decode( wp_remote_retrieve_body( $response ), true );
}

/** A fresh copy of the order from storage (HPOS or posts), never the cached object. */
function navexa_smoke_reload( $order ) {
	wp_cache_flush();
	return wc_get_order( is_object( $order ) ? $order->get_id() : $order );
}

/**
 * A pending order for $product. Args: payment, shipping (method id; '' for an
 * order with no shipping line, as staff often create one), country, and
 * ship_to (fields that make the shipping address differ from billing).
 */
function navexa_smoke_order( $product, $args = array() ) {
	$args    = array_merge(
		array(
			'payment'  => 'bacs',
			'shipping' => 'flat_rate',
			'country'  => 'US',
			'ship_to'  => array(),
		),
		$args
	);
	$order   = wc_create_order( array( 'status' => 'pending' ) );
	$order->add_product( $product, 1 );
	$address = array(
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'address_1'  => '1 Main St',
		'city'       => 'Springfield',
		'state'      => 'US' === $args['country'] ? 'IL' : 'ON',
		'postcode'   => 'US' === $args['country'] ? '62701-1234' : 'K1A 0B1',
		'country'    => $args['country'],
		'email'      => 'ada@example.com',
	);
	$order->set_address( $address, 'billing' );
	$order->set_address( array_merge( $address, $args['ship_to'] ), 'shipping' );
	$order->set_payment_method( $args['payment'] );
	if ( '' !== $args['shipping'] ) {
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_id( $args['shipping'] );
		$shipping->set_method_title( $args['shipping'] );
		$shipping->set_total( '5' );
		$order->add_item( $shipping );
	}
	$order->calculate_totals();
	$order->save();
	return navexa_smoke_reload( $order );
}

function navexa_smoke_release_jobs( $order_id ) {
	return as_get_scheduled_actions(
		array(
			'hook'   => Release::HOOK,
			'group'  => Release::GROUP,
			'status' => ActionScheduler_Store::STATUS_PENDING,
			'args'   => array( $order_id, 0, Release::TRIGGER_HOLD ),
		),
		'ids'
	);
}

function navexa_smoke_webhook( $body, $secret, $timestamp = null ) {
	$timestamp = (string) ( null === $timestamp ? time() : $timestamp );
	$request   = new WP_REST_Request( 'POST', '/navexa-agecheck/v1/webhook' );
	$request->set_body( $body );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_header( 'X-Webhook-Timestamp', $timestamp );
	$request->set_header( 'X-Webhook-Signature-V2', hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ) );
	return rest_do_request( $request );
}

function navexa_smoke_has_note( $order, $needle ) {
	foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
		if ( false !== strpos( $note->content, $needle ) ) {
			return true;
		}
	}
	return false;
}

// Setup: live keys, a webhook secret, the mock reset with this store allowed.
$navexa_secret = 'whsec_smoke_0123456789abcdef';
update_option( Options::START_KEY, $keys['liveStart']['key'], false );
update_option( Options::FULFILMENT_KEY, $keys['liveFulfilment']['key'], false );
update_option( Options::WEBHOOK_SECRET, $navexa_secret, false );
delete_option( Options::RULE );
navexa_smoke_mock( '/__mock/reset', new stdClass() );
navexa_smoke_mock( '/__mock/config', array( 'org' => array( 'allowedOrigins' => array( Mode::store_origin() ) ) ) );
Mode::flush();
navexa_smoke_check( null !== Mode::info( Client::KEY_FULFILMENT ), 'fulfilment key info for W1' );

$product = new WC_Product_Simple();
$product->set_name( 'Smoke disposable' );
$product->set_regular_price( '20' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 10 );
$product->save();

navexa_smoke_check( isset( wc_get_order_statuses()['wc-agecheck-hold'] ), 'Awaiting age check status registered' );

// 1. Payment holds the order; the release run starts an order-bound verification.
$a = navexa_smoke_order( $product );
$a->payment_complete( 'txn_smoke_a' );
$a = navexa_smoke_reload( $a );
navexa_smoke_check( Hold::STATUS === $a->get_status(), 'payment_complete holds a restricted order (' . $a->get_status() . ')' );
navexa_smoke_check( 'yes' === $a->get_meta( Meta::HELD ) && 'processing' === $a->get_meta( Meta::INTERCEPTED_STATUS ), 'hold recorded with the intercepted status' );
navexa_smoke_check( 'txn_smoke_a' === $a->get_transaction_id(), 'the transaction is still recorded' );
navexa_smoke_check( 9 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'stock reduced on entering the hold' );
navexa_smoke_check( ! empty( navexa_smoke_release_jobs( $a->get_id() ) ), 'a release run is queued on entering the hold' );
navexa_smoke_check( 1 === preg_match( '/^wc:[0-9a-f]{32}:\d+:' . $a->get_id() . '$/', $a->get_meta( Meta::EXTERNAL_ID ) ), 'external id stored' );

$outcome = Release::run( $a->get_id(), Release::TRIGGER_HOLD );
$a       = navexa_smoke_reload( $a );
$vid_a   = $a->get_meta( Meta::VERIFICATION_ID );
navexa_smoke_check( Release::PENDING === $outcome && 0 === strpos( $vid_a, 'acv_mock' ), 'order-bound verification created, order pending (' . $outcome . ')' );
navexa_smoke_check( 0 === strpos( $a->get_meta( Meta::LINK ), 'links_off:' ), 'verification links off at AgeCheck (production today): order stays held (' . $a->get_meta( Meta::LINK ) . ')' );
navexa_smoke_check( \Navexa\AgeCheck\Links::STATE_LINKS_OFF === ( \Navexa\AgeCheck\Links::store_block()['reason'] ?? '' ), 'links off recorded once for the store' );
navexa_smoke_check( Hold::STATUS === $a->get_status(), 'still held while the shopper has not passed' );
$navexa_requests = navexa_smoke_get( '/__mock/requests' );
$navexa_create   = null;
foreach ( $navexa_requests as $navexa_request ) {
	if ( 'CreateVerification' === $navexa_request['rpc'] ) {
		$navexa_create = $navexa_request;
	}
}
navexa_smoke_check( null !== $navexa_create && 'liveFulfilment' === $navexa_create['key'], 'create used the fulfilment key' );
navexa_smoke_check( null !== $navexa_create && 'DOCUMENT_NAME_CHECK_REQUIRED' === $navexa_create['body']['documentNameCheck'] && ! isset( $navexa_create['body']['identity']['dob'] ), 'create sends a required name check and no DOB' );
navexa_smoke_check( null !== $navexa_create && '62701' === $navexa_create['body']['shipTo']['postalCode'], 'ship-to ZIP normalized to five digits' );

// 2. The shopper passes; the webhook queues a release; the run releases.
navexa_smoke_mock( '/__mock/verifications', array( 'id' => $vid_a, 'status' => 'passed' ) );
$navexa_event = wp_json_encode(
	array(
		'event_id'          => 'ace_smoke_' . $a->get_id(), // Unique per run: handled ids are remembered for a week.
		'organization_id'   => 'org_mock',
		'verification_id'   => $vid_a,
		'state'             => 'passed',
		'pass_class'        => 'doc_only',
		'policy_version'    => 'mock',
		'order_external_id' => $a->get_meta( Meta::EXTERNAL_ID ),
		'livemode'          => true,
		'occurred_at'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
	)
);
$response     = navexa_smoke_webhook( $navexa_event, $navexa_secret );
navexa_smoke_check( 200 === $response->get_status() && 'queued' === $response->get_data()['result'], 'signed webhook accepted (' . $response->get_status() . ')' );
$response = navexa_smoke_webhook( $navexa_event, $navexa_secret );
navexa_smoke_check( 'duplicate' === $response->get_data()['result'], 'webhook deduped on event_id' );
navexa_smoke_check( 401 === navexa_smoke_webhook( $navexa_event, 'whsec_wrong_secret_0123456' )->get_status(), 'bad signature refused' );
navexa_smoke_check( 401 === navexa_smoke_webhook( $navexa_event, $navexa_secret, time() - 600 )->get_status(), 'stale timestamp refused' );
navexa_smoke_check(
	! empty(
		as_get_scheduled_actions(
			array(
				'hook'   => Release::HOOK,
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'args'   => array( $a->get_id(), 0, Release::TRIGGER_WEBHOOK ),
			),
			'ids'
		)
	),
	'webhook queued a release run'
);
$outcome = Release::run( $a->get_id(), Release::TRIGGER_WEBHOOK );
$a       = navexa_smoke_reload( $a );
navexa_smoke_check( Release::RELEASED === $outcome && 'processing' === $a->get_status(), 'cleared order released to processing (' . $outcome . ', ' . $a->get_status() . ')' );
navexa_smoke_check( Meta::STATUS_CLEARED === $a->get_meta( Meta::STATUS ) && '' === $a->get_meta( Meta::HELD ), 'clearance recorded' );
navexa_smoke_check( navexa_smoke_has_note( $a, 'age check passed' ), 'release note added' );

// 3. Ship-to guard on a bound order.
$a->set_shipping_address_1( '99 Elsewhere Rd' );
$a->set_shipping_first_name( 'Augusta' );
$a->save();
$a = navexa_smoke_reload( $a );
navexa_smoke_check( '1 Main St' === $a->get_shipping_address_1() && 'Augusta' === $a->get_shipping_first_name(), 'ship-to change reverted, name kept' );
// Store API order-pay writes the request's addresses and saves before it validates.
$a->set_shipping_postcode( '10001' );
$a->save();
$navexa_errors = new WP_Error();
do_action( 'woocommerce_checkout_validate_order_before_payment', $a, $navexa_errors );
navexa_smoke_check( in_array( 'navexa_agecheck_ship_to_locked', $navexa_errors->get_error_codes(), true ), 'order-pay refuses a ship-to change the guard reverted during the save' );
$a = navexa_smoke_reload( $a );

// 4. ReportShipment on completion.
$a->update_status( 'completed' );
navexa_smoke_check( ! empty( as_get_scheduled_actions( array( 'hook' => Shipment::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING, 'args' => array( $a->get_id(), 0 ) ), 'ids' ) ), 'shipment report queued on completed' );
navexa_smoke_check( Shipment::report( $a->get_id() ), 'shipment reported' );
$navexa_state = navexa_smoke_get( '/__mock/verifications' );
navexa_smoke_check( ! empty( $navexa_state['shipments'] ) && false === $navexa_state['shipments'][0]['flagged'], 'AgeCheck holds a cleared shipment report' );

// 5. A manual move to processing is refused; the logged override releases.
$b = navexa_smoke_order( $product );
$b->payment_complete( 'txn_smoke_b' );
$b = navexa_smoke_reload( $b );
navexa_smoke_check( ! empty( navexa_smoke_release_jobs( $a->get_id() ) ) && ! empty( navexa_smoke_release_jobs( $b->get_id() ) ), 'two orders each have a pending release run (Action Scheduler ' . ActionScheduler_Versions::instance()->latest_version() . ')' );
$navexa_same_status = 0;
add_action(
	'woocommerce_order_status_changed',
	static function ( $id, $from, $to ) use ( &$navexa_same_status ) {
		if ( $from === $to ) {
			++$navexa_same_status;
		}
	},
	10,
	3
);
$b->update_status( 'processing', '', true );
$b = navexa_smoke_reload( $b );
navexa_smoke_check( Hold::STATUS === $b->get_status() && navexa_smoke_has_note( $b, 'stays "Awaiting age check"' ), 'manual move to processing re-held with a note' );
navexa_smoke_check( 0 === $navexa_same_status && ! navexa_smoke_has_note( $b, 'from Awaiting age check to Awaiting age check' ), 'a refused move fires no status change and adds no WooCommerce transition note' );
$b->update_status( 'completed' );
navexa_smoke_check( Hold::STATUS === navexa_smoke_reload( $b )->get_status(), 'manual move to completed re-held' );
navexa_smoke_check( Hold::override( navexa_smoke_reload( $b ), 1, 'Smoke: ID checked in person' ), 'override released' );
$b = navexa_smoke_reload( $b );
navexa_smoke_check( 'processing' === $b->get_status() && Meta::STATUS_OVERRIDE === $b->get_meta( Meta::STATUS ) && 1 === (int) $b->get_meta( Meta::OVERRIDE_BY ), 'override recorded who and moved to processing' );
navexa_smoke_check( navexa_smoke_has_note( $b, 'Smoke: ID checked in person' ), 'override reason noted' );
$b->update_status( 'completed' );
navexa_smoke_check( ! empty( as_get_scheduled_actions( array( 'hook' => Shipment::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING, 'args' => array( $b->get_id(), 0 ) ), 'ids' ) ), 'a second shipment report is queued while the first is pending' );
Shipment::report( $b->get_id() );
$navexa_state = navexa_smoke_get( '/__mock/verifications' );
navexa_smoke_check( 2 === count( $navexa_state['shipments'] ) && true === $navexa_state['shipments'][1]['flagged'], 'an overridden shipment is reported and flagged by AgeCheck' );

// 6. COD sets processing itself: the backstop holds it.
$c = navexa_smoke_order( $product, array( 'payment' => 'cod' ) );
$c->update_status( 'processing', 'COD.' );
$c = navexa_smoke_reload( $c );
navexa_smoke_check( Hold::STATUS === $c->get_status() && 'processing' === $c->get_meta( Meta::INTERCEPTED_STATUS ), 'COD processing caught by the backstop' );

// 7. A lost CLEARED answer comes back as replayed and still releases.
Release::run( $c->get_id(), Release::TRIGGER_HOLD );
$c     = navexa_smoke_reload( $c );
$vid_c = $c->get_meta( Meta::VERIFICATION_ID );
navexa_smoke_check( '' === (string) $c->get_meta( Meta::NEXT_CHECK_AT ), 'a shopper who was not emailed is still polled every sweep (Verify now)' );
$c->update_meta_data( Meta::NEXT_CHECK_AT, time() + HOUR_IN_SECONDS );
$c->save_meta_data();
navexa_smoke_check( Release::NOT_DUE === Release::run( $c->get_id(), Release::TRIGGER_SWEEP ), 'sweep skips an order not due' );
navexa_smoke_mock( '/__mock/verifications', array( 'id' => $vid_c, 'status' => 'passed', 'consumed' => true ) );
$outcome = Release::run( $c->get_id(), Release::TRIGGER_WEBHOOK );
$c       = navexa_smoke_reload( $c );
navexa_smoke_check( 'processing' === $c->get_status() && navexa_smoke_has_note( $c, 'repeated clearance answer' ), 'replayed clearance releases (' . $outcome . ')' );

// 8. A failed check denies: held, listed for a manual refund (BACS cannot refund by API).
$d = navexa_smoke_order( $product );
$d->payment_complete();
Release::run( $d->get_id(), Release::TRIGGER_HOLD );
$d = navexa_smoke_reload( $d );
navexa_smoke_mock( '/__mock/verifications', array( 'id' => $d->get_meta( Meta::VERIFICATION_ID ), 'status' => 'failed' ) );
$outcome = Release::run( $d->get_id(), Release::TRIGGER_WEBHOOK );
$d       = navexa_smoke_reload( $d );
navexa_smoke_check( Release::DENIED === $outcome && Hold::STATUS === $d->get_status() && Meta::REFUND_MANUAL === $d->get_meta( Meta::REFUND ), 'denied order stays held, listed for a manual refund' );
$navexa_listed = OrdersFilter::order_ids( OrdersFilter::DENIED, 50 );
navexa_smoke_check( in_array( $d->get_id(), $navexa_listed, true ) && ! in_array( $a->get_id(), $navexa_listed, true ) && ! in_array( $b->get_id(), $navexa_listed, true ), 'denied filter lists it, not cleared or overridden orders' );
$navexa_awaiting = OrdersFilter::order_ids( OrdersFilter::AWAITING, 50 );
navexa_smoke_check( ! in_array( $d->get_id(), $navexa_awaiting, true ) && ! in_array( $a->get_id(), $navexa_awaiting, true ), 'awaiting filter leaves denied and released orders out' );
\Navexa\AgeCheck\Admin\Notices::flush_counts();
navexa_smoke_check( count( $navexa_listed ) === \Navexa\AgeCheck\Admin\Notices::counts()['denied'], 'notice counts the denied orders' );
if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
	$_GET[ OrdersFilter::PARAM ] = OrdersFilter::DENIED;
	navexa_smoke_check( $navexa_listed === wc_get_orders( OrdersFilter::filter_hpos_args( array( 'limit' => 50, 'return' => 'ids' ) ) ), 'HPOS list filter lists the same orders' );
	unset( $_GET[ OrdersFilter::PARAM ] );
} else {
	$_GET[ OrdersFilter::PARAM ] = OrdersFilter::DENIED;
	$GLOBALS['typenow']          = 'shop_order';
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	set_current_screen( 'edit-shop_order' ); // is_admin() reads the current screen.
	$navexa_vars  = OrdersFilter::filter_posts_request( array( 'post_type' => 'shop_order', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 50 ) );
	$navexa_query = new WP_Query( $navexa_vars );
	$navexa_posts = array_map( 'intval', $navexa_query->posts );
	sort( $navexa_posts );
	sort( $navexa_listed );
	navexa_smoke_check( $navexa_listed === $navexa_posts, 'posts-table list filter lists the same orders (' . implode( ',', $navexa_posts ) . ')' );
	unset( $_GET[ OrdersFilter::PARAM ], $GLOBALS['typenow'], $GLOBALS['current_screen'] );
}
$d->update_status( 'cancelled' );
$d = navexa_smoke_reload( $d );
navexa_smoke_check( 'cancelled' === $d->get_status() && Meta::REFUND_RESOLVED === $d->get_meta( Meta::REFUND ), 'cancelling a denied order is allowed and takes it off the list' );

// 9. Local pickup skips AgeCheck entirely.
$e = navexa_smoke_order( $product, array( 'shipping' => 'local_pickup' ) );
$e->payment_complete();
$e = navexa_smoke_reload( $e );
navexa_smoke_check( 'processing' === $e->get_status() && Requirement::REASON_PICKUP === $e->get_meta( Meta::REQUIRED_REASON ), 'local pickup order goes straight to processing' );

// 10. Non-US: held, then denied.
$f = navexa_smoke_order( $product, array( 'country' => 'CA' ) );
$f->payment_complete();
$outcome = Release::run( $f->get_id(), Release::TRIGGER_HOLD );
$f       = navexa_smoke_reload( $f );
navexa_smoke_check( Hold::STATUS === $f->get_status() && 'not_us' === $f->get_meta( Meta::REASON ), 'non-US order held and denied (' . $outcome . ')' );

// 10b. An order with no shipping line (staff-created, REST, POS, imported:
// free shipping by omission) is checked where it ships, its shipping address,
// not at billing.
$navexa_last_rpc = static function ( $rpc, $external ) {
	$found = null;
	foreach ( navexa_smoke_get( '/__mock/requests' ) as $navexa_request ) {
		if ( $rpc === $navexa_request['rpc'] && isset( $navexa_request['body']['orderExternalId'] ) && $external === $navexa_request['body']['orderExternalId'] ) {
			$found = $navexa_request;
		}
	}
	return $found;
};
$j = navexa_smoke_order( $product, array( 'shipping' => '', 'ship_to' => array( 'address_1' => '9 Elm St', 'city' => 'Chicago', 'postcode' => '60601' ) ) );
navexa_smoke_check( ! $j->needs_shipping_address(), 'an order with no shipping line needs no shipping address, for WooCommerce' );
$j->payment_complete();
$outcome  = Release::run( $j->get_id(), Release::TRIGGER_HOLD );
$j        = navexa_smoke_reload( $j );
$navexa_c = $navexa_last_rpc( 'CreateVerification', $j->get_meta( Meta::EXTERNAL_ID ) );
navexa_smoke_check( Release::PENDING === $outcome && null !== $navexa_c && '9 Elm St' === $navexa_c['body']['shipTo']['line1'] && '60601' === $navexa_c['body']['shipTo']['postalCode'], 'no shipping line: verified at the shipping address (' . $outcome . ')' );
navexa_smoke_check( null !== $navexa_c && '1 Main St' === $navexa_c['body']['identity']['residentialAddress']['line1'], 'billing stays the residential address' );
navexa_smoke_mock( '/__mock/verifications', array( 'id' => $j->get_meta( Meta::VERIFICATION_ID ), 'status' => 'passed' ) );
$outcome  = Release::run( $j->get_id(), Release::TRIGGER_WEBHOOK );
$j        = navexa_smoke_reload( $j );
$navexa_c = $navexa_last_rpc( 'GetClearance', $j->get_meta( Meta::EXTERNAL_ID ) );
navexa_smoke_check( Release::RELEASED === $outcome && 'processing' === $j->get_status() && null !== $navexa_c && '9 Elm St' === $navexa_c['body']['shipTo']['line1'], 'and cleared at the shipping address (' . $outcome . ')' );

$k = navexa_smoke_order( $product, array( 'shipping' => '', 'ship_to' => array( 'city' => 'Toronto', 'state' => 'ON', 'postcode' => 'M5V 2T6', 'country' => 'CA' ) ) );
$k->payment_complete();
$outcome = Release::run( $k->get_id(), Release::TRIGGER_HOLD );
$k       = navexa_smoke_reload( $k );
navexa_smoke_check( Hold::STATUS === $k->get_status() && 'not_us' === $k->get_meta( Meta::REASON ), 'no shipping line, billed in the US, shipping to Canada: denied not_us (' . $outcome . ')' );
navexa_smoke_check( null === $navexa_last_rpc( 'CreateVerification', $k->get_meta( Meta::EXTERNAL_ID ) ), 'and never verified at the billing address' );

// 11. An exempt product is not held.
$navexa_exempt = new WC_Product_Simple();
$navexa_exempt->set_name( 'Smoke T-shirt' );
$navexa_exempt->set_regular_price( '10' );
$navexa_exempt->update_meta_data( Requirement::PRODUCT_META, Requirement::PRODUCT_EXEMPT );
$navexa_exempt->save();
$g = navexa_smoke_order( $navexa_exempt );
$g->payment_complete();
navexa_smoke_check( 'processing' === navexa_smoke_reload( $g )->get_status(), 'exempt product order is not held' );

// 12. The sweep queues held orders that are not denied.
$h = navexa_smoke_order( $product );
$h->payment_complete();
$navexa_queued = Sweep::run();
navexa_smoke_check( $navexa_queued >= 1 && ! empty( as_get_scheduled_actions( array( 'hook' => Release::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING, 'args' => array( $h->get_id(), 0, Release::TRIGGER_SWEEP ) ), 'ids' ) ), 'sweep queued held orders (' . $navexa_queued . ')' );
navexa_smoke_check( Sweep::healthy(), 'sweep heartbeat recorded' );

// 13. A copy of a cleared order (a duplicate-order plugin copies every meta key) is held under its own id.
$i = navexa_smoke_order( $product );
foreach ( navexa_smoke_reload( $a )->get_meta_data() as $navexa_meta ) {
	if ( 0 === strpos( $navexa_meta->key, '_navexa_agecheck_' ) ) {
		$i->update_meta_data( $navexa_meta->key, $navexa_meta->value );
	}
}
$i->save();
$i->payment_complete();
$i = navexa_smoke_reload( $i );
navexa_smoke_check( Hold::STATUS === $i->get_status(), 'a copy of a cleared order is held (' . $i->get_status() . ')' );
navexa_smoke_check( 1 === preg_match( '/:' . $i->get_id() . '$/', $i->get_meta( Meta::EXTERNAL_ID ) ) && '' === $i->get_meta( Meta::VERIFICATION_ID ) && Meta::STATUS_PENDING === $i->get_meta( Meta::STATUS ), 'the copied AgeCheck state is replaced by its own' );

// 14. A test key never releases without NAVEXA_AGECHECK_ALLOW_TEST_RELEASE (wp-env defines it, so only report).
WP_CLI::log( 'info NAVEXA_AGECHECK_ALLOW_TEST_RELEASE=' . ( defined( 'NAVEXA_AGECHECK_ALLOW_TEST_RELEASE' ) && NAVEXA_AGECHECK_ALLOW_TEST_RELEASE ? 'true' : 'false' ) );

// Clean up what later runs would trip over.
as_unschedule_all_actions( '', array(), Release::GROUP );
delete_option( \Navexa\AgeCheck\Links::OPTION_BLOCK );
delete_option( Options::WEBHOOK_SECRET );
