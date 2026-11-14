<?php
/**
 * Merchant settings storage.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Option names and typed getters for the merchant's settings. Kept apart from
 * Settings, which extends WC_Settings_Page and therefore loads only on
 * WooCommerce's settings screens; checkout code reads its settings from here.
 *
 * Install state (install_id, guard secret, fingerprint) lives in Install.
 */
final class Options {

	const START_KEY      = 'navexa_agecheck_start_key';
	const FULFILMENT_KEY = 'navexa_agecheck_fulfilment_key';
	const MODE           = 'navexa_agecheck_mode';

	/**
	 * Which line items need an age check: RULE_ALL, RULE_INCLUDE or RULE_EXCLUDE.
	 */
	const RULE = 'navexa_agecheck_rule';

	/**
	 * Product category term ids the include or exclude rule names.
	 */
	const RULE_CATEGORIES = 'navexa_agecheck_rule_categories';

	/**
	 * Product tag term ids the include or exclude rule names.
	 */
	const RULE_TAGS = 'navexa_agecheck_rule_tags';

	/**
	 * Roles whose own orders (the order's customer, not whoever places it)
	 * skip the age check.
	 */
	const EXEMPT_ROLES = 'navexa_agecheck_exempt_roles';

	/**
	 * Webhook endpoint secret from the Navexa dashboard. Stored with autoload off.
	 */
	const WEBHOOK_SECRET = 'navexa_agecheck_webhook_secret';

	/**
	 * Unix time of the last webhook delivery whose signature verified.
	 */
	const WEBHOOK_LAST_RECEIVED = 'navexa_agecheck_webhook_last_received';

	/**
	 * What happens to a paid order whose shopper fails the age check:
	 * DENIED_AUTO_REFUND or DENIED_MANUAL.
	 */
	const DENIED_ORDERS = 'navexa_agecheck_denied_orders';

	/**
	 * Every line item needs an age check (unless its product is exempt).
	 */
	const RULE_ALL = 'all';

	/**
	 * Only products in the chosen categories or tags (or flagged) need one.
	 */
	const RULE_INCLUDE = 'include';

	/**
	 * Every product except those in the chosen categories or tags needs one.
	 */
	const RULE_EXCLUDE = 'exclude';

	/**
	 * Refund a denied order through its payment gateway when the gateway can
	 * refund by API; otherwise list it for a manual refund. The default (Q11d).
	 */
	const DENIED_AUTO_REFUND = 'auto_refund';

	/**
	 * Always list a denied order for a manual refund.
	 */
	const DENIED_MANUAL = 'manual';

	/**
	 * Shoppers verify at checkout; an order cannot be placed until they pass.
	 */
	const MODE_PRE_PAYMENT = 'pre_payment';

	/**
	 * Orders are accepted and held until the shopper passes.
	 */
	const MODE_ACCEPT_AND_HOLD = 'accept_and_hold';

	const DEFAULT_MODE = self::MODE_PRE_PAYMENT;

	/**
	 * Option name of the $key_kind API key.
	 *
	 * @param string $key_kind Client::KEY_START or Client::KEY_FULFILMENT.
	 */
	public static function key_option( string $key_kind ): string {
		return Client::KEY_START === $key_kind ? self::START_KEY : self::FULFILMENT_KEY;
	}

	/**
	 * The stored $key_kind API key, or ''. Stored with autoload off.
	 *
	 * @param string $key_kind Client::KEY_START or Client::KEY_FULFILMENT.
	 */
	public static function api_key( string $key_kind ): string {
		$key = get_option( self::key_option( $key_kind ), '' );
		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * Whether $key has the shape of an AgeCheck API key (txk_ followed by the
	 * random part; today 64 hex characters). Only catches paste mistakes;
	 * GetIntegrationInfo decides whether the key works.
	 *
	 * @param string $key Candidate key.
	 */
	public static function looks_like_api_key( string $key ): bool {
		return 1 === preg_match( '/^txk_[A-Za-z0-9]{16,256}$/', $key );
	}

	/**
	 * Display form of a stored key: the first 8 characters, as the Navexa
	 * dashboard lists keys, never the whole key.
	 *
	 * @param string $key Stored key.
	 */
	public static function mask_api_key( string $key ): string {
		if ( strlen( $key ) < 16 ) {
			return '********';
		}
		return substr( $key, 0, 8 ) . '…';
	}

	/**
	 * The checkout mode: MODE_PRE_PAYMENT or MODE_ACCEPT_AND_HOLD.
	 */
	public static function mode(): string {
		$mode = get_option( self::MODE, self::DEFAULT_MODE );
		return in_array( $mode, array( self::MODE_PRE_PAYMENT, self::MODE_ACCEPT_AND_HOLD ), true ) ? $mode : self::DEFAULT_MODE;
	}

	/**
	 * The line-item rule: RULE_ALL (default), RULE_INCLUDE or RULE_EXCLUDE.
	 * An unknown stored value reads as RULE_ALL, the strictest.
	 */
	public static function rule(): string {
		$rule = get_option( self::RULE, self::RULE_ALL );
		return in_array( $rule, array( self::RULE_INCLUDE, self::RULE_EXCLUDE ), true ) ? $rule : self::RULE_ALL;
	}

	/**
	 * Term ids stored under $option (RULE_CATEGORIES or RULE_TAGS).
	 *
	 * @param string $option Option name.
	 * @return int[]
	 */
	public static function term_ids( string $option ): array {
		$ids = get_option( $option, array() );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Role slugs whose own orders are exempt.
	 *
	 * @return string[]
	 */
	public static function exempt_roles(): array {
		$roles = get_option( self::EXEMPT_ROLES, array() );
		if ( ! is_array( $roles ) ) {
			return array();
		}
		return array_values(
			array_filter(
				array_map( 'strval', $roles ),
				static function ( string $role ): bool {
					return '' !== $role;
				}
			)
		);
	}

	/**
	 * The stored webhook secret, or ''.
	 */
	public static function webhook_secret(): string {
		$secret = get_option( self::WEBHOOK_SECRET, '' );
		return is_string( $secret ) ? trim( $secret ) : '';
	}

	/**
	 * Whether $secret looks like a webhook secret: 16 to 256 printable ASCII
	 * characters without spaces. Only catches paste mistakes.
	 *
	 * @param string $secret Candidate secret.
	 */
	public static function looks_like_webhook_secret( string $secret ): bool {
		return 1 === preg_match( '/^[\x21-\x7e]{16,256}$/', $secret );
	}

	/**
	 * DENIED_AUTO_REFUND (default) or DENIED_MANUAL.
	 */
	public static function denied_orders(): string {
		return self::DENIED_MANUAL === get_option( self::DENIED_ORDERS, self::DENIED_AUTO_REFUND ) ? self::DENIED_MANUAL : self::DENIED_AUTO_REFUND;
	}
}
