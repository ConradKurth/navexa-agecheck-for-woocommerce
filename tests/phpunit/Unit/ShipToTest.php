<?php
/**
 * ShipTo tests: the engine's ShipToCanonical, pinned by vectors exported
 * from the engine, plus the plugin's own normalization.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\ShipTo;
use Navexa\AgeCheck\Tests\TestCase;

final class ShipToTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Install::ensure();
	}

	/**
	 * @return array<string, array{array<string, string>, string}>
	 */
	public function engine_vectors(): array {
		$doc = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/ship-to-vectors.json' ), true );
		$out = array();
		foreach ( $doc['vectors'] as $vector ) {
			$out[ $vector['name'] ] = array( $vector['address'], $vector['canonical_hex'] );
		}
		return $out;
	}

	/**
	 * @dataProvider engine_vectors
	 */
	public function test_engine_canonical_matches_the_engine_byte_for_byte( array $address, string $canonical_hex ): void {
		$this->assertSame( $canonical_hex, bin2hex( ShipTo::engine_canonical( $address ) ) );
	}

	public function test_vectors_cover_the_tricky_cases(): void {
		$this->assertGreaterThanOrEqual( 15, count( $this->engine_vectors() ) );
	}

	public function test_normalizes_and_sends_the_normalized_address(): void {
		$ship_to = ShipTo::from_fields(
			array(
				'line1'       => "  123   Main\tSt ",
				'line2'       => '',
				'city'        => ' Springfield ',
				'region'      => 'IL',
				'postal_code' => ' 62701-1234 ',
				'country'     => 'us',
			)
		);
		$this->assertNotNull( $ship_to );
		$this->assertSame(
			array(
				'line1'      => '123 Main St',
				'city'       => 'Springfield',
				'region'     => 'IL',
				'postalCode' => '62701',
				'country'    => 'US',
			),
			$ship_to->engine_address(),
			'ZIP+4 is cut to the ZIP; an empty line2 is left out'
		);
		$this->assertSame( ShipTo::engine_canonical( array( 'line1' => '123 main st', 'line2' => '', 'postal_code' => '62701', 'country' => 'US' ) ), $ship_to->canonical() );
	}

	public function test_the_engine_reads_the_sent_address_as_the_plugin_does(): void {
		foreach ( $this->engine_vectors() as $name => $vector ) {
			$ship_to = ShipTo::from_fields( $vector[0] );
			if ( null === $ship_to ) {
				continue;
			}
			$sent = $ship_to->engine_address();
			$this->assertSame(
				ShipTo::engine_canonical(
					array(
						'line1'       => $sent['line1'],
						'line2'       => isset( $sent['line2'] ) ? $sent['line2'] : '',
						'postal_code' => $sent['postalCode'],
						'country'     => $sent['country'],
					)
				),
				$ship_to->canonical(),
				$name
			);
		}
	}

	public function test_zip_plus_four_variants_and_case_give_one_mac(): void {
		$macs = array();
		foreach ( array( '62701', '62701-1234', '627011234', ' 62701 1234 ' ) as $postal ) {
			$macs[] = ShipTo::from_wc_address( $this->us_address( array( 'postcode' => $postal ) ) )->mac();
		}
		$macs[] = ShipTo::from_wc_address( $this->us_address( array( 'address_1' => '123 MAIN  ST', 'address_2' => 'apt 4' ) ) )->mac();
		$macs[] = ShipTo::from_wc_address( $this->us_address( array( 'city' => 'Elsewhere', 'state' => 'CA' ) ) )->mac();
		$this->assertCount( 1, array_unique( $macs ), 'city and region are not compared' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $macs[0] );

		$other = ShipTo::from_wc_address( $this->us_address( array( 'address_2' => 'Apt 5' ) ) );
		$this->assertNotSame( $macs[0], $other->mac() );
		$this->assertFalse( $other->equals( ShipTo::from_wc_address( $this->us_address() ) ) );
	}

	public function test_non_us_postal_codes_are_kept_whole(): void {
		$ship_to = ShipTo::from_wc_address( $this->us_address( array( 'postcode' => 'sw1a 1aa', 'country' => 'GB' ) ) );
		$this->assertSame( 'SW1A1AA', $ship_to->engine_address()['postalCode'] );
		$this->assertFalse( $ship_to->is_accepted() );
	}

	public function test_decomposed_unicode_is_composed(): void {
		$composed   = ShipTo::from_wc_address( $this->us_address( array( 'address_1' => "Caf\u{00e9} Rd" ) ) );
		$decomposed = ShipTo::from_wc_address( $this->us_address( array( 'address_1' => "Cafe\u{0301} Rd" ) ) );
		$this->assertSame( $composed->mac(), $decomposed->mac() );
		$this->assertSame( "Caf\u{00e9} Rd", $decomposed->engine_address()['line1'] );
	}

	public function test_accepted_countries_are_the_us_and_its_territories(): void {
		foreach ( array( 'US', 'PR', 'GU', 'VI', 'AS', 'MP', 'UM', 'us', ' pr ' ) as $country ) {
			$this->assertTrue( ShipTo::is_accepted_country( $country ), $country );
		}
		foreach ( array( 'FM', 'MH', 'PW', 'CA', 'MX', 'GB', '', 'USA' ) as $country ) {
			$this->assertFalse( ShipTo::is_accepted_country( $country ), $country );
		}
	}

	public function test_freely_associated_states_are_refused_even_as_us_addresses(): void {
		$this->assertFalse( ShipTo::from_wc_address( $this->us_address( array( 'state' => 'FM' ) ) )->is_accepted() );
		$this->assertFalse( ShipTo::from_wc_address( $this->us_address( array( 'state' => 'pw' ) ) )->is_accepted() );
		$this->assertFalse( ShipTo::from_wc_address( $this->us_address( array( 'state' => 'HI', 'postcode' => '96960-1234' ) ) )->is_accepted(), 'Marshall Islands ZIP' );
		$this->assertFalse( ShipTo::from_wc_address( $this->us_address( array( 'country' => 'FM', 'state' => 'Pohnpei', 'postcode' => '96941' ) ) )->is_accepted() );
		$this->assertTrue( ShipTo::from_wc_address( $this->us_address( array( 'state' => 'AE', 'postcode' => '09499' ) ) )->is_accepted(), 'APO/FPO' );
		$this->assertTrue( ShipTo::from_wc_address( $this->us_address( array( 'state' => '', 'postcode' => '96910', 'country' => 'GU' ) ) )->is_accepted() );
	}

	public function test_territories_without_a_state_get_their_own_code_as_region(): void {
		$ship_to = ShipTo::from_wc_address( $this->us_address( array( 'state' => '', 'postcode' => '00901-1234', 'country' => 'PR' ) ) );
		$this->assertNotNull( $ship_to );
		$this->assertSame( 'PR', $ship_to->engine_address()['region'] );
		$this->assertSame( '00901', $ship_to->engine_address()['postalCode'] );
		$this->assertTrue( $ship_to->is_accepted() );
		$this->assertNull( ShipTo::from_wc_address( $this->us_address( array( 'state' => '' ) ) ), 'a US address still needs its state' );
	}

	public function test_incomplete_or_invalid_addresses_are_refused(): void {
		foreach ( array( 'address_1', 'city', 'postcode', 'country' ) as $field ) {
			$this->assertNull( ShipTo::from_wc_address( $this->us_address( array( $field => '  ' ) ) ), $field );
		}
		$this->assertNull( ShipTo::from_wc_address( $this->us_address( array( 'country' => 'USA' ) ) ) );
		$this->assertNull( ShipTo::from_wc_address( $this->us_address( array( 'address_1' => "bad \xff utf8" ) ) ) );
		$this->assertNull( ShipTo::from_wc_address( $this->us_address( array( 'address_1' => str_repeat( 'é', 257 ) ) ) ) );
		$this->assertNotNull( ShipTo::from_wc_address( $this->us_address( array( 'address_1' => str_repeat( 'é', 256 ) ) ) ) );
		$this->assertNull( ShipTo::from_fields( array( 'line1' => array( 'x' ) ) ) );
		$this->assertNotNull( ShipTo::from_wc_address( $this->us_address( array( 'address_2' => '' ) ) ), 'line2 is optional' );
	}

	public function test_checkout_data_uses_shipping_else_billing(): void {
		$data = array(
			'billing_address_1'  => '1 Billing Rd',
			'billing_city'       => 'B',
			'billing_state'      => 'IL',
			'billing_postcode'   => '60601',
			'billing_country'    => 'US',
			'shipping_address_1' => '2 Shipping Rd',
			'shipping_city'      => 'S',
			'shipping_state'     => 'NY',
			'shipping_postcode'  => '10001',
			'shipping_country'   => 'US',
		);
		$this->assertSame( '2 Shipping Rd', ShipTo::from_checkout_data( $data )->engine_address()['line1'] );
		$data['shipping_country'] = 'CA';
		$this->assertSame( 'CA', ShipTo::country_from_checkout_data( $data ) );

		unset( $data['shipping_country'] );
		$this->assertSame( '1 Billing Rd', ShipTo::from_checkout_data( $data )->engine_address()['line1'], 'no shipping country posted: billing' );
		$this->assertSame( 'US', ShipTo::country_from_checkout_data( $data ) );
	}

	public function test_order_uses_shipping_when_it_ships_else_billing(): void {
		$order           = new \WC_Order();
		$order->billing  = $this->us_address( array( 'address_1' => '1 Billing Rd' ) );
		$order->shipping = $this->us_address( array( 'address_1' => '2 Shipping Rd' ) );

		$order->shipping_methods = array( new \WC_Order_Item_Shipping( 'flat_rate' ) );
		$this->assertSame( '2 Shipping Rd', ShipTo::from_order( $order )->engine_address()['line1'] );
		$this->assertSame( 'US', ShipTo::country_of_order( $order ) );

		$order->shipping_methods = array();
		$this->assertSame( '1 Billing Rd', ShipTo::from_order( $order )->engine_address()['line1'], 'no shipping line: billing' );
	}

	public function test_customer_uses_shipping_when_the_cart_ships_else_billing(): void {
		$customer           = new \WC_Customer();
		$customer->billing  = $this->us_address( array( 'address_1' => '1 Billing Rd' ) );
		$customer->shipping = $this->us_address( array( 'address_1' => '2 Shipping Rd' ) );
		$this->assertSame( '2 Shipping Rd', ShipTo::from_customer( $customer, true )->engine_address()['line1'] );
		$this->assertSame( '1 Billing Rd', ShipTo::from_customer( $customer, false )->engine_address()['line1'] );
		$customer->shipping = array();
		$this->assertSame( '1 Billing Rd', ShipTo::from_customer( $customer, true )->engine_address()['line1'], 'no shipping country: billing' );
		$this->assertSame( 'US', ShipTo::country_of_customer( $customer, true ) );
	}

	public function test_mac_is_keyed_by_the_install_and_fails_closed_without_it(): void {
		$ship_to = ShipTo::from_wc_address( $this->us_address() );
		$before  = $ship_to->mac();
		$this->home = 'https://moved.example';
		Install::confirm_site();
		$this->assertNotSame( $before, $ship_to->mac(), 'a confirmed copy has its own key' );

		unset( $this->options[ Install::OPTION_GUARD_SECRET ] );
		$this->assertSame( '', $ship_to->mac() );
	}

	public function test_fold(): void {
		$this->assertSame( 'a b c', ShipTo::fold( " A \u{00a0}B\u{3000}\tC " ) );
		$this->assertSame( "a\u{200b}b", ShipTo::fold( "A\u{200b}B" ), 'a zero-width space is not whitespace' );
	}
}
