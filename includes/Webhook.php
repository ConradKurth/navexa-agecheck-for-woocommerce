<?php
/**
 * AgeCheck webhook receiver.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/navexa-agecheck/v1/webhook, subscribed in the Navexa
 * dashboard with the secret pasted into the settings tab.
 *
 * The route is public (permission_callback __return_true); the signature is
 * the authentication. X-Webhook-Signature-V2 is the lower-case hex
 * HMAC-SHA256, keyed with the secret's text, of X-Webhook-Timestamp (unix
 * seconds, set per attempt), "." and the exact body. The timestamp must be
 * within 300 seconds of this clock. The older body-only X-Webhook-Signature
 * signs no time, so it is never accepted. X-Webhook-Event-Id is unsigned and
 * never used.
 *
 * A delivery only ever queues a release run (or records a note): the run asks
 * GetClearance itself, so a webhook body is a hint, never a decision. Each
 * delivery is handled once, keyed by its signed event_id (a transition) or
 * hold_stale:{verification_id} (the hold alert, one per verification).
 */
final class Webhook {

	const NAMESPACE_V1 = 'navexa-agecheck/v1';
	const ROUTE        = '/webhook';

	/**
	 * Seconds X-Webhook-Timestamp may differ from this clock, either way.
	 */
	const TOLERANCE = 300;

	/**
	 * Largest body accepted, in bytes.
	 */
	const MAX_BODY = 65536;

	/**
	 * Option-name prefix of handled-delivery markers (Lock::claim()).
	 */
	const SEEN_PREFIX = 'navexa_agecheck_seen_';

	/**
	 * Seconds a handled-delivery marker is kept. AgeCheck retries a delivery
	 * for well under a week.
	 */
	const SEEN_TTL = 604800;

	/**
	 * Registers the route.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Registers POST /navexa-agecheck/v1/webhook.
	 */
	public static function register_route(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				// Public by design: the V2 signature, checked in handle(), authenticates.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The URL to subscribe in the Navexa dashboard.
	 */
	public static function url(): string {
		return rest_url( self::NAMESPACE_V1 . self::ROUTE );
	}

	/**
	 * Handles one delivery.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle( $request ) {
		$secret = Options::webhook_secret();
		if ( '' === $secret ) {
			return self::reply( 503, 'not_configured' );
		}
		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY ) {
			return self::reply( 413, 'too_large' );
		}
		$problem = self::signature_problem( $body, $request->get_header( 'x-webhook-timestamp' ), $request->get_header( 'x-webhook-signature-v2' ), $secret, time() );
		if ( '' !== $problem ) {
			Client::log( 'warning', 'agecheck webhook refused: ' . $problem );
			return self::reply( 401, $problem );
		}
		update_option( Options::WEBHOOK_LAST_RECEIVED, time(), false );

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) ) {
			return self::reply( 200, 'ignored' );
		}
		$key = self::dedupe_key( $event );
		if ( '' === $key ) {
			return self::reply( 200, 'ignored' );
		}
		$marker = self::SEEN_PREFIX . substr( hash( 'sha256', $key ), 0, 40 );
		if ( ! Lock::claim( $marker ) ) {
			return self::reply( 200, 'duplicate' );
		}
		try {
			$result = self::act( $event );
		} catch ( \Throwable $e ) {
			Lock::unclaim( $marker );
			Client::log( 'error', sprintf( 'agecheck webhook failed error=%s', get_class( $e ) ) );
			return self::reply( 500, 'error' );
		}
		return self::reply( 200, $result );
	}

	/**
	 * Why a delivery's signature is refused: unsigned, stale or mismatch; ''
	 * when it verifies and its timestamp is fresh.
	 *
	 * @param string $body      Raw body.
	 * @param mixed  $timestamp X-Webhook-Timestamp.
	 * @param mixed  $signature X-Webhook-Signature-V2.
	 * @param string $secret    Endpoint secret.
	 * @param int    $now       Unix time.
	 */
	public static function signature_problem( string $body, $timestamp, $signature, string $secret, int $now ): string {
		if ( '' === $secret || ! is_string( $timestamp ) || ! is_string( $signature ) || '' === trim( $signature ) ) {
			return 'unsigned';
		}
		if ( ! preg_match( '/^\d{1,12}$/', $timestamp ) ) {
			return 'unsigned';
		}
		if ( abs( $now - (int) $timestamp ) > self::TOLERANCE ) {
			return 'stale';
		}
		// Signed over the header exactly as sent, so a re-dated replay fails here.
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		return hash_equals( $expected, strtolower( trim( $signature ) ) ) ? '' : 'mismatch';
	}

	/**
	 * The dedupe key of a verified delivery: event_id for a transition,
	 * hold_stale:{verification_id} for the hold alert; '' when it has neither.
	 *
	 * @param array<string, mixed> $event Decoded body.
	 */
	public static function dedupe_key( array $event ): string {
		$safe = static function ( $value ): string {
			return is_string( $value ) ? substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $value ), 0, 96 ) : '';
		};
		if ( isset( $event['event'] ) && 'verification.hold_stale' === $event['event'] ) {
			$vid = $safe( isset( $event['verification_id'] ) ? $event['verification_id'] : '' );
			return '' === $vid ? '' : 'hold_stale:' . $vid;
		}
		$id = $safe( isset( $event['event_id'] ) ? $event['event_id'] : '' );
		return '' === $id ? '' : 'event:' . $id;
	}

	/**
	 * Does what the delivery asks of the order it names.
	 *
	 * @param array<string, mixed> $event Decoded body.
	 * @return string What was done, for the reply.
	 * @throws \RuntimeException When no release run could be queued (handle() answers 500).
	 */
	private static function act( array $event ): string {
		$vid      = isset( $event['verification_id'] ) && is_string( $event['verification_id'] ) ? $event['verification_id'] : '';
		$external = isset( $event['order_external_id'] ) && is_string( $event['order_external_id'] ) ? $event['order_external_id'] : '';
		$hold     = isset( $event['event'] ) && 'verification.hold_stale' === $event['event'];
		if ( isset( $event['event'] ) && ! $hold ) {
			return 'ignored';
		}
		$state = isset( $event['state'] ) && is_string( $event['state'] ) ? $event['state'] : '';
		if ( ! $hold && ! in_array( $state, array( 'passed', 'failed', 'needs_doc' ), true ) ) {
			return 'ignored';
		}
		$order = self::order_for( $external, $vid );
		if ( ! $hold && 'needs_doc' === $state && ( null === $order || ! DocRetry::waiting( $order ) ) ) {
			// needs_doc matters only after a failure the shopper may retry
			// (TAX-1838); every other one is the check's start.
			return 'ignored';
		}
		if ( null === $order ) {
			// A verification passed before it was bound carries no order: the
			// release run queued on entering the hold binds and clears it.
			return 'no_order';
		}
		if ( $hold ) {
			if ( Meta::get_int( $order, Meta::HOLD_STALE_AT ) > 0 || Meta::is_released( $order ) ) {
				return 'noted';
			}
			Meta::set( $order, Meta::HOLD_STALE_AT, time() );
			$order->save_meta_data();
			$hours = isset( $event['pending_hours'] ) && is_int( $event['pending_hours'] ) ? $event['pending_hours'] : 0;
			Meta::note(
				$order,
				sprintf(
					/* translators: %d: hours. */
					_n( 'AgeCheck: this order has waited %d hour for the shopper\'s age check.', 'AgeCheck: this order has waited %d hours for the shopper\'s age check.', $hours, 'navexa-agecheck-for-woocommerce' ),
					$hours
				)
			);
			return 'noted';
		}
		if ( ! Release::enqueue( $order->get_id(), Release::TRIGGER_WEBHOOK ) ) {
			// handle() drops the claim and answers 500, so AgeCheck redelivers.
			throw new \RuntimeException( 'release run not queued' );
		}
		if ( 'needs_doc' === $state ) {
			// A hint only: the release run records where the check stands.
			Meta::note( $order, __( 'AgeCheck: the shopper is trying the age check again.', 'navexa-agecheck-for-woocommerce' ) );
		}
		return 'queued';
	}

	/**
	 * The order an event names, only when it is this site's order and still
	 * bound to that verification.
	 *
	 * @param string $external order_external_id.
	 * @param string $vid      verification_id.
	 */
	private static function order_for( string $external, string $vid ): ?\WC_Order {
		$parsed = Binding::parse_external_id( $external );
		if ( null === $parsed || get_current_blog_id() !== $parsed['blog_id'] || ! Binding::is_verification_id( $vid ) ) {
			return null;
		}
		$order = wc_get_order( $parsed['order_id'] );
		if ( ! $order instanceof \WC_Order || Meta::get( $order, Meta::EXTERNAL_ID ) !== $external || Meta::get( $order, Meta::VERIFICATION_ID ) !== $vid ) {
			return null;
		}
		return $order;
	}

	/**
	 * A JSON reply.
	 *
	 * @param int    $status HTTP status.
	 * @param string $result Short result code.
	 * @return \WP_REST_Response
	 */
	private static function reply( int $status, string $result ) {
		return new \WP_REST_Response( array( 'result' => $result ), $status );
	}
}
