<?php
/**
 * Asks AgeCheck to email a shopper the link to verify a held order.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * How a held order's shopper is asked to verify: AgeCheck emails them a
 * durable (7-day) verification link, SendVerificationLink (engine E8c, tax
 * d6dead63). Links owns when to ask and what to record; a LinkSender only
 * makes the call and reads the answer.
 *
 * EngineLinkSender calls the RPC: the default, so the engine's answer decides
 * (it refuses while AGECHECK_LINKS is off for the deployment, or the store has
 * no display name). DisabledLinkSender makes no call: the site's kill switch,
 * wp-config.php define( 'NAVEXA_AGECHECK_EMAIL_LINKS', false ). The
 * navexa_agecheck_link_sender filter can swap either (tests).
 */
interface LinkSender {

	/**
	 * Asks AgeCheck to email the shopper a link for $verification_id.
	 *
	 * @param Client $client          Fulfilment-key client.
	 * @param string $verification_id Verification id (acv_...).
	 * @param string $idempotency_key Key for the call (stored on the order before it).
	 * @param string $return_url      Where AgeCheck's page links back to, or '' for none.
	 * @return LinkResult What AgeCheck answered.
	 * @throws ConnectError A failure that is not an answer about the link (outage, key problem).
	 */
	public function send( Client $client, string $verification_id, string $idempotency_key, string $return_url ): LinkResult;
}
