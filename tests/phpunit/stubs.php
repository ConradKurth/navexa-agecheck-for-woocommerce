<?php
/**
 * Minimal stand-ins for the WordPress and WooCommerce classes the unit suite
 * touches. Functions are stubbed per test with Brain Monkey (TestCase).
 *
 * @package Navexa\AgeCheck\Tests
 */

// phpcs:ignoreFile -- test stubs mirror third-party class names.

namespace {

	class WP_Error {
		public $codes    = array();
		public $messages = array();

		public function __construct( $code = '', $message = '' ) {
			if ( '' !== $code ) {
				$this->add( $code, $message );
			}
		}

		public function add( $code, $message ) {
			$this->codes[]    = $code;
			$this->messages[] = $message;
		}

		public function get_error_code() {
			return isset( $this->codes[0] ) ? $this->codes[0] : '';
		}

		public function get_error_message() {
			return isset( $this->messages[0] ) ? $this->messages[0] : '';
		}

		public function get_error_codes() {
			return $this->codes;
		}
	}

	class WC_Settings_Page {
		protected $id    = '';
		protected $label = '';

		public function __construct() {}

		public function get_id() {
			return $this->id;
		}

		public function get_label() {
			return $this->label;
		}

		public function get_settings_for_section( $section_id ) {
			return '' === $section_id ? $this->get_settings_for_default_section() : array();
		}

		protected function get_settings_for_default_section() {
			return array();
		}
	}

	class WC_Admin_Settings {
		public static $errors = array();

		public static function add_error( $text ) {
			self::$errors[] = $text;
		}
	}

	/** In-memory WooCommerce session (with WC_Session_Handler's cookie methods). */
	class WC_Session {
		public $data        = array();
		public $customer_id = 't_0123456789abcdef';
		/** @var bool Whether the visitor has a session (cookie). */
		public $has_session = true;
		/** @var bool Whether set_customer_session_cookie( true ) can start one. */
		public $can_start = true;
		/** @var int set_customer_session_cookie( true ) calls. */
		public $cookie_set = 0;

		public function get_customer_id() {
			return $this->customer_id;
		}

		public function has_session() {
			return $this->has_session;
		}

		public function set_customer_session_cookie( $set ) {
			if ( $set ) {
				++$this->cookie_set;
				$this->has_session = $this->has_session || $this->can_start;
			}
		}

		public function get( $key, $default = null ) {
			return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
		}

		public function set( $key, $value ) {
			if ( null === $value ) {
				unset( $this->data[ $key ] );
				return;
			}
			$this->data[ $key ] = $value;
		}

		public function __unset( $key ) {
			unset( $this->data[ $key ] );
		}
	}

	class WC_Cart {
		public $empty          = false;
		public $needs_shipping = true;
		public $hash           = 'cart-hash';
		/** @var array<string, array> Cart items: one restricted product by default (rule: every product). */
		public $items;

		public function __construct() {
			$this->items = array( 'item-1' => array( 'data' => new WC_Product( 7 ), 'quantity' => 1 ) );
		}

		public function is_empty() {
			return $this->empty;
		}

		public function get_cart() {
			return $this->empty ? array() : $this->items;
		}

		public function needs_shipping_address() {
			return $this->needs_shipping;
		}

		public function get_cart_hash() {
			return $this->hash;
		}
	}

	class WC_Customer {
		public $billing  = array();
		public $shipping = array();

		public function __call( $name, $args ) {
			if ( preg_match( '/^get_(billing|shipping)_(.+)$/', $name, $m ) ) {
				$set = 'billing' === $m[1] ? $this->billing : $this->shipping;
				return isset( $set[ $m[2] ] ) ? $set[ $m[2] ] : '';
			}
			if ( preg_match( '/^set_(billing|shipping)_(.+)$/', $name, $m ) ) {
				$this->{$m[1]}[ $m[2] ] = $args[0];
				return null;
			}
			throw new \BadMethodCallException( $name );
		}

		public function get_billing() {
			return $this->billing;
		}
	}

	class WP_User {
		public $ID           = 0;
		public $roles        = array();
		public $display_name = '';

		public function __construct( $id = 0, $roles = array(), $display_name = '' ) {
			$this->ID           = $id;
			$this->roles        = $roles;
			$this->display_name = $display_name;
		}
	}

	class WP_Screen {
		public $id = '';
	}

	class WP_Post {
		public $ID = 0;

		public function __construct( $id = 0 ) {
			$this->ID = $id;
		}
	}

	class WP_REST_Request {
		private $body;
		private $headers;

		public function __construct( $body = '', $headers = array() ) {
			$this->body    = $body;
			$this->headers = array_change_key_case( $headers, CASE_LOWER );
		}

		public function get_body() {
			return $this->body;
		}

		public function get_header( $name ) {
			$name = strtolower( $name );
			return isset( $this->headers[ $name ] ) ? $this->headers[ $name ] : null;
		}
	}

	class WP_REST_Response {
		private $data;
		private $status;

		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}
	}

	class WC_Product {
		public $id        = 0;
		public $parent_id = 0;
		public $meta      = array();
		/** @var int[] get_children(): a variable product's variation ids. */
		public $children  = array();

		public function __construct( $id = 0, $parent_id = 0, $meta = array() ) {
			$this->id        = $id;
			$this->parent_id = $parent_id;
			$this->meta      = $meta;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_parent_id() {
			return $this->parent_id;
		}

		public function get_children() {
			return $this->children;
		}

		public function get_meta( $key, $single = true, $context = 'view' ) {
			return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
		}

		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}

		public function delete_meta_data( $key ) {
			unset( $this->meta[ $key ] );
		}
	}

	class WC_Order_Item {
		public $type  = '';
		public $total = 0.0;
		public $taxes = array( 'total' => array() );

		public function get_type() {
			return $this->type;
		}

		public function get_total() {
			return $this->total;
		}

		public function get_taxes() {
			return $this->taxes;
		}

		public function is_type( $type ) {
			return $type === $this->type;
		}
	}

	class WC_Order_Item_Product extends WC_Order_Item {
		public $type = 'line_item';
		public $product;
		public $quantity = 1;

		public function __construct( $product = null, $quantity = 1, $total = 10.0 ) {
			$this->product  = $product;
			$this->quantity = $quantity;
			$this->total    = $total;
		}

		public function get_product() {
			return $this->product;
		}

		public function get_quantity() {
			return $this->quantity;
		}
	}

	class WC_Order_Item_Shipping extends WC_Order_Item {
		public $type = 'shipping';
		public $method_id;

		public function __construct( $method_id = 'flat_rate', $total = 5.0 ) {
			$this->method_id = $method_id;
			$this->total     = $total;
		}

		public function get_method_id() {
			return $this->method_id;
		}
	}

	class WC_Order_Item_Fee extends WC_Order_Item {
		public $type = 'fee';
	}

	/**
	 * In-memory stand-in for WC_Order: props with change tracking, meta,
	 * items, notes, and a save() that runs the before/after-save callbacks
	 * and the status-transition callbacks the test registers (Brain Monkey
	 * records add_action() calls but never runs them).
	 */
	class WC_Order {
		/** @var array<int, WC_Order> */
		public static $store = array();
		/** @var callable[] */
		public static $before_save = array();
		/** @var callable[] */
		public static $after_save = array();
		/** @var array<string, callable[]> status => callbacks( id, order, transition ) */
		public static $status_actions = array();
		public static $next_id = 100;

		public $id   = 0;
		public $type = 'shop_order';
		public $data = array(
			'status'         => '',
			'customer_id'    => 0,
			'payment_method' => 'stripe',
			'date_created'   => null,
			'date_completed' => null,
			'billing'        => array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'company'    => '',
				'address_1'  => '1 Main St',
				'address_2'  => '',
				'city'       => 'Springfield',
				'state'      => 'IL',
				'postcode'   => '62701',
				'country'    => 'US',
				'email'      => 'ada@example.com',
				'phone'      => '',
			),
			'shipping'       => array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'company'    => '',
				'address_1'  => '1 Main St',
				'address_2'  => '',
				'city'       => 'Springfield',
				'state'      => 'IL',
				'postcode'   => '62701-1234',
				'country'    => 'US',
				'phone'      => '',
			),
		);
		public $changes          = array();
		public $meta             = array();
		public $items            = array(
			'line_item' => array(),
			'shipping'  => array(),
			'fee'       => array(),
		);
		public $notes            = array();
		public $saves            = 0;
		public $meta_saves       = 0;
		/** @var array<string, mixed> The meta as of the last save_meta_data() or save(). */
		public $saved_meta       = array();
		/** @var bool|null needs_shipping_address(); null: as WooCommerce decides it, from the shipping lines. */
		public $needs_shipping   = null;
		public $needs_processing = true;
		/** @var array|false The pending transition, named as in WC_Order (Hold resets it by reflection). */
		public $status_transition = false;
		/** @var array<int, array> Transitions whose status actions ran on save. */
		public $fired            = array();
		public $remaining_refund = 25.0;
		public $total_refunded   = 0.0;
		/** @var string What has_cart_hash() matches. */
		public $cart_hash        = 'cart-hash';

		public function __construct( $id = 0 ) {
			$this->id = $id;
		}

		/** Creates, stores and returns an order with an id and status. */
		public static function make( $status = 'pending', $id = 0 ) {
			$order                 = new self( $id > 0 ? $id : self::$next_id++ );
			$order->data['status'] = $status;
			self::$store[ $order->id ] = $order;
			return $order;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_order_number() {
			return (string) $this->id;
		}

		public function get_type() {
			return $this->type;
		}

		public function get_status( $context = 'view' ) {
			return array_key_exists( 'status', $this->changes ) ? $this->changes['status'] : $this->data['status'];
		}

		public function set_status( $new_status, $note = '', $manual = false ) {
			$new_status = 0 === strpos( $new_status, 'wc-' ) ? substr( $new_status, 3 ) : $new_status;
			$from       = $this->get_status();
			$this->changes['status'] = $new_status;
			if ( '' !== $from && $from !== $new_status ) {
				$this->status_transition = array(
					'from'   => $this->status_transition ? $this->status_transition['from'] : $from,
					'to'     => $new_status,
					'note'   => $note,
					'manual' => $manual,
				);
			}
			return array(
				'from' => $from,
				'to'   => $new_status,
			);
		}

		public function update_status( $new_status, $note = '', $manual = false ) {
			$this->set_status( $new_status, $note, $manual );
			$this->save();
			return true;
		}

		public function get_changes() {
			return $this->changes;
		}

		public function get_base_data() {
			return array_merge( array( 'id' => $this->id ), $this->data );
		}

		public function get_meta( $key, $single = true, $context = 'view' ) {
			return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
		}

		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}

		public function delete_meta_data( $key ) {
			unset( $this->meta[ $key ] );
		}

		public function save_meta_data() {
			++$this->meta_saves;
			$this->saved_meta = $this->meta;
		}

		public function save() {
			foreach ( self::$before_save as $callback ) {
				$callback( $this, null );
			}
			if ( ! $this->id ) {
				$this->id = self::$next_id++;
			}
			self::$store[ $this->id ] = $this;
			foreach ( $this->changes as $key => $value ) {
				if ( is_array( $value ) ) {
					$this->data[ $key ] = array_merge( $this->data[ $key ], $value );
				} else {
					$this->data[ $key ] = $value;
				}
			}
			$this->changes    = array();
			$this->saved_meta = $this->meta;
			++$this->saves;
			foreach ( self::$after_save as $callback ) {
				$callback( $this, null );
			}
			$transition              = $this->status_transition;
			$this->status_transition = false;
			if ( $transition ) {
				$this->fired[] = $transition;
				if ( is_string( $transition['note'] ) && '' !== $transition['note'] ) {
					$this->notes[] = $transition['note'] . ' [status ' . $transition['from'] . ' -> ' . $transition['to'] . ']';
				}
				$callbacks = isset( self::$status_actions[ $transition['to'] ] ) ? self::$status_actions[ $transition['to'] ] : array();
				foreach ( $callbacks as $callback ) {
					$callback( $this->id, $this, $transition );
				}
			}
			return $this->id;
		}

		public function add_order_note( $note ) {
			$this->notes[] = $note;
			return count( $this->notes );
		}

		public function get_items( $types = 'line_item' ) {
			$out = array();
			foreach ( (array) $types as $type ) {
				foreach ( isset( $this->items[ $type ] ) ? $this->items[ $type ] : array() as $id => $item ) {
					$out[ $id ] = $item;
				}
			}
			return $out;
		}

		public function add_test_item( $item ) {
			static $next = 1000;
			$this->items[ $item->get_type() ][ $next++ ] = $item;
		}

		public function get_shipping_methods() {
			return isset( $this->items['shipping'] ) ? $this->items['shipping'] : array();
		}

		/** WC_Order::needs_shipping_address(): a shipping line that is not local pickup (unless set). */
		public function needs_shipping_address() {
			if ( null !== $this->needs_shipping ) {
				return $this->needs_shipping;
			}
			foreach ( $this->get_shipping_methods() as $line ) {
				if ( ! in_array( $line->get_method_id(), array( 'legacy_local_pickup', 'local_pickup', 'pickup_location' ), true ) ) {
					return true;
				}
			}
			return false;
		}

		public function needs_processing() {
			return $this->needs_processing;
		}

		public function get_customer_id() {
			return $this->data['customer_id'];
		}

		public function get_date_created() {
			return $this->data['date_created'];
		}

		public function get_date_completed() {
			return $this->data['date_completed'];
		}

		public function get_payment_method() {
			return $this->data['payment_method'];
		}

		public function get_checkout_order_received_url() {
			return 'https://shop.example/checkout/order-received/' . $this->id . '/?key=' . $this->get_order_key();
		}

		public function get_order_key() {
			return isset( $this->data['order_key'] ) ? $this->data['order_key'] : 'wc_order_test';
		}

		public function get_remaining_refund_amount() {
			return $this->remaining_refund;
		}

		public function get_total_refunded() {
			return $this->total_refunded;
		}

		public function get_qty_refunded_for_item( $item_id, $item_type = 'line_item' ) {
			return 0;
		}

		public function get_total_refunded_for_item( $item_id, $item_type = 'line_item' ) {
			return 0;
		}

		public function get_tax_refunded_for_item( $item_id, $tax_id, $item_type = 'line_item' ) {
			return 0;
		}

		public function get_last_save_error() {
			return null;
		}

		public function has_cart_hash( $hash ) {
			return $hash === $this->cart_hash;
		}

		public function has_status( $statuses ) {
			return in_array( $this->get_status(), (array) $statuses, true );
		}

		public function get_address( $type = 'billing' ) {
			$out = array();
			foreach ( $this->data[ 'shipping' === $type ? 'shipping' : 'billing' ] as $field => $value ) {
				$out[ $field ] = $this->{'get_' . ( 'shipping' === $type ? 'shipping' : 'billing' ) . '_' . $field}();
			}
			return $out;
		}

		/** Replaces the saved $type address (no change tracking); fields left out become ''. */
		public function set_test_address( $type, array $address ) {
			foreach ( array_keys( $this->data[ $type ] ) as $field ) {
				$this->data[ $type ][ $field ] = isset( $address[ $field ] ) ? (string) $address[ $field ] : '';
			}
			if ( 'billing' === $type && isset( $address['email'] ) ) {
				$this->data['billing']['email'] = (string) $address['email'];
			}
		}

		/** Removes every item of $type (line_item, shipping, fee). */
		public function clear_test_items( $type ) {
			$this->items[ $type ] = array();
		}

		/** get_/set_ billing_* and shipping_* address props, tracked like WC_Order. */
		public function __call( $name, $args ) {
			if ( preg_match( '/^(get|set)_(billing|shipping)_(\w+)$/', $name, $m ) && array_key_exists( $m[3], $this->data[ $m[2] ] ) ) {
				if ( 'get' === $m[1] ) {
					return isset( $this->changes[ $m[2] ][ $m[3] ] ) ? $this->changes[ $m[2] ][ $m[3] ] : $this->data[ $m[2] ][ $m[3] ];
				}
				if ( $args[0] !== $this->data[ $m[2] ][ $m[3] ] || isset( $this->changes[ $m[2] ][ $m[3] ] ) ) {
					$this->changes[ $m[2] ][ $m[3] ] = $args[0];
				}
				return null;
			}
			throw new BadMethodCallException( $name );
		}
	}
	if ( ! class_exists( 'Normalizer' ) ) {
		/**
		 * Stand-in for ext-intl's Normalizer (not in the php Docker images):
		 * composes the few decomposed sequences the tests use.
		 */
		class Normalizer {
			const FORM_C = 16;

			public static function normalize( $string, $form = self::FORM_C ) {
				return strtr( $string, array( "e\u{0301}" => "\u{00e9}", "E\u{0301}" => "\u{00c9}", "n\u{0303}" => "\u{00f1}" ) );
			}
		}
	}

	/** WC() container (TestCase stubs WC() to return one). */
	class WooCommerce {
		public $session;
		public $cart;
		public $customer;
		public $countries;
		/** @var \Navexa\AgeCheck\Tests\FakeMailer */
		public $mailer;

		public function __construct() {
			$this->mailer = new \Navexa\AgeCheck\Tests\FakeMailer();
		}

		public function mailer() {
			return $this->mailer;
		}
	}
}

namespace Automattic\WooCommerce\Blocks\Integrations {

	interface IntegrationInterface {
		public function get_name();
		public function initialize();
		public function get_script_handles();
		public function get_editor_script_handles();
		public function get_script_data();
	}
}

namespace Automattic\WooCommerce\Utilities {

	class OrderUtil {
		public static $hpos = false;

		public static function custom_orders_table_usage_is_enabled() {
			return self::$hpos;
		}
	}

	class FeaturesUtil {
		public static $declared = array();

		public static function declare_compatibility( $feature_id, $plugin_file, $positive_compatibility = true ) {
			self::$declared[] = array( $feature_id, $plugin_file, $positive_compatibility );
			return true;
		}
	}
}

namespace Navexa\AgeCheck\Tests {

	/**
	 * $wpdb stand-in. Understands exactly the statements Lock sends, against
	 * the test's in-memory options (anything else on the options table
	 * throws), and RateLimit's counter table: INSERT ... ON DUPLICATE KEY
	 * UPDATE hits = hits + 1, SELECT hits, the expiry DELETE and SHOW TABLES
	 * LIKE. $fail makes every counter-table query fail; $table_exists false
	 * makes SHOW TABLES find nothing.
	 */
	class FakeWpdb {
		public $prefix       = 'wp_';
		public $options      = 'wp_options';
		/** @var array<string, mixed> */
		public $store;
		/** @var array<string, array{hits: int, expires_at: int}> Counter-table rows. */
		public $rows         = array();
		public $fail         = false;
		public $table_exists = true;
		public $queries      = array();

		public function __construct( array &$store ) {
			$this->store = &$store;
		}

		public function esc_like( $text ) {
			return addcslashes( $text, '_%\\' );
		}

		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$i = 0;
			return preg_replace_callback(
				'/%[sdi]/',
				static function ( $m ) use ( &$i, $args ) {
					$value = $args[ $i++ ];
					if ( '%i' === $m[0] ) {
						return '`' . str_replace( '`', '``', (string) $value ) . '`';
					}
					return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
				},
				$query
			);
		}

		public function query( $sql ) {
			$this->queries[] = $sql;
			if ( false !== strpos( $sql, $this->options ) ) {
				return $this->options_query( $sql );
			}
			if ( $this->fail ) {
				return false;
			}
			if ( preg_match( "/^INSERT INTO \\S+ \\(bucket_key, hits, expires_at\\) VALUES \\('([0-9a-f]{64})', 1, (\\d+)\\)/", $sql, $m ) ) {
				if ( isset( $this->rows[ $m[1] ] ) ) {
					++$this->rows[ $m[1] ]['hits'];
					return 2;
				}
				$this->rows[ $m[1] ] = array(
					'hits'       => 1,
					'expires_at' => (int) $m[2],
				);
				return 1;
			}
			if ( preg_match( '/^DELETE FROM \\S+ WHERE expires_at < (\\d+)/', $sql, $m ) ) {
				$before     = count( $this->rows );
				$this->rows = array_filter(
					$this->rows,
					static function ( $row ) use ( $m ) {
						return $row['expires_at'] >= (int) $m[1];
					}
				);
				return $before - count( $this->rows );
			}
			return 0;
		}

		public function get_var( $sql ) {
			$this->queries[] = $sql;
			if ( preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '(.*?)'$/", $sql, $m ) ) {
				return array_key_exists( $m[1], $this->store ) ? (string) $this->store[ $m[1] ] : null;
			}
			if ( false !== strpos( $sql, $this->options ) ) {
				throw new \RuntimeException( 'FakeWpdb: unexpected query ' . $sql );
			}
			if ( $this->fail ) {
				return null;
			}
			if ( 0 === strpos( $sql, 'SHOW TABLES LIKE ' ) ) {
				return $this->table_exists ? $this->prefix . 'navexa_agecheck_rate_limits' : null;
			}
			if ( preg_match( "/WHERE bucket_key = '([0-9a-f]{64})'/", $sql, $m ) ) {
				return isset( $this->rows[ $m[1] ] ) ? (string) $this->rows[ $m[1] ]['hits'] : null;
			}
			return null;
		}

		public function get_charset_collate() {
			return 'DEFAULT CHARSET=utf8mb4';
		}

		/** Lock's statements against the options table. */
		private function options_query( $sql ) {
			if ( preg_match( "/^INSERT IGNORE INTO wp_options \\(option_name, option_value, autoload\\) VALUES \\('(.*?)', '(.*?)', 'off'\\)$/", $sql, $m ) ) {
				if ( array_key_exists( $m[1], $this->store ) ) {
					return 0;
				}
				$this->store[ $m[1] ] = stripslashes( $m[2] );
				return 1;
			}
			if ( preg_match( "/^UPDATE wp_options SET option_value = '(.*?)' WHERE option_name = '(.*?)' AND option_value = '(.*?)'$/", $sql, $m ) ) {
				if ( isset( $this->store[ $m[2] ] ) && (string) $this->store[ $m[2] ] === stripslashes( $m[3] ) ) {
					$this->store[ $m[2] ] = stripslashes( $m[1] );
					return 1;
				}
				return 0;
			}
			if ( preg_match( "/^DELETE FROM wp_options WHERE option_name = '(.*?)'(?: AND option_value = '(.*?)')?$/", $sql, $m ) ) {
				if ( ! array_key_exists( $m[1], $this->store ) || ( isset( $m[2] ) && (string) $this->store[ $m[1] ] !== stripslashes( $m[2] ) ) ) {
					return 0;
				}
				unset( $this->store[ $m[1] ] );
				return 1;
			}
			if ( preg_match( "/^DELETE FROM wp_options WHERE option_name LIKE '(.*?)%' AND CAST\\(option_value AS UNSIGNED\\) < (\\d+) LIMIT 500$/", $sql, $m ) ) {
				$prefix  = stripcslashes( stripslashes( $m[1] ) );
				$deleted = 0;
				foreach ( array_keys( $this->store ) as $name ) {
					if ( 0 === strpos( $name, $prefix ) && (int) $this->store[ $name ] < (int) $m[2] ) {
						unset( $this->store[ $name ] );
						++$deleted;
					}
				}
				return $deleted;
			}
			throw new \RuntimeException( 'FakeWpdb: unexpected query ' . $sql );
		}
	}

	/** Records the WooCommerce emails triggered. */
	class FakeEmail {
		public $triggered = array();

		public function trigger( $order_id, $order = null ) {
			$this->triggered[] = $order_id;
		}
	}

	class FakeMailer {
		public $emails;

		public function __construct() {
			$this->emails = array(
				'WC_Email_New_Order'                 => new FakeEmail(),
				'WC_Email_Customer_Processing_Order' => new FakeEmail(),
			);
		}

		public function get_emails() {
			return $this->emails;
		}
	}

	class FakeGateway {
		public $refunds = true;

		public function supports( $feature ) {
			return 'refunds' === $feature && $this->refunds;
		}

		public function can_refund_order( $order ) {
			return true;
		}
	}

	/** Records wc_get_logger() lines. */
	class FakeLogger {
		public $lines = array();

		public function log( $level, $message, $context = array() ) {
			$this->lines[] = array(
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			);
		}
	}
}
