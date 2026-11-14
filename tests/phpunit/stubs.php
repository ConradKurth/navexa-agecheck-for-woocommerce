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
		private $code;
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
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
		public $needs_shipping   = true;
		public $needs_processing = true;
		/** @var array|false The pending transition, named as in WC_Order (Hold resets it by reflection). */
		public $status_transition = false;
		/** @var array<int, array> Transitions whose status actions ran on save. */
		public $fired            = array();
		public $remaining_refund = 25.0;

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
			return $this->items['shipping'];
		}

		public function needs_shipping_address() {
			return $this->needs_shipping;
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
			return 'https://shop.example/checkout/order-received/' . $this->id . '/?key=wc_order_test';
		}

		public function get_remaining_refund_amount() {
			return $this->remaining_refund;
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
	 * $wpdb stand-in for Lock: understands exactly the statements Lock sends,
	 * against the test's in-memory options.
	 */
	class FakeWpdb {
		public $options = 'wp_options';
		/** @var array<string, mixed> */
		public $store;
		public $queries = array();

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
				'/%[sd]/',
				static function ( $m ) use ( &$i, $args ) {
					$value = $args[ $i++ ];
					return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
				},
				$query
			);
		}

		public function query( $sql ) {
			$this->queries[] = $sql;
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

		public function get_var( $sql ) {
			$this->queries[] = $sql;
			if ( preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '(.*?)'$/", $sql, $m ) ) {
				return array_key_exists( $m[1], $this->store ) ? (string) $this->store[ $m[1] ] : null;
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

	class FakeSession {
		public $data = array();

		public function get( $key, $default = null ) {
			return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
		}

		public function set( $key, $value ) {
			$this->data[ $key ] = $value;
		}

		public function __unset( $key ) {
			unset( $this->data[ $key ] );
		}
	}

	class FakeWC {
		public $session;
		public $mailer;
		public $countries;

		public function __construct() {
			$this->session = new FakeSession();
			$this->mailer  = new FakeMailer();
		}

		public function mailer() {
			return $this->mailer;
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
