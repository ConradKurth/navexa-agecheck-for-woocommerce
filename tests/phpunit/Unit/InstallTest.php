<?php
/**
 * Install and clone-detection tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Plugin;
use Navexa\AgeCheck\Tests\TestCase;

final class InstallTest extends TestCase {

	public function test_activation_creates_the_install_state(): void {
		$before = time();
		Install::activate();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $this->options[ Install::OPTION_INSTALL_ID ], '128-bit install id' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $this->options[ Install::OPTION_GUARD_SECRET ], '32-byte guard secret' );
		$this->assertSame( 32, strlen( Install::guard_secret() ) );
		$this->assertGreaterThanOrEqual( $before, Install::activated_at() );
		$this->assertSame( Install::home_fingerprint(), $this->options[ Install::OPTION_HOME_FINGERPRINT ] );
		$this->assertSame( NAVEXA_AGECHECK_VERSION, $this->options[ Install::OPTION_VERSION ] );

		$this->assertFalse( $this->autoload[ Install::OPTION_GUARD_SECRET ], 'the secret is never autoloaded' );
		$this->assertTrue( $this->autoload[ Install::OPTION_INSTALL_ID ] );
		$this->assertTrue( $this->autoload[ Install::OPTION_HOME_FINGERPRINT ] );
		$this->assertFalse( Install::is_paused() );
	}

	public function test_reactivation_keeps_the_existing_state(): void {
		Install::activate();
		$first = $this->options;
		$this->options[ Install::OPTION_ACTIVATED_AT ] = 1000;

		Install::activate( true );
		Install::ensure();

		$this->assertSame( $first[ Install::OPTION_INSTALL_ID ], $this->options[ Install::OPTION_INSTALL_ID ] );
		$this->assertSame( $first[ Install::OPTION_GUARD_SECRET ], $this->options[ Install::OPTION_GUARD_SECRET ] );
		$this->assertSame( 1000, Install::activated_at() );
	}

	public function test_two_installs_get_different_ids_and_secrets(): void {
		Install::ensure();
		$first         = $this->options;
		$this->options = array();
		Install::ensure();
		$this->assertNotSame( $first[ Install::OPTION_INSTALL_ID ], $this->options[ Install::OPTION_INSTALL_ID ] );
		$this->assertNotSame( $first[ Install::OPTION_GUARD_SECRET ], $this->options[ Install::OPTION_GUARD_SECRET ] );
	}

	public function test_uninitialized_site_is_paused(): void {
		$this->assertTrue( Install::is_paused() );
		$this->assertSame( '', Install::install_id() );
		$this->assertSame( '', Install::guard_secret() );
	}

	/**
	 * @dataProvider moved_sites
	 */
	public function test_site_address_change_pauses( string $new_home, bool $paused ): void {
		$this->home = 'https://shop.example/store';
		Install::ensure();
		$this->home = $new_home;
		$this->assertSame( $paused, Install::is_paused() );
	}

	public function moved_sites(): array {
		return array(
			'same'               => array( 'https://shop.example/store', false ),
			'trailing slash'     => array( 'https://shop.example/store/', false ),
			'case'               => array( 'https://SHOP.example/Store', false ),
			'http to https move' => array( 'http://shop.example/store', false ),
			'staging host'       => array( 'https://staging.shop.example/store', true ),
			'other port'         => array( 'https://shop.example:8443/store', true ),
			'other path'         => array( 'https://shop.example/', true ),
			'local copy'         => array( 'http://localhost:8888/store', true ),
		);
	}

	public function test_a_filtered_home_url_does_not_pause(): void {
		// TranslatePress and WPML filter home_url() on front-end requests only,
		// e.g. adding /es for a Spanish shopper. The home option is unchanged.
		Install::ensure();
		Functions\when( 'home_url' )->justReturn( 'https://shop.example/es' );
		$this->assertFalse( Install::is_paused() );
		$this->assertSame( 'https://shop.example', Mode::store_origin() );
	}

	public function test_other_blog_id_pauses(): void {
		Install::ensure();
		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		$this->assertTrue( Install::is_paused() );
	}

	public function test_missing_or_corrupt_state_pauses(): void {
		Install::ensure();
		$this->options[ Install::OPTION_HOME_FINGERPRINT ] = '';
		$this->assertTrue( Install::is_paused() );

		Install::ensure();
		$this->options[ Install::OPTION_HOME_FINGERPRINT ] = Install::home_fingerprint();
		$this->options[ Install::OPTION_INSTALL_ID ]       = 'not-hex';
		$this->assertTrue( Install::is_paused() );

		$this->options[ Install::OPTION_INSTALL_ID ]   = str_repeat( 'a', 32 );
		$this->options[ Install::OPTION_GUARD_SECRET ] = 'short';
		$this->assertFalse( Install::is_paused() );
		$this->assertSame( '', Install::guard_secret(), 'callers fail closed on an empty secret' );
	}

	public function test_confirm_rotates_the_install_id_and_secret_and_unpauses(): void {
		Install::ensure();
		$this->options[ Install::OPTION_ACTIVATED_AT ] = 1000;
		$old        = $this->options;
		$this->home = 'https://staging.shop.example';
		$this->assertTrue( Install::is_paused() );

		$this->assertTrue( Install::confirm_site() );

		$this->assertFalse( Install::is_paused() );
		$this->assertNotSame( $old[ Install::OPTION_INSTALL_ID ], Install::install_id() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', Install::install_id() );
		$this->assertNotSame( $old[ Install::OPTION_GUARD_SECRET ], $this->options[ Install::OPTION_GUARD_SECRET ] );
		$this->assertSame( 32, strlen( Install::guard_secret() ) );
		$this->assertFalse( $this->autoload[ Install::OPTION_GUARD_SECRET ] );
		$this->assertSame( 1000, Install::activated_at(), 'activated_at is kept' );
	}

	public function test_confirm_does_nothing_on_a_site_that_is_not_paused(): void {
		Install::ensure();
		$id = Install::install_id();
		$this->assertFalse( Install::confirm_site() );
		$this->assertSame( $id, Install::install_id() );
	}

	public function test_confirm_initializes_a_site_with_stale_partial_state(): void {
		$this->options[ Install::OPTION_HOME_FINGERPRINT ] = 'stale';
		$this->assertTrue( Install::confirm_site() );
		$this->assertFalse( Install::is_paused() );
		$this->assertNotSame( 0, Install::activated_at() );
	}

	public function test_confirm_handler_needs_the_capability(): void {
		$this->caps = array(
			'edit_posts'       => true,
			'activate_plugins' => true,
		);
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
		Functions\expect( 'check_admin_referer' )->never();
		$this->expectExceptionMessage( 'wp_die' );
		Install::handle_confirm();
	}

	public function test_confirm_handler_refuses_a_bad_nonce_before_rotating(): void {
		$this->caps = array( 'manage_woocommerce' => true );
		Install::ensure();
		$this->home = 'https://staging.shop.example';
		$id         = Install::install_id();
		$secret     = $this->options[ Install::OPTION_GUARD_SECRET ];
		// check_admin_referer() ends the request through wp_die() on a bad nonce.
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( Install::CONFIRM_ACTION )
			->andThrow( new \RuntimeException( 'bad nonce' ) );
		// Throws rather than returning, so a regression cannot reach exit.
		Functions\when( 'wp_safe_redirect' )->alias(
			static function () {
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			Install::handle_confirm();
			$this->fail( 'a bad nonce must stop the handler' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'bad nonce', $e->getMessage() );
		}
		$this->assertSame( $id, Install::install_id() );
		$this->assertSame( $secret, $this->options[ Install::OPTION_GUARD_SECRET ] );
		$this->assertTrue( Install::is_paused() );
	}

	public function test_confirm_handler_rotates_and_redirects_to_the_settings_tab(): void {
		$this->caps = array( 'manage_woocommerce' => true );
		Install::ensure();
		$this->home = 'https://staging.shop.example';
		$id         = Install::install_id();
		Functions\expect( 'check_admin_referer' )->once()->with( Install::CONFIRM_ACTION )->andReturn( 1 );
		// handle_confirm() exits after the redirect; throw to get back here.
		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( Plugin::settings_url() )
			->andThrow( new \RuntimeException( 'redirected' ) );

		try {
			Install::handle_confirm();
			$this->fail( 'the handler must redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}
		$this->assertNotSame( $id, Install::install_id() );
		$this->assertFalse( Install::is_paused() );
	}

	public function test_paused_notice_offers_a_nonced_post_form(): void {
		Install::ensure();
		$this->home = 'https://staging.shop.example';
		ob_start();
		Install::paused_notice();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Confirm this site', $html );
		$this->assertStringContainsString( 'method="post"', $html );
		$this->assertStringContainsString( 'value="' . Install::CONFIRM_ACTION . '"', $html );
		$this->assertStringContainsString( 'nonce-' . Install::CONFIRM_ACTION, $html );
	}

	public function test_paused_notice_is_silent_when_not_paused_or_not_allowed(): void {
		Install::ensure();
		ob_start();
		Install::paused_notice();
		$this->assertSame( '', ob_get_clean() );

		$this->home = 'https://staging.shop.example';
		$this->caps = array();
		ob_start();
		Install::paused_notice();
		$this->assertSame( '', ob_get_clean() );
	}
}
