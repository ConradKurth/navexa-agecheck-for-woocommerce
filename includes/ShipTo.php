<?php
/**
 * Ship-to address normalization and MAC.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * A ship-to address, normalized the way the AgeCheck engine compares ship-to
 * addresses (Address.ShipToCanonical in the engine's domain/identity.go):
 * line 1 and line 2 lower-cased with every whitespace run collapsed to one
 * space, the postal code upper-cased without whitespace, the country
 * upper-cased; city and region are not compared.
 *
 * On top of the engine's rules the plugin also composes Unicode (NFC, when
 * ext-intl is loaded) and cuts a US ZIP+4 to its 5-digit ZIP. The engine
 * receives this normalized address (engine_address()) both as the pre-order
 * intended_ship_to and, later, as the order's ship_to, so two addresses the
 * plugin treats as equal are equal for the engine too.
 *
 * mac() binds a checkout guard token and an order binding to the address
 * without storing it: HMAC-SHA256 under a key derived from the install's
 * guard secret, over the engine's canonical byte form.
 *
 * Accepted countries are the US and its territories (owner decision
 * 2026-11-13): US (APO/FPO addresses are US with region AA, AE or AP), PR,
 * GU, VI, AS, MP and UM. The freely associated states (FM, MH, PW), also when
 * written as a US address with their state or ZIP code, and every other
 * country are refused.
 *
 * Never log an address or its canonical form.
 */
final class ShipTo {

	/**
	 * Countries AgeCheck verifies: the US and its territories.
	 */
	const ACCEPTED_COUNTRIES = array( 'US', 'PR', 'GU', 'VI', 'AS', 'MP', 'UM' );

	/**
	 * The freely associated states (Micronesia, Marshall Islands, Palau) use
	 * USPS state codes and ZIP codes but are independent countries: refused
	 * even when an address names them under the US.
	 */
	const FREELY_ASSOCIATED_REGIONS = array( 'FM', 'MH', 'PW' );
	const FREELY_ASSOCIATED_ZIPS    = array( '96939', '96940', '96941', '96942', '96943', '96944', '96960', '96970' );

	/**
	 * Longest field the engine accepts, in Unicode code points.
	 */
	const MAX_FIELD_RUNES = 256;

	/**
	 * The engine's domain tag for ship-to canonical forms.
	 */
	const ENGINE_TAG = 'agecheck-ship-to-v1';

	/**
	 * Characters Go's unicode.IsSpace treats as whitespace (strings.Fields
	 * splits on them): ASCII \t \n \v \f \r and space, U+0085, U+00A0 and
	 * the Unicode White_Space characters of category Z.
	 */
	const SPACE_CLASS = '[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';

	/**
	 * Address line 1.
	 *
	 * @var string
	 */
	private $line1;

	/**
	 * Address line 2, or ''.
	 *
	 * @var string
	 */
	private $line2;

	/**
	 * City.
	 *
	 * @var string
	 */
	private $city;

	/**
	 * State or region.
	 *
	 * @var string
	 */
	private $region;

	/**
	 * Postal code (5-digit ZIP for accepted countries).
	 *
	 * @var string
	 */
	private $postal_code;

	/**
	 * ISO 3166-1 alpha-2 country code, upper-case.
	 *
	 * @var string
	 */
	private $country;

	/**
	 * Use the from_*() constructors.
	 *
	 * @param array<string, string> $fields Normalized fields.
	 */
	private function __construct( array $fields ) {
		$this->line1       = $fields['line1'];
		$this->line2       = $fields['line2'];
		$this->city        = $fields['city'];
		$this->region      = $fields['region'];
		$this->postal_code = $fields['postal_code'];
		$this->country     = $fields['country'];
	}

	/**
	 * Builds the address from engine-style fields (line1, line2, city, region,
	 * postal_code, country). Null when a required field (all but line2) is
	 * missing, a field is not valid UTF-8 or longer than MAX_FIELD_RUNES, or
	 * the country is not two letters.
	 *
	 * @param array<string, mixed> $fields Raw fields.
	 */
	public static function from_fields( array $fields ): ?self {
		$read = static function ( string $name ) use ( $fields ): ?string {
			$value = isset( $fields[ $name ] ) ? $fields[ $name ] : '';
			if ( ! is_string( $value ) && ! is_int( $value ) ) {
				return null;
			}
			$value = (string) $value;
			if ( '' !== $value && 1 !== preg_match( '//u', $value ) ) {
				return null; // Not valid UTF-8.
			}
			return self::collapse( self::nfc( $value ) );
		};

		$out = array();
		foreach ( array( 'line1', 'line2', 'city', 'region', 'postal_code', 'country' ) as $name ) {
			$value = $read( $name );
			if ( null === $value || self::runes( $value ) > self::MAX_FIELD_RUNES ) {
				return null;
			}
			$out[ $name ] = $value;
		}

		$out['country'] = strtoupper( $out['country'] );
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $out['country'] ) ) {
			return null;
		}
		$out['postal_code'] = self::upper( (string) preg_replace( '/' . self::SPACE_CLASS . '+/u', '', $out['postal_code'] ) );
		if ( in_array( $out['country'], self::ACCEPTED_COUNTRIES, true ) ) {
			$out['postal_code'] = self::zip5( $out['postal_code'] );
			// WooCommerce keeps no states for the territories; the USPS state
			// code is the territory's own code, and the engine needs a region.
			if ( '' === $out['region'] && 'US' !== $out['country'] ) {
				$out['region'] = $out['country'];
			}
		}

		foreach ( array( 'line1', 'city', 'region', 'postal_code' ) as $required ) {
			if ( '' === $out[ $required ] ) {
				return null;
			}
		}
		return new self( $out );
	}

	/**
	 * Builds the address from a WooCommerce address array (address_1,
	 * address_2, city, state, postcode, country).
	 *
	 * @param array<string, mixed> $address WooCommerce address.
	 */
	public static function from_wc_address( array $address ): ?self {
		return self::from_fields(
			array(
				'line1'       => isset( $address['address_1'] ) ? $address['address_1'] : '',
				'line2'       => isset( $address['address_2'] ) ? $address['address_2'] : '',
				'city'        => isset( $address['city'] ) ? $address['city'] : '',
				'region'      => isset( $address['state'] ) ? $address['state'] : '',
				'postal_code' => isset( $address['postcode'] ) ? $address['postcode'] : '',
				'country'     => isset( $address['country'] ) ? $address['country'] : '',
			)
		);
	}

	/**
	 * The ship-to of classic checkout's posted data: the shipping_* fields, or
	 * the billing_* fields when no shipping country was posted (WooCommerce
	 * copies billing into shipping when the shopper ships to the billing
	 * address, and posts no shipping fields when the cart needs no shipping
	 * address).
	 *
	 * @param array<string, mixed> $data WC_Checkout::get_posted_data().
	 */
	public static function from_checkout_data( array $data ): ?self {
		$type = self::checkout_address_type( $data );
		return self::from_wc_address( self::prefixed( $data, $type . '_' ) );
	}

	/**
	 * The country of classic checkout's posted ship-to, upper-case, or ''.
	 *
	 * @param array<string, mixed> $data WC_Checkout::get_posted_data().
	 */
	public static function country_from_checkout_data( array $data ): string {
		$key = self::checkout_address_type( $data ) . '_country';
		return isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? strtoupper( trim( $data[ $key ] ) ) : '';
	}

	/**
	 * The ship-to of an order: its shipping address when the order needs one,
	 * else its billing address (the same fallback WooCommerce uses for an
	 * order without a shipping address).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function from_order( \WC_Order $order ): ?self {
		return self::from_wc_address( self::order_address( $order ) );
	}

	/**
	 * The country of an order's ship-to, upper-case, or ''.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function country_of_order( \WC_Order $order ): string {
		$address = self::order_address( $order );
		return isset( $address['country'] ) && is_string( $address['country'] ) ? strtoupper( trim( $address['country'] ) ) : '';
	}

	/**
	 * The ship-to of the shopper's session: the customer's shipping address
	 * when the cart needs one, else the billing address.
	 *
	 * @param \WC_Customer $customer       Session customer.
	 * @param bool         $needs_shipping WC()->cart->needs_shipping_address().
	 */
	public static function from_customer( \WC_Customer $customer, bool $needs_shipping ): ?self {
		return self::from_wc_address( self::customer_address( $customer, $needs_shipping ) );
	}

	/**
	 * The country of the session customer's ship-to, upper-case, or ''.
	 *
	 * @param \WC_Customer $customer       Session customer.
	 * @param bool         $needs_shipping WC()->cart->needs_shipping_address().
	 */
	public static function country_of_customer( \WC_Customer $customer, bool $needs_shipping ): string {
		return strtoupper( trim( self::customer_address( $customer, $needs_shipping )['country'] ) );
	}

	/**
	 * Whether $country is one AgeCheck verifies (the US and its territories).
	 *
	 * @param string $country ISO 3166-1 alpha-2 code, any case.
	 */
	public static function is_accepted_country( string $country ): bool {
		return in_array( strtoupper( trim( $country ) ), self::ACCEPTED_COUNTRIES, true );
	}

	/**
	 * Whether AgeCheck verifies this address: an accepted country, and not a
	 * freely associated state written as a US address (its USPS state code or
	 * ZIP code).
	 */
	public function is_accepted(): bool {
		if ( ! self::is_accepted_country( $this->country ) ) {
			return false;
		}
		if ( in_array( strtoupper( $this->region ), self::FREELY_ASSOCIATED_REGIONS, true ) ) {
			return false;
		}
		return ! in_array( substr( $this->postal_code, 0, 5 ), self::FREELY_ASSOCIATED_ZIPS, true );
	}

	/**
	 * Country code.
	 */
	public function country(): string {
		return $this->country;
	}

	/**
	 * The normalized address as an AgeCheck Address message (proto3 JSON
	 * names; an empty line2 is left out, as proto3 JSON omits zero values).
	 *
	 * @return array<string, string>
	 */
	public function engine_address(): array {
		$address = array(
			'line1'      => $this->line1,
			'line2'      => $this->line2,
			'city'       => $this->city,
			'region'     => $this->region,
			'postalCode' => $this->postal_code,
			'country'    => $this->country,
		);
		if ( '' === $address['line2'] ) {
			unset( $address['line2'] );
		}
		return $address;
	}

	/**
	 * The engine's canonical byte form of this (normalized) address. PII:
	 * hash it, never store or log it.
	 */
	public function canonical(): string {
		return self::engine_canonical(
			array(
				'line1'       => $this->line1,
				'line2'       => $this->line2,
				'postal_code' => $this->postal_code,
				'country'     => $this->country,
			)
		);
	}

	/**
	 * Lower-case hex HMAC-SHA256 of canonical() under the install's ship-to
	 * key, or '' when the install has no guard secret (callers fail closed).
	 */
	public function mac(): string {
		$secret = Install::guard_secret();
		if ( '' === $secret ) {
			return '';
		}
		$key = hash_hmac( 'sha256', 'navexa-agecheck/ship-to-mac/v1', $secret, true );
		return hash_hmac( 'sha256', $this->canonical(), $key );
	}

	/**
	 * Whether this address normalizes to the same canonical form as $other.
	 *
	 * @param ShipTo $other Other address.
	 */
	public function equals( ShipTo $other ): bool {
		return hash_equals( $this->canonical(), $other->canonical() );
	}

	/**
	 * Port of the engine's Address.ShipToCanonical over the fields as given:
	 * line1 and line2 folded (lower-case, whitespace runs collapsed, ends
	 * trimmed), the postal code upper-cased with all whitespace removed, the
	 * country trimmed and upper-cased; each field length-prefixed in bytes
	 * after the domain tag. Pinned by tests/phpunit/fixtures/ship-to-vectors.json,
	 * exported from the engine.
	 *
	 * @param array<string, string> $fields line1, line2, postal_code, country.
	 */
	public static function engine_canonical( array $fields ): string {
		$parts = array(
			self::fold( isset( $fields['line1'] ) ? $fields['line1'] : '' ),
			self::fold( isset( $fields['line2'] ) ? $fields['line2'] : '' ),
			self::upper( (string) preg_replace( '/' . self::SPACE_CLASS . '+/u', '', isset( $fields['postal_code'] ) ? $fields['postal_code'] : '' ) ),
			self::upper( self::trim_space( isset( $fields['country'] ) ? $fields['country'] : '' ) ),
		);
		$out   = self::ENGINE_TAG;
		foreach ( $parts as $part ) {
			$out .= "\n" . strlen( $part ) . ':' . $part;
		}
		return $out;
	}

	/**
	 * Lower-cases $value (Unicode simple case mapping, as Go's
	 * strings.ToLower) and collapses every whitespace run to one space,
	 * trimming the ends.
	 *
	 * @param string $value Value.
	 */
	public static function fold( string $value ): string {
		return self::collapse( self::lower( $value ) );
	}

	/**
	 * $value with every whitespace run collapsed to one space and the ends
	 * trimmed (Go's strings.Join(strings.Fields(s), " ")).
	 *
	 * @param string $value Value.
	 */
	private static function collapse( string $value ): string {
		$parts = preg_split( '/' . self::SPACE_CLASS . '+/u', $value, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $parts ) ? implode( ' ', $parts ) : '';
	}

	/**
	 * $value without leading and trailing whitespace (Go's strings.TrimSpace).
	 *
	 * @param string $value Value.
	 */
	private static function trim_space( string $value ): string {
		return (string) preg_replace( '/^' . self::SPACE_CLASS . '+|' . self::SPACE_CLASS . '+$/u', '', $value );
	}

	/**
	 * Unicode simple lower-case mapping; ASCII only without ext-mbstring.
	 * Without it the plugin is stricter than the engine (a non-ASCII case
	 * difference reads as a different address), never looser.
	 *
	 * @param string $value Value.
	 */
	private static function lower( string $value ): string {
		return function_exists( 'mb_convert_case' ) ? mb_convert_case( $value, MB_CASE_LOWER_SIMPLE, 'UTF-8' ) : strtolower( $value );
	}

	/**
	 * Unicode simple upper-case mapping; ASCII only without ext-mbstring.
	 *
	 * @param string $value Value.
	 */
	private static function upper( string $value ): string {
		return function_exists( 'mb_convert_case' ) ? mb_convert_case( $value, MB_CASE_UPPER_SIMPLE, 'UTF-8' ) : strtoupper( $value );
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

	/**
	 * A US ZIP+4 (12345-6789 or 123456789) cut to its 5-digit ZIP; anything
	 * else unchanged.
	 *
	 * @param string $postal Postal code, whitespace removed, upper-case.
	 */
	private static function zip5( string $postal ): string {
		return 1 === preg_match( '/^([0-9]{5})-?[0-9]{4}$/', $postal, $match ) ? $match[1] : $postal;
	}

	/**
	 * 'shipping' when classic checkout posted a shipping country, else 'billing'.
	 *
	 * @param array<string, mixed> $data Posted data.
	 */
	private static function checkout_address_type( array $data ): string {
		return isset( $data['shipping_country'] ) && is_string( $data['shipping_country'] ) && '' !== trim( $data['shipping_country'] ) ? 'shipping' : 'billing';
	}

	/**
	 * The address fields under $prefix (shipping_ or billing_) of posted data.
	 *
	 * @param array<string, mixed> $data   Posted data.
	 * @param string               $prefix Field prefix.
	 * @return array<string, mixed>
	 */
	private static function prefixed( array $data, string $prefix ): array {
		$out = array();
		foreach ( array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $field ) {
			$out[ $field ] = isset( $data[ $prefix . $field ] ) ? $data[ $prefix . $field ] : '';
		}
		return $out;
	}

	/**
	 * An order's ship-to as a WooCommerce address array.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	private static function order_address( \WC_Order $order ): array {
		$type = $order->needs_shipping_address() && '' !== (string) $order->get_shipping_country() ? 'shipping' : 'billing';
		$out  = $order->get_address( $type );
		return is_array( $out ) ? $out : array();
	}

	/**
	 * The session customer's ship-to as a WooCommerce address array.
	 *
	 * @param \WC_Customer $customer       Customer.
	 * @param bool         $needs_shipping Whether the cart needs a shipping address.
	 * @return array{address_1: string, address_2: string, city: string, state: string, postcode: string, country: string}
	 */
	private static function customer_address( \WC_Customer $customer, bool $needs_shipping ): array {
		if ( $needs_shipping && '' !== (string) $customer->get_shipping_country() ) {
			return array(
				'address_1' => (string) $customer->get_shipping_address_1(),
				'address_2' => (string) $customer->get_shipping_address_2(),
				'city'      => (string) $customer->get_shipping_city(),
				'state'     => (string) $customer->get_shipping_state(),
				'postcode'  => (string) $customer->get_shipping_postcode(),
				'country'   => (string) $customer->get_shipping_country(),
			);
		}
		return array(
			'address_1' => (string) $customer->get_billing_address_1(),
			'address_2' => (string) $customer->get_billing_address_2(),
			'city'      => (string) $customer->get_billing_city(),
			'state'     => (string) $customer->get_billing_state(),
			'postcode'  => (string) $customer->get_billing_postcode(),
			'country'   => (string) $customer->get_billing_country(),
		);
	}
}
