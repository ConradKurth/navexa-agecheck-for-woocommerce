<?php
/**
 * ReportShipment for completed orders.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * When an order that needed an age check is completed, tells AgeCheck it
 * shipped (ReportShipment, keyed by the order's external id). AgeCheck checks
 * every report against its clearance and flags a shipment that never cleared
 * (an override, or a plugin that fulfils on woocommerce_payment_complete
 * before the hold): the audit trail for what this plugin cannot block.
 *
 * Async through Action Scheduler; idempotent per order (AgeCheck keeps the
 * first report, and SHIPMENT_REPORTED stops repeats here).
 */
final class Shipment {

	const HOOK = 'navexa_agecheck_report_shipment';

	/**
	 * Retries before giving up on a report.
	 */
	const MAX_ATTEMPTS = 8;

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_status_completed', array( self::class, 'on_completed' ), 10, 2 );
		add_action( self::HOOK, array( self::class, 'run_job' ), 10, 2 );
	}

	/**
	 * Queues the report for a completed order that needed an age check.
	 *
	 * @param int|string $order_id Order id.
	 * @param mixed      $order    Order.
	 */
	public static function on_completed( $order_id, $order = null ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( ! $order instanceof \WC_Order || ! self::reportable( $order ) ) {
			return;
		}
		Release::queue_once( self::HOOK, array( (int) $order->get_id(), 0 ) );
	}

	/**
	 * Whether the order needs a report: it needed an age check, has an
	 * external id of its own (never one copied from another order, such as a
	 * Subscriptions renewal carrying its parent's) and was not reported yet.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function reportable( \WC_Order $order ): bool {
		return 'yes' === Meta::get( $order, Meta::REQUIRED )
			&& '' !== Binding::owned_external_id( $order )
			&& 0 === Meta::get_int( $order, Meta::SHIPMENT_REPORTED );
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param int|string $order_id Order id.
	 * @param int|string $attempt  Retries so far.
	 */
	public static function run_job( $order_id, $attempt = 0 ): void {
		$order_id = absint( $order_id );
		$attempt  = absint( $attempt );
		try {
			self::report( $order_id );
		} catch ( ConnectError $e ) {
			if ( $e->is_retryable() && $attempt < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
				$delay = max( null === $e->retry_after() ? 0 : $e->retry_after(), (int) min( Release::MAX_BACKOFF, 60 * ( 2 ** $attempt ) ) );
				as_schedule_single_action( time() + $delay, self::HOOK, array( $order_id, $attempt + 1 ), Release::GROUP, false );
			}
		}
	}

	/**
	 * Reports the shipment.
	 *
	 * @param int         $order_id Order id.
	 * @param Client|null $client   Fulfilment-key client; null for the stored key.
	 * @return bool Whether AgeCheck acknowledged a report.
	 * @throws ConnectError On failure.
	 */
	public static function report( int $order_id, ?Client $client = null ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! self::reportable( $order ) ) {
			return false;
		}
		$client  = null === $client ? Client::for_key( Client::KEY_FULFILMENT ) : $client;
		$shipped = $order->get_date_completed();
		$at      = $shipped instanceof \DateTimeInterface ? min( time(), $shipped->getTimestamp() ) : time();
		$client->call(
			'ReportShipment',
			array(
				'orderExternalId' => Binding::owned_external_id( $order ),
				'shippedAt'       => gmdate( 'Y-m-d\TH:i:s\Z', $at ),
			)
		);
		Meta::set( $order, Meta::SHIPMENT_REPORTED, time() );
		$order->save_meta_data();
		Client::log( 'info', sprintf( 'agecheck shipment reported order=%d', $order_id ) );
		return true;
	}
}
