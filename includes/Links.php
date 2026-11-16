<?php
/**
 * Emailed verification links for held orders.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * When AgeCheck emails a held order's shopper a link to verify, and what the
 * order records about it (owner decisions Q7, Q11b; engine E8c).
 *
 * - Who: only a verification this plugin created for the order (BOUND_BY
 *   create), which asks for document_name_check REQUIRED with the billing
 *   name and carries the billing email: an emailed link lasts 7 days and can
 *   be forwarded, so the ID must match the billing name (Q7). A checkout
 *   verification (attach, name check NONE) and an adopted one (name check
 *   unknown) are never emailed (STATE_NOT_ELIGIBLE); their shoppers can still
 *   finish from the order page (OrderVerify).
 * - When: a release run (Release, under the per-order lock) asks once per
 *   verification while it waits on the shopper (HOSTED_STATES). The answer is
 *   recorded in Meta::LINK as "{state}:{vid}", with the link's id, expiry and
 *   send time; an order note marks each change. Staff can resend (a new
 *   link; the earlier one stops working) and withdraw (RevokeVerificationLinks)
 *   from Order actions (Admin\OrderPanel). An order that will not ship has
 *   its links withdrawn by itself (LinkRevoke, STATE_REVOKED); held again,
 *   its next release run asks for a new one.
 * - Idempotency: the key (vl:{vid}:{random}) is stored on the order before
 *   the call and reused until an answer is recorded, so a retry after a lost
 *   answer replays it and emails nobody twice. Any recorded answer, a refusal
 *   included, drops it: the next ask is a new request.
 * - The engine decides (no opt-in constant): it refuses while AGECHECK_LINKS
 *   is off for the deployment, one switch for every store, not per account
 *   (STATE_LINKS_OFF) and until the store has a
 *   display name (STATE_NO_DISPLAY_NAME); an engine without the RPC is
 *   STATE_UNAVAILABLE. Those are about the whole store, so the refusal is
 *   also stored once (OPTION_BLOCK) and, for BLOCK_TTL, other orders record it
 *   without asking: at most one call an hour store-wide while links are off,
 *   never a retry storm. The order stays held; Admin\Notices tells the
 *   merchant why. A display name set since (GetIntegrationInfo, cached an
 *   hour) lifts a no-display-name block at once. Staff resends always ask.
 * - Kill switch: wp-config.php define( 'NAVEXA_AGECHECK_EMAIL_LINKS', false )
 *   makes no call (DisabledLinkSender, STATE_DISABLED). Undefined or true:
 *   the engine's answer decides.
 */
final class Links {

	const STATE_SENT            = 'sent';
	const STATE_NOT_QUEUED      = 'not_queued';
	const STATE_LINKS_OFF       = 'links_off';
	const STATE_NO_DISPLAY_NAME = 'no_display_name';
	const STATE_UNAVAILABLE     = 'unavailable';
	const STATE_DISABLED        = 'disabled';
	const STATE_NO_EMAIL        = 'no_email';
	const STATE_NOT_ALLOWED     = 'not_allowed';
	const STATE_REFUSED         = 'refused';
	const STATE_WITHDRAWN       = 'withdrawn';
	const STATE_REVOKED         = 'revoked';
	const STATE_NOT_ELIGIBLE    = 'not_eligible';

	/**
	 * Not a state: the verification's send budget (5 in 24 hours) is used up.
	 */
	const LIMITED = 'limited';

	/**
	 * Not a state: another run holds the order's lock.
	 */
	const LOCKED = 'locked';

	/**
	 * Not a state: the order cannot take a link from staff (resend()).
	 */
	const INELIGIBLE = 'ineligible';

	/**
	 * States about the whole store: asked again later (after BLOCK_TTL for
	 * the blocking ones, at every run for the kill switch, which calls nothing).
	 */
	const STORE_STATES = array( self::STATE_LINKS_OFF, self::STATE_NO_DISPLAY_NAME, self::STATE_UNAVAILABLE, self::STATE_DISABLED );

	/**
	 * Store states that set OPTION_BLOCK.
	 */
	const BLOCKING_STATES = array( self::STATE_LINKS_OFF, self::STATE_NO_DISPLAY_NAME, self::STATE_UNAVAILABLE );

	/**
	 * States after which a release run never asks again for that verification
	 * (staff may still resend). STATE_REVOKED only while the order will not
	 * ship (LinkRevoke::is_closed()): held again, it is asked again.
	 */
	const FINAL_STATES = array( self::STATE_SENT, self::STATE_NOT_QUEUED, self::STATE_NO_EMAIL, self::STATE_NOT_ALLOWED, self::STATE_REFUSED, self::STATE_WITHDRAWN, self::STATE_REVOKED, self::STATE_NOT_ELIGIBLE );

	/**
	 * States whose link may still be open (withdraw() applies).
	 */
	const LIVE_STATES = array( self::STATE_SENT, self::STATE_NOT_QUEUED );

	/**
	 * Verification statuses AgeCheck emails a link for: waiting on the
	 * shopper at the hosted page (the engine's LinkAllowed).
	 */
	const HOSTED_STATES = array( 'needs_doc', 'doc_pending', 'awaiting_identity' );

	/**
	 * Store-wide refusal: {reason: a BLOCKING_STATES state, at: unix time}.
	 */
	const OPTION_BLOCK = 'navexa_agecheck_link_block';

	/**
	 * Seconds a store-wide refusal stands before a release run asks again.
	 */
	const BLOCK_TTL = 3600;

	/**
	 * Seconds after its last refusal the merchant notice is shown.
	 */
	const NOTICE_TTL = 604800;

	const RPC_REVOKE = 'RevokeVerificationLinks';

	/**
	 * The link sender: EngineLinkSender (the engine decides), or
	 * DisabledLinkSender when wp-config.php defines NAVEXA_AGECHECK_EMAIL_LINKS
	 * as false.
	 */
	public static function sender(): LinkSender {
		$sender = defined( 'NAVEXA_AGECHECK_EMAIL_LINKS' ) && false === constant( 'NAVEXA_AGECHECK_EMAIL_LINKS' ) ? new DisabledLinkSender() : new EngineLinkSender();
		/**
		 * Filters the verification-link sender.
		 *
		 * @param LinkSender $sender Sender.
		 */
		$filtered = apply_filters( 'navexa_agecheck_link_sender', $sender );
		return $filtered instanceof LinkSender ? $filtered : $sender;
	}

	/**
	 * The link state recorded for the order's current verification, or ''.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function state( \WC_Order $order ): string {
		$vid    = Meta::get( $order, Meta::VERIFICATION_ID );
		$stored = explode( ':', Meta::get( $order, Meta::LINK ), 2 );
		return '' !== $vid && 2 === count( $stored ) && $stored[1] === $vid ? $stored[0] : '';
	}

	/**
	 * A release run's ask (the caller holds the order's lock and saves the
	 * order's meta): once per verification, unless a store-wide refusal
	 * stands. Unsaved meta changes, except the idempotency key, saved before
	 * the call.
	 *
	 * @param \WC_Order            $order  Order whose verification waits on the shopper.
	 * @param Client               $client Fulfilment-key client.
	 * @param IntegrationInfo|null $info   The fulfilment key's info (display name, origin_allowed).
	 * @return string The state recorded, or '' without a verification.
	 * @throws ConnectError A failure a later run may get past.
	 */
	public static function send( \WC_Order $order, Client $client, ?IntegrationInfo $info = null ): string {
		$vid = Meta::get( $order, Meta::VERIFICATION_ID );
		if ( '' === $vid ) {
			return '';
		}
		if ( 'create' !== Meta::get( $order, Meta::BOUND_BY ) ) {
			self::set_state( $order, $vid, self::STATE_NOT_ELIGIBLE );
			return self::STATE_NOT_ELIGIBLE;
		}
		$current = self::state( $order );
		if ( self::STATE_REVOKED === $current && ! LinkRevoke::is_closed( $order ) ) {
			// Withdrawn because the order would not ship, and it can ship
			// again (paid after failing, restored from the trash): the shopper
			// needs a link that works.
			$current = '';
		}
		if ( in_array( $current, self::FINAL_STATES, true ) ) {
			return $current;
		}
		$sender = self::sender();
		if ( ! $sender instanceof DisabledLinkSender ) {
			$blocked = self::blocked( $info );
			if ( '' !== $blocked ) {
				self::set_state( $order, $vid, $blocked );
				return $blocked;
			}
		}
		$result = self::ask( $order, $client, $sender, $info );
		if ( self::LIMITED === $result->state ) {
			// Staff resends used the budget up: the shopper has been emailed.
			Meta::set( $order, Meta::LINK_KEY, '' );
			self::set_state( $order, $vid, self::STATE_SENT );
			return self::STATE_SENT;
		}
		self::record( $order, $vid, $result, false );
		return $result->state;
	}

	/**
	 * Staff's "Resend verification email": a new link whatever the order
	 * recorded before (the earlier link stops working), under the order's
	 * lock. Saves the order's meta.
	 *
	 * @param \WC_Order $order  Order.
	 * @param Client    $client Fulfilment-key client.
	 * @return string The state recorded, LIMITED (nothing sent), LOCKED or INELIGIBLE.
	 * @throws ConnectError When AgeCheck could not answer (nothing recorded).
	 */
	public static function resend( \WC_Order $order, Client $client ): string {
		$lock  = Release::lock_name( (int) $order->get_id() );
		$token = Lock::acquire( $lock, Release::LOCK_TTL );
		if ( '' === $token ) {
			return self::LOCKED;
		}
		try {
			$fresh = wc_get_order( $order->get_id() );
			$order = $fresh instanceof \WC_Order ? $fresh : $order;
			if ( ! self::can_resend( $order ) ) {
				return self::INELIGIBLE;
			}
			$vid    = Meta::get( $order, Meta::VERIFICATION_ID );
			$info   = Mode::info( Client::KEY_FULFILMENT );
			$result = self::ask( $order, $client, self::sender(), $info );
			if ( self::LIMITED === $result->state ) {
				Meta::set( $order, Meta::LINK_KEY, '' );
				$order->save_meta_data();
				Client::log( 'info', sprintf( 'agecheck link resend order=%d vid=%s state=limited', $order->get_id(), $vid ) );
				return self::LIMITED;
			}
			self::record( $order, $vid, $result, true );
			$order->save_meta_data();
			return $result->state;
		} finally {
			Lock::release( $lock, $token );
		}
	}

	/**
	 * Staff's "Withdraw verification link": RevokeVerificationLinks, under the
	 * order's lock. None of the verification's links opens AgeCheck's page
	 * any more, and a release run never sends a new one (staff may resend).
	 * Saves the order's meta.
	 *
	 * @param \WC_Order $order  Order.
	 * @param Client    $client Fulfilment-key client.
	 * @return int|string How many links were still open, LOCKED or INELIGIBLE.
	 * @throws ConnectError When AgeCheck could not answer (nothing recorded).
	 */
	public static function withdraw( \WC_Order $order, Client $client ) {
		$lock  = Release::lock_name( (int) $order->get_id() );
		$token = Lock::acquire( $lock, Release::LOCK_TTL );
		if ( '' === $token ) {
			return self::LOCKED;
		}
		try {
			$fresh = wc_get_order( $order->get_id() );
			$order = $fresh instanceof \WC_Order ? $fresh : $order;
			if ( ! self::can_withdraw( $order ) ) {
				return self::INELIGIBLE;
			}
			$vid    = Meta::get( $order, Meta::VERIFICATION_ID );
			$answer = $client->call(
				self::RPC_REVOKE,
				array(
					'idempotencyKey' => 'rvl:' . $vid . ':' . bin2hex( random_bytes( 8 ) ),
					'verificationId' => $vid,
				)
			);
			$count  = isset( $answer['revokedCount'] ) && is_numeric( $answer['revokedCount'] ) ? max( 0, (int) $answer['revokedCount'] ) : 0;
			foreach ( array( Meta::LINK_ID, Meta::LINK_EXPIRES_AT, Meta::LINK_KEY ) as $key ) {
				Meta::set( $order, $key, '' );
			}
			Meta::set( $order, Meta::LINK, self::STATE_WITHDRAWN . ':' . $vid );
			Meta::note(
				$order,
				sprintf(
					/* translators: %d: number of links that could still be opened. */
					_n( 'AgeCheck: withdrew the emailed verification link (%d was still open). The shopper can no longer use it.', 'AgeCheck: withdrew the emailed verification links (%d were still open). The shopper can no longer use them.', $count, 'navexa-agecheck-for-woocommerce' ),
					$count
				)
			);
			$order->save_meta_data();
			Client::log( 'info', sprintf( 'agecheck link withdrawn order=%d vid=%s revoked=%d', $order->get_id(), $vid, $count ) );
			return $count;
		} finally {
			Lock::release( $lock, $token );
		}
	}

	/**
	 * Whether staff may have AgeCheck email the order's shopper a (new) link:
	 * a held, undecided order whose verification this plugin created and that
	 * waits on the shopper.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function can_resend( \WC_Order $order ): bool {
		return Hold::STATUS === $order->get_status( 'edit' )
			&& ! Meta::is_released( $order )
			&& Meta::STATUS_DENIED !== Meta::get( $order, Meta::STATUS )
			&& '' !== Meta::get( $order, Meta::VERIFICATION_ID )
			&& 'create' === Meta::get( $order, Meta::BOUND_BY )
			&& in_array( Meta::get( $order, Meta::VERIFICATION_STATUS ), self::HOSTED_STATES, true );
	}

	/**
	 * Whether the order's verification has a link that may still be open.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function can_withdraw( \WC_Order $order ): bool {
		return in_array( self::state( $order ), self::LIVE_STATES, true );
	}

	/**
	 * The stored store-wide refusal, or an empty array.
	 *
	 * @return array{reason: string, at: int}|array{}
	 */
	public static function store_block(): array {
		$block = get_option( self::OPTION_BLOCK, array() );
		if ( ! is_array( $block ) || ! isset( $block['reason'], $block['at'] ) || ! in_array( $block['reason'], self::BLOCKING_STATES, true ) ) {
			return array();
		}
		return array(
			'reason' => (string) $block['reason'],
			'at'     => (int) $block['at'],
		);
	}

	/**
	 * The store-wide refusal that still stands (younger than BLOCK_TTL), or ''.
	 * A no-display-name refusal no longer stands once GetIntegrationInfo
	 * reports a display name.
	 *
	 * @param IntegrationInfo|null $info The fulfilment key's info.
	 * @param int|null             $now  Unix time; null for time().
	 */
	public static function blocked( ?IntegrationInfo $info, ?int $now = null ): string {
		$block = self::store_block();
		if ( array() === $block ) {
			return '';
		}
		$now = null === $now ? time() : $now;
		if ( $now - $block['at'] >= self::BLOCK_TTL || $block['at'] > $now ) {
			return '';
		}
		if ( self::STATE_NO_DISPLAY_NAME === $block['reason'] && null !== $info && '' !== trim( $info->display_name ) ) {
			return '';
		}
		return $block['reason'];
	}

	/**
	 * The return URL signed into the link: the order's return step
	 * (OrderVerify), only when GetIntegrationInfo says the store's origin is
	 * allowed (AgeCheck refuses any other); else ''.
	 *
	 * @param \WC_Order            $order Order.
	 * @param IntegrationInfo|null $info  The fulfilment key's info.
	 */
	public static function return_url( \WC_Order $order, ?IntegrationInfo $info ): string {
		return null !== $info && $info->origin_allowed ? OrderVerify::return_url( $order ) : '';
	}

	/**
	 * The link state in words, for the admin panel ('' without one).
	 *
	 * @param \WC_Order $order Order.
	 * @param int|null  $now   Unix time; null for time().
	 */
	public static function describe( \WC_Order $order, ?int $now = null ): string {
		$now     = null === $now ? time() : $now;
		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$expires = Meta::get_int( $order, Meta::LINK_EXPIRES_AT );
		switch ( self::state( $order ) ) {
			case '':
				return '';
			case self::STATE_SENT:
				$sent = Meta::get_int( $order, Meta::LINK_SENT_AT );
				if ( $expires > 0 && $expires <= $now ) {
					/* translators: %s: date and time. */
					return sprintf( __( 'Emailed to the shopper; the link expired on %s. Resend it from Order actions.', 'navexa-agecheck-for-woocommerce' ), wp_date( $format, $expires ) );
				}
				if ( $sent > 0 && $expires > 0 ) {
					/* translators: 1: date and time sent, 2: date and time the link expires. */
					return sprintf( __( 'Emailed to the shopper on %1$s; the link works until %2$s.', 'navexa-agecheck-for-woocommerce' ), wp_date( $format, $sent ), wp_date( $format, $expires ) );
				}
				return __( 'Emailed to the shopper.', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_NOT_QUEUED:
				return __( 'Test mode: AgeCheck made a link but emailed nobody (test mode emails only your confirmed AgeCheck notification recipients).', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_LINKS_OFF:
				return __( 'Not sent: AgeCheck is not sending verification emails yet.', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_NO_DISPLAY_NAME:
				return __( 'Not sent: set your store name in the AgeCheck dashboard. It is sent on a later check.', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_UNAVAILABLE:
				return __( 'Not sent: AgeCheck does not offer verification emails yet.', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_DISABLED:
				return __( 'Not sent: verification emails are turned off on this site (NAVEXA_AGECHECK_EMAIL_LINKS).', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_NO_EMAIL:
				return __( 'Not sent: the age check was started without a valid billing email.', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_NOT_ALLOWED:
				return __( 'Not sent: this age check cannot be emailed (it is not waiting on the shopper).', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_REFUSED:
				return __( 'Not sent: AgeCheck refused (see the order notes).', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_WITHDRAWN:
				return __( 'Withdrawn by staff: the emailed link no longer works.', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_REVOKED:
				return __( 'Withdrawn because the order will not ship (cancelled, refunded in full, failed or trashed): the emailed link no longer works.', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_NOT_ELIGIBLE:
				return __( 'Not sent: a check the shopper started at checkout is never emailed.', 'navexa-agecheck-for-woocommerce' );
			default:
				return self::state( $order );
		}
	}

	/**
	 * Calls the sender with the order's stored idempotency key, or a new one
	 * stored (and saved) before the call.
	 *
	 * @param \WC_Order            $order  Order.
	 * @param Client               $client Fulfilment-key client.
	 * @param LinkSender           $sender Sender.
	 * @param IntegrationInfo|null $info   The fulfilment key's info.
	 * @throws ConnectError A failure the sender does not read as an answer.
	 */
	private static function ask( \WC_Order $order, Client $client, LinkSender $sender, ?IntegrationInfo $info ): LinkResult {
		$vid = Meta::get( $order, Meta::VERIFICATION_ID );
		$key = Meta::get( $order, Meta::LINK_KEY );
		if ( 0 !== strpos( $key, 'vl:' . $vid . ':' ) ) {
			$key = 'vl:' . $vid . ':' . bin2hex( random_bytes( 8 ) );
			Meta::set( $order, Meta::LINK_KEY, $key );
			$order->save_meta_data();
		}
		return $sender->send( $client, $vid, $key, self::return_url( $order, $info ) );
	}

	/**
	 * Records an answer (unsaved): the state, the link's id and times, and
	 * the store-wide refusal. A refusal never replaces a link still on record
	 * as open (a refused resend leaves the earlier link working).
	 *
	 * @param \WC_Order  $order  Order.
	 * @param string     $vid    Verification id.
	 * @param LinkResult $result Answer.
	 * @param bool       $resend Whether staff asked for it.
	 */
	private static function record( \WC_Order $order, string $vid, LinkResult $result, bool $resend ): void {
		Meta::set( $order, Meta::LINK_KEY, '' );
		$state = $result->state;
		$live  = in_array( $state, self::LIVE_STATES, true );
		if ( ! $live && in_array( self::state( $order ), self::LIVE_STATES, true ) ) {
			// A refused resend: AgeCheck sent nothing and changed nothing, so
			// the link emailed earlier still opens its page. Keep it on record
			// (Withdraw stays offered); note the refusal only.
			self::block_store( $state );
			Meta::note(
				$order,
				sprintf(
					/* translators: %s: why AgeCheck sent no new link (a state code such as links_off or refused). */
					__( 'AgeCheck sent no new verification link (%s). The link emailed earlier still works until it expires; withdraw it from Order actions if this order will not ship.', 'navexa-agecheck-for-woocommerce' ),
					$state
				)
			);
			Client::log( 'info', sprintf( 'agecheck link order=%d vid=%s state=%s kept=live%s', $order->get_id(), $vid, $state, $resend ? ' by=staff' : '' ) );
			return;
		}
		if ( $live ) {
			Meta::set( $order, Meta::LINK_ID, $result->link_id );
			Meta::set( $order, Meta::LINK_EXPIRES_AT, $result->expires_at > 0 ? $result->expires_at : '' );
			Meta::set( $order, Meta::LINK_SENT_AT, time() );
			delete_option( self::OPTION_BLOCK );
		} else {
			self::block_store( $state );
		}
		self::set_state( $order, $vid, $state, $resend );
	}

	/**
	 * Stores a store-wide refusal (OPTION_BLOCK) when $state is one.
	 *
	 * @param string $state STATE_*.
	 */
	private static function block_store( string $state ): void {
		if ( ! in_array( $state, self::BLOCKING_STATES, true ) ) {
			return;
		}
		update_option(
			self::OPTION_BLOCK,
			array(
				'reason' => $state,
				'at'     => time(),
			),
			true
		);
	}

	/**
	 * Sets the order's link state (unsaved), with a note and a log line when
	 * it changes (and for every send staff asked for).
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $vid    Verification id.
	 * @param string    $state  STATE_*.
	 * @param bool      $resend Whether staff asked for it.
	 */
	private static function set_state( \WC_Order $order, string $vid, string $state, bool $resend = false ): void {
		$value    = $state . ':' . $vid;
		$previous = Meta::get( $order, Meta::LINK );
		Meta::set( $order, Meta::LINK, $value );
		if ( $value === $previous && ! $resend ) {
			return;
		}
		Meta::note( $order, self::note( $order, $state, $resend ) );
		Client::log( 'info', sprintf( 'agecheck link order=%d vid=%s state=%s%s', $order->get_id(), $vid, $state, $resend ? ' by=staff' : '' ) );
	}

	/**
	 * Order note for a state.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $state  STATE_*.
	 * @param bool      $resend Whether staff asked for it.
	 */
	private static function note( \WC_Order $order, string $state, bool $resend ): string {
		switch ( $state ) {
			case self::STATE_SENT:
				$expires = Meta::get_int( $order, Meta::LINK_EXPIRES_AT );
				$until   = $expires > 0 ? wp_date( get_option( 'date_format' ), $expires ) : __( 'about 7 days', 'navexa-agecheck-for-woocommerce' );
				$link    = Meta::get( $order, Meta::LINK_ID );
				return $resend
					/* translators: 1: link id (acl_...), 2: date the link expires. */
					? sprintf( __( 'AgeCheck: emailed the shopper a new verification link (%1$s, good until %2$s) at a staff member\'s request. Earlier links no longer work. The order stays held until they pass.', 'navexa-agecheck-for-woocommerce' ), '' === $link ? '-' : $link, $until )
					/* translators: 1: link id (acl_...), 2: date the link expires. */
					: sprintf( __( 'AgeCheck: emailed the shopper a link to verify their age (%1$s, good until %2$s). The order stays held until they pass.', 'navexa-agecheck-for-woocommerce' ), '' === $link ? '-' : $link, $until );
			case self::STATE_NOT_QUEUED:
				return __( 'AgeCheck (test mode): made a verification link but emailed nobody, because test mode emails only your confirmed AgeCheck notification recipients. The order stays held.', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_LINKS_OFF:
				return __( 'AgeCheck: AgeCheck is not sending verification emails yet, so the shopper was not emailed a link. The order stays held until the shopper verifies from their order page, or until you release it after checking their age yourself.', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_NO_DISPLAY_NAME:
				return __( 'AgeCheck: no verification email was sent because your store has no name in AgeCheck. Set your store name in the AgeCheck dashboard; the email goes out on a later check. The order stays held.', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_UNAVAILABLE:
				return __( 'AgeCheck: verification emails are not available from AgeCheck yet, so the shopper has not been emailed. The order stays held. Release it without an age check only if you have checked the buyer\'s age yourself.', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_DISABLED:
				return __( 'AgeCheck: verification emails are turned off on this site (NAVEXA_AGECHECK_EMAIL_LINKS), so the shopper was not emailed. The order stays held.', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_NO_EMAIL:
				return __( 'AgeCheck: could not email the shopper a verification link, because the order had no valid billing email when its age check started. The order stays held; the shopper can verify from their order page.', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_NOT_ALLOWED:
				return __( 'AgeCheck: this age check cannot be emailed (it is no longer waiting on the shopper). The order stays held.', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_NOT_ELIGIBLE:
				return __( 'AgeCheck: the shopper has not finished the age check linked to this order (usually one started at checkout). AgeCheck emails links only for checks that match the ID to the billing name, so none was sent. The order stays held until the shopper finishes it. Release it without an age check only if you have checked the buyer\'s age yourself.', 'navexa-agecheck-for-woocommerce' );
			default:
				return __( 'AgeCheck refused to email the shopper a verification link (check billing and live access in the Navexa dashboard). The order stays held.', 'navexa-agecheck-for-woocommerce' );
		}
	}
}
