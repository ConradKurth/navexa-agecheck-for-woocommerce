<?php
/**
 * An AgeCheck call that did not succeed.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * What AgeCheck answered, or what the transport failed with. Callers in
 * request paths treat every ConnectError as a refusal (fail closed); Action
 * Scheduler jobs may retry the retryable ones after retry_after().
 *
 * The exception never carries the request: it can hold shopper identity.
 */
final class ConnectError extends \RuntimeException {

	/**
	 * Connect error codes (https://connectrpc.com/docs/protocol/#error-codes).
	 */
	const CODES = array(
		'canceled',
		'unknown',
		'invalid_argument',
		'deadline_exceeded',
		'not_found',
		'already_exists',
		'permission_denied',
		'resource_exhausted',
		'failed_precondition',
		'aborted',
		'out_of_range',
		'unimplemented',
		'internal',
		'unavailable',
		'data_loss',
		'unauthenticated',
	);

	/**
	 * Codes a background job may retry with the same idempotency key. ABORTED
	 * means "the same key is still in flight": a retry replays the first answer.
	 */
	const RETRYABLE = array( 'unavailable', 'aborted', 'resource_exhausted', 'deadline_exceeded' );

	/**
	 * Local reason: the caller passed something that is not an RPC name (a bug).
	 */
	const REASON_INVALID_RPC = 'invalid_rpc';

	/**
	 * Local reason: the site is paused until an administrator confirms it.
	 */
	const REASON_INSTALL_UNCONFIRMED = 'install_unconfirmed';

	/**
	 * Local reason: no API key is configured for the key kind.
	 */
	const REASON_NO_API_KEY = 'no_api_key';

	/**
	 * Local reason: the request could not be encoded as JSON.
	 */
	const REASON_UNENCODABLE_REQUEST = 'unencodable_request';

	/**
	 * Local reason: a 2xx answer whose body is not a JSON object.
	 */
	const REASON_UNREADABLE_RESPONSE = 'unreadable_response';

	/**
	 * RPC name, e.g. GetVerification.
	 *
	 * @var string
	 */
	private $rpc;

	/**
	 * Connect error code.
	 *
	 * @var string
	 */
	private $connect_code;

	/**
	 * HTTP status, or 0 when no response arrived.
	 *
	 * @var int
	 */
	private $http_status;

	/**
	 * Seconds from a Retry-After header, or null.
	 *
	 * @var int|null
	 */
	private $retry_after;

	/**
	 * The engine's message, for refusal copy only. Never logged.
	 *
	 * @var string
	 */
	private $server_message;

	/**
	 * Why the plugin refused before or after the network call ('' when AgeCheck answered).
	 *
	 * @var string
	 */
	private $local_reason;

	/**
	 * Constructor.
	 *
	 * @param string   $rpc            RPC name.
	 * @param string   $connect_code   Connect error code; anything unknown becomes 'unknown'.
	 * @param int      $http_status    HTTP status, 0 when no response arrived.
	 * @param int|null $retry_after    Seconds from Retry-After, or null.
	 * @param string   $server_message The engine's error message.
	 * @param string   $local_reason   A REASON_* constant, or ''.
	 */
	public function __construct( string $rpc, string $connect_code, int $http_status = 0, ?int $retry_after = null, string $server_message = '', string $local_reason = '' ) {
		$this->rpc            = $rpc;
		$this->connect_code   = in_array( $connect_code, self::CODES, true ) ? $connect_code : 'unknown';
		$this->http_status    = $http_status;
		$this->retry_after    = $retry_after;
		$this->server_message = $server_message;
		$this->local_reason   = $local_reason;
		$suffix               = '' === $local_reason ? '' : ' (' . $local_reason . ')';
		parent::__construct( 'agecheck ' . $rpc . ': ' . $this->connect_code . $suffix );
	}

	/**
	 * RPC name.
	 */
	public function rpc(): string {
		return $this->rpc;
	}

	/**
	 * Connect error code.
	 */
	public function connect_code(): string {
		return $this->connect_code;
	}

	/**
	 * HTTP status, or 0 when no response arrived.
	 */
	public function http_status(): int {
		return $this->http_status;
	}

	/**
	 * Seconds from a Retry-After header, or null.
	 */
	public function retry_after(): ?int {
		return $this->retry_after;
	}

	/**
	 * The engine's message (e.g. "AgeCheck verifies US addresses only"). For
	 * refusal copy only; never log it.
	 */
	public function server_message(): string {
		return $this->server_message;
	}

	/**
	 * A REASON_* constant when the plugin refused locally, else ''.
	 */
	public function local_reason(): string {
		return $this->local_reason;
	}

	/**
	 * Whether a background job may retry the call later with the same body.
	 * Local refusals are never retryable: they need an administrator.
	 */
	public function is_retryable(): bool {
		return '' === $this->local_reason && in_array( $this->connect_code, self::RETRYABLE, true );
	}
}
