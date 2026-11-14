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

	public function test_incomplete_ship_to_is_null_and_a_shipless_order_uses_billing(): void {
		$order = \WC_Order::make();
		foreach ( array( 'address_1', 'city', 'state', 'postcode', 'country' ) as $field ) {
			$order->{'set_shipping_' . $field}( '' );
		}
		$this->assertNull( OrderAddress::ship_to( $order ), 'needs shipping, has no address' );

		$order->needs_shipping = false;
		$this->assertSame( '62701', OrderAddress::ship_to( $order )['postalCode'], 'nothing to ship: billing address' );

		$order->set_shipping_address_1( '9 Side St' );
		$this->assertNull( OrderAddress::ship_to( $order ), 'a partial shipping address is not replaced by billing' );
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
		$this->assertArrayNotHasKey( 'region', $us, 'a US address without a state stays without one' );
		$this->assertNull( OrderAddress::normalize( array( 'address_1' => '1 Main', 'city' => 'X', 'postcode' => '1', 'country' => 'USA' ) ) );
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
