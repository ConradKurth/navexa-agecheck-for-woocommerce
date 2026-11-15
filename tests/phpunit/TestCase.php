<?php
/**
 * Base test case: Brain Monkey plus in-memory options, transients, HTTP,
 * orders, Action Scheduler, and WooCommerce session, cart and customer doubles.
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

	/** @var bool What is_admin() answers. */
	public $is_admin = false;

	/** @var int What get_current_user_id() answers. */
	public $current_user = 0;

	/** @var array<int, \WP_User> get_userdata() answers. */
	public $users = array();

	/** @var array<int, array{hook: string, args: array, group: string, unique: bool, when: int, status: string}> Actions queued through the Action Scheduler stand-in. */
	public $scheduled = array();

	/** @var callable|null Called with ( $url, $args ) before each wp_remote_post() answer. */
	public $before_http = null;

	/** @var array<int, \WC_Product> wc_get_product() answers. */
	public $products = array();

	/** @var array<int, array<string, int[]>> product id => taxonomy => term ids. */
	public $product_terms = array();

	/**
	 * What WC() returns: session, cart, customer and mailer doubles.
	 *
	 * @var \WooCommerce|null
	 */
	public static $wc = null;

	/** @var FakeWpdb Options-table (Lock) and counter-table (RateLimit) SQL. */
	public $wpdb;

	/** @var array<int, \WC_Order> wc_get_order() answers by id, ahead of WC_Order::$store. */
	protected $orders = array();

	/** @var string[] wc_get_chosen_shipping_method_ids(). */
	protected $chosen_methods = array( 'flat_rate' );

	/** @var bool is_user_logged_in(). */
	protected $logged_in = false;

	/** @var array<string, mixed> Transients for user meta, keyed "{user}:{key}". */
	public $user_meta = array();

	/** @var mixed What wc_get_payment_gateway_by_order() answers. */
	public $gateway = null;

	/** @var array<int, array> wc_create_refund() calls. */
	public $refunds = array();

	/** @var mixed What wc_create_refund() answers (null: a refund object). */
	public $refund_result = null;

	/** @var array<int, int> wc_maybe_reduce_stock_levels() calls. */
	public $stock_reduced = array();

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

		\WC_Order::$store          = array();
		\WC_Order::$before_save    = array();
		\WC_Order::$after_save     = array();
		\WC_Order::$status_actions = array();
		self::$wc                   = new \WooCommerce();
		self::$wc->session          = new \WC_Session();
		self::$wc->cart             = new \WC_Cart();
		self::$wc->customer         = new \WC_Customer();
		$this->wpdb                 = new FakeWpdb( $this->options );
		$GLOBALS['wpdb']            = $this->wpdb;
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		\Navexa\AgeCheck\Start::reset();
		\Navexa\AgeCheck\Checkout\Blocks::reset();
		\Navexa\AgeCheck\Checkout\Gate::reset();
		\Navexa\AgeCheck\Express::reset();

		Functions\when( 'WC' )->alias(
			static function () {
				return self::$wc;
			}
		);

		Functions\stubs(
			array(
				'is_admin'                     => static function () use ( $test ) {
					return $test->is_admin;
				},
				'get_current_user_id'          => static function () use ( $test ) {
					return $test->current_user;
				},
				'get_userdata'                 => static function ( $id ) use ( $test ) {
					return isset( $test->users[ $id ] ) ? $test->users[ $id ] : false;
				},
				'sanitize_key'                 => static function ( $key ) {
					return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
				},
				'sanitize_textarea_field'      => static function ( $value ) {
					return trim( (string) $value );
				},
				'absint'                       => static function ( $value ) {
					return abs( (int) $value );
				},
				'is_email'                     => static function ( $email ) {
					return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
				},
				'wp_date'                      => static function ( $format, $ts = null ) {
					return gmdate( $format, null === $ts ? time() : $ts );
				},
				'rest_url'                     => static function ( $path = '' ) {
					return 'https://shop.example/wp-json/' . ltrim( $path, '/' );
				},
				'wc_get_order'                 => static function ( $id ) use ( $test ) {
					if ( isset( $test->orders[ (int) $id ] ) ) {
						return $test->orders[ (int) $id ];
					}
					return isset( \WC_Order::$store[ (int) $id ] ) ? \WC_Order::$store[ (int) $id ] : false;
				},
				'wc_get_chosen_shipping_method_ids' => static function () use ( $test ) {
					return $test->chosen_methods;
				},
				'is_user_logged_in'            => static function () use ( $test ) {
					return $test->logged_in;
				},
				'trailingslashit'              => static function ( $value ) {
					return rtrim( $value, '/\\' ) . '/';
				},
				'esc_url_raw'                  => null,
				'wp_specialchars_decode'       => static function ( $text ) {
					return htmlspecialchars_decode( (string) $text, ENT_QUOTES );
				},
				'wp_rand'                      => 1,
				'wc_get_checkout_url'          => 'https://shop.example/checkout/',
				'get_bloginfo'                 => 'en-US',
				'wp_create_nonce'              => static function ( $action ) {
					return 'nonce-' . $action;
				},
				'wc_get_product'               => static function ( $id ) use ( $test ) {
					return isset( $test->products[ $id ] ) ? $test->products[ $id ] : false;
				},
				'wc_get_product_term_ids'      => static function ( $id, $taxonomy ) use ( $test ) {
					return isset( $test->product_terms[ $id ][ $taxonomy ] ) ? $test->product_terms[ $id ][ $taxonomy ] : array();
				},
				'is_taxonomy_hierarchical'     => static function ( $taxonomy ) {
					return 'product_cat' === $taxonomy;
				},
				'get_ancestors'                => static function ( $term_id ) {
					return 12 === (int) $term_id ? array( 11 ) : array();
				},
				'wc_get_order_statuses'        => static function () {
					return array(
						'wc-pending'       => 'Pending payment',
						'wc-agecheck-hold' => 'Awaiting age check',
						'wc-processing'    => 'Processing',
						'wc-on-hold'       => 'On hold',
						'wc-completed'     => 'Completed',
						'wc-cancelled'     => 'Cancelled',
						'wc-refunded'      => 'Refunded',
						'wc-failed'        => 'Failed',
						'wc-shipped'       => 'Shipped',
					);
				},
				'wc_get_order_status_name'     => static function ( $status ) {
					return ucfirst( (string) $status );
				},
				'wc_maybe_reduce_stock_levels' => static function ( $id ) use ( $test ) {
					$test->stock_reduced[] = $id;
				},
				'wc_get_payment_gateway_by_order' => static function () use ( $test ) {
					return $test->gateway;
				},
				'wc_create_refund'             => static function ( $args ) use ( $test ) {
					$test->refunds[] = $args;
					return null === $test->refund_result ? new \stdClass() : $test->refund_result;
				},
				'wc_format_decimal'            => static function ( $value, $dp = 2 ) {
					return number_format( (float) $value, (int) $dp, '.', '' );
				},
				'wc_get_price_decimals'        => 2,
				'is_checkout_pay_page'         => false,
				'get_user_meta'                => static function ( $user, $key ) use ( $test ) {
					return isset( $test->user_meta[ $user . ':' . $key ] ) ? $test->user_meta[ $user . ':' . $key ] : '';
				},
				'update_user_meta'             => static function ( $user, $key, $value ) use ( $test ) {
					$test->user_meta[ $user . ':' . $key ] = $value;
					return true;
				},
				'as_enqueue_async_action'      => static function ( $hook, $args = array(), $group = '', $unique = false ) use ( $test ) {
					return $test->as_queue( 0, $hook, $args, $group, $unique );
				},
				'as_schedule_single_action'    => static function ( $when, $hook, $args = array(), $group = '', $unique = false ) use ( $test ) {
					return $test->as_queue( $when, $hook, $args, $group, $unique );
				},
				'as_get_scheduled_actions'     => static function ( $query = array(), $format = 'OBJECT' ) use ( $test ) {
					$ids = array();
					foreach ( $test->scheduled as $i => $action ) {
						if ( ( isset( $query['hook'] ) && $query['hook'] !== $action['hook'] )
							|| ( isset( $query['group'] ) && $query['group'] !== $action['group'] )
							|| ( array_key_exists( 'args', $query ) && null !== $query['args'] && $query['args'] !== $action['args'] )
							|| ( isset( $query['status'] ) && $query['status'] !== $action['status'] ) ) {
							continue;
						}
						$ids[] = $i + 1;
					}
					return isset( $query['per_page'] ) ? array_slice( $ids, 0, (int) $query['per_page'] ) : $ids;
				},
				'wc_get_orders'                => static function ( $args ) use ( $test ) {
					return $test->query_orders( $args );
				},
				'get_terms'                    => static function ( $args ) {
					return 'product_cat' === $args['taxonomy'] ? array( 11 => 'Vapes', 12 => 'Disposables' ) : array();
				},
				'wp_roles'                     => static function () {
					return new class() {
						public function get_names() {
							return array(
								'administrator' => 'Administrator',
								'customer'      => 'Customer',
								'wholesale'     => 'Wholesale',
							);
						}
					};
				},
				'translate_user_role'          => static function ( $name ) {
					return $name;
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
				if ( null !== $test->before_http ) {
					( $test->before_http )( $url, $args );
				}
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

	/**
	 * Action Scheduler stand-in for as_enqueue_async_action() and
	 * as_schedule_single_action(). $unique behaves as in Action Scheduler 3.9
	 * (WooCommerce 9.9): any pending action with the same hook and group, whatever
	 * its args, blocks the new one, which returns 0.
	 *
	 * @return int Action id, or 0.
	 */
	public function as_queue( int $when, string $hook, array $args, string $group, bool $unique ): int {
		if ( $unique ) {
			foreach ( $this->scheduled as $action ) {
				if ( $hook === $action['hook'] && $group === $action['group'] && 'pending' === $action['status'] ) {
					return 0;
				}
			}
		}
		$this->scheduled[] = array(
			'hook'   => $hook,
			'args'   => $args,
			'group'  => $group,
			'unique' => $unique,
			'when'   => $when,
			'status' => 'pending',
		);
		return count( $this->scheduled );
	}

	/**
	 * A small wc_get_orders(): status, meta_query (=, !=, <, NOT EXISTS, OR),
	 * limit, paged, return ids.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function query_orders( array $args ): array {
		$out = array();
		foreach ( \WC_Order::$store as $order ) {
			if ( isset( $args['status'] ) ) {
				$statuses = array_map(
					static function ( $s ) {
						return 0 === strpos( $s, 'wc-' ) ? substr( $s, 3 ) : $s;
					},
					(array) $args['status']
				);
				if ( ! in_array( $order->get_status(), $statuses, true ) ) {
					continue;
				}
			}
			if ( isset( $args['meta_query'] ) && ! $this->meta_matches( $order, $args['meta_query'] ) ) {
				continue;
			}
			// 'customer': billing emails and customer ids, as WooCommerce matches them.
			if ( isset( $args['customer'] ) ) {
				$match = false;
				foreach ( (array) $args['customer'] as $who ) {
					$match = $match || ( is_int( $who ) ? $who === (int) $order->get_customer_id() : $who === $order->get_billing_email() );
				}
				if ( ! $match ) {
					continue;
				}
			}
			// OrderQuery's posts-table bridge, as cpt_meta_query() applies it.
			if ( isset( $args[ \Navexa\AgeCheck\OrderQuery::QUERY_VAR ] ) && ! $this->meta_matches( $order, $args[ \Navexa\AgeCheck\OrderQuery::QUERY_VAR ] ) ) {
				continue;
			}
			$out[] = $order;
		}
		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
		$page   = isset( $args['paged'] ) ? (int) $args['paged'] : ( isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$offset = ( $page - 1 ) * $limit;
		$out    = array_slice( $out, $offset, $limit );
		if ( isset( $args['return'] ) && 'ids' === $args['return'] ) {
			return array_map(
				static function ( $o ) {
					return $o->get_id();
				},
				$out
			);
		}
		return $out;
	}

	private function meta_matches( \WC_Order $order, array $query ): bool {
		$relation = isset( $query['relation'] ) ? $query['relation'] : 'AND';
		unset( $query['relation'] );
		$results = array();
		foreach ( $query as $clause ) {
			if ( ! isset( $clause['key'] ) ) {
				$results[] = $this->meta_matches( $order, $clause );
				continue;
			}
			$exists  = array_key_exists( $clause['key'], $order->meta );
			$value   = $exists ? $order->meta[ $clause['key'] ] : null;
			$compare = isset( $clause['compare'] ) ? $clause['compare'] : '=';
			switch ( $compare ) {
				case 'NOT EXISTS':
					$results[] = ! $exists;
					break;
				case '!=':
					$results[] = $exists && (string) $value !== (string) $clause['value'];
					break;
				case '<':
					$results[] = $exists && (float) $value < (float) $clause['value'];
					break;
				default:
					$results[] = $exists && (string) $value === (string) $clause['value'];
			}
		}
		return 'OR' === $relation ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	/**
	 * Registers the plugin's order-save and status callbacks on the WC_Order
	 * stand-in, as WordPress would run them.
	 */
	protected function wire_order_hooks(): void {
		\WC_Order::$before_save = array(
			array( \Navexa\AgeCheck\Hold::class, 'backstop' ),
			array( \Navexa\AgeCheck\AddressGuard::class, 'guard' ),
		);
		\WC_Order::$status_actions = array(
			\Navexa\AgeCheck\Hold::STATUS => array( array( \Navexa\AgeCheck\Hold::class, 'entered' ) ),
		);
	}

	/**
	 * A restricted (rule: every product) order with one line item.
	 *
	 * @param string $status Status.
	 */
	protected function restricted_order( string $status = 'pending' ): \WC_Order {
		$order = \WC_Order::make( $status );
		$order->add_test_item( new \WC_Order_Item_Product( new \WC_Product( 7 ), 1, 20.0 ) );
		$order->add_test_item( new \WC_Order_Item_Shipping( 'flat_rate', 5.0 ) );
		return $order;
	}

	/** Every Action Scheduler call for $hook. */
	protected function scheduled_for( string $hook ): array {
		return array_values(
			array_filter(
				$this->scheduled,
				static function ( $call ) use ( $hook ) {
					return $hook === $call['hook'];
				}
			)
		);
	}

	/** The JSON body of recorded HTTP request $i. */
	protected function request_body( int $i ): array {
		return json_decode( $this->http_requests[ $i ]['args']['body'], true );
	}

	/** The RPC names called, in order. */
	protected function rpcs(): array {
		return array_map(
			static function ( $request ) {
				return substr( $request['url'], strrpos( $request['url'], '/' ) + 1 );
			},
			$this->http_requests
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		self::$wc = null;
		parent::tearDown();
	}

	/** The fake WooCommerce session. */
	protected function session(): \WC_Session {
		return self::$wc->session;
	}

	/** A complete US shipping address in WooCommerce's shape. */
	protected function us_address( array $overrides = array() ): array {
		return array_merge(
			array(
				'first_name' => 'Pat',
				'last_name'  => 'Doe',
				'address_1'  => '123 Main St',
				'address_2'  => 'Apt 4',
				'city'       => 'Springfield',
				'state'      => 'IL',
				'postcode'   => '62701-1234',
				'country'    => 'US',
				'email'      => 'pat@example.com',
			),
			$overrides
		);
	}

	/** Gives the session customer this billing and shipping address. */
	protected function customer_at( array $address, ?array $billing = null ): void {
		self::$wc->customer->shipping = $address;
		self::$wc->customer->billing  = null === $billing ? $address : $billing;
	}

	/**
	 * Runs the pre-payment gate as a cart checkout does before its order is
	 * placed: the session holds a guard for $vid at $address, AgeCheck answers
	 * PASSED, and Gate::check() admits it (what Binding::bind_checkout()
	 * binds). Leaves no recorded or queued HTTP behind.
	 *
	 * @param string                $vid     Verification id.
	 * @param array<string, string> $address WooCommerce address the shopper verified at.
	 */
	protected function admit_checkout( string $vid, array $address ): void {
		$requests  = $this->http_requests;
		$responses = $this->http_responses;
		$ship_to   = \Navexa\AgeCheck\ShipTo::from_wc_address( $address );
		$mac       = $ship_to->mac();
		\Navexa\AgeCheck\Checkout\Session::set_record( $vid, $mac, 0, \Navexa\AgeCheck\Checkout\Session::STATUS_PASSED );
		\Navexa\AgeCheck\Checkout\Session::set_guard( \Navexa\AgeCheck\GuardToken::mint_checkout_guard( \Navexa\AgeCheck\Checkout\Session::sid( true ), $vid, $mac ), $vid );
		$this->http_responses = array();
		$this->respond( 200, $this->live_info() );
		\Navexa\AgeCheck\Mode::info( Client::KEY_FULFILMENT ); // Uses the answer unless the mode is cached.
		$this->http_responses = array();
		$this->respond( 200, array( 'verification' => $this->verification( $vid, 'PASSED' ) ) );
		$this->assertNull( \Navexa\AgeCheck\Checkout\Gate::check( $ship_to, $ship_to->country() ) );
		$this->assertSame( $vid, \Navexa\AgeCheck\Checkout\Gate::admitted_verification_id() );
		$this->http_requests  = $requests;
		$this->http_responses = $responses;
	}

	/** A Verification message (proto3 JSON). */
	protected function verification( string $id, string $status, array $extra = array() ): array {
		return array_merge(
			array(
				'id'       => $id,
				'status'   => 'VERIFICATION_STATUS_' . $status,
				'livemode' => true,
			),
			'PASSED' === $status ? array( 'passClass' => 'PASS_CLASS_DOC_ONLY' ) : array(),
			$extra
		);
	}

	/**
	 * A stored order (WC_Order::make()) with one restricted line item, shipping
	 * by $method to $address (WooCommerce's address shape), billed to $billing
	 * (default: the same address).
	 *
	 * @param array<string, string>      $address Shipping address.
	 * @param string                     $method  Shipping method id.
	 * @param array<string, string>|null $billing Billing address.
	 * @param string                     $status  Status.
	 * @param int                        $id      Order id (0: the next free one).
	 */
	protected function order_at( array $address, string $method = 'flat_rate', ?array $billing = null, string $status = 'checkout-draft', int $id = 0 ): \WC_Order {
		$order = \WC_Order::make( $status, $id );
		$order->add_test_item( new \WC_Order_Item_Product( new \WC_Product( 7 ), 1, 20.0 ) );
		$order->add_test_item( new \WC_Order_Item_Shipping( $method, 5.0 ) );
		$order->set_test_address( 'shipping', $address );
		$order->set_test_address( 'billing', null === $billing ? $address : $billing );
		return $order;
	}

	/** The RPC name of the $index-th recorded HTTP request. */
	protected function request_rpc( int $index ): string {
		return substr( strrchr( $this->http_requests[ $index ]['url'], '/' ), 1 );
	}

	/** The API key the $index-th recorded HTTP request used. */
	protected function request_key( int $index ): string {
		return substr( $this->http_requests[ $index ]['args']['headers']['Authorization'], 7 );
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
				'livemode'       => true,
				'scopes'         => array( 'agecheck:verify', 'agecheck:clearance' ),
				'originAllowed'  => true,
				'minimumAge'     => 21,
				'displayName'    => 'Shop Example',
				'organizationId' => 'org_example',
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
