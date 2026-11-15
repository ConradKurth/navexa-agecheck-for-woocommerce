<?php
/**
 * OrderQuery tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\OrderQuery;
use Navexa\AgeCheck\Tests\TestCase;

final class OrderQueryTest extends TestCase {

	public function test_register(): void {
		OrderQuery::register();
		$this->assertNotFalse( has_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( OrderQuery::class, 'cpt_meta_query' ) ) );
	}

	protected function tearDown(): void {
		\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = false;
		parent::tearDown();
	}

	public function test_hpos_gets_the_meta_query_itself(): void {
		\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = true;
		$seen = null;
		Functions\when( 'wc_get_orders' )->alias(
			static function ( $args ) use ( &$seen ) {
				$seen = $args;
				return array();
			}
		);
		OrderQuery::ids( array( 'meta_query' => array( array( 'key' => 'k' ) ) ) );
		$this->assertSame( array( 'meta_query' => array( array( 'key' => 'k' ) ), 'return' => 'ids' ), $seen );
		$this->assertTrue( OrderQuery::hpos() );
	}

	public function test_ids_carry_the_meta_query_for_the_posts_table(): void {
		$meta = array(
			array(
				'key'   => Meta::REFUND,
				'value' => Meta::REFUND_MANUAL,
			),
		);
		$seen = null;
		Functions\when( 'wc_get_orders' )->alias(
			static function ( $args ) use ( &$seen ) {
				$seen = $args;
				return array( '7', new \WC_Order( 9 ) );
			}
		);
		$this->assertSame( array( 7, 9 ), OrderQuery::ids( array( 'limit' => 5, 'meta_query' => $meta ) ) );
		$this->assertSame(
			array(
				'limit'               => 5,
				OrderQuery::QUERY_VAR => $meta,
				'return'              => 'ids',
			),
			$seen,
			'the posts-table store gets no meta_query (WooCommerce 9.2+ warns), only the bridge var'
		);
	}

	public function test_ids_tolerate_odd_answers(): void {
		Functions\when( 'wc_get_orders' )->justReturn( null );
		$this->assertSame( array(), OrderQuery::ids( array() ) );
	}

	public function test_posts_table_bridge(): void {
		$meta = array(
			'relation' => 'OR',
			array(
				'key'     => Meta::STATUS,
				'compare' => 'NOT EXISTS',
			),
		);
		$wp   = OrderQuery::cpt_meta_query(
			array(
				'post_type'           => 'shop_order',
				'meta_query'          => array( array( 'key' => '_customer_user' ) ),
				OrderQuery::QUERY_VAR => $meta,
			),
			array( OrderQuery::QUERY_VAR => $meta )
		);
		$this->assertArrayNotHasKey( OrderQuery::QUERY_VAR, $wp );
		$this->assertSame( array( array( 'key' => '_customer_user' ), $meta ), $wp['meta_query'] );
		$this->assertSame( array( 'p' => 1 ), OrderQuery::cpt_meta_query( array( 'p' => 1 ), array() ) );
		$this->assertSame( 'x', OrderQuery::cpt_meta_query( 'x', array() ) );
	}
}
