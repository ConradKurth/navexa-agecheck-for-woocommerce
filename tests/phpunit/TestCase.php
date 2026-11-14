<?php
/**
 * Base test case: Brain Monkey plus in-memory options, transients and HTTP.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Options;

abstract class TestCase extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	const LIVE_START_KEY      = 'txk_1111111111111111111111111111111111111111111111111111111111111111';
	const LIVE_FULFILMENT_KEY = 'txk_2222222222222222222222222222222222222222222222222222222222222222';

	/** @var array<string, mixed> */
	protected $options = array();

	/** @var array<string, bool> */
	protected $autoload = array();

	/** @var array<string, array{value: mixed, ttl: int}> */
	protected $transients = array();

	/** @var array<int, array{url: string, args: array}> */
	protected $http_requests = array();

	/** @var array<int, mixed> */
	protected $http_responses = array();

	/**
	 * The home option (Install::home()). home_url() is deliberately not
	 * stubbed: plugin code must never read the filtered address.
	 *
	 * @var string
	 */
	protected $home = 'https://shop.example';

	/** @var array<string, bool> */
	protected $caps = array(
		'manage_woocommerce' => true,
		'activate_plugins'   => true,
	);

	/** @var FakeLogger */
	protected $logger;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		\WC_Admin_Settings::$errors = array();
		$this->logger               = new FakeLogger();

		$test = $this;
		Functions\stubs(
			array(
				'wp_unslash'          => null,
				'get_current_blog_id' => 1,
				'sanitize_text_field' => static function ( $value ) {
					return trim( (string) $value );
				},
				'wp_parse_url'        => static function ( $url, $component = -1 ) {
					return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
				},
				'wp_json_encode'      => static function ( $data, $flags = 0 ) {
					return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				},
				'is_wp_error'         => static function ( $thing ) {
					return $thing instanceof \WP_Error;
				},
				'admin_url'           => static function ( $path = '' ) {
					return 'https://shop.example/wp-admin/' . ltrim( $path, '/' );
				},
				'current_user_can'    => static function ( $cap ) use ( $test ) {
					return ! empty( $test->caps[ $cap ] );
				},
				'wc_get_logger'       => static function () use ( $test ) {
					return $test->logger;
				},
				'wp_nonce_field'      => static function ( $action ) {
					echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				},
			)
		);

		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( $test ) {
				if ( 'home' === $name ) {
					return $test->home;
				}
				return array_key_exists( $name, $test->options ) ? $test->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value = '', $deprecated = '', $autoload = null ) use ( $test ) {
				if ( array_key_exists( $name, $test->options ) ) {
					return false;
				}
				$test->options[ $name ]  = $value;
				$test->autoload[ $name ] = false !== $autoload && 'no' !== $autoload;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value, $autoload = null ) use ( $test ) {
				$test->options[ $name ] = $value;
				if ( null !== $autoload || ! array_key_exists( $name, $test->autoload ) ) {
					$test->autoload[ $name ] = false !== $autoload && 'no' !== $autoload;
				}
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( $test ) {
				unset( $test->options[ $name ], $test->autoload[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			static function ( $name ) use ( $test ) {
				return isset( $test->transients[ $name ] ) ? $test->transients[ $name ]['value'] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $name, $value, $ttl = 0 ) use ( $test ) {
				$test->transients[ $name ] = array(
					'value' => $value,
					'ttl'   => $ttl,
				);
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $name ) use ( $test ) {
				unset( $test->transients[ $name ] );
				return true;
			}
		);

		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args = array() ) use ( $test ) {
				$test->http_requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				if ( empty( $test->http_responses ) ) {
					throw new \RuntimeException( 'unexpected HTTP call to ' . $url );
				}
				return array_shift( $test->http_responses );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return is_array( $response ) && isset( $response['response']['code'] ) ? $response['response']['code'] : '';
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static function ( $response, $header ) {
				if ( ! is_array( $response ) || empty( $response['headers'] ) ) {
					return '';
				}
				foreach ( $response['headers'] as $name => $value ) {
					if ( strtolower( $name ) === strtolower( $header ) ) {
						return $value;
					}
				}
				return '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Initializes the install state and stores both live keys. */
	protected function install_with_keys(): void {
		Install::ensure();
		$this->options[ Options::START_KEY ]      = self::LIVE_START_KEY;
		$this->options[ Options::FULFILMENT_KEY ] = self::LIVE_FULFILMENT_KEY;
	}

	/**
	 * Queues one HTTP answer.
	 *
	 * @param int                  $status  HTTP status.
	 * @param array|string|null    $body    Array (JSON-encoded), raw string, or null for empty.
	 * @param array<string,string> $headers Response headers.
	 */
	protected function respond( int $status, $body = array(), array $headers = array() ): void {
		$this->http_responses[] = array(
			'response' => array( 'code' => $status ),
			'body'     => is_array( $body ) ? json_encode( (object) $body ) : (string) $body, // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			'headers'  => $headers,
		);
	}

	/**
	 * Queues one transport failure.
	 *
	 * @param string $message WP_Error message.
	 */
	protected function respond_transport_error( string $message ): void {
		$this->http_responses[] = new \WP_Error( 'http_request_failed', $message );
	}

	/** A live GetIntegrationInfo answer with both scopes and an allowed origin. */
	protected function live_info( array $overrides = array() ): array {
		return array_merge(
			array(
				'livemode'      => true,
				'scopes'        => array( 'agecheck:verify', 'agecheck:clearance' ),
				'originAllowed' => true,
				'minimumAge'    => 21,
				'displayName'   => 'Shop Example',
			),
			$overrides
		);
	}

	/** Every logged message joined, for "never logs X" assertions. */
	protected function all_logs(): string {
		return implode( "\n", array_column( $this->logger->lines, 'message' ) );
	}

	protected function fulfilment_client(): Client {
		return Client::for_key( Client::KEY_FULFILMENT );
	}
}
