<?php
/**
 * Ship-to address normalization and MAC.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * A ship-to address, normalized by OrderAddress::normalize() (the plugin's
 * only address normalizer), and compared the way the AgeCheck engine
 * compares ship-to addresses (Address.ShipToCanonical in the engine's
 * domain/identity.go): line 1 and line 2 lower-cased with every whitespace
 * run collapsed to one space, the postal code upper-cased without whitespace,
 * the country upper-cased; city and region are not compared.
 *
 * The engine receives the normalized address (engine_address()) both as the
 * pre-order intended_ship_to and, later, as the order's ship_to
 * (OrderAddress::ship_to()), so two addresses the plugin treats as equal are
 * equal for the engine too.
 *
 * mac() binds a checkout guard token to the address without storing it:
 * HMAC-SHA256 under a key derived from the install's guard secret, over the
 * engine's canonical byte form. The ship-to an order was bound with is
 * stored as OrderAddress::hash() (Meta::SHIP_TO_HASH), which survives a
 * rotated guard secret.
 *
 * Accepted countries and the freely associated states are OrderAddress's
 * (owner decision 2026-11-13): US (APO/FPO addresses are US with region AA,
 * AE or AP), PR, GU, VI, AS, MP and UM.
 *
 * Never log an address or its canonical form.
 */
final class ShipTo {

	/**
	 * The engine's domain tag for ship-to canonical forms.
	 */
	const ENGINE_TAG = 'agecheck-ship-to-v1';

	/**
	 * The normalized Address message (proto3 JSON names).
	 *
	 * @var array<string, string>
	 */
	private $address;

	/**
	 * Use the from_*() constructors.
	 *
	 * @param array<string, string> $address Address message from OrderAddress::normalize().
	 */
	private function __construct( array $address ) {
		$this->address = $address;
	}

	/**
	 * Wraps an Address message OrderAddress::normalize() produced; null for null.
	 *
	 * @param array<string, string>|null $address Normalized Address message.
	 */
	public static function from_message( ?array $address ): ?self {
		return null === $address ? null : new self( $address );
	}

	/**
	 * Builds the address from engine-style fields (line1, line2, city, region,
	 * postal_code, country), through OrderAddress::normalize().
	 *
	 * @param array<string, mixed> $fields Raw fields.
	 */
	public static function from_fields( array $fields ): ?self {
		return self::from_wc_address(
			array(
				'address_1' => isset( $fields['line1'] ) ? $fields['line1'] : '',
				'address_2' => isset( $fields['line2'] ) ? $fields['line2'] : '',
				'city'      => isset( $fields['city'] ) ? $fields['city'] : '',
				'state'     => isset( $fields['region'] ) ? $fields['region'] : '',
				'postcode'  => isset( $fields['postal_code'] ) ? $fields['postal_code'] : '',
				'country'   => isset( $fields['country'] ) ? $fields['country'] : '',
			)
		);
	}

	/**
	 * Builds the address from a WooCommerce address array (address_1,
	 * address_2, city, state, postcode, country).
	 *
	 * @param array<string, mixed> $address WooCommerce address.
	 */
	public static function from_wc_address( array $address ): ?self {
		return self::from_message( OrderAddress::normalize( $address ) );
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
	 * The ship-to of an order (OrderAddress::ship_to(): its shipping address
	 * when the order has or needs one, billing only when it has none).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function from_order( \WC_Order $order ): ?self {
		return self::from_message( OrderAddress::ship_to( $order ) );
	}

	/**
	 * The country of an order's ship-to, upper-case, or ''.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function country_of_order( \WC_Order $order ): string {
		return strtoupper( trim( OrderAddress::parts( $order, OrderAddress::ship_to_type( $order ) )['country'] ) );
	}

	/**
	 * The ship-to of the shopper's session: the customer's shipping address
	 * when the cart needs one, else the billing address. The order the cart
	 * becomes ships to the same address (OrderAddress::ship_to_type()): a cart
	 * that needs no shipping address gets no shipping address on classic
	 * checkout and a copy of billing through the Store API. Not the order's
	 * rule on purpose: before the Store API copies billing over it, the session
	 * customer of such a cart may still hold an old shipping address.
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
		return in_array( strtoupper( trim( $country ) ), OrderAddress::US_COUNTRIES, true );
	}

	/**
	 * Whether AgeCheck verifies this address (OrderAddress::is_us(): an
	 * accepted country, and not a freely associated state written as a US
	 * address).
	 */
	public function is_accepted(): bool {
		return OrderAddress::is_us( $this->address );
	}

	/**
	 * Country code.
	 */
	public function country(): string {
		return $this->address['country'];
	}

	/**
	 * The normalized address as an AgeCheck Address message (proto3 JSON
	 * names; an empty line2 is left out, as proto3 JSON omits zero values).
	 *
	 * @return array<string, string>
	 */
	public function engine_address(): array {
		return $this->address;
	}

	/**
	 * The engine's canonical byte form of this (normalized) address. PII:
	 * hash it, never store or log it.
	 */
	public function canonical(): string {
		return self::engine_canonical(
			array(
				'line1'       => $this->address['line1'],
				'line2'       => isset( $this->address['line2'] ) ? $this->address['line2'] : '',
				'postal_code' => $this->address['postalCode'],
				'country'     => $this->address['country'],
			)
		);
	}

	/**
	 * OrderAddress::hash() of this address: what Meta::SHIP_TO_HASH stores
	 * for the ship-to an order was bound with.
	 */
	public function hash(): string {
		return OrderAddress::hash( $this->address );
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
			self::upper( (string) preg_replace( '/' . OrderAddress::SPACE_CLASS . '+/u', '', isset( $fields['postal_code'] ) ? $fields['postal_code'] : '' ) ),
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
		return OrderAddress::collapse( self::lower( $value ) );
	}

	/**
	 * $value without leading and trailing whitespace (Go's strings.TrimSpace).
	 *
	 * @param string $value Value.
	 */
	private static function trim_space( string $value ): string {
		return (string) preg_replace( '/^' . OrderAddress::SPACE_CLASS . '+|' . OrderAddress::SPACE_CLASS . '+$/u', '', $value );
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
