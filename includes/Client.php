<?php
/**
 * AgeCheck API client.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Connect unary JSON over the WordPress HTTP API:
 * POST {base}/agecheck.v1.VerificationService/{Rpc} with a Bearer API key.
 *
 * One attempt per call, never a sleep: request paths (checkout, start) fail
 * closed on any error, and Action Scheduler jobs reschedule themselves after
 * ConnectError::retry_after(). Logs carry the RPC, key kind, code, HTTP status
 * and timing only, never a key, a request body or the engine's message.
 *
 * The plugin uses two keys (see Options): the start key (agecheck:verify)
 * for the shopper-triggered start and refresh calls, and the fulfilment key
 * (agecheck:verify + agecheck:clearance) for everything else, so a flood of
 * starts cannot drain the fulfilment key's rate limit.
 */
final class Client {

	const SERVICE          = 'agecheck.v1.VerificationService';
	const DEFAULT_BASE_URL = 'https://verify.navexa.co';
	const TIMEOUT_SECONDS  = 8;
	const LOG_SOURCE       = 'navexa-agecheck';

	const KEY_START      = 'start';
	const KEY_FULFILMENT = 'fulfilment';

	/**
	 * Longest Retry-After the client reports, in seconds.
	 */
	const MAX_RETRY_AFTER = 86400;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * KEY_START or KEY_FULFILMENT, for logs.
	 *
	 * @var string
	 */
	private $key_kind;

	/**
	 * Base URL without a trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Constructor.
	 *
	 * @param string      $api_key  API key ('' refuses every call).
	 * @param string      $key_kind KEY_START or KEY_FULFILMENT.
	 * @param string|null $base_url Base URL; null for base_url().
	 */
	public function __construct( string $api_key, string $key_kind, ?string $base_url = null ) {
		$this->api_key  = trim( $api_key );
		$this->key_kind = $key_kind;
		$this->base_url = rtrim( null === $base_url ? self::base_url() : $base_url, '/' );
	}

	/**
	 * A client for the stored key of $key_kind.
	 *
	 * @param string $key_kind KEY_START or KEY_FULFILMENT.
	 */
	public static function for_key( string $key_kind ): self {
		return new self( Options::api_key( $key_kind ), $key_kind );
	}

	/**
	 * The AgeCheck API base URL: NAVEXA_AGECHECK_API_URL from wp-config.php
	 * (tests and the mock server) or https://verify.navexa.co. It is never a
	 * setting, so the admin UI cannot point the keys at another host.
	 */
	public static function base_url(): string {
		$configured = defined( 'NAVEXA_AGECHECK_API_URL' ) ? constant( 'NAVEXA_AGECHECK_API_URL' ) : null;
		if ( is_string( $configured ) ) {
			$url    = rtrim( $configured, '/' );
			$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
			if ( 'https' === $scheme || 'http' === $scheme ) {
				return $url;
			}
		}
		return self::DEFAULT_BASE_URL;
	}

	/**
	 * The key kind this client was built for.
	 */
	public function key_kind(): string {
		return $this->key_kind;
	}

	/**
	 * Whether this client has a key at all.
	 */
	public function has_key(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * A short, non-reversible id of this client's key and base URL, for cache
	 * names. Never the key itself.
	 */
	public function key_fingerprint(): string {
		return substr( hash( 'sha256', 'navexa-agecheck-key-v1|' . $this->base_url . '|' . $this->api_key ), 0, 32 );
	}

	/**
	 * Calls one RPC and returns the decoded response object.
	 *
	 * @param string               $rpc     RPC name, e.g. GetVerification.
	 * @param array<string, mixed> $request Request message as an associative array (proto3 JSON names).
	 * @return array<string, mixed> Decoded response; [] for an empty message.
	 * @throws ConnectError On any failure: a bad RPC name, paused install, no key, transport error or a Connect error.
	 */
	public function call( string $rpc, array $request = array() ): array {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- ConnectError messages hold an RPC name and codes, and are never output.
		if ( ! preg_match( '/^[A-Z][A-Za-z0-9]{0,63}$/', $rpc ) ) {
			throw new ConnectError( 'invalid', 'invalid_argument', 0, null, '', ConnectError::REASON_INVALID_RPC );
		}
		if ( Install::is_paused() ) {
			throw $this->logged( new ConnectError( $rpc, 'failed_precondition', 0, null, '', ConnectError::REASON_INSTALL_UNCONFIRMED ), 0 );
		}
		if ( ! $this->has_key() ) {
			throw $this->logged( new ConnectError( $rpc, 'unauthenticated', 0, null, '', ConnectError::REASON_NO_API_KEY ), 0 );
		}
		$body = wp_json_encode( empty( $request ) ? new \stdClass() : $request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $body ) ) {
			throw $this->logged( new ConnectError( $rpc, 'invalid_argument', 0, null, '', ConnectError::REASON_UNENCODABLE_REQUEST ), 0 );
		}

		$started    = microtime( true );
		$response   = wp_remote_post(
			$this->base_url . '/' . self::SERVICE . '/' . $rpc,
			array(
				'timeout'     => self::TIMEOUT_SECONDS,
				// Never follow a redirect: it would resend the Bearer key elsewhere.
				'redirection' => 0,
				'user-agent'  => 'navexa-agecheck-for-woocommerce/' . NAVEXA_AGECHECK_VERSION,
				'headers'     => array(
					'Content-Type'             => 'application/json',
					'Accept'                   => 'application/json',
					'Connect-Protocol-Version' => '1',
					'Connect-Timeout-Ms'       => (string) ( self::TIMEOUT_SECONDS * 1000 ),
					'Authorization'            => 'Bearer ' . $this->api_key,
				),
				'body'        => $body,
				'data_format' => 'body',
			)
		);
		$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$code = self::is_timeout( $response ) ? 'deadline_exceeded' : 'unavailable';
			throw $this->logged( new ConnectError( $rpc, $code ), $elapsed_ms );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$text    = (string) wp_remote_retrieve_body( $response );
		$decoded = '' === $text ? array() : json_decode( $text, true );

		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $decoded ) ) {
				throw $this->logged( new ConnectError( $rpc, 'internal', $status, null, '', ConnectError::REASON_UNREADABLE_RESPONSE ), $elapsed_ms );
			}
			return $decoded;
		}

		$code    = is_array( $decoded ) && isset( $decoded['code'] ) && in_array( $decoded['code'], ConnectError::CODES, true )
			? $decoded['code']
			: self::code_from_http_status( $status );
		$message = is_array( $decoded ) && isset( $decoded['message'] ) && is_string( $decoded['message'] ) ? $decoded['message'] : '';
		$retry   = self::parse_retry_after( wp_remote_retrieve_header( $response, 'retry-after' ) );
		throw $this->logged( new ConnectError( $rpc, $code, $status, $retry, $message ), $elapsed_ms );
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Connect's HTTP-to-code mapping for an answer without a Connect error body
	 * (a proxy's 502, or connect-go's plain 404 for an RPC the engine does not
	 * serve yet).
	 *
	 * Beyond Connect's table, a bare 500 and Cloudflare's 520-526 also read as
	 * unavailable. verify.navexa.co sits behind Cloudflare, and the engine
	 * always answers its own errors with a Connect body, so these come from
	 * the edge or the ingress when the origin did not answer properly. Jobs
	 * may then retry them with the same idempotency key.
	 *
	 * @param int $status HTTP status.
	 */
	public static function code_from_http_status( int $status ): string {
		if ( $status >= 520 && $status <= 526 ) {
			return 'unavailable';
		}
		switch ( $status ) {
			case 400:
				return 'internal';
			case 401:
				return 'unauthenticated';
			case 403:
				return 'permission_denied';
			case 404:
				return 'unimplemented';
			case 429:
			case 500:
			case 502:
			case 503:
			case 504:
				return 'unavailable';
			default:
				return 'unknown';
		}
	}

	/**
	 * Seconds to wait from a Retry-After header (delta-seconds or HTTP-date),
	 * capped at MAX_RETRY_AFTER; null when absent or unreadable.
	 *
	 * @param mixed $header Header value (string, or array when repeated).
	 */
	public static function parse_retry_after( $header ): ?int {
		if ( is_array( $header ) ) {
			$header = reset( $header );
		}
		if ( ! is_string( $header ) ) {
			return null;
		}
		$header = trim( $header );
		if ( '' === $header ) {
			return null;
		}
		if ( ctype_digit( $header ) ) {
			return min( self::MAX_RETRY_AFTER, (int) $header );
		}
		$at = strtotime( $header );
		if ( false === $at ) {
			return null;
		}
		return min( self::MAX_RETRY_AFTER, max( 0, $at - time() ) );
	}

	/**
	 * Whether a transport error was a timeout.
	 *
	 * @param \WP_Error $error Transport error.
	 */
	private static function is_timeout( $error ): bool {
		$message = (string) $error->get_error_message();
		return false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'cURL error 28' );
	}

	/**
	 * Logs $error (ids and codes only) and returns it for the caller to throw.
	 *
	 * @param ConnectError $error      The failure.
	 * @param int          $elapsed_ms Call duration in milliseconds (0 when no call was made).
	 */
	private function logged( ConnectError $error, int $elapsed_ms ): ConnectError {
		$level = in_array( $error->connect_code(), array( 'unauthenticated', 'permission_denied' ), true ) ? 'error' : 'warning';
		self::log(
			$level,
			sprintf(
				'agecheck call failed rpc=%s key=%s code=%s reason=%s http=%d retry_after=%s ms=%d',
				$error->rpc(),
				$this->key_kind,
				$error->connect_code(),
				'' === $error->local_reason() ? '-' : $error->local_reason(),
				$error->http_status(),
				null === $error->retry_after() ? '-' : (string) $error->retry_after(),
				$elapsed_ms
			)
		);
		return $error;
	}

	/**
	 * Writes one line to the WooCommerce log under LOG_SOURCE. Messages must
	 * hold ids and codes only.
	 *
	 * @param string $level   PSR-3 level.
	 * @param string $message Message.
	 */
	public static function log( string $level, string $message ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}
}
