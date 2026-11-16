<?php
/**
 * Admin notices for held and denied orders.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck\Admin;

use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Links;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\OrderQuery;
use Navexa\AgeCheck\Options;

defined( 'ABSPATH' ) || exit;

/**
 * - One-request "flash" notices for the staff member whose action AgeCheck
 *   refused (a status change, a ship-to edit).
 * - A dismissible notice on WooCommerce screens counting denied orders that
 *   need a refund and orders held for more than 30 days. Dismissing hides it
 *   until the counts change.
 * - A notice on the same screens while AgeCheck refuses to email
 *   verification links for the whole store (Links::store_block(): AgeCheck
 *   not sending them yet, which is one switch for every store, no store
 *   display name, or no link emails at all), for a week after the last
 *   refusal; it goes once a link is sent, and the display-name one as soon
 *   as AgeCheck reports a name (Options::STORE_PROFILE). Dismissing it hides
 *   it until the reason changes.
 */
final class Notices {

	const COUNTS_TRANSIENT = 'navexa_agecheck_notice_counts';
	const FLASH_PREFIX     = 'navexa_agecheck_flash_';
	const DISMISS_ACTION   = 'navexa_agecheck_dismiss_notice';
	const USER_META        = 'navexa_agecheck_dismissed_counts';
	const USER_META_LINKS  = 'navexa_agecheck_dismissed_links';

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
		$links = self::links_text();
		$block = Links::store_block();
		if ( '' !== $links && array() !== $block && get_user_meta( $user, self::USER_META_LINKS, true ) !== $block['reason'] ) {
			$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION . '&links=' . rawurlencode( $block['reason'] ) ), self::DISMISS_ACTION );
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong></p><p>%2$s</p><p><a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'Navexa AgeCheck', 'navexa-agecheck-for-woocommerce' ),
				esc_html( $links ),
				esc_url( $dismiss ),
				esc_html__( 'Dismiss until this changes', 'navexa-agecheck-for-woocommerce' )
			);
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
	 * The store-wide link refusal in words, while it is recent; else ''.
	 *
	 * @param int|null $now Unix time; null for time().
	 */
	public static function links_text( ?int $now = null ): string {
		$block = Links::store_block();
		$now   = null === $now ? time() : $now;
		if ( array() === $block || $now - $block['at'] > Links::NOTICE_TTL ) {
			return '';
		}
		switch ( $block['reason'] ) {
			case Links::STATE_LINKS_OFF:
				return __( 'AgeCheck is not sending verification emails yet, so shoppers of held orders are not emailed a link to verify. Their orders stay held until they verify from their order page (Verify now) or you release them after checking their age yourself. Nothing needs changing on your side: the plugin uses the emails once AgeCheck turns them on.', 'navexa-agecheck-for-woocommerce' );
			case Links::STATE_NO_DISPLAY_NAME:
				if ( '' !== Options::reported_display_name() ) {
					return ''; // Set since: the next check of each held order sends its email.
				}
				return __( 'AgeCheck cannot email shoppers a link to verify until you set your store name in the AgeCheck dashboard (AgeCheck settings, store display name). Held orders stay held until then; once AgeCheck reports the name (within an hour), each held order\'s email goes out at its next check, or at once with the order action "AgeCheck: check now".', 'navexa-agecheck-for-woocommerce' );
			default:
				return __( 'AgeCheck does not offer verification emails yet, so shoppers of held orders are not emailed a link. Their orders stay held until they verify from their order page or you release them after checking their age yourself.', 'navexa-agecheck-for-woocommerce' );
		}
	}

	/**
	 * Dismisses the counts notice or the link notice for the current user.
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
		$links = isset( $_GET['links'] ) ? sanitize_key( wp_unslash( $_GET['links'] ) ) : '';
		if ( in_array( $links, Links::BLOCKING_STATES, true ) ) {
			update_user_meta( get_current_user_id(), self::USER_META_LINKS, $links );
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
