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
	const FIELD_INFO   = 'navexa_agecheck_info';

	/**
	 * Where merchants manage keys, allowed origins, minimum age and branding.
	 */
	const DASHBOARD_URL = 'https://tax.navexa.co';

	/**
	 * Terms listed per taxonomy in the rule's multiselects (saved terms are
	 * always listed too).
	 */
	const TERM_LIMIT = 500;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = Plugin::SETTINGS_TAB;
		$this->label = __( 'AgeCheck', 'navexa-agecheck-for-woocommerce' );
		parent::__construct();

		add_action( 'woocommerce_admin_field_' . self::FIELD_SECRET, array( $this, 'output_secret_field' ) );
		add_action( 'woocommerce_admin_field_' . self::FIELD_STATUS, array( $this, 'output_status_field' ) );
		add_action( 'woocommerce_admin_field_' . self::FIELD_INFO, array( $this, 'output_info_field' ) );
		foreach ( array( Options::START_KEY, Options::FULFILMENT_KEY, Options::WEBHOOK_SECRET, Options::TURNSTILE_SECRET ) as $option ) {
			add_filter( 'woocommerce_admin_settings_sanitize_option_' . $option, array( $this, 'sanitize_secret' ), 10, 3 );
		}
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Options::TURNSTILE_SITE_KEY, array( $this, 'sanitize_turnstile_site_key' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Options::ENTRY_GATE_DAYS, array( $this, 'sanitize_gate_days' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Options::ENTRY_GATE_EXIT_URL, array( $this, 'sanitize_gate_exit_url' ), 10, 3 );
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
				'id'      => Options::TRUSTED_PROXY_HEADER,
				'type'    => 'select',
				'title'   => __( 'Client IP address', 'navexa-agecheck-for-woocommerce' ),
				'desc'    => __( 'Where to read the shopper\'s IP address for the age check rate limits. Choose a header only when your store is behind that proxy or CDN: anyone can send these headers to a store that is not.', 'navexa-agecheck-for-woocommerce' ),
				'default' => '',
				'options' => array( '' => __( 'The connection (REMOTE_ADDR)', 'navexa-agecheck-for-woocommerce' ) ) + self::proxy_header_labels(),
			),
			array(
				'id'   => 'navexa_agecheck_section',
				'type' => 'sectionend',
			),
			array(
				'id'    => 'navexa_agecheck_rules_section',
				'type'  => 'title',
				'title' => __( 'Which orders need an age check', 'navexa-agecheck-for-woocommerce' ),
				'desc'  => __( 'A product\'s own "Age check" setting (product editor, General tab) overrides these rules. Local pickup orders never need one: check the buyer\'s ID at the counter. Orders paid on the "pay for order" page are accepted, then held until the shopper verifies.', 'navexa-agecheck-for-woocommerce' ),
			),
			array(
				'id'      => Options::RULE,
				'type'    => 'select',
				'title'   => __( 'Products', 'navexa-agecheck-for-woocommerce' ),
				'default' => Options::RULE_ALL,
				'options' => array(
					Options::RULE_ALL     => __( 'Every product', 'navexa-agecheck-for-woocommerce' ),
					Options::RULE_INCLUDE => __( 'Only products in the categories or tags below', 'navexa-agecheck-for-woocommerce' ),
					Options::RULE_EXCLUDE => __( 'Every product except those in the categories or tags below', 'navexa-agecheck-for-woocommerce' ),
				),
			),
			array(
				'id'      => Options::RULE_CATEGORIES,
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'title'   => __( 'Categories', 'navexa-agecheck-for-woocommerce' ),
				'desc'    => __( 'A category also covers its subcategories.', 'navexa-agecheck-for-woocommerce' ),
				'default' => array(),
				'options' => self::term_options( 'product_cat', Options::RULE_CATEGORIES ),
			),
			array(
				'id'      => Options::RULE_TAGS,
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'title'   => __( 'Tags', 'navexa-agecheck-for-woocommerce' ),
				'default' => array(),
				'options' => self::term_options( 'product_tag', Options::RULE_TAGS ),
			),
			array(
				'id'      => Options::EXEMPT_ROLES,
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'title'   => __( 'Exempt customer roles', 'navexa-agecheck-for-woocommerce' ),
				'desc'    => __( 'Orders whose customer account has one of these roles skip the age check. It is the order\'s customer that counts, not the staff member who creates the order.', 'navexa-agecheck-for-woocommerce' ),
				'default' => array(),
				'options' => self::role_options(),
			),
			array(
				'id'      => Options::DENIED_ORDERS,
				'type'    => 'select',
				'title'   => __( 'When a shopper fails', 'navexa-agecheck-for-woocommerce' ),
				'desc'    => __( 'A failed order is never shipped. Automatic refunds need a payment gateway that refunds through its API, and never happen with test keys; other orders are listed under "AgeCheck: denied, refund required" on the Orders screen.', 'navexa-agecheck-for-woocommerce' ),
				'default' => Options::DENIED_AUTO_REFUND,
				'options' => array(
					Options::DENIED_AUTO_REFUND => __( 'Refund through the payment gateway when it can, otherwise list for a manual refund', 'navexa-agecheck-for-woocommerce' ),
					Options::DENIED_MANUAL      => __( 'Always list for a manual refund', 'navexa-agecheck-for-woocommerce' ),
				),
			),
			array(
				'id'   => 'navexa_agecheck_rules_section',
				'type' => 'sectionend',
			),
			array(
				'id'    => 'navexa_agecheck_webhook_section',
				'type'  => 'title',
				'title' => __( 'Webhook', 'navexa-agecheck-for-woocommerce' ),
				'desc'  => __( 'Optional but recommended: AgeCheck tells the store the moment a shopper passes, instead of the store asking every 15 minutes. In the Navexa dashboard, add a webhook endpoint with the URL below for the verification.passed, verification.failed and verification.hold_stale events, and paste its signing secret here.', 'navexa-agecheck-for-woocommerce' ),
			),
			array(
				'id'        => 'navexa_agecheck_webhook_url',
				'type'      => self::FIELD_INFO,
				'title'     => __( 'Webhook URL', 'navexa-agecheck-for-woocommerce' ),
				'is_option' => false,
			),
			array(
				'id'       => Options::WEBHOOK_SECRET,
				'type'     => self::FIELD_SECRET,
				'title'    => __( 'Signing secret', 'navexa-agecheck-for-woocommerce' ),
				'desc'     => __( 'Shown once when you create the endpoint in the Navexa dashboard.', 'navexa-agecheck-for-woocommerce' ),
				'autoload' => false,
			),
			array(
				'id'   => 'navexa_agecheck_webhook_section',
				'type' => 'sectionend',
			),
			array(
				'id'    => 'navexa_agecheck_protection_section',
				'type'  => 'title',
				'title' => __( 'Checkout protection', 'navexa-agecheck-for-woocommerce' ),
			),
			array(
				'id'       => Options::HIDE_EXPRESS,
				'type'     => 'checkbox',
				'title'    => __( 'Express payment buttons', 'navexa-agecheck-for-woocommerce' ),
				'desc'     => __( 'Hide express checkout buttons on age-restricted carts and products', 'navexa-agecheck-for-woocommerce' ),
				'desc_tip' => __( 'Apple Pay, Google Pay, Link, Amazon Pay, WooPay and PayPal buttons from WooPayments, WooCommerce Stripe Gateway and WooCommerce PayPal Payments skip the checkout form, so the shopper never sees Verify age. Hidden, the shopper pays through the checkout form instead. An express order that still gets through is refused before payment (Before payment mode) or held after payment until the age check clears.', 'navexa-agecheck-for-woocommerce' ),
				'default'  => 'yes',
			),
			array(
				'id'       => Options::TURNSTILE,
				'type'     => 'checkbox',
				'title'    => __( 'Cloudflare Turnstile', 'navexa-agecheck-for-woocommerce' ),
				'desc'     => __( 'Ask for a Turnstile security check before a shopper\'s age check starts', 'navexa-agecheck-for-woocommerce' ),
				'desc_tip' => __( 'Keeps bots from starting age checks. The checkout page then loads Cloudflare\'s Turnstile script, and the shopper\'s Turnstile token and IP address are sent to Cloudflare to confirm it. When Turnstile is on and cannot confirm the shopper (or its keys are missing), the age check does not start.', 'navexa-agecheck-for-woocommerce' ),
				'default'  => 'no',
			),
			array(
				'id'      => Options::TURNSTILE_SITE_KEY,
				'type'    => 'text',
				'title'   => __( 'Turnstile site key', 'navexa-agecheck-for-woocommerce' ),
				'desc'    => __( 'From the Turnstile widget you create for this store\'s domain in the Cloudflare dashboard.', 'navexa-agecheck-for-woocommerce' ),
				'default' => '',
				'class'   => 'regular-text',
			),
			array(
				'id'       => Options::TURNSTILE_SECRET,
				'type'     => self::FIELD_SECRET,
				'title'    => __( 'Turnstile secret key', 'navexa-agecheck-for-woocommerce' ),
				'desc'     => __( 'The same widget\'s secret key.', 'navexa-agecheck-for-woocommerce' ),
				'autoload' => false,
			),
			array(
				'id'   => 'navexa_agecheck_protection_section',
				'type' => 'sectionend',
			),
			array(
				'id'    => 'navexa_agecheck_gate_section',
				'type'  => 'title',
				'title' => __( 'Entry age gate (self-attestation)', 'navexa-agecheck-for-woocommerce' ),
				'desc'  => self::gate_section_text(),
			),
			array(
				'id'       => Options::ENTRY_GATE,
				'type'     => 'checkbox',
				'title'    => __( 'Entry age gate', 'navexa-agecheck-for-woocommerce' ),
				'desc'     => __( 'Ask visitors to confirm their age when they enter the store (self-attestation, not age verification)', 'navexa-agecheck-for-woocommerce' ),
				'desc_tip' => __( 'Visitors only click a button; anyone can say they are old enough. The age check at checkout still verifies every age-restricted order. Off by default.', 'navexa-agecheck-for-woocommerce' ),
				'default'  => 'no',
			),
			array(
				'id'      => Options::ENTRY_GATE_SCOPE,
				'type'    => 'select',
				'title'   => __( 'Where the gate shows', 'navexa-agecheck-for-woocommerce' ),
				'default' => Options::GATE_SCOPE_SITE,
				'options' => array(
					Options::GATE_SCOPE_SITE       => __( 'Every page of the store', 'navexa-agecheck-for-woocommerce' ),
					Options::GATE_SCOPE_RESTRICTED => __( 'Only age-restricted product pages and their category and tag pages', 'navexa-agecheck-for-woocommerce' ),
				),
			),
			array(
				'id'                => Options::ENTRY_GATE_DAYS,
				'type'              => 'number',
				'title'             => __( 'Remember the answer for (days)', 'navexa-agecheck-for-woocommerce' ),
				'desc'              => __( 'Kept in the visitor\'s own browser. 0 remembers it only in the browser tab where they answered; a new tab or window asks again.', 'navexa-agecheck-for-woocommerce' ),
				'default'           => (string) Options::DEFAULT_GATE_DAYS,
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => (string) Options::MAX_GATE_DAYS,
					'step' => '1',
				),
				'css'               => 'width:6em',
			),
			array(
				'id'          => Options::ENTRY_GATE_EXIT_URL,
				'type'        => 'url',
				'title'       => __( 'Send under-age visitors to', 'navexa-agecheck-for-woocommerce' ),
				'desc'        => __( 'An absolute http or https address, such as a page outside the age-restricted part of your store (the gate never shows there). Blank keeps them on the gate with a short message.', 'navexa-agecheck-for-woocommerce' ),
				'default'     => '',
				'placeholder' => 'https://',
				'class'       => 'regular-text',
			),
			array(
				'id'   => 'navexa_agecheck_gate_section',
				'type' => 'sectionend',
			),
			array(
				'id'    => 'navexa_agecheck_data_section',
				'type'  => 'title',
				'title' => __( 'Data', 'navexa-agecheck-for-woocommerce' ),
			),
			array(
				'id'       => Options::DELETE_DATA,
				'type'     => 'checkbox',
				'title'    => __( 'Delete data on uninstall', 'navexa-agecheck-for-woocommerce' ),
				'desc'     => __( 'Also delete each order\'s age check details and each product\'s age check setting when the plugin is deleted', 'navexa-agecheck-for-woocommerce' ),
				'desc_tip' => __( 'Off, they stay as an audit trail. The plugin\'s settings, keys and scheduled jobs are always deleted, and orders still awaiting an age check are moved to On hold, never released.', 'navexa-agecheck-for-woocommerce' ),
				'default'  => 'no',
			),
			array(
				'id'   => 'navexa_agecheck_data_section',
				'type' => 'sectionend',
			),
		);
	}

	/**
	 * Term id => name for a product taxonomy: the first TERM_LIMIT terms by
	 * name, plus every term saved under $option. WooCommerce saves a
	 * multiselect as exactly the options posted back, so a saved term left out
	 * of the list would be dropped by the next save of this tab, and under
	 * "Only products in the categories or tags below" its products would stop
	 * needing a check. A saved term that cannot be read is listed by its id.
	 *
	 * @param string $taxonomy product_cat or product_tag.
	 * @param string $option   Options::RULE_CATEGORIES or Options::RULE_TAGS.
	 * @return array<int, string>
	 */
	private static function term_options( string $taxonomy, string $option ): array {
		$options = self::term_names(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'id=>name',
				'number'     => self::TERM_LIMIT,
			)
		);
		$missing = array_values( array_diff( Options::term_ids( $option ), array_keys( $options ) ) );
		if ( empty( $missing ) ) {
			return $options;
		}
		$options += self::term_names(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'id=>name',
				'include'    => $missing,
			)
		);
		foreach ( $missing as $term_id ) {
			if ( ! isset( $options[ $term_id ] ) ) {
				/* translators: %d: a term id. */
				$options[ $term_id ] = sprintf( __( 'Term #%d', 'navexa-agecheck-for-woocommerce' ), $term_id );
			}
		}
		return $options;
	}

	/**
	 * Terms from get_terms() with 'fields' => 'id=>name', as int id => name.
	 *
	 * @param array<string, mixed> $args get_terms() arguments.
	 * @return array<int, string>
	 */
	private static function term_names( array $args ): array {
		$terms = get_terms( $args );
		$out   = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $id => $name ) {
				$out[ (int) $id ] = (string) $name;
			}
		}
		return $out;
	}

	/**
	 * Whether the include rule names at least one category or tag that exists.
	 */
	private static function include_terms_exist(): bool {
		$rules = array(
			'product_cat' => Options::RULE_CATEGORIES,
			'product_tag' => Options::RULE_TAGS,
		);
		foreach ( $rules as $taxonomy => $option ) {
			$ids = Options::term_ids( $option );
			if ( empty( $ids ) ) {
				continue; // get_terms() with an empty include would list every term.
			}
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
					'include'    => $ids,
				)
			);
			if ( is_array( $found ) && ! empty( $found ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Role slug => name.
	 *
	 * @return array<string, string>
	 */
	private static function role_options(): array {
		$roles = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();
		return is_array( $roles ) ? array_map( 'translate_user_role', $roles ) : array();
	}

	/**
	 * Renders a read-only line: the webhook URL.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	public function output_info_field( $field ): void {
		$title = isset( $field['title'] ) ? (string) $field['title'] : '';
		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html( $title ) . '</th><td class="forminp">';
		echo '<code>' . esc_html( Webhook::url() ) . '</code>';
		echo '</td></tr>';
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
		if ( Options::WEBHOOK_SECRET === $id ) {
			return $this->sanitize_webhook_secret( $raw_value );
		}
		if ( Options::TURNSTILE_SECRET === $id ) {
			return $this->sanitize_turnstile_secret( $raw_value );
		}
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
	 * Saves the webhook secret like a key (blank keeps it, the Remove box
	 * deletes it), with its own shape check. Always returns null.
	 *
	 * @param mixed $raw_value Unslashed posted value.
	 * @return null
	 */
	private function sanitize_webhook_secret( $raw_value ) {
		$id = Options::WEBHOOK_SECRET;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC_Admin_Settings::save() checked the woocommerce-settings nonce.
		$clear = isset( $_POST[ $id . '_clear' ] ) && '1' === sanitize_text_field( wp_unslash( $_POST[ $id . '_clear' ] ) );
		$raw   = is_string( $raw_value ) ? trim( $raw_value ) : '';
		if ( '' === $raw && ! $clear ) {
			return null;
		}
		if ( '' !== $raw && ! Options::looks_like_webhook_secret( $raw ) ) {
			\WC_Admin_Settings::add_error( __( 'That does not look like a webhook signing secret. The saved secret was kept.', 'navexa-agecheck-for-woocommerce' ) );
			return null;
		}
		if ( '' === $raw ) {
			delete_option( $id );
		} else {
			update_option( $id, $raw, false );
		}
		return null;
	}

	/**
	 * Saves the Turnstile secret like the webhook secret (blank keeps it, the
	 * Remove box deletes it), with its own shape check. Always returns null.
	 *
	 * @param mixed $raw_value Unslashed posted value.
	 * @return null
	 */
	private function sanitize_turnstile_secret( $raw_value ) {
		$id = Options::TURNSTILE_SECRET;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC_Admin_Settings::save() checked the woocommerce-settings nonce.
		$clear = isset( $_POST[ $id . '_clear' ] ) && '1' === sanitize_text_field( wp_unslash( $_POST[ $id . '_clear' ] ) );
		$raw   = is_string( $raw_value ) ? trim( $raw_value ) : '';
		if ( '' === $raw && ! $clear ) {
			return null;
		}
		if ( '' !== $raw && ! Options::looks_like_turnstile_key( $raw ) ) {
			\WC_Admin_Settings::add_error( __( 'That does not look like a Turnstile secret key. The saved key was kept.', 'navexa-agecheck-for-woocommerce' ) );
			return null;
		}
		if ( '' === $raw ) {
			delete_option( $id );
		} else {
			update_option( $id, $raw, false );
		}
		return null;
	}

	/**
	 * The entry gate section's explanation: self-attestation, what it asks
	 * and where its age and store name come from. Reads stored values only.
	 */
	private static function gate_section_text(): string {
		return sprintf(
			/* translators: 1: minimum age, e.g. 21; 2: the store's name. */
			__( 'An optional prompt when visitors enter the store: "I am %1$d or older" or "I am under %1$d", under the store name "%2$s". It is self-attestation, not age verification: it only asks, and anyone can answer yes. The age check at checkout still applies to every age-restricted order. The age is the minimum age set in the Navexa dashboard (21 until AgeCheck has answered), and the name is the display name set there, or this site\'s title. It needs no API key, sends nothing to your server or to Navexa, loads nothing from another site and works with page caching. Never shown in wp-admin, on the login page or to the REST API.', 'navexa-agecheck-for-woocommerce' ),
			Options::store_minimum_age(),
			Options::store_display_name()
		);
	}

	/**
	 * Saves the remember-days as a whole number from 0 to Options::MAX_GATE_DAYS
	 * (anything else: the default).
	 *
	 * @param mixed $value     Value after wc_clean().
	 * @param mixed $option    Field definition.
	 * @param mixed $raw_value Unslashed posted value.
	 * @return string
	 */
	public function sanitize_gate_days( $value, $option, $raw_value ) {
		return (string) Options::gate_days( is_string( $raw_value ) ? trim( $raw_value ) : $raw_value );
	}

	/**
	 * Saves the exit URL when it is blank or an absolute http(s) URL; keeps
	 * the saved one with an error otherwise.
	 *
	 * @param mixed $value     Value after wc_clean().
	 * @param mixed $option    Field definition.
	 * @param mixed $raw_value Unslashed posted value.
	 * @return string
	 */
	public function sanitize_gate_exit_url( $value, $option, $raw_value ) {
		$raw = is_string( $raw_value ) ? trim( $raw_value ) : '';
		if ( '' === $raw ) {
			return '';
		}
		$url = Options::exit_url( $raw );
		if ( '' !== $url ) {
			return $url;
		}
		\WC_Admin_Settings::add_error( __( 'The under-age address must be an absolute http or https URL. The saved address was kept.', 'navexa-agecheck-for-woocommerce' ) );
		return Options::entry_gate_exit_url();
	}

	/**
	 * Checks the Turnstile site key's shape. Blank clears it; a malformed one
	 * keeps the saved key with an error.
	 *
	 * @param mixed $value     Value after wc_clean().
	 * @param mixed $option    Field definition.
	 * @param mixed $raw_value Unslashed posted value.
	 * @return mixed
	 */
	public function sanitize_turnstile_site_key( $value, $option, $raw_value ) {
		$raw = is_string( $raw_value ) ? trim( $raw_value ) : '';
		if ( '' === $raw || Options::looks_like_turnstile_key( $raw ) ) {
			return $raw;
		}
		\WC_Admin_Settings::add_error( __( 'That does not look like a Turnstile site key. The saved key was kept.', 'navexa-agecheck-for-woocommerce' ) );
		return Options::turnstile_site_key();
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
		// Only AgeCheck API keys show their first characters; secrets show nothing.
		$api_key = in_array( $id, array( Options::START_KEY, Options::FULFILMENT_KEY ), true );

		echo '<tr valign="top"><th scope="row" class="titledesc">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</label>';
		echo '</th><td class="forminp forminp-password">';
		printf(
			'<input name="%1$s" id="%1$s" type="password" value="" class="regular-text" autocomplete="off" spellcheck="false" placeholder="%2$s" />',
			esc_attr( $id ),
			esc_attr( '' === $saved ? ( $api_key ? 'txk_…' : '' ) : __( 'Leave blank to keep the saved key', 'navexa-agecheck-for-woocommerce' ) )
		);
		echo '<p class="description">';
		if ( '' === $saved ) {
			echo esc_html( Options::WEBHOOK_SECRET === $id ? __( 'No secret saved.', 'navexa-agecheck-for-woocommerce' ) : __( 'No key saved.', 'navexa-agecheck-for-woocommerce' ) );
		} elseif ( ! $api_key ) {
			// No part of the secret is shown.
			esc_html_e( 'A secret is saved.', 'navexa-agecheck-for-woocommerce' );
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
				esc_html( $api_key ? __( 'Remove the saved key', 'navexa-agecheck-for-woocommerce' ) : __( 'Remove the saved secret', 'navexa-agecheck-for-woocommerce' ) )
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
			// Orders started under one organization cannot be linked or released
			// with another organization's key. GetIntegrationInfo names each key's
			// organization; a key whose answer has none cannot be compared.
			if ( '' !== $start->organization_id && '' !== $fulfilment->organization_id && ! hash_equals( $start->organization_id, $fulfilment->organization_id ) ) {
				$lines[] = self::line(
					'error',
					'' !== $start->display_name && '' !== $fulfilment->display_name && $start->display_name !== $fulfilment->display_name
						? sprintf(
							/* translators: 1: account display name of the start key, 2: account display name of the fulfilment key. */
							__( 'The keys belong to different Navexa accounts (%1$s and %2$s). Use two keys from the same account.', 'navexa-agecheck-for-woocommerce' ),
							$start->display_name,
							$fulfilment->display_name
						)
						: __( 'The keys belong to different Navexa accounts. Use two keys from the same account.', 'navexa-agecheck-for-woocommerce' )
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

		if ( '' === Options::webhook_secret() ) {
			$lines[] = self::line( 'warning', __( 'No webhook secret: held orders are checked every 15 minutes instead of the moment the shopper passes.', 'navexa-agecheck-for-woocommerce' ) );
		} elseif ( (int) get_option( Options::WEBHOOK_LAST_RECEIVED, 0 ) > 0 ) {
			/* translators: %s: date and time. */
			$lines[] = self::line( 'ok', sprintf( __( 'Last webhook received %s.', 'navexa-agecheck-for-woocommerce' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) get_option( Options::WEBHOOK_LAST_RECEIVED, 0 ) ) ) );
		} else {
			$lines[] = self::line( 'warning', __( 'No webhook received yet. Check the endpoint URL and secret in the Navexa dashboard.', 'navexa-agecheck-for-woocommerce' ) );
		}
		if ( ! Sweep::healthy() ) {
			$lines[] = self::line( 'warning', __( 'The 15-minute check of held orders has not run in the last hour. WP-Cron may be off: set up a real system cron so held orders are released.', 'navexa-agecheck-for-woocommerce' ) );
		}

		if ( Options::RULE_INCLUDE === Options::rule() && ! self::include_terms_exist() ) {
			$lines[] = self::line( 'error', __( 'Age checks are limited to the categories and tags below, but none is chosen (or they were deleted). Only products set to "Always needs an age check" in the product editor are checked.', 'navexa-agecheck-for-woocommerce' ) );
		}

		if ( Turnstile::enabled() && ! Turnstile::configured() ) {
			$lines[] = self::line( 'error', __( 'Cloudflare Turnstile is on, but its site key or secret key is missing, so shoppers cannot start age checks. Add both keys or turn Turnstile off.', 'navexa-agecheck-for-woocommerce' ) );
		} elseif ( Turnstile::enabled() && ( Options::is_turnstile_test_key( Options::turnstile_site_key() ) || Options::is_turnstile_test_key( Options::turnstile_secret() ) ) ) {
			$live = false;
			foreach ( $infos as $key_info ) {
				$live = $live || $key_info->livemode;
			}
			$lines[] = self::line( $live ? 'error' : 'warning', __( 'Cloudflare Turnstile uses Cloudflare\'s test keys, which accept a public dummy token, so it does not stop bots. Enter the site key and secret key of a Turnstile widget for this store\'s domain before you take live orders.', 'navexa-agecheck-for-woocommerce' ) );
		} elseif ( Turnstile::enabled() ) {
			$lines[] = self::line( 'ok', __( 'Cloudflare Turnstile checks shoppers before an age check starts.', 'navexa-agecheck-for-woocommerce' ) );
		}

		if ( ! RateLimit::table_exists() ) {
			$lines[] = self::line( 'error', __( 'The age check rate-limit table is missing, so shoppers cannot start age checks. Deactivate and reactivate the plugin; if this stays, ask your host to let the database user create tables.', 'navexa-agecheck-for-woocommerce' ) );
		}

		$proxy = RateLimit::suspected_proxy();
		$heads = self::proxy_header_labels();
		if ( null !== $proxy && isset( $heads[ $proxy ] ) ) {
			$lines[] = self::line(
				'warning',
				sprintf(
					/* translators: %s: a proxy header option, e.g. X-Forwarded-For (last address). */
					__( 'The latest age checks reached your store through a proxy, load balancer or CDN (they carry the header %s), but the client IP is read from the connection, so shoppers share rate limits. If your store is behind that proxy, choose its header under Client IP address.', 'navexa-agecheck-for-woocommerce' ),
					$heads[ $proxy ]
				)
			);
		} elseif ( null !== $proxy ) {
			$lines[] = self::line( 'warning', __( 'The latest age checks all came from one IP address or from a private network address, which usually means a proxy, load balancer or CDN in front of your store. If it is, choose its header under Client IP address, or shoppers share rate limits.', 'navexa-agecheck-for-woocommerce' ) );
		}

		if ( self::ships_outside_us() ) {
			$lines[] = self::line( 'warning', __( 'Your store ships outside the US. AgeCheck verifies US addresses only (including US territories and APO/FPO), so age-restricted orders shipping elsewhere will be refused.', 'navexa-agecheck-for-woocommerce' ) );
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
	 * Labels of the proxy headers a merchant may trust, by $_SERVER key
	 * (RateLimit::PROXY_HEADERS).
	 *
	 * @return array<string, string>
	 */
	private static function proxy_header_labels(): array {
		return array(
			'HTTP_CF_CONNECTING_IP' => __( 'CF-Connecting-IP (Cloudflare)', 'navexa-agecheck-for-woocommerce' ),
			'HTTP_TRUE_CLIENT_IP'   => __( 'True-Client-IP', 'navexa-agecheck-for-woocommerce' ),
			'HTTP_X_REAL_IP'        => __( 'X-Real-IP', 'navexa-agecheck-for-woocommerce' ),
			'HTTP_X_FORWARDED_FOR'  => __( 'X-Forwarded-For (last address)', 'navexa-agecheck-for-woocommerce' ),
		);
	}

	/**
	 * Whether WooCommerce is set to ship to any country other than the US and
	 * its territories.
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
			if ( ! ShipTo::is_accepted_country( (string) $code ) ) {
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
