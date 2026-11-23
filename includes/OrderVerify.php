<?php
/**
 * "Verify now" for a held order, on the shopper's order pages.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * A held order whose age check waits on the shopper shows a "Verify now"
 * button on the order-received (thank-you) page and on My account > Orders >
 * view order (woocommerce_thankyou, which WooCommerce's block order
 * confirmation fires too, and woocommerce_view_order: WooCommerce has
 * already decided the visitor may see the order). The button goes through
 * the plugin's own endpoint, never AgeCheck directly, so the browser gets no
 * key and no verification id:
 *
 * - start (POST {home}/?wc-api=navexa_agecheck_order, step=start): the order
 *   id, its order key and a nonce tied to the visitor (WooCommerce ties a
 *   guest's nonce to their session for actions named woocommerce*); an
 *   order with an account also needs that customer signed in. Rate limited
 *   per order and per client IP. An order with no verification yet (the
 *   hold's release run has not run) gets one now: a release run in this
 *   request (Release::run(), under the order's lock). Then
 *   CreateHostedSession (start key; return URL below) and a 303 to the
 *   hosted page, whose URL Start::is_hosted_url() checks first. Any error:
 *   a "temporarily unavailable" page, the order stays held. A refused
 *   session (FAILED_PRECONDITION) is followed by GetVerification: a check
 *   that moved on is recorded and the shopper goes back to the order page;
 *   one still waiting means AgeCheck refuses the store (live access), so
 *   the "temporarily unavailable" page.
 * - return (GET, step=return, order, t, n?): where AgeCheck's page sends the
 *   shopper back, for this flow and for the emailed link (Links). t is a
 *   keyed hash of the order (return_url()), so only AgeCheck's link can ask
 *   for a release run; the order's key is never in it. A held order gets a
 *   release run queued (rate limited); the shopper then goes to their order
 *   page when this browser may see it (their account, or the nonce n from
 *   the start, which is added only while it is bound to the visitor:
 *   nonce_is_bound()), with the check's status read first so the page stops
 *   offering Verify now, else sees a short "finished" page.
 *
 * Nothing here decides the order: the release run asks GetClearance.
 */
final class OrderVerify {

	const API_ACTION = 'navexa_agecheck_order';

	const STEP_START  = 'start';
	const STEP_RETURN = 'return';

	/**
	 * Nonce action prefix (+ order id). WooCommerce ties a guest's nonce to
	 * their session only for actions that start with "woocommerce".
	 */
	const NONCE_PREFIX = 'woocommerce-navexa-agecheck-order-';

	/**
	 * Hourly limits: starts per order and per client IP, returns per order.
	 */
	const LIMITS = array(
		'start_order'  => 10,
		'start_ip'     => 30,
		'return_order' => 20,
	);

	/**
	 * Registers the order-page box and the endpoint.
	 */
	public static function register(): void {
		add_action( 'woocommerce_thankyou', array( self::class, 'render_for' ), 5, 1 );
		add_action( 'woocommerce_view_order', array( self::class, 'render_for' ), 5, 1 );
		add_action( 'woocommerce_api_' . self::API_ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Whether the order waits on its shopper's age check: held by this
	 * plugin, undecided, and either not bound yet (and not waiting on staff)
	 * or bound to a verification waiting at AgeCheck's page.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function needs_shopper( \WC_Order $order ): bool {
		if ( 'shop_order' !== $order->get_type() || Hold::STATUS !== $order->get_status( 'edit' ) || 'yes' !== Meta::get( $order, Meta::REQUIRED ) ) {
			return false;
		}
		if ( Meta::is_released( $order ) || Meta::STATUS_DENIED === Meta::get( $order, Meta::STATUS ) || Subscriptions::needs_review( $order ) ) {
			return false;
		}
		if ( '' === Meta::get( $order, Meta::VERIFICATION_ID ) ) {
			return '' === Meta::get( $order, Meta::REASON );
		}
		return in_array( Meta::get( $order, Meta::VERIFICATION_STATUS ), Links::HOSTED_STATES, true );
	}

	/**
	 * Prints the order's age check state on the order-received and view-order
	 * pages: the "Verify now" box while the order waits on its shopper, the
	 * outcome after a failed check, and a short note while the result is on
	 * its way (the release run that records it runs after the shopper is back).
	 * Never an id, a reason code beyond US-only, or a verdict detail.
	 *
	 * @param mixed $order_id Order id (or order).
	 */
	public static function render_for( $order_id ): void {
		$order = $order_id instanceof \WC_Order ? $order_id : wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() || 'yes' !== Meta::get( $order, Meta::REQUIRED ) || Meta::is_released( $order ) || Install::is_paused() ) {
			return;
		}
		if ( Meta::STATUS_DENIED === Meta::get( $order, Meta::STATUS ) ) {
			self::render_denied( $order );
			return;
		}
		if ( ! self::needs_shopper( $order ) ) {
			if ( Hold::STATUS === $order->get_status( 'edit' ) ) {
				echo '<section class="navexa-agecheck-order" style="margin:0 0 2em"><h2>' . esc_html__( 'Age check', 'navexa-agecheck-for-woocommerce' ) . '</h2>';
				echo '<p>' . esc_html__( 'This order has age-restricted items. We are finishing your age check, and the store ships the order once it is complete.', 'navexa-agecheck-for-woocommerce' ) . '</p></section>';
			}
			return;
		}
		echo '<section class="navexa-agecheck-order" style="margin:0 0 2em"><h2>' . esc_html__( 'Verify your age', 'navexa-agecheck-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'This order has age-restricted items. The store ships it once you pass an age check.', 'navexa-agecheck-for-woocommerce' ) . '</p>';
		if ( Links::STATE_SENT === Links::state( $order ) ) {
			echo '<p>' . esc_html__( 'We have also emailed you a link to the age check.', 'navexa-agecheck-for-woocommerce' ) . '</p>';
		}
		printf(
			'<form method="post" action="%1$s"><input type="hidden" name="step" value="%2$s" /><input type="hidden" name="order" value="%3$d" /><input type="hidden" name="key" value="%4$s" /><input type="hidden" name="_wpnonce" value="%5$s" /><button type="submit" class="button wp-element-button">%6$s</button></form></section>',
			esc_url( self::endpoint() ),
			esc_attr( self::STEP_START ),
			(int) $order->get_id(),
			esc_attr( (string) $order->get_order_key() ),
			esc_attr( wp_create_nonce( self::NONCE_PREFIX . $order->get_id() ) ),
			esc_html__( 'Verify now', 'navexa-agecheck-for-woocommerce' )
		);
	}

	/**
	 * The outcome of a failed check: the order will not ship, and what
	 * happens to the payment.
	 *
	 * @param \WC_Order $order Denied order.
	 */
	private static function render_denied( \WC_Order $order ): void {
		$status = $order->get_status( 'edit' );
		echo '<section class="navexa-agecheck-order" style="margin:0 0 2em"><h2>' . esc_html__( 'This order will not ship', 'navexa-agecheck-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html(
			'not_us' === Meta::get( $order, Meta::REASON )
				? __( 'Age-restricted items ship only to US addresses, so the store cannot ship this order.', 'navexa-agecheck-for-woocommerce' )
				: __( 'We could not verify your age, so the store cannot ship this order.', 'navexa-agecheck-for-woocommerce' )
		) . '</p>';
		if ( 'refunded' === $status || Meta::REFUND_REFUNDED === Meta::get( $order, Meta::REFUND ) ) {
			$money = __( 'Your payment has been refunded.', 'navexa-agecheck-for-woocommerce' );
		} elseif ( 'cancelled' === $status ) {
			$money = __( 'The order has been cancelled.', 'navexa-agecheck-for-woocommerce' );
		} else {
			$money = __( 'If you have paid, the store will refund your payment.', 'navexa-agecheck-for-woocommerce' );
		}
		echo '<p>' . esc_html( $money ) . '</p></section>';
	}

	/**
	 * The endpoint, on the store's address (Install::home()).
	 */
	public static function endpoint(): string {
		return trailingslashit( Install::home() ) . '?wc-api=' . self::API_ACTION;
	}

	/**
	 * Where AgeCheck's page sends the shopper back for this order: the return
	 * step with the order's tag, and with $with_nonce the visitor's nonce
	 * (only for the start flow: an emailed link has no visitor). '' while
	 * the order has no external id of its own or the install no guard secret.
	 *
	 * @param \WC_Order $order      Order.
	 * @param bool      $with_nonce Add the current visitor's nonce.
	 */
	public static function return_url( \WC_Order $order, bool $with_nonce = false ): string {
		$tag = self::tag( $order );
		if ( '' === $tag ) {
			return '';
		}
		$url = self::endpoint() . '&step=' . self::STEP_RETURN . '&order=' . (int) $order->get_id() . '&t=' . $tag;
		if ( $with_nonce ) {
			$url .= '&n=' . rawurlencode( wp_create_nonce( self::NONCE_PREFIX . $order->get_id() ) );
		}
		return strlen( $url ) <= 2048 ? $url : '';
	}

	/**
	 * The endpoint's callback: answers and exits.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- respond() checks the order key and the nonce itself.
		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$request = 'POST' === $method ? wp_unslash( $_POST ) : wp_unslash( $_GET );
		// phpcs:enable WordPress.Security.NonceVerification
		$answer = self::respond( $method, is_array( $request ) ? $request : array() );
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
		}
		if ( isset( $answer['redirect'] ) ) {
			// The hosted page is AgeCheck's host (checked by Start::is_hosted_url()); the order page is this store's.
			wp_redirect( $answer['redirect'], 303 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated above.
			exit;
		}
		status_header( $answer['status'] );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		echo self::html( $answer['title'], $answer['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
		exit;
	}

	/**
	 * What the endpoint answers: a redirect, or a page.
	 *
	 * @param string               $method  HTTP method.
	 * @param array<string, mixed> $request The request's fields (unslashed).
	 * @return array{redirect?: string, status: int, title: string, message: string}
	 */
	public static function respond( string $method, array $request ): array {
		$step     = isset( $request['step'] ) && is_string( $request['step'] ) ? sanitize_key( $request['step'] ) : '';
		$order_id = isset( $request['order'] ) && is_scalar( $request['order'] ) ? absint( $request['order'] ) : 0;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( self::STEP_RETURN === $step && 'GET' === $method ) {
			return self::finish( $order instanceof \WC_Order ? $order : null, self::field( $request, 't' ), self::field( $request, 'n' ) );
		}
		if ( self::STEP_START !== $step || 'POST' !== $method ) {
			return self::page( 400, __( 'This page has expired. Go back to your order and select Verify now again.', 'navexa-agecheck-for-woocommerce' ) );
		}
		if ( ! $order instanceof \WC_Order || ! self::may_view( $order, self::field( $request, 'key' ), self::field( $request, '_wpnonce' ) ) ) {
			return self::page( 403, __( 'This page has expired. Go back to your order and select Verify now again.', 'navexa-agecheck-for-woocommerce' ) );
		}
		return self::start( $order );
	}

	/**
	 * The start step for an order the visitor may see.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{redirect?: string, status: int, title: string, message: string}
	 */
	private static function start( \WC_Order $order ): array {
		$id   = (int) $order->get_id();
		$ip   = RateLimit::client_ip();
		$rate = RateLimit::check( 'order_verify_order', (string) $id, self::LIMITS['start_order'], HOUR_IN_SECONDS );
		if ( RateLimit::OK === $rate ) {
			$rate = RateLimit::check( 'order_verify_ip', '' === $ip ? 'unknown' : $ip, self::LIMITS['start_ip'], HOUR_IN_SECONDS );
		}
		if ( RateLimit::LIMITED === $rate ) {
			return self::page( 429, __( 'Too many age check attempts. Please wait a while and try again.', 'navexa-agecheck-for-woocommerce' ) );
		}
		if ( RateLimit::OK !== $rate || Install::is_paused() ) {
			return self::unavailable();
		}
		if ( ! self::needs_shopper( $order ) ) {
			return self::to_order( $order );
		}
		if ( '' === Meta::get( $order, Meta::VERIFICATION_ID ) ) {
			// The hold's release run has not run yet: run it now.
			try {
				$outcome = Release::run( $id, Release::TRIGGER_SHOPPER );
			} catch ( ConnectError $e ) {
				return self::unavailable();
			}
			if ( Release::LOCKED === $outcome ) {
				return self::unavailable();
			}
			$fresh = wc_get_order( $id );
			$order = $fresh instanceof \WC_Order ? $fresh : $order;
			if ( ! self::needs_shopper( $order ) ) {
				return '' === Meta::get( $order, Meta::VERIFICATION_ID ) && Hold::STATUS === $order->get_status( 'edit' )
					? self::page( 200, __( 'We could not start your age check online. The store will contact you about this order.', 'navexa-agecheck-for-woocommerce' ) )
					: self::to_order( $order );
			}
		}
		$vid    = Meta::get( $order, Meta::VERIFICATION_ID );
		$info   = Mode::info( Client::KEY_START );
		$return = null !== $info && $info->origin_allowed ? self::return_url( $order, self::nonce_is_bound() ) : '';
		$req    = array( 'verificationId' => $vid );
		if ( '' !== $return ) {
			$req['returnUrl'] = $return;
		}
		$client = Client::for_key( Client::KEY_START );
		try {
			$session = $client->call( 'CreateHostedSession', $req );
		} catch ( ConnectError $e ) {
			if ( '' !== $e->local_reason() || 'failed_precondition' !== $e->connect_code() ) {
				return self::unavailable();
			}
			// Either the verification no longer waits on the shopper (being
			// checked, or decided) or AgeCheck refuses the store itself (live
			// access: no card, an unpaid invoice, not open yet). GetVerification
			// tells them apart without reading the engine's text: a status that
			// moved is recorded, so the order page stops offering the button,
			// and a release run asks GetClearance; one still waiting on the
			// shopper means the refusal is the store's (or the status could not
			// be read): fail closed, no release run.
			$fresh = self::refresh_status( $id, $vid, $client );
			if ( null !== $fresh && ! self::needs_shopper( $fresh ) ) {
				Release::enqueue( $id, Release::TRIGGER_SHOPPER );
				return self::to_order( $fresh );
			}
			Client::log( 'warning', sprintf( 'agecheck order verify refused order=%d vid=%s code=failed_precondition', $id, $vid ) );
			return self::unavailable();
		}
		$url = isset( $session['hostedUrl'] ) && is_string( $session['hostedUrl'] ) ? $session['hostedUrl'] : '';
		if ( ! Start::is_hosted_url( $url ) ) {
			Client::log( 'error', 'agecheck order verify has no hosted link order=' . $id . ' vid=' . $vid );
			return self::unavailable();
		}
		Client::log( 'info', sprintf( 'agecheck order verify started order=%d vid=%s', $id, $vid ) );
		return array(
			'redirect' => $url,
			'status'   => 303,
			'title'    => '',
			'message'  => '',
		);
	}

	/**
	 * The return step.
	 *
	 * @param \WC_Order|null $order Order named by the request.
	 * @param string         $tag   Its tag as sent.
	 * @param string         $nonce The start's nonce, or ''.
	 * @return array{redirect?: string, status: int, title: string, message: string}
	 */
	private static function finish( ?\WC_Order $order, string $tag, string $nonce ): array {
		$done = self::page( 200, __( 'Thank you. The store ships your order once AgeCheck confirms your age check, and emails you as usual.', 'navexa-agecheck-for-woocommerce' ), __( 'Your age check is finished', 'navexa-agecheck-for-woocommerce' ) );
		$want = null === $order ? '' : self::tag( $order );
		if ( null === $order || '' === $want || ! hash_equals( $want, $tag ) ) {
			return $done; // Says nothing about the order.
		}
		$id       = (int) $order->get_id();
		$customer = (int) $order->get_customer_id();
		$own      = $customer > 0
			? get_current_user_id() === $customer
			: '' !== $nonce && false !== wp_verify_nonce( $nonce, self::NONCE_PREFIX . $id );
		if ( Hold::STATUS === $order->get_status( 'edit' ) && RateLimit::OK === RateLimit::check( 'order_return_order', (string) $id, self::LIMITS['return_order'], HOUR_IN_SECONDS ) ) {
			$vid = Meta::get( $order, Meta::VERIFICATION_ID );
			if ( $own && '' !== $vid && in_array( Meta::get( $order, Meta::VERIFICATION_STATUS ), Links::HOSTED_STATES, true ) ) {
				// The order page reads the stored status: record where the
				// check stands now (before the run is queued, so the two never
				// meet at the order's lock), so a shopper who just passed is not
				// offered Verify now again while the run waits for its turn.
				$fresh = self::refresh_status( $id, $vid, Client::for_key( Client::KEY_START ) );
				$order = null === $fresh ? $order : $fresh;
			}
			Release::enqueue( $id, Release::TRIGGER_SHOPPER );
		}
		return $own ? self::to_order( $order ) : $done;
	}

	/**
	 * Records the verification's status as GetVerification answers it, under
	 * the order's lock (skipped when a run holds it: the run records its own).
	 * Never throws.
	 *
	 * @param int    $order_id Order id.
	 * @param string $vid      The verification the caller found on the order.
	 * @param Client $client   Start-key client.
	 * @return \WC_Order|null The order as recorded, or null when nothing was (locked, another verification, AgeCheck did not answer).
	 */
	private static function refresh_status( int $order_id, string $vid, Client $client ): ?\WC_Order {
		$lock  = Release::lock_name( $order_id );
		$token = Lock::acquire( $lock, Release::LOCK_TTL );
		if ( '' === $token ) {
			return null;
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || Meta::get( $order, Meta::VERIFICATION_ID ) !== $vid ) {
				return null;
			}
			$answer       = $client->call( 'GetVerification', array( 'id' => $vid ) );
			$verification = isset( $answer['verification'] ) && is_array( $answer['verification'] ) ? $answer['verification'] : array();
			if ( array() === $verification ) {
				return null;
			}
			Binding::record_verification( $order, $verification );
			$order->save_meta_data();
			return $order;
		} catch ( ConnectError $e ) {
			unset( $e ); // The release run records it later.
			return null;
		} finally {
			Lock::release( $lock, $token );
		}
	}

	/**
	 * Whether a nonce made now is tied to this visitor, so the return step's n
	 * proves the browser that started the check: signed in (WordPress binds
	 * the nonce to the user and their login session), or a guest with a
	 * WooCommerce session (WooCommerce binds a woocommerce* nonce to the
	 * session's customer id, but only while there is one: without it every
	 * visitor with no session shares one nonce). A guest without a session
	 * gets one here (its cookie goes out with the redirect). False when none
	 * can be had: the return then never opens the order page.
	 */
	private static function nonce_is_bound(): bool {
		if ( is_user_logged_in() ) {
			return true;
		}
		$session = Checkout\Session::wc_session();
		if ( null === $session || ! method_exists( $session, 'has_session' ) ) {
			return false;
		}
		if ( ! $session->has_session() && method_exists( $session, 'set_customer_session_cookie' ) ) {
			$session->set_customer_session_cookie( true );
		}
		return (bool) $session->has_session();
	}

	/**
	 * Whether the visitor may act for the order: its key, their nonce, and
	 * for an order with an account, that customer signed in.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Order key sent.
	 * @param string    $nonce Nonce sent.
	 */
	private static function may_view( \WC_Order $order, string $key, string $nonce ): bool {
		$want = (string) $order->get_order_key();
		if ( '' === $want || '' === $key || ! hash_equals( $want, $key ) ) {
			return false;
		}
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::NONCE_PREFIX . $order->get_id() ) ) {
			return false;
		}
		$customer = (int) $order->get_customer_id();
		return 0 === $customer || get_current_user_id() === $customer;
	}

	/**
	 * The order's return tag: 32 hex of HMAC-SHA256 over the blog, order and
	 * external id under a key derived from the guard secret; '' without
	 * either.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function tag( \WC_Order $order ): string {
		$external = Binding::owned_external_id( $order );
		$secret   = Install::guard_secret();
		if ( '' === $external || '' === $secret ) {
			return '';
		}
		$key = hash_hmac( 'sha256', 'navexa-agecheck/order-return/v1', $secret, true );
		return substr( hash_hmac( 'sha256', get_current_blog_id() . '|' . (int) $order->get_id() . '|' . $external, $key ), 0, 32 );
	}

	/**
	 * A string field of the request, or ''.
	 *
	 * @param array<string, mixed> $request Request.
	 * @param string               $name    Field.
	 */
	private static function field( array $request, string $name ): string {
		return isset( $request[ $name ] ) && is_string( $request[ $name ] ) ? sanitize_text_field( $request[ $name ] ) : '';
	}

	/**
	 * A redirect to the order's page.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{redirect: string, status: int, title: string, message: string}
	 */
	private static function to_order( \WC_Order $order ): array {
		return array(
			'redirect' => (string) $order->get_checkout_order_received_url(),
			'status'   => 303,
			'title'    => '',
			'message'  => '',
		);
	}

	/**
	 * The fail-closed page.
	 *
	 * @return array{status: int, title: string, message: string}
	 */
	private static function unavailable(): array {
		return self::page( 503, __( 'Age verification is temporarily unavailable. Please try again in a few minutes.', 'navexa-agecheck-for-woocommerce' ) );
	}

	/**
	 * A page answer.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Text.
	 * @param string $title   Heading ('' for "Age check").
	 * @return array{status: int, title: string, message: string}
	 */
	private static function page( int $status, string $message, string $title = '' ): array {
		return array(
			'status'  => $status,
			'title'   => '' === $title ? __( 'Age check', 'navexa-agecheck-for-woocommerce' ) : $title,
			'message' => $message,
		);
	}

	/**
	 * The page's HTML.
	 *
	 * @param string $title   Heading.
	 * @param string $message Text.
	 */
	public static function html( string $title, string $message ): string {
		$lang = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'language' ) : 'en';
		return '<!DOCTYPE html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow">'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<style>body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1.5rem;line-height:1.5;color:#1e1e1e;text-align:center}a{color:inherit}</style>'
			. '</head><body>'
			. '<h1 style="font-size:1.4rem">' . esc_html( $title ) . '</h1>'
			. '<p>' . esc_html( $message ) . '</p>'
			. '<p><a href="' . esc_url( trailingslashit( Install::home() ) ) . '">' . esc_html__( 'Back to the store', 'navexa-agecheck-for-woocommerce' ) . '</a></p>'
			. '</body></html>';
	}
}
