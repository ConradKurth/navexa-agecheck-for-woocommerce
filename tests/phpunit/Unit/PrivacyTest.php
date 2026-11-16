<?php
/**
 * WordPress privacy tools (W4 PR 16): policy text, exporter and eraser of
 * the plugin's order meta.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Navexa\AgeCheck\AddressGuard;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Privacy;
use Navexa\AgeCheck\Tests\TestCase;

final class PrivacyTest extends TestCase {

	const EMAIL = 'ada@example.com';

	/** @var array<int, array{0: string, 1: string}> wp_add_privacy_policy_content() calls. */
	private $policies = array();

	protected function setUp(): void {
		parent::setUp();
		$test = $this;
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_add_privacy_policy_content' )->alias(
			static function ( $name, $text ) use ( $test ) {
				$test->policies[] = array( $name, $text );
			}
		);
	}

	/** A completed order of EMAIL, released by AgeCheck. */
	private function cleared_order(): \WC_Order {
		$order = \WC_Order::make( 'completed' );
		Meta::set( $order, Meta::REQUIRED, 'yes' );
		Meta::set( $order, Meta::VERIFICATION_ID, 'acv_1' );
		Meta::set( $order, Meta::PREVIOUS_VERIFICATION_IDS, 'acv_0' );
		Meta::set( $order, Meta::VERIFICATION_STATUS, 'passed' );
		Meta::set( $order, Meta::SHIP_TO_HASH, str_repeat( 'a', 64 ) );
		Meta::set( $order, Meta::LINK, 'sent:acv_1' );
		Meta::set( $order, Meta::LINK_ID, 'acl_1' );
		Meta::set( $order, Meta::LINK_EXPIRES_AT, 1700600000 );
		Meta::set( $order, Meta::LINK_SENT_AT, 1700000000 );
		Meta::set( $order, Meta::LINK_KEY, 'vl:acv_1:0123456789abcdef' );
		Meta::set( $order, Meta::PASS_CLASS, 'REUSED' );
		Meta::set( $order, Meta::REASON, 'cleared' );
		Meta::set( $order, Meta::BOUND_AT, 1700000000 );
		Meta::set( $order, Meta::EXTERNAL_ID, 'wc:abc:1:' . $order->get_id() );
		Meta::mark_released( $order, Meta::STATUS_CLEARED );
		return $order;
	}

	private function allow_order_erasure(): void {
		$this->options['woocommerce_erasure_request_removes_order_data'] = 'yes';
	}

	public function test_registers_policy_exporter_and_eraser(): void {
		Privacy::register();
		$this->assertSame( 10, has_action( 'admin_init', array( Privacy::class, 'add_policy_content' ) ) );
		$this->assertSame( 5, has_filter( 'wp_privacy_personal_data_erasers', array( Privacy::class, 'register_eraser' ) ), 'before WooCommerce\'s erasers (10), which anonymize the email orders are found by' );
		$this->assertSame( 10, has_action( 'woocommerce_privacy_before_remove_order_personal_data', array( Privacy::class, 'before_woocommerce_erases' ) ) );
		$exporters = Privacy::register_exporter( array() );
		$erasers   = Privacy::register_eraser( array( 'woocommerce-customer-data' => array() ) );
		$this->assertSame( array( Privacy::class, 'export' ), $exporters[ Privacy::EXPORTER ]['callback'] );
		$this->assertSame( array( Privacy::class, 'erase' ), $erasers[ Privacy::ERASER ]['callback'] );
		$this->assertArrayHasKey( 'woocommerce-customer-data', $erasers, 'other erasers are kept' );
		$this->assertSame( 'x', Privacy::register_exporter( 'x' ) );
	}

	public function test_policy_text_says_what_is_sent_and_kept(): void {
		Privacy::add_policy_content();
		$this->assertCount( 1, $this->policies );
		$text = $this->policies[0][1];
		$this->assertSame( 'Navexa AgeCheck for WooCommerce', $this->policies[0][0] );
		foreach ( array( 'verify.navexa.co', 'billing name', 'date of birth', 'Didit', 'https://navexa.co/privacy', 'keyed hashes', 'email you a link to verify your age', 'keyed reference to your account' ) as $needle ) {
			$this->assertStringContainsString( $needle, $text );
		}
		$this->assertStringNotContainsString( 'challenges.cloudflare.com', $text, 'Cloudflare only when Turnstile is on' );

		$this->options[ Options::TURNSTILE ] = 'yes';
		$this->assertStringContainsString( 'challenges.cloudflare.com', Privacy::policy_text() );
	}

	public function test_export_lists_the_age_check_meta_of_the_requesters_orders(): void {
		$order = $this->cleared_order();
		\WC_Order::make( 'completed' ); // Same email, no AgeCheck meta: not exported.
		$other = $this->cleared_order();
		$other->set_test_address( 'billing', array( 'email' => 'someone@example.com' ) );

		$export = Privacy::export( self::EMAIL, 1 );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'navexa-agecheck-order-' . $order->get_id(), $export['data'][0]['item_id'] );
		$values = array_column( $export['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'acv_1', $values['AgeCheck verification'] );
		$this->assertSame( 'acv_0', $values['Earlier AgeCheck verifications'] );
		$this->assertSame( Meta::STATUS_CLEARED, $values['Age check result'] );
		$this->assertSame( 'REUSED', $values['How the age check passed'] );
		$this->assertSame( '2023-11-14 22:13:20 UTC', $values['Verification link emailed'] );
		$this->assertSame( '2023-11-14 22:13:20 UTC', $values['Linked to the order'] );
		$this->assertArrayNotHasKey( 'Cleared', $values );
	}

	public function test_export_pages_ten_orders(): void {
		for ( $i = 0; $i < 12; $i++ ) {
			$this->cleared_order();
		}
		$first = Privacy::export( self::EMAIL, 1 );
		$this->assertFalse( $first['done'] );
		$this->assertCount( 10, $first['data'] );
		$second = Privacy::export( self::EMAIL, 2 );
		$this->assertTrue( $second['done'] );
		$this->assertCount( 2, $second['data'] );
	}

	public function test_export_finds_orders_of_the_account_with_that_email(): void {
		Functions\when( 'get_user_by' )->justReturn( new \WP_User( 9 ) );
		$order                      = $this->cleared_order();
		$order->data['customer_id'] = 9;
		$order->set_test_address( 'billing', array( 'email' => 'old@example.com' ) );
		$this->assertCount( 1, Privacy::export( self::EMAIL, 1 )['data'] );
		$this->assertSame( array(), Privacy::export( '', 1 )['data'] );
	}

	public function test_eraser_keeps_meta_while_woocommerce_keeps_order_data(): void {
		$order = $this->cleared_order();

		$result = Privacy::erase( self::EMAIL, 1 );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertStringContainsString( 'Accounts & Privacy', $result['messages'][0] );
		$this->assertStringContainsString( 'privacy@navexa.co', end( $result['messages'] ) );
		$this->assertSame( 'acv_1', Meta::get( $order, Meta::VERIFICATION_ID ) );
	}

	public function test_eraser_removes_identifying_meta_and_keeps_the_release_state(): void {
		$this->allow_order_erasure();
		$order = $this->cleared_order();

		$result = Privacy::erase( self::EMAIL, 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		foreach ( Privacy::ERASABLE_META as $key ) {
			$this->assertSame( '', Meta::get( $order, $key ), $key );
		}
		foreach ( array( Meta::LINK, Meta::LINK_ID, Meta::LINK_EXPIRES_AT, Meta::LINK_SENT_AT, Meta::LINK_KEY ) as $key ) {
			$this->assertContains( $key, Privacy::ERASABLE_META, 'the link ties the order to the shopper\'s AgeCheck record' );
			$this->assertSame( '', Meta::get( $order, $key ), $key );
		}
		$this->assertArrayNotHasKey( Meta::LINK_KEY, $order->meta, 'the link key names the verification (vl:{vid}:...)' );
		$this->assertSame( 'REUSED', Meta::get( $order, Meta::PASS_CLASS ), 'how it passed is release state, kept' );
		$this->assertTrue( Meta::is_released( $order ), 'an erased order is never held again' );
		$this->assertSame( 'yes', Meta::get( $order, Meta::REQUIRED ) );
		$this->assertSame( 1, $order->meta_saves );
		$this->assertStringContainsString( 'erased on a personal data erasure request', end( $order->notes ) );
		$this->assertStringNotContainsString( 'acv_1', implode( ' ', $order->notes ) );

		$again = Privacy::erase( self::EMAIL, 1 );
		$this->assertFalse( $again['items_removed'], 'nothing left to erase' );
		$this->assertSame( array(), $again['messages'] );
	}

	public function test_eraser_never_touches_an_order_the_plugin_still_acts_on(): void {
		$this->allow_order_erasure();
		$held = $this->cleared_order();
		$held->set_status( Hold::STATUS );
		$held->save();
		$refund = $this->cleared_order();
		Meta::set( $refund, Meta::REFUND, Meta::REFUND_MANUAL );
		$flagged = $this->cleared_order();
		Meta::set( $flagged, Meta::HELD, 'yes' );

		$result = Privacy::erase( self::EMAIL, 1 );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		foreach ( array( $held, $refund, $flagged ) as $order ) {
			$this->assertSame( 'acv_1', Meta::get( $order, Meta::VERIFICATION_ID ) );
		}
		$this->assertStringContainsString( 'still waiting', $result['messages'][0] );
	}

	public function test_eraser_keeps_an_order_that_can_still_ship(): void {
		$this->allow_order_erasure();
		$orders = array();
		foreach ( array( 'processing', 'on-hold', 'pending', 'shipped' ) as $status ) {
			$order = $this->cleared_order();
			$order->set_status( $status );
			$order->save();
			$orders[ $status ] = $order;
		}

		$result = Privacy::erase( self::EMAIL, 1 );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		foreach ( $orders as $status => $order ) {
			$this->assertSame( 'acv_1', Meta::get( $order, Meta::VERIFICATION_ID ), $status . ': the verification locks the ship-to' );
		}
		$this->assertStringContainsString( 'can still ship', $result['messages'][0] );
		foreach ( array( 'cancelled', 'refunded', 'failed' ) as $status ) {
			$closed = $this->cleared_order();
			$closed->set_status( $status );
			$closed->save();
			Privacy::erase( self::EMAIL, 1 );
			$this->assertSame( '', Meta::get( $closed, Meta::VERIFICATION_ID ), $status . ': erased' );
		}
	}

	public function test_eraser_waits_for_a_pending_link_withdrawal_then_takes_its_state(): void {
		$this->allow_order_erasure();
		$order = $this->cleared_order();
		$order->set_status( 'refunded' );
		$order->save();
		Meta::set( $order, Meta::REVOKE, 'pending:acv_1' );
		Meta::set( $order, Meta::REVOKE_KEY, 'rvk:acv_1:0123456789abcdef' );

		$result = Privacy::erase( self::EMAIL, 1 );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertSame( 'acv_1', Meta::get( $order, Meta::VERIFICATION_ID ), 'the withdrawal still needs the verification id' );
		$this->assertStringContainsString( 'still withdrawing its emailed verification link', $result['messages'][0] );

		Meta::set( $order, Meta::REVOKE, 'revoked:acv_1' );
		Meta::set( $order, Meta::REVOKE_KEY, '' );
		$this->assertTrue( Privacy::erase( self::EMAIL, 1 )['items_removed'] );
		foreach ( array( Meta::REVOKE, Meta::REVOKE_KEY ) as $key ) {
			$this->assertContains( $key, Privacy::ERASABLE_META, 'it names the verification' );
			$this->assertSame( '', Meta::get( $order, $key ), $key );
		}
	}

	public function test_woocommerce_own_erasure_erases_closed_orders_first(): void {
		$closed = $this->cleared_order();
		$open   = $this->cleared_order();
		$open->set_status( 'processing' );
		$open->save();
		$held = $this->cleared_order();
		Meta::set( $held, Meta::HELD, 'yes' );

		// WooCommerce applied its own rule already (eraser, bulk action, retention cleanup).
		foreach ( array( $closed, $open, $held ) as $order ) {
			Privacy::before_woocommerce_erases( $order );
		}
		Privacy::before_woocommerce_erases( 'not an order' );

		$this->assertSame( '', Meta::get( $closed, Meta::VERIFICATION_ID ) );
		$this->assertTrue( Meta::is_released( $closed ) );
		$this->assertSame( 'acv_1', Meta::get( $open, Meta::VERIFICATION_ID ), 'can still ship' );
		$this->assertSame( 'acv_1', Meta::get( $held, Meta::VERIFICATION_ID ), 'still acted on' );
	}

	public function test_an_erased_open_order_stays_locked_to_its_ship_to(): void {
		$this->install_with_keys();
		$this->wire_order_hooks();
		$this->allow_order_erasure();
		$order = $this->cleared_order();
		$order->set_test_address( 'shipping', array( 'address_1' => '1 Main St', 'city' => 'Springfield', 'state' => 'IL', 'postcode' => '62701', 'country' => 'US' ) );
		$order->set_status( 'processing' );
		$order->save();

		// The real sequence: this eraser first, then WooCommerce's order eraser.
		Privacy::erase( self::EMAIL, 1 );
		AddressGuard::erasure_starts( $order );
		Privacy::before_woocommerce_erases( $order );
		$order->set_shipping_address_1( '[deleted]' );
		$order->set_shipping_city( '[deleted]' );
		$order->set_shipping_postcode( '[deleted]' );
		$order->save();
		AddressGuard::erasure_ends( $order );
		$this->assertSame( '[deleted]', $order->get_shipping_address_1(), 'WooCommerce\'s anonymization is not undone' );

		// "Ship it to this address instead": refused, the order is still locked.
		$order->set_shipping_address_1( '99 Elsewhere Rd' );
		$order->set_shipping_postcode( '10001' );
		$order->save();
		$this->assertSame( '[deleted]', $order->get_shipping_address_1() );
		$this->assertSame( 'acv_1', Meta::get( $order, Meta::VERIFICATION_ID ) );
	}

	public function test_woocommerce_per_order_filter_is_honoured(): void {
		$this->allow_order_erasure();
		$order = $this->cleared_order();
		Filters\expectApplied( 'woocommerce_privacy_erase_order_personal_data' )->once()->with( true, $order )->andReturn( false );
		$this->assertTrue( Privacy::erase( self::EMAIL, 1 )['items_retained'] );
		$this->assertSame( 'acv_1', Meta::get( $order, Meta::VERIFICATION_ID ) );
	}
}
