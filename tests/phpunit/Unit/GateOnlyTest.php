<?php
/**
 * Gate-only mode (TAX-1875, WooCommerce part): age verification off, only the
 * entry age gate runs, no API key needed. Explicit only: a missing key in the
 * two verification modes still refuses and holds.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Admin\Notices;
use Navexa\AgeCheck\Checkout\Blocks;
use Navexa\AgeCheck\Checkout\Classic;
use Navexa\AgeCheck\Checkout\Gate;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Plugin;
use Navexa\AgeCheck\Requirement;
use Navexa\AgeCheck\Settings;
use Navexa\AgeCheck\Start;
use Navexa\AgeCheck\Tests\TestCase;

final class GateOnlyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->wire_order_hooks();
	}

	private function gate_only(): void {
		$this->options[ Options::MODE ] = Options::MODE_GATE_ONLY;
	}

	private function screen( string $id ): void {
		$screen     = new \WP_Screen();
		$screen->id = $id;
		\Brain\Monkey\Functions\when( 'get_current_screen' )->justReturn( $screen );
		\Brain\Monkey\Functions\when( 'wp_nonce_url' )->alias(
			static function ( $url ) {
				return $url . '&_wpnonce=n';
			}
		);
	}

	public function test_mode_reads_gate_only_explicitly_and_an_unknown_mode_fails_closed(): void {
		$this->assertSame( Options::MODE_PRE_PAYMENT, Options::mode(), 'new installs verify before payment' );
		$this->assertTrue( Options::verification_enabled(), 'no key is not gate-only: fail closed' );
		$this->gate_only();
		$this->assertSame( Options::MODE_GATE_ONLY, Options::mode() );
		$this->assertFalse( Options::verification_enabled() );
		$this->assertFalse( Gate::enforces() );
		$this->options[ Options::MODE ] = 'gate-only';
		$this->assertSame( Options::MODE_PRE_PAYMENT, Options::mode(), 'an unknown value reads as the strictest' );
	}

	public function test_nothing_is_required_and_nothing_is_held(): void {
		$this->gate_only();
		$order = $this->restricted_order();
		$this->assertSame(
			array(
				'required' => false,
				'reason'   => Requirement::REASON_VERIFICATION_OFF,
			),
			Requirement::evaluate( $order )
		);
		Hold::payment_starting( $order->get_id() );
		$this->assertSame( 'processing', Hold::payment_complete_status( 'processing', $order->get_id(), $order ), 'not held' );
		Hold::payment_finished( $order->get_id() );
		$this->assertSame( 'no', $order->meta[ Meta::REQUIRED ] );
		$this->assertSame( Requirement::REASON_VERIFICATION_OFF, $order->meta[ Meta::REQUIRED_REASON ] );
		$this->assertFalse( Requirement::cart_required( self::$wc->cart ) );
		$this->assertSame( array(), $this->http_requests, 'no AgeCheck call' );
	}

	public function test_an_order_held_before_the_switch_stays_held(): void {
		$this->install_with_keys();
		$order = $this->restricted_order();
		Hold::payment_starting( $order->get_id() );
		$order->set_status( Hold::payment_complete_status( 'processing', $order->get_id(), $order ) );
		$order->save();
		Hold::payment_finished( $order->get_id() );
		$this->assertSame( Hold::STATUS, $order->get_status() );

		$this->gate_only();
		$this->assertTrue( Requirement::is_required( $order ), 'required is sticky' );
		$order->update_status( 'processing', '', true );
		$this->assertSame( Hold::STATUS, $order->get_status(), 'the backstop still guards it' );
	}

	public function test_switching_back_re_evaluates_an_order_marked_not_required(): void {
		$this->gate_only();
		$order = $this->restricted_order();
		$this->assertFalse( Requirement::is_required( $order ) );
		unset( $this->options[ Options::MODE ] );
		$this->assertTrue( Requirement::is_required( $order ), 'a stored no is not final' );
	}

	public function test_checkouts_skip_and_express_buttons_are_not_hidden(): void {
		$this->gate_only();
		$this->customer_at( $this->us_address() );
		$errors = new \WP_Error();
		Classic::validate( array( 'billing_country' => 'US' ), $errors );
		$this->assertSame( array(), $errors->codes );
		$order = $this->restricted_order();
		Blocks::mark_cart_checkout( $order );
		Blocks::validate( $order, $errors );
		$this->assertSame( array(), $errors->codes );
		Blocks::reset();
		$this->assertFalse( Options::hide_express() );
		$this->assertSame( array( 'v' => 1, 'required' => false, 'status' => Start::STATUS_NONE ), Start::state( Gate::ROUTE_STORE_API ) );
		ob_start();
		Classic::render_status();
		Classic::render_lock_bar();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_no_test_mode_or_setup_notice_and_a_status_that_says_verification_is_off(): void {
		$this->screen( 'woocommerce_page_wc-orders' );
		ob_start();
		Notices::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'refuses age-restricted checkouts until you add your API keys', $html, 'verification on, no keys: the setup notice' );
		$this->assertStringContainsString( 'set Age verification to Off', $html );
		$this->assertStringContainsString( 'setup=keys', $html, 'dismissible off the settings tab' );

		$this->current_user = 5;
		$this->user_meta[ '5:' . Notices::USER_META_SETUP ] = Notices::SETUP_REASON;
		ob_start();
		Notices::render();
		$this->assertStringNotContainsString( 'until you add your API keys', ob_get_clean(), 'dismissed' );

		$this->screen( 'woocommerce_page_wc-settings' );
		$_GET['tab'] = Plugin::SETTINGS_TAB;
		ob_start();
		Notices::render();
		$html = ob_get_clean();
		unset( $_GET['tab'] );
		$this->assertStringContainsString( 'until you add your API keys', $html, 'always on the settings tab' );
		$this->assertStringNotContainsString( 'setup=keys', $html );

		$this->gate_only();
		$this->screen( 'woocommerce_page_wc-orders' );
		unset( $this->user_meta[ '5:' . Notices::USER_META_SETUP ] );
		ob_start();
		Notices::render();
		Mode::test_mode_notice();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'API keys', $html, 'gate-only users never see key nags' );
		$this->assertStringNotContainsString( 'TEST MODE', $html );

		$lines = ( new Settings() )->status_lines();
		$this->assertSame( 'warning', $lines[0]['level'] );
		$this->assertStringContainsString( 'Age verification is off', $lines[0]['text'] );
		$this->assertCount( 1, $lines, 'no key lines without keys, no network call' );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_in_after_payment_mode_the_setup_notice_says_orders_are_held_not_refused(): void {
		$this->options[ Options::MODE ] = Options::MODE_ACCEPT_AND_HOLD;
		$this->screen( 'woocommerce_page_wc-orders' );
		ob_start();
		Notices::render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'refuses age-restricted checkouts', $html, 'checkouts are not refused after payment' );
		$this->assertStringContainsString( 'places age-restricted orders on hold until they pass an age check, and it needs both API keys to check and release them', $html );
		$this->assertStringContainsString( 'set Age verification to Off', $html );
		$this->assertStringContainsString( 'setup=' . Notices::SETUP_REASON_HOLD, $html );

		$this->current_user = 5;
		$this->user_meta[ '5:' . Notices::USER_META_SETUP ] = Notices::SETUP_REASON_HOLD;
		ob_start();
		Notices::render();
		$this->assertStringNotContainsString( 'API keys', ob_get_clean(), 'dismissed in this mode' );

		$this->options[ Options::MODE ] = Options::MODE_PRE_PAYMENT;
		ob_start();
		Notices::render();
		$this->assertStringContainsString( 'refuses age-restricted checkouts until you add your API keys', ob_get_clean(), 'a switch to Before payment shows it again' );
	}

	public function test_setup_notice_needs_a_missing_key_and_verification_on(): void {
		$this->assertTrue( Notices::needs_setup() );
		$this->install_with_keys();
		$this->assertFalse( Notices::needs_setup() );
		unset( $this->options[ Options::FULFILMENT_KEY ] );
		$this->assertTrue( Notices::needs_setup(), 'either key missing' );
		$this->gate_only();
		$this->assertFalse( Notices::needs_setup() );
	}
}
