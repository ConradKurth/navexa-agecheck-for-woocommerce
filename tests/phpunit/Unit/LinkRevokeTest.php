<?php
/**
 * Withdrawing emailed verification links once an order will not ship:
 * which orders and status changes queue it, the run (persisted key, lock,
 * retries, every answer and failure recorded), and what the order shows.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Admin\OrderPanel;
use Navexa\AgeCheck\Binding;
use Navexa\AgeCheck\ConnectError;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\IntegrationInfo;
use Navexa\AgeCheck\LinkRevoke;
use Navexa\AgeCheck\Links;
use Navexa\AgeCheck\Lock;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\Sweep;
use Navexa\AgeCheck\Tests\TestCase;

final class LinkRevokeTest extends TestCase {

	const VID = 'acv_REVOKETEST';

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->options['date_format'] = 'Y-m-d';
		$this->options['time_format'] = 'H:i';
	}

	public function test_register(): void {
		LinkRevoke::register();
		$this->assertNotFalse( has_action( 'woocommerce_order_status_changed', array( LinkRevoke::class, 'on_status_changed' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_fully_refunded', array( LinkRevoke::class, 'on_fully_refunded' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_trash_order', array( LinkRevoke::class, 'on_trashed' ) ) );
		$this->assertNotFalse( has_action( 'trashed_post', array( LinkRevoke::class, 'on_trashed_post' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_before_delete_order', array( LinkRevoke::class, 'on_before_delete' ) ) );
		$this->assertNotFalse( has_action( 'before_delete_post', array( LinkRevoke::class, 'on_before_delete_post' ) ) );
		$this->assertNotFalse( has_action( LinkRevoke::HOOK, array( LinkRevoke::class, 'run_job' ) ) );
	}

	public function test_cancelling_a_held_order_queues_the_withdrawal_and_calls_nothing(): void {
		$order = $this->emailed_order();
		$order->set_status( 'cancelled' );
		$order->save();

		LinkRevoke::on_status_changed( $order->get_id(), Hold::STATUS, 'cancelled', $order );

		$this->assertSame( array(), $this->http_requests, 'never in the status change' );
		$this->assertSame( 'pending:' . self::VID, $order->saved_meta[ Meta::REVOKE ], 'recorded and saved' );
		$this->assertGreaterThan( time() - 5, $order->saved_meta[ Meta::REVOKE_AT ], 'pending since (the sweep queues a stale one again)' );
		$jobs = $this->scheduled_for( LinkRevoke::HOOK );
		$this->assertCount( 1, $jobs );
		$this->assertSame( array( $order->get_id(), 0 ), $jobs[0]['args'] );
		$this->assertSame( Release::GROUP, $jobs[0]['group'] );
		$this->assertFalse( $jobs[0]['unique'], 'never Action Scheduler\'s $unique (3.9 ignores args)' );
		$this->assertSame( 'The order will not ship: AgeCheck is being asked to withdraw its emailed verification link.', OrderPanel::rows( $order )['Link withdrawal'] );
		$this->assertStringContainsString( 'agecheck link revoke queued order=' . $order->get_id() . ' vid=' . self::VID . ' trigger=cancelled queued=yes', $this->all_logs() );

		$saves = $order->meta_saves;
		LinkRevoke::on_fully_refunded( $order->get_id() );
		LinkRevoke::on_trashed( $order->get_id() );
		$this->assertCount( 1, $this->scheduled_for( LinkRevoke::HOOK ), 'one pending run per order' );
		$this->assertSame( $saves, $order->meta_saves, 'pending is recorded once' );
	}

	public function test_the_run_withdraws_the_links_with_a_key_stored_first(): void {
		$order = $this->closed_order( 'cancelled' );
		$saved = null;
		$this->before_http = static function () use ( $order, &$saved ) {
			$saved = $order->saved_meta;
		};
		$this->respond( 200, array( 'revokedCount' => 1 ) );

		$this->assertSame( LinkRevoke::REVOKED, LinkRevoke::run( $order->get_id() ) );

		$this->assertSame( array( 'RevokeVerificationLinks' ), $this->rpcs() );
		$this->assertSame( self::LIVE_FULFILMENT_KEY, $this->request_key( 0 ), 'the fulfilment key' );
		$body = $this->request_body( 0 );
		$this->assertMatchesRegularExpression( '/^rvk:' . self::VID . ':[0-9a-f]{16}$/', $body['idempotencyKey'] );
		$this->assertSame( self::VID, $body['verificationId'] );
		$this->assertSame( $body['idempotencyKey'], $saved[ Meta::REVOKE_KEY ], 'the key is saved before the call' );

		$this->assertSame( 'revoked:' . self::VID, $order->saved_meta[ Meta::REVOKE ] );
		$this->assertSame( 1, $order->saved_meta[ Meta::REVOKE_DETAIL ] );
		$this->assertGreaterThan( time() - 5, $order->saved_meta[ Meta::REVOKE_AT ] );
		$this->assertArrayNotHasKey( Meta::REVOKE_KEY, $order->saved_meta, 'dropped once the answer is recorded' );
		$this->assertSame( 'revoked:' . self::VID, $order->saved_meta[ Meta::LINK ] );
		$this->assertArrayNotHasKey( Meta::LINK_ID, $order->saved_meta );
		$this->assertArrayNotHasKey( Meta::LINK_EXPIRES_AT, $order->saved_meta );
		$this->assertStringContainsString( 'the order will not ship, so AgeCheck withdrew its emailed verification link (1 was still open)', end( $order->notes ) );
		$this->assertStringContainsString( 'agecheck link revoked order=' . $order->get_id() . ' vid=' . self::VID . ' revoked=1', $this->all_logs() );
		$this->assertStringNotContainsString( 'rvk:', $this->all_logs(), 'never the key in a log line' );
		$this->assertArrayNotHasKey( Release::lock_name( $order->get_id() ), $this->options, 'the lock is released' );

		$rows = OrderPanel::rows( $order );
		$this->assertStringContainsString( 'Withdrawn because the order will not ship', $rows['Verification link'] );
		$this->assertArrayNotHasKey( 'Link withdrawal', $rows );
		$this->assertFalse( Links::can_withdraw( $order ), 'nothing open on record' );
		$this->assertSame( Links::STATE_REVOKED, Links::send( $order, $this->fulfilment_client(), $this->info() ), 'a release run never sends it a new link while it will not ship' );
		$this->assertCount( 1, $this->http_requests );

		$this->assertSame( LinkRevoke::SKIPPED, LinkRevoke::run( $order->get_id() ), 'answered: a second run asks nothing' );
		LinkRevoke::on_trashed( $order->get_id() );
		$this->assertSame( array(), $this->scheduled_for( LinkRevoke::HOOK ), 'withdrawn, nothing sent since: trashing it later queues nothing' );
	}

	public function test_none_still_open_is_an_answer_too(): void {
		$order = $this->closed_order( 'failed' );
		$this->respond( 200, array() );
		$this->assertSame( LinkRevoke::REVOKED, LinkRevoke::run( $order->get_id() ) );
		$this->assertSame( 0, $order->saved_meta[ Meta::REVOKE_DETAIL ], 'proto3 JSON omits 0' );
		$this->assertStringContainsString( 'None was still open', end( $order->notes ) );
	}

	public function test_a_staff_resend_after_the_withdrawal_makes_the_next_close_withdraw_again(): void {
		$order = $this->closed_order( 'cancelled' );
		$this->respond( 200, array( 'revokedCount' => 1 ) );
		LinkRevoke::run( $order->get_id() );
		$this->assertFalse( LinkRevoke::wanted( $order ) );

		$order->meta[ Meta::LINK ] = 'sent:' . self::VID; // Held again, and staff resent it.
		$this->assertTrue( LinkRevoke::wanted( $order ) );
		$order->meta[ Meta::LINK ]     = 'revoked:' . self::VID;
		$order->meta[ Meta::LINK_KEY ] = 'vl:' . self::VID . ':0123456789abcdef'; // A send whose answer is not recorded.
		$this->assertTrue( LinkRevoke::wanted( $order ) );
	}

	/**
	 * @dataProvider not_closing
	 */
	public function test_only_closing_statuses_queue( string $to ): void {
		$order = $this->emailed_order();
		LinkRevoke::on_status_changed( $order->get_id(), Hold::STATUS, $to, $order );
		$this->assertSame( array(), $this->scheduled );
		$this->assertArrayNotHasKey( Meta::REVOKE, $order->meta );
	}

	public static function not_closing(): array {
		return array(
			'processing' => array( 'processing' ),
			'completed'  => array( 'completed' ),
			'on-hold'    => array( 'on-hold' ),
			'pending'    => array( 'pending' ),
			'hold'       => array( Hold::STATUS ),
		);
	}

	/**
	 * @dataProvider closing
	 */
	public function test_every_closing_status_queues( string $to ): void {
		$order = $this->emailed_order();
		LinkRevoke::on_status_changed( $order->get_id(), Hold::STATUS, $to, $order );
		$this->assertCount( 1, $this->scheduled_for( LinkRevoke::HOOK ), $to );
	}

	public static function closing(): array {
		return array(
			'cancelled' => array( 'cancelled' ),
			'refunded'  => array( 'refunded' ),
			'failed'    => array( 'failed' ),
		);
	}

	public function test_a_partial_refund_withdraws_nothing_a_full_one_does(): void {
		$order                   = $this->emailed_order();
		$order->total_refunded   = 5.0;
		$order->remaining_refund = 20.0;
		// WooCommerce fires woocommerce_order_partially_refunded and keeps the status.
		$this->assertFalse( LinkRevoke::is_closed( $order ), 'a partial refund can still ship' );
		$this->assertSame( array(), $this->scheduled );

		// Refunded in full, with the status kept by woocommerce_order_fully_refunded_status.
		$order->total_refunded   = 25.0;
		$order->remaining_refund = 0.0;
		LinkRevoke::on_fully_refunded( $order->get_id() );
		$this->assertCount( 1, $this->scheduled_for( LinkRevoke::HOOK ) );
		$this->assertTrue( LinkRevoke::is_closed( $order ) );
		$this->respond( 200, array( 'revokedCount' => 1 ) );
		$this->assertSame( LinkRevoke::REVOKED, LinkRevoke::run( $order->get_id() ), 'refunded in full counts whatever the status' );

		$free                   = $this->emailed_order();
		$free->remaining_refund = 0.0;
		$this->assertFalse( LinkRevoke::is_closed( $free ), 'a free order is not refunded by being free' );
	}

	public function test_trashing_on_either_order_store(): void {
		$order = $this->emailed_order();
		$order->set_status( 'trash' );
		LinkRevoke::on_trashed( $order->get_id() );
		$this->assertCount( 1, $this->scheduled_for( LinkRevoke::HOOK ), 'WC_Order::delete() (woocommerce_trash_order)' );

		$types = array();
		Functions\when( 'get_post_type' )->alias(
			static function ( $id ) use ( &$types ) {
				return isset( $types[ $id ] ) ? $types[ $id ] : false;
			}
		);
		$posts = $this->emailed_order( 'acv_POSTS' );
		$hpos  = $this->emailed_order( 'acv_HPOS' );
		$types = array(
			$posts->get_id() => 'shop_order',
			$hpos->get_id()  => 'shop_order_placehold',
		);
		LinkRevoke::on_trashed_post( $posts->get_id() );
		LinkRevoke::on_trashed_post( $hpos->get_id() );
		LinkRevoke::on_trashed_post( 999999 );
		$jobs = $this->scheduled_for( LinkRevoke::HOOK );
		$this->assertCount( 2, $jobs, 'wp_trash_post() on the posts table; never the HPOS placeholder post' );
		$this->assertSame( array( $posts->get_id(), 0 ), $jobs[1]['args'] );
	}

	public function test_which_orders_qualify(): void {
		$this->assertTrue( LinkRevoke::wanted( $this->emailed_order() ), 'held, link sent' );

		$held_no_link = $this->emailed_order();
		unset( $held_no_link->meta[ Meta::LINK ] );
		$this->assertTrue( LinkRevoke::wanted( $held_no_link ), 'held by this plugin: AgeCheck may have sent what the order does not know' );
		$this->assertTrue( LinkRevoke::wanted( $held_no_link, Hold::STATUS ) );

		$released = $this->emailed_order();
		unset( $released->meta[ Meta::HELD ] );
		Meta::mark_released( $released, Meta::STATUS_OVERRIDE );
		$this->assertTrue( LinkRevoke::wanted( $released ), 'released by staff, refunded later: its link may still open' );
		unset( $released->meta[ Meta::LINK ] );
		$this->assertFalse( LinkRevoke::wanted( $released ), 'not held and no link on record' );

		$checkout                         = $this->emailed_order();
		$checkout->meta[ Meta::BOUND_BY ] = 'attach';
		$checkout->meta[ Meta::LINK ]     = 'not_eligible:' . self::VID;
		$this->assertFalse( LinkRevoke::wanted( $checkout ), 'held on a checkout verification: it passed before the order, and AgeCheck emails none for it' );
		$this->assertFalse( LinkRevoke::wanted( $checkout, Hold::STATUS ) );
		$checkout->meta[ Meta::LINK ] = 'sent:' . self::VID;
		$this->assertTrue( LinkRevoke::wanted( $checkout ), 'a link on record as open always counts' );
		$adopted                         = $this->emailed_order();
		$adopted->meta[ Meta::BOUND_BY ] = 'adopt';
		$adopted->meta[ Meta::LINK ]     = 'not_eligible:' . self::VID;
		$this->assertTrue( LinkRevoke::wanted( $adopted ), 'an adopted order-bound check may have been emailed from the dashboard' );

		$withdrawn                     = $this->emailed_order();
		$withdrawn->meta[ Meta::LINK ] = 'withdrawn:' . self::VID;
		$this->assertFalse( LinkRevoke::wanted( $withdrawn ), 'staff withdrew it already' );

		$none = $this->emailed_order();
		unset( $none->meta[ Meta::VERIFICATION_ID ] );
		$this->assertFalse( LinkRevoke::wanted( $none ), 'no verification' );

		$copied = $this->emailed_order();
		$source = $this->emailed_order();
		$copied->meta[ Meta::EXTERNAL_ID ] = $source->meta[ Meta::EXTERNAL_ID ];
		$this->assertFalse( LinkRevoke::wanted( $copied ), 'a verification copied from another order is never withdrawn' );
		LinkRevoke::on_status_changed( $copied->get_id(), Hold::STATUS, 'cancelled', $copied );
		$this->assertSame( array(), $this->scheduled );

		$refund       = $this->emailed_order();
		$refund->type = 'shop_order_refund';
		$this->assertFalse( LinkRevoke::wanted( $refund ) );
	}

	public function test_copied_state_is_dropped_with_the_external_id(): void {
		foreach ( array( Meta::REVOKE, Meta::REVOKE_KEY, Meta::REVOKE_DETAIL, Meta::REVOKE_AT ) as $key ) {
			$this->assertContains( $key, Binding::COPIED_STATE, $key );
		}
		$copied = $this->closed_order( 'cancelled' );
		$source = $this->emailed_order( 'acv_SOURCE' );
		$copied->meta[ Meta::EXTERNAL_ID ] = $source->meta[ Meta::EXTERNAL_ID ];
		$this->assertSame( LinkRevoke::SKIPPED, LinkRevoke::run( $copied->get_id() ), 'a run never withdraws another order\'s links' );
		$this->assertSame( array(), $this->http_requests );
		$this->assertArrayNotHasKey( Meta::REVOKE, $copied->saved_meta, 'the copied state is dropped, so nothing stays pending' );
		$this->assertArrayNotHasKey( Meta::VERIFICATION_ID, $copied->saved_meta );
		$this->assertStringContainsString( 'reason=copied', $this->all_logs() );
	}

	public function test_a_retryable_failure_retries_with_the_same_key_then_is_recorded(): void {
		$order = $this->closed_order( 'cancelled' );
		$this->respond( 503, array( 'code' => 'unavailable' ), array( 'Retry-After' => '600' ) );

		LinkRevoke::run_job( $order->get_id(), 0 );

		$key   = $order->saved_meta[ Meta::REVOKE_KEY ];
		$retry = $this->scheduled_for( LinkRevoke::HOOK );
		$this->assertCount( 1, $retry );
		$this->assertSame( array( $order->get_id(), 1 ), $retry[0]['args'] );
		$this->assertGreaterThanOrEqual( time() + 600, $retry[0]['when'], 'Retry-After honoured' );
		$this->assertSame( 'pending:' . self::VID, $order->meta[ Meta::REVOKE ], 'nothing recorded yet' );
		$notes = count( $order->notes );

		$this->respond( 503, array( 'code' => 'unavailable' ) );
		LinkRevoke::run_job( $order->get_id(), 3 );
		$retry = $this->scheduled_for( LinkRevoke::HOOK );
		$this->assertSame( array( $order->get_id(), 4 ), $retry[1]['args'] );
		$this->assertGreaterThanOrEqual( time() + 480, $retry[1]['when'], 'backoff: 60 s x 2^attempt' );
		$this->assertSame( $key, $this->request_body( 1 )['idempotencyKey'], 'the retry replays the stored key' );
		$this->assertCount( $notes, $order->notes );

		$this->respond( 503, array( 'code' => 'unavailable' ) );
		LinkRevoke::run_job( $order->get_id(), LinkRevoke::MAX_ATTEMPTS );
		$this->assertCount( 2, $this->scheduled_for( LinkRevoke::HOOK ), 'the last attempt schedules nothing' );
		$this->assertSame( $key, $this->request_body( 2 )['idempotencyKey'] );
		$this->assertSame( 'failed:' . self::VID, $order->saved_meta[ Meta::REVOKE ] );
		$this->assertSame( 'unavailable', $order->saved_meta[ Meta::REVOKE_DETAIL ] );
		$this->assertStringContainsString( 'could not withdraw its emailed verification link (unavailable)', end( $order->notes ) );
		$this->assertSame( 'sent:' . self::VID, $order->saved_meta[ Meta::LINK ], 'fail closed: still on record as open' );
		$this->assertTrue( Links::can_withdraw( $order ), 'staff can still withdraw it' );
		$this->assertStringContainsString( 'AgeCheck could not withdraw the emailed verification link (unavailable)', OrderPanel::rows( $order )['Link withdrawal'] );
		$this->assertStringContainsString( 'agecheck link revoke failed order=' . $order->get_id() . ' vid=' . self::VID . ' code=unavailable', $this->all_logs() );
	}

	public function test_a_lost_answer_is_replayed_even_if_the_order_reopened(): void {
		$order                           = $this->closed_order( 'cancelled' );
		$order->meta[ Meta::REVOKE_KEY ] = 'rvk:' . self::VID . ':00112233445566aa';
		$order->set_status( Hold::STATUS );
		$this->respond( 200, array( 'revokedCount' => 1 ) );
		$this->assertSame( LinkRevoke::REVOKED, LinkRevoke::run( $order->get_id() ), 'AgeCheck may have withdrawn it: ask again to know' );
		$this->assertSame( 'rvk:' . self::VID . ':00112233445566aa', $this->request_body( 0 )['idempotencyKey'] );
		$this->assertSame( 'revoked:' . self::VID, $order->meta[ Meta::LINK ], 'the order says what AgeCheck did' );
	}

	public function test_an_order_that_can_ship_again_before_the_run_keeps_its_link(): void {
		$order = $this->closed_order( 'trash' );
		$order->set_status( Hold::STATUS ); // Restored from the trash.
		$this->assertSame( LinkRevoke::SKIPPED, LinkRevoke::run( $order->get_id() ) );
		$this->assertSame( array(), $this->http_requests );
		$this->assertArrayNotHasKey( Meta::REVOKE, $order->saved_meta );
		$this->assertSame( 'sent:' . self::VID, $order->meta[ Meta::LINK ] );
		$this->assertStringContainsString( 'reason=reopened', $this->all_logs() );
	}

	/**
	 * @dataProvider refusals
	 *
	 * @param int          $status HTTP status.
	 * @param array|string $body   Answer body.
	 * @param string       $code   Code recorded.
	 */
	public function test_a_refusal_is_recorded_at_once( int $status, $body, string $code ): void {
		$order = $this->closed_order( 'refunded' );
		$this->respond( $status, $body );
		LinkRevoke::run_job( $order->get_id(), 0 );
		$this->assertSame( array(), $this->scheduled_for( LinkRevoke::HOOK ), 'no retry' );
		$this->assertSame( 'failed:' . self::VID, $order->saved_meta[ Meta::REVOKE ] );
		$this->assertSame( $code, $order->saved_meta[ Meta::REVOKE_DETAIL ] );
		$this->assertArrayNotHasKey( Meta::REVOKE_KEY, $order->saved_meta );
		$this->assertStringContainsString( '(' . $code . ')', end( $order->notes ) );
		$this->assertStringNotContainsString( 'secret engine text', $this->all_logs(), 'never the engine\'s message' );
	}

	public static function refusals(): array {
		return array(
			'scope'      => array( 403, array( 'code' => 'permission_denied', 'message' => 'secret engine text' ), 'permission_denied' ),
			'unknown'    => array( 404, array( 'code' => 'not_found', 'message' => 'secret engine text' ), 'not_found' ),
			'old engine' => array( 404, '404 page not found', 'unimplemented' ),
		);
	}

	public function test_no_key_or_a_paused_site_calls_nothing_and_says_so(): void {
		$order = $this->closed_order( 'cancelled' );
		unset( $this->options[ Options::FULFILMENT_KEY ] );
		$this->assertSame( LinkRevoke::FAILED, LinkRevoke::run( $order->get_id() ) );
		$this->assertSame( 'no_api_key', $order->saved_meta[ Meta::REVOKE_DETAIL ] );

		$copy = $this->closed_order( 'cancelled', 'acv_COPYSITE' );
		$this->options[ Options::FULFILMENT_KEY ] = self::LIVE_FULFILMENT_KEY;
		$this->home = 'https://copy.example';
		$this->assertSame( LinkRevoke::FAILED, LinkRevoke::run( $copy->get_id() ), 'a copied site never withdraws the original site\'s links' );
		$this->assertSame( 'install_unconfirmed', $copy->saved_meta[ Meta::REVOKE_DETAIL ] );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_a_locked_order_is_tried_again_shortly(): void {
		$order = $this->closed_order( 'cancelled' );
		$token = Lock::acquire( Release::lock_name( $order->get_id() ), 120 );
		LinkRevoke::run_job( $order->get_id(), LinkRevoke::MAX_ATTEMPTS );
		$jobs = $this->scheduled_for( LinkRevoke::HOOK );
		$this->assertCount( 1, $jobs );
		$this->assertSame( array( $order->get_id(), LinkRevoke::MAX_ATTEMPTS ), $jobs[0]['args'], 'a lock is not a failure: never past the last attempt' );
		$this->assertLessThanOrEqual( time() + LinkRevoke::LOCKED_RETRY + 1, $jobs[0]['when'] );
		$this->assertSame( array(), $this->http_requests );
		Lock::release( Release::lock_name( $order->get_id() ), $token );
	}

	public function test_nothing_in_the_status_change_can_throw(): void {
		$order = new class( 4242 ) extends \WC_Order {
			public function save_meta_data() {
				throw new \RuntimeException( 'database gone' );
			}
		};
		\WC_Order::$store[4242]             = $order;
		$order->data['status']               = 'cancelled';
		$order->meta[ Meta::HELD ]            = 'yes';
		$order->meta[ Meta::VERIFICATION_ID ] = self::VID;
		Binding::external_id( $order );

		LinkRevoke::on_status_changed( $order->get_id(), Hold::STATUS, 'cancelled', $order );

		$this->assertStringContainsString( 'agecheck link revoke not queued order=4242 error=RuntimeException', $this->all_logs() );
		$this->assertStringNotContainsString( 'database gone', $this->all_logs() );
	}

	public function test_deleting_for_good_queues_a_run_that_needs_no_order(): void {
		Functions\when( 'get_post_type' )->justReturn( 'shop_order' );
		$order = $this->emailed_order();
		$id    = $order->get_id();

		LinkRevoke::on_before_delete( $id );
		LinkRevoke::on_before_delete_post( $id ); // The posts table fires both for one order.

		$this->assertSame( array(), $this->http_requests, 'never in the deletion' );
		$jobs = $this->scheduled_for( LinkRevoke::HOOK );
		$this->assertCount( 1, $jobs, 'one run per deleted order' );
		$this->assertSame( array( $id, 0, self::VID ), array_slice( $jobs[0]['args'], 0, 3 ) );
		$this->assertMatchesRegularExpression( '/^rvk:' . self::VID . ':[0-9a-f]{16}$/', $jobs[0]['args'][3] );
		$this->assertStringContainsString( 'agecheck link revoke queued order=' . $id . ' vid=' . self::VID . ' trigger=deleted queued=yes', $this->all_logs() );

		unset( \WC_Order::$store[ $id ] ); // Deleted.
		$this->respond( 200, array( 'revokedCount' => 1 ) );
		LinkRevoke::run_job( ...$jobs[0]['args'] );

		$this->assertSame( array( 'RevokeVerificationLinks' ), $this->rpcs() );
		$this->assertSame( array( 'idempotencyKey' => $jobs[0]['args'][3], 'verificationId' => self::VID ), $this->request_body( 0 ) );
		$this->assertStringContainsString( 'agecheck link revoked order=' . $id . ' vid=' . self::VID . ' revoked=1 deleted=yes', $this->all_logs() );
		$this->assertStringNotContainsString( 'rvk:', $this->all_logs(), 'never the key in a log line' );
		$this->assertCount( 1, $this->scheduled_for( LinkRevoke::HOOK ) );
	}

	public function test_an_order_trashed_then_deleted_before_its_run_is_still_withdrawn(): void {
		$order                           = $this->closed_order( 'trash' );
		$id                              = $order->get_id();
		$order->meta[ Meta::REVOKE_KEY ] = 'rvk:' . self::VID . ':00112233445566aa'; // Asked already, answer lost.
		$order->save_meta_data();
		LinkRevoke::on_trashed( $id ); // The trash's own run, still queued.
		LinkRevoke::on_before_delete( $id ); // "Empty Trash" before it ran.
		$jobs = $this->scheduled_for( LinkRevoke::HOOK );
		$this->assertSame( array( $id, 0 ), $jobs[0]['args'] );
		$this->assertSame( array( $id, 0, self::VID, 'rvk:' . self::VID . ':00112233445566aa' ), $jobs[1]['args'], 'the pending withdrawal and its stored key carry over' );

		unset( \WC_Order::$store[ $id ] );
		LinkRevoke::run_job( ...$jobs[0]['args'] );
		$this->assertSame( array(), $this->http_requests, 'the trash\'s run has nothing to ask with' );
		$this->assertStringContainsString( 'agecheck link revoke skipped order=' . $id . ' reason=no_order', $this->all_logs() );

		$this->respond( 503, array( 'code' => 'unavailable' ), array( 'Retry-After' => '600' ) );
		LinkRevoke::run_job( ...$jobs[1]['args'] );
		$retry = $this->scheduled_for( LinkRevoke::HOOK );
		$this->assertSame( array( $id, 1, self::VID, 'rvk:' . self::VID . ':00112233445566aa' ), $retry[2]['args'], 'retried with the same arguments' );
		$this->assertGreaterThanOrEqual( time() + 600, $retry[2]['when'] );

		$this->respond( 503, array( 'code' => 'unavailable' ) );
		LinkRevoke::run_job( $id, LinkRevoke::MAX_ATTEMPTS, self::VID, 'rvk:' . self::VID . ':00112233445566aa' );
		$this->assertCount( 3, $this->scheduled_for( LinkRevoke::HOOK ), 'the last attempt schedules nothing' );
		$this->assertStringContainsString( 'agecheck link revoke failed order=' . $id . ' vid=' . self::VID . ' code=unavailable deleted=yes', $this->all_logs() );
		$this->assertSame( 'rvk:' . self::VID . ':00112233445566aa', $this->request_body( 1 )['idempotencyKey'] );
	}

	public function test_deleting_queues_nothing_for_an_order_that_does_not_qualify(): void {
		$types = array();
		Functions\when( 'get_post_type' )->alias(
			static function ( $id ) use ( &$types ) {
				return isset( $types[ $id ] ) ? $types[ $id ] : false;
			}
		);
		$plain = \WC_Order::make( 'pending' );
		LinkRevoke::on_before_delete( $plain->get_id() );
		$withdrawn = $this->closed_order( 'cancelled' );
		$withdrawn->meta[ Meta::REVOKE ] = 'revoked:' . self::VID;
		$withdrawn->meta[ Meta::LINK ]   = 'revoked:' . self::VID;
		LinkRevoke::on_before_delete( $withdrawn->get_id() );
		$copied                            = $this->emailed_order();
		$copied->meta[ Meta::EXTERNAL_ID ] = $this->emailed_order( 'acv_SOURCE' )->meta[ Meta::EXTERNAL_ID ];
		$copied->meta[ Meta::REVOKE ]      = 'pending:' . self::VID;
		LinkRevoke::on_before_delete( $copied->get_id() );
		$placeholder                       = $this->emailed_order();
		$types[ $placeholder->get_id() ]   = 'shop_order_placehold';
		LinkRevoke::on_before_delete_post( $placeholder->get_id() );
		LinkRevoke::on_before_delete( 999999 );
		$this->assertSame( array(), $this->scheduled_for( LinkRevoke::HOOK ) );

		$other                                       = $this->emailed_order( 'acv_OTHERSITE' );
		$this->options[ Install::OPTION_INSTALL_ID ] = str_repeat( 'b', 32 ); // A copied site, confirmed since.
		LinkRevoke::on_before_delete( $other->get_id() );
		$this->assertSame( array(), $this->scheduled_for( LinkRevoke::HOOK ) );
		$this->assertStringContainsString( 'trigger=deleted reason=other_install', $this->all_logs() );
	}

	public function test_an_order_bound_under_another_install_id_is_not_withdrawn_by_itself(): void {
		$copy    = $this->emailed_order();
		$pending = $this->closed_order( 'cancelled', 'acv_PENDINGCOPY' );
		$asked   = $this->closed_order( 'cancelled', 'acv_ASKEDCOPY' );
		$asked->meta[ Meta::REVOKE_KEY ] = 'rvk:acv_ASKEDCOPY:00112233445566aa';
		// A copied site whose admin confirmed it with the live keys: a new install_id.
		$this->options[ Install::OPTION_INSTALL_ID ] = str_repeat( 'b', 32 );

		$copy->set_status( 'cancelled' );
		LinkRevoke::on_status_changed( $copy->get_id(), Hold::STATUS, 'cancelled', $copy );
		$this->assertSame( array(), $this->scheduled_for( LinkRevoke::HOOK ) );
		$this->assertArrayNotHasKey( Meta::REVOKE, $copy->meta );
		$this->assertStringContainsString( 'agecheck link revoke skipped order=' . $copy->get_id() . ' vid=' . self::VID . ' trigger=cancelled reason=other_install', $this->all_logs() );
		$this->assertTrue( Links::can_withdraw( $copy ), 'staff can still withdraw it' );

		$this->assertSame( LinkRevoke::SKIPPED, LinkRevoke::run( $pending->get_id() ), 'pending when the site was copied' );
		$this->assertArrayNotHasKey( Meta::REVOKE, $pending->saved_meta, 'nothing stays pending' );
		$this->assertStringContainsString( 'vid=acv_PENDINGCOPY reason=other_install', $this->all_logs() );
		$this->assertSame( array(), $this->http_requests );

		$this->respond( 200, array( 'revokedCount' => 0 ) );
		$this->assertSame( LinkRevoke::REVOKED, LinkRevoke::run( $asked->get_id() ), 'a call already made is replayed to know its answer' );
		$this->assertSame( 'rvk:acv_ASKEDCOPY:00112233445566aa', $this->request_body( 0 )['idempotencyKey'] );
	}

	public function test_a_run_that_throws_is_retried_and_never_left_to_die(): void {
		$order = new class( 4343 ) extends \WC_Order {
			public function get_type() {
				throw new \RuntimeException( 'database gone' ); // A broken order store, or another plugin's hook.
			}
		};
		\WC_Order::$store[4343]      = $order;
		$order->data['status']       = 'cancelled';
		$order->meta[ Meta::REVOKE ] = 'pending:' . self::VID;

		LinkRevoke::run_job( 4343, 2 );
		$jobs = $this->scheduled_for( LinkRevoke::HOOK );
		$this->assertSame( array( 4343, 3 ), $jobs[0]['args'], 'retried like an outage' );
		$this->assertGreaterThanOrEqual( time() + 240, $jobs[0]['when'] );
		$this->assertStringContainsString( 'agecheck link revoke run failed order=4343 attempt=2 error=RuntimeException', $this->all_logs() );
		$this->assertStringNotContainsString( 'database gone', $this->all_logs() );
		$this->assertArrayNotHasKey( Release::lock_name( 4343 ), $this->options, 'the lock is released' );

		LinkRevoke::run_job( 4343, LinkRevoke::MAX_ATTEMPTS );
		$this->assertCount( 1, $this->scheduled_for( LinkRevoke::HOOK ), 'past the last attempt the sweep takes it' );
	}

	public function test_the_sweep_queues_withdrawals_left_pending_again(): void {
		$stale                           = $this->closed_order( 'cancelled', 'acv_STALE' );
		$stale->meta[ Meta::REVOKE_AT ]  = time() - LinkRevoke::REQUEUE_AFTER - 60;
		$recent                          = $this->closed_order( 'refunded', 'acv_RECENT' );
		$recent->meta[ Meta::REVOKE_AT ] = time() - 60;
		$unstamped                       = $this->closed_order( 'trash', 'acv_UNSTAMPED' );
		$answered                          = $this->closed_order( 'cancelled', 'acv_ANSWERED' );
		$answered->meta[ Meta::REVOKE ]    = 'revoked:acv_ANSWERED';
		$answered->meta[ Meta::REVOKE_AT ] = time() - 86400;

		$this->assertSame( 0, Sweep::run(), 'no held orders to release' );
		$jobs = $this->scheduled_for( LinkRevoke::HOOK );
		$this->assertSame( array( array( $stale->get_id(), 0 ), array( $unstamped->get_id(), 0 ) ), array_column( $jobs, 'args' ), 'stale or unstamped, any status, the trash included' );
		$this->assertGreaterThan( time() - 5, $stale->saved_meta[ Meta::REVOKE_AT ], 'renewed: queued again at most once per REQUEUE_AFTER' );
		$this->assertStringContainsString( 'agecheck link revoke requeued order=' . $stale->get_id() . ' queued=yes', $this->all_logs() );

		$this->assertSame( 0, LinkRevoke::requeue_stale() );
		$this->assertSame( 3, LinkRevoke::requeue_stale( time() + LinkRevoke::REQUEUE_AFTER + 60 ), 'REQUEUE_AFTER later all three are stale, the recent one too' );
		$this->assertCount( 3, $this->scheduled_for( LinkRevoke::HOOK ), 'one pending run per order' );
	}

	public function test_an_order_held_again_after_its_link_was_withdrawn_gets_a_new_one(): void {
		$order = $this->closed_order( 'failed' );
		$this->respond( 200, array( 'revokedCount' => 1 ) );
		LinkRevoke::run( $order->get_id() );
		$this->assertSame( 'revoked:' . self::VID, $order->meta[ Meta::LINK ] );

		$order->set_status( Hold::STATUS ); // The shopper paid the failed order again.
		$this->respond( 200, array( 'linkId' => 'acl_AGAIN', 'emailQueued' => true ) );
		$this->assertSame( Links::STATE_SENT, Links::send( $order, $this->fulfilment_client(), $this->info() ) );
		$this->assertSame( array( 'RevokeVerificationLinks', 'SendVerificationLink' ), $this->rpcs() );
		$this->assertSame( 'sent:' . self::VID, $order->meta[ Meta::LINK ] );
		$this->assertSame( 'acl_AGAIN', $order->meta[ Meta::LINK_ID ] );
		$this->assertTrue( LinkRevoke::wanted( $order ), 'closing it again withdraws the new link' );

		$refunded                   = $this->closed_order( 'refunded', 'acv_KEPTHELD' );
		$refunded->total_refunded   = 25.0;
		$refunded->remaining_refund = 0.0;
		$this->respond( 200, array( 'revokedCount' => 1 ) );
		LinkRevoke::run( $refunded->get_id() );
		$refunded->set_status( Hold::STATUS ); // Refunded in full, status kept by a filter.
		$this->assertSame( Links::STATE_REVOKED, Links::send( $refunded, $this->fulfilment_client(), $this->info() ), 'refunded in full: still will not ship' );
		$this->assertCount( 3, $this->http_requests );
	}

	/** A held order whose order-bound verification was emailed a link that is still open. */
	private function emailed_order( string $vid = self::VID ): \WC_Order {
		$order                                    = $this->restricted_order( Hold::STATUS );
		$order->meta[ Meta::HELD ]                = 'yes';
		$order->meta[ Meta::REQUIRED ]            = 'yes';
		$order->meta[ Meta::VERIFICATION_ID ]     = $vid;
		$order->meta[ Meta::VERIFICATION_STATUS ] = 'needs_doc';
		$order->meta[ Meta::BOUND_BY ]            = 'create';
		$order->meta[ Meta::BOUND_AT ]            = time();
		$order->meta[ Meta::LINK ]                = 'sent:' . $vid;
		$order->meta[ Meta::LINK_ID ]             = 'acl_LIVE';
		$order->meta[ Meta::LINK_EXPIRES_AT ]     = time() + 86400;
		Binding::external_id( $order );
		$order->save_meta_data();
		return $order;
	}

	/** emailed_order() moved to $status, with the withdrawal pending. */
	private function closed_order( string $status, string $vid = self::VID ): \WC_Order {
		$order = $this->emailed_order( $vid );
		$order->set_status( $status );
		$order->save();
		$order->meta[ Meta::REVOKE ] = 'pending:' . $vid;
		$order->save_meta_data();
		return $order;
	}

	private function info(): IntegrationInfo {
		return IntegrationInfo::from_response( $this->live_info() );
	}
}
