<?php
/**
 * One SendVerificationLink answer.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * What a LinkSender learned: a Links::STATE_* (or Links::LIMITED, the
 * per-verification send budget), and for a sent link its id and expiry.
 */
final class LinkResult {

	/**
	 * Links::STATE_* or Links::LIMITED.
	 *
	 * @var string
	 */
	public $state;

	/**
	 * The link's id (acl_...), or ''.
	 *
	 * @var string
	 */
	public $link_id = '';

	/**
	 * Unix time the link expires, or 0.
	 *
	 * @var int
	 */
	public $expires_at = 0;

	/**
	 * Constructor.
	 *
	 * @param string $state      Links::STATE_* or Links::LIMITED.
	 * @param string $link_id    Link id, or ''.
	 * @param int    $expires_at Unix time, or 0.
	 */
	public function __construct( string $state, string $link_id = '', int $expires_at = 0 ) {
		$this->state      = $state;
		$this->link_id    = 1 === preg_match( '/^acl_[A-Za-z0-9_-]{1,60}$/', $link_id ) ? $link_id : '';
		$this->expires_at = max( 0, $expires_at );
	}
}
