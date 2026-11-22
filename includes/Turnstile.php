<?php
/**
 * Optional Cloudflare Turnstile check on the start endpoint.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * When the merchant turns Turnstile on (Options::TURNSTILE, off by default),
 * a shopper's start (Start::ACTION_START) must carry a Turnstile token that
 * Cloudflare's siteverify API accepts, or no verification is created. It is a
 * bot brake on the one shopper-triggered call that costs the merchant
 * something; the rate limits still apply.
 *
 * Fail closed: Turnstile on with no site key or secret, a missing or
 * oversized token, a transport error, a non-200 answer, success false, or an
 * action other than ACTION refuses the start. Refresh is never checked (it
 * creates nothing).
 *
 * The token and the secret are never logged; Cloudflare's error codes are.
 * The siteverify URL is a constant, never a setting.
 */
final class Turnstile {

	const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Cloudflare's server-side siteverify API, not offloaded content (readme: External services).

	/**
	 * The Turnstile JavaScript API (explicit rendering: the checkout glue
	 * renders the widget when the shopper selects Verify age).
	 */
	const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Cloudflare requires its own api.js (no self-hosting); opt-in, off by default (readme: External services).

	/**
	 * The action the widget is rendered with, checked on the answer.
	 */
	const ACTION = 'navexa_agecheck_start';

	/**
	 * Cloudflare's longest token is 2048 characters.
	 */
	const MAX_TOKEN = 2048;

	const TIMEOUT = 8;

	const OK          = 'ok';
	const FAILED      = 'failed';
	const UNAVAILABLE = 'unavailable';

	/**
	 * Whether starts need a token (the merchant turned Turnstile on).
	 */
	public static function enabled(): bool {
		return Options::turnstile_enabled();
	}

	/**
	 * Whether Turnstile is on and has both keys.
	 */
	public static function configured(): bool {
		return self::enabled() && '' !== Options::turnstile_site_key() && '' !== Options::turnstile_secret();
	}

	/**
	 * Checks a shopper's token: OK, FAILED (no token, or Cloudflare refused
	 * it) or UNAVAILABLE (Turnstile is on without its keys, or Cloudflare
	 * could not be asked). OK without a call when Turnstile is off.
	 *
	 * @param mixed  $token     The token the browser sent with the start.
	 * @param string $remote_ip The shopper's IP address, or ''.
	 */
	public static function verify( $token, string $remote_ip = '' ): string {
		if ( ! self::enabled() ) {
			return self::OK;
		}
		$secret = Options::turnstile_secret();
		if ( '' === $secret || '' === Options::turnstile_site_key() ) {
			Client::log( 'error', 'agecheck turnstile refused reason=not_configured' );
			return self::UNAVAILABLE;
		}
		if ( ! is_string( $token ) || '' === $token || strlen( $token ) > self::MAX_TOKEN ) {
			Client::log( 'warning', 'agecheck turnstile refused reason=no_token' );
			return self::FAILED;
		}
		$body = array(
			'secret'   => $secret,
			'response' => $token,
		);
		if ( '' !== $remote_ip ) {
			$body['remoteip'] = $remote_ip;
		}
		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'body'        => $body,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			Client::log( 'error', 'agecheck turnstile unavailable' );
			return self::UNAVAILABLE;
		}
		$answer = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $answer ) ) {
			Client::log( 'error', 'agecheck turnstile unavailable reason=bad_answer' );
			return self::UNAVAILABLE;
		}
		$action = isset( $answer['action'] ) && is_string( $answer['action'] ) ? $answer['action'] : '';
		if ( true === ( $answer['success'] ?? null ) && ( '' === $action || self::ACTION === $action ) ) {
			return self::OK;
		}
		$codes = isset( $answer['error-codes'] ) && is_array( $answer['error-codes'] ) ? array_map( 'sanitize_key', array_filter( $answer['error-codes'], 'is_string' ) ) : array();
		if ( '' !== $action && self::ACTION !== $action ) {
			$codes[] = 'action_mismatch';
		}
		Client::log( 'warning', 'agecheck turnstile refused codes=' . implode( ',', $codes ) );
		return self::FAILED;
	}

	/**
	 * What the checkout scripts need, or null when Turnstile is off.
	 *
	 * @return array{siteKey: string, action: string}|null
	 */
	public static function script_data(): ?array {
		if ( ! self::enabled() ) {
			return null;
		}
		return array(
			'siteKey' => Options::turnstile_site_key(),
			'action'  => self::ACTION,
		);
	}
}
