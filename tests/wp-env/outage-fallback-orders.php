<?php
/**
 * Follows the orders tests/wp-env/outage-fallback.mjs placed without a check
 * while AgeCheck was down (TAX-1841) to a CLEARED release, then checks the
 * setting's link gate on positive evidence:
 *
 *   wp eval-file tests/wp-env/outage-fallback-orders.php
 *
 * Run right after outage-fallback.mjs, against the same mock engine. For
 * every order billed to outage@example.com in the last hour (refused drafts
 * left out):
 *
 * - it is marked taken while AgeCheck was down (Meta::FALLBACK), binds no
 *   verification, and the backstop held it (Hold: no verification id, so
 *   payment's processing was intercepted);
 * - with AgeCheck back, a release run creates an order-bound verification
 *   with the ID-to-billing-name check (BOUND_BY create, REQUIRED) and has
 *   AgeCheck email the shopper the link (SendVerificationLink, Meta::LINK
 *   sent), and waits;
 * - once the shopper passes, the next run releases it on GetClearance
 *   CLEARED and the clearance is spent.
 *
 * Then: with links off at AgeCheck (GetIntegrationInfo links_enabled false),
 * the setting reads refuse, though no link refusal is on record; with links
 * on again, hold.
 *
 * Exits non-zero when a check fails.
 *
 * @package Navexa\AgeCheck\Tests
 */

// phpcs:ignoreFile -- test script.

use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Links;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Release;

$navexa_failures = 0;
$navexa_check    = static function ( $ok, $label ) use ( &$navexa_failures ) {
	if ( $ok ) {
		WP_CLI::log( 'ok   ' . $label );
		return;
	}
	++$navexa_failures;
	WP_CLI::warning( 'FAIL ' . $label );
};
$navexa_base = rtrim( NAVEXA_AGECHECK_API_URL, '/' );
$navexa_get  = static function ( $path ) use ( $navexa_base ) {
	return json_decode( wp_remote_retrieve_body( wp_remote_get( $navexa_base . $path, array( 'timeout' => 5 ) ) ), true );
};
$navexa_post = static function ( $path, $body ) use ( $navexa_base ) {
	$response = wp_remote_post(
		$navexa_base . $path,
		array(
			'timeout' => 5,
			'body'    => wp_json_encode( $body ),
		)
	);
	return 200 === wp_remote_retrieve_response_code( $response );
};

WP_CLI::log( '--- outage fallback orders (' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'posts table' ) . ') ---' );

$navexa_check( $navexa_post( '/__mock/config', array( 'down' => false, 'availability' => 'available', 'links' => 'on' ) ), 'AgeCheck is back' );

$navexa_orders = array_values(
	array_filter(
		wc_get_orders(
			array(
				'billing_email' => 'outage@example.com',
				'date_created'  => '>' . ( time() - HOUR_IN_SECONDS ),
				'limit'         => 100,
				'orderby'       => 'id',
				'order'         => 'ASC',
			)
		),
		static function ( $order ) {
			return ! in_array( $order->get_status(), array( 'checkout-draft', 'pending', 'failed' ), true );
		}
	)
);
$navexa_check( count( $navexa_orders ) >= 4, 'the flow placed the orders taken while AgeCheck was down: block, a draft placed again, the ID check unavailable, classic (' . count( $navexa_orders ) . ')' );

foreach ( $navexa_orders as $navexa_order ) {
	$id = $navexa_order->get_id();
	$navexa_check( 1 === preg_match( '/^outage:\d+$/', (string) $navexa_order->get_meta( Meta::FALLBACK ) ), "order {$id}: marked taken while AgeCheck was down" );
	$navexa_check( 'attach' !== $navexa_order->get_meta( Meta::BOUND_BY ) && 'yes' !== $navexa_order->get_meta( Meta::ATTACH_PENDING ), "order {$id}: no checkout verification bound" );
	$navexa_check( in_array( $navexa_order->get_status(), array( Hold::STATUS ), true ) && 'processing' === $navexa_order->get_meta( Meta::INTERCEPTED_STATUS ), "order {$id}: payment taken, order held (" . $navexa_order->get_status() . ')' );

	$outcome      = Release::run( $id, Release::TRIGGER_HOLD );
	wp_cache_flush();
	$navexa_order = wc_get_order( $id );
	$navexa_check( Release::PENDING === $outcome, "order {$id}: the release run waits on the shopper ({$outcome})" );

	$ext    = $navexa_order->get_meta( Meta::EXTERNAL_ID );
	$vid    = (string) $navexa_order->get_meta( Meta::VERIFICATION_ID );
	$create = null;
	$sent   = false;
	foreach ( (array) $navexa_get( '/__mock/requests' ) as $navexa_r ) {
		if ( 'CreateVerification' === $navexa_r['rpc'] && isset( $navexa_r['body']['orderExternalId'] ) && $ext === $navexa_r['body']['orderExternalId'] ) {
			$create = $navexa_r;
		}
		if ( 'SendVerificationLink' === $navexa_r['rpc'] && isset( $navexa_r['body']['verificationId'] ) && $vid === $navexa_r['body']['verificationId'] ) {
			$sent = true;
		}
	}
	$navexa_check( 'create' === $navexa_order->get_meta( Meta::BOUND_BY ) && null !== $create && 'DOCUMENT_NAME_CHECK_REQUIRED' === $create['body']['documentNameCheck'], "order {$id}: an order-bound verification with the name check (" . $navexa_order->get_meta( Meta::BOUND_BY ) . ')' );
	$navexa_check( $sent && 0 === strpos( (string) $navexa_order->get_meta( Meta::LINK ), Links::STATE_SENT . ':' ), "order {$id}: AgeCheck emailed the shopper the link (" . $navexa_order->get_meta( Meta::LINK ) . ')' );
	$navexa_check( Hold::STATUS === $navexa_order->get_status(), "order {$id}: still held until the shopper passes" );

	$navexa_check( $navexa_post( '/__mock/decide', array( 'id' => $vid, 'status' => 'passed' ) ), "order {$id}: the shopper passes by the link" );
	$outcome      = Release::run( $id, Release::TRIGGER_HOLD );
	wp_cache_flush();
	$navexa_order = wc_get_order( $id );
	$navexa_check( Release::RELEASED === $outcome && 'processing' === $navexa_order->get_status() && Meta::STATUS_CLEARED === $navexa_order->get_meta( Meta::STATUS ), "order {$id}: released to processing on CLEARED ({$outcome})" );
}

$navexa_state = $navexa_get( '/__mock/verifications' );
foreach ( $navexa_orders as $navexa_order ) {
	$vid      = (string) wc_get_order( $navexa_order->get_id() )->get_meta( Meta::VERIFICATION_ID );
	$consumed = false;
	foreach ( (array) $navexa_state['verifications'] as $navexa_v ) {
		if ( $vid === $navexa_v['id'] ) {
			$consumed = true === $navexa_v['consumed'];
		}
	}
	$navexa_check( $consumed, 'order ' . $navexa_order->get_id() . ': the clearance was spent' );
}

// The setting on positive evidence: links off at AgeCheck, no refusal on record.
delete_option( Links::OPTION_BLOCK );
$navexa_post( '/__mock/config', array( 'links' => 'off' ) );
Mode::flush();
Mode::info( Client::KEY_START );
$navexa_check( ! Options::reported_links_enabled() && Options::OUTAGE_REFUSE === Options::outage_fallback(), 'links off at AgeCheck: the setting reads refuse, though no link was ever refused' );
$navexa_post( '/__mock/config', array( 'links' => 'on' ) );
Mode::flush();
Mode::info( Client::KEY_START );
$navexa_check( Options::reported_links_enabled() && Options::OUTAGE_HOLD === Options::outage_fallback(), 'links on at AgeCheck: hold' );

if ( $navexa_failures > 0 ) {
	WP_CLI::error( $navexa_failures . ' outage fallback order check(s) failed' );
}
WP_CLI::success( 'outage fallback orders held, verified by link and released' );
