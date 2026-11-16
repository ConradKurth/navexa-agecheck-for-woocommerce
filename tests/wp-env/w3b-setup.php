<?php
/**
 * Helpers for tests/wp-env/w3b.mjs (W3 PRs 12 and 14 over HTTP):
 *
 *   wp eval-file tests/wp-env/w3b-setup.php user              the signed-in shopper (re)made
 *   wp eval-file tests/wp-env/w3b-setup.php mode <prepayment|hold>
 *   wp eval-file tests/wp-env/w3b-setup.php run <order id>    one release run (as Action Scheduler would)
 *   wp eval-file tests/wp-env/w3b-setup.php order <order id>  the order's AgeCheck state
 *
 * Needs prepayment-setup.php run first. Prints one JSON line.
 *
 * @package Navexa\AgeCheck\Tests
 */

// phpcs:ignoreFile -- test script.

use Navexa\AgeCheck\Admin\OrderPanel;
use Navexa\AgeCheck\CustomerRef;
use Navexa\AgeCheck\Links;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Release;

$args = isset( $args ) && is_array( $args ) ? $args : array();
$cmd  = isset( $args[0] ) ? $args[0] : '';

/** The order's AgeCheck state. */
function navexa_w3b_state( $order ) {
	wp_cache_flush();
	$order = wc_get_order( $order );
	if ( ! $order ) {
		return array( 'error' => 'no order' );
	}
	$rows = OrderPanel::rows( $order );
	return array(
		'status'     => $order->get_status(),
		'vid'        => $order->get_meta( Meta::VERIFICATION_ID ),
		'bound_by'   => $order->get_meta( Meta::BOUND_BY ),
		'pass_class' => $order->get_meta( Meta::PASS_CLASS ),
		'link'       => $order->get_meta( Meta::LINK ),
		'passed_by'  => isset( $rows['Passed by'] ) ? $rows['Passed by'] : '',
		'customer'   => (int) $order->get_customer_id(),
		'view_url'   => $order->get_view_order_url(),
	);
}

switch ( $cmd ) {
	case 'user':
		$login = 'w3b_shopper';
		$pass  = 'w3b-Shopper-pass-1';
		$user  = get_user_by( 'login', $login );
		$id    = $user ? $user->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => $pass, 'user_email' => 'w3b-shopper@example.com', 'role' => 'customer' ) );
		wp_set_password( $pass, $id );
		echo wp_json_encode(
			array(
				'user_id'      => (int) $id,
				'login'        => $login,
				'pass'         => $pass,
				'customer_ref' => CustomerRef::for_user( (int) $id ),
			)
		) . "\n";
		break;
	case 'mode':
		update_option( Options::MODE, 'hold' === ( $args[1] ?? '' ) ? Options::MODE_ACCEPT_AND_HOLD : Options::MODE_PRE_PAYMENT );
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . \Navexa\AgeCheck\RateLimit::table() );
		delete_option( Links::OPTION_BLOCK );
		as_unschedule_all_actions( '', array(), Release::GROUP );
		Mode::flush();
		echo wp_json_encode( array( 'mode' => Options::mode(), 'origin' => Mode::store_origin() ) ) . "\n";
		break;
	case 'run':
		$id      = (int) ( $args[1] ?? 0 );
		// WP-Cron may have run Action Scheduler (and the release) during the HTTP flow already.
		$queued  = as_get_scheduled_actions( array( 'hook' => Release::HOOK, 'group' => Release::GROUP, 'status' => array( ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING, ActionScheduler_Store::STATUS_COMPLETE ), 'args' => array( $id, 0, Release::TRIGGER_SHOPPER ) ), 'ids' );
		$outcome = Release::run( $id, Release::TRIGGER_SHOPPER );
		echo wp_json_encode( array( 'outcome' => $outcome, 'queued_by_return' => ! empty( $queued ) ) + navexa_w3b_state( $id ) ) . "\n";
		break;
	case 'order':
		echo wp_json_encode( navexa_w3b_state( (int) ( $args[1] ?? 0 ) ) ) . "\n";
		break;
	default:
		echo wp_json_encode( array( 'error' => 'unknown command' ) ) . "\n";
}
