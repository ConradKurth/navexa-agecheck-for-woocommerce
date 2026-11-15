<?php
/**
 * Entry age gate (W3 PR 13): self-attestation, not verification. Which pages
 * carry it, its configuration, and that it never calls AgeCheck.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\EntryGate;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Plugin;
use Navexa\AgeCheck\Privacy;
use Navexa\AgeCheck\Requirement;
use Navexa\AgeCheck\Settings;
use Navexa\AgeCheck\Tests\TestCase;

final class EntryGateTest extends TestCase {

	/** @var array<string, bool> What the WordPress and WooCommerce conditionals answer. */
	public $is = array();

	/** @var int get_queried_object_id(). */
	public $queried = 0;

	/** @var string get_bloginfo( 'name' ). */
	public $site_name = 'Corner &amp; Co';

	/** @var array<int, array> wp_enqueue_script() calls. */
	public $enqueued = array();

	/** @var array<int, array> wp_add_inline_script() calls. */
	public $inline = array();

	protected function setUp(): void {
		parent::setUp();
		$test = $this;
		foreach ( array( 'wp_doing_ajax', 'wp_doing_cron', 'wp_is_serving_rest_request', 'is_login', 'is_feed', 'is_embed', 'is_robots', 'is_favicon', 'is_trackback', 'is_customize_preview', 'is_product', 'is_product_category', 'is_product_tag' ) as $name ) {
			Functions\when( $name )->alias(
				static function () use ( $test, $name ) {
					return ! empty( $test->is[ $name ] );
				}
			);
		}
		Functions\when( 'get_queried_object_id' )->alias(
			static function () use ( $test ) {
				return $test->queried;
			}
		);
		Functions\when( 'get_bloginfo' )->alias(
			static function ( $show = '' ) use ( $test ) {
				return 'name' === $show ? $test->site_name : 'en-US';
			}
		);
		Functions\when( 'get_term_children' )->alias(
			static function ( $term_id ) {
				return 11 === (int) $term_id ? array( 12 ) : array();
			}
		);
		Functions\when( 'plugins_url' )->alias(
			static function ( $path ) {
				return 'https://shop.example/wp-content/plugins/navexa-agecheck-for-woocommerce/' . $path;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( ...$args ) use ( $test ) {
				$test->enqueued[] = $args;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( ...$args ) use ( $test ) {
				$test->inline[] = $args;
				return true;
			}
		);
		unset( $_GET['wc-api'], $GLOBALS['pagenow'] );
		$this->options[ Options::ENTRY_GATE ] = 'yes';
	}

	protected function tearDown(): void {
		unset( $_GET['wc-api'], $GLOBALS['pagenow'] );
		parent::tearDown();
	}

	public function test_off_by_default(): void {
		unset( $this->options[ Options::ENTRY_GATE ] );
		$this->assertFalse( Options::entry_gate_enabled() );
		$this->assertFalse( EntryGate::applies() );
		EntryGate::enqueue();
		$this->assertSame( array(), $this->enqueued );
		$this->options[ Options::ENTRY_GATE ] = 'on';
		$this->assertFalse( EntryGate::applies(), 'only a stored yes turns it on' );
	}

	public function test_whole_site_gate_is_a_bundled_head_script_with_fixed_config(): void {
		$this->install_with_keys(); // Keys and no cached answer: still no AgeCheck call.
		$this->assertTrue( EntryGate::applies() );
		EntryGate::enqueue();

		$this->assertCount( 1, $this->enqueued );
		list( $handle, $src, $deps, $ver, $args ) = $this->enqueued[0];
		$this->assertSame( EntryGate::HANDLE, $handle );
		$this->assertSame( 'https://shop.example/wp-content/plugins/navexa-agecheck-for-woocommerce/assets/js/entry-gate.min.js', $src, 'bundled, from the store itself' );
		$this->assertSame( array(), $deps, 'no dependencies' );
		$this->assertSame( array( 'in_footer' => false ), $args, 'in the head, so the page never flashes' );
		$this->assertSame( EntryGate::HANDLE, $this->inline[0][0] );
		$this->assertSame( 'before', $this->inline[0][2] );
		$this->assertStringStartsWith( 'window.navexaAgeCheckGate = {', $this->inline[0][1] );

		$config = EntryGate::config();
		$this->assertSame( 1, $config['v'] );
		$this->assertSame( 21, $config['minimumAge'], '21 until AgeCheck has answered' );
		$this->assertSame( 30, $config['days'] );
		$this->assertSame( '', $config['exitUrl'] );
		$this->assertSame( 'Welcome to Corner & Co', $config['i18n']['title'], 'the site title, decoded (the script sets it as text)' );
		$this->assertSame( 'I am 21 or older', $config['i18n']['yes'] );
		$this->assertSame( 'I am under 21', $config['i18n']['no'] );
		$this->assertStringContainsString( '21 or older', $config['i18n']['denied'] );

		$this->assertSame( array(), $this->http_requests, 'no network call' );
		$json = $this->inline[0][1];
		$this->assertStringNotContainsString( 'txk_', $json, 'no key' );
		$this->assertStringNotContainsString( '<', $json, 'JSON_HEX_TAG' );
	}

	public function test_config_uses_the_last_integration_info_and_the_merchants_choices(): void {
		$this->install_with_keys();
		$this->respond( 200, $this->live_info( array( 'minimumAge' => 18, 'displayName' => 'Vape <b>Hut</b>' ) ) );
		Mode::info( Client::KEY_FULFILMENT );
		$this->assertTrue( $this->autoload[ Options::STORE_PROFILE ], 'read on storefront pages' );
		$this->options[ Options::ENTRY_GATE_DAYS ]     = '7';
		$this->options[ Options::ENTRY_GATE_EXIT_URL ] = 'https://www.google.com/';

		$config = EntryGate::config();
		$this->assertSame( 18, $config['minimumAge'] );
		$this->assertSame( 'I am 18 or older', $config['i18n']['yes'] );
		$this->assertSame( 'Welcome to Vape <b>Hut</b>', $config['i18n']['title'], 'text, never markup: the script uses textContent' );
		$this->assertSame( 7, $config['days'] );
		$this->assertSame( 'https://www.google.com/', $config['exitUrl'] );

		$this->http_requests = array();
		EntryGate::enqueue();
		$this->assertSame( array(), $this->http_requests, 'the cached profile, never the network' );
		$this->assertStringContainsString( 'Vape \\u003Cb\\u003EHut', $this->inline[0][1], 'JSON_HEX_TAG: no markup in the inline script' );

		Mode::flush();
		$this->assertSame( 21, EntryGate::config()['minimumAge'], 'new keys: back to the default until AgeCheck answers' );

		$this->options[ Options::STORE_PROFILE ] = array(
			'minimum_age'  => 20,
			'display_name' => '  ',
		);
		$config                                  = EntryGate::config();
		$this->assertSame( 21, $config['minimumAge'], 'an age AgeCheck does not offer reads as 21' );
		$this->assertSame( 'Welcome to Corner & Co', $config['i18n']['title'] );
		$this->site_name = '';
		$this->assertSame( 'Welcome', EntryGate::config()['i18n']['title'] );
	}

	public function test_days_and_exit_url_are_bounded(): void {
		$cases = array(
			array( '0', 0 ),
			array( '365', 365 ),
			array( '366', 365 ),
			array( ' 12 ', 12 ),
			array( '-1', 30 ),
			array( '1.5', 30 ),
			array( 'abc', 30 ),
			array( array(), 30 ),
			array( 9, 9 ),
		);
		foreach ( $cases as $case ) {
			$this->assertSame( $case[1], Options::gate_days( $case[0] ), (string) wp_json_encode( $case[0] ) );
		}
		foreach ( array( 'https://example.org/x' => 'https://example.org/x', 'http://example.org' => 'http://example.org', 'javascript:alert(1)' => '', '/relative' => '', 'ftp://example.org' => '', '' => '', 'https://' => '' ) as $in => $out ) {
			$this->assertSame( $out, Options::exit_url( $in ), $in );
		}
		$this->options[ Options::ENTRY_GATE_EXIT_URL ] = 'data:text/html,hi';
		$this->assertSame( '', Options::entry_gate_exit_url() );
		$this->options[ Options::ENTRY_GATE_SCOPE ] = 'junk';
		$this->assertSame( Options::GATE_SCOPE_SITE, Options::entry_gate_scope() );
	}

	public function test_never_on_admin_login_rest_ajax_cron_feeds_or_the_return_page(): void {
		$excluded = array(
			'wp-admin'           => function () {
				$this->is_admin = true;
			},
			'AJAX'               => function () {
				$this->is['wp_doing_ajax'] = true;
			},
			'cron'               => function () {
				$this->is['wp_doing_cron'] = true;
			},
			'REST'               => function () {
				$this->is['wp_is_serving_rest_request'] = true;
			},
			'login'              => function () {
				$this->is['is_login'] = true;
			},
			'wp-login.php'       => function () {
				$GLOBALS['pagenow'] = 'wp-login.php';
			},
			'AgeCheck return'    => function () {
				$_GET['wc-api'] = 'navexa_agecheck_return';
			},
			'feed'               => function () {
				$this->is['is_feed'] = true;
			},
			'embed'              => function () {
				$this->is['is_embed'] = true;
			},
			'robots.txt'         => function () {
				$this->is['is_robots'] = true;
			},
			'favicon'            => function () {
				$this->is['is_favicon'] = true;
			},
			'trackback'          => function () {
				$this->is['is_trackback'] = true;
			},
			'Customizer preview' => function () {
				$this->is['is_customize_preview'] = true;
			},
		);
		foreach ( $excluded as $name => $setup ) {
			$this->is       = array();
			$this->is_admin = false;
			unset( $_GET['wc-api'], $GLOBALS['pagenow'] );
			$this->assertTrue( EntryGate::applies(), $name . ': control' );
			$setup();
			$this->assertFalse( EntryGate::applies(), $name );
		}
	}

	public function test_restricted_scope_gates_restricted_products(): void {
		$this->options[ Options::ENTRY_GATE_SCOPE ] = Options::GATE_SCOPE_RESTRICTED;
		$this->assertFalse( EntryGate::applies(), 'the home page is not age-restricted' );

		$this->is['is_product'] = true;
		$this->queried          = 7;
		$this->products[7]      = new \WC_Product( 7 );
		$this->assertTrue( EntryGate::applies(), 'every product (the default rule)' );

		$this->products[7] = new \WC_Product( 7, 0, array( Requirement::PRODUCT_META => Requirement::PRODUCT_EXEMPT ) );
		$this->assertFalse( EntryGate::applies(), 'an exempt product' );

		$this->products[7]->children = array( 70, 71 );
		$this->products[70]          = new \WC_Product( 70, 7 );
		$this->products[71]          = new \WC_Product( 71, 7, array( Requirement::PRODUCT_META => Requirement::PRODUCT_REQUIRE ) );
		$this->assertTrue( EntryGate::applies(), 'a variation that needs a check' );

		$this->queried = 404;
		$this->assertFalse( EntryGate::applies(), 'a product that cannot be loaded is no product page to gate' );

		$this->options[ Options::RULE ]            = Options::RULE_INCLUDE;
		$this->options[ Options::RULE_CATEGORIES ] = array( 12 );
		$this->products[8]                         = new \WC_Product( 8 );
		$this->product_terms[8]['product_cat']     = array( 12 );
		$this->queried                             = 8;
		$this->assertTrue( EntryGate::applies(), 'a product in a chosen category' );
		$this->product_terms[8]['product_cat'] = array( 99 );
		$this->assertFalse( EntryGate::applies() );
	}

	public function test_restricted_scope_gates_category_and_tag_pages_by_the_store_rule(): void {
		$this->options[ Options::ENTRY_GATE_SCOPE ] = Options::GATE_SCOPE_RESTRICTED;
		$this->is['is_product_category']            = true;

		$this->queried = 99;
		$this->assertTrue( EntryGate::applies(), 'every product: every category page' );

		// Category 12 is a child of 11 (TestCase's get_ancestors and this test's get_term_children).
		$this->options[ Options::RULE ]            = Options::RULE_INCLUDE;
		$this->options[ Options::RULE_CATEGORIES ] = array( 11 );
		foreach ( array( 11 => true, 12 => true, 99 => false ) as $term => $want ) {
			$this->queried = $term;
			$this->assertSame( $want, EntryGate::applies(), 'include 11: category ' . $term );
		}
		$this->options[ Options::RULE_CATEGORIES ] = array( 12 );
		$this->queried                             = 11;
		$this->assertTrue( EntryGate::applies(), 'include 12: its parent page lists its products' );

		$this->options[ Options::RULE ]            = Options::RULE_EXCLUDE;
		$this->options[ Options::RULE_CATEGORIES ] = array( 11 );
		foreach ( array( 11 => false, 12 => false, 99 => true ) as $term => $want ) {
			$this->queried = $term;
			$this->assertSame( $want, EntryGate::applies(), 'exclude 11: category ' . $term );
		}

		$this->is                           = array( 'is_product_tag' => true );
		$this->options[ Options::RULE ]     = Options::RULE_INCLUDE;
		$this->options[ Options::RULE_TAGS ] = array( 5 );
		$this->queried                      = 5;
		$this->assertTrue( EntryGate::applies(), 'a chosen tag' );
		$this->queried = 6;
		$this->assertFalse( EntryGate::applies() );
	}

	public function test_settings_label_it_self_attestation_and_bound_the_input(): void {
		$page   = new Settings();
		$fields = array();
		foreach ( $page->get_settings_for_section( '' ) as $field ) {
			$fields[ $field['id'] . ':' . $field['type'] ] = $field;
		}
		$section = $fields['navexa_agecheck_gate_section:title'];
		$this->assertStringContainsString( 'self-attestation', $section['title'] );
		$this->assertStringContainsString( 'not age verification', $section['desc'] );
		$this->assertStringContainsString( '"I am 21 or older"', $section['desc'] );
		$this->assertStringContainsString( 'Corner & Co', $section['desc'] );
		$toggle = $fields[ Options::ENTRY_GATE . ':checkbox' ];
		$this->assertSame( 'no', $toggle['default'], 'off by default' );
		$this->assertStringContainsString( 'not age verification', $toggle['desc'] );
		$scope = $fields[ Options::ENTRY_GATE_SCOPE . ':select' ];
		$this->assertSame( Options::GATE_SCOPE_SITE, $scope['default'] );
		$this->assertSame( array( Options::GATE_SCOPE_SITE, Options::GATE_SCOPE_RESTRICTED ), array_keys( $scope['options'] ) );
		$days = $fields[ Options::ENTRY_GATE_DAYS . ':number' ];
		$this->assertSame( '30', $days['default'] );
		$this->assertSame( '365', $days['custom_attributes']['max'] );
		$this->assertSame( '', $fields[ Options::ENTRY_GATE_EXIT_URL . ':url' ]['default'] );

		$this->assertSame( '365', $page->sanitize_gate_days( '999', array(), '999' ) );
		$this->assertSame( '30', $page->sanitize_gate_days( '', array(), 'x' ) );
		$this->assertSame( 'https://example.org/bye', $page->sanitize_gate_exit_url( '', array(), ' https://example.org/bye ' ) );
		$this->assertSame( '', $page->sanitize_gate_exit_url( '', array(), '' ), 'blank clears it' );
		$this->options[ Options::ENTRY_GATE_EXIT_URL ] = 'https://example.org/kept';
		$this->assertSame( 'https://example.org/kept', $page->sanitize_gate_exit_url( '', array(), 'javascript:alert(1)' ), 'a bad address keeps the saved one' );
		$this->assertCount( 1, \WC_Admin_Settings::$errors );
	}

	public function test_privacy_text_mentions_the_gate_only_when_it_is_on(): void {
		$this->assertStringContainsString( 'not an age verification', Privacy::policy_text() );
		$this->assertStringContainsString( 'only in your own browser', Privacy::policy_text() );
		$this->options[ Options::ENTRY_GATE ] = 'no';
		$this->assertStringNotContainsString( 'only in your own browser', Privacy::policy_text() );
	}

	public function test_script_optimizers_are_told_to_leave_only_the_gates_tags_alone(): void {
		$src = EntryGate::script_attributes(
			array(
				'src' => 'https://shop.example/entry-gate.min.js',
				'id'  => 'navexa-agecheck-entry-gate-js',
			)
		);
		$this->assertSame( array( 'data-cfasync', 'data-no-optimize', 'data-no-defer', 'data-no-minify', 'nowprocket', 'src', 'id' ), array_keys( $src ), 'data-cfasync before src' );
		$this->assertSame( 'false', $src['data-cfasync'] );
		$this->assertSame( 'https://shop.example/entry-gate.min.js', $src['src'] );
		$inline = EntryGate::script_attributes( array( 'id' => 'navexa-agecheck-entry-gate-js-before' ) );
		$this->assertSame( '1', $inline['data-no-defer'] );
		$this->assertTrue( $inline['nowprocket'] );
		foreach ( array( array( 'id' => 'navexa-agecheck-js' ), array( 'id' => 'navexa-agecheck-entry-gate-js-after' ), array( 'src' => 'x' ), 'not-an-array' ) as $other ) {
			$this->assertSame( $other, EntryGate::script_attributes( $other ), 'other tags unchanged' );
		}
	}

	public function test_plugin_wires_the_gate_and_the_built_script_exists(): void {
		EntryGate::register();
		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( EntryGate::class, 'enqueue' ) ) );
		$this->assertNotFalse( has_filter( 'wp_script_attributes', array( EntryGate::class, 'script_attributes' ) ) );
		$this->assertNotFalse( has_filter( 'wp_inline_script_attributes', array( EntryGate::class, 'script_attributes' ) ) );
		$root = dirname( __DIR__, 3 );
		$this->assertFileExists( $root . '/assets/js/entry-gate.js' );
		$this->assertFileExists( $root . '/assets/js/entry-gate.min.js' );
		$this->assertStringContainsString( 'EntryGate::register()', (string) file_get_contents( $root . '/includes/Plugin.php' ) );
		$this->assertTrue( class_exists( Plugin::class ) );
	}
}
