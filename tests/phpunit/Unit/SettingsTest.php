<?php
/**
 * Settings tab tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Plugin;
use Navexa\AgeCheck\Settings;
use Navexa\AgeCheck\Tests\TestCase;

final class SettingsTest extends TestCase {

	/** @var Settings */
	private $page;

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->page = new Settings();
		$_POST      = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	public function test_tab_and_fields(): void {
		$this->assertSame( Plugin::SETTINGS_TAB, $this->page->get_id() );
		$this->assertSame( 'AgeCheck', $this->page->get_label() );

		$fields = array();
		foreach ( $this->page->get_settings_for_section( '' ) as $field ) {
			$fields[ $field['id'] . ':' . $field['type'] ] = $field;
		}
		foreach ( array( Options::START_KEY, Options::FULFILMENT_KEY ) as $key ) {
			$field = $fields[ $key . ':' . Settings::FIELD_SECRET ];
			$this->assertFalse( $field['autoload'] );
		}
		$mode = $fields[ Options::MODE . ':select' ];
		$this->assertSame( Options::MODE_PRE_PAYMENT, $mode['default'] );
		$this->assertSame( array( Options::MODE_PRE_PAYMENT, Options::MODE_ACCEPT_AND_HOLD ), array_keys( $mode['options'] ) );
		$this->assertFalse( $fields[ 'navexa_agecheck_status:' . Settings::FIELD_STATUS ]['is_option'] );
		$this->assertStringContainsString( 'US addresses only', $fields['navexa_agecheck_section:title']['desc'] );
	}

	public function test_no_field_uses_a_type_the_wc_rest_settings_api_exposes(): void {
		foreach ( $this->page->get_settings_for_section( '' ) as $field ) {
			if ( in_array( $field['id'], array( Options::START_KEY, Options::FULFILMENT_KEY ), true ) ) {
				$this->assertNotContains( $field['type'], array( 'text', 'password', 'textarea' ) );
			}
		}
	}

	public function test_blank_submission_keeps_the_saved_key(): void {
		$this->assertNull( $this->sanitize( Options::START_KEY, '' ) );
		$this->assertSame( self::LIVE_START_KEY, $this->options[ Options::START_KEY ] );
	}

	public function test_new_key_is_saved_without_autoload_and_drops_the_cache(): void {
		$this->respond( 200, $this->live_info( array( 'livemode' => false ) ) );
		Mode::info( Client::KEY_FULFILMENT );
		$this->assertCount( 1, $this->transients );
		$this->assertTrue( Mode::is_test_mode() );
		$new = 'txk_' . str_repeat( 'ab', 32 );

		$this->assertNull( $this->sanitize( Options::FULFILMENT_KEY, "  {$new}\n" ), 'WooCommerce must not save it again' );

		$this->assertSame( $new, $this->options[ Options::FULFILMENT_KEY ] );
		$this->assertFalse( $this->autoload[ Options::FULFILMENT_KEY ] );
		$this->assertSame( array(), $this->transients, 'the old key\'s cached answer is gone' );
		$this->assertArrayNotHasKey( Mode::OPTION_LAST_MODE_PREFIX . Client::KEY_FULFILMENT, $this->options );
		$this->assertFalse( Mode::is_test_mode() );
	}

	public function test_malformed_key_is_refused_and_the_saved_key_kept(): void {
		$this->assertNull( $this->sanitize( Options::START_KEY, 'sk_live_123' ) );
		$this->assertSame( self::LIVE_START_KEY, $this->options[ Options::START_KEY ] );
		$this->assertCount( 1, \WC_Admin_Settings::$errors );
	}

	public function test_remove_checkbox_deletes_the_key(): void {
		$_POST[ Options::START_KEY . '_clear' ] = '1';
		$this->assertNull( $this->sanitize( Options::START_KEY, '' ) );
		$this->assertArrayNotHasKey( Options::START_KEY, $this->options );
	}

	public function test_other_options_pass_through(): void {
		$this->assertSame( 'x', $this->page->sanitize_secret( 'x', array( 'id' => 'woocommerce_other' ), 'x' ) );
	}

	public function test_key_field_never_prints_the_key(): void {
		ob_start();
		$this->page->output_secret_field(
			array(
				'id'    => Options::START_KEY,
				'title' => 'Start key',
				'desc'  => 'desc',
			)
		);
		$html = ob_get_clean();

		$this->assertStringNotContainsString( self::LIVE_START_KEY, $html );
		$this->assertStringNotContainsString( substr( self::LIVE_START_KEY, 0, 12 ), $html );
		$this->assertStringContainsString( 'txk_1111…', $html );
		$this->assertStringContainsString( 'type="password" value=""', $html );
		$this->assertStringContainsString( Options::START_KEY . '_clear', $html );
	}

	public function test_key_field_without_a_saved_key(): void {
		unset( $this->options[ Options::START_KEY ] );
		ob_start();
		$this->page->output_secret_field( array( 'id' => Options::START_KEY ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'No key saved.', $html );
		$this->assertStringNotContainsString( '_clear', $html );
	}

	public function test_status_for_a_healthy_live_setup(): void {
		$this->respond( 200, $this->live_info( array( 'scopes' => array( 'agecheck:verify' ) ) ) );
		$this->respond( 200, $this->live_info() );

		$lines = $this->page->status_lines();

		$this->assertSame( array( 'ok' ), array_values( array_unique( array_column( $lines, 'level' ) ) ), print_r( $lines, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		$text = implode( "\n", array_column( $lines, 'text' ) );
		$this->assertStringContainsString( 'Start key: live', $text );
		$this->assertStringContainsString( 'Fulfilment key: live', $text );
		$this->assertStringContainsString( 'https://shop.example is an allowed origin', $text );
		$this->assertStringContainsString( 'Minimum age: 21', $text );
	}

	public function test_status_flags_missing_scope_mixed_modes_and_origin(): void {
		$this->respond( 200, $this->live_info( array( 'livemode' => false ) ) );
		$this->respond(
			200,
			$this->live_info(
				array(
					'scopes'        => array( 'agecheck:verify' ),
					'originAllowed' => false,
				)
			)
		);

		$text = implode( "\n", array_column( $this->page->status_lines(), 'text' ) );

		$this->assertStringContainsString( 'Start key: TEST MODE', $text );
		$this->assertStringContainsString( 'Missing scope: agecheck:clearance', $text );
		$this->assertStringContainsString( 'One key is live and the other is a test key', $text );
		$this->assertStringContainsString( 'Add https://shop.example to the allowed origins', $text );
	}

	public function test_status_asks_again_even_when_a_success_is_cached(): void {
		$this->respond( 200, $this->live_info( array( 'scopes' => array( 'agecheck:verify' ) ) ) );
		$this->respond( 200, $this->live_info( array( 'originAllowed' => false ) ) );
		$text = implode( "\n", array_column( $this->page->status_lines(), 'text' ) );
		$this->assertStringContainsString( 'Add https://shop.example to the allowed origins', $text );

		// The merchant adds the origin in the Navexa dashboard and reloads the tab.
		$this->respond( 200, $this->live_info( array( 'scopes' => array( 'agecheck:verify' ) ) ) );
		$this->respond( 200, $this->live_info() );
		$text = implode( "\n", array_column( $this->page->status_lines(), 'text' ) );
		$this->assertStringContainsString( 'https://shop.example is an allowed origin', $text );
		$this->assertCount( 4, $this->http_requests );
		$this->assertTrue( Mode::info( Client::KEY_FULFILMENT )->origin_allowed, 'the refreshed answer is cached' );
		$this->assertCount( 4, $this->http_requests );
	}

	public function test_status_flags_one_key_in_both_fields(): void {
		$this->options[ Options::START_KEY ] = self::LIVE_FULFILMENT_KEY;
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->live_info() );

		$lines = $this->page->status_lines();

		$errors = implode( "\n", array_column( array_filter( $lines, array( $this, 'is_error' ) ), 'text' ) );
		$this->assertStringContainsString( 'are the same key', $errors );
		$this->assertStringNotContainsString( 'also has the agecheck:clearance scope', implode( "\n", array_column( $lines, 'text' ) ) );
	}

	public function test_status_warns_when_the_start_key_can_read_clearances(): void {
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->live_info() );

		$lines = $this->page->status_lines();

		$this->assertContains(
			array(
				'level' => 'warning',
				'text'  => 'The start key also has the agecheck:clearance scope. It needs only agecheck:verify.',
			),
			$lines
		);
		$this->assertSame( array(), array_filter( $lines, array( $this, 'is_error' ) ) );
	}

	public function test_status_flags_keys_from_two_accounts(): void {
		$this->respond( 200, $this->live_info( array( 'scopes' => array( 'agecheck:verify' ) ) ) );
		$this->respond( 200, $this->live_info( array( 'displayName' => 'Other Shop' ) ) );

		$errors = implode( "\n", array_column( array_filter( $this->page->status_lines(), array( $this, 'is_error' ) ), 'text' ) );

		$this->assertStringContainsString( 'different Navexa accounts (Shop Example and Other Shop)', $errors );
	}

	public function test_status_before_the_engine_serves_get_integration_info(): void {
		$this->respond( 404, '404 page not found' );
		$this->respond( 404, '404 page not found' );
		$lines = $this->page->status_lines();
		$this->assertSame( array( 'error' ), array_values( array_unique( array_column( $lines, 'level' ) ) ) );
		$this->assertStringContainsString( 'does not offer the connection check', $lines[0]['text'] );
	}

	public function test_status_when_paused_makes_no_calls(): void {
		$this->home = 'https://staging.shop.example';
		$text       = implode( "\n", array_column( $this->page->status_lines(), 'text' ) );
		$this->assertStringContainsString( 'Paused', $text );
		$this->assertStringContainsString( 'paused until this site is confirmed', $text );
		$this->assertCount( 0, $this->http_requests );
	}

	public function test_status_warns_when_the_store_ships_outside_the_us(): void {
		$countries     = new class() {
			public function get_shipping_countries() {
				return array(
					'US' => 'United States (US)',
					'CA' => 'Canada',
				);
			}
		};
		$wc            = new \stdClass();
		$wc->countries = $countries;
		Functions\when( 'WC' )->justReturn( $wc );
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->live_info() );

		$text = implode( "\n", array_column( $this->page->status_lines(), 'text' ) );
		$this->assertStringContainsString( 'Your store ships outside the US', $text );
	}

	public function test_options_helpers(): void {
		$this->assertTrue( Options::looks_like_api_key( self::LIVE_START_KEY ) );
		$this->assertFalse( Options::looks_like_api_key( 'txk_short' ) );
		$this->assertFalse( Options::looks_like_api_key( 'txk_' . str_repeat( 'a', 30 ) . ' x' ) );
		$this->assertSame( '********', Options::mask_api_key( 'txk_abc' ) );

		$this->assertSame( Options::MODE_PRE_PAYMENT, Options::mode() );
		$this->options[ Options::MODE ] = Options::MODE_ACCEPT_AND_HOLD;
		$this->assertSame( Options::MODE_ACCEPT_AND_HOLD, Options::mode() );
		$this->options[ Options::MODE ] = 'fail_open';
		$this->assertSame( Options::DEFAULT_MODE, Options::mode() );
	}

	/**
	 * Whether a status line is an error.
	 *
	 * @param array{level: string, text: string} $line Status line.
	 */
	public function is_error( array $line ): bool {
		return 'error' === $line['level'];
	}

	private function sanitize( string $id, string $raw ) {
		return $this->page->sanitize_secret( trim( $raw ), array( 'id' => $id ), $raw );
	}
}
