<?php
/**
 * W5: emailed verification links withdrawn once an order will not ship,
 * inside a real WordPress + WooCommerce (wp-env):
 *
 *   wp eval-file tests/wp-env/w5.php        (after prepayment-setup.php)
 *
 * Real held orders emailed a link by the mock engine are cancelled, refunded
 * (in part, then in full), failed, trashed (WC_Order::delete(), and
 * wp_trash_post() on the posts table) and deleted for good (straight away,
 * and from the trash before the trash's own run). Each status change must go through
 * with no call to AgeCheck in it, and queue one Action Scheduler action,
 * which this script runs through Action Scheduler's own runner: then
 * RevokeVerificationLinks with the fulfilment key and a stored key, the
 * answer and a note on the order, and the mock's link withdrawn (merchant).
 * Also: a partial refund withdraws nothing; an outage retries with the same
 * key; a refusal is recorded; an order restored before the run keeps its
 * link; an order carrying another order's copied state queues nothing; a
 * failed order paid again after its link was withdrawn is emailed a new one;
 * a withdrawal whose action died is queued again by the sweep.
 *
 * Needs the mock engine at NAVEXA_AGECHECK_API_URL and prepayment-setup.php
 * run first. Exits non-zero on any failure.
 *
 * @package Navexa\AgeCheck\Tests
 */

// phpcs:ignoreFile -- test script.

use Automattic\WooCommerce\Utilities\OrderUtil;
use Navexa\AgeCheck\Admin\OrderPanel;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\LinkRevoke;
use Navexa\AgeCheck\Links;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Release;

$GLOBALS['navexa_w5_failures'] = 0;

function navexa_w5_check( $ok, $what ) {
	if ( $ok ) {
		WP_CLI::log( 'ok   ' . $what );
		return;
	}
	++$GLOBALS['navexa_w5_failures'];
	WP_CLI::log( 'FAIL ' . $what );
}

function navexa_w5_mock( $path, $body = null ) {
	$url      = rtrim( NAVEXA_AGECHECK_API_URL, '/' ) . $path;
	$response = null === $body ? wp_remote_get( $url, array( 'timeout' => 5 ) ) : wp_remote_post( $url, array( 'timeout' => 5, 'body' => wp_json_encode( $body ) ) );
	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		navexa_w5_check( false, "mock {$path}" );
	}
	return json_decode( wp_remote_retrieve_body( $response ), true );
}

/** Calls of $rpc the mock recorded, in order. */
function navexa_w5_calls( $rpc ) {
	return array_values(
		array_filter(
			navexa_w5_mock( '/__mock/requests' ),
			static function ( $request ) use ( $rpc ) {
				return $rpc === $request['rpc'];
			}
		)
	);
}

/** The mock's links of $vid. */
function navexa_w5_links( $vid ) {
	return array_values(
		array_filter(
			navexa_w5_mock( '/__mock/verifications' )['links'],
			static function ( $link ) use ( $vid ) {
				return $vid === $link['verificationId'];
			}
		)
	);
}

function navexa_w5_reload( $order ) {
	wp_cache_flush();
	return wc_get_order( is_object( $order ) ? $order->get_id() : $order );
}

function navexa_w5_has_note( $order, $needle ) {
	foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
		if ( false !== strpos( $note->content, $needle ) ) {
			return true;
		}
	}
	return false;
}

/** Pending withdrawal actions of $order (or an order id): action id => args. */
function navexa_w5_pending( $order ) {
	$order_id = is_object( $order ) ? $order->get_id() : (int) $order;
	$out      = array();
	foreach ( as_get_scheduled_actions( array( 'hook' => LinkRevoke::HOOK, 'group' => Release::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50 ) ) as $id => $action ) {
		$args = $action->get_args();
		if ( (int) $args[0] === $order_id ) {
			$out[ $id ] = $args;
		}
	}
	return $out;
}

/** Runs $order's (or an order id's) pending withdrawal actions through Action Scheduler's runner; returns how many ran. */
function navexa_w5_run( $order ) {
	$ran = 0;
	foreach ( array_keys( navexa_w5_pending( $order ) ) as $action_id ) {
		ActionScheduler::runner()->process_action( $action_id, 'navexa-w5' );
		++$ran;
	}
	return $ran;
}

/** A paid order of $product, held, whose release run had AgeCheck email the shopper a link. */
function navexa_w5_emailed( $product ) {
	$order   = wc_create_order( array( 'status' => 'pending' ) );
	$order->add_product( $product, 1 );
	$address = array(
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'address_1'  => '1 Main St',
		'city'       => 'Springfield',
		'state'      => 'IL',
		'postcode'   => '62701',
		'country'    => 'US',
		'email'      => 'ada@example.com',
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
	$order->payment_complete( 'txn_w5_' . wp_generate_password( 6, false ) );
	Release::run( $order->get_id(), Release::TRIGGER_HOLD );
	$order = navexa_w5_reload( $order );
	$vid   = $order->get_meta( Meta::VERIFICATION_ID );
	navexa_w5_check( Hold::STATUS === $order->get_status() && '' !== $vid && 'sent:' . $vid === $order->get_meta( Meta::LINK ), 'order #' . $order->get_id() . ' held and emailed a link (' . $order->get_status() . ', ' . $order->get_meta( Meta::LINK ) . ')' );
	return $order;
}

/** Checks a run's recorded withdrawal of $order's link. */
function navexa_w5_withdrawn( $order, $calls_before, $what ) {
	$order   = navexa_w5_reload( $order );
	$vid     = $order->get_meta( Meta::VERIFICATION_ID );
	$revokes = navexa_w5_calls( 'RevokeVerificationLinks' );
	$last    = end( $revokes );
	navexa_w5_check( count( $revokes ) === $calls_before + 1 && 'liveFulfilment' === $last['key'] && $vid === $last['body']['verificationId'] && 1 === preg_match( '/^rvk:' . preg_quote( $vid, '/' ) . ':[0-9a-f]{16}$/', $last['body']['idempotencyKey'] ), $what . ': RevokeVerificationLinks with the fulfilment key and a stored key' );
	navexa_w5_check( 'revoked:' . $vid === $order->get_meta( Meta::REVOKE ) && '1' === (string) $order->get_meta( Meta::REVOKE_DETAIL ) && '' === $order->get_meta( Meta::REVOKE_KEY ), $what . ': recorded on the order (' . $order->get_meta( Meta::REVOKE ) . ', ' . $order->get_meta( Meta::REVOKE_DETAIL ) . ')' );
	navexa_w5_check( 'revoked:' . $vid === $order->get_meta( Meta::LINK ) && '' === $order->get_meta( Meta::LINK_ID ), $what . ': the link is on record as withdrawn' );
	navexa_w5_check( navexa_w5_has_note( $order, 'withdrew its emailed verification link (1 was still open)' ), $what . ': order note' );
	$links = navexa_w5_links( $vid );
	navexa_w5_check( 1 === count( $links ) && 'merchant' === ( $links[0]['revokedReason'] ?? '' ), $what . ': AgeCheck withdrew it (' . ( $links[0]['revokedReason'] ?? 'open' ) . ')' );
	return $order;
}

/** Checks the withdrawal a deleted order's action made: $vid's links under the $key it carried. */
function navexa_w5_deleted( $order_id, $vid, $key, $calls_before, $what ) {
	$revokes = navexa_w5_calls( 'RevokeVerificationLinks' );
	$last    = end( $revokes );
	navexa_w5_check( count( $revokes ) === $calls_before + 1 && 'liveFulfilment' === $last['key'] && $vid === $last['body']['verificationId'] && $key === $last['body']['idempotencyKey'], $what . ': RevokeVerificationLinks with the verification and key its action carried' );
	$links = navexa_w5_links( $vid );
	navexa_w5_check( 1 === count( $links ) && 'merchant' === ( $links[0]['revokedReason'] ?? '' ), $what . ': AgeCheck withdrew it (' . ( $links[0]['revokedReason'] ?? 'open' ) . ')' );
	navexa_w5_check( array() === navexa_w5_pending( $order_id ), $what . ': nothing left queued' );
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
navexa_w5_mock( '/__mock/reset', new stdClass() );
navexa_w5_mock( '/__mock/config', array( 'org' => array( 'allowedOrigins' => array( Mode::store_origin() ) ), 'links' => 'on' ) );
Mode::flush();
wp_set_current_user( 1 );
$hpos = OrderUtil::custom_orders_table_usage_is_enabled();

WP_CLI::log( '--- W5: emailed links withdrawn once an order will not ship (HPOS ' . ( $hpos ? 'on' : 'off' ) . ', WooCommerce ' . WC_VERSION . ', Action Scheduler ' . ActionScheduler_Versions::instance()->latest_version() . ') ---' );

// 1. Cancelled: the status change goes through with no call; one queued action does it.
$a = navexa_w5_emailed( $product );
$a->update_status( 'cancelled', 'w5: cancelled by staff.' );
$a = navexa_w5_reload( $a );
navexa_w5_check( 'cancelled' === $a->get_status(), 'a held order can be cancelled (' . $a->get_status() . ')' );
navexa_w5_check( array() === navexa_w5_calls( 'RevokeVerificationLinks' ), 'no AgeCheck call in the status change' );
navexa_w5_check( 'pending:' . $a->get_meta( Meta::VERIFICATION_ID ) === $a->get_meta( Meta::REVOKE ), 'pending recorded (' . $a->get_meta( Meta::REVOKE ) . ')' );
navexa_w5_check( array( array( $a->get_id(), 0 ) ) === array_values( navexa_w5_pending( $a ) ), 'one action queued in the plugin\'s group' );
navexa_w5_check( isset( OrderPanel::rows( $a )['Link withdrawal'] ), 'the panel says it is pending' );
navexa_w5_check( 1 === navexa_w5_run( $a ), 'Action Scheduler ran it' );
$a = navexa_w5_withdrawn( $a, 0, 'cancelled' );
navexa_w5_check( Links::STATE_REVOKED === Links::send( $a, \Navexa\AgeCheck\Client::for_key( \Navexa\AgeCheck\Client::KEY_FULFILMENT ) ), 'never sent a new link afterwards' );
$a_id = $a->get_id();
$a->delete( false ); // WC_Order::delete() zeroes the object's id.
navexa_w5_check( array() === navexa_w5_pending( navexa_w5_reload( $a_id ) ), 'trashing it afterwards queues nothing (withdrawn, nothing sent since)' );

// 2. A partial refund withdraws nothing; refunding the rest does.
$b      = navexa_w5_emailed( $product );
$refund = wc_create_refund( array( 'order_id' => $b->get_id(), 'amount' => '1.00', 'reason' => 'w5 partial' ) );
$b      = navexa_w5_reload( $b );
navexa_w5_check( ! is_wp_error( $refund ) && Hold::STATUS === $b->get_status() && '' === $b->get_meta( Meta::REVOKE ) && array() === navexa_w5_pending( $b ), 'a partial refund withdraws nothing (' . $b->get_status() . ')' );
$refund = wc_create_refund( array( 'order_id' => $b->get_id(), 'amount' => wc_format_decimal( $b->get_remaining_refund_amount() ), 'reason' => 'w5 full' ) );
$b      = navexa_w5_reload( $b );
navexa_w5_check( ! is_wp_error( $refund ) && 'refunded' === $b->get_status() && 1 === count( navexa_w5_pending( $b ) ), 'refunded in full: one action queued (' . $b->get_status() . ')' );
navexa_w5_run( $b );
navexa_w5_withdrawn( $b, 1, 'refunded in full' );

// 3. Trashed: WC_Order::delete(), and on the posts table wp_trash_post() as its Orders screen does.
$c    = navexa_w5_emailed( $product );
$c_id = $c->get_id();
$c->delete( false );
$c = navexa_w5_reload( $c_id );
navexa_w5_check( 'trash' === $c->get_status() && 1 === count( navexa_w5_pending( $c ) ), 'trashed (WC_Order::delete): one action queued (' . $c->get_status() . ')' );
navexa_w5_run( $c );
navexa_w5_withdrawn( $c, 2, 'trashed' );
$calls = 3;
if ( ! $hpos ) {
	$d = navexa_w5_emailed( $product );
	wp_trash_post( $d->get_id() );
	$d = navexa_w5_reload( $d );
	navexa_w5_check( 'trash' === $d->get_status() && 1 === count( navexa_w5_pending( $d ) ), 'trashed (wp_trash_post, posts table): one action queued (' . $d->get_status() . ')' );
	navexa_w5_run( $d );
	navexa_w5_withdrawn( $d, $calls, 'trashed on the posts table' );
	++$calls;
}

// 4. Failed during an AgeCheck outage: retried with the same key.
$e = navexa_w5_emailed( $product );
navexa_w5_mock( '/__mock/faults', array( 'rpc' => 'RevokeVerificationLinks', 'code' => 'unavailable', 'retryAfter' => 1, 'times' => 1 ) );
$e->update_status( 'failed' );
navexa_w5_run( $e );
$e       = navexa_w5_reload( $e );
$pending = navexa_w5_pending( $e );
navexa_w5_check( 'pending:' . $e->get_meta( Meta::VERIFICATION_ID ) === $e->get_meta( Meta::REVOKE ) && '' !== $e->get_meta( Meta::REVOKE_KEY ) && array( array( $e->get_id(), 1 ) ) === array_values( $pending ), 'an outage: still pending, key kept, retry scheduled' );
navexa_w5_run( $e );
navexa_w5_withdrawn( $e, $calls + 1, 'failed, after a retry' );
$revokes = navexa_w5_calls( 'RevokeVerificationLinks' );
navexa_w5_check( $revokes[ $calls ]['body']['idempotencyKey'] === $revokes[ $calls + 1 ]['body']['idempotencyKey'], 'the retry replayed the stored key' );
$calls += 2;

// 5. A refusal is recorded at once, with a note; staff can still withdraw.
$f = navexa_w5_emailed( $product );
navexa_w5_mock( '/__mock/faults', array( 'rpc' => 'RevokeVerificationLinks', 'code' => 'permission_denied', 'times' => 1 ) );
$f->update_status( 'cancelled' );
navexa_w5_run( $f );
$f = navexa_w5_reload( $f );
navexa_w5_check( 'failed:' . $f->get_meta( Meta::VERIFICATION_ID ) === $f->get_meta( Meta::REVOKE ) && 'permission_denied' === $f->get_meta( Meta::REVOKE_DETAIL ) && array() === navexa_w5_pending( $f ), 'a refusal is recorded, not retried' );
navexa_w5_check( navexa_w5_has_note( $f, 'could not withdraw its emailed verification link (permission_denied)' ) && Links::can_withdraw( $f ), 'noted; the link stays on record as open (fail closed)' );
++$calls;

// 6. Restored before the run: the link is wanted again.
$g = navexa_w5_emailed( $product );
$g->update_status( 'cancelled' );
$g = navexa_w5_reload( $g );
$g->update_status( Hold::STATUS );
navexa_w5_run( $g );
$g = navexa_w5_reload( $g );
navexa_w5_check( count( navexa_w5_calls( 'RevokeVerificationLinks' ) ) === $calls && '' === $g->get_meta( Meta::REVOKE ) && 'sent:' . $g->get_meta( Meta::VERIFICATION_ID ) === $g->get_meta( Meta::LINK ), 'held again before the run: nothing withdrawn (' . $g->get_status() . ')' );

// 7. Orders that never queue: unpaid without AgeCheck state, and one carrying another order's copied state.
$plain = wc_create_order( array( 'status' => 'pending' ) );
$plain->add_product( $product, 1 );
$plain->save();
$plain->update_status( 'cancelled' );
$source = navexa_w5_emailed( $product );
$copy   = wc_create_order( array( 'status' => 'pending' ) );
foreach ( $source->get_meta_data() as $meta ) {
	if ( 0 === strpos( $meta->key, '_navexa_agecheck_' ) ) {
		$copy->update_meta_data( $meta->key, $meta->value );
	}
}
$copy->save();
$copy->update_status( 'cancelled' );
navexa_w5_check( array() === navexa_w5_pending( $plain ) && array() === navexa_w5_pending( $copy ) && '' === navexa_w5_reload( $copy )->get_meta( Meta::REVOKE ), 'nothing queued for an unpaid order or a copy of another order' );
navexa_w5_check( count( navexa_w5_calls( 'RevokeVerificationLinks' ) ) === $calls && 1 === count( array_filter( navexa_w5_links( $source->get_meta( Meta::VERIFICATION_ID ) ), static function ( $link ) { return empty( $link['revokedAt'] ); } ) ), 'the original order\'s link is untouched' );

// 8. Deleted for good: straight away, and from the trash before the trash's own run.
$h     = navexa_w5_emailed( $product );
$h_id  = $h->get_id();
$h_vid = $h->get_meta( Meta::VERIFICATION_ID );
$h->delete( true );
$jobs = array_values( navexa_w5_pending( $h_id ) );
navexa_w5_check( false === navexa_w5_reload( $h_id ) && 1 === count( $jobs ) && 4 === count( $jobs[0] ) && $h_vid === $jobs[0][2], 'deleted for good: one action, carrying the verification (' . wp_json_encode( array_map( 'count', $jobs ) ) . ')' );
navexa_w5_check( count( navexa_w5_calls( 'RevokeVerificationLinks' ) ) === $calls, 'no AgeCheck call in the deletion' );
navexa_w5_run( $h_id );
navexa_w5_deleted( $h_id, $h_vid, $jobs[0][3], $calls, 'deleted for good' );
++$calls;

$i     = navexa_w5_emailed( $product );
$i_id  = $i->get_id();
$i_vid = $i->get_meta( Meta::VERIFICATION_ID );
if ( $hpos ) {
	$i->delete( false );
} else {
	wp_trash_post( $i_id );
}
navexa_w5_check( array( array( $i_id, 0 ) ) === array_values( navexa_w5_pending( $i_id ) ), 'trashed: its own run queued' );
if ( $hpos ) {
	navexa_w5_reload( $i_id )->delete( true ); // "Delete permanently" before that run.
} else {
	wp_delete_post( $i_id, true ); // "Empty Trash" before that run.
}
$carried = array_values( array_filter( navexa_w5_pending( $i_id ), static function ( $args ) { return 4 === count( $args ); } ) );
navexa_w5_check( false === navexa_w5_reload( $i_id ) && 2 === count( navexa_w5_pending( $i_id ) ) && 1 === count( $carried ) && $i_vid === $carried[0][2], 'deleted from the trash before its run: one more action, carrying the verification (' . ( $hpos ? 'WC_Order::delete' : 'wp_delete_post' ) . ')' );
navexa_w5_run( $i_id );
navexa_w5_deleted( $i_id, $i_vid, $carried[0][3], $calls, 'deleted from the trash' );
++$calls;

// 9. A failed order paid again after its link was withdrawn: held again, emailed a new link.
$j     = navexa_w5_emailed( $product );
$j_vid = $j->get_meta( Meta::VERIFICATION_ID );
$j->update_status( 'failed' );
navexa_w5_run( $j );
$j = navexa_w5_withdrawn( $j, $calls, 'failed, to be paid again' );
++$calls;
$j->payment_complete( 'txn_w5_again_' . wp_generate_password( 6, false ) );
$j = navexa_w5_reload( $j );
navexa_w5_check( Hold::STATUS === $j->get_status(), 'paid again: held again (' . $j->get_status() . ')' );
Release::run( $j->get_id(), Release::TRIGGER_HOLD );
$j    = navexa_w5_reload( $j );
$open = array_values( array_filter( navexa_w5_links( $j_vid ), static function ( $link ) { return empty( $link['revokedAt'] ); } ) );
navexa_w5_check( 'sent:' . $j_vid === $j->get_meta( Meta::LINK ) && 1 === count( $open ) && $open[0]['linkId'] === $j->get_meta( Meta::LINK_ID ), 'held again: its release run emailed a new link (' . $j->get_meta( Meta::LINK ) . ')' );

// 10. A withdrawal whose action died (Action Scheduler never retries it) is queued again by the sweep.
$k = navexa_w5_emailed( $product );
$k->update_status( 'cancelled' );
as_unschedule_action( LinkRevoke::HOOK, array( $k->get_id(), 0 ), Release::GROUP );
$k = navexa_w5_reload( $k );
$k->update_meta_data( Meta::REVOKE_AT, time() - LinkRevoke::REQUEUE_AFTER - 60 );
$k->save_meta_data();
$k = navexa_w5_reload( $k );
navexa_w5_check( array() === navexa_w5_pending( $k ) && 'pending:' . $k->get_meta( Meta::VERIFICATION_ID ) === $k->get_meta( Meta::REVOKE ), 'pending, with no action left' );
\Navexa\AgeCheck\Sweep::run();
$k = navexa_w5_reload( $k );
navexa_w5_check( array( array( $k->get_id(), 0 ) ) === array_values( navexa_w5_pending( $k ) ) && (int) $k->get_meta( Meta::REVOKE_AT ) > time() - 60, 'the sweep queued it again and renewed its stamp (' . $k->get_meta( Meta::REVOKE_AT ) . ')' );
navexa_w5_run( $k );
navexa_w5_withdrawn( $k, $calls, 'queued again by the sweep' );
++$calls;

if ( $GLOBALS['navexa_w5_failures'] > 0 ) {
	WP_CLI::error( $GLOBALS['navexa_w5_failures'] . ' W5 check(s) failed' );
}
WP_CLI::success( 'W5 checks passed' );
