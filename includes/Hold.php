<?php
/**
 * The "Awaiting age check" order status and everything that puts an order
 * into it or lets it out.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

use Navexa\AgeCheck\Admin\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * Holds age-restricted orders that have not passed in STATUS until AgeCheck
 * clears them.
 *
 * Two ways in, neither making a network call:
 * - Payment: woocommerce_pre_payment_complete marks the order as paying;
 *   woocommerce_payment_complete_order_status (priority 999) then answers
 *   STATUS instead of processing/completed for a required, unreleased order
 *   and records the status it replaced. Outside payment_complete() the filter
 *   answers unchanged, so WooCommerce's maybe_set_date_paid() never stamps a
 *   paid date on an unpaid order.
 * - Backstop: woocommerce_before_order_object_save catches every other move
 *   of a required, unreleased order to a status that is not known to be safe
 *   (processing, completed, or any custom "shipped" status): COD, BACS,
 *   gateways that set the status themselves, REST, admin edits, bulk actions.
 *
 * Every way out of STATUS other than cancelled, refunded, failed, trash or
 * this plugin's own release (AgeCheck cleared it, or the logged "Release
 * without age check" order action) is refused: the order stays held and gets
 * a note (owner decision Q11a).
 *
 * Entering STATUS reduces stock, sends the admin new-order email (WooCommerce
 * sends it only for pending to processing/completed/on-hold), sends no
 * customer email, and queues a release run at once, because binding an order
 * to its verification emits no webhook.
 */
final class Hold {

	/**
	 * The order status, without WooCommerce's wc- prefix.
	 */
	const STATUS = 'agecheck-hold';

	/**
	 * Statuses a held order may move to without being released.
	 */
	const ALLOWED_EXITS = array( 'cancelled', 'refunded', 'failed', 'trash' );

	/**
	 * Statuses an unreleased order may move to freely: none of them ships.
	 */
	const UNGATED = array( 'pending', 'on-hold', 'failed', 'cancelled', 'refunded', 'trash', 'checkout-draft', 'draft', 'auto-draft', 'new' );

	/**
	 * Orders inside payment_complete(), by id.
	 *
	 * @var array<int, bool>
	 */
	private static $paying = array();

	/**
	 * Orders this plugin is releasing right now, by id: the backstop lets them pass.
	 *
	 * @var array<int, bool>
	 */
	private static $releasing = array();

	/**
	 * Registers the status and the hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_status' ) );
		add_filter( 'wc_order_statuses', array( self::class, 'add_status' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( self::class, 'valid_for_payment_complete' ) );
		add_action( 'woocommerce_pre_payment_complete', array( self::class, 'payment_starting' ), 10, 1 );
		add_filter( 'woocommerce_payment_complete_order_status', array( self::class, 'payment_complete_status' ), 999, 3 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'payment_finished' ), 10, 1 );
		add_action( 'woocommerce_before_order_object_save', array( self::class, 'backstop' ), 10, 1 );
		add_action( 'woocommerce_order_status_' . self::STATUS, array( self::class, 'entered' ), 10, 3 );
	}

	/**
	 * Registers wc-agecheck-hold as a post status (the posts-table order list
	 * and its status counts need it; HPOS reads wc_order_statuses).
	 */
	public static function register_status(): void {
		register_post_status(
			'wc-' . self::STATUS,
			array(
				'label'                     => _x( 'Awaiting age check', 'Order status', 'navexa-agecheck-for-woocommerce' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders. */
				'label_count'               => _n_noop( 'Awaiting age check <span class="count">(%s)</span>', 'Awaiting age check <span class="count">(%s)</span>', 'navexa-agecheck-for-woocommerce' ),
			)
		);
	}

	/**
	 * Adds the status to WooCommerce's list, after "Pending payment".
	 *
	 * @param mixed $statuses wc-prefixed status => label.
	 * @return array<string, string>
	 */
	public static function add_status( $statuses ): array {
		$statuses = is_array( $statuses ) ? $statuses : array();
		$out      = array();
		$label    = _x( 'Awaiting age check', 'Order status', 'navexa-agecheck-for-woocommerce' );
		foreach ( $statuses as $key => $name ) {
			$out[ $key ] = $name;
			if ( 'wc-pending' === $key ) {
				$out[ 'wc-' . self::STATUS ] = $label;
			}
		}
		if ( ! isset( $out[ 'wc-' . self::STATUS ] ) ) {
			$out[ 'wc-' . self::STATUS ] = $label;
		}
		return $out;
	}

	/**
	 * Lets payment_complete() run on a held order, so a gateway that confirms
	 * the payment late still records its transaction; the status filter keeps
	 * the order held.
	 *
	 * @param mixed $statuses Statuses.
	 * @return string[]
	 */
	public static function valid_for_payment_complete( $statuses ): array {
		$statuses   = is_array( $statuses ) ? array_values( $statuses ) : array();
		$statuses[] = self::STATUS;
		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Marks the order as inside payment_complete().
	 *
	 * @param int|string $order_id Order id.
	 */
	public static function payment_starting( $order_id ): void {
		self::$paying[ (int) $order_id ] = true;
	}

	/**
	 * Clears the mark.
	 *
	 * @param int|string $order_id Order id.
	 */
	public static function payment_finished( $order_id ): void {
		unset( self::$paying[ (int) $order_id ] );
	}

	/**
	 * The payment-complete status: STATUS for a required order AgeCheck has not
	 * released, recorded with the status it replaces. Only inside
	 * payment_complete(); no network call.
	 *
	 * @param mixed           $status   Status WooCommerce would set.
	 * @param int|string      $order_id Order id.
	 * @param \WC_Order|mixed $order    Order (WooCommerce passes it since 2.7).
	 * @return mixed
	 */
	public static function payment_complete_status( $status, $order_id = 0, $order = null ) {
		$order_id = (int) $order_id;
		if ( ! isset( self::$paying[ $order_id ] ) || ! is_string( $status ) || self::STATUS === $status ) {
			return $status;
		}
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order || Meta::is_released( $order ) || ! Requirement::is_required( $order ) ) {
			return $status;
		}
		self::mark_held( $order, $status );
		Client::log( 'info', sprintf( 'agecheck hold order=%d trigger=payment_complete intercepted=%s', $order_id, sanitize_key( $status ) ) );
		return self::STATUS;
	}

	/**
	 * The backstop. Runs before every order save.
	 *
	 * @param mixed $order Order being saved.
	 */
	public static function backstop( $order ): void {
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
			return;
		}
		$changes = $order->get_changes();
		if ( ! array_key_exists( 'status', $changes ) ) {
			return;
		}
		$to   = self::bare( (string) $changes['status'] );
		$base = $order->get_base_data();
		$from = isset( $base['status'] ) ? self::bare( (string) $base['status'] ) : '';
		$id   = (int) $order->get_id();
		if ( $from === $to || ( $id > 0 && isset( self::$releasing[ $id ] ) ) ) {
			return;
		}

		if ( self::STATUS === $from ) {
			if ( in_array( $to, self::ALLOWED_EXITS, true ) || Meta::is_released( $order ) ) {
				return;
			}
			self::refuse_exit( $order, $to );
			return;
		}

		if ( self::STATUS === $to ) {
			// Payment, staff or another plugin chose "Awaiting age check": it is
			// ours. mark_held() also drops AgeCheck state copied from another order.
			Meta::set( $order, Meta::REQUIRED, 'yes' );
			self::mark_held( $order, '' );
			return;
		}
		if ( in_array( $to, self::UNGATED, true ) || Meta::is_released( $order ) || ! Requirement::is_required( $order ) ) {
			return;
		}

		self::mark_held( $order, $to );
		$order->set_status(
			self::STATUS,
			sprintf(
				/* translators: %s: the status the order was moving to, e.g. Processing. */
				__( 'AgeCheck: held until the shopper\'s age check passes (it was moving to %s).', 'navexa-agecheck-for-woocommerce' ),
				self::status_name( $to )
			)
		);
		Client::log( 'info', sprintf( 'agecheck hold order=%d trigger=status_change intercepted=%s', $id, sanitize_key( $to ) ) );
	}

	/**
	 * The order entered STATUS: reduce stock, send the admin new-order email,
	 * queue a release run.
	 *
	 * @param int|string           $order_id   Order id.
	 * @param \WC_Order|null|mixed $order      Order.
	 * @param array<string, mixed> $transition Status transition (from, to, note, manual).
	 */
	public static function entered( $order_id, $order = null, $transition = array() ): void {
		if ( is_array( $transition ) && isset( $transition['from'] ) && self::STATUS === $transition['from'] ) {
			return; // A refused exit, not an entry.
		}
		$order_id = (int) $order_id;
		if ( function_exists( 'wc_maybe_reduce_stock_levels' ) ) {
			wc_maybe_reduce_stock_levels( $order_id );
		}
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order instanceof \WC_Order ) {
			self::send_email( 'WC_Email_New_Order', $order );
		}
		Release::enqueue( $order_id, Release::TRIGGER_HOLD );
	}

	/**
	 * Releases a held order to the status it was intercepted on its way to
	 * (processing when unknown) and sends the customer processing email
	 * WooCommerce skips for this transition. Only an order this plugin holds
	 * (status STATUS, HELD yes) is touched. Callers decide that it may go:
	 * AgeCheck cleared it (Release) or staff overrode (override()).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $note  Order note for the transition.
	 * @return bool Whether the order left the hold.
	 */
	public static function release( \WC_Order $order, string $note ): bool {
		$id = (int) $order->get_id();
		if ( $id <= 0 || self::STATUS !== $order->get_status( 'edit' ) || 'yes' !== Meta::get( $order, Meta::HELD ) ) {
			return false;
		}
		$target = self::bare( Meta::get( $order, Meta::INTERCEPTED_STATUS ) );
		if ( '' === $target || self::STATUS === $target || in_array( $target, self::UNGATED, true ) || ! array_key_exists( 'wc-' . $target, wc_get_order_statuses() ) ) {
			$target = $order->needs_processing() ? 'processing' : 'completed';
		}
		self::$releasing[ $id ] = true;
		try {
			Meta::set( $order, Meta::HELD, '' );
			$order->set_status( $target, $note );
			$order->save();
		} finally {
			unset( self::$releasing[ $id ] );
		}
		if ( $target !== $order->get_status( 'edit' ) ) {
			return false;
		}
		if ( 'processing' === $target ) {
			self::send_email( 'WC_Email_Customer_Processing_Order', $order );
		}
		Client::log( 'info', sprintf( 'agecheck released order=%d to=%s', $id, sanitize_key( $target ) ) );
		return true;
	}

	/**
	 * The logged "Release without age check" action: staff take responsibility
	 * for the buyer's age. Recorded on the order (who, when, why) and in the
	 * log (ids only). ReportShipment later tells AgeCheck the order shipped
	 * without a clearance.
	 *
	 * @param \WC_Order $order   Order.
	 * @param int       $user_id Staff user id.
	 * @param string    $reason  Why (required; staff's own words).
	 * @return bool Whether the order left the hold.
	 */
	public static function override( \WC_Order $order, int $user_id, string $reason ): bool {
		$reason = trim( $reason );
		if ( $user_id <= 0 || '' === $reason || self::STATUS !== $order->get_status( 'edit' ) ) {
			return false;
		}
		$user     = get_userdata( $user_id );
		$name     = $user instanceof \WP_User ? $user->display_name : '';
		$unlisted = Meta::mark_released( $order, Meta::STATUS_OVERRIDE );
		Meta::set( $order, Meta::REASON, 'override' );
		Meta::set( $order, Meta::OVERRIDE_BY, $user_id );
		Meta::set( $order, Meta::OVERRIDE_AT, time() );
		Client::log( 'warning', sprintf( 'agecheck override order=%d user=%d', $order->get_id(), $user_id ) );
		$note = sprintf(
			/* translators: 1: staff member's name, 2: their user id, 3: the reason they gave. */
			__( 'AgeCheck: released WITHOUT an age check by %1$s (user #%2$d). Reason given: %3$s', 'navexa-agecheck-for-woocommerce' ),
			$name,
			$user_id,
			$reason
		);
		$released = self::release( $order, $note );
		if ( ! $released ) {
			$order->save();
		}
		if ( $unlisted ) {
			Notices::flush_counts();
		}
		return $released;
	}

	/**
	 * Whether this plugin is releasing $order_id right now.
	 *
	 * @param int $order_id Order id.
	 */
	public static function is_releasing( int $order_id ): bool {
		return isset( self::$releasing[ $order_id ] );
	}

	/**
	 * Records that the plugin holds $order (unsaved).
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $wanted The status it was moving to ('' when unknown).
	 */
	private static function mark_held( \WC_Order $order, string $wanted ): void {
		// First: drops state copied from another order before this order's own
		// is recorded. Every held order gets its external id now, so a later
		// override can still be reported to AgeCheck as a shipment.
		Binding::external_id( $order );
		$wanted = self::bare( $wanted );
		if ( '' !== $wanted && self::STATUS !== $wanted && '' === Meta::get( $order, Meta::INTERCEPTED_STATUS ) ) {
			Meta::set( $order, Meta::INTERCEPTED_STATUS, $wanted );
		}
		Meta::set( $order, Meta::HELD, 'yes' );
		if ( 0 === Meta::get_int( $order, Meta::HELD_AT ) ) {
			Meta::set( $order, Meta::HELD_AT, time() );
		}
		if ( '' === Meta::get( $order, Meta::STATUS ) ) {
			Meta::set( $order, Meta::STATUS, Meta::STATUS_PENDING );
		}
	}

	/**
	 * Keeps a held order held and says why.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $to    The refused status.
	 */
	private static function refuse_exit( \WC_Order $order, string $to ): void {
		$order->set_status( self::STATUS, false );
		self::drop_status_transition( $order );
		$message = sprintf(
			/* translators: %s: the refused status, e.g. Processing. */
			__( 'AgeCheck: this order has not passed its age check, so it stays "Awaiting age check" instead of moving to %s. To release it without an age check, choose "AgeCheck: release without age check" in Order actions.', 'navexa-agecheck-for-woocommerce' ),
			self::status_name( $to )
		);
		Meta::note( $order, $message );
		Notices::flash( $message );
		Client::log( 'warning', sprintf( 'agecheck refused status change order=%d to=%s', $order->get_id(), sanitize_key( $to ) ) );
	}

	/**
	 * Forgets the status transition a refused exit leaves behind. set_status()
	 * above records it as STATUS to STATUS. On save WooCommerce would then fire
	 * woocommerce_order_status_changed (and ..._agecheck-hold_to_agecheck-hold)
	 * for a status that did not change, on every version; WooCommerce 9.9 also
	 * adds an "Order status changed from Awaiting age check to Awaiting age
	 * check" note, because it ignores note === false (11.3 honours it).
	 * WC_Order has no public way to drop a pending transition, so the
	 * protected property is reset. The order stays held either way: a
	 * WooCommerce that renames the property only brings the redundant note
	 * and actions back.
	 *
	 * @param \WC_Order $order Order whose base status is STATUS.
	 */
	private static function drop_status_transition( \WC_Order $order ): void {
		try {
			$property = new \ReflectionProperty( \WC_Order::class, 'status_transition' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$transition = $property->getValue( $order );
			if ( is_array( $transition ) && isset( $transition['from'], $transition['to'] ) && $transition['from'] === $transition['to'] ) {
				$property->setValue( $order, false );
			}
		} catch ( \ReflectionException $e ) {
			unset( $e );
		}
	}

	/**
	 * Triggers one of WooCommerce's emails for $order.
	 *
	 * @param string    $email_class WC_Email class name (the mailer's key).
	 * @param \WC_Order $order       Order.
	 */
	private static function send_email( string $email_class, \WC_Order $order ): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails[ $email_class ] ) && is_object( $emails[ $email_class ] ) && is_callable( array( $emails[ $email_class ], 'trigger' ) ) ) {
			$emails[ $email_class ]->trigger( $order->get_id(), $order );
		}
	}

	/**
	 * A status without the wc- prefix.
	 *
	 * @param string $status Status.
	 */
	private static function bare( string $status ): string {
		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * A status's label.
	 *
	 * @param string $status Status without prefix.
	 */
	private static function status_name( string $status ): string {
		return function_exists( 'wc_get_order_status_name' ) ? (string) wc_get_order_status_name( $status ) : $status;
	}
}
