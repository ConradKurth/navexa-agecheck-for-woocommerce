<?php
/**
 * Upgrade tests (TAX-1887): the version option follows the running version,
 * and the one-time clean-up takes released orders off the refund list.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\Tests\TestCase;
use Navexa\AgeCheck\Upgrade;

final class UpgradeTest extends TestCase {

	const LISTING = 'AgeCheck: refund or cancel this order. It is listed under "AgeCheck: denied, refund required" on the Orders screen.';
	const RELEASE = 'AgeCheck: age check passed. Order released. Order status changed from Awaiting age check to Processing.';

	/**
	 * Who added each note, by order id and note index (default: system).
	 *
	 * @var array<int, array<int, string>>
	 */
	private $added_by = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->wire_order_hooks();
		$test = $this;
		Functions\when( 'wc_get_order_notes' )->alias(
			static function ( $args ) use ( $test ) {
				$order = \WC_Order::$store[ $args['order_id'] ] ?? null;
				$notes = array();
				foreach ( null === $order ? array() : $order->notes as $i => $content ) {
					$notes[] = (object) array(
						'id'            => $args['order_id'] * 1000 + $i + 1,
						'content'       => $content,
						'customer_note' => false,
						'added_by'      => $test->added_by( (int) $args['order_id'], $i ),
					);
				}
				return array_reverse( $notes ); // Newest first, as WooCommerce answers.
			}
		);
	}

	/**
	 * Who added a note.
	 *
	 * @param int $order_id Order id.
	 * @param int $index    Note index.
	 */
	public function added_by( int $order_id, int $index ): string {
		return $this->added_by[ $order_id ][ $index ] ?? 'system';
	}

	public function test_the_version_option_follows_the_running_version(): void {
		$this->options[ Install::OPTION_VERSION ] = '1.0.0';
		Upgrade::maybe_upgrade();
		$this->assertSame( NAVEXA_AGECHECK_VERSION, $this->options[ Install::OPTION_VERSION ] );
		$this->assertCount( 1, $this->upgrade_actions() );

		// The next request finds it current: nothing queued again.
		Upgrade::maybe_upgrade();
		$this->assertCount( 1, $this->upgrade_actions() );
	}

	public function test_an_uninitialized_install_records_nothing(): void {
		$this->options = array();
		Upgrade::maybe_upgrade();
		$this->assertArrayNotHasKey( Install::OPTION_VERSION, $this->options );
		$this->assertCount( 0, $this->upgrade_actions() );
	}

	public function test_a_fresh_install_has_the_running_version(): void {
		$this->options = array();
		Install::ensure();
		Upgrade::maybe_upgrade();
		$this->assertSame( NAVEXA_AGECHECK_VERSION, $this->options[ Install::OPTION_VERSION ] );
		$this->assertCount( 0, $this->upgrade_actions() );
	}

	public function test_released_orders_leave_the_refund_list_once(): void {
		// Order #30 of the pilot: listed, then cleared and released by 1.1.0.
		$released = $this->listed_order( 'processing', Meta::STATUS_CLEARED, array( self::LISTING, self::RELEASE ) );
		// TEST-prefixed notes (a test key) count the same.
		$test = $this->listed_order( 'completed', Meta::STATUS_CLEARED, array( 'TEST ' . self::LISTING, 'TEST ' . self::RELEASE ) );
		// No release note: left alone.
		$no_note = $this->listed_order( 'processing', Meta::STATUS_CLEARED, array( self::LISTING ) );
		// Released before it was listed (a note order no release run writes): left alone.
		$earlier = $this->listed_order( 'processing', Meta::STATUS_CLEARED, array( self::RELEASE, self::LISTING ) );
		// The release note typed by a staff member: not the plugin's.
		$typed                                 = $this->listed_order( 'processing', Meta::STATUS_CLEARED, array( self::LISTING, self::RELEASE ) );
		$this->added_by[ $typed->get_id() ][1] = 'shop_manager';
		// Released by staff without age check: not the plugin's own release.
		$staff = $this->listed_order( 'processing', Meta::STATUS_OVERRIDE, array( self::LISTING, self::RELEASE ) );
		// Still held, or the release state copied from another order: left alone.
		$held                               = $this->listed_order( Hold::STATUS, Meta::STATUS_CLEARED, array( self::LISTING, self::RELEASE ) );
		$copied                             = $this->listed_order( 'processing', Meta::STATUS_CLEARED, array( self::LISTING, self::RELEASE ) );
		$copied->meta[ Meta::RELEASED_FOR ] = '1:9999';
		$copied->save_meta_data();
		// Denied and still listed: the list's own order.
		$denied = $this->listed_order( Hold::STATUS, Meta::STATUS_DENIED, array( self::LISTING ) );

		$this->transients[ \Navexa\AgeCheck\Admin\Notices::COUNTS_TRANSIENT ] = array(
			'value' => array(
				'denied' => 9,
				'stale'  => 0,
			),
			'ttl'   => 600,
		);
		$this->assertSame( 2, Upgrade::run() );
		foreach ( array( $released, $test ) as $order ) {
			$this->assertArrayNotHasKey( Meta::REFUND, $order->saved_meta, 'order ' . $order->get_id() );
			$this->assertStringContainsString( 'taken off "AgeCheck: denied, refund required"', end( $order->notes ) );
			$this->assertSame( Meta::STATUS_CLEARED, $order->saved_meta[ Meta::STATUS ], 'the release state stays' );
		}
		foreach ( array( $no_note, $earlier, $typed, $staff, $held, $copied, $denied ) as $order ) {
			$this->assertSame( Meta::REFUND_MANUAL, $order->saved_meta[ Meta::REFUND ], 'order ' . $order->get_id() );
		}
		$this->assertArrayNotHasKey( \Navexa\AgeCheck\Admin\Notices::COUNTS_TRANSIENT, $this->transients, 'the notice recounts' );

		// Once per site: a second run (a second queued action) does nothing.
		$again = $this->listed_order( 'processing', Meta::STATUS_CLEARED, array( self::LISTING, self::RELEASE ) );
		$this->assertSame( 0, Upgrade::run() );
		$this->assertSame( Meta::REFUND_MANUAL, $again->saved_meta[ Meta::REFUND ] );
	}

	public function test_a_release_drops_the_listing_and_the_retry_flag(): void {
		$order                           = \WC_Order::make( Hold::STATUS );
		$order->meta[ Meta::REFUND ]     = Meta::REFUND_MANUAL;
		$order->meta[ Meta::RETRY_OPEN ] = 'yes';
		$this->assertTrue( Meta::mark_released( $order, Meta::STATUS_OVERRIDE ), 'the listing was dropped' );
		$this->assertArrayNotHasKey( Meta::REFUND, $order->meta );
		$this->assertArrayNotHasKey( Meta::RETRY_OPEN, $order->meta );
		$this->assertTrue( Meta::is_released( $order ) );

		// A refund already recorded is history: kept.
		$refunded                       = \WC_Order::make( Hold::STATUS );
		$refunded->meta[ Meta::REFUND ] = Meta::REFUND_RESOLVED;
		$this->assertFalse( Meta::mark_released( $refunded, Meta::STATUS_CLEARED ) );
		$this->assertSame( Meta::REFUND_RESOLVED, $refunded->meta[ Meta::REFUND ] );
	}

	public function test_the_job_is_registered(): void {
		Upgrade::register();
		$this->assertNotFalse( has_action( 'init', array( Upgrade::class, 'maybe_upgrade' ) ) );
		$this->assertNotFalse( has_action( Upgrade::HOOK, array( Upgrade::class, 'run_job' ) ) );
	}

	/**
	 * A released or denied order still listed for a manual refund.
	 *
	 * @param string   $status Order status.
	 * @param string   $state  Meta::STATUS.
	 * @param string[] $notes  Its notes, oldest first.
	 */
	private function listed_order( string $status, string $state, array $notes ): \WC_Order {
		$order                             = \WC_Order::make( $status );
		$order->meta[ Meta::STATUS ]       = $state;
		$order->meta[ Meta::RELEASED_FOR ] = '1:' . $order->get_id();
		$order->meta[ Meta::REFUND ]       = Meta::REFUND_MANUAL;
		$order->notes                      = $notes;
		$order->save_meta_data();
		return $order;
	}

	/** The queued clean-up actions. */
	private function upgrade_actions(): array {
		return array_values(
			array_filter(
				$this->scheduled,
				static function ( $action ) {
					return Upgrade::HOOK === $action['hook'] && Release::GROUP === $action['group'];
				}
			)
		);
	}
}
