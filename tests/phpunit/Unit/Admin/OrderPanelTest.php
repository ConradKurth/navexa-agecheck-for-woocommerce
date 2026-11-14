<?php
/**
 * Order panel and order action tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Admin\Notices;
use Navexa\AgeCheck\Admin\OrderPanel;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\LinkSender;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Requirement;
use Navexa\AgeCheck\Tests\TestCase;

final class OrderPanelTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->wire_order_hooks();
		$this->is_admin     = true;
		$this->current_user = 3;
		$this->users[3]     = new \WP_User( 3, array( 'shop_manager' ), 'Sam Staff' );
		$_POST              = array();
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) {
				return 'good' === $nonce && 'woocommerce_save_data' === $action ? 1 : false;
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	public function test_register_and_box(): void {
		OrderPanel::register();
		$this->assertNotFalse( has_action( 'add_meta_boxes', array( OrderPanel::class, 'add_box' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_order_actions', array( OrderPanel::class, 'order_actions' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_action_navexa_agecheck_check', array( OrderPanel::class, 'action_check' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_action_navexa_agecheck_override', array( OrderPanel::class, 'action_override' ) ) );

		Functions\when( 'wc_get_page_screen_id' )->justReturn( 'woocommerce_page_wc-orders' );
		Functions\expect( 'add_meta_box' )->once()->with( 'navexa-agecheck', 'AgeCheck', array( OrderPanel::class, 'render' ), 'woocommerce_page_wc-orders', 'side', 'high' );
		OrderPanel::add_box();
	}

	public function test_rows_for_a_held_order(): void {
		$order                                    = $this->held_order();
		$order->meta[ Meta::VERIFICATION_ID ]     = 'acv_PANEL';
		$order->meta[ Meta::VERIFICATION_STATUS ] = 'needs_doc';
		$order->meta[ Meta::REASON ]              = 'pending';
		$order->meta[ Meta::LINK ]                = LinkSender::RESULT_UNAVAILABLE . ':acv_PANEL';
		$order->meta[ Meta::HELD_AT ]             = 1794600000;
		$rows                                     = OrderPanel::rows( $order );
		$this->assertSame( 'Awaiting the shopper\'s age check.', $rows['Age check'] );
		$this->assertSame( 'Waiting for the shopper to verify their age.', $rows['Why'] );
		$this->assertSame( 'acv_PANEL (needs doc)', $rows['Verification'] );
		$this->assertStringContainsString( 'not available yet', $rows['Verification link'] );
		$this->assertArrayHasKey( 'Held since', $rows );
	}

	public function test_rows_for_denied_overridden_and_unrequired_orders(): void {
		$denied                       = $this->held_order();
		$denied->meta[ Meta::STATUS ] = Meta::STATUS_DENIED;
		$denied->meta[ Meta::REFUND ] = Meta::REFUND_MANUAL;
		$rows                         = OrderPanel::rows( $denied );
		$this->assertSame( 'Failed. Do not ship this order.', $rows['Age check'] );
		$this->assertSame( 'Refund or cancel this order yourself.', $rows['Refund'] );

		$override                            = $this->held_order();
		Meta::mark_released( $override, Meta::STATUS_OVERRIDE );
		$override->meta[ Meta::OVERRIDE_BY ] = 3;
		$override->meta[ Meta::OVERRIDE_AT ] = 1794600000;
		$override->meta[ Meta::REASON ]      = 'override';
		$rows                                = OrderPanel::rows( $override );
		$this->assertStringStartsWith( 'Sam Staff on ', $rows['Released by'] );
		$this->assertArrayNotHasKey( 'Why', $rows, 'a released order shows no hold reason' );

		$pickup                                = \WC_Order::make();
		$pickup->meta[ Meta::REQUIRED ]        = 'no';
		$pickup->meta[ Meta::REQUIRED_REASON ] = Requirement::REASON_PICKUP;
		$this->assertStringContainsString( 'local pickup', OrderPanel::rows( $pickup )['Age check'] );
		$this->assertSame( array( 'Age check' => 'Not evaluated yet.' ), OrderPanel::rows( \WC_Order::make() ) );
	}

	public function test_render_escapes_and_offers_the_override_fields_only_when_held(): void {
		$order                                = $this->held_order();
		$order->meta[ Meta::VERIFICATION_ID ] = 'acv_<script>';
		ob_start();
		OrderPanel::render( $order );
		$html = ob_get_clean();
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'name="navexa_agecheck_override_reason"', $html );
		$this->assertStringContainsString( 'name="navexa_agecheck_override_confirm"', $html );

		ob_start();
		OrderPanel::render( (object) array( 'ID' => $this->restricted_order( 'processing' )->get_id() ) );
		$this->assertStringNotContainsString( 'override_reason', ob_get_clean(), 'posts-table screens pass a WP_Post' );

		$this->caps = array();
		ob_start();
		OrderPanel::render( $order );
		$this->assertStringNotContainsString( 'override_reason', ob_get_clean(), 'staff without manage_woocommerce' );
		OrderPanel::render( null );
	}

	public function test_order_actions_only_for_held_orders_and_managers(): void {
		$actions = OrderPanel::order_actions( array( 'send_order_details' => 'Email' ), $this->held_order() );
		$this->assertArrayHasKey( OrderPanel::ACTION_CHECK, $actions );
		$this->assertArrayHasKey( OrderPanel::ACTION_OVERRIDE, $actions );
		$this->assertSame( array( 'x' => 'y' ), OrderPanel::order_actions( array( 'x' => 'y' ), $this->restricted_order( 'processing' ) ) );
		$this->caps = array();
		$this->assertSame( array(), OrderPanel::order_actions( array(), $this->held_order() ) );
	}

	public function test_override_action_needs_nonce_reason_and_confirmation(): void {
		$order = $this->held_order();
		OrderPanel::action_override( $order );
		$this->assertSame( Hold::STATUS, $order->get_status(), 'no nonce' );

		$_POST['woocommerce_meta_nonce'] = 'good';
		$_POST[ OrderPanel::FIELD_REASON ] = 'Checked ID in store';
		OrderPanel::action_override( $order );
		$this->assertSame( Hold::STATUS, $order->get_status(), 'no confirmation tick' );
		$this->assertStringContainsString( 'was not released', $this->transients[ Notices::FLASH_PREFIX . '3' ]['value'][0] );

		$_POST[ OrderPanel::FIELD_CONFIRM ] = '1';
		OrderPanel::action_override( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 3, $order->meta[ Meta::OVERRIDE_BY ] );
		$this->assertStringContainsString( 'Reason given: Checked ID in store', implode( "\n", $order->notes ) );
	}

	public function test_override_action_is_refused_without_the_capability_or_a_good_nonce(): void {
		$_POST = array(
			'woocommerce_meta_nonce'   => 'bad',
			OrderPanel::FIELD_REASON  => 'x',
			OrderPanel::FIELD_CONFIRM => '1',
		);
		$order = $this->held_order();
		OrderPanel::action_override( $order );
		$this->assertSame( Hold::STATUS, $order->get_status() );
		$_POST['woocommerce_meta_nonce'] = 'good';
		$this->caps                      = array();
		OrderPanel::action_override( $order );
		$this->assertSame( Hold::STATUS, $order->get_status() );
	}

	public function test_check_now_runs_a_release_and_reports_it(): void {
		$_POST['woocommerce_meta_nonce'] = 'good';
		$order                           = $this->held_order();
		$order->meta[ Meta::RENEWAL ]    = 'yes';
		OrderPanel::action_check( $order );
		$this->assertStringContainsString( 'See the AgeCheck panel', $this->transients[ Notices::FLASH_PREFIX . '3' ]['value'][0] );

		$other = $this->held_order();
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		OrderPanel::action_check( $other );
		$this->assertStringContainsString( 'could not be reached', end( $this->transients[ Notices::FLASH_PREFIX . '3' ]['value'] ) );

		$_POST = array();
		OrderPanel::action_check( $other );
		$this->assertCount( 1, $this->http_requests, 'no nonce, no run' );
	}

	private function held_order(): \WC_Order {
		$order                                   = $this->restricted_order( Hold::STATUS );
		$order->meta[ Meta::REQUIRED ]           = 'yes';
		$order->meta[ Meta::HELD ]               = 'yes';
		$order->meta[ Meta::INTERCEPTED_STATUS ] = 'processing';
		$order->meta[ Meta::STATUS ]             = Meta::STATUS_PENDING;
		return $order;
	}
}
