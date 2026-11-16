<?php
/**
 * Binding orders to AgeCheck verifications.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

use Navexa\AgeCheck\Checkout\Gate;
use Navexa\AgeCheck\Checkout\Session;

defined( 'ABSPATH' ) || exit;

/**
 * Binds an order to the verification that clears it.
 *
 * - Checkout verification (pre-payment mode): the shopper verified before
 *   paying, and the cart checkout's gate (Checkout\Gate::check(), run by
 *   classic woocommerce_after_checkout_validation and by Checkout\Blocks for
 *   the Store API cart route) admitted the session's guarded verification in
 *   this request. On woocommerce_checkout_order_processed and
 *   woocommerce_store_api_checkout_order_processed that verification, and
 *   only that one (checkout_vid()), moves from the session to the order: the
 *   order records it with the hash of its ship-to (SHIP_TO_HASH, what a
 *   resumed order is admitted at), Session::forget_verification() drops the
 *   id, the guard and the session record, and AttachOrder binds it with the
 *   order's ship-to, idempotency key ao:{external id}. The Store API hook
 *   also fires on every order-pay POST, where no gate runs: that order, an
 *   order placed in accept-and-hold mode, and any order placed without the
 *   gate bind nothing here and take the order-bound path below (owner
 *   decision Q11b), even when the session still holds an older check. The
 *   hooks are re-entrant: an order that already has a verification id is
 *   left alone, so a failed-payment retry does not bind twice. A transient
 *   failure leaves ATTACH_PENDING for the release run and the sweep;
 *   checkout never fails on it.
 * - Order-bound verification (accept-and-hold, order-pay, or a checkout
 *   verification that cannot be used): CreateVerification with the order id
 *   and ship-to, the billing name, email and address as a partial identity
 *   (never a date of birth) and document_name_check REQUIRED, because an
 *   emailed link must match the ID to the billing name (owner decision Q7).
 *   The billing address (OrderAddress::billing(), the same normalizer as the
 *   ship-to) is the residential address the engine's data match (E5,
 *   data_then_doc) compares with the ship-to: an order shipping to its
 *   billing address may pass on the data match, any other takes the ID
 *   check. A signed-in customer's order also carries customer_ref
 *   (CustomerRef: a keyed hash of the WooCommerce customer id, never PII;
 *   guests none), so a returning customer may pass at once on their earlier
 *   check while AgeCheck's per-store reuse is on (E12a, PASS_CLASS_REUSED,
 *   which clears like any pass). Then Links emails the shopper a link while
 *   the verification waits on them.
 *
 * AttachOrder answers FAILED_PRECONDITION for a verification bound to any
 * order, this one included, so a re-attach after its idempotency key expired
 * (24 h) confirms with GetVerification that the order it holds is ours.
 *
 * Engine contract (E4a, in production since tax v0.0.164; proto
 * agecheck/v1/verification.proto): an identity without a date of birth is
 * accepted under an ID-only pass policy (a policy that runs the database
 * check answers INVALID_ARGUMENT naming the missing field, which leaves the
 * order held for review); with order_external_id, document_name_check is
 * always REQUIRED and identity.name required; return_url is signed into the
 * hosted link; AttachOrder answers FAILED_PRECONDITION "ship_to_changed" when
 * the shopper verified for another intended ship-to.
 */
final class Binding {

	const BOUND    = 'bound';
	const NO_VID   = 'no_vid';
	const REVERIFY = 'reverify';
	const REVIEW   = 'review';
	const DENIED   = 'denied';

	/**
	 * Meta that belongs to the order named by the external id
	 * (forget_foreign()).
	 */
	const COPIED_STATE = array(
		Meta::EXTERNAL_ID,
		Meta::VERIFICATION_ID,
		Meta::VERIFICATION_STATUS,
		Meta::PREVIOUS_VERIFICATION_IDS,
		Meta::ATTACH_PENDING,
		Meta::BOUND_AT,
		Meta::BOUND_BY,
		Meta::SHIP_TO_HASH,
		Meta::STATUS,
		Meta::RELEASED_FOR,
		Meta::REASON,
		Meta::CLEARANCE_KEY,
		Meta::CLEARED_AT,
		Meta::CREATE_ATTEMPT,
		Meta::PASS_CLASS,
		Meta::LINK,
		Meta::LINK_ID,
		Meta::LINK_EXPIRES_AT,
		Meta::LINK_SENT_AT,
		Meta::LINK_KEY,
		Meta::REVOKE,
		Meta::REVOKE_KEY,
		Meta::REVOKE_DETAIL,
		Meta::REVOKE_AT,
		Meta::REFUND,
		Meta::SHIPMENT_REPORTED,
		Meta::NEXT_CHECK_AT,
		Meta::HOLD_STALE_AT,
		Meta::STALE_NOTED_AT,
		Meta::OVERRIDE_BY,
		Meta::OVERRIDE_AT,
	);

	/**
	 * Registers the checkout hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'on_classic_order_processed' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'on_store_api_order_processed' ), 10, 1 );
	}

	/**
	 * Classic checkout placed an order.
	 *
	 * @param int|string $order_id Order id.
	 * @param mixed      $posted   Posted data (unused).
	 * @param mixed      $order    Order.
	 */
	public static function on_classic_order_processed( $order_id, $posted = array(), $order = null ): void {
		unset( $posted );
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( $order instanceof \WC_Order ) {
			self::bind_checkout( $order );
		}
	}

	/**
	 * The Store API placed (or is paying for) an order.
	 *
	 * @param mixed $order Order.
	 */
	public static function on_store_api_order_processed( $order ): void {
		if ( $order instanceof \WC_Order ) {
			self::bind_checkout( $order );
		}
	}

	/**
	 * Records whether the order needs a check and binds the session's
	 * verification to it. Never throws.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function bind_checkout( \WC_Order $order ): void {
		try {
			if ( ! Requirement::is_required( $order ) ) {
				$order->save_meta_data();
				return;
			}
			self::external_id( $order );
			if ( '' !== Meta::get( $order, Meta::VERIFICATION_ID ) ) {
				$order->save_meta_data();
				return;
			}
			$vid = self::checkout_vid();
			if ( '' === $vid ) {
				$order->save_meta_data();
				return;
			}
			Meta::set( $order, Meta::VERIFICATION_ID, $vid );
			Meta::set( $order, Meta::ATTACH_PENDING, 'yes' );
			$ship_to = OrderAddress::ship_to( $order );
			if ( null !== $ship_to ) {
				// What the guard admitted: a payment retried before AttachOrder
				// answers is still admitted at this ship-to (Checkout\Gate).
				Meta::set( $order, Meta::SHIP_TO_HASH, OrderAddress::hash( $ship_to ) );
			}
			$order->save_meta_data();
			Session::forget_verification();
			try {
				self::attach( $order, Client::for_key( Client::KEY_FULFILMENT ) );
			} catch ( ConnectError $e ) {
				// ATTACH_PENDING stays; the release run and the sweep retry.
				unset( $e );
			}
			$order->save_meta_data();
		} catch ( \Throwable $e ) {
			Client::log( 'error', sprintf( 'agecheck bind failed order=%d error=%s', $order->get_id(), get_class( $e ) ) );
		}
	}

	/**
	 * The order's external id, wc:{install_id}:{blog_id}:{order_id}: the stored
	 * one, or a new one stored on the order (unsaved). '' while the install is
	 * paused or the order has no id: a copied site must not mint ids under the
	 * original site's install_id.
	 *
	 * A stored id is used only when its blog and order id are this order's
	 * (its install_id may be an older one). Anything else was copied from
	 * another order, so forget_foreign() drops it and the state that came with
	 * it, and a new id is minted.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function external_id( \WC_Order $order ): string {
		self::forget_foreign( $order );
		$stored = Meta::get( $order, Meta::EXTERNAL_ID );
		if ( '' !== $stored ) {
			return $stored; // This order's own: forget_foreign() dropped any other.
		}
		$install = Install::install_id();
		$id      = (int) $order->get_id();
		if ( '' === $install || $id <= 0 || Install::is_paused() ) {
			return '';
		}
		$external = sprintf( 'wc:%s:%d:%d', $install, get_current_blog_id(), $id );
		Meta::set( $order, Meta::EXTERNAL_ID, $external );
		return $external;
	}

	/**
	 * The stored external id when it is this order's own, else '' (never
	 * mints one).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function owned_external_id( \WC_Order $order ): string {
		$stored = Meta::get( $order, Meta::EXTERNAL_ID );
		return '' !== $stored && self::owns( $order, $stored ) ? $stored : '';
	}

	/**
	 * Whether $external names this order on this blog.
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $external External id.
	 */
	public static function owns( \WC_Order $order, string $external ): bool {
		$parsed = self::parse_external_id( $external );
		return null !== $parsed
			&& $order->get_id() > 0
			&& (int) $order->get_id() === $parsed['order_id']
			&& get_current_blog_id() === $parsed['blog_id'];
	}

	/**
	 * Drops AgeCheck state copied from another order (unsaved).
	 *
	 * - The stored external id is not this order's: its verification,
	 *   binding, clearance, release, link, refund and shipment state belong to
	 *   that other order (duplicate-order plugins, imports, WooCommerce
	 *   Subscriptions copying the parent order's meta to renewals). Acting on
	 *   it would ask AgeCheck about the other order, or report this order's
	 *   shipment under its id. All of COPIED_STATE goes.
	 * - Otherwise, a cleared or override status not recorded for this order
	 *   (Meta::has_foreign_release()) goes, with its release details.
	 *
	 * Kept: REQUIRED (a copied 'yes' only makes this order stricter) and the
	 * hold bookkeeping, which follows this order's own status.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool Whether anything was dropped.
	 */
	public static function forget_foreign( \WC_Order $order ): bool {
		$stored = Meta::get( $order, Meta::EXTERNAL_ID );
		if ( '' !== $stored && ! self::owns( $order, $stored ) ) {
			$keys = self::COPIED_STATE;
		} elseif ( Meta::has_foreign_release( $order ) ) {
			$keys = array( Meta::STATUS, Meta::RELEASED_FOR, Meta::CLEARED_AT, Meta::OVERRIDE_BY, Meta::OVERRIDE_AT );
		} else {
			return false;
		}
		foreach ( $keys as $key ) {
			Meta::set( $order, $key, '' );
		}
		Meta::note( $order, __( 'AgeCheck: this order carried age check details copied from another order. They were removed; this order needs its own age check.', 'navexa-agecheck-for-woocommerce' ) );
		Client::log( 'warning', sprintf( 'agecheck dropped copied state order=%d', $order->get_id() ) );
		return true;
	}

	/**
	 * The blog and order id in an external id, or null when it is not one of
	 * this plugin's.
	 *
	 * @param string $external External id.
	 * @return array{blog_id: int, order_id: int}|null
	 */
	public static function parse_external_id( string $external ): ?array {
		if ( ! preg_match( '/^wc:[0-9a-f]{32}:(\d{1,10}):(\d{1,20})$/', $external, $m ) ) {
			return null;
		}
		return array(
			'blog_id'  => (int) $m[1],
			'order_id' => (int) $m[2],
		);
	}

	/**
	 * Binds the order's verification with AttachOrder (unsaved meta changes).
	 *
	 * @param \WC_Order $order  Order.
	 * @param Client    $client Fulfilment-key client.
	 * @return string BOUND, NO_VID, REVERIFY, REVIEW or DENIED (REASON says why).
	 * @throws ConnectError A failure a later run may get past.
	 */
	public static function attach( \WC_Order $order, Client $client ): string {
		self::forget_foreign( $order );
		$vid = Meta::get( $order, Meta::VERIFICATION_ID );
		if ( '' === $vid ) {
			return self::NO_VID;
		}
		if ( 'yes' !== Meta::get( $order, Meta::ATTACH_PENDING ) && Meta::get_int( $order, Meta::BOUND_AT ) > 0 ) {
			return self::BOUND;
		}
		$external = self::require_external_id( $order, 'AttachOrder' );
		$ship_to  = OrderAddress::ship_to( $order );
		if ( null === $ship_to ) {
			return self::review( $order, 'no_ship_to' );
		}
		if ( ! OrderAddress::is_us( $ship_to ) ) {
			Meta::set( $order, Meta::REASON, 'not_us' );
			return self::DENIED;
		}
		try {
			$answer = $client->call(
				'AttachOrder',
				array(
					'idempotencyKey'  => 'ao:' . $external,
					'verificationId'  => $vid,
					'orderExternalId' => $external,
					'shipTo'          => $ship_to,
				)
			);
		} catch ( ConnectError $e ) {
			$code = $e->connect_code();
			if ( '' !== $e->local_reason() || ! in_array( $code, array( 'failed_precondition', 'already_exists', 'invalid_argument', 'not_found' ), true ) ) {
				throw $e;
			}
			if ( 'not_found' !== $code && self::bound_order( $client, $vid ) === $external ) {
				self::mark_bound( $order, $vid, 'attach', $ship_to );
				return self::BOUND;
			}
			switch ( $code ) {
				case 'failed_precondition':
					// E4a: the shopper verified for another ship-to (ship_to_changed),
					// or the verification already holds another order. Either way this
					// order needs a verification of its own.
					$changed = false !== strpos( $e->server_message(), 'ship_to_changed' );
					self::reverify( $order, $changed ? 'ship_to_changed' : 'verification_bound_elsewhere' );
					return self::REVERIFY;
				case 'already_exists':
					return self::adopt_bound( $order, $client, $external, $ship_to );
				case 'not_found':
					return self::review( $order, 'verification_not_found' );
				default:
					return self::review( $order, 'invalid_ship_to' );
			}
		}
		self::mark_bound( $order, $vid, 'attach', $ship_to );
		$verification = isset( $answer['verification'] ) && is_array( $answer['verification'] ) ? $answer['verification'] : array();
		self::record_verification( $order, $verification );
		return self::BOUND;
	}

	/**
	 * Starts an order-bound verification (unsaved meta changes).
	 *
	 * @param \WC_Order $order  Order.
	 * @param Client    $client Fulfilment-key client.
	 * @return string BOUND, REVIEW or DENIED.
	 * @throws ConnectError A failure a later run may get past.
	 */
	public static function create( \WC_Order $order, Client $client ): string {
		$external = self::require_external_id( $order, 'CreateVerification' );
		$ship_to  = OrderAddress::ship_to( $order );
		if ( null === $ship_to ) {
			return self::review( $order, 'no_ship_to' );
		}
		if ( ! OrderAddress::is_us( $ship_to ) ) {
			Meta::set( $order, Meta::REASON, 'not_us' );
			return self::DENIED;
		}
		$name = trim( preg_replace( '/\s+/', ' ', $order->get_billing_first_name( 'edit' ) . ' ' . $order->get_billing_last_name( 'edit' ) ) );
		if ( '' === $name ) {
			return self::review( $order, 'no_billing_name' );
		}
		$identity = array( 'name' => $name );
		$email    = (string) $order->get_billing_email( 'edit' );
		if ( '' !== $email && is_email( $email ) ) {
			$identity['email'] = $email;
		}
		$residence = OrderAddress::billing( $order );
		if ( null !== $residence && isset( $residence['region'] ) ) {
			$identity['residentialAddress'] = $residence;
		}
		$request = array(
			'idempotencyKey'    => 'cv:' . $external . ':' . Meta::get_int( $order, Meta::CREATE_ATTEMPT ),
			'identity'          => $identity,
			'orderExternalId'   => $external,
			'shipTo'            => $ship_to,
			// Always REQUIRED with order_external_id (E4a): the ID's name must match identity.name.
			'documentNameCheck' => 'DOCUMENT_NAME_CHECK_REQUIRED',
		);
		$return  = self::return_url( $order );
		if ( '' !== $return ) {
			$request['returnUrl'] = $return; // Signed into the hosted link (E4a).
		}
		$customer = CustomerRef::for_order( $order );
		if ( '' !== $customer ) {
			// Per-store reuse (E12a): a returning customer may pass on their earlier check.
			$request['customerRef'] = $customer;
		}

		try {
			$answer = $client->call( 'CreateVerification', $request );
		} catch ( ConnectError $e ) {
			if ( '' !== $e->local_reason() ) {
				throw $e;
			}
			switch ( $e->connect_code() ) {
				case 'already_exists':
					return self::adopt_bound( $order, $client, $external, $ship_to );
				case 'invalid_argument':
					// A different request needs a different key.
					Meta::set( $order, Meta::CREATE_ATTEMPT, Meta::get_int( $order, Meta::CREATE_ATTEMPT ) + 1 );
					return self::review( $order, 'create_rejected' );
				case 'failed_precondition':
					return self::review( $order, 'create_refused' );
				default:
					throw $e;
			}
		}
		$verification = isset( $answer['verification'] ) && is_array( $answer['verification'] ) ? $answer['verification'] : array();
		$vid          = isset( $verification['id'] ) && is_string( $verification['id'] ) ? $verification['id'] : '';
		if ( ! self::is_verification_id( $vid ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- codes only, never output.
			throw new ConnectError( 'CreateVerification', 'internal', 200, null, '', ConnectError::REASON_UNREADABLE_RESPONSE );
		}
		self::mark_bound( $order, $vid, 'create', $ship_to );
		self::record_verification( $order, $verification );
		Meta::note(
			$order,
			sprintf(
				/* translators: %s: AgeCheck verification id. */
				__( 'AgeCheck: started an age check for this order (%s).', 'navexa-agecheck-for-woocommerce' ),
				$vid
			)
		);
		Client::log( 'info', sprintf( 'agecheck order-bound create order=%d vid=%s', $order->get_id(), $vid ) );
		return self::BOUND;
	}

	/**
	 * Drops the order's verification so the next run starts an order-bound one
	 * (unsaved meta changes).
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason Why.
	 */
	public static function reverify( \WC_Order $order, string $reason ): void {
		$vid = Meta::get( $order, Meta::VERIFICATION_ID );
		if ( '' !== $vid ) {
			$previous   = array_filter( explode( ',', Meta::get( $order, Meta::PREVIOUS_VERIFICATION_IDS ) ) );
			$previous[] = $vid;
			Meta::set( $order, Meta::PREVIOUS_VERIFICATION_IDS, implode( ',', array_slice( array_unique( $previous ), -10 ) ) );
		}
		foreach ( array( Meta::VERIFICATION_ID, Meta::ATTACH_PENDING, Meta::BOUND_AT, Meta::BOUND_BY, Meta::SHIP_TO_HASH, Meta::CLEARANCE_KEY, Meta::LINK, Meta::LINK_ID, Meta::LINK_EXPIRES_AT, Meta::LINK_SENT_AT, Meta::LINK_KEY, Meta::VERIFICATION_STATUS, Meta::PASS_CLASS ) as $key ) {
			Meta::set( $order, $key, '' );
		}
		Meta::set( $order, Meta::CREATE_ATTEMPT, Meta::get_int( $order, Meta::CREATE_ATTEMPT ) + 1 );
		Meta::set( $order, Meta::REASON, $reason );
		Meta::note(
			$order,
			sprintf(
				/* translators: %s: machine reason, e.g. ship_to_changed. */
				__( 'AgeCheck: the shopper\'s earlier age check cannot be used for this order (%s). A new age check is needed.', 'navexa-agecheck-for-woocommerce' ),
				$reason
			)
		);
		Client::log( 'info', sprintf( 'agecheck reverify order=%d reason=%s', $order->get_id(), $reason ) );
	}

	/**
	 * Whether $vid has the shape of a verification id.
	 *
	 * @param string $vid Candidate.
	 */
	public static function is_verification_id( string $vid ): bool {
		return 1 === preg_match( '/^acv_[A-Za-z0-9_-]{1,60}$/', $vid );
	}

	/**
	 * The lower-case status name of a Verification message (VERIFICATION_STATUS_NEEDS_DOC
	 * -> needs_doc), or ''.
	 *
	 * @param array<string, mixed> $verification Verification message.
	 */
	public static function status_name( array $verification ): string {
		$status = isset( $verification['status'] ) && is_string( $verification['status'] ) ? $verification['status'] : '';
		$prefix = 'VERIFICATION_STATUS_';
		if ( 0 === strpos( $status, $prefix ) ) {
			$status = substr( $status, strlen( $prefix ) );
		}
		return 'UNSPECIFIED' === $status ? '' : strtolower( $status );
	}

	/**
	 * The pass class of a Verification message without its prefix
	 * (PASS_CLASS_REUSED -> REUSED), or '' when absent or unspecified.
	 * Accepts the proto3 JSON name and the number.
	 *
	 * @param array<string, mixed> $verification Verification message.
	 */
	public static function pass_class_name( array $verification ): string {
		$numbers = array(
			1 => 'DB_VERIFIED',
			2 => 'DB_UNATTESTED',
			3 => 'DOC_ONLY',
			4 => 'MERCHANT_OVERRIDE',
			5 => 'DATA_VERIFIED',
			6 => 'REUSED',
		);
		$class   = isset( $verification['passClass'] ) ? $verification['passClass'] : '';
		if ( is_int( $class ) ) {
			return isset( $numbers[ $class ] ) ? $numbers[ $class ] : '';
		}
		$class = is_string( $class ) ? $class : '';
		$class = 0 === strpos( $class, 'PASS_CLASS_' ) ? substr( $class, strlen( 'PASS_CLASS_' ) ) : $class;
		return 1 === preg_match( '/^[A-Z][A-Z_]{0,40}$/', $class ) && 'UNSPECIFIED' !== $class ? $class : '';
	}

	/**
	 * Records a Verification message's status and, once passed, its pass
	 * class on the order (unsaved; for the admin panel: release decisions
	 * come from GetClearance). Returns the status name.
	 *
	 * @param \WC_Order            $order        Order.
	 * @param array<string, mixed> $verification Verification message.
	 */
	public static function record_verification( \WC_Order $order, array $verification ): string {
		$status = self::status_name( $verification );
		Meta::set( $order, Meta::VERIFICATION_STATUS, $status );
		Meta::set( $order, Meta::PASS_CLASS, 'passed' === $status ? self::pass_class_name( $verification ) : '' );
		return $status;
	}

	/**
	 * Records a review reason and answers REVIEW.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason Reason.
	 */
	private static function review( \WC_Order $order, string $reason ): string {
		Meta::set( $order, Meta::REASON, $reason );
		return self::REVIEW;
	}

	/**
	 * The external id, or a local refusal while the install is paused.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $rpc   RPC the caller is about to make.
	 * @throws ConnectError When no external id can be had.
	 */
	private static function require_external_id( \WC_Order $order, string $rpc ): string {
		$external = self::external_id( $order );
		if ( '' === $external ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- codes only, never output.
			throw new ConnectError( $rpc, 'failed_precondition', 0, null, '', ConnectError::REASON_INSTALL_UNCONFIRMED );
		}
		return $external;
	}

	/**
	 * The order external id $vid is bound to ('' when none).
	 *
	 * @param Client $client Client.
	 * @param string $vid    Verification id.
	 * @throws ConnectError When AgeCheck cannot answer.
	 */
	private static function bound_order( Client $client, string $vid ): string {
		$answer       = $client->call( 'GetVerification', array( 'id' => $vid ) );
		$verification = isset( $answer['verification'] ) && is_array( $answer['verification'] ) ? $answer['verification'] : array();
		return isset( $verification['orderExternalId'] ) && is_string( $verification['orderExternalId'] ) ? $verification['orderExternalId'] : '';
	}

	/**
	 * Takes over the verification AgeCheck already binds to this order (an
	 * earlier create whose answer was lost after its key expired).
	 *
	 * @param \WC_Order             $order    Order.
	 * @param Client                $client   Client.
	 * @param string                $external External id.
	 * @param array<string, string> $ship_to  Ship-to.
	 * @throws ConnectError When AgeCheck cannot answer.
	 */
	private static function adopt_bound( \WC_Order $order, Client $client, string $external, array $ship_to ): string {
		$answer = $client->call(
			'ListVerifications',
			array(
				'orderExternalId' => $external,
				'pageSize'        => 1,
			)
		);
		$list   = isset( $answer['verifications'] ) && is_array( $answer['verifications'] ) ? $answer['verifications'] : array();
		$entry  = isset( $list[0] ) && is_array( $list[0] ) ? $list[0] : array();
		$found  = isset( $entry['verification'] ) && is_array( $entry['verification'] ) ? $entry['verification'] : array();
		$vid    = isset( $found['id'] ) && is_string( $found['id'] ) ? $found['id'] : '';
		$bound  = isset( $found['orderExternalId'] ) && is_string( $found['orderExternalId'] ) ? $found['orderExternalId'] : '';
		if ( ! self::is_verification_id( $vid ) || $bound !== $external ) {
			return self::review( $order, 'order_bound_elsewhere' );
		}
		$previous = Meta::get( $order, Meta::VERIFICATION_ID );
		if ( '' !== $previous && $previous !== $vid ) {
			self::reverify( $order, 'order_bound_elsewhere' );
		}
		self::mark_bound( $order, $vid, 'adopt', $ship_to );
		self::record_verification( $order, $found );
		return self::BOUND;
	}

	/**
	 * Records the binding (unsaved).
	 *
	 * @param \WC_Order             $order   Order.
	 * @param string                $vid     Verification id.
	 * @param string                $by      attach, create or adopt.
	 * @param array<string, string> $ship_to The ship-to bound.
	 */
	private static function mark_bound( \WC_Order $order, string $vid, string $by, array $ship_to ): void {
		Meta::set( $order, Meta::VERIFICATION_ID, $vid );
		Meta::set( $order, Meta::BOUND_AT, time() );
		Meta::set( $order, Meta::BOUND_BY, $by );
		Meta::set( $order, Meta::SHIP_TO_HASH, OrderAddress::hash( $ship_to ) );
		Meta::set( $order, Meta::ATTACH_PENDING, '' );
		if ( in_array( Meta::get( $order, Meta::REASON ), array( 'no_ship_to', 'invalid_ship_to', 'verification_not_found', 'order_bound_elsewhere', 'create_rejected', 'create_refused', 'no_billing_name' ), true ) ) {
			Meta::set( $order, Meta::REASON, '' );
		}
	}

	/**
	 * The checkout verification to bind to the order being placed, or '': the
	 * one the pre-payment gate admitted in this request through the session's
	 * guard (Gate::admitted_verification_id(), which is also Session::KEY_VID).
	 * KEY_VID alone is never enough: it outlives the guard's TTL, is not tied
	 * to this order's ship-to, and stays in the session when the shopper
	 * leaves a checkout unfinished. A resumed bound order is admitted on its
	 * own verification, which is no longer the session's, so it never
	 * matches (and is skipped earlier anyway).
	 */
	private static function checkout_vid(): string {
		if ( ! Gate::enforces() ) {
			return '';
		}
		$admitted = Gate::admitted_verification_id();
		$session  = Session::wc_session();
		$vid      = null === $session ? null : $session->get( Session::KEY_VID );
		return is_string( $vid ) && self::is_verification_id( $vid ) && $admitted === $vid ? $vid : '';
	}

	/**
	 * The order-received page when it is on the store's own origin (an allowed
	 * origin), else ''.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function return_url( \WC_Order $order ): string {
		$url    = (string) $order->get_checkout_order_received_url();
		$origin = Mode::store_origin();
		if ( '' === $origin || 0 !== strpos( strtolower( $url ), $origin . '/' ) || strlen( $url ) > 2048 ) {
			return '';
		}
		return $url;
	}
}
