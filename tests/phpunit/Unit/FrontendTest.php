<?php
/**
 * Frontend script registration tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Frontend;
use Navexa\AgeCheck\Tests\TestCase;

final class FrontendTest extends TestCase {

	/** @var array<string, array{src: string, deps: string[]}> */
	private $registered = array();

	protected function setUp(): void {
		parent::setUp();
		$test = $this;
		Functions\when( 'wp_script_is' )->alias(
			static function ( $handle ) use ( $test ) {
				return isset( $test->registered[ $handle ] );
			}
		);
		Functions\when( 'wp_register_script' )->alias(
			static function ( $handle, $src, $deps, $ver = false ) use ( $test ) {
				$test->registered[ $handle ] = array(
					'src'  => $src,
					'deps' => $deps,
					'ver'  => $ver,
				);
				return true;
			}
		);
		Functions\when( 'plugins_url' )->alias(
			static function ( $path ) {
				return 'https://shop.example/wp-content/plugins/navexa-agecheck-for-woocommerce/' . $path;
			}
		);
		Functions\when( 'wp_set_script_translations' )->justReturn( true );
	}

	public function test_registers_the_bundled_loader_and_its_users(): void {
		Frontend::register_scripts();
		Frontend::register_scripts();
		$this->assertSame( array( Frontend::HANDLE_LOADER, Frontend::HANDLE_CLASSIC, Frontend::HANDLE_BLOCK ), array_keys( $this->registered ) );
		$this->assertStringEndsWith( '/assets/vendor/agecheck-js/agecheck-' . Frontend::LOADER_VERSION . '.js', $this->registered[ Frontend::HANDLE_LOADER ]['src'], 'the vendored shared loader, minified build' );
		$this->assertSame( Frontend::LOADER_VERSION, $this->registered[ Frontend::HANDLE_LOADER ]['ver'] );
		$this->assertSame( array(), $this->registered[ Frontend::HANDLE_LOADER ]['deps'], 'zero dependencies' );
		$this->assertSame( array( Frontend::HANDLE_LOADER ), $this->registered[ Frontend::HANDLE_CLASSIC ]['deps'] );
		foreach ( array( Frontend::HANDLE_LOADER, 'wc-blocks-checkout', 'wc-blocks-checkout-events', 'wc-blocks-data-store', 'wc-settings', 'wp-data', 'wp-element' ) as $dep ) {
			$this->assertContains( $dep, $this->registered[ Frontend::HANDLE_BLOCK ]['deps'], $dep );
		}
		foreach ( $this->registered as $script ) {
			$this->assertStringStartsWith( 'https://shop.example/', $script['src'], 'nothing from a third-party host' );
		}
	}

	public function test_the_built_files_exist(): void {
		$root = dirname( __DIR__, 3 );
		foreach ( array( 'checkout-classic.js', 'checkout-classic.min.js', 'gate-boot.js', 'gate-boot.min.js', 'admin-settings.js', 'admin-settings.min.js' ) as $file ) {
			$this->assertFileExists( $root . '/assets/js/' . $file );
		}
		foreach ( array( 'agecheck-', 'agecheck-gate-' ) as $build ) {
			foreach ( array( '.js', '.debug.js' ) as $suffix ) {
				$this->assertFileExists( $root . '/assets/vendor/agecheck-js/' . $build . Frontend::LOADER_VERSION . $suffix );
			}
		}
		$this->assertFileDoesNotExist( $root . '/assets/js/agecheck-1.0.0.min.js', 'the 1.0.x bundled loader is gone' );
		$builds = array_map( 'basename', (array) glob( $root . '/assets/vendor/agecheck-js/agecheck*.js' ) );
		sort( $builds );
		$this->assertSame( array( 'agecheck-' . Frontend::LOADER_VERSION . '.debug.js', 'agecheck-' . Frontend::LOADER_VERSION . '.js', 'agecheck-gate-' . Frontend::LOADER_VERSION . '.debug.js', 'agecheck-gate-' . Frontend::LOADER_VERSION . '.js' ), $builds, 'the plugin ships one loader version' );
		foreach ( array( 'block.json', 'index.js', 'index.asset.php', 'frontend.js', 'frontend.asset.php' ) as $file ) {
			$this->assertFileExists( $root . '/assets/build/verify-age/' . $file );
		}
		foreach ( array( 'block.json', 'index.js', 'index.asset.php' ) as $file ) {
			$this->assertFileExists( $root . '/assets/build/verify-age-no-payment/' . $file );
		}
		$manifest = json_decode( (string) file_get_contents( $root . '/assets/vendor/agecheck-js/manifest.json' ), true );
		$this->assertIsArray( $manifest['versions'][ Frontend::LOADER_VERSION ]['files'], 'the vendored manifest has the pinned version' );
		$this->assertStringStartsWith( '/*! @navexa/agecheck-js v' . Frontend::LOADER_VERSION . ' | MIT | ', (string) file_get_contents( $root . '/assets/vendor/agecheck-js/agecheck-' . Frontend::LOADER_VERSION . '.js' ) );
	}

	public function test_loader_url_is_the_minified_build_or_the_debug_build(): void {
		$this->assertSame( 'https://shop.example/wp-content/plugins/navexa-agecheck-for-woocommerce/assets/vendor/agecheck-js/agecheck-gate-' . Frontend::LOADER_VERSION . '.js', Frontend::loader_url( 'agecheck-gate' ) );
	}

	public function test_script_data_carries_the_modal_labels_and_no_button_copy(): void {
		$data = Frontend::script_data();
		$this->assertSame( array( 'title', 'close', 'loading', 'slow', 'newWindow', 'popup', 'reopen', 'blocked', 'samePage', 'frame', 'verified' ), array_keys( $data['labels'] ), "the loader's VERIFY_LABEL_KEYS" );
		$this->assertSame( 'Verify your age', $data['labels']['title'] );
		$this->assertSame( 'Verified', $data['labels']['verified'], 'loader 1.0.1: the pass moment' );
		// Every key of the vendored loader's DEFAULT_VERIFY_LABELS, so a sync that adds one is noticed.
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/vendor/agecheck-js/src/verify.ts' );
		$this->assertSame( 1, preg_match( '/DEFAULT_VERIFY_LABELS: Required<VerifyLabels> = \{(.*?)\n\}/s', $source, $block ) );
		preg_match_all( '/^\s+(\w+):/m', $block[1], $keys );
		$this->assertSame( $keys[1], array_keys( $data['labels'] ), 'the vendored loader\'s label keys, in order' );
		foreach ( array( 'verify', 'verifying', 'opening', 'required' ) as $gone ) {
			$this->assertArrayNotHasKey( $gone, $data['i18n'], $gone );
		}
		$this->assertStringContainsString( 'When you place it', $data['i18n']['prompt'] );
		$this->assertArrayHasKey( 'unlocked', $data['i18n'] );
		$this->assertArrayHasKey( 'edited', $data['i18n'] );
	}

	public function test_classic_data_has_a_rest_nonce_only_when_signed_in(): void {
		$data = Frontend::classic_data();
		$this->assertSame( 'https://shop.example/wp-json/wc/store/v1/', $data['storeApi'] );
		$this->assertArrayNotHasKey( 'restNonce', $data );
		$this->assertSame( 'Age verified.', $data['i18n']['passed'] );

		$this->logged_in = true;
		$this->assertSame( 'nonce-wp_rest', Frontend::classic_data()['restNonce'] );
	}

	public function test_enqueue_classic_inlines_its_data(): void {
		$inline = array();
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code, $position ) use ( &$inline ) {
				$inline[] = array( $handle, $code, $position );
				return true;
			}
		);
		Frontend::enqueue_classic();
		$this->assertSame( Frontend::HANDLE_CLASSIC, $inline[0][0] );
		$this->assertSame( 'before', $inline[0][2] );
		$this->assertStringStartsWith( 'window.navexaAgeCheckClassic = {', $inline[0][1] );
		$this->assertStringNotContainsString( '</', $inline[0][1] );
	}

	public function test_block_type_registration(): void {
		$dirs = array();
		Functions\when( 'register_block_type' )->alias(
			static function ( $dir ) use ( &$dirs ) {
				$dirs[] = $dir;
				return true;
			}
		);
		Frontend::register_block_type();
		$this->assertCount( 2, $dirs );
		$this->assertStringEndsWith( '/assets/build/verify-age', $dirs[0] );
		$this->assertStringEndsWith( '/assets/build/verify-age-no-payment', $dirs[1] );
		$meta = json_decode( (string) file_get_contents( $dirs[0] . '/block.json' ), true );
		$this->assertSame( array( 'woocommerce/checkout-payment-block' ), $meta['parent'] );
		$this->assertTrue( $meta['attributes']['lock']['default']['remove'], 'forced into the checkout' );
		$fallback = json_decode( (string) file_get_contents( $dirs[1] . '/block.json' ), true );
		$this->assertSame( array( 'woocommerce/checkout-fields-block' ), $fallback['parent'] );
		$this->assertTrue( $fallback['attributes']['lock']['default']['remove'] );
	}

	public function test_turnstile_scripts_only_when_turnstile_is_on(): void {
		$this->options[ \Navexa\AgeCheck\Options::TURNSTILE ]          = 'yes';
		$this->options[ \Navexa\AgeCheck\Options::TURNSTILE_SITE_KEY ] = '1x00000000000000000000AA';
		Frontend::register_scripts();

		$api = $this->registered[ Frontend::HANDLE_TURNSTILE_API ];
		$this->assertSame( \Navexa\AgeCheck\Turnstile::SCRIPT_URL, $api['src'] );
		$this->assertNull( $api['ver'], 'no ver query on Cloudflare\'s URL' );
		$this->assertSame( array( Frontend::HANDLE_TURNSTILE_API ), $this->registered[ Frontend::HANDLE_TURNSTILE ]['deps'] );
		$this->assertStringEndsWith( '/assets/js/turnstile.min.js', $this->registered[ Frontend::HANDLE_TURNSTILE ]['src'] );
		$this->assertContains( Frontend::HANDLE_TURNSTILE, $this->registered[ Frontend::HANDLE_CLASSIC ]['deps'] );
		$this->assertContains( Frontend::HANDLE_TURNSTILE, $this->registered[ Frontend::HANDLE_BLOCK ]['deps'] );
		$this->assertSame( array( Frontend::HANDLE_LOADER ), array_slice( $this->registered[ Frontend::HANDLE_CLASSIC ]['deps'], 0, 1 ) );
		$this->assertSame( array(), $this->registered[ Frontend::HANDLE_LOADER ]['deps'], 'the loader stays dependency-free' );

		$data = Frontend::script_data();
		$this->assertSame( '1x00000000000000000000AA', $data['turnstile']['siteKey'] );
		$this->assertSame( \Navexa\AgeCheck\Turnstile::ACTION, $data['turnstile']['action'] );
		$this->assertNotSame( '', $data['i18n']['turnstile'] );
		$root = dirname( __DIR__, 3 );
		$this->assertFileExists( $root . '/assets/js/turnstile.js' );
		$this->assertFileExists( $root . '/assets/js/turnstile.min.js' );
	}

	public function test_no_turnstile_data_while_it_is_off(): void {
		$this->assertArrayNotHasKey( 'turnstile', Frontend::script_data() );
		Frontend::register_scripts();
		$this->assertArrayNotHasKey( Frontend::HANDLE_TURNSTILE_API, $this->registered );
		$this->assertNotContains( Frontend::HANDLE_TURNSTILE, $this->registered[ Frontend::HANDLE_BLOCK ]['deps'] );
	}
}
