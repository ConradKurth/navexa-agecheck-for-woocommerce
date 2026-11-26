<?php
/**
 * Work that runs once after the plugin is updated.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

use Navexa\AgeCheck\Admin\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress runs no activation hook when a plugin is updated, so
 * Install::OPTION_VERSION, written once by Install::ensure(), used to keep
 * the version first installed (TAX-1887). On the first request after an
 * update (stored version other than NAVEXA_AGECHECK_VERSION) this records the
 * running version and queues the one-time clean-ups (Action Scheduler, or
 * inline without it). Each clean-up runs once per site, whoever queued it
 * (Lock::claim()), and touches only what it can prove stale.
 *
 * - clear_released_refunds(): 1.1.0 could release a denied order listed for
 *   a manual refund when its shopper passed later, and left the listing
 *   (Meta::REFUND manual_required) on the shipped order, so the "denied,
 *   refund required" notice and filter counted it. Since then a release
 *   drops the listing (Meta::mark_released()). Only orders AgeCheck cleared
 *   and this plugin released after the listing, as the plugin's own notes
 *   show, are taken off it.
 */
final class Upgrade {

	/**
	 * Action Scheduler hook of the clean-ups.
	 */
	const HOOK = 'navexa_agecheck_upgrade';

	/**
	 * Claim (options row) that clear_released_refunds() ran on this site.
	 */
	const CLAIM_RELEASED_REFUNDS = 'navexa_agecheck_upgrade_released_refunds';

	/**
	 * Orders read per query, and the most pages read.
	 */
	const BATCH     = 100;
	const MAX_PAGES = 50;

	/**
	 * Registers the version check (after Install::ensure()) and the job.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'maybe_upgrade' ), 20 );
		add_action( self::HOOK, array( self::class, 'run_job' ) );
	}

	/**
	 * Records the running version and queues the clean-ups when it changed.
	 * An uninitialized install has nothing to clean up.
	 */
	public static function maybe_upgrade(): void {
		if ( '' === Install::install_id() || NAVEXA_AGECHECK_VERSION === get_option( Install::OPTION_VERSION, '' ) ) {
			return;
		}
		update_option( Install::OPTION_VERSION, NAVEXA_AGECHECK_VERSION, true );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array(), Release::GROUP );
			return;
		}
		self::run();
	}

	/**
	 * Action Scheduler callback.
	 */
	public static function run_job(): void {
		self::run();
	}

	/**
	 * The clean-ups not run on this site yet.
	 *
	 * @return int Orders changed.
	 */
	public static function run(): int {
		if ( ! Lock::claim( self::CLAIM_RELEASED_REFUNDS ) ) {
			return 0;
		}
		return self::clear_released_refunds();
	}

	/**
	 * Takes off the manual refund list every order released after it was
	 * listed: AgeCheck cleared it (Meta::STATUS cleared, recorded for this
	 * order), it left the hold, and the plugin's own release note is newer
	 * than its "refund or cancel this order" note. Staff's releases without
	 * age check and orders without such a note are left as they are.
	 *
	 * @return int Orders changed.
	 */
	public static function clear_released_refunds(): int {
		$ids = array();
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$found = OrderQuery::ids(
				array(
					'limit'      => self::BATCH,
					'paged'      => $page,
					'orderby'    => 'ID',
					'order'      => 'ASC',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- once per site: the refund list.
						array(
							'key'   => Meta::REFUND,
							'value' => Meta::REFUND_MANUAL,
						),
					),
				)
			);
			$ids   = array_merge( $ids, $found );
			if ( count( $found ) < self::BATCH ) {
				break;
			}
		}
		$changed = 0;
		foreach ( array_unique( $ids ) as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order || ! self::released_after_listing( $order ) ) {
				continue;
			}
			Meta::set( $order, Meta::REFUND, '' );
			$order->save_meta_data();
			Meta::note( $order, __( 'AgeCheck: taken off "AgeCheck: denied, refund required". The order was released after its age check passed, so no refund is due.', 'navexa-agecheck-for-woocommerce' ) );
			Client::log( 'info', sprintf( 'agecheck upgrade order=%d stale refund listing cleared', $order->get_id() ) );
			++$changed;
		}
		if ( $changed > 0 ) {
			Notices::flush_counts();
		}
		return $changed;
	}

	/**
	 * Whether the listed order was released by this plugin after the listing.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function released_after_listing( \WC_Order $order ): bool {
		if ( Meta::REFUND_MANUAL !== Meta::get( $order, Meta::REFUND )
			|| Meta::STATUS_CLEARED !== Meta::get( $order, Meta::STATUS )
			|| ! Meta::is_released( $order )
			|| Hold::STATUS === $order->get_status( 'edit' ) ) {
			return false;
		}
		$listed   = 0;
		$released = 0;
		foreach ( self::system_notes( $order ) as $id => $content ) {
			if ( self::contains_any( $content, self::listing_texts() ) ) {
				$listed = max( $listed, $id );
			}
			if ( self::contains_any( $content, self::release_texts() ) ) {
				$released = max( $released, $id );
			}
		}
		return $released > $listed;
	}

	/**
	 * The order's notes WooCommerce added itself (no staff member typed
	 * them), by note id (ids grow, so a larger one is newer).
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, string>
	 */
	private static function system_notes( \WC_Order $order ): array {
		if ( ! function_exists( 'wc_get_order_notes' ) ) {
			return array();
		}
		$notes = array();
		foreach ( (array) wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			if ( ! is_object( $note ) || ! isset( $note->id, $note->content ) || ! isset( $note->added_by ) || 'system' !== $note->added_by ) {
				continue;
			}
			if ( ! empty( $note->customer_note ) ) {
				continue;
			}
			$notes[ (int) $note->id ] = (string) $note->content;
		}
		return $notes;
	}

	/**
	 * Whether $content contains one of $texts.
	 *
	 * @param string   $content Note text.
	 * @param string[] $texts   Texts.
	 */
	private static function contains_any( string $content, array $texts ): bool {
		foreach ( $texts as $text ) {
			if ( '' !== $text && false !== strpos( $content, $text ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The note Denial writes when it lists an order (English and translated).
	 *
	 * @return string[]
	 */
	private static function listing_texts(): array {
		return array_unique(
			array(
				'AgeCheck: refund or cancel this order.',
				__( 'AgeCheck: refund or cancel this order. It is listed under "AgeCheck: denied, refund required" on the Orders screen.', 'navexa-agecheck-for-woocommerce' ),
			)
		);
	}

	/**
	 * The notes Release writes when AgeCheck's clearance releases an order
	 * (English and translated; a TEST prefix is matched too).
	 *
	 * @return string[]
	 */
	private static function release_texts(): array {
		return array_unique(
			array(
				'AgeCheck: age check passed. Order released.',
				'AgeCheck: age check passed (confirmed on a repeated clearance answer). Order released.',
				'AgeCheck: releasing an order that was already cleared.',
				__( 'AgeCheck: age check passed. Order released.', 'navexa-agecheck-for-woocommerce' ),
				__( 'AgeCheck: age check passed (confirmed on a repeated clearance answer). Order released.', 'navexa-agecheck-for-woocommerce' ),
				__( 'AgeCheck: releasing an order that was already cleared.', 'navexa-agecheck-for-woocommerce' ),
			)
		);
	}
}
