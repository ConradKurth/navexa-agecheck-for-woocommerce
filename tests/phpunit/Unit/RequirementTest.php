<?php
/**
 * Requirement tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Filters;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Requirement;
use Navexa\AgeCheck\Tests\TestCase;

final class RequirementTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
	}

	public function test_every_product_by_default_and_the_answer_is_recorded(): void {
		$order = $this->restricted_order();
		$this->assertTrue( Requirement::is_required( $order ) );
		$this->assertSame( 'yes', $order->meta[ Meta::REQUIRED ] );
	}

	public function test_required_is_sticky(): void {
		$order = $this->restricted_order();
		Requirement::is_required( $order );
		$this->options[ Options::EXEMPT_ROLES ] = array( 'customer' );
		$order->data['customer_id']             = 5;
		$this->users[5]                         = new \WP_User( 5, array( 'customer' ) );
		$this->assertTrue( Requirement::is_required( $order ), 'a rule change never releases an order that needed a check' );
	}

	public function test_no_product_lines_is_not_required_and_says_why(): void {
		$order = \WC_Order::make();
		$this->assertFalse( Requirement::is_required( $order ) );
		$this->assertSame( 'no', $order->meta[ Meta::REQUIRED ] );
		$this->assertSame( Requirement::REASON_NO_RESTRICTED, $order->meta[ Meta::REQUIRED_REASON ] );

		$order->add_test_item( new \WC_Order_Item_Product( new \WC_Product( 7 ) ) );
		$this->assertTrue( Requirement::is_required( $order ), 'a "no" is re-evaluated' );
		$this->assertArrayNotHasKey( Meta::REQUIRED_REASON, $order->meta );
	}

	public function test_a_deleted_product_requires_a_check(): void {
		$this->options[ Options::RULE ] = Options::RULE_INCLUDE;
		$order                          = \WC_Order::make();
		$order->add_test_item( new \WC_Order_Item_Product( null ) );
		$this->assertTrue( Requirement::is_required( $order ) );
	}

	public function test_local_pickup_skips_the_check(): void {
		$order = \WC_Order::make();
		$order->add_test_item( new \WC_Order_Item_Product( new \WC_Product( 7 ) ) );
		$order->add_test_item( new \WC_Order_Item_Shipping( 'local_pickup' ) );
		$this->assertFalse( Requirement::is_required( $order ) );
		$this->assertSame( Requirement::REASON_PICKUP, $order->meta[ Meta::REQUIRED_REASON ] );

		$blocks = \WC_Order::make();
		$blocks->add_test_item( new \WC_Order_Item_Product( new \WC_Product( 7 ) ) );
		$blocks->add_test_item( new \WC_Order_Item_Shipping( 'pickup_location' ) );
		$this->assertTrue( Requirement::is_pickup( $blocks ), 'the checkout block pickup method' );

		$order->add_test_item( new \WC_Order_Item_Shipping( 'flat_rate' ) );
		$this->assertTrue( Requirement::is_required( $order ), 'one shipped package makes it a shipping order' );
	}

	public function test_pickup_methods_are_filterable(): void {
		Filters\expectApplied( 'navexa_agecheck_pickup_methods' )->andReturn( array( 'store_collect' ) );
		$order = \WC_Order::make();
		$order->add_test_item( new \WC_Order_Item_Shipping( 'store_collect' ) );
		$this->assertTrue( Requirement::is_pickup( $order ) );
		$this->assertFalse( Requirement::is_pickup( \WC_Order::make() ), 'no shipping lines is not pickup' );
	}

	public function test_the_cart_follows_the_same_rules_before_an_order_exists(): void {
		$cart = new \WC_Cart();
		$this->assertTrue( Requirement::cart_required( $cart ), 'rule: every product' );

		$cart->items = array( 'a' => array( 'data' => new \WC_Product( 8, 0, array( Requirement::PRODUCT_META => Requirement::PRODUCT_EXEMPT ) ) ) );
		$this->assertFalse( Requirement::cart_required( $cart ), 'an exempt product' );
		$cart->items['b'] = array( 'data' => null );
		$this->assertTrue( Requirement::cart_required( $cart ), 'an item without a product counts as restricted' );

		$cart = new \WC_Cart();
		$this->options[ Options::EXEMPT_ROLES ] = array( 'wholesale' );
		$this->users[5]                         = new \WP_User( 5, array( 'wholesale' ) );
		$this->current_user                     = 5;
		$this->assertFalse( Requirement::cart_required( $cart ), 'the signed-in shopper is the order\'s customer' );
		$this->current_user = 0;
		$this->assertTrue( Requirement::cart_required( $cart ), 'guests are never exempt' );

		$cart->empty = true;
		$this->assertFalse( Requirement::cart_required( $cart ) );
	}

	public function test_exempt_role_of_the_orders_customer(): void {
		$this->options[ Options::EXEMPT_ROLES ] = array( 'wholesale' );
		$this->users[5]                         = new \WP_User( 5, array( 'wholesale' ) );
		$this->users[6]                         = new \WP_User( 6, array( 'customer' ) );

		$order                      = $this->restricted_order();
		$order->data['customer_id'] = 5;
		$this->assertFalse( Requirement::is_required( $order ) );
		$this->assertSame( Requirement::REASON_EXEMPT_ROLE, $order->meta[ Meta::REQUIRED_REASON ] );

		$other                      = $this->restricted_order();
		$other->data['customer_id'] = 6;
		$this->assertTrue( Requirement::is_required( $other ) );

		$guest = $this->restricted_order();
		$this->assertTrue( Requirement::is_required( $guest ), 'guests are never exempt' );

		$ghost                      = $this->restricted_order();
		$ghost->data['customer_id'] = 99;
		$this->assertTrue( Requirement::is_required( $ghost ), 'a deleted user is not exempt' );
	}

	public function test_orders_from_before_activation_are_left_alone(): void {
		$this->options[ Install::OPTION_ACTIVATED_AT ] = 2000000000;
		$order                                         = $this->restricted_order();
		$order->data['date_created']                   = new \DateTimeImmutable( '@1900000000' );
		$this->assertFalse( Requirement::is_required( $order ) );
		$this->assertSame( Requirement::REASON_BEFORE_ACTIVATION, $order->meta[ Meta::REQUIRED_REASON ] );

		$order->data['date_created'] = new \DateTimeImmutable( '@2000000001' );
		$this->assertTrue( Requirement::is_required( $order ) );

		$this->options[ Install::OPTION_ACTIVATED_AT ] = 0;
		$old                                           = $this->restricted_order();
		$old->data['date_created']                     = new \DateTimeImmutable( '@1' );
		$this->assertTrue( Requirement::is_required( $old ), 'an unknown activation time skips nothing' );
	}

	public function test_include_rule_by_category_tag_and_subcategory(): void {
		$this->options[ Options::RULE ]            = Options::RULE_INCLUDE;
		$this->options[ Options::RULE_CATEGORIES ] = array( 11 );
		$this->options[ Options::RULE_TAGS ]       = array( 40 );
		$this->product_terms[1]                    = array( 'product_cat' => array( 11 ) );
		$this->product_terms[2]                    = array( 'product_cat' => array( 12 ) ); // Child of 11.
		$this->product_terms[3]                    = array( 'product_tag' => array( 40 ) );
		$this->product_terms[4]                    = array( 'product_cat' => array( 99 ) );

		$this->assertTrue( Requirement::product_requires( new \WC_Product( 1 ) ) );
		$this->assertTrue( Requirement::product_requires( new \WC_Product( 2 ) ), 'a chosen category covers its subcategories' );
		$this->assertTrue( Requirement::product_requires( new \WC_Product( 3 ) ) );
		$this->assertFalse( Requirement::product_requires( new \WC_Product( 4 ) ) );
		$this->assertTrue( Requirement::product_requires( new \WC_Product( 50, 1 ) ), 'a variation follows its parent\'s terms' );
	}

	public function test_exclude_rule(): void {
		$this->options[ Options::RULE ]            = Options::RULE_EXCLUDE;
		$this->options[ Options::RULE_CATEGORIES ] = array( 11 );
		$this->product_terms[1]                    = array( 'product_cat' => array( 11 ) );
		$this->assertFalse( Requirement::product_requires( new \WC_Product( 1 ) ) );
		$this->assertTrue( Requirement::product_requires( new \WC_Product( 4 ) ) );

		$this->options[ Options::RULE_CATEGORIES ] = array();
		$this->assertTrue( Requirement::product_requires( new \WC_Product( 1 ) ), 'excluding nothing requires everything' );
	}

	public function test_product_setting_overrides_the_rule_and_variations_inherit_it(): void {
		$this->options[ Options::RULE ] = Options::RULE_INCLUDE;
		$this->assertTrue( Requirement::product_requires( new \WC_Product( 1, 0, array( Requirement::PRODUCT_META => 'require' ) ) ) );

		$this->options[ Options::RULE ] = Options::RULE_ALL;
		$this->assertFalse( Requirement::product_requires( new \WC_Product( 1, 0, array( Requirement::PRODUCT_META => 'exempt' ) ) ) );
		$this->assertTrue( Requirement::product_requires( new \WC_Product( 1, 0, array( Requirement::PRODUCT_META => 'bogus' ) ) ) );

		$this->products[30] = new \WC_Product( 30, 0, array( Requirement::PRODUCT_META => 'exempt' ) );
		$this->assertFalse( Requirement::product_requires( new \WC_Product( 31, 30 ) ), 'a variation inherits its parent\'s setting' );
		$this->assertTrue( Requirement::product_requires( new \WC_Product( 32, 30, array( Requirement::PRODUCT_META => 'require' ) ) ), 'its own setting wins' );
		$this->assertTrue( Requirement::product_requires( new \WC_Product( 33, 404 ) ), 'a missing parent falls back to the rule' );
	}

	public function test_an_unreadable_order_counts_as_required(): void {
		$order = new class( 5 ) extends \WC_Order {
			public function get_items( $types = 'line_item' ) {
				throw new \RuntimeException( 'database gone' );
			}
		};
		$this->assertSame(
			array(
				'required' => true,
				'reason'   => Requirement::REASON_REQUIRED,
			),
			Requirement::evaluate( $order )
		);
		$this->assertStringContainsString( 'treated as required', $this->all_logs() );
	}
}
