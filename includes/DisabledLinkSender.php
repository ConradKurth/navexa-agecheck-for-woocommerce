<?php
/**
 * Link sender for a site that turned verification emails off.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * The kill switch: wp-config.php define( 'NAVEXA_AGECHECK_EMAIL_LINKS', false ).
 * Makes no call and answers Links::STATE_DISABLED, so held orders stay held
 * and the admin panel says the shopper was not emailed. Withdrawing links
 * still reaches AgeCheck (Links::withdraw()).
 */
final class DisabledLinkSender implements LinkSender {

	/**
	 * Answers STATE_DISABLED.
	 *
	 * @param Client $client          Unused.
	 * @param string $verification_id Unused.
	 * @param string $idempotency_key Unused.
	 * @param string $return_url      Unused.
	 */
	public function send( Client $client, string $verification_id, string $idempotency_key, string $return_url ): LinkResult {
		unset( $client, $verification_id, $idempotency_key, $return_url );
		return new LinkResult( Links::STATE_DISABLED );
	}
}
