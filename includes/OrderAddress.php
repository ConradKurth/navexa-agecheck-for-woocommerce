<?php
/**
 * Order addresses as AgeCheck Address messages.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the AgeCheck Address message (proto3 JSON names: line1, line2, city,
 * region, postalCode, country) from an order's shipping or billing address,
 * and decides whether an address is one AgeCheck verifies.
 *
 * The single normalizer, normalize(), serves every address this plugin sends:
 * the checkout's intended ship-to (W2) and the order's ship-to here must
 * normalize identically, or AttachOrder answers ship_to_changed. It trims and
 * collapses whitespace, applies NFC when ext-intl is present, upper-cases the
 * country and cuts a US ZIP+4 to its five digits (the engine's
 * ShipToCanonical keeps the postal code as sent, so 12345-6789 and 12345 would
 * not match). A territory without a region gets its country code as the
 * region, because the engine requires one; region is not part of the match.
 *
 * US only (owner decisions 2026-11-13/14): the US and its territories PR, GU,
 * VI, AS, MP and UM are accepted; the freely associated states FM, MH and PW,
 * and every other country, are refused. Mirrors the engine's
 * Address.RequireUS (E3).
 */
final class OrderAddress {

	/**
	 * Countries AgeCheck verifies: the US and its territories.
	 */
	const US_COUNTRIES = array( 'US', 'PR', 'GU', 'VI', 'AS', 'MP', 'UM' );

	/**
	 * Freely associated states USPS gives state codes to; independent countries.
	 */
	const FREELY_ASSOCIATED_REGIONS = array( 'FM', 'MH', 'PW' );

	/**
	 * Names of those states, refused when they appear in a region.
	 */
	const FREELY_ASSOCIATED_NAMES = array( 'micronesia', 'marshall', 'palau' );

	/**
	 * ZIP codes (first five digits) of Palau, Micronesia and the Marshall Islands.
	 */
	const FREELY_ASSOCIATED_ZIPS = array( '96939', '96940', '96941', '96942', '96943', '96944', '96960', '96970' );

	/**
	 * Where the order ships, as an Address message, or null when the address is
	 * incomplete. An order that ships nothing (no shipping address and no
	 * shipping need) uses its billing address, as WooCommerce's tax location does.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, string>|null
	 */
	public static function ship_to( \WC_Order $order ): ?array {
		$shipping = self::parts( $order, 'shipping' );
		if ( '' === $shipping['country'] && '' === $shipping['address_1'] && ! $order->needs_shipping_address() ) {
			return self::normalize( self::parts( $order, 'billing' ) );
		}
		return self::normalize( $shipping );
	}

	/**
	 * The billing address as an Address message (the identity's residential
	 * address), or null when incomplete.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, string>|null
	 */
	public static function billing( \WC_Order $order ): ?array {
		return self::normalize( self::parts( $order, 'billing' ) );
	}

	/**
	 * The order's raw $type address fields (edit context, unsaved changes included).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $type  shipping or billing.
	 * @return array{address_1: string, address_2: string, city: string, state: string, postcode: string, country: string}
	 */
	public static function parts( \WC_Order $order, string $type ): array {
		$parts = array();
		foreach ( array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $field ) {
			$getter          = 'get_' . $type . '_' . $field;
			$value           = is_callable( array( $order, $getter ) ) ? $order->{$getter}( 'edit' ) : '';
			$parts[ $field ] = is_scalar( $value ) ? (string) $value : '';
		}
		return $parts;
	}

	/**
	 * An Address message from WooCommerce address fields, or null when line 1,
	 * city, postcode or country is missing.
	 *
	 * @param array<string, mixed> $parts address_1, address_2, city, state, postcode, country.
	 * @return array<string, string>|null
	 */
	public static function normalize( array $parts ): ?array {
		$field   = static function ( string $name ) use ( $parts ): string {
			return isset( $parts[ $name ] ) && is_scalar( $parts[ $name ] ) ? self::clean( (string) $parts[ $name ] ) : '';
		};
		$country = strtoupper( $field( 'country' ) );
		$region  = $field( 'state' );
		$postal  = $field( 'postcode' );
		if ( in_array( $country, self::US_COUNTRIES, true ) && preg_match( '/^(\d{5})(?:[\s-]?\d{4})?$/', $postal, $zip ) ) {
			$postal = $zip[1];
		}
		if ( '' === $region && in_array( $country, self::US_COUNTRIES, true ) && 'US' !== $country ) {
			$region = $country;
		}
		$address = array(
			'line1'      => $field( 'address_1' ),
			'line2'      => $field( 'address_2' ),
			'city'       => $field( 'city' ),
			'region'     => $region,
			'postalCode' => $postal,
			'country'    => $country,
		);
		if ( '' === $address['line1'] || '' === $address['city'] || '' === $address['postalCode'] || 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return null;
		}
		if ( '' === $address['line2'] ) {
			unset( $address['line2'] );
		}
		if ( '' === $address['region'] ) {
			unset( $address['region'] );
		}
		return $address;
	}

	/**
	 * Whether AgeCheck verifies an order shipping to $address.
	 *
	 * @param array<string, string> $address Address message from normalize().
	 */
	public static function is_us( array $address ): bool {
		$country = isset( $address['country'] ) ? strtoupper( $address['country'] ) : '';
		if ( ! in_array( $country, self::US_COUNTRIES, true ) ) {
			return false;
		}
		$region = isset( $address['region'] ) ? strtolower( $address['region'] ) : '';
		foreach ( preg_split( '/[^a-z]+/', $region, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
			if ( in_array( strtoupper( $word ), self::FREELY_ASSOCIATED_REGIONS, true ) ) {
				return false;
			}
		}
		foreach ( self::FREELY_ASSOCIATED_NAMES as $name ) {
			if ( false !== strpos( $region, $name ) ) {
				return false;
			}
		}
		$zip = preg_replace( '/\s+/', '', isset( $address['postalCode'] ) ? $address['postalCode'] : '' );
		return ! in_array( substr( (string) $zip, 0, 5 ), self::FREELY_ASSOCIATED_ZIPS, true );
	}

	/**
	 * SHA-256 (hex) of $address's match fields as the engine's ShipToCanonical
	 * folds them: lines lower-cased and whitespace-collapsed, postal code
	 * upper-cased without whitespace, country upper-cased; city and region left
	 * out. For comparing two addresses locally; never sent anywhere.
	 *
	 * @param array<string, string> $address Address message.
	 */
	public static function hash( array $address ): string {
		$fold      = static function ( string $value ): string {
			return trim( (string) preg_replace( '/\s+/u', ' ', strtolower( $value ) ) );
		};
		$fields    = array(
			$fold( isset( $address['line1'] ) ? $address['line1'] : '' ),
			$fold( isset( $address['line2'] ) ? $address['line2'] : '' ),
			strtoupper( (string) preg_replace( '/\s+/u', '', isset( $address['postalCode'] ) ? $address['postalCode'] : '' ) ),
			strtoupper( trim( isset( $address['country'] ) ? $address['country'] : '' ) ),
		);
		$canonical = 'agecheck-ship-to-v1';
		foreach ( $fields as $value ) {
			$canonical .= "\n" . strlen( $value ) . ':' . $value;
		}
		return hash( 'sha256', $canonical );
	}

	/**
	 * Trims, collapses whitespace and applies NFC when ext-intl is loaded.
	 *
	 * @param string $value Raw value.
	 */
	private static function clean( string $value ): string {
		if ( class_exists( 'Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $value, \Normalizer::FORM_C );
			if ( is_string( $normalized ) ) {
				$value = $normalized;
			}
		}
		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}
}
