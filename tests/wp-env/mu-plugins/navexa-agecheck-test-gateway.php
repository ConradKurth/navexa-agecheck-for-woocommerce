<?php
/**
 * Plugin Name: Navexa AgeCheck test gateway (wp-env only)
 * Description: A payment method that always declines, for the payment-retry checks (1.0.4) in tests/wp-env/prepayment.mjs and tests/e2e/prepayment-browser.mjs. Never shipped: .wp-env.json maps this directory to wp-content/mu-plugins.
 *
 * The gateway (navexa_test_decline) marks the order failed and answers
 * failure, as a card gateway does on a decline: classic checkout keeps the
 * order in order_awaiting_payment, the Store API keeps it as
 * store_api_draft_order, and both resume it on the next attempt with the same
 * cart. It is listed after cash on delivery, so it is never the default
 * payment method.
 *
 * @package Navexa\AgeCheck\Tests
 */

// phpcs:ignoreFile -- test fixture.

defined( 'ABSPATH' ) || exit;

add_filter(
	'woocommerce_payment_gateways',
	static function ( $gateways ) {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return $gateways;
		}
		if ( ! class_exists( 'Navexa_AgeCheck_Test_Decline_Gateway', false ) ) {
			/**
			 * Always declines.
			 */
			class Navexa_AgeCheck_Test_Decline_Gateway extends WC_Payment_Gateway {
				public function __construct() {
					$this->id                 = 'navexa_test_decline';
					$this->method_title       = 'Test card that declines (AgeCheck tests)';
					$this->method_description = 'wp-env only.';
					$this->title              = 'Test card (declines)';
					$this->description        = 'Every payment is declined.';
					$this->has_fields         = false;
					$this->enabled            = 'yes';
					$this->supports           = array( 'products' );
				}

				public function process_payment( $order_id ) {
					$order = wc_get_order( $order_id );
					if ( $order ) {
						$order->update_status( 'failed', 'Test gateway: the card was declined.' );
					}
					wc_add_notice( 'Your card was declined.', 'error' );
					return array( 'result' => 'failure' );
				}
			}
		}
		$gateways[] = 'Navexa_AgeCheck_Test_Decline_Gateway';
		return $gateways;
	}
);

// The checkout block lists only payment methods with a block integration.
add_action(
	'woocommerce_blocks_payment_method_type_registration',
	static function ( $registry ) {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		$registry->register(
			new class() extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
				protected $name = 'navexa_test_decline';

				public function initialize() {}

				public function is_active() {
					return true;
				}

				public function get_payment_method_script_handles() {
					wp_register_script( 'navexa-agecheck-test-decline', content_url( 'mu-plugins/navexa-agecheck-test-gateway.js' ), array( 'wc-blocks-registry', 'wp-element' ), '1', true );
					return array( 'navexa-agecheck-test-decline' );
				}

				public function get_payment_method_data() {
					return array( 'title' => 'Test card (declines)' );
				}
			}
		);
	}
);
