<?php
/**
 * Hides express payment buttons on age-restricted products and carts.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Express payment buttons (Apple Pay, Google Pay, Amazon Pay, Link, WooPay,
 * PayPal) let a shopper pay from a product or cart page without the checkout
 * form, so without the Verify age button. With Options::HIDE_EXPRESS on (the
 * default) this class hides them through each gateway's own documented
 * filters wherever the product or cart needs an age check. Hiding is a
 * convenience, not the enforcement: an express order that still gets through
 * (a gateway or version not listed here, a cart that became restricted after
 * the page loaded) is refused before payment by the Store API or classic
 * checkout gate in "Before payment" mode, and in every mode a paid
 * restricted order is held (Hold) until AgeCheck clears it.
 *
 * Spike (W4, sources read: WooPayments 11.3.0, WooCommerce Stripe Gateway
 * 11.2.0, WooCommerce PayPal Payments 4.2.2; the matrix is in README.md):
 *
 * - WooPayments Express Checkout (Apple Pay, Google Pay, Amazon Pay): orders
 *   go through POST /wc/store/v1/checkout (wcpay express-checkout.js
 *   placeOrder; product pages in a separate "tokenized cart" session that
 *   holds no checkout guard) and /checkout/{id} on pay-for-order. Hidden by
 *   wcpay_payment_request_is_product_supported (product page) and
 *   wcpay_payment_request_is_cart_supported (cart and checkout, classic and
 *   block: on block pages a false answer keeps the express scripts unloaded,
 *   and the block payment methods' canMakePayment answers false). Link is a
 *   card-form payment method there, not an express button.
 * - WooPay (WooPayments): its own wcpay_woopay_button_is_product_supported
 *   and wcpay_platform_checkout_button_are_cart_items_supported.
 * - Stripe Express Checkout Element (Apple Pay, Google Pay, Link, Amazon
 *   Pay): orders go through POST /wc/store/v1/checkout in the shopper's own
 *   session (stripe express-checkout.js expressCheckoutECECreateOrder).
 *   Hidden by wc_stripe_hide_payment_request_on_product_page,
 *   wc_stripe_show_payment_request_on_cart and
 *   wc_stripe_show_payment_request_on_checkout, which Stripe also applies to
 *   block pages (WC_Stripe_Blocks_Support shouldShowExpressCheckoutButton).
 * - PayPal Payments: product, cart and mini-cart buttons either return to
 *   checkout (gated) or, with PayPal's "Pay now" experience or App Switch,
 *   create the order with wc_create_order() and pay it, bypassing both
 *   checkout gates (WooCommerceOrderCreator, PayNowOrderHandler): the hold
 *   catches those. PayPal Payments 4.2 runs two button stacks:
 *   - SDK v6, the default (modules.php loads ppcp-sdk-v6 unless the option
 *     woocommerce-ppcp-sdk-v6-eligible is 'no', which only a merchant country
 *     v6 does not support sets). On every page it owns it swaps the v5
 *     SmartButton for DisabledSmartButton, so the v5 "disabled" filters below
 *     are never asked; its express payments always go through
 *     WooCommerceOrderCreator. Classic pages: it prints its button wrappers on
 *     the renderer hooks it shares with v5
 *     (woocommerce_paypal_payments_single_product_renderer_hook,
 *     ..._proceed_to_checkout_button_renderer_hook,
 *     ..._mini_cart_button_renderer_hook); without a wrapper no PayPal, Venmo,
 *     Pay Later, Apple Pay or Google Pay button renders. Block cart and
 *     checkout: V6PaymentMethod's data (filtered by
 *     woocommerce_paypal_payments_blocks_payment_method_data) registers the
 *     PayPal express buttons only when buttons_enabled is true, and each
 *     wallet only when its styles name the page.
 *   - SDK v5 (stores v6 left alone): woocommerce_paypal_payments_product_buttons_disabled,
 *     woocommerce_paypal_payments_buttons_disabled (cart, mini-cart,
 *     cart-block, checkout-block) and smartButtonsEnabled in the same blocks
 *     data filter.
 *   This class answers all of them. The renderer hooks are redirected to a
 *   hook of this class that forwards to PayPal's callbacks only when the
 *   product or cart is not restricted at render time (the mini-cart of an
 *   AJAX add-to-cart renders after the product was added, but PayPal reads
 *   the hook name before). PayPal on the classic checkout page is the payment
 *   method itself and posts the checkout form (WC_Checkout validation, so the
 *   classic gate) in both stacks: left alone. Not seen in a browser: needs a
 *   connected PayPal account. Whether v5's own Apple Pay and Google Pay
 *   buttons follow the v5 disabled flags is unverified.

 * Pay-for-order pages are never touched: owner decision Q11b (accept, hold,
 * email a link). Stripe and WooPayments treat the order-pay page as the
 * checkout and ask their cart filters about the session cart there, so
 * supported_for_cart() answers as the gateway decides on that page.
 */
final class Express {

	/**
	 * PayPal Payments button contexts whose buttons are express buttons.
	 * Not 'checkout' (the classic checkout's PayPal button is the payment
	 * method, behind the form's validation) and not 'pay-now' (order-pay).
	 */
	const PAYPAL_EXPRESS_CONTEXTS = array( 'cart', 'mini-cart', 'cart-block', 'checkout-block' );

	/**
	 * Variations of a variable product checked on its page before the
	 * product counts as restricted without looking further.
	 */
	const MAX_VARIATIONS = 200;

	/**
	 * PayPal renderer hooks this class takes over: filter => [place, the
	 * priority PayPal renders at on it (v5 and v6 alike)].
	 */
	const PAYPAL_RENDERER_HOOKS = array(
		'woocommerce_paypal_payments_single_product_renderer_hook' => array( 'product', 31 ),
		'woocommerce_paypal_payments_proceed_to_checkout_button_renderer_hook' => array( 'cart', 20 ),
		'woocommerce_paypal_payments_mini_cart_button_renderer_hook' => array( 'mini_cart', 30 ),
	);

	/**
	 * Forwarders added in this request, keyed "{hook}|{proxy}".
	 *
	 * @var array<string, bool>
	 */
	private static $forwarders = array();

	/**
	 * Registers the gateway filters. Each callback reads the setting, so the
	 * filters cost nothing for a gateway that is not installed.
	 */
	public static function register(): void {
		// WooPayments: Express Checkout Element and WooPay.
		add_filter( 'wcpay_payment_request_is_product_supported', array( self::class, 'supported_for_product' ), 20, 2 );
		add_filter( 'wcpay_payment_request_is_cart_supported', array( self::class, 'supported_for_cart' ), 20 );
		add_filter( 'wcpay_woopay_button_is_product_supported', array( self::class, 'supported_for_product' ), 20, 2 );
		add_filter( 'wcpay_platform_checkout_button_are_cart_items_supported', array( self::class, 'supported_for_cart' ), 20 );
		// WooCommerce Stripe Gateway: Express Checkout Element.
		add_filter( 'wc_stripe_hide_payment_request_on_product_page', array( self::class, 'stripe_hide_on_product' ), 20, 2 );
		add_filter( 'wc_stripe_show_payment_request_on_cart', array( self::class, 'supported_for_cart' ), 20 );
		add_filter( 'wc_stripe_show_payment_request_on_checkout', array( self::class, 'supported_for_cart' ), 20 );
		// WooCommerce PayPal Payments: smart buttons.
		add_filter( 'woocommerce_paypal_payments_product_buttons_disabled', array( self::class, 'paypal_product_disabled' ), 20, 2 );
		add_filter( 'woocommerce_paypal_payments_buttons_disabled', array( self::class, 'paypal_context_disabled' ), 20, 2 );
		add_filter( 'woocommerce_paypal_payments_blocks_payment_method_data', array( self::class, 'paypal_blocks_data' ), 20 );
		add_filter( 'woocommerce_paypal_payments_single_product_renderer_hook', array( self::class, 'paypal_product_hook' ), 20 );
		add_filter( 'woocommerce_paypal_payments_proceed_to_checkout_button_renderer_hook', array( self::class, 'paypal_cart_hook' ), 20 );
		add_filter( 'woocommerce_paypal_payments_mini_cart_button_renderer_hook', array( self::class, 'paypal_mini_cart_hook' ), 20 );
	}

	/**
	 * Whether express buttons for $product are hidden: the setting is on, the
	 * shopper holds no exempt role, and the product, or any of its
	 * variations, needs an age check. Unreadable counts as restricted.
	 *
	 * @param mixed $product A WC_Product, or anything else (restricted).
	 */
	public static function product_restricted( $product ): bool {
		if ( ! Options::hide_express() ) {
			return false;
		}
		try {
			if ( Requirement::user_exempt( get_current_user_id() ) ) {
				return false;
			}
			if ( ! $product instanceof \WC_Product ) {
				return true;
			}
			if ( Requirement::product_requires( $product ) ) {
				return true;
			}
			$children = is_callable( array( $product, 'get_children' ) ) ? (array) $product->get_children() : array();
			if ( count( $children ) > self::MAX_VARIATIONS ) {
				return true;
			}
			foreach ( $children as $child_id ) {
				$child = wc_get_product( (int) $child_id );
				if ( ! $child instanceof \WC_Product || Requirement::product_requires( $child ) ) {
					return true;
				}
			}
			return false;
		} catch ( \Throwable $e ) {
			Client::log( 'error', 'agecheck express check unreadable for a product; buttons hidden' );
			return true;
		}
	}

	/**
	 * Whether express buttons for the session cart are hidden: the setting
	 * is on and the cart needs an age check (Requirement::cart_required(),
	 * which also skips exempt shoppers). Pickup does not count: the wallet
	 * sheet can switch the shipping method. No cart: not hidden.
	 */
	public static function cart_restricted(): bool {
		if ( ! Options::hide_express() ) {
			return false;
		}
		$cart = Checkout\Session::cart();
		return null !== $cart && Requirement::cart_required( $cart );
	}

	/**
	 * "Is this product supported" filters (WooPayments, WooPay): false hides.
	 *
	 * @param mixed $supported The gateway's answer.
	 * @param mixed $product   The product.
	 * @return mixed
	 */
	public static function supported_for_product( $supported, $product = null ) {
		return self::product_restricted( $product ) ? false : $supported;
	}

	/**
	 * "Show on the cart or checkout" and "cart supported" filters
	 * (WooPayments, WooPay, Stripe): false hides.
	 *
	 * @param mixed $supported The gateway's answer.
	 * @return mixed
	 */
	public static function supported_for_cart( $supported ) {
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			return $supported; // Pay-for-order: the order is paid and held (Q11b), whatever the session cart holds.
		}
		return self::cart_restricted() ? false : $supported;
	}

	/**
	 * Stripe's "hide on product page" filter: true hides.
	 *
	 * @param mixed $hide The gateway's answer.
	 * @param mixed $post The product page's post, if any.
	 * @return mixed
	 */
	public static function stripe_hide_on_product( $hide, $post = null ) {
		if ( true === $hide ) {
			return $hide;
		}
		$product = $post instanceof \WP_Post ? wc_get_product( $post->ID ) : null;
		return self::product_restricted( $product ) ? true : $hide;
	}

	/**
	 * PayPal's product button filter: true disables.
	 *
	 * @param mixed $disabled     The gateway's answer.
	 * @param mixed $context_data {product, order_total}.
	 * @return mixed
	 */
	public static function paypal_product_disabled( $disabled, $context_data = array() ) {
		if ( true === $disabled ) {
			return $disabled;
		}
		$product = is_array( $context_data ) && isset( $context_data['product'] ) ? $context_data['product'] : null;
		return self::product_restricted( $product ) ? true : $disabled;
	}

	/**
	 * PayPal's per-context button filter: true disables the express contexts
	 * (PAYPAL_EXPRESS_CONTEXTS) for a restricted cart.
	 *
	 * @param mixed $disabled The gateway's answer.
	 * @param mixed $context  PayPal's context name.
	 * @return mixed
	 */
	public static function paypal_context_disabled( $disabled, $context = '' ) {
		if ( true === $disabled || ! in_array( $context, self::PAYPAL_EXPRESS_CONTEXTS, true ) ) {
			return $disabled;
		}
		return self::cart_restricted() ? true : $disabled;
	}

	/**
	 * PayPal's block payment method data: no express buttons on the block cart
	 * and checkout for a restricted cart. SDK v5: smartButtonsEnabled. SDK v6:
	 * buttons_enabled (PayPal, Venmo, Pay Later) and each wallet's styles
	 * (Apple Pay, Google Pay). PayPal stays a payment method there only as its
	 * "Place order" row, which goes through the Store API checkout.
	 *
	 * @param mixed $data Payment method data.
	 * @return mixed
	 */
	public static function paypal_blocks_data( $data ) {
		if ( ! is_array( $data ) || ! self::cart_restricted() ) {
			return $data;
		}
		$data['smartButtonsEnabled'] = false;
		$data['buttons_enabled']     = false;
		foreach ( array( 'apple_pay', 'google_pay' ) as $wallet ) {
			if ( isset( $data[ $wallet ] ) && is_array( $data[ $wallet ] ) ) {
				$data[ $wallet ]['enabled'] = false;
				$data[ $wallet ]['styles']  = array();
			}
		}
		return $data;
	}

	/**
	 * PayPal's single product renderer hook (SDK v5 and v6).
	 *
	 * @param mixed $hook The hook PayPal renders its product buttons on.
	 * @return mixed
	 */
	public static function paypal_product_hook( $hook ) {
		return self::paypal_proxy( 'woocommerce_paypal_payments_single_product_renderer_hook', $hook );
	}

	/**
	 * PayPal's cart page renderer hook (SDK v5 and v6).
	 *
	 * @param mixed $hook The hook PayPal renders its cart buttons on.
	 * @return mixed
	 */
	public static function paypal_cart_hook( $hook ) {
		return self::paypal_proxy( 'woocommerce_paypal_payments_proceed_to_checkout_button_renderer_hook', $hook );
	}

	/**
	 * PayPal's mini-cart renderer hook (SDK v5 and v6).
	 *
	 * @param mixed $hook The hook PayPal renders its mini-cart buttons on.
	 * @return mixed
	 */
	public static function paypal_mini_cart_hook( $hook ) {
		return self::paypal_proxy( 'woocommerce_paypal_payments_mini_cart_button_renderer_hook', $hook );
	}

	/**
	 * Hands PayPal this class's hook for $filter instead of $hook, and
	 * forwards $hook to it at PayPal's own priority, at render time, only when
	 * the product or cart is not restricted. Setting off: $hook unchanged.
	 *
	 * @param string $filter A PAYPAL_RENDERER_HOOKS key.
	 * @param mixed  $hook   The hook PayPal would render on.
	 * @return mixed
	 */
	private static function paypal_proxy( string $filter, $hook ) {
		if ( ! Options::hide_express() || ! is_string( $hook ) || '' === $hook || ! isset( self::PAYPAL_RENDERER_HOOKS[ $filter ] ) ) {
			return $hook;
		}
		list( $place, $priority ) = self::PAYPAL_RENDERER_HOOKS[ $filter ];
		$proxy                    = 'navexa_agecheck_paypal_' . $place;
		if ( $hook === $proxy ) {
			return $hook;
		}
		$key = $hook . '|' . $proxy;
		if ( ! isset( self::$forwarders[ $key ] ) ) {
			self::$forwarders[ $key ] = true;
			add_action(
				$hook,
				static function () use ( $place, $proxy ) {
					if ( ! self::paypal_hidden( $place ) ) {
						do_action( $proxy ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- navexa_agecheck_paypal_{place}.
					}
				},
				$priority
			);
		}
		return $proxy;
	}

	/**
	 * Whether PayPal's buttons for $place are hidden now (render time).
	 *
	 * @param string $place 'product', 'cart' or 'mini_cart'.
	 */
	public static function paypal_hidden( string $place ): bool {
		if ( 'product' !== $place ) {
			return self::cart_restricted();
		}
		$product = isset( $GLOBALS['product'] ) && $GLOBALS['product'] instanceof \WC_Product
			? $GLOBALS['product']
			: ( function_exists( 'wc_get_product' ) ? wc_get_product() : null );
		return self::product_restricted( $product );
	}

	/**
	 * Forgets the forwarders added in this request (tests).
	 */
	public static function reset(): void {
		self::$forwarders = array();
	}
}
