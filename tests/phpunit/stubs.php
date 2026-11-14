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

	/** In-memory WooCommerce session. */
	class WC_Session {
		public $data        = array();
		public $customer_id = 't_0123456789abcdef';

		public function get_customer_id() {
			return $this->customer_id;
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
	}

	class WC_Cart {
		public $empty          = false;
		public $needs_shipping = true;
		public $hash           = 'cart-hash';

		public function is_empty() {
			return $this->empty;
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
			throw new \BadMethodCallException( $name );
		}

		public function get_billing() {
			return $this->billing;
		}
	}

	class WC_Order_Item_Shipping {
		private $method_id;

		public function __construct( $method_id ) {
			$this->method_id = $method_id;
		}

		public function get_method_id() {
			return $this->method_id;
		}
	}

	class WC_Order {
		public $id               = 0;
		public $meta             = array();
		public $items            = array( 'line' );
		public $shipping_methods = array();
		public $billing          = array();
		public $shipping         = array();
		public $status           = 'checkout-draft';
		public $cart_hash        = 'cart-hash';

		public function get_id() {
			return $this->id;
		}

		public function get_meta( $key ) {
			return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
		}

		public function get_items() {
			return $this->items;
		}

		public function get_shipping_methods() {
			return $this->shipping_methods;
		}

		public function needs_shipping_address() {
			foreach ( $this->shipping_methods as $method ) {
				if ( ! in_array( $method->get_method_id(), array( 'local_pickup', 'pickup_location' ), true ) ) {
					return true;
				}
			}
			return false;
		}

		public function get_shipping_country() {
			return isset( $this->shipping['country'] ) ? $this->shipping['country'] : '';
		}

		public function get_address( $type ) {
			return 'shipping' === $type ? $this->shipping : $this->billing;
		}

		public function has_cart_hash( $hash ) {
			return $hash === $this->cart_hash;
		}

		public function has_status( $statuses ) {
			return in_array( $this->status, (array) $statuses, true );
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
	 * Enough of wpdb for RateLimit: one counter table in memory, with
	 * INSERT ... ON DUPLICATE KEY UPDATE hits = hits + 1, SELECT hits, the
	 * expiry DELETE and SHOW TABLES LIKE. $fail makes every query fail;
	 * $table_exists false makes SHOW TABLES find nothing.
	 */
	class FakeWpdb {
		public $prefix       = 'wp_';
		public $rows         = array();
		public $fail         = false;
		public $table_exists = true;
		public $queries      = array();

		public function esc_like( $text ) {
			return addcslashes( $text, '_%\\' );
		}

		public function prepare( $query, ...$args ) {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'", $query, 1 );
			}
			return $query;
		}

		public function query( $sql ) {
			$this->queries[] = $sql;
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
