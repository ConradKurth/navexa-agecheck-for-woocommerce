<?php
/**
 * WooCommerce > Settings > AgeCheck.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * The AgeCheck settings tab: the two API keys, the checkout mode, and a
 * connection status read from GetIntegrationInfo.
 *
 * API keys use a custom field type, never WooCommerce's "password" type:
 * that type prints the stored value into the page, and WooCommerce's REST
 * settings API exposes every field of a standard type. The key field is
 * always rendered empty with the masked key beside it; submitting it blank
 * keeps the saved key. Keys are stored with autoload off.
 *
 * Minimum age, ID-only mode and branding are AgeCheck org settings: this tab
 * shows the minimum age and links to the Navexa dashboard instead of copying
 * them.
 *
 * Loaded only from the woocommerce_get_settings_pages filter, because
 * WC_Settings_Page exists only on WooCommerce's settings screens.
 */
final class Settings extends \WC_Settings_Page {

	const FIELD_SECRET = 'navexa_agecheck_secret';
	const FIELD_STATUS = 'navexa_agecheck_status';

	/**
	 * Where merchants manage keys, allowed origins, minimum age and branding.
	 */
	const DASHBOARD_URL = 'https://tax.navexa.co';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = Plugin::SETTINGS_TAB;
		$this->label = __( 'AgeCheck', 'navexa-agecheck-for-woocommerce' );
		parent::__construct();

		add_action( 'woocommerce_admin_field_' . self::FIELD_SECRET, array( $this, 'output_secret_field' ) );
		add_action( 'woocommerce_admin_field_' . self::FIELD_STATUS, array( $this, 'output_status_field' ) );
		foreach ( array( Options::START_KEY, Options::FULFILMENT_KEY ) as $option ) {
			add_filter( 'woocommerce_admin_settings_sanitize_option_' . $option, array( $this, 'sanitize_secret' ), 10, 3 );
		}
	}

	/**
	 * Fields of the tab's only section.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_settings_for_default_section() {
		return array(
			array(
				'id'    => 'navexa_agecheck_section',
				'type'  => 'title',
				'title' => __( 'Navexa AgeCheck', 'navexa-agecheck-for-woocommerce' ),
				'desc'  => __( 'Age verification for age-restricted orders. AgeCheck verifies orders shipping to US addresses only: an age-restricted order shipping anywhere else is refused.', 'navexa-agecheck-for-woocommerce' ),
			),
			array(
				'id'        => 'navexa_agecheck_status',
				'type'      => self::FIELD_STATUS,
				'title'     => __( 'Connection', 'navexa-agecheck-for-woocommerce' ),
				'is_option' => false,
			),
			array(
				'id'       => Options::START_KEY,
				'type'     => self::FIELD_SECRET,
				'title'    => __( 'Start key', 'navexa-agecheck-for-woocommerce' ),
				'desc'     => __( 'An API key with the agecheck:verify scope. Used only when a shopper starts or refreshes a check, so a flood of starts cannot slow down order fulfilment.', 'navexa-agecheck-for-woocommerce' ),
				'autoload' => false,
			),
			array(
				'id'       => Options::FULFILMENT_KEY,
				'type'     => self::FIELD_SECRET,
				'title'    => __( 'Fulfilment key', 'navexa-agecheck-for-woocommerce' ),
				'desc'     => __( 'A second API key with the agecheck:verify and agecheck:clearance scopes. Used to link orders to checks and to release held orders.', 'navexa-agecheck-for-woocommerce' ),
				'autoload' => false,
			),
			array(
				'id'      => Options::MODE,
				'type'    => 'select',
				'title'   => __( 'When shoppers verify', 'navexa-agecheck-for-woocommerce' ),
				'desc'    => __( 'In both modes, a paid age-restricted order that has not passed is held, never released for shipping.', 'navexa-agecheck-for-woocommerce' ),
				'default' => Options::DEFAULT_MODE,
				'options' => array(
					Options::MODE_PRE_PAYMENT     => __( 'Before payment: the shopper verifies at checkout', 'navexa-agecheck-for-woocommerce' ),
					Options::MODE_ACCEPT_AND_HOLD => __( 'After payment: the order is held until the shopper verifies', 'navexa-agecheck-for-woocommerce' ),
				),
			),
			array(
				'id'   => 'navexa_agecheck_section',
				'type' => 'sectionend',
			),
		);
	}

	/**
	 * Saves a key field. Returns null in every case so WooCommerce does not
	 * save the value itself; the key is written here with autoload off.
	 *
	 * Blank keeps the saved key; the "Remove" checkbox deletes it; anything
	 * that does not look like a key is refused with an error.
	 *
	 * @param mixed $value     Value after wc_clean().
	 * @param mixed $option    Field definition.
	 * @param mixed $raw_value Unslashed posted value.
	 * @return mixed
	 */
	public function sanitize_secret( $value, $option, $raw_value ) {
		$id = is_array( $option ) && isset( $option['id'] ) ? (string) $option['id'] : '';
		if ( Options::START_KEY !== $id && Options::FULFILMENT_KEY !== $id ) {
			return $value;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC_Admin_Settings::save() checked the woocommerce-settings nonce.
		$clear = isset( $_POST[ $id . '_clear' ] ) && '1' === sanitize_text_field( wp_unslash( $_POST[ $id . '_clear' ] ) );
		$raw   = is_string( $raw_value ) ? trim( $raw_value ) : '';
		if ( '' === $raw && ! $clear ) {
			return null;
		}
		if ( '' !== $raw && ! Options::looks_like_api_key( $raw ) ) {
			\WC_Admin_Settings::add_error( __( 'That does not look like an AgeCheck API key (txk_…). The saved key was kept.', 'navexa-agecheck-for-woocommerce' ) );
			return null;
		}
		Mode::flush();
		if ( '' === $raw ) {
			delete_option( $id );
		} else {
			update_option( $id, $raw, false );
		}
		return null;
	}

	/**
	 * Renders a key field: an empty password input plus the masked saved key.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	public function output_secret_field( $field ): void {
		$id    = isset( $field['id'] ) ? (string) $field['id'] : '';
		$saved = get_option( $id, '' );
		$saved = is_string( $saved ) ? trim( $saved ) : '';
		$title = isset( $field['title'] ) ? (string) $field['title'] : '';
		$desc  = isset( $field['desc'] ) ? (string) $field['desc'] : '';

		echo '<tr valign="top"><th scope="row" class="titledesc">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</label>';
		echo '</th><td class="forminp forminp-password">';
		printf(
			'<input name="%1$s" id="%1$s" type="password" value="" class="regular-text" autocomplete="off" spellcheck="false" placeholder="%2$s" />',
			esc_attr( $id ),
			esc_attr( '' === $saved ? 'txk_…' : __( 'Leave blank to keep the saved key', 'navexa-agecheck-for-woocommerce' ) )
		);
		echo '<p class="description">';
		if ( '' === $saved ) {
			esc_html_e( 'No key saved.', 'navexa-agecheck-for-woocommerce' );
		} else {
			printf(
				/* translators: %s: the first characters of the saved API key. */
				esc_html__( 'Saved key: %s', 'navexa-agecheck-for-woocommerce' ),
				'<code>' . esc_html( Options::mask_api_key( $saved ) ) . '</code>'
			);
		}
		echo ' ' . esc_html( $desc ) . '</p>';
		if ( '' !== $saved ) {
			printf(
				'<p><label><input type="checkbox" name="%1$s" value="1" /> %2$s</label></p>',
				esc_attr( $id . '_clear' ),
				esc_html__( 'Remove the saved key', 'navexa-agecheck-for-woocommerce' )
			);
		}
		echo '</td></tr>';
	}

	/**
	 * Renders the connection status: each key's mode and scopes, the store
	 * origin check, the minimum age and the US-only note.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	public function output_status_field( $field ): void {
		$title = isset( $field['title'] ) ? (string) $field['title'] : '';
		$lines = $this->status_lines();

		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html( $title ) . '</th><td class="forminp"><ul style="margin-top:0">';
		foreach ( $lines as $line ) {
			$color = 'ok' === $line['level'] ? '#008a20' : ( 'warning' === $line['level'] ? '#996800' : '#d63638' );
			printf(
				'<li><span style="color:%1$s;font-weight:600">%2$s</span> %3$s</li>',
				esc_attr( $color ),
				esc_html( 'ok' === $line['level'] ? '✓' : '!' ),
				esc_html( $line['text'] )
			);
		}
		echo '</ul><p class="description">';
		printf(
			/* translators: %s: link to the Navexa dashboard. */
			esc_html__( 'Create keys, add allowed origins and set the minimum age in the %s.', 'navexa-agecheck-for-woocommerce' ),
			'<a href="' . esc_url( self::DASHBOARD_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Navexa dashboard', 'navexa-agecheck-for-woocommerce' ) . '</a>'
		);
		echo '</p></td></tr>';
	}

	/**
	 * The connection status as lines of {level: ok|warning|error, text}.
	 * Always asks AgeCheck instead of reading the cache (and refreshes it), so
	 * a key, origin or minimum age fixed in the Navexa dashboard shows on the
	 * next load.
	 *
	 * @return array<int, array{level: string, text: string}>
	 */
	public function status_lines(): array {
		$lines = array();
		if ( Install::is_paused() ) {
			$lines[] = self::line( 'error', __( 'Paused: this site\'s address changed. Use "Confirm this site" in the notice above.', 'navexa-agecheck-for-woocommerce' ) );
		}

		$infos = array();
		$kinds = array(
			Client::KEY_START      => __( 'Start key', 'navexa-agecheck-for-woocommerce' ),
			Client::KEY_FULFILMENT => __( 'Fulfilment key', 'navexa-agecheck-for-woocommerce' ),
		);
		foreach ( $kinds as $kind => $label ) {
			$result = Mode::lookup( $kind, null, true );
			$info   = $result['info'];
			if ( null === $info ) {
				$lines[] = self::line( 'error', $label . ': ' . self::error_text( $result['error'] ) );
				continue;
			}
			$infos[ $kind ] = $info;
			$mode           = $info->livemode
				? __( 'live', 'navexa-agecheck-for-woocommerce' )
				: __( 'TEST MODE (simulator only)', 'navexa-agecheck-for-woocommerce' );
			$missing        = $info->missing_scopes( $kind );
			if ( empty( $missing ) ) {
				$lines[] = self::line( $info->livemode ? 'ok' : 'warning', $label . ': ' . $mode );
			} else {
				$lines[] = self::line(
					'error',
					$label . ': ' . $mode . '. ' . sprintf(
						/* translators: %s: comma-separated API key scopes. */
						__( 'Missing scope: %s.', 'navexa-agecheck-for-woocommerce' ),
						implode( ', ', $missing )
					)
				);
			}
		}

		// The two keys exist so that shopper-triggered starts have their own rate
		// limit: one key in both fields shares it with order fulfilment.
		$start_key = Options::api_key( Client::KEY_START );
		if ( '' !== $start_key && hash_equals( $start_key, Options::api_key( Client::KEY_FULFILMENT ) ) ) {
			$lines[] = self::line( 'error', __( 'The start key and the fulfilment key are the same key. Create a separate start key with only the agecheck:verify scope, or a burst of checkout starts can use up the rate limit that releases held orders.', 'navexa-agecheck-for-woocommerce' ) );
		} elseif ( isset( $infos[ Client::KEY_START ] ) && in_array( IntegrationInfo::SCOPE_CLEARANCE, $infos[ Client::KEY_START ]->scopes, true ) ) {
			$lines[] = self::line( 'warning', __( 'The start key also has the agecheck:clearance scope. It needs only agecheck:verify.', 'navexa-agecheck-for-woocommerce' ) );
		}

		if ( isset( $infos[ Client::KEY_START ], $infos[ Client::KEY_FULFILMENT ] ) ) {
			$start      = $infos[ Client::KEY_START ];
			$fulfilment = $infos[ Client::KEY_FULFILMENT ];
			if ( $start->livemode !== $fulfilment->livemode ) {
				$lines[] = self::line( 'error', __( 'One key is live and the other is a test key. Use two live keys or two test keys from the same Navexa account.', 'navexa-agecheck-for-woocommerce' ) );
			}
			// GetIntegrationInfo carries no account id; the account's display name
			// is the only hint. Two different names mean two accounts, and orders
			// started under one cannot be linked or released with the other.
			if ( '' !== $start->display_name && '' !== $fulfilment->display_name && $start->display_name !== $fulfilment->display_name ) {
				$lines[] = self::line(
					'error',
					sprintf(
						/* translators: 1: account display name of the start key, 2: account display name of the fulfilment key. */
						__( 'The keys belong to different Navexa accounts (%1$s and %2$s). Use two keys from the same account.', 'navexa-agecheck-for-woocommerce' ),
						$start->display_name,
						$fulfilment->display_name
					)
				);
			}
		}

		$info = isset( $infos[ Client::KEY_FULFILMENT ] ) ? $infos[ Client::KEY_FULFILMENT ] : ( isset( $infos[ Client::KEY_START ] ) ? $infos[ Client::KEY_START ] : null );
		if ( null !== $info ) {
			$origin = Mode::store_origin();
			if ( $info->origin_allowed ) {
				/* translators: %s: the store origin, e.g. https://shop.example. */
				$lines[] = self::line( 'ok', sprintf( __( '%s is an allowed origin.', 'navexa-agecheck-for-woocommerce' ), $origin ) );
			} else {
				/* translators: %s: the store origin, e.g. https://shop.example. */
				$lines[] = self::line( 'error', sprintf( __( 'Add %s to the allowed origins in the Navexa dashboard, or shoppers cannot verify.', 'navexa-agecheck-for-woocommerce' ), $origin ) );
			}
			if ( null !== $info->minimum_age ) {
				/* translators: %d: minimum age, e.g. 21. */
				$lines[] = self::line( 'ok', sprintf( __( 'Minimum age: %d (set in the Navexa dashboard).', 'navexa-agecheck-for-woocommerce' ), $info->minimum_age ) );
			}
		}

		if ( self::ships_outside_us() ) {
			$lines[] = self::line( 'warning', __( 'Your store ships outside the US. AgeCheck verifies US addresses only, so age-restricted orders shipping elsewhere will be refused.', 'navexa-agecheck-for-woocommerce' ) );
		}
		return $lines;
	}

	/**
	 * Merchant-facing copy for a Mode::lookup() error.
	 *
	 * @param string $error 'no_key', a Connect code or a ConnectError::REASON_* constant.
	 */
	public static function error_text( string $error ): string {
		switch ( $error ) {
			case 'no_key':
				return __( 'not set.', 'navexa-agecheck-for-woocommerce' );
			case ConnectError::REASON_INSTALL_UNCONFIRMED:
				return __( 'paused until this site is confirmed.', 'navexa-agecheck-for-woocommerce' );
			case 'unauthenticated':
				return __( 'AgeCheck refused this key. Check it was copied in full and has not been revoked.', 'navexa-agecheck-for-woocommerce' );
			case 'permission_denied':
				return __( 'this key is not allowed to use AgeCheck. Check its scopes in the Navexa dashboard.', 'navexa-agecheck-for-woocommerce' );
			case 'unimplemented':
				return __( 'AgeCheck does not offer the connection check this plugin needs yet. Age checks stay off until it does.', 'navexa-agecheck-for-woocommerce' );
			default:
				/* translators: %s: an error code, e.g. unavailable. */
				return sprintf( __( 'AgeCheck could not be reached (%s). Age checks fail closed until it answers.', 'navexa-agecheck-for-woocommerce' ), $error );
		}
	}

	/**
	 * Whether WooCommerce is set to ship to any country other than the US.
	 */
	private static function ships_outside_us(): bool {
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( ! is_object( $wc ) || ! is_object( $wc->countries ) ) {
			return false;
		}
		$countries = $wc->countries->get_shipping_countries();
		if ( ! is_array( $countries ) ) {
			return false;
		}
		foreach ( array_keys( $countries ) as $code ) {
			if ( 'US' !== $code ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One status line.
	 *
	 * @param string $level ok, warning or error.
	 * @param string $text  Text.
	 * @return array{level: string, text: string}
	 */
	private static function line( string $level, string $text ): array {
		return array(
			'level' => $level,
			'text'  => $text,
		);
	}
}
