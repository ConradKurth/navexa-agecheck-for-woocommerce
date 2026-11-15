<?php
/**
 * Link sender for engines without SendVerificationLink.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * The default until AgeCheck can email verification links (E8c): makes no
 * call and answers RESULT_UNAVAILABLE, so the order stays held and the admin
 * panel says the shopper has not been asked yet.
 */
final class UnavailableLinkSender implements LinkSender {

	/**
	 * Answers RESULT_UNAVAILABLE.
	 *
	 * @param Client $client          Unused.
	 * @param string $verification_id Unused.
	 * @param string $idempotency_key Unused.
	 */
	public function send( Client $client, string $verification_id, string $idempotency_key ): string {
		unset( $client, $verification_id, $idempotency_key );
		return self::RESULT_UNAVAILABLE;
	}
}
