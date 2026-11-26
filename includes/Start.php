<?php
/**
 * The merchant side of the AgeCheck loader: start and refresh.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

use Navexa\AgeCheck\Checkout\Gate;
use Navexa\AgeCheck\Checkout\Session;

defined( 'ABSPATH' ) || exit;

/**
 * A Store API extension (namespace navexa-agecheck):
 *
 * - update callback, reached through POST /wc/store/v1/cart/extensions
 *   {namespace: "navexa-agecheck", data: {v: 1, action: "start"|"refresh"}}:
 *   extensionCartUpdate() on block checkout, a fetch with the Store API
 *   Nonce header on classic checkout. The Store API loads the shopper's
 *   session before it checks the nonce, so this works for guests and keeps a
 *   signed-in shopper's session;
 * - cart endpoint data extensions["navexa-agecheck"] =
 *   {v: 1, required, status, hosted_url?, message?}. hosted_url and the
 *   action's message appear only in the response to the start or refresh
 *   call itself; every other cart response carries the state read from the
 *   session, with no AgeCheck call.
 *
 * Statuses: passed | pending | failed | refused | unavailable (the plan's
 * §2.7 contract) plus none (nothing started, or the ship-to changed since).
 * The cart data also reads passed for a payment retry: the draft the Store
 * API will resume is already bound to the shopper's check at this ship-to
 * (Gate::resumable_bound_order() for Gate::ROUTE_STORE_API), and the gate
 * admits it as it is. The classic checkout's button asks the same for the
 * order the classic checkout resumes (Gate::ROUTE_CLASSIC).
 *
 * The browser sends no PII and no verification id, and never receives the
 * verification id or an API key. Start calls CreateVerification once per
 * (session, ship-to): intended_ship_to (the normalized ship-to),
 * document_name_check NONE (owner: no name match before the order exists),
 * return_url = the return page with popup=1, and the identity for the
 * engine's data match (identity()): the billing name and the billing address
 * as the residential address, only when both are filled in, the address is
 * a US one and the order ships there, never a DOB; for a signed-in shopper
 * also customer_ref (CustomerRef, E12a: a returning customer may come back
 * PASSED with PASS_CLASS_REUSED at once, which clears like any pass); then
 * CreateHostedSession for a fresh link. Only the start key is used.
 * Refresh calls GetVerification and mints the checkout guard on PASSED with a
 * clearing pass class. Any error answers unavailable.
 *
 * With Turnstile on (Turnstile, off by default) a start also carries
 * data.turnstile, a Cloudflare Turnstile token: no token, or one Cloudflare
 * refuses, answers refused; Turnstile without its keys, or Cloudflare not
 * answering, answers unavailable. Checked after the rate limits, before any
 * AgeCheck call.
 *
 * Engine contract (E4a, in production since tax v0.0.164; proto
 * agecheck/v1/verification.proto): CreateVerification's identity is optional
 * under an ID-only pass policy and under data_then_doc (the legacy policy
 * that runs the database check on every create needs name, dob and
 * residential address and answers INVALID_ARGUMENT, which reads as
 * unavailable here); return_url (5), document_name_check (6) and
 * intended_ship_to (7). document_name_check NONE requires intended_ship_to,
 * is allowed with a name (unset would mean REQUIRED once a name is sent, so
 * it is always sent), and AttachOrder then binds only an order with the same
 * ship-to (FAILED_PRECONDITION "ship_to_changed" otherwise).
 *
 * Data match (E5, tax v0.0.167, policy data_then_doc): the engine runs one
 * paid data check only when the merchant sent identity.name and
 * identity.residential_address, the intended ship-to is a US address equal
 * to the residential address (ZIP+4 compared as its ZIP) and the hosted page
 * has the shopper's DOB and data consent (E4b, AWAITING_IDENTITY until then).
 * Anything else goes to the ID + selfie check (NEEDS_DOC). A pass is
 * PASS_CLASS_DATA_VERIFIED, which clears like DOC_ONLY. A shopper whose
 * billing address is not the ship-to is not eligible, so no identity is sent
 * for them (nothing would use it).
 */
final class Start {

	const STORE_NAMESPACE = 'navexa-agecheck';
	const VERSION         = 1;

	const ACTION_START   = 'start';
	const ACTION_REFRESH = 'refresh';

	const STATUS_NONE        = 'none';
	const STATUS_PENDING     = 'pending';
	const STATUS_PASSED      = 'passed';
	const STATUS_FAILED      = 'failed';
	const STATUS_REFUSED     = 'refused';
	const STATUS_UNAVAILABLE = 'unavailable';

	/**
	 * Default limits per hour: starts per session and per client IP (plan
	 * §5), refreshes per session and per client IP.
	 */
	const LIMITS = array(
		'start_session'   => 10,
		'start_ip'        => 20,
		'refresh_session' => 60,
		'refresh_ip'      => 120,
	);

	/**
	 * Engine contract (E4a): DocumentNameCheck NONE.
	 */
	const DOCUMENT_NAME_CHECK_NONE = 'DOCUMENT_NAME_CHECK_NONE';

	/**
	 * The answer to this request's start or refresh, merged into the cart
	 * endpoint data of the same response.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $answer = null;

	/**
	 * Registers the Store API extension once WooCommerce Blocks has loaded.
	 */
	public static function register(): void {
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_store_api();
			return;
		}
		add_action( 'woocommerce_blocks_loaded', array( self::class, 'register_store_api' ) );
	}

	/**
	 * Registers the cart endpoint data and the update callback.
	 */
	public static function register_store_api(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => 'cart',
				'namespace'       => self::STORE_NAMESPACE,
				'data_callback'   => array( self::class, 'endpoint_data' ),
				'schema_callback' => array( self::class, 'endpoint_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::STORE_NAMESPACE,
				'callback'  => array( self::class, 'handle' ),
			)
		);
	}

	/**
	 * The update callback. Never throws: the answer rides on the cart
	 * response's endpoint data.
	 *
	 * @param mixed $data Request data: {v: 1, action: "start"|"refresh"}.
	 */
	public static function handle( $data ): void {
		self::$answer = self::answer( is_array( $data ) ? $data : array() );
	}

	/**
	 * Clears the request's answer (tests, and long-running processes).
	 */
	public static function reset(): void {
		self::$answer = null;
	}

	/**
	 * Runs one start or refresh.
	 *
	 * @param array<string, mixed> $data Request data.
	 * @return array<string, mixed> {v, status, hosted_url?, message?}
	 */
	public static function answer( array $data ): array {
		$version = isset( $data['v'] ) ? $data['v'] : null;
		$action  = isset( $data['action'] ) && is_string( $data['action'] ) ? $data['action'] : '';
		if ( self::VERSION !== $version || ! in_array( $action, array( self::ACTION_START, self::ACTION_REFRESH ), true ) ) {
			return self::reply( self::STATUS_REFUSED, __( 'This page is out of date. Reload it and try again.', 'navexa-agecheck-for-woocommerce' ) );
		}

		$context = self::context();
		if ( null !== $context['refusal'] ) {
			$refusal = $context['refusal'];
			unset( $refusal['_required'] );
			return $refusal;
		}
		/**
		 * The checked ship-to (context() refuses without one).
		 *
		 * @var ShipTo $ship_to
		 */
		$ship_to = $context['ship_to'];

		if ( Install::is_paused() || ! Client::for_key( Client::KEY_START )->has_key() ) {
			Client::log( 'error', 'agecheck start refused reason=' . ( Install::is_paused() ? 'install_unconfirmed' : 'no_start_key' ) );
			return self::unavailable();
		}
		$mac = $ship_to->mac();
		$sid = Session::sid( true );
		if ( '' === $mac || '' === $sid ) {
			Client::log( 'error', 'agecheck start refused reason=no_session_or_secret' );
			return self::unavailable();
		}
		$rate = self::within_limits( $action, Session::rate_subject() );
		if ( RateLimit::UNAVAILABLE === $rate ) {
			return self::unavailable();
		}
		if ( RateLimit::OK !== $rate ) {
			return self::reply( self::STATUS_UNAVAILABLE, __( 'Too many age check attempts. Please wait a while and try again.', 'navexa-agecheck-for-woocommerce' ) );
		}
		if ( self::ACTION_START === $action ) {
			$turnstile = Turnstile::verify( isset( $data['turnstile'] ) ? $data['turnstile'] : null, RateLimit::client_ip() );
			if ( Turnstile::FAILED === $turnstile ) {
				return self::reply( self::STATUS_REFUSED, self::turnstile_message() );
			}
			if ( Turnstile::OK !== $turnstile ) {
				return self::unavailable();
			}
		}

		$client = Client::for_key( Client::KEY_START );
		try {
			return self::ACTION_START === $action
				? self::start( $client, $ship_to, $sid, $mac )
				: self::refresh( $client, $mac );
		} catch ( ConnectError $e ) {
			// The engine's own US-only refusal (E3), e.g. for a territory it does
			// not accept yet: say so instead of "temporarily unavailable".
			if ( 'invalid_argument' === $e->connect_code() && false !== stripos( $e->server_message(), 'US addresses only' ) ) {
				return self::reply( self::STATUS_REFUSED, Gate::message( Gate::REFUSE_US_ONLY ) );
			}
			return self::unavailable();
		}
	}

	/**
	 * Cart endpoint data: {v, required, status, hosted_url?, message?}.
	 * Reads the session only; never calls AgeCheck. The checkout block reads
	 * it and places the order through the Store API, so a payment retry reads
	 * passed only for the draft the Store API resumes.
	 *
	 * @return array<string, mixed>
	 */
	public static function endpoint_data(): array {
		$state = self::state( Gate::ROUTE_STORE_API );
		if ( null !== self::$answer ) {
			$state = array_merge( $state, self::$answer );
			unset( $state['v'] );
			$state = array( 'v' => self::VERSION ) + $state;
			if ( self::STATUS_PASSED === $state['status'] ) {
				unset( $state['message'] );
			}
		}
		return $state;
	}

	/**
	 * The session's state for a checkout that places its order through
	 * $route: the cart data (the checkout block, Gate::ROUTE_STORE_API) and
	 * the classic button (Gate::ROUTE_CLASSIC). Passed for a guarded check at
	 * this ship-to, or for the bound order a payment retry through $route
	 * resumes at its bound ship-to (Gate::resumable_bound_order()): exactly
	 * what the gate on that route admits without a new check.
	 *
	 * @param string $route Gate::ROUTE_STORE_API or Gate::ROUTE_CLASSIC.
	 * @return array<string, mixed>
	 */
	public static function state( string $route ): array {
		$context  = self::context();
		$required = null === $context['refusal'] || ! empty( $context['refusal']['_required'] );
		$state    = array(
			'v'        => self::VERSION,
			'required' => $required,
			'status'   => self::STATUS_NONE,
		);
		if ( null !== $context['refusal'] ) {
			if ( $required && isset( $context['refusal']['message'] ) ) {
				$state['message'] = $context['refusal']['message'];
			}
			return $state;
		}
		/**
		 * The checked ship-to.
		 *
		 * @var ShipTo $ship_to
		 */
		$ship_to = $context['ship_to'];
		$mac     = $ship_to->mac();
		if ( '' === $mac ) {
			return $state;
		}
		if ( null !== Session::guarded_verification_id( $mac ) || null !== Gate::resumable_bound_order( $ship_to, $route ) ) {
			// A guarded pass, or the order a payment retry through $route
			// resumes at the ship-to it was bound with (its check left the
			// session when the order was bound, before payment): the gate
			// admits either.
			$state['status'] = self::STATUS_PASSED;
			return $state;
		}
		$record = Session::record();
		if ( null === $record ) {
			return $state;
		}
		if ( ! hash_equals( $record['shipto_mac'], $mac ) ) {
			$state['message'] = Gate::message( Gate::REFUSE_SHIP_TO_CHANGED );
			return $state;
		}
		$state['status'] = Session::STATUS_FAILED === $record['status'] ? self::STATUS_FAILED : self::STATUS_PENDING;
		return $state;
	}

	/**
	 * Schema of the cart endpoint data.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function endpoint_schema(): array {
		return array(
			'v'          => array(
				'description' => __( 'Contract version.', 'navexa-agecheck-for-woocommerce' ),
				'type'        => 'integer',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'required'   => array(
				'description' => __( 'Whether the cart needs an age check before checkout.', 'navexa-agecheck-for-woocommerce' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'status'     => array(
				'description' => __( 'Age check status.', 'navexa-agecheck-for-woocommerce' ),
				'type'        => 'string',
				'enum'        => array( self::STATUS_NONE, self::STATUS_PENDING, self::STATUS_PASSED, self::STATUS_FAILED, self::STATUS_REFUSED, self::STATUS_UNAVAILABLE ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'hosted_url' => array(
				'description' => __( 'Where the shopper verifies; only in the response to a start.', 'navexa-agecheck-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'message'    => array(
				'description' => __( 'Text to show the shopper.', 'navexa-agecheck-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * Start: reuse the session's verification for this ship-to while it can
	 * still pass, else create one; answer passed, pending with a hosted URL,
	 * or failed.
	 *
	 * @param Client $client  Start-key client.
	 * @param ShipTo $ship_to Ship-to.
	 * @param string $sid     Session id.
	 * @param string $mac     Ship-to MAC.
	 * @return array<string, mixed>
	 * @throws ConnectError On an AgeCheck error.
	 */
	private static function start( Client $client, ShipTo $ship_to, string $sid, string $mac ): array {
		RateLimit::record_start();

		$record       = Session::record();
		$verification = null;
		$n            = 0;
		if ( null !== $record && hash_equals( $record['shipto_mac'], $mac ) && Session::STATUS_FAILED !== $record['status'] ) {
			$verification = self::get_verification( $client, $record['vid'] );
			$n            = $record['n'];
			if ( null !== $verification && ( self::is_bound( $verification ) || 'failed' === Gate::verdict( $verification, Client::KEY_START ) ) ) {
				$verification = null; // Bound to an order, or failed: this checkout needs a new one.
			} elseif ( null !== $verification && 'VERIFICATION_STATUS_CREATED' === self::status_of( $verification ) ) {
				// CREATED stopped before its policy step; replaying its key resumes it.
				$verification = self::create( $client, $ship_to, $sid, $mac, $n );
			}
		}
		if ( null === $verification ) {
			$n            = Session::next_counter();
			$verification = self::create( $client, $ship_to, $sid, $mac, $n );
			if ( self::is_bound( $verification ) ) {
				// The key replayed a verification an order took since: take the next one.
				$n            = Session::next_counter();
				$verification = self::create( $client, $ship_to, $sid, $mac, $n );
			}
		}

		$vid = isset( $verification['id'] ) && is_string( $verification['id'] ) ? $verification['id'] : '';
		if ( ! Binding::is_verification_id( $vid ) || self::is_bound( $verification ) ) {
			Client::log( 'error', 'agecheck start got an unusable verification' );
			return self::unavailable();
		}
		return self::conclude( $client, $verification, $vid, $mac, $n, true );
	}

	/**
	 * Refresh: re-read the session's verification for this ship-to.
	 *
	 * @param Client $client Start-key client.
	 * @param string $mac    Ship-to MAC.
	 * @return array<string, mixed>
	 * @throws ConnectError On an AgeCheck error.
	 */
	private static function refresh( Client $client, string $mac ): array {
		$record = Session::record();
		if ( null === $record ) {
			return self::reply( self::STATUS_NONE );
		}
		if ( ! hash_equals( $record['shipto_mac'], $mac ) ) {
			return self::reply( self::STATUS_NONE, Gate::message( Gate::REFUSE_SHIP_TO_CHANGED ) );
		}
		$verification = self::get_verification( $client, $record['vid'] );
		if ( null === $verification || self::is_bound( $verification ) ) {
			Session::forget_verification();
			return self::reply( self::STATUS_NONE );
		}
		return self::conclude( $client, $verification, $record['vid'], $mac, $record['n'], false );
	}

	/**
	 * Records what a verification allows and answers the browser. On start,
	 * a verification waiting on the shopper also gets a fresh hosted URL.
	 *
	 * @param Client               $client       Start-key client.
	 * @param array<string, mixed> $verification Verification.
	 * @param string               $vid          Its id.
	 * @param string               $mac          Ship-to MAC.
	 * @param int                  $n            Idempotency counter it was created with.
	 * @param bool                 $with_link    Mint a hosted URL when the shopper has a step to take.
	 * @return array<string, mixed>
	 */
	private static function conclude( Client $client, array $verification, string $vid, string $mac, int $n, bool $with_link ): array {
		$verdict = Gate::verdict( $verification, Client::KEY_START );
		switch ( $verdict ) {
			case 'passed':
				$guard = GuardToken::mint_checkout_guard( Session::sid(), $vid, $mac );
				if ( '' === $guard ) {
					return self::unavailable();
				}
				Session::set_record( $vid, $mac, $n, Session::STATUS_PASSED );
				Session::set_guard( $guard, $vid );
				Client::log( 'info', 'agecheck checkout verification passed vid=' . $vid );
				return self::reply( self::STATUS_PASSED );
			case 'failed':
			case 'not_clearing':
				Session::set_record( $vid, $mac, $n, Session::STATUS_FAILED );
				Session::set_guard( '' );
				Client::log( 'info', sprintf( 'agecheck checkout verification refused vid=%s verdict=%s', $vid, $verdict ) );
				return self::reply( self::STATUS_FAILED, Gate::message( Gate::REFUSE_FAILED ) );
			case 'mode_unknown':
			case 'mode_mismatch':
				Client::log( 'error', sprintf( 'agecheck checkout verification unusable vid=%s verdict=%s', $vid, $verdict ) );
				return self::unavailable();
		}

		Session::set_record( $vid, $mac, $n, Session::STATUS_PENDING );
		$status = self::status_of( $verification );
		if ( ! $with_link ) {
			return self::reply( self::STATUS_PENDING );
		}
		// CreateHostedSession serves NEEDS_DOC and DOC_PENDING, and E4b's
		// AWAITING_IDENTITY (the data match's consent and DOB step), listed so
		// E4b's engine release needs no plugin release.
		if ( ! in_array( $status, array( 'VERIFICATION_STATUS_NEEDS_DOC', 'VERIFICATION_STATUS_DOC_PENDING', 'VERIFICATION_STATUS_AWAITING_IDENTITY' ), true ) ) {
			// Nothing for the shopper to do on AgeCheck's page (being checked, or held).
			return self::reply( self::STATUS_PENDING, __( 'Your age check is being processed. Select Verify age again in a minute to see if it is done.', 'navexa-agecheck-for-woocommerce' ) );
		}

		$url = '';
		try {
			$session = $client->call(
				'CreateHostedSession',
				array(
					'verificationId' => $vid,
					'returnUrl'      => ReturnPage::url( true ),
				)
			);
			$url     = isset( $session['hostedUrl'] ) && is_string( $session['hostedUrl'] ) ? $session['hostedUrl'] : '';
		} catch ( ConnectError $e ) {
			$url = '';
		}
		if ( '' === $url ) {
			// The link on the create answer, when there is one (E4a signs return_url into it too).
			$url = isset( $verification['hostedUrl'] ) && is_string( $verification['hostedUrl'] ) ? $verification['hostedUrl'] : '';
		}
		if ( ! self::is_hosted_url( $url ) ) {
			Client::log( 'error', 'agecheck start has no hosted link vid=' . $vid );
			return self::unavailable();
		}
		return array(
			'v'          => self::VERSION,
			'status'     => self::STATUS_PENDING,
			'hosted_url' => $url,
		);
	}

	/**
	 * CreateVerification for this session and ship-to.
	 *
	 * @param Client $client  Start-key client.
	 * @param ShipTo $ship_to Ship-to.
	 * @param string $sid     Session id.
	 * @param string $mac     Ship-to MAC.
	 * @param int    $n       Idempotency counter.
	 * @return array<string, mixed> The verification.
	 * @throws ConnectError On an AgeCheck error.
	 */
	private static function create( Client $client, ShipTo $ship_to, string $sid, string $mac, int $n ): array {
		$key      = Install::install_id() . ':' . $sid . ':' . $mac . ':' . $n;
		$customer = CustomerRef::for_current_user();
		if ( '' !== $customer ) {
			// A guest who signs in mid-checkout keeps the sid: the key must
			// change with the request, or AgeCheck refuses the replay.
			$key .= ':c' . substr( $customer, strlen( CustomerRef::PREFIX ), 16 );
		}
		$request  = array(
			'idempotencyKey'    => $key,
			'intendedShipTo'    => $ship_to->engine_address(),
			'documentNameCheck' => self::DOCUMENT_NAME_CHECK_NONE,
			'returnUrl'         => ReturnPage::url( true ),
		);
		$identity = self::identity( $ship_to );
		if ( array() !== $identity ) {
			$request['identity'] = $identity;
		}
		if ( '' !== $customer ) {
			$request['customerRef'] = $customer;
		}
		$body = $client->call( 'CreateVerification', $request );
		return isset( $body['verification'] ) && is_array( $body['verification'] ) ? $body['verification'] : array();
	}

	/**
	 * GetVerification; null when AgeCheck does not know the id.
	 *
	 * @param Client $client Client.
	 * @param string $vid    Verification id.
	 * @return array<string, mixed>|null
	 * @throws ConnectError On any other AgeCheck error.
	 */
	private static function get_verification( Client $client, string $vid ): ?array {
		try {
			$body = $client->call( 'GetVerification', array( 'id' => $vid ) );
		} catch ( ConnectError $e ) {
			if ( 'not_found' === $e->connect_code() ) {
				return null;
			}
			throw $e;
		}
		return isset( $body['verification'] ) && is_array( $body['verification'] ) ? $body['verification'] : null;
	}

	/**
	 * The identity sent for the engine's data match (E5, data_then_doc): the
	 * billing name (first and last name, whitespace collapsed as the engine
	 * trims it, so a name of Unicode spaces counts as none) and the billing
	 * address as identity.residential_address, normalized by
	 * OrderAddress::normalize() exactly as the intended ship-to is. Sent only
	 * together, and only when the first name, the last name and a complete
	 * billing address are filled in, the address is a US one
	 * (OrderAddress::is_us(): the US, its territories and APO/FPO) and it is
	 * where the order ships (OrderAddress::same_residence(), the engine's own
	 * eligibility comparison); otherwise no identity at all, and the shopper
	 * takes the ID + selfie check. A billing address other than the ship-to
	 * is never eligible, and under document_name_check NONE the name is not
	 * compared with the ID either, so sending it would only hand AgeCheck
	 * personal data it cannot use.
	 *
	 * Never a date of birth (the shopper types it only on AgeCheck's page),
	 * and no email: a check started at checkout is never emailed (links are
	 * for order-bound checks, Binding).
	 *
	 * @param ShipTo $ship_to The intended ship-to (accepted: a US address).
	 * @return array<string, mixed> {name, residentialAddress} or empty.
	 */
	private static function identity( ShipTo $ship_to ): array {
		$customer = Session::customer();
		if ( null === $customer ) {
			return array();
		}
		$first = (string) $customer->get_billing_first_name();
		$last  = (string) $customer->get_billing_last_name();
		if ( 1 !== preg_match( '//u', $first ) || 1 !== preg_match( '//u', $last ) ) {
			return array(); // Not valid UTF-8.
		}
		$first = OrderAddress::collapse( $first );
		$last  = OrderAddress::collapse( $last );
		$name  = $first . ' ' . $last;
		if ( '' === $first || '' === $last || mb_strlen( $name, 'UTF-8' ) > OrderAddress::MAX_FIELD_RUNES ) {
			return array();
		}
		$billing = $customer->get_billing();
		$address = OrderAddress::normalize( is_array( $billing ) ? $billing : array() );
		if ( null === $address || ! OrderAddress::is_us( $address ) || ! OrderAddress::same_residence( $address, $ship_to->engine_address() ) ) {
			return array();
		}
		return array(
			'name'               => $name,
			'residentialAddress' => $address,
		);
	}

	/**
	 * What start, refresh and the cart data share: whether the cart needs a
	 * check at all, and the ship-to. 'refusal' is the answer when the browser
	 * cannot start; its private '_required' says whether the cart still needs
	 * a check (an address problem) or not (nothing to check).
	 *
	 * @return array{ship_to: ShipTo|null, refusal: array<string, mixed>|null}
	 */
	private static function context(): array {
		$cart     = Session::cart();
		$customer = Session::customer();
		if ( ! Gate::enforces() || null === $cart || null === $customer || ! Gate::cart_required() ) {
			return array(
				'ship_to' => null,
				'refusal' => self::reply( self::STATUS_REFUSED, __( 'Nothing in your cart needs an age check.', 'navexa-agecheck-for-woocommerce' ) ),
			);
		}
		if ( Requirement::cart_is_pickup() ) {
			return array(
				'ship_to' => null,
				'refusal' => self::reply( self::STATUS_REFUSED, __( 'Pickup orders are age-checked in store.', 'navexa-agecheck-for-woocommerce' ) ),
			);
		}
		$needs_shipping = (bool) $cart->needs_shipping_address();
		$ship_to        = ShipTo::from_customer( $customer, $needs_shipping );
		$country        = ShipTo::country_of_customer( $customer, $needs_shipping );
		if ( null === $ship_to || ! $ship_to->is_accepted() ) {
			$code    = null === $ship_to && ( '' === $country || ShipTo::is_accepted_country( $country ) ) ? Gate::REFUSE_NO_ADDRESS : Gate::REFUSE_US_ONLY;
			$refusal = self::reply( self::STATUS_REFUSED, Gate::message( $code ) );
			return array(
				'ship_to' => null,
				'refusal' => $refusal + array( '_required' => true ),
			);
		}
		return array(
			'ship_to' => $ship_to,
			'refusal' => null,
		);
	}

	/**
	 * Counts this action against the session's and the client IP's limits:
	 * RateLimit::OK, LIMITED, or UNAVAILABLE when a counter cannot be read
	 * (the shopper is told AgeCheck is unavailable, not that they tried too
	 * often).
	 *
	 * @param string $action  ACTION_*.
	 * @param string $subject Session::rate_subject().
	 */
	private static function within_limits( string $action, string $subject ): string {
		/**
		 * Hourly limits for the shopper's start and refresh calls.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, int> $limits start_session, start_ip, refresh_session, refresh_ip.
		 */
		$limits = apply_filters( 'navexa_agecheck_rate_limits', self::LIMITS );
		$limits = is_array( $limits ) ? array_merge( self::LIMITS, $limits ) : self::LIMITS;
		$ip     = RateLimit::client_ip();
		$result = RateLimit::check( $action . '_session', $subject, (int) $limits[ $action . '_session' ], HOUR_IN_SECONDS );
		if ( RateLimit::OK === $result ) {
			$result = RateLimit::check( $action . '_ip', '' === $ip ? 'unknown' : $ip, (int) $limits[ $action . '_ip' ], HOUR_IN_SECONDS );
		}
		if ( RateLimit::LIMITED === $result ) {
			Client::log( 'warning', 'agecheck ' . $action . ' rate limited' );
		}
		return $result;
	}

	/**
	 * Whether a verification is bound to an order already.
	 *
	 * @param array<string, mixed> $verification Verification.
	 */
	private static function is_bound( array $verification ): bool {
		return isset( $verification['orderExternalId'] ) && is_string( $verification['orderExternalId'] ) && '' !== $verification['orderExternalId'];
	}

	/**
	 * A verification's status name ('' when absent).
	 *
	 * @param array<string, mixed> $verification Verification.
	 */
	private static function status_of( array $verification ): string {
		return isset( $verification['status'] ) && is_string( $verification['status'] ) ? $verification['status'] : '';
	}

	/**
	 * Whether $url is a hosted page link the loader can open: an absolute
	 * https URL (http only for localhost and the configured API host, i.e.
	 * the mock engine) whose path is /v/{token}.
	 *
	 * @param string $url Candidate.
	 */
	public static function is_hosted_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || empty( $parts['path'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'https' !== $scheme ) {
			$api_host = wp_parse_url( Client::base_url(), PHP_URL_HOST );
			if ( 'http' !== $scheme || ( 'localhost' !== $parts['host'] && $api_host !== $parts['host'] ) ) {
				return false;
			}
		}
		return 1 === preg_match( '#^/v/[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$#', (string) $parts['path'] );
	}

	/**
	 * A browser answer.
	 *
	 * @param string $status  STATUS_*.
	 * @param string $message Copy, or ''.
	 * @return array<string, mixed>
	 */
	private static function reply( string $status, string $message = '' ): array {
		$reply = array(
			'v'      => self::VERSION,
			'status' => $status,
		);
		if ( '' !== $message ) {
			$reply['message'] = $message;
		}
		return $reply;
	}

	/**
	 * Shopper-facing copy when the Turnstile check did not pass.
	 */
	public static function turnstile_message(): string {
		return __( 'The security check did not pass. Complete it, then select Verify age again.', 'navexa-agecheck-for-woocommerce' );
	}

	/**
	 * The fail-closed answer.
	 *
	 * @return array<string, mixed>
	 */
	private static function unavailable(): array {
		return self::reply( self::STATUS_UNAVAILABLE, __( 'Age verification is temporarily unavailable. Please try again in a few minutes.', 'navexa-agecheck-for-woocommerce' ) );
	}
}
