<?php
/**
 * The release sweep.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Every 15 minutes (Action Scheduler, unique recurring action), queues a
 * release run for each held order that is not denied, BATCH at a time with a
 * rotating page, and for each order whose checkout verification still waits
 * to be bound. The release run itself skips orders not due yet
 * (NEXT_CHECK_AT), so orders waiting on staff are asked about every six hours.
 * It also queues again link withdrawals left pending
 * (LinkRevoke::requeue_stale()) and forgets handled-webhook markers older
 * than a week.
 *
 * Only wc_get_orders() with a status and meta query (OrderQuery), which works
 * on HPOS and on the posts table. When Action Scheduler is unavailable, a WP-Cron event
 * runs the same sweep. Both depend on WP-Cron or a real system cron; the
 * readme recommends a system cron.
 */
final class Sweep {

	const HOOK        = 'navexa_agecheck_sweep';
	const CRON_HOOK   = 'navexa_agecheck_sweep_cron';
	const INTERVAL    = 900;
	const BATCH       = 100;
	const OPTION_PAGE = 'navexa_agecheck_sweep_page';
	const OPTION_LAST = 'navexa_agecheck_sweep_last';

	/**
	 * Registers the schedule and the callbacks.
	 */
	public static function register(): void {
		add_action( 'action_scheduler_init', array( self::class, 'schedule' ) );
		add_action( 'init', array( self::class, 'schedule_fallback' ) );
		add_action( self::HOOK, array( self::class, 'run_job' ) );
		add_action( self::CRON_HOOK, array( self::class, 'run_job' ) );
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 15 minutes, the sweep's interval.
	}

	/**
	 * Schedules the recurring Action Scheduler action once.
	 */
	public static function schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::HOOK, array(), Release::GROUP ) ) {
			as_schedule_recurring_action( time() + 60, self::INTERVAL, self::HOOK, array(), Release::GROUP, true );
		}
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
	}

	/**
	 * WP-Cron fallback when Action Scheduler is not loaded.
	 */
	public static function schedule_fallback(): void {
		if ( class_exists( 'ActionScheduler', false ) || false !== wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + 60, 'navexa_agecheck_15min', self::CRON_HOOK );
	}

	/**
	 * Adds the 15-minute WP-Cron schedule.
	 *
	 * @param mixed $schedules Schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function cron_schedules( $schedules ): array {
		$schedules                          = is_array( $schedules ) ? $schedules : array();
		$schedules['navexa_agecheck_15min'] = array(
			'interval' => self::INTERVAL,
			'display'  => __( 'Every 15 minutes (AgeCheck)', 'navexa-agecheck-for-woocommerce' ),
		);
		return $schedules;
	}

	/**
	 * Unschedules both (deactivation).
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), Release::GROUP );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Action Scheduler and WP-Cron callback.
	 */
	public static function run_job(): void {
		self::run();
	}

	/**
	 * One sweep.
	 *
	 * @return int Release runs queued.
	 */
	public static function run(): int {
		update_option( self::OPTION_LAST, time(), false );
		$page = max( 1, (int) get_option( self::OPTION_PAGE, 1 ) );
		$held = OrderQuery::ids(
			array(
				'status'     => array( 'wc-' . Hold::STATUS ),
				'limit'      => self::BATCH,
				'paged'      => $page,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- HPOS meta query on a held order set.
					'relation' => 'OR',
					array(
						'key'     => Meta::STATUS,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => Meta::STATUS,
						'value'   => Meta::STATUS_DENIED,
						'compare' => '!=',
					),
				),
			)
		);
		update_option( self::OPTION_PAGE, count( $held ) < self::BATCH ? 1 : $page + 1, false );

		$pending = OrderQuery::ids(
			array(
				'status'     => array( 'wc-pending', 'wc-on-hold', 'wc-failed' ),
				'limit'      => self::BATCH,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small set: checkout verifications not bound yet.
					array(
						'key'   => Meta::ATTACH_PENDING,
						'value' => 'yes',
					),
				),
			)
		);

		$queued = 0;
		foreach ( array_unique( array_merge( $held, $pending ) ) as $order_id ) {
			Release::enqueue( $order_id, Release::TRIGGER_SWEEP );
			++$queued;
		}
		// Link withdrawals left pending (an action that died or was never queued).
		LinkRevoke::requeue_stale();
		Lock::purge_claims( Webhook::SEEN_PREFIX, time() - Webhook::SEEN_TTL );
		return $queued;
	}

	/**
	 * Whether the sweep ran in the last hour (setup checklist: cron health).
	 */
	public static function healthy(): bool {
		return time() - (int) get_option( self::OPTION_LAST, 0 ) < 3600;
	}
}
