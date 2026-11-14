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
	 * Shoppers verify at checkout; an order cannot be placed until they pass.
	 */
	const MODE_PRE_PAYMENT = 'pre_payment';

	/**
	 * Orders are accepted and held until the shopper passes.
	 */
	const MODE_ACCEPT_AND_HOLD = 'accept_and_hold';

	const DEFAULT_MODE = self::MODE_PRE_PAYMENT;

	/**
	 * The $_SERVER key of the proxy header that carries the client IP
	 * (RateLimit::PROXY_HEADERS), or '' for REMOTE_ADDR.
	 */
	const TRUSTED_PROXY_HEADER = 'navexa_agecheck_trusted_proxy_header';

	/**
	 * The trusted proxy header's $_SERVER key, or '' for none.
	 */
	public static function trusted_proxy_header(): string {
		$header = get_option( self::TRUSTED_PROXY_HEADER, '' );
		return is_string( $header ) && in_array( $header, RateLimit::PROXY_HEADERS, true ) ? $header : '';
	}

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
}
