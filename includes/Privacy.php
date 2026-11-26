<?php
/**
 * WordPress privacy tools: policy text, personal data exporter and eraser.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * - A suggested privacy policy section (Settings > Privacy > Policy Guide)
 *   saying what the plugin sends to Navexa AgeCheck and what it keeps.
 * - An exporter and an eraser (Tools > Export / Erase Personal Data) for the
 *   plugin's order meta (Meta::*) on the orders WooCommerce links to the
 *   requester (billing email, or the account with that email), ten orders a
 *   page, as WooCommerce's own order exporter pages them.
 *
 * The eraser follows WooCommerce's own order rule: it erases only when
 * "Remove personal data from orders on request" is on
 * (woocommerce_erasure_request_removes_order_data, filtered per order by
 * woocommerce_privacy_erase_order_personal_data). It removes what links the
 * order to the shopper's AgeCheck record (the verification ids, the last
 * verification status and reason, the link state and the ship-to hash) and
 * keeps the release state (required, cleared, denied, override, and whose
 * order it is), so an erased order is never held again or released by
 * mistake. It never erases from an order that can still ship (any status
 * but completed, cancelled, refunded, failed or trash) or that the plugin
 * still acts on (held, or waiting for a manual refund): the verification id
 * is what locks the order's ship-to (AddressGuard), and a bound unpaid order
 * needs it when it is paid. AgeCheck's own records are Navexa's to erase:
 * the messages say so.
 *
 * Order: WooCommerce's own order eraser anonymizes the billing email and
 * customer id it finds orders by, so this eraser is registered before it
 * (priority 5; WooCommerce adds its erasers at 10). The same erasure also
 * runs on woocommerce_privacy_before_remove_order_personal_data, which
 * WooCommerce fires for every order it anonymizes (its eraser, the "Remove
 * personal data" bulk action and its retention cleanup), after it applied
 * its own rule to that order.
 */
final class Privacy {

	const EXPORTER = 'navexa-agecheck';
	const ERASER   = 'navexa-agecheck';
	const PER_PAGE = 10;

	/**
	 * Priority of the eraser registration: before WooCommerce's (10).
	 */
	const ERASER_PRIORITY = 5;

	/**
	 * Order statuses after which an order no longer ships.
	 */
	const CLOSED_STATUSES = array( 'completed', 'cancelled', 'refunded', 'failed', 'trash' );

	/**
	 * Meta the eraser removes.
	 */
	const ERASABLE_META = array(
		Meta::VERIFICATION_ID,
		Meta::PREVIOUS_VERIFICATION_IDS,
		Meta::VERIFICATION_STATUS,
		Meta::REASON,
		Meta::LINK,
		Meta::LINK_ID,
		Meta::LINK_EXPIRES_AT,
		Meta::LINK_SENT_AT,
		Meta::LINK_KEY,
		Meta::REVOKE,
		Meta::REVOKE_KEY,
		Meta::SHIP_TO_HASH,
	);

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'add_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ), self::ERASER_PRIORITY );
		add_action( 'woocommerce_privacy_before_remove_order_personal_data', array( self::class, 'before_woocommerce_erases' ), 10, 1 );
	}

	/**
	 * Adds the suggested privacy policy text.
	 */
	public static function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( __( 'Navexa AgeCheck for WooCommerce', 'navexa-agecheck-for-woocommerce' ), wp_kses_post( self::policy_text() ) );
	}

	/**
	 * The suggested policy section (HTML).
	 */
	public static function policy_text(): string {
		if ( ! Options::verification_enabled() ) {
			return self::gate_only_policy_text();
		}
		$paragraphs = array(
			'<p class="privacy-policy-tutorial">' . esc_html__( 'Navexa AgeCheck for WooCommerce checks the age of shoppers who order age-restricted products. The text below says what the plugin sends to Navexa AgeCheck and what it keeps in your store. Adjust it to your store\'s settings (for example, whether Cloudflare Turnstile is on).', 'navexa-agecheck-for-woocommerce' ) . '</p>',
			'<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'navexa-agecheck-for-woocommerce' ) . ' </strong>'
				. esc_html__( 'We use Navexa AgeCheck to verify the age of customers who order age-restricted products. When you start an age check at checkout, we send Navexa AgeCheck the shipping address of the order you are about to place and, when you have entered them and the order ships to your US billing address, your billing name and billing address, for the age check. When such an order is placed, we send its shipping address and an order reference, and, when no age check was done at checkout, your billing name, billing address and email address, and Navexa AgeCheck may email you a link to verify your age for that order. If you have an account with us, we also send a keyed reference to your account (the reference is not your name, email address or account number), so that a returning customer who passed an age check here recently may not have to verify again. When returning-customer checks are on and you check out as a guest, we also send Navexa AgeCheck your billing email address when you start an age check, so that if you passed a check here recently Navexa can email you a one-time code that lets you skip a new one; Navexa keeps the address encrypted with your check, and a one-way keyed code made from it that is tied to our store. You then verify on Navexa\'s own page (verify.navexa.co), where you enter your date of birth and, when asked, agree to a data check or photograph an ID document and take a selfie; we never see or keep your date of birth or ID images. Navexa uses Didit for the ID, selfie and liveness checks and, when the shipping address is your billing address, to check your billing name, billing address and date of birth against US data sources such as financial and credit-bureau records.', 'navexa-agecheck-for-woocommerce' ) . '</p>',
			'<p>' . esc_html__( 'We keep, with your order, the AgeCheck verification reference, whether your age check passed or failed and when, and a one-way hash of the shipping address that was checked. While you check out, your shopping session holds the verification reference. To stop abuse, we count age check attempts per session and per IP address for one hour, stored only as keyed hashes. Navexa\'s privacy policy explains how Navexa handles your data: https://navexa.co/privacy', 'navexa-agecheck-for-woocommerce' ) . '</p>',
		);
		if ( Options::entry_gate_enabled() ) {
			$paragraphs[] = '<p>' . esc_html__( 'When you enter the store, we ask you to confirm that you are old enough. Your answer is remembered only in your own browser (local storage), so that we do not ask again for a while; it is not sent to us or to Navexa, and it is not an age verification.', 'navexa-agecheck-for-woocommerce' ) . '</p>';
		}
		if ( Options::turnstile_enabled() ) {
			// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- privacy policy text that names Cloudflare's domain; nothing is loaded here.
			$paragraphs[] = '<p>' . esc_html__( 'Before an age check starts, the checkout page runs a Cloudflare Turnstile security check, which loads a script from Cloudflare (challenges.cloudflare.com) and sends Cloudflare information about your browser; we send Cloudflare the check\'s result token and your IP address to confirm it. Cloudflare\'s Turnstile privacy addendum: https://www.cloudflare.com/turnstile-privacy-policy/', 'navexa-agecheck-for-woocommerce' ) . '</p>';
		}
		return implode( "\n", $paragraphs );
	}

	/**
	 * The suggested policy with age verification off (Options::MODE_GATE_ONLY):
	 * nothing goes to Navexa, so only the entry gate is described, when it is on.
	 */
	private static function gate_only_policy_text(): string {
		$paragraphs = array( '<p class="privacy-policy-tutorial">' . esc_html__( 'Age verification is off in Navexa AgeCheck for WooCommerce: it sends nothing to Navexa AgeCheck and keeps nothing with your orders. Only the entry age gate runs when you turn it on, and the text below describes it.', 'navexa-agecheck-for-woocommerce' ) . '</p>' );
		if ( Options::entry_gate_enabled() ) {
			$paragraphs[] = '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'navexa-agecheck-for-woocommerce' ) . ' </strong></p>';
			$paragraphs[] = '<p>' . esc_html__( 'When you enter the store, we ask you to confirm that you are old enough. Your answer is remembered only in your own browser (local storage), so that we do not ask again for a while; it is not sent to us or to Navexa, and it is not an age verification.', 'navexa-agecheck-for-woocommerce' ) . '</p>';
		}
		return implode( "\n", $paragraphs );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return mixed
	 */
	public static function register_exporter( $exporters ) {
		if ( ! is_array( $exporters ) ) {
			return $exporters;
		}
		$exporters[ self::EXPORTER ] = array(
			'exporter_friendly_name' => __( 'Navexa AgeCheck age checks', 'navexa-agecheck-for-woocommerce' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return mixed
	 */
	public static function register_eraser( $erasers ) {
		if ( ! is_array( $erasers ) ) {
			return $erasers;
		}
		$erasers[ self::ERASER ] = array(
			'eraser_friendly_name' => __( 'Navexa AgeCheck age checks', 'navexa-agecheck-for-woocommerce' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exports the plugin's meta of one page of the requester's orders.
	 *
	 * @param mixed $email Requester email.
	 * @param mixed $page  Page, from 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( $email, $page = 1 ): array {
		$orders = self::orders( $email, $page );
		$data   = array();
		foreach ( $orders as $order ) {
			$items = self::export_items( $order );
			if ( empty( $items ) ) {
				continue;
			}
			$data[] = array(
				'group_id'          => 'navexa-agecheck-orders',
				'group_label'       => __( 'Age checks', 'navexa-agecheck-for-woocommerce' ),
				'group_description' => __( 'Navexa AgeCheck age checks linked to your orders.', 'navexa-agecheck-for-woocommerce' ),
				'item_id'           => 'navexa-agecheck-order-' . $order->get_id(),
				'data'              => $items,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $orders ) < self::PER_PAGE,
		);
	}

	/**
	 * Erases the plugin's identifying meta from one page of the requester's
	 * orders, where WooCommerce's rule and the order's state allow it.
	 *
	 * @param mixed $email Requester email.
	 * @param mixed $page  Page, from 1.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( $email, $page = 1 ): array {
		$orders   = self::orders( $email, $page );
		$removed  = false;
		$retained = false;
		$messages = array();
		foreach ( $orders as $order ) {
			if ( ! self::has_meta( $order ) ) {
				continue;
			}
			$reason = self::retain_reason( $order );
			if ( '' !== $reason ) {
				$retained   = true;
				$messages[] = $reason;
				continue;
			}
			if ( self::erase_meta( $order ) ) {
				$removed    = true;
				$messages[] = sprintf(
					/* translators: %s: an order number. */
					__( 'Removed the age check details from order %s.', 'navexa-agecheck-for-woocommerce' ),
					$order->get_order_number()
				);
			}
		}
		$done = count( $orders ) < self::PER_PAGE;
		if ( $done && ( $removed || $retained ) ) {
			$messages[] = __( 'Navexa AgeCheck keeps its own record of each age check. To have it deleted, contact Navexa at privacy@navexa.co.', 'navexa-agecheck-for-woocommerce' );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => array_values( array_unique( $messages ) ),
			'done'           => $done,
		);
	}

	/**
	 * WooCommerce is about to anonymize $order (it already applied its own
	 * rule to it): erase the plugin's identifying meta first, unless the
	 * order can still ship or the plugin still acts on it.
	 *
	 * @param mixed $order Order.
	 */
	public static function before_woocommerce_erases( $order ): void {
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() || ! self::has_meta( $order ) ) {
			return;
		}
		if ( '' === self::state_retain_reason( $order ) ) {
			self::erase_meta( $order );
		}
	}

	/**
	 * Deletes the identifying meta of $order; false when it had none.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function erase_meta( \WC_Order $order ): bool {
		$changed = false;
		foreach ( self::ERASABLE_META as $key ) {
			if ( '' !== Meta::get( $order, $key ) ) {
				$order->delete_meta_data( $key );
				$changed = true;
			}
		}
		if ( $changed ) {
			$order->save_meta_data();
			Meta::note( $order, __( 'AgeCheck: the age check details were erased on a personal data erasure request. The release state is kept.', 'navexa-agecheck-for-woocommerce' ) );
		}
		return $changed;
	}

	/**
	 * Why an order's meta must stay, or '' when it may be erased.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function retain_reason( \WC_Order $order ): string {
		$allowed = 'yes' === get_option( 'woocommerce_erasure_request_removes_order_data', 'no' );
		/** This filter is documented in WooCommerce: includes/class-wc-privacy-erasers.php. */
		$allowed = (bool) apply_filters( 'woocommerce_privacy_erase_order_personal_data', $allowed, $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
		if ( ! $allowed ) {
			return sprintf(
				/* translators: %s: an order number. */
				__( 'Kept the age check details of order %s: WooCommerce is set to keep order data on erasure requests (WooCommerce > Settings > Accounts & Privacy).', 'navexa-agecheck-for-woocommerce' ),
				$order->get_order_number()
			);
		}
		return self::state_retain_reason( $order );
	}

	/**
	 * Why the order's state keeps its meta, or '' when it may be erased: the
	 * plugin still acts on it (held, or waiting for a manual refund), or it can
	 * still ship (its verification locks the ship-to; AddressGuard).
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function state_retain_reason( \WC_Order $order ): string {
		if ( Hold::STATUS === $order->get_status() || 'yes' === Meta::get( $order, Meta::HELD ) || Meta::REFUND_MANUAL === Meta::get( $order, Meta::REFUND ) ) {
			return sprintf(
				/* translators: %s: an order number. */
				__( 'Kept the age check details of order %s: the order is still waiting for its age check or a refund.', 'navexa-agecheck-for-woocommerce' ),
				$order->get_order_number()
			);
		}
		if ( 0 === strpos( Meta::get( $order, Meta::REVOKE ), LinkRevoke::STATE_PENDING . ':' ) ) {
			return sprintf(
				/* translators: %s: an order number. */
				__( 'Kept the age check details of order %s: AgeCheck is still withdrawing its emailed verification link.', 'navexa-agecheck-for-woocommerce' ),
				$order->get_order_number()
			);
		}
		if ( ! in_array( $order->get_status(), self::CLOSED_STATUSES, true ) ) {
			return sprintf(
				/* translators: %s: an order number. */
				__( 'Kept the age check details of order %s: the order can still ship, and its shipping address stays tied to its age check until it is completed, cancelled, refunded or failed.', 'navexa-agecheck-for-woocommerce' ),
				$order->get_order_number()
			);
		}
		return '';
	}

	/**
	 * The exported name/value pairs of an order's meta.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function export_items( \WC_Order $order ): array {
		$fields = array(
			Meta::VERIFICATION_ID           => __( 'AgeCheck verification', 'navexa-agecheck-for-woocommerce' ),
			Meta::PREVIOUS_VERIFICATION_IDS => __( 'Earlier AgeCheck verifications', 'navexa-agecheck-for-woocommerce' ),
			Meta::FALLBACK                  => __( 'Placed while age verification was unavailable', 'navexa-agecheck-for-woocommerce' ),
			Meta::VERIFICATION_STATUS       => __( 'Verification status', 'navexa-agecheck-for-woocommerce' ),
			Meta::STATUS                    => __( 'Age check result', 'navexa-agecheck-for-woocommerce' ),
			Meta::PASS_CLASS                => __( 'How the age check passed', 'navexa-agecheck-for-woocommerce' ),
			Meta::REASON                    => __( 'Reason', 'navexa-agecheck-for-woocommerce' ),
			Meta::SHIP_TO_HASH              => __( 'Checked shipping address (hash)', 'navexa-agecheck-for-woocommerce' ),
			Meta::BOUND_AT                  => __( 'Linked to the order', 'navexa-agecheck-for-woocommerce' ),
			Meta::HELD_AT                   => __( 'Held for the age check', 'navexa-agecheck-for-woocommerce' ),
			Meta::CLEARED_AT                => __( 'Cleared', 'navexa-agecheck-for-woocommerce' ),
			Meta::LINK_SENT_AT              => __( 'Verification link emailed', 'navexa-agecheck-for-woocommerce' ),
		);
		$times  = array( Meta::BOUND_AT, Meta::HELD_AT, Meta::CLEARED_AT, Meta::LINK_SENT_AT );
		$items  = array(
			array(
				'name'  => __( 'Order', 'navexa-agecheck-for-woocommerce' ),
				'value' => (string) $order->get_order_number(),
			),
		);
		foreach ( $fields as $key => $name ) {
			$value = Meta::get( $order, $key );
			if ( '' === $value ) {
				continue;
			}
			if ( in_array( $key, $times, true ) && ctype_digit( $value ) ) {
				$value = gmdate( 'Y-m-d H:i:s', (int) $value ) . ' UTC';
			}
			$items[] = array(
				'name'  => $name,
				'value' => $value,
			);
		}
		return count( $items ) > 1 ? $items : array();
	}

	/**
	 * Whether the order carries any meta the eraser handles.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function has_meta( \WC_Order $order ): bool {
		foreach ( self::ERASABLE_META as $key ) {
			if ( '' !== Meta::get( $order, $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One page of the requester's orders: billing email, or the customer
	 * account with that email (WooCommerce's exporter query).
	 *
	 * @param mixed $email Requester email.
	 * @param mixed $page  Page, from 1.
	 * @return \WC_Order[]
	 */
	private static function orders( $email, $page ): array {
		$email = is_string( $email ) ? trim( $email ) : '';
		if ( '' === $email || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$customer = array( $email );
		$user     = get_user_by( 'email', $email );
		if ( $user instanceof \WP_User ) {
			$customer[] = (int) $user->ID;
		}
		$orders = wc_get_orders(
			array(
				'limit'    => self::PER_PAGE,
				'page'     => max( 1, (int) $page ),
				'customer' => $customer,
				'type'     => 'shop_order',
			)
		);
		$out    = array();
		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( $order instanceof \WC_Order ) {
				$out[] = $order;
			}
		}
		return $out;
	}
}
