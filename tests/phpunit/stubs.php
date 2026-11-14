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
