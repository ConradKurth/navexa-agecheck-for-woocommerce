<?php
/**
 * Orders list filter and admin notice tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Admin\Notices;
use Navexa\AgeCheck\Admin\OrdersFilter;
use Navexa\AgeCheck\Admin\ProductField;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Requirement;
use Navexa\AgeCheck\Tests\TestCase;

final class OrdersFilterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->is_admin     = true;
		$this->current_user = 3;
		$_GET               = array();
		$_POST              = array();
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value, $url ) {
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value;
			}
		);
		Functions\when( 'selected' )->alias(
			static function ( $a, $b, $echo = true ) {
				return (string) $a === (string) $b ? ' selected="selected"' : '';
			}
		);
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		unset( $GLOBALS['typenow'] );
		parent::tearDown();
	}

	public function test_register(): void {
		OrdersFilter::register();
		$this->assertNotFalse( has_action( 'woocommerce_order_list_table_restrict_manage_orders', array( OrdersFilter::class, 'render_hpos' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( OrdersFilter::class, 'filter_hpos_args' ) ) );
		$this->assertNotFalse( has_action( 'restrict_manage_posts', array( OrdersFilter::class, 'render_posts' ) ) );
		$this->assertNotFalse( has_filter( 'request', array( OrdersFilter::class, 'filter_posts_request' ) ) );
	}

	public function test_dropdown_on_both_screens(): void {
		$_GET[ OrdersFilter::PARAM ] = OrdersFilter::DENIED;
		ob_start();
		OrdersFilter::render_hpos( 'shop_order' );
		OrdersFilter::render_posts( 'shop_order' );
		OrdersFilter::render_hpos( 'shop_subscription' );
		OrdersFilter::render_posts( 'page' );
		$html = ob_get_clean();
		$this->assertSame( 2, substr_count( $html, '<select name="navexa_agecheck"' ) );
		$this->assertStringContainsString( 'value="denied_refund" selected="selected">AgeCheck: denied, refund required', $html );
	}

	public function test_awaiting_and_denied_queries_find_the_right_orders(): void {
		$awaiting                       = \WC_Order::make( Hold::STATUS );
		$denied                         = \WC_Order::make( Hold::STATUS );
		$denied->meta[ Meta::STATUS ]   = Meta::STATUS_DENIED;
		$denied->meta[ Meta::REFUND ]   = Meta::REFUND_MANUAL;
		$resolved                       = \WC_Order::make( 'cancelled' );
		$resolved->meta[ Meta::REFUND ] = Meta::REFUND_RESOLVED;
		\WC_Order::make( 'processing' );

		$_GET[ OrdersFilter::PARAM ] = OrdersFilter::AWAITING;
		$args                        = OrdersFilter::filter_hpos_args( array( 'limit' => 20, 'return' => 'ids' ) );
		$this->assertSame( array( 'wc-agecheck-hold' ), $args['status'] );
		$this->assertSame( array( $awaiting->get_id() ), $this->query_orders( $args ) );

		$_GET[ OrdersFilter::PARAM ] = OrdersFilter::DENIED;
		$args                        = OrdersFilter::filter_hpos_args( array( 'limit' => 20, 'return' => 'ids' ) );
		$this->assertSame( array( $denied->get_id() ), $this->query_orders( $args ) );

		$_GET[ OrdersFilter::PARAM ] = 'bogus';
		$this->assertSame( array( 'limit' => 1 ), OrdersFilter::filter_hpos_args( array( 'limit' => 1 ) ) );
	}

	public function test_posts_screen_request_vars(): void {
		$_GET[ OrdersFilter::PARAM ] = OrdersFilter::AWAITING;
		$GLOBALS['typenow']          = 'shop_order';
		$vars                        = OrdersFilter::filter_posts_request( array( 'meta_query' => array( array( 'key' => 'x' ) ) ) );
		$this->assertSame( array( 'wc-agecheck-hold' ), $vars['post_status'] );
		$this->assertCount( 2, $vars['meta_query'], 'existing conditions are kept' );

		$GLOBALS['typenow'] = 'post';
		$this->assertSame( array( 'p' => 1 ), OrdersFilter::filter_posts_request( array( 'p' => 1 ) ) );
	}

	public function test_url(): void {
		$this->assertSame( 'https://shop.example/wp-admin/edit.php?post_type=shop_order&navexa_agecheck=awaiting', OrdersFilter::url( OrdersFilter::AWAITING ) );
	}

	public function test_counts_notice_and_dismissal(): void {
		$denied                       = \WC_Order::make( Hold::STATUS );
		$denied->meta[ Meta::REFUND ] = Meta::REFUND_MANUAL;
		$old                          = \WC_Order::make( Hold::STATUS );
		$old->meta[ Meta::HELD_AT ]   = time() - 40 * 86400;
		$new                          = \WC_Order::make( Hold::STATUS );
		$new->meta[ Meta::HELD_AT ]   = time();
		$screen                       = new \WP_Screen();
		$screen->id                   = 'woocommerce_page_wc-orders';
		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( 'wp_nonce_url' )->alias(
			static function ( $url ) {
				return $url . '&_wpnonce=n';
			}
		);

		$this->assertSame( array( 'denied' => 1, 'stale' => 1 ), Notices::counts() );
		ob_start();
		Notices::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( '1 order failed its age check and needs a refund or cancellation.', $html );
		$this->assertStringContainsString( '1 order has waited more than 30 days', $html );
		$this->assertStringContainsString( 'counts=1%3A1', $html );

		$this->user_meta[ '3:' . Notices::USER_META ] = '1:1';
		ob_start();
		Notices::render();
		$this->assertSame( '', ob_get_clean(), 'dismissed until the counts change' );

		$screen->id = 'edit-post';
		$this->user_meta = array();
		ob_start();
		Notices::render();
		$this->assertSame( '', ob_get_clean(), 'only on WooCommerce screens' );
	}

	public function test_the_link_refusal_notice_shows_on_woocommerce_screens_while_recent(): void {
		$screen     = new \WP_Screen();
		$screen->id = 'woocommerce_page_wc-orders';
		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( 'wp_nonce_url' )->alias(
			static function ( $url ) {
				return $url . '&_wpnonce=n';
			}
		);
		$this->options[ \Navexa\AgeCheck\Links::OPTION_BLOCK ] = array(
			'reason' => \Navexa\AgeCheck\Links::STATE_LINKS_OFF,
			'at'     => time(),
		);
		ob_start();
		Notices::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'AgeCheck is not sending verification emails yet', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'links=links_off', $html, 'dismissible' );

		$this->user_meta[ '3:' . Notices::USER_META_LINKS ] = 'links_off';
		ob_start();
		Notices::render();
		$this->assertSame( '', ob_get_clean(), 'dismissed until the reason changes' );

		$this->options[ \Navexa\AgeCheck\Links::OPTION_BLOCK ]['reason'] = \Navexa\AgeCheck\Links::STATE_NO_DISPLAY_NAME;
		ob_start();
		Notices::render();
		$this->assertStringContainsString( 'set your store name in the AgeCheck dashboard', ob_get_clean() );

		$screen->id = 'edit-post';
		ob_start();
		Notices::render();
		$this->assertSame( '', ob_get_clean(), 'only on WooCommerce screens' );

		$screen->id = 'dashboard';
		$this->caps = array();
		ob_start();
		Notices::render();
		$this->assertSame( '', ob_get_clean(), 'only for shop managers' );
	}

	public function test_flash_notices_are_shown_once_and_escaped(): void {
		Notices::flash( 'Kept <b>held</b>' );
		Functions\when( 'get_current_screen' )->justReturn( null );
		ob_start();
		Notices::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Kept &lt;b&gt;held&lt;/b&gt;', $html );
		ob_start();
		Notices::render();
		$this->assertSame( '', ob_get_clean() );

		$this->is_admin = false;
		Notices::flash( 'front end' );
		$this->assertArrayNotHasKey( Notices::FLASH_PREFIX . '3', $this->transients );
	}

	public function test_dismiss_handler_checks_capability_and_nonce(): void {
		Functions\expect( 'check_admin_referer' )->once()->with( Notices::DISMISS_ACTION );
		Functions\when( 'wp_get_referer' )->justReturn( false );
		Functions\expect( 'wp_safe_redirect' )->once()->andThrow( new \RuntimeException( 'redirect' ) );
		$_GET['counts'] = '2:0';
		try {
			Notices::handle_dismiss();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}
		$this->assertSame( '2:0', $this->user_meta[ '3:' . Notices::USER_META ] );
	}

	public function test_dismiss_handler_refuses_non_managers(): void {
		$this->caps = array();
		Functions\expect( 'wp_die' )->once()->andThrow( new \RuntimeException( 'die' ) );
		$this->expectExceptionMessage( 'die' );
		Notices::handle_dismiss();
	}

	public function test_product_field_saves_only_known_values(): void {
		ProductField::register();
		$this->assertNotFalse( has_action( 'woocommerce_admin_process_product_object', array( ProductField::class, 'save' ) ) );
		$this->caps['edit_product'] = true;
		$product                    = new \WC_Product( 7 );
		$_POST[ Requirement::PRODUCT_META ] = 'require';
		ProductField::save( $product );
		$this->assertSame( 'require', $product->meta[ Requirement::PRODUCT_META ] );
		$_POST[ Requirement::PRODUCT_META ] = 'bogus';
		ProductField::save( $product );
		$this->assertSame( 'require', $product->meta[ Requirement::PRODUCT_META ] );
		$_POST[ Requirement::PRODUCT_META ] = '';
		ProductField::save( $product );
		$this->assertArrayNotHasKey( Requirement::PRODUCT_META, $product->meta );

		$this->caps                         = array();
		$_POST[ Requirement::PRODUCT_META ] = 'exempt';
		ProductField::save( $product );
		$this->assertArrayNotHasKey( Requirement::PRODUCT_META, $product->meta );

		Functions\expect( 'woocommerce_wp_select' )->once()->with( \Mockery::on( static function ( $args ) {
			return Requirement::PRODUCT_META === $args['id'] && array( '', 'require', 'exempt' ) === array_keys( $args['options'] );
		} ) );
		ob_start();
		ProductField::render();
		ob_end_clean();
	}
}
