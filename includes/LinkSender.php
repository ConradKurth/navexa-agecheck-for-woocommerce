<?php
/**
 * Emails a shopper the link to verify a held order.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * How a held order's shopper is asked to verify: AgeCheck emails them a
 * durable (about 7-day) verification link, SendVerificationLink (engine PR
 * E8c). E8c is not in production yet, so the default sender is
 * UnavailableLinkSender, which makes no call and keeps the order held.
 * EngineLinkSender calls the RPC; it is selected when wp-config.php defines
 * NAVEXA_AGECHECK_EMAIL_LINKS as true (CI against the mock engine, and
 * production once E8c ships), or through the navexa_agecheck_link_sender filter.
 */
interface LinkSender {

	/**
	 * AgeCheck queued the email.
	 */
	const RESULT_SENT = 'sent';

	/**
	 * This store cannot have AgeCheck email links yet (E8c not available).
	 */
	const RESULT_UNAVAILABLE = 'unavailable';

	/**
	 * AgeCheck refused for this verification (no email on file, no display
	 * name, a state that takes no link): staff need to look.
	 */
	const RESULT_REFUSED = 'refused';

	/**
	 * Asks AgeCheck to email the shopper a link for $verification_id.
	 *
	 * @param Client $client          Fulfilment-key client.
	 * @param string $verification_id Verification id (acv_...).
	 * @param string $idempotency_key Key for the call.
	 * @return string RESULT_*.
	 * @throws ConnectError A retryable failure (the caller retries later).
	 */
	public function send( Client $client, string $verification_id, string $idempotency_key ): string;
}
