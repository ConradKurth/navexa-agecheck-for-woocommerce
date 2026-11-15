<?php
/**
 * Follows the orders tests/wp-env/prepayment.mjs placed through the hold
 * path: the seam between pre-payment (W2) and the hold core (W1).
 *
 *   wp eval-file tests/wp-env/prepayment-orders.php
 *
 * Run right after prepayment.mjs, against the same mock engine (it reads the
 * mock's recorded requests and verifications). For every order the flow
 * placed for the shopper pat@example.com (the drafts of refused checkouts
 * stay checkout-draft and are left out; among them an order billed to
 * another address than its ship-to, which took the ID check, and one that
 * passed on the data match, PASS_CLASS_DATA_VERIFIED):
 *
 * - a shipped order was bound by AttachOrder to the verification the shopper
 *   passed at checkout (BOUND_BY attach; no ship_to_changed, also for the
 *   order whose ZIP+4 differs from the ZIP the shopper verified at), with the
 *   bound ship-to hash recorded, and AgeCheck holds the same binding;
 * - no order-bound verification was created for it (the checkout one was
 *   used);
 * - cash on delivery moved it to processing, the backstop held it, and a
 *   release run releases it on GetClearance CLEARED, spending the clearance;
 * - a local pickup order was neither verified nor held.
 *
 * Then the orders the flow billed to other emails:
 *
 * - reuse@example.com: placed on a draft a refused checkout left behind,
 *   once at local pickup and once with only an exempt item left; neither
 *   needs a check, neither is held;
 * - orderpay@example.com: the order prepayment-setup.php created for staff,
 *   paid through the Store API (order-pay) by a shopper whose session held a
 *   passed check for the same ship-to. It is not bound to that check: it is
 *   held, and the release run creates an order-bound verification with the
 *   ID-to-billing-name check (owner decisions Q11b, Q7).
 *
 * Exits non-zero when a check fails.
 *
 * @package Navexa\AgeCheck\Tests
 */

// phpcs:ignoreFile -- test script.

use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\OrderAddress;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\Requirement;

$navexa_failures = 0;
$navexa_check    = static function ( $ok, $label ) use ( &$navexa_failures ) {
	if ( $ok ) {
		WP_CLI::log( 'ok   ' . $label );
		return;
	}
	++$navexa_failures;
	WP_CLI::warning( 'FAIL ' . $label );
};
$navexa_mock = static function ( $path ) {
	$response = wp_remote_get( rtrim( NAVEXA_AGECHECK_API_URL, '/' ) . $path, array( 'timeout' => 5 ) );
	return json_decode( wp_remote_retrieve_body( $response ), true );
};

WP_CLI::log( '--- pre-payment orders through the hold path (' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'posts table' ) . ') ---' );

$navexa_orders = array_values(
	array_filter(
		wc_get_orders(
			array(
				'billing_email' => 'pat@example.com',
				'date_created'  => '>' . ( time() - HOUR_IN_SECONDS ),
				'limit'         => 100,
				'orderby'       => 'id',
				'order'         => 'ASC',
			)
		),
		static function ( $order ) {
			return 'checkout-draft' !== $order->get_status();
		}
	)
);
$navexa_state     = $navexa_mock( '/__mock/verifications' );
$navexa_requests  = $navexa_mock( '/__mock/requests' );
$navexa_by_order  = array();
foreach ( (array) $navexa_state['verifications'] as $navexa_v ) {
	if ( '' !== (string) $navexa_v['orderExternalId'] ) {
		$navexa_by_order[ $navexa_v['orderExternalId'] ] = $navexa_v;
	}
}
$navexa_order_creates = array();
foreach ( (array) $navexa_requests as $navexa_r ) {
	if ( 'CreateVerification' === $navexa_r['rpc'] && ! empty( $navexa_r['body']['orderExternalId'] ) ) {
		$navexa_order_creates[] = $navexa_r['body']['orderExternalId'];
	}
}

$navexa_shipped = 0;
$navexa_pickup  = 0;
$navexa_zip4    = 0;
foreach ( $navexa_orders as $navexa_order ) {
	$id = $navexa_order->get_id();
	if ( Requirement::is_pickup( $navexa_order ) ) {
		++$navexa_pickup;
		$navexa_check( 'processing' === $navexa_order->get_status() && Requirement::REASON_PICKUP === $navexa_order->get_meta( Meta::REQUIRED_REASON ) && '' === $navexa_order->get_meta( Meta::VERIFICATION_ID ), "pickup order {$id}: not verified, not held (" . $navexa_order->get_status() . ')' );
		continue;
	}
	++$navexa_shipped;
	$ext     = $navexa_order->get_meta( Meta::EXTERNAL_ID );
	$vid     = $navexa_order->get_meta( Meta::VERIFICATION_ID );
	$ship_to = OrderAddress::ship_to( $navexa_order );
	if ( '62701-1234' === $navexa_order->get_shipping_postcode() ) {
		++$navexa_zip4;
	}
	$navexa_check( 'yes' === $navexa_order->get_meta( Meta::REQUIRED ), "order {$id}: required" );
	$navexa_check( 0 === strpos( $vid, 'acv_mock' ) && 'attach' === $navexa_order->get_meta( Meta::BOUND_BY ) && '' === $navexa_order->get_meta( Meta::ATTACH_PENDING ), "order {$id}: bound by AttachOrder to the checkout verification (" . $navexa_order->get_meta( Meta::BOUND_BY ) . ', reason ' . $navexa_order->get_meta( Meta::REASON ) . ')' );
	$navexa_check( null !== $ship_to && OrderAddress::hash( $ship_to ) === $navexa_order->get_meta( Meta::SHIP_TO_HASH ), "order {$id}: bound ship-to hash recorded" );
	$navexa_check( isset( $navexa_by_order[ $ext ] ) && $vid === $navexa_by_order[ $ext ]['id'] && 'DOCUMENT_NAME_CHECK_NONE' === $navexa_by_order[ $ext ]['documentNameCheck'], "order {$id}: AgeCheck binds the same pre-order verification" );
	$navexa_check( ! in_array( $ext, $navexa_order_creates, true ), "order {$id}: no order-bound verification was created" );
	$navexa_check( in_array( $navexa_order->get_status(), array( Hold::STATUS, 'processing' ), true ) && 'processing' === $navexa_order->get_meta( Meta::INTERCEPTED_STATUS ), "order {$id}: COD's processing was held (" . $navexa_order->get_status() . ')' );

	if ( Hold::STATUS === $navexa_order->get_status() ) {
		$outcome      = Release::run( $id, Release::TRIGGER_HOLD );
		wp_cache_flush();
		$navexa_order = wc_get_order( $id );
		$navexa_check( Release::RELEASED === $outcome, "order {$id}: release run released it ({$outcome})" );
	}
	$navexa_check( 'processing' === $navexa_order->get_status() && Meta::STATUS_CLEARED === $navexa_order->get_meta( Meta::STATUS ) && Meta::is_released( $navexa_order ), "order {$id}: released to processing on CLEARED" );
}

$navexa_state    = $navexa_mock( '/__mock/verifications' );
$navexa_by_order = array();
foreach ( (array) $navexa_state['verifications'] as $navexa_v ) {
	if ( '' !== (string) $navexa_v['orderExternalId'] ) {
		$navexa_by_order[ $navexa_v['orderExternalId'] ] = $navexa_v;
	}
}
foreach ( $navexa_orders as $navexa_order ) {
	if ( Requirement::is_pickup( $navexa_order ) ) {
		continue;
	}
	$ext = $navexa_order->get_meta( Meta::EXTERNAL_ID );
	$navexa_check( isset( $navexa_by_order[ $ext ] ) && true === $navexa_by_order[ $ext ]['consumed'], 'order ' . $navexa_order->get_id() . ': the clearance was spent' );
}

$navexa_check( 5 === $navexa_shipped, "the flow placed 5 shipped orders: block, billed elsewhere than the ship-to, data match, block at the ZIP+4, classic ({$navexa_shipped})" );
$navexa_check( $navexa_zip4 >= 1, "one of them ships to the ZIP+4 of the verified ZIP ({$navexa_zip4})" );
$navexa_check( 1 === $navexa_pickup, "and one local pickup order ({$navexa_pickup})" );

$navexa_by_email = static function ( $email ) {
	return array_values(
		array_filter(
			wc_get_orders(
				array(
					'billing_email' => $email,
					'date_created'  => '>' . ( time() - HOUR_IN_SECONDS ),
					'limit'         => 100,
					'orderby'       => 'id',
					'order'         => 'ASC',
				)
			),
			static function ( $order ) {
				return ! in_array( $order->get_status(), array( 'checkout-draft', 'pending' ), true );
			}
		)
	);
};

$navexa_reuse = $navexa_by_email( 'reuse@example.com' );
$navexa_kinds = array();
foreach ( $navexa_reuse as $navexa_order ) {
	$id     = $navexa_order->get_id();
	$reason = $navexa_order->get_meta( Meta::REQUIRED_REASON );
	$navexa_kinds[] = $reason;
	$navexa_check( 'processing' === $navexa_order->get_status() && 'no' === $navexa_order->get_meta( Meta::REQUIRED ) && '' === $navexa_order->get_meta( Meta::VERIFICATION_ID ), "reused draft {$id}: placed, not required, not verified, not held ({$reason}, " . $navexa_order->get_status() . ')' );
}
sort( $navexa_kinds );
$navexa_check( array( Requirement::REASON_NO_RESTRICTED, Requirement::REASON_PICKUP ) === $navexa_kinds, 'the two reused drafts: one with only an exempt item, one at local pickup (' . implode( ', ', $navexa_kinds ) . ')' );

$navexa_paid = $navexa_by_email( 'orderpay@example.com' );
$navexa_check( count( $navexa_paid ) >= 1, 'the flow paid the staff-created order through the Store API (' . count( $navexa_paid ) . ')' );
foreach ( $navexa_paid as $navexa_order ) {
	$id = $navexa_order->get_id();
	$navexa_check( 'attach' !== $navexa_order->get_meta( Meta::BOUND_BY ) && 'yes' !== $navexa_order->get_meta( Meta::ATTACH_PENDING ), "order-pay {$id}: the session's checkout verification was not bound to it" );
	$navexa_check( Hold::STATUS === $navexa_order->get_status() && 'processing' === $navexa_order->get_meta( Meta::INTERCEPTED_STATUS ), "order-pay {$id}: payment taken, order held (" . $navexa_order->get_status() . ')' );
	if ( '' === $navexa_order->get_meta( Meta::BOUND_BY ) ) {
		$outcome      = Release::run( $id, Release::TRIGGER_HOLD );
		wp_cache_flush();
		$navexa_order = wc_get_order( $id );
		$navexa_check( Release::PENDING === $outcome, "order-pay {$id}: the release run waits on the shopper ({$outcome})" );
	}
	$ext    = $navexa_order->get_meta( Meta::EXTERNAL_ID );
	$create = null;
	foreach ( (array) $navexa_mock( '/__mock/requests' ) as $navexa_r ) {
		if ( 'CreateVerification' === $navexa_r['rpc'] && isset( $navexa_r['body']['orderExternalId'] ) && $ext === $navexa_r['body']['orderExternalId'] ) {
			$create = $navexa_r;
		}
	}
	$navexa_check( 'create' === $navexa_order->get_meta( Meta::BOUND_BY ) && null !== $create && 'DOCUMENT_NAME_CHECK_REQUIRED' === $create['body']['documentNameCheck'], "order-pay {$id}: bound by an order-bound verification with the name check (" . $navexa_order->get_meta( Meta::BOUND_BY ) . ')' );
	$navexa_check( Hold::STATUS === $navexa_order->get_status(), "order-pay {$id}: still held until the shopper passes" );
}

if ( $navexa_failures > 0 ) {
	WP_CLI::error( $navexa_failures . ' pre-payment order check(s) failed' );
}
WP_CLI::success( 'pre-payment orders bound, held and released' );
