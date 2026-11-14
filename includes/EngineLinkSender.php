<?php
/**
 * Link sender over SendVerificationLink (engine PR E8c).
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Calls SendVerificationLink. Contract from the plan (design-final.md §2.1;
 * E8c, not in production yet): {idempotency_key, verification_id,
 * return_url?} -> {link_id, expires_at, email_queued}. The answer never holds
 * the URL. FAILED_PRECONDITION when the verification has no email or the org
 * no display name, or its state takes no link. An engine without the RPC
 * answers a plain 404 (unimplemented), read as RESULT_UNAVAILABLE.
 */
final class EngineLinkSender implements LinkSender {

	const RPC = 'SendVerificationLink';

	/**
	 * Sends the link.
	 *
	 * @param Client $client          Fulfilment-key client.
	 * @param string $verification_id Verification id.
	 * @param string $idempotency_key Idempotency key.
	 * @return string RESULT_*.
	 * @throws ConnectError A retryable or unexpected failure.
	 */
	public function send( Client $client, string $verification_id, string $idempotency_key ): string {
		try {
			$answer = $client->call(
				self::RPC,
				array(
					'idempotencyKey' => $idempotency_key,
					'verificationId' => $verification_id,
				)
			);
		} catch ( ConnectError $e ) {
			if ( 'unimplemented' === $e->connect_code() ) {
				return self::RESULT_UNAVAILABLE;
			}
			if ( in_array( $e->connect_code(), array( 'failed_precondition', 'invalid_argument', 'not_found' ), true ) ) {
				return self::RESULT_REFUSED;
			}
			throw $e;
		}
		$queued = isset( $answer['emailQueued'] ) ? $answer['emailQueued'] : ( isset( $answer['email_queued'] ) ? $answer['email_queued'] : false );
		return true === $queued ? self::RESULT_SENT : self::RESULT_REFUSED;
	}
}
