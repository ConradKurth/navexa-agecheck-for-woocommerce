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
use Navexa\AgeCheck\Sweep;
use Navexa\AgeCheck\Tests\TestCase;

final class SettingsTest extends TestCase {

	/** @var Settings */
	private $page;

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->page = new Settings();
		$_POST      = array();
		// A healthy W1 setup: webhook secret saved and used, sweep running.
		$this->options[ Options::WEBHOOK_SECRET ]        = 'whsec_0123456789abcdef0123';
		$this->options[ Options::WEBHOOK_LAST_RECEIVED ] = time() - 60;
		$this->options[ Sweep::OPTION_LAST ]             = time() - 60;
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

		$proxy = $fields[ Options::TRUSTED_PROXY_HEADER . ':select' ];
		$this->assertSame( '', $proxy['default'], 'REMOTE_ADDR unless the merchant says otherwise' );
		$this->assertSame( array_merge( array( '' ), \Navexa\AgeCheck\RateLimit::PROXY_HEADERS ), array_keys( $proxy['options'] ) );
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
		$this->respond( 200, $this->live_info( array( 'displayName' => 'Other Shop', 'organizationId' => 'org_other' ) ) );

		$errors = implode( "\n", array_column( array_filter( $this->page->status_lines(), array( $this, 'is_error' ) ), 'text' ) );

		$this->assertStringContainsString( 'different Navexa accounts (Shop Example and Other Shop)', $errors );
	}

	public function test_status_compares_organization_ids_not_display_names(): void {
		// Same organization, a display name changed between the two reads: fine.
		$this->respond( 200, $this->live_info( array( 'scopes' => array( 'agecheck:verify' ) ) ) );
		$this->respond( 200, $this->live_info( array( 'displayName' => 'Renamed Shop' ) ) );
		$errors = implode( "\n", array_column( array_filter( $this->page->status_lines(), array( $this, 'is_error' ) ), 'text' ) );
		$this->assertStringNotContainsString( 'different Navexa accounts', $errors );

		// Two organizations that share a display name (or have none): flagged.
		$this->transients = array();
		$this->respond( 200, $this->live_info( array( 'scopes' => array( 'agecheck:verify' ), 'displayName' => '' ) ) );
		$this->respond( 200, $this->live_info( array( 'organizationId' => 'org_other', 'displayName' => '' ) ) );
		$errors = implode( "\n", array_column( array_filter( $this->page->status_lines(), array( $this, 'is_error' ) ), 'text' ) );
		$this->assertStringContainsString( 'The keys belong to different Navexa accounts. Use two keys', $errors );
	}

	public function test_status_before_the_engine_serves_get_integration_info(): void {
		$this->respond( 404, '404 page not found' );
		$this->respond( 404, '404 page not found' );
		$lines = $this->page->status_lines();
		// Both key lines are errors; only the W1 webhook and cron lines follow.
		$this->assertSame( array( 'error', 'error' ), array_column( array_slice( $lines, 0, 2 ), 'level' ) );
		$this->assertCount( 3, $lines );
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

	public function test_status_accepts_us_territories_as_us(): void {
		$countries     = new class() {
			public function get_shipping_countries() {
				return array(
					'US' => 'United States (US)',
					'PR' => 'Puerto Rico',
					'GU' => 'Guam',
				);
			}
		};
		$wc            = new \stdClass();
		$wc->countries = $countries;
		Functions\when( 'WC' )->justReturn( $wc );
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->live_info() );

		$text = implode( "\n", array_column( $this->page->status_lines(), 'text' ) );
		$this->assertStringNotContainsString( 'ships outside the US', $text );
	}

	/** Status text with $recent as the recorded recent starts. */
	private function status_with_recent_starts( array $recent ): string {
		$this->options[ \Navexa\AgeCheck\RateLimit::OPTION_RECENT_START_IPS ] = $recent;
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->live_info() );
		return implode( "\n", array_column( $this->page->status_lines(), 'text' ) );
	}

	public function test_status_warns_when_recent_starts_share_one_ip(): void {
		$text = $this->status_with_recent_starts(
			array_fill(
				0,
				12,
				array(
					'ip'  => 'abcdefabcdef',
					'via' => '',
				)
			)
		);
		$this->assertStringContainsString( 'all came from one IP address or from a private network address', $text );
	}

	public function test_status_names_the_proxy_header_the_starts_carried(): void {
		$recent = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$recent[] = array(
				'ip'  => sprintf( '%012x', $i ),
				'via' => 'HTTP_X_FORWARDED_FOR',
			);
		}
		$text = $this->status_with_recent_starts( $recent );
		$this->assertStringContainsString( 'they carry the header X-Forwarded-For (last address)', $text );
		$this->assertStringNotContainsString( 'one IP address', $text );
	}

	public function test_status_flags_a_missing_rate_limit_table(): void {
		$this->wpdb->table_exists = false;
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->live_info() );
		$errors = array_filter( $this->page->status_lines(), array( $this, 'is_error' ) );
		$this->assertStringContainsString( 'rate-limit table is missing', implode( "\n", array_column( $errors, 'text' ) ) );
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

	public function test_w1_fields(): void {
		$fields = array();
		foreach ( $this->page->get_settings_for_section( '' ) as $field ) {
			$fields[ $field['id'] . ':' . $field['type'] ] = $field;
		}
		$rule = $fields[ Options::RULE . ':select' ];
		$this->assertSame( Options::RULE_ALL, $rule['default'] );
		$this->assertSame( array( Options::RULE_ALL, Options::RULE_INCLUDE, Options::RULE_EXCLUDE ), array_keys( $rule['options'] ) );
		$this->assertSame( array( 11 => 'Vapes', 12 => 'Disposables' ), $fields[ Options::RULE_CATEGORIES . ':multiselect' ]['options'] );
		$this->assertArrayHasKey( Options::RULE_TAGS . ':multiselect', $fields );
		$this->assertArrayHasKey( 'wholesale', $fields[ Options::EXEMPT_ROLES . ':multiselect' ]['options'] );
		$this->assertSame( Options::DENIED_AUTO_REFUND, $fields[ Options::DENIED_ORDERS . ':select' ]['default'] );
		$this->assertFalse( $fields[ Options::WEBHOOK_SECRET . ':' . Settings::FIELD_SECRET ]['autoload'] );
		$this->assertFalse( $fields[ 'navexa_agecheck_webhook_url:' . Settings::FIELD_INFO ]['is_option'] );
		$this->assertStringContainsString( 'Local pickup orders never need one', $fields['navexa_agecheck_rules_section:title']['desc'] );

		ob_start();
		$this->page->output_info_field( array( 'title' => 'Webhook URL' ) );
		$this->assertStringContainsString( 'https://shop.example/wp-json/navexa-agecheck/v1/webhook', ob_get_clean() );
	}

	public function test_saved_terms_outside_the_listed_ones_are_listed_so_a_save_keeps_them(): void {
		$calls = array();
		Functions\when( 'get_terms' )->alias(
			static function ( $args ) use ( &$calls ) {
				$calls[] = $args;
				if ( 'product_tag' !== $args['taxonomy'] ) {
					return array();
				}
				if ( isset( $args['include'] ) ) {
					return array_intersect_key( array( 900 => 'zyn-pouches' ), array_flip( $args['include'] ) );
				}
				return array( 5 => 'aaa' ); // The first Settings::TERM_LIMIT by name.
			}
		);
		$this->options[ Options::RULE_TAGS ] = array( '5', '900', '901' );
		$fields                               = array();
		foreach ( $this->page->get_settings_for_section( '' ) as $field ) {
			$fields[ $field['id'] . ':' . $field['type'] ] = $field;
		}
		$this->assertSame(
			array(
				5   => 'aaa',
				900 => 'zyn-pouches',
				901 => 'Term #901',
			),
			$fields[ Options::RULE_TAGS . ':multiselect' ]['options'],
			'WooCommerce saves only the options posted back: a saved term must be one'
		);
		$this->assertSame( Settings::TERM_LIMIT, $calls[0]['number'] );
		$tag_calls = array_values(
			array_filter(
				$calls,
				static function ( $args ) {
					return 'product_tag' === $args['taxonomy'];
				}
			)
		);
		$this->assertSame( array( 900, 901 ), $tag_calls[1]['include'], 'only the saved terms not listed yet are looked up' );
	}

	public function test_status_flags_an_include_rule_that_matches_no_term(): void {
		$existing = array( 12 );
		Functions\when( 'get_terms' )->alias(
			function ( $args ) use ( &$existing ) {
				$this->assertNotEmpty( $args['include'], 'an empty include would list every term' );
				return array_values( array_intersect( $existing, $args['include'] ) );
			}
		);
		$this->options[ Options::RULE ] = Options::RULE_INCLUDE;
		foreach ( array( array(), array( 99 ) ) as $saved ) {
			$this->options[ Options::RULE_CATEGORIES ] = $saved;
			$this->transients                          = array();
			$this->respond( 200, $this->live_info() );
			$this->respond( 200, $this->live_info() );
			$errors = array_filter( $this->page->status_lines(), array( $this, 'is_error' ) );
			$this->assertStringContainsString( 'none is chosen (or they were deleted)', implode( "\n", array_column( $errors, 'text' ) ), print_r( $saved, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		$this->options[ Options::RULE_CATEGORIES ] = array( 12 );
		$this->transients                          = array();
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->live_info() );
		$this->assertStringNotContainsString( 'none is chosen', implode( "\n", array_column( $this->page->status_lines(), 'text' ) ) );

		$this->options[ Options::RULE ] = Options::RULE_EXCLUDE;
		$this->options[ Options::RULE_CATEGORIES ] = array();
		$this->transients                          = array();
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->live_info() );
		$this->assertStringNotContainsString( 'none is chosen', implode( "\n", array_column( $this->page->status_lines(), 'text' ) ), 'exclude with no terms checks everything' );
	}

	public function test_webhook_secret_is_saved_like_a_key_and_never_shown(): void {
		unset( $this->options[ Options::WEBHOOK_SECRET ] );
		$secret = 'whsec_' . str_repeat( 'Ab9', 12 );
		$this->assertNull( $this->sanitize( Options::WEBHOOK_SECRET, $secret ) );
		$this->assertSame( $secret, $this->options[ Options::WEBHOOK_SECRET ] );
		$this->assertFalse( $this->autoload[ Options::WEBHOOK_SECRET ] );

		$this->assertNull( $this->sanitize( Options::WEBHOOK_SECRET, 'short' ) );
		$this->assertSame( $secret, $this->options[ Options::WEBHOOK_SECRET ], 'a malformed secret is refused' );
		$this->assertNotEmpty( \WC_Admin_Settings::$errors );

		$this->assertNull( $this->sanitize( Options::WEBHOOK_SECRET, '' ) );
		$this->assertSame( $secret, $this->options[ Options::WEBHOOK_SECRET ], 'blank keeps it' );

		ob_start();
		$this->page->output_secret_field( array( 'id' => Options::WEBHOOK_SECRET ) );
		$html = ob_get_clean();
		$this->assertStringNotContainsString( substr( $secret, 0, 8 ), $html, 'no part of the secret is printed' );
		$this->assertStringContainsString( 'A secret is saved.', $html );
		$this->assertStringContainsString( 'Remove the saved secret', $html );

		$_POST[ Options::WEBHOOK_SECRET . '_clear' ] = '1';
		$this->assertNull( $this->sanitize( Options::WEBHOOK_SECRET, '' ) );
		$this->assertArrayNotHasKey( Options::WEBHOOK_SECRET, $this->options );

		ob_start();
		$this->page->output_secret_field( array( 'id' => Options::WEBHOOK_SECRET ) );
		$this->assertStringContainsString( 'No secret saved.', ob_get_clean() );
	}

	public function test_status_flags_webhook_and_cron_health(): void {
		unset( $this->options[ Options::WEBHOOK_SECRET ] );
		$this->options[ Sweep::OPTION_LAST ] = time() - 7200;
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->live_info() );
		$text = implode( "\n", array_column( $this->page->status_lines(), 'text' ) );
		$this->assertStringContainsString( 'No webhook secret', $text );
		$this->assertStringContainsString( 'has not run in the last hour', $text );

		$this->options[ Options::WEBHOOK_SECRET ] = 'whsec_0123456789abcdef0123';
		unset( $this->options[ Options::WEBHOOK_LAST_RECEIVED ] );
		$this->transients = array();
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->live_info() );
		$text = implode( "\n", array_column( $this->page->status_lines(), 'text' ) );
		$this->assertStringContainsString( 'No webhook received yet', $text );
	}

	public function test_w1_option_helpers(): void {
		$this->assertSame( Options::RULE_ALL, Options::rule() );
		$this->options[ Options::RULE ] = Options::RULE_EXCLUDE;
		$this->assertSame( Options::RULE_EXCLUDE, Options::rule() );
		$this->options[ Options::RULE ] = 'none';
		$this->assertSame( Options::RULE_ALL, Options::rule(), 'unknown reads as the strictest rule' );

		$this->options[ Options::RULE_CATEGORIES ] = array( '12', 12, 'x', 0, 5 );
		$this->assertSame( array( 12, 5 ), Options::term_ids( Options::RULE_CATEGORIES ) );
		$this->options[ Options::RULE_TAGS ] = 'not-an-array';
		$this->assertSame( array(), Options::term_ids( Options::RULE_TAGS ) );

		$this->options[ Options::EXEMPT_ROLES ] = array( 'wholesale', '', 3 );
		$this->assertSame( array( 'wholesale', '3' ), Options::exempt_roles() );
		$this->options[ Options::EXEMPT_ROLES ] = 'administrator';
		$this->assertSame( array(), Options::exempt_roles() );

		$this->assertSame( Options::DENIED_AUTO_REFUND, Options::denied_orders() );
		$this->options[ Options::DENIED_ORDERS ] = Options::DENIED_MANUAL;
		$this->assertSame( Options::DENIED_MANUAL, Options::denied_orders() );

		$this->assertTrue( Options::looks_like_webhook_secret( '4f1c2b7e9a0d3c5e8f6a1b2c3d4e5f60' ) );
		$this->assertFalse( Options::looks_like_webhook_secret( 'has a space in it 0123456789' ) );
		$this->assertFalse( Options::looks_like_webhook_secret( 'short' ) );
	}

	public function test_w4_fields_and_their_defaults(): void {
		$fields = array();
		foreach ( $this->page->get_settings_for_section( '' ) as $field ) {
			$fields[ $field['id'] . ':' . $field['type'] ] = $field;
		}
		$this->assertSame( 'yes', $fields[ Options::HIDE_EXPRESS . ':checkbox' ]['default'], 'express buttons hidden by default' );
		$this->assertSame( 'no', $fields[ Options::TURNSTILE . ':checkbox' ]['default'], 'Turnstile off by default' );
		$this->assertSame( 'no', $fields[ Options::DELETE_DATA . ':checkbox' ]['default'], 'order meta kept by default' );
		$this->assertSame( '', $fields[ Options::TURNSTILE_SITE_KEY . ':text' ]['default'] );
		$secret = $fields[ Options::TURNSTILE_SECRET . ':' . Settings::FIELD_SECRET ];
		$this->assertFalse( $secret['autoload'], 'the Turnstile secret is never autoloaded' );
		$this->assertStringContainsString( 'express checkout buttons on age-restricted carts', $fields[ Options::HIDE_EXPRESS . ':checkbox' ]['desc'] );
	}

	public function test_w4_option_helpers(): void {
		$this->assertTrue( Options::hide_express(), 'unset reads as on' );
		$this->options[ Options::HIDE_EXPRESS ] = 'no';
		$this->assertFalse( Options::hide_express() );
		$this->options[ Options::HIDE_EXPRESS ] = 'garbage';
		$this->assertTrue( Options::hide_express(), 'only an explicit no turns it off' );

		$this->assertFalse( Options::turnstile_enabled() );
		$this->options[ Options::TURNSTILE ] = 'yes';
		$this->assertTrue( Options::turnstile_enabled() );
		$this->options[ Options::TURNSTILE_SITE_KEY ] = ' 1x00000000000000000000AA ';
		$this->assertSame( '1x00000000000000000000AA', Options::turnstile_site_key() );
		$this->options[ Options::TURNSTILE_SECRET ] = array( 'not a string' );
		$this->assertSame( '', Options::turnstile_secret() );

		$this->assertTrue( Options::looks_like_turnstile_key( '0x4AAAAAAABkMYinukE8nzYS' ) );
		$this->assertTrue( Options::looks_like_turnstile_key( '1x0000000000000000000000000000000AA' ) );
		$this->assertFalse( Options::looks_like_turnstile_key( 'txk_' . str_repeat( 'a', 64 ) ) );
		$this->assertFalse( Options::looks_like_turnstile_key( '0x short' ) );

		$this->assertFalse( Options::delete_data_on_uninstall() );
		$this->options[ Options::DELETE_DATA ] = 'yes';
		$this->assertTrue( Options::delete_data_on_uninstall() );
	}

	public function test_turnstile_secret_is_saved_like_a_secret_and_never_shown(): void {
		$secret = '0x4AAAAAAA' . str_repeat( 'Zz9', 9 );
		$this->assertNull( $this->sanitize( Options::TURNSTILE_SECRET, $secret ) );
		$this->assertSame( $secret, $this->options[ Options::TURNSTILE_SECRET ] );
		$this->assertFalse( $this->autoload[ Options::TURNSTILE_SECRET ] );

		$this->assertNull( $this->sanitize( Options::TURNSTILE_SECRET, 'txk_not_a_turnstile_key_at_all' ) );
		$this->assertSame( $secret, $this->options[ Options::TURNSTILE_SECRET ], 'a malformed secret is refused' );
		$this->assertStringContainsString( 'Turnstile secret key', end( \WC_Admin_Settings::$errors ) );

		$this->assertNull( $this->sanitize( Options::TURNSTILE_SECRET, '' ) );
		$this->assertSame( $secret, $this->options[ Options::TURNSTILE_SECRET ], 'blank keeps it' );

		ob_start();
		$this->page->output_secret_field( array( 'id' => Options::TURNSTILE_SECRET ) );
		$html = ob_get_clean();
		$this->assertStringNotContainsString( substr( $secret, 0, 6 ), $html, 'no part of the secret is printed' );
		$this->assertStringContainsString( 'A secret is saved.', $html );
		$this->assertStringNotContainsString( 'txk_', $html );

		$_POST[ Options::TURNSTILE_SECRET . '_clear' ] = '1';
		$this->assertNull( $this->sanitize( Options::TURNSTILE_SECRET, '' ) );
		$this->assertArrayNotHasKey( Options::TURNSTILE_SECRET, $this->options );
	}

	public function test_turnstile_site_key_shape_is_checked(): void {
		$this->assertSame( '0x4AAAAAAABkMYinukE8nzYS', $this->page->sanitize_turnstile_site_key( 'x', array(), ' 0x4AAAAAAABkMYinukE8nzYS ' ) );
		$this->assertSame( '', $this->page->sanitize_turnstile_site_key( '', array(), '' ), 'blank clears it' );
		$this->options[ Options::TURNSTILE_SITE_KEY ] = '1x00000000000000000000AA';
		$this->assertSame( '1x00000000000000000000AA', $this->page->sanitize_turnstile_site_key( 'bad key', array(), 'bad key' ), 'a malformed key keeps the saved one' );
		$this->assertNotEmpty( \WC_Admin_Settings::$errors );
	}

	public function test_status_flags_turnstile_without_keys(): void {
		$this->options[ Options::TURNSTILE ] = 'yes';
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->live_info() );
		$lines = $this->page->status_lines();
		$text  = implode( "\n", array_column( array_filter( $lines, array( $this, 'is_error' ) ), 'text' ) );
		$this->assertStringContainsString( 'Turnstile is on, but its site key or secret key is missing', $text );

		$this->options[ Options::TURNSTILE_SITE_KEY ] = '0x4AAAAAAABkMYinukE8nzYS';
		$this->options[ Options::TURNSTILE_SECRET ]   = '0x4AAAAAAABkMYinukE8nzYS_secret_value';
		$this->transients                             = array();
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->live_info() );
		$text = implode( "\n", array_column( $this->page->status_lines(), 'text' ) );
		$this->assertStringContainsString( 'Turnstile checks shoppers', $text );
		$this->assertStringNotContainsString( 'missing', $text );
	}

	public function test_status_flags_cloudflare_test_keys(): void {
		$this->options[ Options::TURNSTILE ]          = 'yes';
		$this->options[ Options::TURNSTILE_SITE_KEY ] = '0x4AAAAAAABkMYinukE8nzYS';
		$this->options[ Options::TURNSTILE_SECRET ]   = '1x0000000000000000000000000000000AA';
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->live_info() );
		$errors = implode( "\n", array_column( array_filter( $this->page->status_lines(), array( $this, 'is_error' ) ), 'text' ) );
		$this->assertStringContainsString( 'test keys', $errors, 'live keys + Cloudflare\'s always-pass test secret: an error' );

		$this->options[ Options::TURNSTILE_SITE_KEY ] = '3x00000000000000000000FF';
		$this->options[ Options::TURNSTILE_SECRET ]   = '0x4AAAAAAABkMYinukE8nzYS_secret_value';
		$this->transients                             = array();
		$this->respond( 200, $this->live_info( array( 'livemode' => false ) ) );
		$this->respond( 200, $this->live_info( array( 'livemode' => false ) ) );
		$lines = $this->page->status_lines();
		$test  = array_values(
			array_filter(
				$lines,
				static function ( $line ) {
					return false !== strpos( $line['text'], 'test keys' );
				}
			)
		);
		$this->assertCount( 1, $test );
		$this->assertSame( 'warning', $test[0]['level'], 'test-mode AgeCheck keys: a warning' );
		$this->assertStringNotContainsString( 'Turnstile checks shoppers', implode( "\n", array_column( $lines, 'text' ) ) );

		$this->assertTrue( Options::is_turnstile_test_key( '2x00000000000000000000AB' ) );
		$this->assertFalse( Options::is_turnstile_test_key( '0x4AAAAAAABkMYinukE8nzYS' ) );
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
