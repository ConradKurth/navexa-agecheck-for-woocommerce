<?php
/**
 * The AgeCheck panel and actions on the order screen.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck\Admin;

use Navexa\AgeCheck\ConnectError;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\LinkSender;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\Requirement;

defined( 'ABSPATH' ) || exit;

/**
 * A side panel on the order edit screen (HPOS and posts-table) showing the
 * order's AgeCheck state, and two WooCommerce order actions:
 *
 * - "AgeCheck: check now" runs a release run at once.
 * - "AgeCheck: release without age check" (held orders only) is the logged
 *   override (owner decision Q11a): it needs manage_woocommerce, a reason
 *   typed into the panel and a confirmation tick, and records who, when and
 *   why on the order. Order actions are saved with the order form, so
 *   WooCommerce has already checked its nonce and edit capability; the
 *   handlers check them again.
 */
final class OrderPanel {

	const ACTION_CHECK    = 'navexa_agecheck_check';
	const ACTION_OVERRIDE = 'navexa_agecheck_override';
	const FIELD_REASON    = 'navexa_agecheck_override_reason';
	const FIELD_CONFIRM   = 'navexa_agecheck_override_confirm';

	/**
	 * Registers the panel and the actions.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add_box' ), 30, 0 );
		add_filter( 'woocommerce_order_actions', array( self::class, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_' . self::ACTION_CHECK, array( self::class, 'action_check' ), 10, 1 );
		add_action( 'woocommerce_order_action_' . self::ACTION_OVERRIDE, array( self::class, 'action_override' ), 10, 1 );
	}

	/**
	 * Adds the panel to the order screen.
	 */
	public static function add_box(): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'navexa-agecheck', __( 'AgeCheck', 'navexa-agecheck-for-woocommerce' ), array( self::class, 'render' ), $screen, 'side', 'high' );
	}

	/**
	 * Prints the panel.
	 *
	 * @param mixed $post_or_order WP_Post (posts table) or WC_Order (HPOS).
	 */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : ( is_object( $post_or_order ) && isset( $post_or_order->ID ) ? wc_get_order( (int) $post_or_order->ID ) : null );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$rows = self::rows( $order );
		if ( Mode::is_test_mode() ) {
			echo '<p><strong>' . esc_html__( 'TEST MODE: no real age check happens.', 'navexa-agecheck-for-woocommerce' ) . '</strong></p>';
		}
		echo '<dl class="navexa-agecheck-panel">';
		foreach ( $rows as $label => $value ) {
			printf( '<dt><strong>%1$s</strong></dt><dd style="margin:0 0 .6em">%2$s</dd>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</dl>';

		if ( Hold::STATUS === $order->get_status( 'edit' ) && current_user_can( 'manage_woocommerce' ) ) {
			echo '<p>' . esc_html__( 'To ship this order without an age check, type why, tick the box, choose "AgeCheck: release without age check" in Order actions and update the order. Your name and reason are recorded on the order.', 'navexa-agecheck-for-woocommerce' ) . '</p>';
			printf(
				'<p><label for="%1$s">%2$s</label><textarea id="%1$s" name="%1$s" rows="2" style="width:100%%" maxlength="500"></textarea></p>',
				esc_attr( self::FIELD_REASON ),
				esc_html__( 'Reason (for example: ID checked in person)', 'navexa-agecheck-for-woocommerce' )
			);
			printf(
				'<p><label><input type="checkbox" name="%1$s" value="1" /> %2$s</label></p>',
				esc_attr( self::FIELD_CONFIRM ),
				esc_html__( 'I checked the buyer is of legal age myself.', 'navexa-agecheck-for-woocommerce' )
			);
		}
	}

	/**
	 * The panel's rows: label => plain text.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, string>
	 */
	public static function rows( \WC_Order $order ): array {
		$rows     = array();
		$required = Meta::get( $order, Meta::REQUIRED );
		if ( 'yes' !== $required ) {
			$rows[ __( 'Age check', 'navexa-agecheck-for-woocommerce' ) ] = 'no' === $required
				? self::not_required_text( Meta::get( $order, Meta::REQUIRED_REASON ) )
				: __( 'Not evaluated yet.', 'navexa-agecheck-for-woocommerce' );
			return $rows;
		}
		$rows[ __( 'Age check', 'navexa-agecheck-for-woocommerce' ) ] = self::status_text( $order );

		$reason = Meta::get( $order, Meta::REASON );
		if ( '' !== $reason && ! Meta::is_released( $order ) ) {
			$rows[ __( 'Why', 'navexa-agecheck-for-woocommerce' ) ] = Release::reason_text( $reason );
		}
		$vid = Meta::get( $order, Meta::VERIFICATION_ID );
		if ( '' !== $vid ) {
			$status = Meta::get( $order, Meta::VERIFICATION_STATUS );
			$rows[ __( 'Verification', 'navexa-agecheck-for-woocommerce' ) ] = '' === $status ? $vid : $vid . ' (' . str_replace( '_', ' ', $status ) . ')';
		}
		$link = explode( ':', Meta::get( $order, Meta::LINK ), 2 );
		if ( '' !== $link[0] ) {
			$texts = array(
				LinkSender::RESULT_SENT        => __( 'Emailed to the shopper.', 'navexa-agecheck-for-woocommerce' ),
				LinkSender::RESULT_UNAVAILABLE => __( 'Not sent: verification emails are not available yet.', 'navexa-agecheck-for-woocommerce' ),
				LinkSender::RESULT_REFUSED     => __( 'Could not be emailed (check the billing email).', 'navexa-agecheck-for-woocommerce' ),
			);
			$rows[ __( 'Verification link', 'navexa-agecheck-for-woocommerce' ) ] = isset( $texts[ $link[0] ] ) ? $texts[ $link[0] ] : $link[0];
		}
		$refund = Meta::get( $order, Meta::REFUND );
		if ( '' !== $refund ) {
			$texts = array(
				Meta::REFUND_REFUNDED => __( 'Refunded automatically.', 'navexa-agecheck-for-woocommerce' ),
				Meta::REFUND_MANUAL   => __( 'Refund or cancel this order yourself.', 'navexa-agecheck-for-woocommerce' ),
				Meta::REFUND_RESOLVED => __( 'Refunded or cancelled by staff.', 'navexa-agecheck-for-woocommerce' ),
			);
			$rows[ __( 'Refund', 'navexa-agecheck-for-woocommerce' ) ] = isset( $texts[ $refund ] ) ? $texts[ $refund ] : $refund;
		}
		$held_at = Meta::get_int( $order, Meta::HELD_AT );
		if ( $held_at > 0 ) {
			$rows[ __( 'Held since', 'navexa-agecheck-for-woocommerce' ) ] = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $held_at );
		}
		if ( Meta::STATUS_OVERRIDE === Meta::get( $order, Meta::STATUS ) ) {
			$user = get_userdata( Meta::get_int( $order, Meta::OVERRIDE_BY ) );
			$rows[ __( 'Released by', 'navexa-agecheck-for-woocommerce' ) ] = sprintf(
				/* translators: 1: staff name, 2: date. */
				__( '%1$s on %2$s', 'navexa-agecheck-for-woocommerce' ),
				$user instanceof \WP_User ? $user->display_name : '#' . Meta::get_int( $order, Meta::OVERRIDE_BY ),
				wp_date( get_option( 'date_format' ), Meta::get_int( $order, Meta::OVERRIDE_AT ) )
			);
		}
		return $rows;
	}

	/**
	 * Adds the AgeCheck order actions.
	 *
	 * @param mixed $actions Actions.
	 * @param mixed $order   Order (WooCommerce 6.x+ passes it).
	 * @return mixed
	 */
	public static function order_actions( $actions, $order = null ) {
		if ( ! is_array( $actions ) || ! $order instanceof \WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}
		if ( Hold::STATUS === $order->get_status( 'edit' ) ) {
			$actions[ self::ACTION_CHECK ]    = __( 'AgeCheck: check now', 'navexa-agecheck-for-woocommerce' );
			$actions[ self::ACTION_OVERRIDE ] = __( 'AgeCheck: release without age check', 'navexa-agecheck-for-woocommerce' );
		}
		return $actions;
	}

	/**
	 * "Check now".
	 *
	 * @param mixed $order Order.
	 */
	public static function action_check( $order ): void {
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'manage_woocommerce' ) || ! self::nonce_ok() ) {
			return;
		}
		try {
			$outcome = Release::run( (int) $order->get_id(), Release::TRIGGER_ADMIN );
		} catch ( ConnectError $e ) {
			$outcome = 'error';
		}
		$texts = array(
			Release::RELEASED  => __( 'AgeCheck cleared the order and released it.', 'navexa-agecheck-for-woocommerce' ),
			Release::CLEARED   => __( 'AgeCheck cleared the order.', 'navexa-agecheck-for-woocommerce' ),
			Release::PENDING   => __( 'AgeCheck: the shopper has not passed yet. The order stays held.', 'navexa-agecheck-for-woocommerce' ),
			Release::DENIED    => __( 'AgeCheck: the shopper did not pass. The order stays held.', 'navexa-agecheck-for-woocommerce' ),
			Release::TEST_MODE => __( 'AgeCheck: test keys never release orders automatically.', 'navexa-agecheck-for-woocommerce' ),
			Release::LOCKED    => __( 'AgeCheck is already checking this order. Try again in a minute.', 'navexa-agecheck-for-woocommerce' ),
			'error'            => __( 'AgeCheck could not be reached. The order stays held; it is checked again automatically.', 'navexa-agecheck-for-woocommerce' ),
		);
		Notices::flash( isset( $texts[ $outcome ] ) ? $texts[ $outcome ] : __( 'AgeCheck: the order stays held. See the AgeCheck panel for why.', 'navexa-agecheck-for-woocommerce' ) );
	}

	/**
	 * "Release without age check".
	 *
	 * @param mixed $order Order.
	 */
	public static function action_override( $order ): void {
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'manage_woocommerce' ) || ! self::nonce_ok() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce_ok() checked woocommerce_meta_nonce.
		$reason  = isset( $_POST[ self::FIELD_REASON ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::FIELD_REASON ] ) ) : '';
		$confirm = isset( $_POST[ self::FIELD_CONFIRM ] ) && '1' === sanitize_text_field( wp_unslash( $_POST[ self::FIELD_CONFIRM ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$reason = substr( trim( $reason ), 0, 500 );
		if ( '' === $reason || ! $confirm ) {
			Notices::flash( __( 'AgeCheck: the order was not released. Type a reason and tick the confirmation box in the AgeCheck panel, then choose the action again.', 'navexa-agecheck-for-woocommerce' ) );
			return;
		}
		if ( ! Hold::override( $order, (int) get_current_user_id(), $reason ) ) {
			Notices::flash( __( 'AgeCheck: the order could not be released. Reload the order and try again.', 'navexa-agecheck-for-woocommerce' ) );
		}
	}

	/**
	 * Whether the order form's nonce is valid.
	 */
	private static function nonce_ok(): bool {
		$nonce = isset( $_POST['woocommerce_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ) : '';
		return '' !== $nonce && false !== wp_verify_nonce( $nonce, 'woocommerce_save_data' );
	}

	/**
	 * The order's AgeCheck state in words.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function status_text( \WC_Order $order ): string {
		switch ( Meta::get( $order, Meta::STATUS ) ) {
			case Meta::STATUS_CLEARED:
				return __( 'Passed. AgeCheck cleared the order.', 'navexa-agecheck-for-woocommerce' );
			case Meta::STATUS_DENIED:
				return __( 'Failed. Do not ship this order.', 'navexa-agecheck-for-woocommerce' );
			case Meta::STATUS_OVERRIDE:
				return __( 'Released by staff without an age check.', 'navexa-agecheck-for-woocommerce' );
			case Meta::STATUS_PENDING:
				return __( 'Awaiting the shopper\'s age check.', 'navexa-agecheck-for-woocommerce' );
			default:
				return __( 'Required. Checked when the order is paid.', 'navexa-agecheck-for-woocommerce' );
		}
	}

	/**
	 * Why an order needs no age check, in words.
	 *
	 * @param string $reason Requirement::REASON_*.
	 */
	private static function not_required_text( string $reason ): string {
		switch ( $reason ) {
			case Requirement::REASON_PICKUP:
				return __( 'Not needed: local pickup. Check the buyer\'s ID at the counter.', 'navexa-agecheck-for-woocommerce' );
			case Requirement::REASON_EXEMPT_ROLE:
				return __( 'Not needed: the customer has an exempt role.', 'navexa-agecheck-for-woocommerce' );
			case Requirement::REASON_BEFORE_ACTIVATION:
				return __( 'Not needed: placed before AgeCheck was set up.', 'navexa-agecheck-for-woocommerce' );
			default:
				return __( 'Not needed: no age-restricted products.', 'navexa-agecheck-for-woocommerce' );
		}
	}
}
