<?php
/**
 * Shipment (ReportShipment) tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Binding;
use Navexa\AgeCheck\ConnectError;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Shipment;
use Navexa\AgeCheck\Tests\TestCase;

final class ShipmentTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
	}

	public function test_register(): void {
		Shipment::register();
		$this->assertNotFalse( has_action( 'woocommerce_order_status_completed', array( Shipment::class, 'on_completed' ) ) );
		$this->assertNotFalse( has_action( Shipment::HOOK, array( Shipment::class, 'run_job' ) ) );
	}

	public function test_completed_required_order_is_reported_once(): void {
		$order = $this->required_order();
		Shipment::on_completed( $order->get_id(), $order );
		$jobs = $this->scheduled_for( Shipment::HOOK );
		$this->assertSame( array( $order->get_id(), 0 ), $jobs[0]['args'] );
		$this->assertFalse( $jobs[0]['unique'] );

		$order->data['date_completed'] = new \DateTimeImmutable( '@1794600000' );
		$this->respond( 200, array() );
		$this->assertTrue( Shipment::report( $order->get_id() ) );
		$this->assertSame(
			array(
				'orderExternalId' => $order->meta[ Meta::EXTERNAL_ID ],
				'shippedAt'       => gmdate( 'Y-m-d\\TH:i:s\\Z', 1794600000 ),
			),
			$this->request_body( 0 )
		);
		$this->assertStringEndsWith( '/ReportShipment', $this->http_requests[0]['url'] );
		$this->assertGreaterThan( 0, $order->meta[ Meta::SHIPMENT_REPORTED ] );
		$this->assertFalse( Shipment::report( $order->get_id() ), 'never twice' );
		Shipment::on_completed( $order->get_id() );
		$this->assertCount( 1, $this->scheduled_for( Shipment::HOOK ) );
	}

	public function test_only_required_orders_with_an_external_id(): void {
		$plain = \WC_Order::make( 'completed' );
		Shipment::on_completed( $plain->get_id(), $plain );
		$pickup                        = \WC_Order::make( 'completed' );
		$pickup->meta[ Meta::REQUIRED ] = 'no';
		Binding::external_id( $pickup );
		Shipment::on_completed( $pickup->get_id() );
		$this->assertSame( array(), $this->scheduled );
		$this->assertFalse( Shipment::report( 4040 ) );
	}

	public function test_completed_orders_are_each_queued_and_a_copied_id_is_never_reported(): void {
		$first  = $this->required_order();
		$second = $this->required_order();
		Shipment::on_completed( $first->get_id(), $first );
		Shipment::on_completed( $second->get_id(), $second );
		Shipment::on_completed( $second->get_id(), $second );
		$this->assertSame(
			array( array( $first->get_id(), 0 ), array( $second->get_id(), 0 ) ),
			array_column( $this->scheduled_for( Shipment::HOOK ), 'args' ),
			'one pending report per order, whatever else is pending (Action Scheduler 3.9)'
		);

		$renewal       = \WC_Order::make( 'completed' );
		$renewal->meta = $first->meta; // The parent's external id.
		$this->assertFalse( Shipment::reportable( $renewal ) );
		$this->assertFalse( Shipment::report( $renewal->get_id() ) );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_retries_with_backoff_then_gives_up(): void {
		$order = $this->required_order();
		$this->respond( 503, array( 'code' => 'unavailable' ), array( 'Retry-After' => '30' ) );
		Shipment::run_job( $order->get_id(), 1 );
		$retry = $this->scheduled_for( Shipment::HOOK );
		$this->assertSame( array( $order->get_id(), 2 ), $retry[0]['args'] );
		$this->assertGreaterThanOrEqual( time() + 120, $retry[0]['when'] );

		$this->respond( 503, array( 'code' => 'unavailable' ) );
		Shipment::run_job( $order->get_id(), Shipment::MAX_ATTEMPTS );
		$this->respond( 404, array( 'code' => 'not_found' ) );
		Shipment::run_job( $order->get_id(), 0 );
		$this->assertCount( 1, $this->scheduled_for( Shipment::HOOK ) );
		$this->assertArrayNotHasKey( Meta::SHIPMENT_REPORTED, $order->meta );
	}

	public function test_report_rethrows(): void {
		$order = $this->required_order();
		$this->respond( 500, '' );
		$this->expectException( ConnectError::class );
		Shipment::report( $order->get_id() );
	}

	private function required_order(): \WC_Order {
		$order                         = \WC_Order::make( 'completed' );
		$order->meta[ Meta::REQUIRED ] = 'yes';
		Binding::external_id( $order );
		return $order;
	}
}
