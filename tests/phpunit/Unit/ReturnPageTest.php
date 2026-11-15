<?php
/**
 * ReturnPage tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\ReturnPage;
use Navexa\AgeCheck\Tests\TestCase;

final class ReturnPageTest extends TestCase {

	protected function tearDown(): void {
		unset( $_GET['popup'] );
		parent::tearDown();
	}

	public function test_url_is_on_the_stores_own_address(): void {
		$this->assertSame( 'https://shop.example/?wc-api=navexa_agecheck_return', ReturnPage::url() );
		$this->assertSame( 'https://shop.example/?wc-api=navexa_agecheck_return&popup=1', ReturnPage::url( true ) );
		$this->home = 'https://shop.example/store/';
		$this->assertSame( 'https://shop.example/store/?wc-api=navexa_agecheck_return&popup=1', ReturnPage::url( true ) );
	}

	public function test_page_signals_the_checkout_tab_and_tries_to_close(): void {
		$html = ReturnPage::html( 'https://shop.example/checkout/?a=1&b="2"' );
		$this->assertStringContainsString( 'new BroadcastChannel("navexa-agecheck")', $html );
		$this->assertStringContainsString( '{type:"navexa-agecheck",v:1,event:"return"}', $html );
		$this->assertStringContainsString( 'localStorage.setItem("navexa-agecheck-return"', $html );
		$this->assertStringContainsString( 'window.close()', $html );
		$this->assertStringContainsString( 'sessionStorage.getItem("navexa-agecheck-redirect")', $html );
		$this->assertStringContainsString( 'You can close this window', $html );
		$this->assertStringContainsString( 'Return to checkout', $html );
		$this->assertStringContainsString( '<meta name="robots" content="noindex,nofollow">', $html );
		$this->assertStringContainsString( 'var checkout="https://shop.example/checkout/?a=1\\u0026b=\\u00222\\u0022";', $html, 'JSON-escaped inside the script' );
		$this->assertStringNotContainsString( 'acv_', $html );
	}

	public function test_without_popup_the_page_redirects_to_checkout(): void {
		Functions\expect( 'wp_safe_redirect' )->once()->with( 'https://shop.example/checkout/' )->andThrow( new \RuntimeException( 'redirected' ) );
		$this->expectExceptionMessage( 'redirected' );
		ReturnPage::render();
	}
}
