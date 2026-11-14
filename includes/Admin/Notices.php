<?php
/**
 * Admin notices for held and denied orders.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck\Admin;

use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\OrderQuery;

defined( 'ABSPATH' ) || exit;

/**
 * - One-request "flash" notices for the staff member whose action AgeCheck
 *   refused (a status change, a ship-to edit).
 * - A dismissible notice on WooCommerce screens counting denied orders that
 *   need a refund and orders held for more than 30 days. Dismissing hides it
 *   until the counts change.
 */
final class Notices {

	const COUNTS_TRANSIENT = 'navexa_agecheck_notice_counts';
	const FLASH_PREFIX     = 'navexa_agecheck_flash_';
	const DISMISS_ACTION   = 'navexa_agecheck_dismiss_notice';
	const USER_META        = 'navexa_agecheck_dismissed_counts';

	/**
	 * Largest count the notice queries for.
	 */
	const COUNT_CAP = 100;

	/**
	 * Registers the notices and the dismiss handler.
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( self::class, 'handle_dismiss' ) );
	}

	/**
	 * Queues a notice for the current staff member's next admin screen. Does
	 * nothing outside the admin or without a signed-in user.
	 *
	 * @param string $message Plain text.
	 */
	public static function flash( string $message ): void {
		$user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user <= 0 || ! is_admin() ) {
			return;
		}
		$queued   = get_transient( self::FLASH_PREFIX . $user );
		$queued   = is_array( $queued ) ? $queued : array();
		$queued[] = $message;
		set_transient( self::FLASH_PREFIX . $user, array_slice( array_unique( $queued ), -5 ), 300 );
	}

	/**
	 * Drops the cached counts (a denial or a 30-day note changed them).
	 */
	public static function flush_counts(): void {
		delete_transient( self::COUNTS_TRANSIENT );
	}

	/**
	 * Prints the flash notices and the counts notice.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$user   = (int) get_current_user_id();
		$queued = get_transient( self::FLASH_PREFIX . $user );
		if ( is_array( $queued ) && ! empty( $queued ) ) {
			delete_transient( self::FLASH_PREFIX . $user );
			foreach ( $queued as $message ) {
				printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html( (string) $message ) );
			}
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen instanceof \WP_Screen ? (string) $screen->id : '';
		if ( 'dashboard' !== $id && false === strpos( $id, 'woocommerce' ) && false === strpos( $id, 'shop_order' ) ) {
			return;
		}
		$counts = self::counts();
		if ( 0 === $counts['denied'] && 0 === $counts['stale'] ) {
			return;
		}
		$signature = $counts['denied'] . ':' . $counts['stale'];
		if ( get_user_meta( $user, self::USER_META, true ) === $signature ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Navexa AgeCheck', 'navexa-agecheck-for-woocommerce' ) . '</strong></p><ul>';
		if ( $counts['denied'] > 0 ) {
			printf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( OrdersFilter::url( OrdersFilter::DENIED ) ),
				esc_html(
					sprintf(
						/* translators: %s: number of orders (may read "100+"). */
						_n( '%s order failed its age check and needs a refund or cancellation.', '%s orders failed their age check and need a refund or cancellation.', $counts['denied'], 'navexa-agecheck-for-woocommerce' ),
						self::shown( $counts['denied'] )
					)
				)
			);
		}
		if ( $counts['stale'] > 0 ) {
			printf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( OrdersFilter::url( OrdersFilter::AWAITING ) ),
				esc_html(
					sprintf(
						/* translators: %s: number of orders (may read "100+"). */
						_n( '%s order has waited more than 30 days for its age check.', '%s orders have waited more than 30 days for their age check.', $counts['stale'], 'navexa-agecheck-for-woocommerce' ),
						self::shown( $counts['stale'] )
					)
				)
			);
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION . '&counts=' . rawurlencode( $signature ) ), self::DISMISS_ACTION );
		printf( '</ul><p><a href="%1$s">%2$s</a></p></div>', esc_url( $dismiss ), esc_html__( 'Dismiss until this changes', 'navexa-agecheck-for-woocommerce' ) );
	}

	/**
	 * Dismisses the counts notice for the current user.
	 */
	public static function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'navexa-agecheck-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::DISMISS_ACTION );
		$counts = isset( $_GET['counts'] ) ? sanitize_text_field( wp_unslash( $_GET['counts'] ) ) : '';
		if ( 1 === preg_match( '/^\d{1,4}:\d{1,4}$/', $counts ) ) {
			update_user_meta( get_current_user_id(), self::USER_META, $counts );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Denied orders needing a refund, and orders held over 30 days; cached for
	 * ten minutes.
	 *
	 * @return array{denied: int, stale: int}
	 */
	public static function counts(): array {
		$cached = get_transient( self::COUNTS_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['denied'], $cached['stale'] ) ) {
			return array(
				'denied' => (int) $cached['denied'],
				'stale'  => (int) $cached['stale'],
			);
		}
		$denied = OrderQuery::ids(
			array(
				'limit'      => self::COUNT_CAP,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cached ten minutes.
					array(
						'key'   => Meta::REFUND,
						'value' => Meta::REFUND_MANUAL,
					),
				),
			)
		);
		$stale  = OrderQuery::ids(
			array(
				'status'     => array( 'wc-' . Hold::STATUS ),
				'limit'      => self::COUNT_CAP,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cached ten minutes.
					array(
						'key'     => Meta::HELD_AT,
						'value'   => time() - 30 * DAY_IN_SECONDS,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		$counts = array(
			'denied' => count( $denied ),
			'stale'  => count( $stale ),
		);
		set_transient( self::COUNTS_TRANSIENT, $counts, 600 );
		return $counts;
	}

	/**
	 * A count as shown: "100+" at the cap.
	 *
	 * @param int $count Count.
	 */
	private static function shown( int $count ): string {
		return $count >= self::COUNT_CAP ? self::COUNT_CAP . '+' : (string) $count;
	}
}
