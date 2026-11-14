<?php
/**
 * Meta helper tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Tests\TestCase;

final class MetaTest extends TestCase {

	public function test_typed_reads_and_deleting_writes(): void {
		$order                       = \WC_Order::make();
		$order->meta['_s']           = 'x';
		$order->meta['_n']           = '42';
		$order->meta['_a']           = array( 1 );
		$this->assertSame( 'x', Meta::get( $order, '_s' ) );
		$this->assertSame( '', Meta::get( $order, '_a' ), 'non-scalars read as empty' );
		$this->assertSame( 42, Meta::get_int( $order, '_n' ) );
		$this->assertSame( 0, Meta::get_int( $order, '_s' ) );
		Meta::set( $order, '_s', '' );
		$this->assertArrayNotHasKey( '_s', $order->meta );
		Meta::set( $order, '_t', 5 );
		$this->assertSame( 5, $order->meta['_t'] );
	}

	public function test_released_means_cleared_or_overridden_on_this_order(): void {
		$order = \WC_Order::make();
		foreach ( array( '' => false, Meta::STATUS_PENDING => false, Meta::STATUS_DENIED => false, Meta::STATUS_CLEARED => true, Meta::STATUS_OVERRIDE => true ) as $status => $released ) {
			Meta::mark_released( $order, (string) $status );
			$this->assertSame( $released, Meta::is_released( $order ), (string) $status );
		}
		$this->assertSame( '1:' . $order->get_id(), $order->meta[ Meta::RELEASED_FOR ] );

		// Duplicate-order plugins, imports and Subscriptions copy order meta.
		$copy       = \WC_Order::make();
		$copy->meta = $order->meta;
		$this->assertFalse( Meta::is_released( $copy ), 'a release copied from another order is not one' );
		unset( $copy->meta[ Meta::RELEASED_FOR ] );
		$this->assertFalse( Meta::is_released( $copy ), 'nor is a bare status (a REST client writing the meta key)' );

		$unsaved       = new \WC_Order();
		$unsaved->meta = $order->meta;
		$this->assertFalse( Meta::is_released( $unsaved ) );
	}

	public function test_notes_are_prefixed_in_test_mode(): void {
		$this->install_with_keys();
		$order = \WC_Order::make();
		$this->assertSame( 1, Meta::note( $order, 'AgeCheck: live.' ) );
		$this->options[ Mode::OPTION_LAST_MODE_PREFIX . Client::KEY_FULFILMENT ] = array(
			'key'      => Client::for_key( Client::KEY_FULFILMENT )->key_fingerprint(),
			'livemode' => false,
		);
		Meta::note( $order, 'AgeCheck: simulated.' );
		$this->assertSame( array( 'AgeCheck: live.', 'TEST AgeCheck: simulated.' ), $order->notes );
		$this->assertSame( 0, Meta::note( new \WC_Order(), 'no id' ) );
	}
}
