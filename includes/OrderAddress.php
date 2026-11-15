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
 * The plugin's only address normalizer is normalize(). Every address the
 * engine sees goes through it: the checkout's intended_ship_to (ShipTo),
 * the order's ship_to for AttachOrder, CreateVerification and GetClearance
 * (here), and the identity's residential address. The intended ship-to and
 * the order's ship-to must normalize identically, or AttachOrder answers
 * ship_to_changed. It composes Unicode (NFC, when ext-intl is loaded),
 * collapses every whitespace run the engine's strings.Fields splits on,
 * refuses fields that are not valid UTF-8 or longer than the engine accepts,
 * upper-cases the country, strips whitespace from the postal code and cuts a
 * US ZIP+4 to its five digits (the engine's ShipToCanonical keeps the postal
 * code as sent, so 12345-6789 and 12345 would not match). A territory
 * without a region gets its country code as the region; every address needs
 * line 1, city, region and postal code, as the engine's Address does.
 *
 * Which address an order ships to (ship_to_type()): its shipping address
 * whenever it has one or needs one, and billing only for an order with no
 * shipping address at all. An order without a shipping line can still carry
 * a full shipping address (staff-created, REST, POS and imported orders), and
 * WooCommerce, the admin screen and fulfilment exports ship it there. For a
 * cart checkout this is the same address as the session customer's
 * (ShipTo::from_customer()): a cart that needs no shipping address leaves the
 * order's shipping empty (classic checkout) or a copy of billing (Store API).
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
	 * Longest field the engine accepts, in Unicode code points (Address and
	 * Identity max_len).
	 */
	const MAX_FIELD_RUNES = 256;

	/**
	 * Characters Go's unicode.IsSpace treats as whitespace (strings.Fields
	 * splits on them): ASCII \t \n \v \f \r and space, U+0085, U+00A0 and
	 * the Unicode White_Space characters of category Z.
	 */
	const SPACE_CLASS = '[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';

	/**
	 * Where the order ships, as an Address message, or null when the address is
	 * incomplete or invalid.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, string>|null
	 */
	public static function ship_to( \WC_Order $order ): ?array {
		return self::normalize( self::parts( $order, self::ship_to_type( $order ) ) );
	}

	/**
	 * Which of the order's addresses is its ship-to: 'shipping' when the order
	 * needs a shipping address or any shipping address field is filled in,
	 * 'billing' only when the shipping address is wholly empty and nothing is
	 * shipped (WooCommerce's own fallback). An incomplete shipping address is
	 * never replaced by billing: ship_to() answers null for it, so a held
	 * order goes to review and a pre-payment checkout is refused.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function ship_to_type( \WC_Order $order ): string {
		if ( $order->needs_shipping_address() ) {
			return 'shipping';
		}
		foreach ( self::parts( $order, 'shipping' ) as $value ) {
			if ( '' !== trim( $value ) ) {
				return 'shipping';
			}
		}
		return 'billing';
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
	 * An Address message from WooCommerce address fields (address_1,
	 * address_2, city, state, postcode, country), or null when line 1, city,
	 * region (state), postcode or country is missing, a field is not valid
	 * UTF-8 or longer than MAX_FIELD_RUNES, or the country is not two letters.
	 * An empty line2 is left out, as proto3 JSON omits zero values.
	 *
	 * @param array<string, mixed> $parts WooCommerce address fields.
	 * @return array<string, string>|null
	 */
	public static function normalize( array $parts ): ?array {
		$out = array();
		foreach ( array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $name ) {
			$value = isset( $parts[ $name ] ) ? $parts[ $name ] : '';
			if ( ! is_string( $value ) && ! is_int( $value ) ) {
				return null;
			}
			$value = (string) $value;
			if ( '' !== $value && 1 !== preg_match( '//u', $value ) ) {
				return null; // Not valid UTF-8.
			}
			$value = self::collapse( self::nfc( $value ) );
			if ( self::runes( $value ) > self::MAX_FIELD_RUNES ) {
				return null;
			}
			$out[ $name ] = $value;
		}

		$country = strtoupper( $out['country'] );
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return null;
		}
		$postal = (string) preg_replace( '/' . self::SPACE_CLASS . '+/u', '', $out['postcode'] );
		$postal = function_exists( 'mb_convert_case' ) ? mb_convert_case( $postal, MB_CASE_UPPER_SIMPLE, 'UTF-8' ) : strtoupper( $postal );
		$region = $out['state'];
		if ( in_array( $country, self::US_COUNTRIES, true ) ) {
			if ( 1 === preg_match( '/^([0-9]{5})-?[0-9]{4}$/', $postal, $zip ) ) {
				$postal = $zip[1];
			}
			// WooCommerce keeps no states for the territories; the USPS state
			// code is the territory's own code, and the engine needs a region.
			if ( '' === $region && 'US' !== $country ) {
				$region = $country;
			}
		}

		$address = array(
			'line1'      => $out['address_1'],
			'line2'      => $out['address_2'],
			'city'       => $out['city'],
			'region'     => $region,
			'postalCode' => $postal,
			'country'    => $country,
		);
		foreach ( array( 'line1', 'city', 'region', 'postalCode' ) as $required ) {
			if ( '' === $address[ $required ] ) {
				return null;
			}
		}
		if ( '' === $address['line2'] ) {
			unset( $address['line2'] );
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
	 * SHA-256 (hex) of $address's engine canonical form
	 * (ShipTo::engine_canonical(), the port of the engine's ShipToCanonical:
	 * lines folded, postal code upper-cased without whitespace, country
	 * upper-cased; city and region left out). Stored as
	 * Meta::SHIP_TO_HASH, the ship-to an order was bound with; never sent
	 * anywhere.
	 *
	 * @param array<string, string> $address Address message.
	 */
	public static function hash( array $address ): string {
		return hash(
			'sha256',
			ShipTo::engine_canonical(
				array(
					'line1'       => isset( $address['line1'] ) ? (string) $address['line1'] : '',
					'line2'       => isset( $address['line2'] ) ? (string) $address['line2'] : '',
					'postal_code' => isset( $address['postalCode'] ) ? (string) $address['postalCode'] : '',
					'country'     => isset( $address['country'] ) ? (string) $address['country'] : '',
				)
			)
		);
	}

	/**
	 * Whether $a and $b are one residence by the engine's data-match
	 * comparison (domain.sameResidence, which DataMatchEligible uses): the
	 * fields hash() covers (lines folded, postal code upper-cased without
	 * whitespace, country upper-cased; city and region left out), with a
	 * postal code that starts with five digits cut to them (a ZIP+4 read as
	 * its ZIP). Never stricter than the engine, or an eligible shopper would
	 * lose the data match.
	 *
	 * @param array<string, string> $a Address message.
	 * @param array<string, string> $b Address message.
	 */
	public static function same_residence( array $a, array $b ): bool {
		return self::residence( $a ) === self::residence( $b );
	}

	/**
	 * $address's same_residence() form.
	 *
	 * @param array<string, string> $address Address message.
	 */
	private static function residence( array $address ): string {
		$postal = (string) preg_replace( '/' . self::SPACE_CLASS . '+/u', '', isset( $address['postalCode'] ) ? (string) $address['postalCode'] : '' );
		if ( 1 === preg_match( '/^[0-9]{5}/', $postal ) ) {
			$postal = substr( $postal, 0, 5 );
		}
		return ShipTo::engine_canonical(
			array(
				'line1'       => isset( $address['line1'] ) ? (string) $address['line1'] : '',
				'line2'       => isset( $address['line2'] ) ? (string) $address['line2'] : '',
				'postal_code' => $postal,
				'country'     => isset( $address['country'] ) ? (string) $address['country'] : '',
			)
		);
	}

	/**
	 * $value with every whitespace run (SPACE_CLASS) collapsed to one space
	 * and the ends trimmed (Go's strings.Join(strings.Fields(s), " ")).
	 *
	 * @param string $value Valid UTF-8.
	 */
	public static function collapse( string $value ): string {
		$parts = preg_split( '/' . self::SPACE_CLASS . '+/u', $value, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $parts ) ? implode( ' ', $parts ) : '';
	}

	/**
	 * $value composed to Unicode NFC when ext-intl is loaded, else unchanged.
	 *
	 * @param string $value Valid UTF-8.
	 */
	private static function nfc( string $value ): string {
		if ( '' === $value || ! class_exists( 'Normalizer' ) ) {
			return $value;
		}
		$composed = \Normalizer::normalize( $value, \Normalizer::FORM_C );
		return is_string( $composed ) ? $composed : $value;
	}

	/**
	 * Length of $value in code points.
	 *
	 * @param string $value Valid UTF-8.
	 */
	private static function runes( string $value ): int {
		$count = preg_match_all( '/./us', $value );
		return false === $count ? PHP_INT_MAX : $count;
	}
}
