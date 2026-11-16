<?php
/**
 * Link sender over SendVerificationLink (engine E8c).
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Calls SendVerificationLink (proto agecheck/v1/verification.proto, tax
 * origin/main d6dead63): {idempotency_key, verification_id, return_url?} ->
 * {link_id, expires_at, email_queued}. The answer never carries the link
 * itself (it goes to the shopper's inbox only), and a retry under the same
 * key replays it without a second email.
 *
 * The engine's refusals, all FAILED_PRECONDITION with its own sentinel text
 * (read here to tell them apart, never logged):
 * - "verification links are not enabled": AGECHECK_LINKS off for the
 *   deployment (Links::STATE_LINKS_OFF, the whole store);
 * - "set the store's display name ...": the organization has no display
 *   name (Links::STATE_NO_DISPLAY_NAME, the whole store);
 * - "... has no email ...": the verification's identity carried no email
 *   (Links::STATE_NO_EMAIL);
 * - "... waiting on the shopper, with the document name check": decided,
 *   not waiting on the shopper, purged, or created without the ID name
 *   check (Links::STATE_NOT_ALLOWED);
 * - anything else (the access gate: no card, live mode paused or not open):
 *   Links::STATE_REFUSED.
 * RESOURCE_EXHAUSTED with "verification links sent" is the budget of 5 sends
 * per verification in 24 hours (Links::LIMITED, nothing sent); any other
 * RESOURCE_EXHAUSTED is the key's rate limit and is rethrown (retryable).
 * INVALID_ARGUMENT (a return URL off the allowed origins) and NOT_FOUND are
 * STATE_REFUSED. An engine without the RPC answers a plain 404, read as
 * unimplemented: Links::STATE_UNAVAILABLE. email_queued false (a test-mode
 * verification whose email is not one of the organization's confirmed
 * recipients) is Links::STATE_NOT_QUEUED: the link exists, no email went out.
 */
final class EngineLinkSender implements LinkSender {

	const RPC = 'SendVerificationLink';

	/**
	 * Sends the link.
	 *
	 * @param Client $client          Fulfilment-key client.
	 * @param string $verification_id Verification id.
	 * @param string $idempotency_key Idempotency key.
	 * @param string $return_url      Return URL, or ''.
	 * @throws ConnectError A retryable or unexpected failure.
	 */
	public function send( Client $client, string $verification_id, string $idempotency_key, string $return_url ): LinkResult {
		$request = array(
			'idempotencyKey' => $idempotency_key,
			'verificationId' => $verification_id,
		);
		if ( '' !== $return_url ) {
			$request['returnUrl'] = $return_url;
		}
		try {
			$answer = $client->call( self::RPC, $request );
		} catch ( ConnectError $e ) {
			$state = self::refusal( $e );
			if ( '' === $state ) {
				throw $e;
			}
			return new LinkResult( $state );
		}
		$queued  = isset( $answer['emailQueued'] ) && true === $answer['emailQueued'];
		$link_id = isset( $answer['linkId'] ) && is_string( $answer['linkId'] ) ? $answer['linkId'] : '';
		$expires = isset( $answer['expiresAt'] ) && is_string( $answer['expiresAt'] ) ? strtotime( $answer['expiresAt'] ) : false;
		return new LinkResult( $queued ? Links::STATE_SENT : Links::STATE_NOT_QUEUED, $link_id, false === $expires ? 0 : (int) $expires );
	}

	/**
	 * The link state a refusal means, or '' for an error to rethrow.
	 *
	 * @param ConnectError $e Failure.
	 */
	public static function refusal( ConnectError $e ): string {
		if ( '' !== $e->local_reason() ) {
			return '';
		}
		$message = strtolower( $e->server_message() );
		switch ( $e->connect_code() ) {
			case 'unimplemented':
				return Links::STATE_UNAVAILABLE;
			case 'failed_precondition':
				if ( false !== strpos( $message, 'links are not enabled' ) ) {
					return Links::STATE_LINKS_OFF;
				}
				if ( false !== strpos( $message, 'display name' ) ) {
					return Links::STATE_NO_DISPLAY_NAME;
				}
				if ( false !== strpos( $message, 'no email' ) ) {
					return Links::STATE_NO_EMAIL;
				}
				if ( false !== strpos( $message, 'waiting on the shopper' ) ) {
					return Links::STATE_NOT_ALLOWED;
				}
				return Links::STATE_REFUSED;
			case 'resource_exhausted':
				return false !== strpos( $message, 'verification links sent' ) ? Links::LIMITED : '';
			case 'invalid_argument':
			case 'not_found':
				return Links::STATE_REFUSED;
			default:
				return '';
		}
	}
}
