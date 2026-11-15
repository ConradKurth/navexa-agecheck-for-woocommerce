<?php
/**
 * Denial tests: auto-refund through the gateway, else the manual list.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Admin\Notices;
use Navexa\AgeCheck\Denial;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Tests\FakeGateway;
use Navexa\AgeCheck\Tests\TestCase;

final class DenialTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->gateway = new FakeGateway();
	}

	public function test_register(): void {
		Denial::register();
		$this->assertNotFalse( has_action( 'woocommerce_order_status_refunded', array( Denial::class, 'resolved' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_status_cancelled', array( Denial::class, 'resolved' ) ) );
	}

	public function test_live_denial_refunds_through_the_gateway_and_restocks(): void {
		$order = $this->held();
		$order->add_test_item( new \WC_Order_Item_Product( new \WC_Product( 8 ), 2, 30.0 ) );
		$this->assertSame( Meta::REFUND_REFUNDED, Denial::deny( $order, 'not_verified', true ) );
		$this->assertCount( 1, $this->refunds );
		$refund = $this->refunds[0];
		$this->assertSame( '25.00', $refund['amount'] );
		$this->assertSame( $order->get_id(), $refund['order_id'] );
		$this->assertTrue( $refund['refund_payment'] );
		$this->assertTrue( $refund['restock_items'] );
		$quantities = array_column( $refund['line_items'], 'qty' );
		$this->assertSame( array( 1, 2, 0 ), $quantities, 'products restock their quantity; shipping has none' );
		$this->assertSame( 20.0, $refund['line_items'][ array_key_first( $refund['line_items'] ) ]['refund_total'] );
		$this->assertSame( Meta::STATUS_DENIED, $order->meta[ Meta::STATUS ] );
		$this->assertSame( 'failed', $order->meta[ Meta::VERIFICATION_STATUS ] );
		$this->assertStringContainsString( 'did not pass the age check', implode( "\n", $order->notes ) );
		$this->assertStringContainsString( 'refunded through the payment gateway', end( $order->notes ) );
		$this->assertStringContainsString( 'agecheck auto refunded order=' . $order->get_id(), $this->all_logs() );
	}

	public function test_denial_is_idempotent(): void {
		$order = $this->held();
		Denial::deny( $order, 'not_verified', true );
		$this->assertSame( Meta::REFUND_REFUNDED, Denial::deny( $order, 'not_verified', true ) );
		$this->assertCount( 1, $this->refunds );
	}

	public function test_manual_list_when_the_gateway_cannot_refund(): void {
		$this->gateway->refunds = false;
		$order                  = $this->held();
		$this->transients[ Notices::COUNTS_TRANSIENT ] = array( 'value' => array( 'denied' => 0, 'stale' => 0 ), 'ttl' => 600 );
		$this->assertSame( Meta::REFUND_MANUAL, Denial::deny( $order, 'not_verified', true ) );
		$this->assertSame( array(), $this->refunds );
		$this->assertStringContainsString( 'AgeCheck: denied, refund required', end( $order->notes ) );
		$this->assertArrayNotHasKey( Notices::COUNTS_TRANSIENT, $this->transients, 'the notice count is refreshed' );

		$this->gateway = null;
		$this->assertSame( Meta::REFUND_MANUAL, Denial::deny( $this->held(), 'not_verified', true ), 'no gateway' );
	}

	public function test_manual_when_set_or_test_mode_or_nothing_to_refund(): void {
		$this->options[ Options::DENIED_ORDERS ] = Options::DENIED_MANUAL;
		$this->assertSame( Meta::REFUND_MANUAL, Denial::deny( $this->held(), 'not_verified', true ) );
		unset( $this->options[ Options::DENIED_ORDERS ] );

		$this->assertSame( Meta::REFUND_MANUAL, Denial::deny( $this->held(), 'not_verified', false ), 'a test key never moves money' );

		$free                   = $this->held();
		$free->remaining_refund = 0.0;
		$this->assertSame( Meta::REFUND_MANUAL, Denial::deny( $free, 'not_verified', true ) );
		$this->assertSame( array(), $this->refunds );
	}

	public function test_a_failed_gateway_refund_falls_back_to_the_list(): void {
		$this->refund_result = new \WP_Error( 'error', 'Card network timeout for ada@example.com' );
		$order               = $this->held();
		$this->assertSame( Meta::REFUND_MANUAL, Denial::deny( $order, 'not_verified', true ) );
		$notes = implode( "\n", $order->notes );
		$this->assertStringContainsString( 'automatic refund through the payment gateway failed', $notes );
		$this->assertStringNotContainsString( 'ada@example.com', $notes . $this->all_logs(), 'the gateway message is not copied' );
	}

	public function test_gateway_that_cannot_refund_this_order(): void {
		$this->gateway = new class() extends FakeGateway {
			public function can_refund_order( $order ) {
				return false;
			}
		};
		$this->assertFalse( Denial::gateway_refunds( $this->held() ) );
	}

	public function test_not_us_has_its_own_note_and_released_orders_are_never_denied(): void {
		$order = $this->held();
		Denial::deny( $order, 'not_us', true );
		$this->assertStringContainsString( 'ships outside the US', implode( "\n", $order->notes ) );
		$this->assertArrayNotHasKey( Meta::VERIFICATION_STATUS, $order->meta );

		$cleared                       = $this->held();
		Meta::mark_released( $cleared, Meta::STATUS_CLEARED );
		$this->assertSame( '', Denial::deny( $cleared, 'not_verified', true ) );
		$this->assertSame( Meta::STATUS_CLEARED, $cleared->meta[ Meta::STATUS ] );
	}

	public function test_refunding_or_cancelling_takes_it_off_the_list(): void {
		$order                       = $this->held();
		$order->meta[ Meta::REFUND ] = Meta::REFUND_MANUAL;
		Denial::resolved( $order->get_id() );
		$this->assertSame( Meta::REFUND_RESOLVED, $order->meta[ Meta::REFUND ] );

		$auto                       = $this->held();
		$auto->meta[ Meta::REFUND ] = Meta::REFUND_REFUNDED;
		Denial::resolved( $auto->get_id() );
		$this->assertSame( Meta::REFUND_REFUNDED, $auto->meta[ Meta::REFUND ] );
		Denial::resolved( 4040 );
	}

	private function held(): \WC_Order {
		$order                     = $this->restricted_order( Hold::STATUS );
		$order->meta[ Meta::HELD ] = 'yes';
		return $order;
	}
}
