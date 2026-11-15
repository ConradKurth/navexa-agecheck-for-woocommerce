<?php
/**
 * OrderAddress tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\OrderAddress;
use Navexa\AgeCheck\Tests\TestCase;

final class OrderAddressTest extends TestCase {

	public function test_ship_to_is_normalized(): void {
		$order = \WC_Order::make();
		$order->set_shipping_address_1( "  1  Main\tSt " );
		$order->set_shipping_address_2( 'Apt 2' );
		$order->set_shipping_country( 'us' );
		$this->assertSame(
			array(
				'line1'      => '1 Main St',
				'line2'      => 'Apt 2',
				'city'       => 'Springfield',
				'region'     => 'IL',
				'postalCode' => '62701',
				'country'    => 'US',
			),
			OrderAddress::ship_to( $order ),
			'ZIP+4 is cut to five digits so it matches the checkout ship-to'
		);
	}

	public function test_incomplete_ship_to_is_null_and_only_an_order_without_a_shipping_address_uses_billing(): void {
		$order = \WC_Order::make();
		$order->add_test_item( new \WC_Order_Item_Shipping( 'flat_rate' ) );
		foreach ( array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $field ) {
			$order->{'set_shipping_' . $field}( '' );
		}
		$this->assertNull( OrderAddress::ship_to( $order ), 'needs shipping, has no address' );
		$this->assertSame( 'shipping', OrderAddress::ship_to_type( $order ) );

		$order->set_shipping_address_1( '9 Side St' );
		$this->assertNull( OrderAddress::ship_to( $order ), 'needs shipping, no shipping country: not replaced by billing' );

		$order->clear_test_items( 'shipping' );
		$this->assertNull( OrderAddress::ship_to( $order ), 'a partial shipping address is not replaced by billing' );
		$this->assertSame( 'shipping', OrderAddress::ship_to_type( $order ) );

		$order->set_shipping_address_1( '' );
		$this->assertSame( '62701', OrderAddress::ship_to( $order )['postalCode'], 'nothing to ship and no shipping address: billing' );
		$this->assertSame( 'billing', OrderAddress::ship_to_type( $order ) );

		foreach ( array( 'address_2', 'city', 'state', 'postcode', 'country' ) as $field ) {
			$partial = clone $order;
			$partial->{'set_shipping_' . $field}( 'x' );
			$this->assertSame( 'shipping', OrderAddress::ship_to_type( $partial ), "shipping {$field} alone is a shipping address" );
			$this->assertNull( OrderAddress::ship_to( $partial ), "shipping {$field} alone: incomplete, never billing" );
		}

		$order->set_shipping_city( "\u{00a0}" );
		$this->assertSame( 'shipping', OrderAddress::ship_to_type( $order ), 'a no-break space is not nothing: fail closed' );
		$this->assertNull( OrderAddress::ship_to( $order ) );
	}

	public function test_an_order_without_a_shipping_line_ships_to_its_shipping_address(): void {
		// Staff-created, REST, POS and imported orders often have no shipping
		// line (free shipping by omission), so WooCommerce says they need no
		// shipping address, yet they carry one, and that is where they ship.
		$order = $this->order_at(
			$this->us_address( array( 'address_1' => '9 Elm St', 'address_2' => '', 'city' => 'Chicago', 'postcode' => '60601' ) ),
			'flat_rate',
			$this->us_address()
		);
		$order->clear_test_items( 'shipping' );
		$this->assertFalse( $order->needs_shipping_address() );
		$this->assertSame( 'shipping', OrderAddress::ship_to_type( $order ) );
		$this->assertSame( '9 Elm St', OrderAddress::ship_to( $order )['line1'] );
		$this->assertSame( '60601', OrderAddress::ship_to( $order )['postalCode'] );
		$this->assertSame( '123 Main St', OrderAddress::billing( $order )['line1'], 'billing stays the residential address' );

		$order->set_test_address( 'shipping', $this->us_address( array( 'city' => 'Toronto', 'state' => 'ON', 'postcode' => 'M5V 2T6', 'country' => 'CA' ) ) );
		$this->assertSame( 'CA', OrderAddress::ship_to( $order )['country'], 'a non-US shipping address is not hidden behind a US billing one' );
		$this->assertFalse( OrderAddress::is_us( OrderAddress::ship_to( $order ) ) );

		$pickup = $this->order_at( $this->us_address( array( 'address_1' => '9 Elm St' ) ), 'local_pickup', $this->us_address() );
		$this->assertFalse( $pickup->needs_shipping_address(), 'pickup needs no shipping address' );
		$this->assertSame( '9 Elm St', OrderAddress::ship_to( $pickup )['line1'] );
	}

	public function test_territory_without_region_gets_its_country_code(): void {
		$address = OrderAddress::normalize(
			array(
				'address_1' => '1 Calle Sol',
				'city'      => 'San Juan',
				'state'     => '',
				'postcode'  => '00901',
				'country'   => 'PR',
			)
		);
		$this->assertSame( 'PR', $address['region'] );
		$this->assertArrayNotHasKey( 'line2', $address );
		$this->assertTrue( OrderAddress::is_us( $address ) );

		$us = OrderAddress::normalize(
			array(
				'address_1' => '1 Main',
				'city'      => 'X',
				'postcode'  => '10001',
				'country'   => 'US',
			)
		);
		$this->assertNull( $us, 'a US address without a state is incomplete (the engine requires a region)' );
		$this->assertNull( OrderAddress::normalize( array( 'address_1' => '1 Main', 'city' => 'X', 'state' => 'NY', 'postcode' => '1', 'country' => 'USA' ) ) );
	}

	public function test_normalize_is_the_engines_whitespace_and_refuses_what_the_engine_refuses(): void {
		$base = array(
			'address_1' => "1\u{00a0}Main\u{3000}St",
			'city'      => ' Springfield ',
			'state'     => 'IL',
			'postcode'  => ' 62701 1234 ',
			'country'   => 'us',
		);
		$this->assertSame(
			array(
				'line1'      => '1 Main St',
				'city'       => 'Springfield',
				'region'     => 'IL',
				'postalCode' => '62701',
				'country'    => 'US',
			),
			OrderAddress::normalize( $base ),
			'Go whitespace (NBSP, ideographic space) collapses; ZIP+4 with a space is the ZIP'
		);
		$this->assertNull( OrderAddress::normalize( array_merge( $base, array( 'address_1' => "1 Main \xff" ) ) ), 'not UTF-8' );
		$this->assertNull( OrderAddress::normalize( array_merge( $base, array( 'address_2' => str_repeat( 'é', 257 ) ) ) ), 'longer than the engine accepts' );
		$this->assertNotNull( OrderAddress::normalize( array_merge( $base, array( 'address_2' => str_repeat( 'é', 256 ) ) ) ), '256 code points is fine' );
		$this->assertNull( OrderAddress::normalize( array_merge( $base, array( 'city' => array( 'x' ) ) ) ), 'not a string' );
		$this->assertSame( "e\u{0301}" === \Normalizer::normalize( "e\u{0301}" ) ? "e\u{0301}" : "\u{00e9}", OrderAddress::normalize( array_merge( $base, array( 'address_2' => "e\u{0301}" ) ) )['line2'], 'NFC' );
	}

	/**
	 * @dataProvider countries
	 */
	public function test_us_only_with_territories( string $country, string $region, string $zip, bool $accepted ): void {
		$address = array(
			'line1'      => '1 Main',
			'city'       => 'X',
			'region'     => $region,
			'postalCode' => $zip,
			'country'    => $country,
		);
		$this->assertSame( $accepted, OrderAddress::is_us( $address ) );
	}

	public function countries(): array {
		return array(
			'US'                         => array( 'US', 'NY', '10001', true ),
			'Puerto Rico'                => array( 'PR', 'PR', '00901', true ),
			'Guam'                       => array( 'GU', '', '96910', true ),
			'Virgin Islands'             => array( 'VI', '', '00802', true ),
			'American Samoa'             => array( 'AS', '', '96799', true ),
			'Northern Mariana Islands'   => array( 'MP', '', '96950', true ),
			'Minor Outlying Islands'     => array( 'UM', '', '96898', true ),
			'APO'                        => array( 'US', 'AE', '09001', true ),
			'West Virginia'              => array( 'US', 'West Virginia', '25301', true ),
			'Canada'                     => array( 'CA', 'ON', 'K1A0B1', false ),
			'Micronesia as a state'      => array( 'US', 'FM', '96941', false ),
			'Marshall Islands by name'   => array( 'US', 'Republic of the Marshall Islands', '00000', false ),
			'Palau by ZIP'               => array( 'US', '', '96940', false ),
			'Micronesia ZIP+4'           => array( 'US', '', '96942 1234', false ),
			'Palau as a country'         => array( 'PW', '', '96940', false ),
			'Micronesia as a country'    => array( 'FM', '', '96941', false ),
		);
	}

	public function test_hash_is_sha256_of_the_engine_canonical_form(): void {
		$address = OrderAddress::normalize(
			array(
				'address_1' => 'ÉCOLE  Rd',
				'city'      => 'X',
				'state'     => 'NY',
				'postcode'  => '10001',
				'country'   => 'US',
			)
		);
		$this->assertSame( hash( 'sha256', \Navexa\AgeCheck\ShipTo::engine_canonical( array( 'line1' => 'école rd', 'line2' => '', 'postal_code' => '10001', 'country' => 'US' ) ) ), OrderAddress::hash( $address ), 'Unicode lower-casing, as the engine' );
	}

	public function test_hash_folds_like_the_engine(): void {
		$a = array(
			'line1'      => '1 Main  St',
			'postalCode' => '62701',
			'country'    => 'us',
			'city'       => 'A',
		);
		$b = array(
			'line1'      => ' 1 main st',
			'postalCode' => ' 627 01',
			'country'    => 'US',
			'city'       => 'B',
		);
		$this->assertSame( OrderAddress::hash( $a ), OrderAddress::hash( $b ), 'case, whitespace, city and region do not count' );
		$this->assertNotSame( OrderAddress::hash( $a ), OrderAddress::hash( array_merge( $a, array( 'line2' => 'Apt 1' ) ) ) );
	}
}
