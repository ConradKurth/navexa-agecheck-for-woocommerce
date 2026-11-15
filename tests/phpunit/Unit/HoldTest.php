<?php
/**
 * Hold tests: the status, the payment-complete filter, the backstop, release
 * and the logged override.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Admin\Notices;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\Tests\TestCase;

final class HoldTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->wire_order_hooks();
	}

	public function test_register_wires_the_hooks(): void {
		Hold::register();
		$this->assertNotFalse( has_action( 'init', array( Hold::class, 'register_status' ) ) );
		$this->assertNotFalse( has_filter( 'wc_order_statuses', array( Hold::class, 'add_status' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( Hold::class, 'valid_for_payment_complete' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_pre_payment_complete', array( Hold::class, 'payment_starting' ) ) );
		$this->assertSame( 999, has_filter( 'woocommerce_payment_complete_order_status', array( Hold::class, 'payment_complete_status' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_payment_complete', array( Hold::class, 'payment_finished' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_before_order_object_save', array( Hold::class, 'backstop' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_status_agecheck-hold', array( Hold::class, 'entered' ) ) );
	}

	public function test_status_registration_and_list_position(): void {
		Functions\expect( 'register_post_status' )->once()->with( 'wc-agecheck-hold', \Mockery::on( static function ( $args ) {
			return 'Awaiting age check' === $args['label'] && true === $args['show_in_admin_status_list'] && false === $args['public'];
		} ) );
		Hold::register_status();

		$this->assertSame(
			array( 'wc-pending', 'wc-agecheck-hold', 'wc-processing' ),
			array_keys( Hold::add_status( array( 'wc-pending' => 'Pending', 'wc-processing' => 'Processing' ) ) )
		);
		$this->assertSame( array( 'wc-agecheck-hold' ), array_keys( Hold::add_status( 'junk' ) ) );
		$this->assertSame( array( 'pending', 'on-hold', 'agecheck-hold' ), Hold::valid_for_payment_complete( array( 'pending', 'on-hold' ) ) );
	}

	public function test_payment_complete_holds_a_required_order(): void {
		$order = $this->restricted_order();
		Hold::payment_starting( $order->get_id() );
		$status = Hold::payment_complete_status( 'processing', $order->get_id(), $order );
		$this->assertSame( Hold::STATUS, $status );
		$this->assertSame( 'yes', $order->meta[ Meta::HELD ] );
		$this->assertSame( 'processing', $order->meta[ Meta::INTERCEPTED_STATUS ] );
		$this->assertSame( Meta::STATUS_PENDING, $order->meta[ Meta::STATUS ] );
		$this->assertMatchesRegularExpression( '/^wc:[0-9a-f]{32}:1:' . $order->get_id() . '$/', $order->meta[ Meta::EXTERNAL_ID ] );
		$this->assertGreaterThan( 0, $order->meta[ Meta::HELD_AT ] );

		// What payment_complete() does next.
		$order->set_status( $status );
		$order->save();
		$this->assertSame( Hold::STATUS, $order->get_status() );
		$this->assertSame( array( $order->get_id() ), $this->stock_reduced, 'entering the hold reduces stock' );
		$this->assertSame( array( $order->get_id() ), self::$wc->mailer->emails['WC_Email_New_Order']->triggered, 'the admin gets the new-order email' );
		$this->assertSame( array(), self::$wc->mailer->emails['WC_Email_Customer_Processing_Order']->triggered, 'no customer email yet' );
		$jobs = $this->scheduled_for( Release::HOOK );
		$this->assertCount( 1, $jobs );
		$this->assertSame( array( $order->get_id(), 0, Release::TRIGGER_HOLD ), $jobs[0]['args'] );
		$this->assertFalse( $jobs[0]['unique'], 'Action Scheduler 3.9 would match any order\'s run' );
		$this->assertSame( 'navexa-agecheck', $jobs[0]['group'] );
		Hold::payment_finished( $order->get_id() );
	}

	public function test_payment_complete_filter_is_inert_outside_payment_complete(): void {
		$order = $this->restricted_order();
		$this->assertSame( 'processing', Hold::payment_complete_status( 'processing', $order->get_id(), $order ), 'maybe_set_date_paid() must see the real status' );

		Hold::payment_starting( $order->get_id() );
		Hold::payment_finished( $order->get_id() );
		$this->assertSame( 'processing', Hold::payment_complete_status( 'processing', $order->get_id(), $order ) );
		$this->assertSame( array(), $order->meta );
	}

	public function test_payment_complete_passes_unrequired_released_and_odd_values(): void {
		$plain = \WC_Order::make();
		Hold::payment_starting( $plain->get_id() );
		$this->assertSame( 'processing', Hold::payment_complete_status( 'processing', $plain->get_id(), $plain ) );

		$cleared                       = $this->restricted_order();
		Meta::mark_released( $cleared, Meta::STATUS_CLEARED );
		Hold::payment_starting( $cleared->get_id() );
		$this->assertSame( 'completed', Hold::payment_complete_status( 'completed', $cleared->get_id(), $cleared ) );

		$order = $this->restricted_order();
		Hold::payment_starting( $order->get_id() );
		$this->assertSame( 123, Hold::payment_complete_status( 123, $order->get_id(), $order ) );
		$this->assertSame( Hold::STATUS, Hold::payment_complete_status( 'completed', $order->get_id() ), 'the order is loaded when WooCommerce passes only its id' );
		$this->assertSame( 'completed', $order->meta[ Meta::INTERCEPTED_STATUS ] );

		Hold::payment_starting( 4040 );
		$this->assertSame( 'processing', Hold::payment_complete_status( 'processing', 4040 ), 'an unknown order passes' );
	}

	public function test_backstop_holds_a_direct_move_to_processing(): void {
		$order = $this->restricted_order();
		$order->update_status( 'processing', '', true ); // COD, BACS confirmation, an admin edit.
		$this->assertSame( Hold::STATUS, $order->get_status() );
		$this->assertSame( 'processing', $order->meta[ Meta::INTERCEPTED_STATUS ] );
		$this->assertStringContainsString( 'held until the shopper\'s age check passes (it was moving to Processing)', implode( "\n", $order->notes ) );
		$this->assertCount( 1, $this->scheduled_for( Release::HOOK ) );
		$this->assertStringContainsString( 'trigger=status_change intercepted=processing', $this->all_logs() );
	}

	public function test_backstop_holds_custom_shipping_statuses_but_not_safe_ones(): void {
		$shipped = $this->restricted_order( 'on-hold' );
		$shipped->update_status( 'shipped' );
		$this->assertSame( Hold::STATUS, $shipped->get_status(), 'a 3PL "shipped" status is not known to be safe' );
		$this->assertSame( 'shipped', $shipped->meta[ Meta::INTERCEPTED_STATUS ] );

		foreach ( array( 'on-hold', 'cancelled', 'failed' ) as $safe ) {
			$order = $this->restricted_order();
			$order->update_status( $safe );
			$this->assertSame( $safe, $order->get_status() );
			$this->assertArrayNotHasKey( Meta::HELD, $order->meta );
		}
	}

	public function test_backstop_lets_unrequired_and_released_orders_through(): void {
		$plain = \WC_Order::make();
		$plain->update_status( 'processing' );
		$this->assertSame( 'processing', $plain->get_status() );

		$cleared                       = $this->restricted_order( 'on-hold' );
		Meta::mark_released( $cleared, Meta::STATUS_CLEARED );
		$cleared->update_status( 'processing' );
		$this->assertSame( 'processing', $cleared->get_status() );

		$refund       = $this->restricted_order();
		$refund->type = 'shop_order_refund';
		$refund->update_status( 'completed' );
		$this->assertSame( 'completed', $refund->get_status(), 'refunds and subscriptions are not orders the hold governs' );

		$unchanged = $this->restricted_order( 'processing' );
		$unchanged->set_billing_phone( '555' );
		$unchanged->save();
		$this->assertSame( 'processing', $unchanged->get_status(), 'saves without a status change are ignored' );
	}

	public function test_a_held_order_cannot_be_moved_to_processing_by_hand(): void {
		$this->is_admin     = true;
		$this->current_user = 3;
		$order              = $this->held_order();
		$order->update_status( 'processing', '', true );
		$this->assertSame( Hold::STATUS, $order->get_status() );
		$this->assertStringContainsString( 'stays "Awaiting age check" instead of moving to Processing', end( $order->notes ) );
		$this->assertCount( 0, $this->scheduled_for( Release::HOOK ), 'a refused exit is not a new entry' );
		$this->assertStringContainsString( 'refused status change order=' . $order->get_id() . ' to=processing', $this->all_logs() );
		$flash = $this->transients[ Notices::FLASH_PREFIX . '3' ]['value'];
		$this->assertStringContainsString( 'release without age check', $flash[0] );

		foreach ( array( 'completed', 'on-hold', 'pending', 'shipped' ) as $refused ) {
			$order->update_status( $refused );
			$this->assertSame( Hold::STATUS, $order->get_status(), $refused );
		}
	}

	public function test_a_held_order_may_be_cancelled_refunded_or_failed(): void {
		foreach ( array( 'cancelled', 'refunded', 'failed', 'trash' ) as $exit ) {
			$order = $this->held_order();
			$order->update_status( $exit );
			$this->assertSame( $exit, $order->get_status() );
		}
		$cleared                       = $this->held_order();
		Meta::mark_released( $cleared, Meta::STATUS_CLEARED );
		$cleared->update_status( 'processing' );
		$this->assertSame( 'processing', $cleared->get_status(), 'a cleared order whose release did not stick may be moved by hand' );
	}

	public function test_a_copy_of_a_cleared_order_is_held(): void {
		$source = $this->held_order();
		Meta::mark_released( $source, Meta::STATUS_CLEARED );
		$this->assertTrue( Hold::release( $source, 'AgeCheck: passed.' ) );

		// A duplicate-order plugin copies every meta key, then pays or moves the copy.
		$paid       = $this->restricted_order();
		$paid->meta = $source->meta;
		Hold::payment_starting( $paid->get_id() );
		$this->assertSame( Hold::STATUS, Hold::payment_complete_status( 'processing', $paid->get_id(), $paid ) );
		Hold::payment_finished( $paid->get_id() );
		$this->assertSame( Meta::STATUS_PENDING, $paid->meta[ Meta::STATUS ] );
		$this->assertSame( 'wc:' . \Navexa\AgeCheck\Install::install_id() . ':1:' . $paid->get_id(), $paid->meta[ Meta::EXTERNAL_ID ] );

		$moved       = $this->restricted_order( 'on-hold' );
		$moved->meta = $source->meta;
		$moved->update_status( 'processing' );
		$this->assertSame( Hold::STATUS, $moved->get_status() );

		// A REST client writing the status key itself, in the same save as the status.
		$forged = $this->restricted_order( 'on-hold' );
		\Navexa\AgeCheck\Binding::external_id( $forged );
		$forged->meta[ Meta::STATUS ] = Meta::STATUS_CLEARED;
		$forged->update_status( 'processing' );
		$this->assertSame( Hold::STATUS, $forged->get_status() );
		$this->assertSame( Meta::STATUS_PENDING, $forged->meta[ Meta::STATUS ] );

		$held_copy       = $this->held_order();
		$held_copy->meta = array_merge( $held_copy->meta, array_intersect_key( $source->meta, array_flip( array( Meta::STATUS, Meta::RELEASED_FOR ) ) ) );
		$held_copy->update_status( 'processing' );
		$this->assertSame( Hold::STATUS, $held_copy->get_status(), 'a copied release cannot open the hold' );
	}

	public function test_a_refused_exit_leaves_no_status_transition(): void {
		$order = $this->held_order();
		$order->update_status( 'processing', '', true );
		$this->assertSame( Hold::STATUS, $order->get_status() );
		$this->assertSame( array(), $order->fired, 'no woocommerce_order_status_changed for a status that did not change' );
		$this->assertFalse( $order->status_transition );
		$this->assertCount( 1, $order->notes, 'only the AgeCheck note' );

		// A real entry still fires its transition.
		$entry = $this->restricted_order();
		$entry->update_status( 'processing' );
		$this->assertCount( 1, $entry->fired );
		$this->assertSame( Hold::STATUS, $entry->fired[0]['to'] );
	}

	public function test_choosing_awaiting_age_check_by_hand_makes_it_ours(): void {
		$order = \WC_Order::make( 'processing' );
		$order->update_status( Hold::STATUS );
		$this->assertSame( 'yes', $order->meta[ Meta::HELD ] );
		$this->assertSame( 'yes', $order->meta[ Meta::REQUIRED ] );
		$this->assertArrayNotHasKey( Meta::INTERCEPTED_STATUS, $order->meta );
		$this->assertCount( 1, $this->scheduled_for( Release::HOOK ) );
	}

	public function test_a_new_order_created_as_processing_is_held_and_entered(): void {
		$order = new \WC_Order();
		$order->add_test_item( new \WC_Order_Item_Product( new \WC_Product( 7 ) ) );
		$order->set_status( 'processing' ); // REST create: a first status records no transition...
		$this->assertFalse( $order->status_transition );
		$order->save();
		$this->assertSame( Hold::STATUS, $order->get_status() );
		$this->assertGreaterThan( 0, $order->get_id() );
		// ...but the hold's own set_status() does (from the pending processing), so the entry work runs once.
		$this->assertCount( 1, $this->scheduled_for( Release::HOOK ) );
	}

	public function test_release_restores_the_intercepted_status_and_sends_the_processing_email(): void {
		$order = $this->held_order();
		$this->assertTrue( Hold::release( $order, 'AgeCheck: passed.' ) );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertArrayNotHasKey( Meta::HELD, $order->meta );
		$this->assertSame( array( $order->get_id() ), self::$wc->mailer->emails['WC_Email_Customer_Processing_Order']->triggered );
		$this->assertStringContainsString( 'AgeCheck: passed. [status agecheck-hold -> processing]', implode( "\n", $order->notes ) );
		$this->assertFalse( Hold::is_releasing( $order->get_id() ) );

		$digital                                     = $this->held_order();
		$digital->meta[ Meta::INTERCEPTED_STATUS ] = 'completed';
		$this->assertTrue( Hold::release( $digital, 'ok' ) );
		$this->assertSame( 'completed', $digital->get_status() );
		$this->assertCount( 1, self::$wc->mailer->emails['WC_Email_Customer_Processing_Order']->triggered, 'WooCommerce sends the completed email itself' );
	}

	public function test_release_falls_back_when_the_intercepted_status_is_unusable(): void {
		foreach ( array( '', 'pending', 'agecheck-hold', 'wc-bogus' ) as $bad ) {
			$order                                     = $this->held_order();
			$order->meta[ Meta::INTERCEPTED_STATUS ] = $bad;
			$order->needs_processing                   = '' !== $bad;
			$this->assertTrue( Hold::release( $order, 'ok' ) );
			$this->assertSame( '' === $bad ? 'completed' : 'processing', $order->get_status(), $bad );
		}
	}

	public function test_release_only_touches_orders_this_plugin_holds(): void {
		$not_ours = $this->held_order();
		unset( $not_ours->meta[ Meta::HELD ] );
		$this->assertFalse( Hold::release( $not_ours, 'x' ) );
		$this->assertSame( Hold::STATUS, $not_ours->get_status() );

		$this->assertFalse( Hold::release( $this->restricted_order( 'on-hold' ), 'x' ) );
		$this->assertFalse( Hold::release( new \WC_Order(), 'x' ) );
	}

	public function test_override_is_logged_with_who_and_why(): void {
		$this->users[3] = new \WP_User( 3, array( 'shop_manager' ), 'Sam Staff' );
		$order          = $this->held_order();
		$this->assertTrue( Hold::override( $order, 3, ' Checked ID at the counter ' ) );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( Meta::STATUS_OVERRIDE, $order->meta[ Meta::STATUS ] );
		$this->assertSame( 3, $order->meta[ Meta::OVERRIDE_BY ] );
		$this->assertGreaterThan( 0, $order->meta[ Meta::OVERRIDE_AT ] );
		$this->assertStringContainsString( 'released WITHOUT an age check by Sam Staff (user #3). Reason given: Checked ID at the counter', implode( "\n", $order->notes ) );
		$this->assertStringContainsString( 'agecheck override order=' . $order->get_id() . ' user=3', $this->all_logs() );
		$this->assertStringNotContainsString( 'counter', $this->all_logs(), 'the reason text stays on the order' );
	}

	public function test_override_needs_a_reason_a_user_and_a_held_order(): void {
		$order = $this->held_order();
		$this->assertFalse( Hold::override( $order, 3, '   ' ) );
		$this->assertFalse( Hold::override( $order, 0, 'reason' ) );
		$this->assertSame( Hold::STATUS, $order->get_status() );
		$this->assertArrayNotHasKey( Meta::OVERRIDE_BY, $order->meta );
		$this->assertFalse( Hold::override( $this->restricted_order( 'processing' ), 3, 'reason' ) );

		$foreign = $this->held_order();
		unset( $foreign->meta[ Meta::HELD ] );
		$this->assertFalse( Hold::override( $foreign, 3, 'reason' ), 'not released, but recorded' );
		$this->assertSame( Meta::STATUS_OVERRIDE, $foreign->meta[ Meta::STATUS ] );
	}

	/** An order this plugin holds, on its way to processing. */
	private function held_order(): \WC_Order {
		$order = $this->restricted_order( Hold::STATUS );
		$order->meta[ Meta::REQUIRED ]           = 'yes';
		$order->meta[ Meta::HELD ]               = 'yes';
		$order->meta[ Meta::INTERCEPTED_STATUS ] = 'processing';
		$order->meta[ Meta::STATUS ]             = Meta::STATUS_PENDING;
		return $order;
	}
}
