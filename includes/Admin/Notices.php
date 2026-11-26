<?php
/**
 * Admin notices for held and denied orders.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck\Admin;

use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Links;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\OrderQuery;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * - One-request "flash" notices for the staff member whose action AgeCheck
 *   refused (a status change, a ship-to edit).
 * - A dismissible notice on WooCommerce screens counting denied orders that
 *   need a refund and orders held for more than 30 days. Dismissing hides it
 *   until the counts change.
 * - A setup notice on the same screens while age verification is on and
 *   either API key is missing: the plugin then refuses every age-restricted
 *   checkout (fail closed), and a merchant who wants only the entry gate
 *   should set Age verification to Off (Options::MODE_GATE_ONLY). It is
 *   never inferred from a missing key. Always shown on the settings tab
 *   (not dismissible there), as the test-mode notice is.
 * - A notice on the same screens while AgeCheck refuses to email
 *   verification links for the whole store (Links::store_block(): AgeCheck
 *   not sending them yet, which is one switch for every store, no store
 *   display name, or no link emails at all), for a week after the last
 *   refusal; it goes once a link is sent, and the display-name one as soon
 *   as AgeCheck reports a name (Options::STORE_PROFILE). Dismissing it hides
 *   it until the reason changes.
 * - A notice on the same screens while the store has used its AgeCheck ID
 *   checks for the day (Options::capped_until(), from GetCheckAvailability,
 *   TAX-1841): shoppers whose check needs the ID step cannot order until
 *   it resets. Dismissing it hides it until the next limit.
 *
 * Every lasting notice of this plugin (these, the test-mode one in Mode and
 * the paused one in Install) shows only on WooCommerce screens
 * (on_store_screen(): orders, settings, reports), never on the WordPress
 * dashboard or other plugins' pages, and each staff member can dismiss it
 * until its reason changes (dismiss_url(), is_dismissed()). The
 * WooCommerce-missing notice shows on the Plugins screen only.
 */
final class Notices {

	const COUNTS_TRANSIENT = 'navexa_agecheck_notice_counts';
	const FLASH_PREFIX     = 'navexa_agecheck_flash_';
	const DISMISS_ACTION   = 'navexa_agecheck_dismiss_notice';
	const USER_META        = 'navexa_agecheck_dismissed_counts';
	const USER_META_LINKS  = 'navexa_agecheck_dismissed_links';
	const USER_META_TEST   = 'navexa_agecheck_dismissed_test';
	const USER_META_PAUSED = 'navexa_agecheck_dismissed_paused';
	const USER_META_CAPPED = 'navexa_agecheck_dismissed_capped';
	const USER_META_SETUP  = 'navexa_agecheck_dismissed_setup';

	/**
	 * The setup notice's one reason (dismissed until it shows for another).
	 */
	const SETUP_REASON = 'keys';

	/**
	 * Dismissable notices: the dismiss URL's parameter and the user meta that
	 * remembers the dismissed reason.
	 */
	const DISMISSIBLE = array(
		'counts' => self::USER_META,
		'links'  => self::USER_META_LINKS,
		'test'   => self::USER_META_TEST,
		'paused' => self::USER_META_PAUSED,
		'capped' => self::USER_META_CAPPED,
		'setup'  => self::USER_META_SETUP,
	);

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

		if ( ! self::on_store_screen() ) {
			return;
		}
		self::render_setup();
		$links = self::links_text();
		$block = Links::store_block();
		if ( '' !== $links && array() !== $block && ! self::is_dismissed( 'links', $block['reason'] ) ) {
			$dismiss = self::dismiss_url( 'links', $block['reason'] );
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong></p><p>%2$s</p><p><a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'Navexa AgeCheck', 'navexa-agecheck-for-woocommerce' ),
				esc_html( $links ),
				esc_url( $dismiss ),
				esc_html__( 'Dismiss until this changes', 'navexa-agecheck-for-woocommerce' )
			);
		}
		$capped = self::capped_text();
		$until  = Options::capped_until();
		if ( '' !== $capped && ! self::is_dismissed( 'capped', (string) $until ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong></p><p>%2$s</p>%3$s</div>',
				esc_html__( 'Navexa AgeCheck', 'navexa-agecheck-for-woocommerce' ),
				esc_html( $capped ),
				self::dismiss_link( 'capped', (string) $until ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in dismiss_link().
			);
		}
		$counts = self::counts();
		if ( 0 === $counts['denied'] && 0 === $counts['stale'] ) {
			return;
		}
		$signature = $counts['denied'] . ':' . $counts['stale'];
		if ( self::is_dismissed( 'counts', $signature ) ) {
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
		$dismiss = self::dismiss_url( 'counts', $signature );
		printf( '</ul><p><a href="%1$s">%2$s</a></p></div>', esc_url( $dismiss ), esc_html__( 'Dismiss until this changes', 'navexa-agecheck-for-woocommerce' ) );
	}

	/**
	 * The setup notice: age verification on and a key missing, so every
	 * age-restricted checkout is refused until the keys are added.
	 */
	private static function render_setup(): void {
		if ( ! self::needs_setup() ) {
			return;
		}
		$settings = self::on_settings_tab();
		if ( ! $settings && self::is_dismissed( 'setup', self::SETUP_REASON ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Navexa AgeCheck', 'navexa-agecheck-for-woocommerce' ) . '</strong> ';
		esc_html_e( 'Navexa AgeCheck refuses age-restricted checkouts until you add your API keys. To use only the entry age gate, set Age verification to Off.', 'navexa-agecheck-for-woocommerce' );
		echo '</p>';
		if ( ! $settings ) {
			printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( Plugin::settings_url() ), esc_html__( 'AgeCheck settings', 'navexa-agecheck-for-woocommerce' ) );
			echo self::dismiss_link( 'setup', self::SETUP_REASON ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- dismiss_link() escapes.
		}
		echo '</div>';
	}

	/**
	 * Whether age verification is on with either API key missing.
	 */
	public static function needs_setup(): bool {
		return Options::verification_enabled() && ( '' === Options::api_key( Client::KEY_START ) || '' === Options::api_key( Client::KEY_FULFILMENT ) );
	}

	/**
	 * Whether the current admin screen is a WooCommerce one (its settings,
	 * including this plugin's tab, its orders, reports and other pages):
	 * where this plugin's lasting notices show.
	 */
	public static function on_store_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen instanceof \WP_Screen ? (string) $screen->id : '';
		return false !== strpos( $id, 'woocommerce' ) || false !== strpos( $id, 'shop_order' );
	}

	/**
	 * Whether the current screen is this plugin's settings tab.
	 */
	public static function on_settings_tab(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return $screen instanceof \WP_Screen && 'woocommerce_page_wc-settings' === $screen->id && Plugin::SETTINGS_TAB === $tab;
	}

	/**
	 * Whether the current user dismissed the $kind notice for $reason.
	 *
	 * @param string $kind   A DISMISSIBLE key.
	 * @param string $reason What the notice is about now (a signature).
	 */
	public static function is_dismissed( string $kind, string $reason ): bool {
		return isset( self::DISMISSIBLE[ $kind ] ) && get_user_meta( (int) get_current_user_id(), self::DISMISSIBLE[ $kind ], true ) === $reason;
	}

	/**
	 * The link that dismisses the $kind notice for $reason, until it changes.
	 *
	 * @param string $kind   A DISMISSIBLE key.
	 * @param string $reason What the notice is about now (a signature).
	 */
	public static function dismiss_url( string $kind, string $reason ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION . '&' . $kind . '=' . rawurlencode( $reason ) ), self::DISMISS_ACTION );
	}

	/**
	 * The dismiss link as printed at the end of a notice.
	 *
	 * @param string $kind   A DISMISSIBLE key.
	 * @param string $reason What the notice is about now (a signature).
	 */
	public static function dismiss_link( string $kind, string $reason ): string {
		return '<p><a href="' . esc_url( self::dismiss_url( $kind, $reason ) ) . '">' . esc_html__( 'Dismiss until this changes', 'navexa-agecheck-for-woocommerce' ) . '</a></p>';
	}

	/**
	 * A 32-hex signature of $value, for a dismissed reason that must not be
	 * stored in the clear (a key fingerprint, a site address).
	 *
	 * @param string $value Value.
	 */
	public static function signature( string $value ): string {
		return substr( hash( 'sha256', 'navexa-agecheck-notice-v1|' . $value ), 0, 32 );
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
				return __( 'AgeCheck is not sending verification emails yet, so shoppers of held orders are not emailed a link to verify. Their orders stay held until they verify from their order page (Verify now) or you release them after checking their age yourself. Nothing needs changing on your side: the plugin uses the emails once AgeCheck turns them on.', 'navexa-agecheck-for-woocommerce' ) . self::fallback_suffix();
			case Links::STATE_NO_DISPLAY_NAME:
				if ( '' !== Options::reported_display_name() ) {
					return ''; // Set since: the next check of each held order sends its email.
				}
				return __( 'AgeCheck cannot email shoppers a link to verify until you set your store name in the AgeCheck dashboard (AgeCheck settings, store display name). Held orders stay held until then; once AgeCheck reports the name (within an hour), each held order\'s email goes out at its next check, or at once with the order action "AgeCheck: check now".', 'navexa-agecheck-for-woocommerce' ) . self::fallback_suffix();
			default:
				return __( 'AgeCheck does not offer verification emails yet, so shoppers of held orders are not emailed a link. Their orders stay held until they verify from their order page or you release them after checking their age yourself.', 'navexa-agecheck-for-woocommerce' ) . self::fallback_suffix();
		}
	}

	/**
	 * The store's daily ID check limit in words while it stands
	 * (Options::capped_until()); else ''.
	 *
	 * @param int|null $now Unix time; null for time().
	 */
	public static function capped_text( ?int $now = null ): string {
		$until = Options::capped_until( $now );
		if ( 0 === $until ) {
			return '';
		}
		return sprintf(
			/* translators: %s: a date and time. */
			__( 'Your store has used its AgeCheck ID checks for today, so shoppers whose age check needs a photo ID cannot place age-restricted orders until %s (00:00 UTC). AgeCheck raises the limit once your first AgeCheck invoice is paid; contact Navexa if you need more.', 'navexa-agecheck-for-woocommerce' ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $until )
		);
	}

	/**
	 * What links_text() adds while a store that takes and holds orders when
	 * AgeCheck is down cannot: no link could reach those shoppers
	 * (Options::outage_fallback()).
	 */
	private static function fallback_suffix(): string {
		if ( Options::OUTAGE_HOLD !== Options::stored_outage_fallback() ) {
			return '';
		}
		return ' ' . __( 'While this lasts, checkout also refuses orders when AgeCheck is down instead of taking and holding them (your "When AgeCheck is down" setting), because those shoppers could not be sent a link.', 'navexa-agecheck-for-woocommerce' );
	}

	/**
	 * Dismisses a notice (DISMISSIBLE) for the current user.
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
		$capped = isset( $_GET['capped'] ) ? sanitize_key( wp_unslash( $_GET['capped'] ) ) : '';
		if ( 1 === preg_match( '/^\d{1,12}$/', $capped ) ) {
			update_user_meta( get_current_user_id(), self::USER_META_CAPPED, $capped );
		}
		$setup = isset( $_GET['setup'] ) ? sanitize_key( wp_unslash( $_GET['setup'] ) ) : '';
		if ( self::SETUP_REASON === $setup ) {
			update_user_meta( get_current_user_id(), self::USER_META_SETUP, $setup );
		}
		foreach ( array( 'test', 'paused' ) as $kind ) {
			$reason = isset( $_GET[ $kind ] ) ? sanitize_key( wp_unslash( $_GET[ $kind ] ) ) : '';
			if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $reason ) ) {
				update_user_meta( get_current_user_id(), self::DISMISSIBLE[ $kind ], $reason );
			}
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
