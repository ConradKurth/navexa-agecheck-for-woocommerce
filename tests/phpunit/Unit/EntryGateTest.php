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

	public function test_whole_site_gate_is_the_vendored_gate_build_and_a_boot_script_in_the_head(): void {
		$this->install_with_keys(); // Keys and no cached answer: still no AgeCheck call.
		$this->assertTrue( EntryGate::applies() );
		EntryGate::enqueue();

		$this->assertCount( 2, $this->enqueued );
		list( $handle, $src, $deps, $ver, $args ) = $this->enqueued[0];
		$this->assertSame( EntryGate::HANDLE_LOADER, $handle );
		$this->assertSame( 'https://shop.example/wp-content/plugins/navexa-agecheck-for-woocommerce/assets/vendor/agecheck-js/agecheck-gate-' . \Navexa\AgeCheck\Frontend::LOADER_VERSION . '.js', $src, 'the shared loader\'s gate-only build, vendored, from the store itself' );
		$this->assertSame( array(), $deps );
		$this->assertSame( \Navexa\AgeCheck\Frontend::LOADER_VERSION, $ver );
		$this->assertSame( array( 'in_footer' => false ), $args, 'in the head, so the page never flashes' );
		list( $handle, $src, $deps, , $args ) = $this->enqueued[1];
		$this->assertSame( EntryGate::HANDLE, $handle );
		$this->assertSame( 'https://shop.example/wp-content/plugins/navexa-agecheck-for-woocommerce/assets/js/gate-boot.min.js', $src );
		$this->assertSame( array( EntryGate::HANDLE_LOADER ), $deps );
		$this->assertSame( array( 'in_footer' => false ), $args );
		$this->assertSame( array(), $this->inline, 'no inline script (a script-src \'self\' CSP works)' );

		$config = EntryGate::config();
		$this->assertSame( 'attest', $config['mode'], 'Yes/No by default, as 1.0.x' );
		$this->assertSame( 21, $config['minAge'], '21 until AgeCheck has answered' );
		$this->assertSame( 30, $config['rememberDays'] );
		$this->assertArrayNotHasKey( 'exitUrl', $config );
		$this->assertArrayNotHasKey( 'logoUrl', $config );
		$this->assertSame( 'Corner & Co', $config['siteName'], 'the site title, decoded (the loader sets it as text)' );
		$this->assertSame( 'Welcome to Corner & Co', $config['title'] );
		$this->assertSame( 'You must be 21 or older to enter this store. Please confirm your age.', $config['message'], 'the 1.0.x copy' );
		$this->assertSame( 'I am 21 or older', $config['labels']['yes'] );
		$this->assertSame( 'I am under 21', $config['labels']['no'] );
		$this->assertStringContainsString( '21 or older', $config['labels']['denied'] );
		$this->assertSame( array( 'backdrop' => '#1e1e1e', 'card' => '#ffffff', 'text' => '#1e1e1e', 'accent' => '#1e1e1e', 'accentText' => '#ffffff' ), $config['theme'], 'the 1.0.x colours' );
		$this->assertSame( array( 'yes', 'no', 'submit', 'dob', 'month', 'day', 'year', 'invalid', 'denied', 'leaving' ), array_keys( $config['labels'] ), "the loader's label keys" );

		$attributes = EntryGate::script_attributes(
			array(
				'src' => 'https://shop.example/gate-boot.min.js',
				'id'  => EntryGate::HANDLE . '-js',
			)
		);
		$boot       = json_decode( $attributes['data-config'], true );
		$this->assertSame( 2, $boot['v'] );
		$this->assertSame( $config, $boot['options'] );
		$this->assertSame( array(), $this->http_requests, 'no network call' );
		$this->assertStringNotContainsString( 'txk_', $attributes['data-config'], 'no key' );
		foreach ( array( '<', '>', '&', "'" ) as $char ) {
			$this->assertStringNotContainsString( $char, $attributes['data-config'], 'hex escapes: attribute escaping never alters it' );
		}
	}

	public function test_config_uses_the_last_integration_info_and_the_merchants_choices(): void {
		$this->install_with_keys();
		$this->respond( 200, $this->live_info( array( 'minimumAge' => 18, 'displayName' => 'Vape <b>Hut</b>' ) ) );
		Mode::info( Client::KEY_FULFILMENT );
		$this->assertTrue( $this->autoload[ Options::STORE_PROFILE ], 'read on storefront pages' );
		$this->options[ Options::ENTRY_GATE_DAYS ]     = '7';
		$this->options[ Options::ENTRY_GATE_EXIT_URL ] = 'https://www.google.com/';

		$config = EntryGate::config();
		$this->assertSame( 18, $config['minAge'] );
		$this->assertSame( 'I am 18 or older', $config['labels']['yes'] );
		$this->assertSame( 'Welcome to Vape <b>Hut</b>', $config['title'], 'text, never markup: the loader uses textContent' );
		$this->assertSame( 7, $config['rememberDays'] );
		$this->assertSame( 'https://www.google.com/', $config['exitUrl'] );

		$this->http_requests = array();
		EntryGate::enqueue();
		$attributes = EntryGate::script_attributes( array( 'id' => EntryGate::HANDLE . '-js' ) );
		$this->assertSame( array(), $this->http_requests, 'the cached profile, never the network' );
		$this->assertStringContainsString( 'Vape \\u003Cb\\u003EHut', $attributes['data-config'], 'JSON_HEX_TAG: no markup in the attribute' );

		Mode::flush();
		$this->assertSame( 21, EntryGate::config()['minAge'], 'new keys: back to the default until AgeCheck answers' );

		$this->options[ Options::STORE_PROFILE ] = array(
			'minimum_age'  => 20,
			'display_name' => '  ',
		);
		$config                                  = EntryGate::config();
		$this->assertSame( 21, $config['minAge'], 'an age AgeCheck does not offer reads as 21' );
		$this->assertSame( 'Welcome to Corner & Co', $config['title'] );
		$this->site_name = '';
		$this->assertSame( 'Welcome', EntryGate::config()['title'] );
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
		$page = new Settings();
		$this->assertSame( array( '', Settings::SECTION_GATE ), array_keys( $page->get_sections() ), 'the gate has its own section' );
		$fields = array();
		foreach ( $page->get_settings_for_section( Settings::SECTION_GATE ) as $field ) {
			$fields[ $field['id'] . ':' . $field['type'] ] = $field;
		}
		$section = $fields['navexa_agecheck_gate_section:title'];
		$this->assertStringContainsString( 'self-attestation', $section['title'] );
		$this->assertStringContainsString( 'not age verification', $section['desc'] );
		$this->assertStringContainsString( 'Corner & Co', $section['desc'] );
		$toggle = $fields[ Options::ENTRY_GATE . ':checkbox' ];
		$this->assertSame( 'no', $toggle['default'], 'off by default' );
		$this->assertStringContainsString( 'not age verification', $toggle['desc'] );
		$scope = $fields[ Options::ENTRY_GATE_SCOPE . ':select' ];
		$this->assertSame( Options::GATE_SCOPE_SITE, $scope['default'] );
		$this->assertSame( array( Options::GATE_SCOPE_SITE, Options::GATE_SCOPE_RESTRICTED ), array_keys( $scope['options'] ) );
		$mode = $fields[ Options::ENTRY_GATE_MODE . ':select' ];
		$this->assertSame( Options::GATE_MODE_ATTEST, $mode['default'] );
		$this->assertSame( array( Options::GATE_MODE_ATTEST, Options::GATE_MODE_DOB ), array_keys( $mode['options'] ) );
		$this->assertStringContainsString( 'never stored or sent', $mode['desc'] );
		$this->assertStringContainsString( 'self-attestation', $mode['desc'] );
		$age = $fields[ Options::ENTRY_GATE_MIN_AGE . ':select' ];
		$this->assertSame( array( '18', '19', '21' ), array_map( 'strval', array_keys( $age['options'] ) ), 'AgeCheck has not reported an age: the gate\'s own' );
		$days = $fields[ Options::ENTRY_GATE_DAYS . ':number' ];
		$this->assertSame( '30', $days['default'] );
		$this->assertSame( '365', $days['custom_attributes']['max'] );
		$this->assertSame( '', $fields[ Options::ENTRY_GATE_EXIT_URL . ':url' ]['default'] );
		$this->assertArrayHasKey( Options::ENTRY_GATE_LOGO . ':' . Settings::FIELD_MEDIA, $fields );
		foreach ( Options::GATE_THEME_DEFAULTS as $key => $default ) {
			$this->assertSame( $default, $fields[ Options::ENTRY_GATE_THEME . '[' . $key . ']:color' ]['default'], $key );
		}
		$this->assertSame( 'textarea', $fields[ Options::ENTRY_GATE_TEXT . '[message]:textarea' ]['type'] );
		$this->assertSame( 'I am 21 or older', $fields[ Options::ENTRY_GATE_TEXT . '[yes]:text' ]['placeholder'], 'blank shows the default it stands for' );
		$this->assertSame( '40', $fields[ Options::ENTRY_GATE_TEXT . '[yes]:text' ]['custom_attributes']['maxlength'] );

		$this->assertSame( '365', $page->sanitize_gate_days( '999', array(), '999' ) );
		$this->assertSame( '30', $page->sanitize_gate_days( '', array(), 'x' ) );
		$this->assertSame( 'https://example.org/bye', $page->sanitize_gate_exit_url( '', array(), ' https://example.org/bye ' ) );
		$this->assertSame( '', $page->sanitize_gate_exit_url( '', array(), '' ), 'blank clears it' );
		$this->options[ Options::ENTRY_GATE_EXIT_URL ] = 'https://example.org/kept';
		$this->assertSame( 'https://example.org/kept', $page->sanitize_gate_exit_url( '', array(), 'javascript:alert(1)' ), 'a bad address keeps the saved one' );
		$this->assertCount( 1, \WC_Admin_Settings::$errors );
	}

	public function test_minimum_age_is_the_dashboards_with_verification_on_else_the_gates_own(): void {
		$this->options[ Options::ENTRY_GATE_MIN_AGE ] = '18';
		$this->assertSame( 18, Options::entry_gate_minimum_age(), 'AgeCheck has not reported one' );
		$this->options[ Options::STORE_PROFILE ] = array( 'minimum_age' => 21 );
		$this->assertSame( 21, Options::entry_gate_minimum_age(), 'verification on: the age checkout verifies' );
		$page   = new Settings();
		$fields = array();
		foreach ( $page->get_settings_for_section( Settings::SECTION_GATE ) as $field ) {
			$fields[ $field['id'] . ':' . $field['type'] ] = $field;
		}
		$note = $fields[ Options::ENTRY_GATE_MIN_AGE . ':' . Settings::FIELD_NOTE ];
		$this->assertFalse( $note['is_option'], 'read-only: the saved local age is kept, unposted' );
		$this->assertStringContainsString( '21, set in the Navexa dashboard', $note['desc'] );
		$this->options[ Options::MODE ] = Options::MODE_GATE_ONLY;
		$this->assertSame( 18, Options::entry_gate_minimum_age(), 'verification off: the gate\'s own' );
		$this->options[ Options::ENTRY_GATE_MIN_AGE ] = '20';
		$this->assertSame( 21, Options::entry_gate_minimum_age(), 'only 18, 19 or 21' );
	}

	public function test_contrast_ratio_matches_the_loaders_vectors(): void {
		$root    = dirname( __DIR__, 3 );
		$vectors = json_decode( (string) file_get_contents( $root . '/assets/vendor/agecheck-js/schema/' . \Navexa\AgeCheck\Frontend::LOADER_VERSION . '/vectors/contrast.json' ), true );
		$this->assertNotEmpty( $vectors['cases'] );
		foreach ( $vectors['cases'] as $case ) {
			$ratio = Options::contrast_ratio( $case['fg'], $case['bg'] );
			$this->assertEqualsWithDelta( $case['ratio'], round( $ratio, 2 ), 0.001, $case['fg'] . ' on ' . $case['bg'] );
			$this->assertSame( $case['pass'], $ratio >= Options::MIN_CONTRAST, $case['fg'] . ' on ' . $case['bg'] );
		}
		$this->assertSame( 1.0, Options::contrast_ratio( 'red', '#fff' ), 'not a colour' );
		$this->assertSame( '#abc', Options::hex_color( ' #ABC ' ) );
		$this->assertSame( '', Options::hex_color( '#abcd' ) );
	}

	public function test_theme_defaults_bad_values_and_a_low_contrast_pair_fall_back(): void {
		$this->assertSame( Options::GATE_THEME_DEFAULTS, Options::entry_gate_theme() );
		$this->options[ Options::ENTRY_GATE_THEME ] = array(
			'backdrop'    => '#0B5',
			'card'        => 'url(x)',
			'text'        => '#111111',
			'accent'      => '#ffffff',
			'accent_text' => '#eeeeee',
		);
		$theme = Options::entry_gate_theme();
		$this->assertSame( '#0b5', $theme['backdrop'], 'lower-cased' );
		$this->assertSame( '#ffffff', $theme['card'], 'not a colour: the default' );
		$this->assertSame( '#111111', $theme['text'] );
		$this->assertSame( array( '#1e1e1e', '#ffffff' ), array( $theme['accent'], $theme['accent_text'] ), 'a pair under 4.5:1 falls back to its default pair' );
	}

	public function test_settings_save_refuses_a_low_contrast_pair_and_keeps_the_saved_colours(): void {
		$page                    = new Settings();
		$this->options[ Options::ENTRY_GATE_THEME ] = array( 'text' => '#222222' );
		$_POST[ Options::ENTRY_GATE_THEME ] = array(
			'backdrop'    => '#000000',
			'card'        => '#ffffff',
			'text'        => '#777777',
			'accent'      => '#0a6b3d',
			'accent_text' => '#ffffff',
		);
		$field = static function ( string $key ): array {
			return array( 'id' => Options::ENTRY_GATE_THEME . '[' . $key . ']' );
		};
		$this->assertNull( $page->sanitize_gate_color( '', $field( 'text' ), '#777777' ), '#777 on white is 4.477:1: kept' );
		$this->assertNull( $page->sanitize_gate_color( '', $field( 'card' ), '#ffffff' ), 'both colours of the pair are kept' );
		$this->assertSame( '#0a6b3d', $page->sanitize_gate_color( '', $field( 'accent' ), '#0A6B3D' ), 'a passing pair saves (6.6:1)' );
		$this->assertSame( '#ffffff', $page->sanitize_gate_color( '', $field( 'accent_text' ), '#ffffff' ) );
		$this->assertSame( '#000000', $page->sanitize_gate_color( '', $field( 'backdrop' ), '#000000' ), 'the backdrop carries no text' );
		$this->assertSame( '#1e1e1e', $page->sanitize_gate_color( '', $field( 'backdrop' ), '' ), 'blank is the default' );
		$this->assertCount( 1, \WC_Admin_Settings::$errors, 'one error per refused pair, not per field' );
		$this->assertStringContainsString( 'Text on the card has a contrast of 4.47:1; it needs at least 4.5:1', \WC_Admin_Settings::$errors[0] );
		$this->assertNull( $page->sanitize_gate_color( '', $field( 'card' ), 'red' ) );
		$this->assertStringContainsString( 'hex colours', \WC_Admin_Settings::$errors[1] );
		$this->assertNull( $page->sanitize_gate_color( '', array( 'id' => Options::ENTRY_GATE_THEME . '[evil]' ), '#000' ) );
		$this->assertSame( '4.49', Settings::ratio_text( 4.4999 ), 'never rounds up to 4.50' );
		unset( $_POST[ Options::ENTRY_GATE_THEME ] );
	}

	public function test_settings_save_gate_text_as_plain_text_within_the_loaders_limits(): void {
		$page  = new Settings();
		$field = static function ( string $key ): array {
			return array( 'id' => Options::ENTRY_GATE_TEXT . '[' . $key . ']' );
		};
		$this->assertSame( 'Over {age}? Welcome to {site}', $page->sanitize_gate_text( '', $field( 'title' ), ' Over {age}? Welcome to {site} ' ) );
		$this->assertSame( 'Hi there', $page->sanitize_gate_text( '', $field( 'yes' ), "Hi\tthere " ), 'control characters fold to spaces, as the loader reads them' );
		$this->assertNull( $page->sanitize_gate_text( '', $field( 'yes' ), str_repeat( 'a', 41 ) ), 'over 40: kept' );
		$this->assertStringContainsString( 'longer than 40', \WC_Admin_Settings::$errors[0] );
		$this->assertSame( str_repeat( 'é', 40 ), $page->sanitize_gate_text( '', $field( 'yes' ), str_repeat( 'é', 40 ) ), 'counted in code points' );
		$this->assertSame( '', $page->sanitize_gate_text( '', $field( 'message' ), '' ), 'blank: the default' );
		$this->options[ Options::ENTRY_GATE_TEXT ] = array(
			'title'   => 'Hello {site}',
			'yes'     => str_repeat( 'x', 41 ),
			'message' => array( 'not text' ),
		);
		$text = Options::entry_gate_text();
		$this->assertSame( 'Hello {site}', $text['title'] );
		$this->assertSame( '', $text['yes'], 'too long for the loader: the default' );
		$this->assertSame( '', $text['message'] );
		$config = EntryGate::config();
		$this->assertSame( 'Hello {site}', $config['title'], 'the loader fills in {site}' );
		$this->assertSame( 'I am 21 or older', $config['labels']['yes'] );
	}

	public function test_dob_mode_and_the_logo_only_when_it_is_an_https_image(): void {
		$this->options[ Options::ENTRY_GATE_MODE ] = Options::GATE_MODE_DOB;
		$config = EntryGate::config();
		$this->assertSame( 'dob', $config['mode'] );
		$this->assertSame( 'You must be 21 or older to enter this store. Please enter your date of birth.', $config['message'] );
		$this->assertSame( 'Date of birth', $config['labels']['dob'] );
		$this->assertStringContainsString( 'date of birth', Privacy::policy_text() );
		$this->assertStringContainsString( 'never stored or sent', Privacy::policy_text() );

		$images = array( 7 => 'https://shop.example/wp-content/uploads/logo-300x100.png', 8 => 'http://shop.example/logo.png' );
		Functions\when( 'wp_attachment_is_image' )->alias(
			static function ( $id ) use ( $images ) {
				return isset( $images[ (int) $id ] );
			}
		);
		Functions\when( 'wp_get_attachment_image_url' )->alias(
			static function ( $id ) use ( $images ) {
				return isset( $images[ (int) $id ] ) ? $images[ (int) $id ] : false;
			}
		);
		$this->options[ Options::ENTRY_GATE_LOGO ] = '7';
		$this->assertSame( 'https://shop.example/wp-content/uploads/logo-300x100.png', EntryGate::config()['logoUrl'] );
		$this->options[ Options::ENTRY_GATE_LOGO ] = '8';
		$this->assertArrayNotHasKey( 'logoUrl', EntryGate::config(), 'an http logo is mixed content: the loader would ignore it' );
		$this->options[ Options::ENTRY_GATE_LOGO ] = '9';
		$this->assertSame( 0, Options::entry_gate_logo_id(), 'not an image' );

		$page = new Settings();
		$this->assertSame( '7', $page->sanitize_gate_logo( '', array(), '7' ) );
		$this->assertSame( '', $page->sanitize_gate_logo( '', array(), '' ), 'blank removes it' );
		$this->assertNull( $page->sanitize_gate_logo( '', array(), '9' ), 'not an image: kept' );
		$this->assertNull( $page->sanitize_gate_logo( '', array(), '7 or 1=1' ) );
		$this->assertCount( 2, \WC_Admin_Settings::$errors );
	}

	public function test_gate_only_mode_needs_no_key_and_the_gate_still_serves(): void {
		$this->options[ Options::MODE ] = Options::MODE_GATE_ONLY;
		$this->assertFalse( Options::verification_enabled() );
		$this->assertTrue( EntryGate::applies() );
		EntryGate::enqueue();
		$this->assertCount( 2, $this->enqueued );
		$this->assertSame( array(), $this->http_requests );
		$this->assertStringContainsString( 'Age verification is off', ( new Settings() )->get_settings_for_section( Settings::SECTION_GATE )[0]['desc'] );
		$policy = Privacy::policy_text();
		$this->assertStringContainsString( 'only in your own browser', $policy );
		$this->assertStringNotContainsString( 'Didit', $policy, 'nothing about new orders goes to Navexa' );
		$this->assertStringNotContainsString( 'keeps nothing with your orders', $policy );
		$this->assertStringContainsString( 'orders still on hold from before the switch are still checked with Navexa AgeCheck until they are released, cancelled or refunded', $policy, 'held orders still reach AgeCheck' );
		$this->assertStringContainsString( 'keep the age verification part of your privacy policy', $policy );
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
				'src' => 'https://shop.example/agecheck-gate-1.0.0.js',
				'id'  => 'navexa-agecheck-gate-loader-js',
			)
		);
		$this->assertSame( array( 'data-cfasync', 'data-no-optimize', 'data-no-defer', 'data-no-minify', 'nowprocket', 'src', 'id' ), array_keys( $src ), 'data-cfasync before src' );
		$this->assertSame( 'false', $src['data-cfasync'] );
		$this->assertSame( 'https://shop.example/agecheck-gate-1.0.0.js', $src['src'] );
		$boot = EntryGate::script_attributes( array( 'id' => 'navexa-agecheck-entry-gate-js' ) );
		$this->assertSame( '1', $boot['data-no-defer'] );
		$this->assertTrue( $boot['nowprocket'] );
		$this->assertArrayHasKey( 'data-config', $boot );
		$this->assertArrayNotHasKey( 'data-config', $src, 'the configuration rides on the boot script only' );
		foreach ( array( array( 'id' => 'navexa-agecheck-js' ), array( 'id' => 'navexa-agecheck-entry-gate-js-before' ), array( 'src' => 'x' ), 'not-an-array' ) as $other ) {
			$this->assertSame( $other, EntryGate::script_attributes( $other ), 'other tags unchanged' );
		}
	}

	public function test_plugin_wires_the_gate_and_the_built_script_exists(): void {
		EntryGate::register();
		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( EntryGate::class, 'enqueue' ) ) );
		$this->assertNotFalse( has_filter( 'wp_script_attributes', array( EntryGate::class, 'script_attributes' ) ) );
		$root = dirname( __DIR__, 3 );
		$this->assertFileExists( $root . '/assets/js/gate-boot.js' );
		$this->assertFileExists( $root . '/assets/js/gate-boot.min.js' );
		$this->assertFileExists( $root . '/assets/js/entry-gate.min.js', 'the 1.0.x gate stays for pages a cache stored under 1.0.x' );
		$this->assertStringContainsString( 'EntryGate::register()', (string) file_get_contents( $root . '/includes/Plugin.php' ) );
		$this->assertTrue( class_exists( Plugin::class ) );
	}
}
