<?php
/**
 * Binding tests: external ids, checkout binding (AttachOrder), order-bound
 * CreateVerification and the verification link.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Binding;
use Navexa\AgeCheck\Checkout\Session;
use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\ConnectError;
use Navexa\AgeCheck\CustomerRef;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\OrderAddress;
use Navexa\AgeCheck\Tests\TestCase;

final class BindingTest extends TestCase {

	const VID = 'acv_01HZTESTVERIFICATION';

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
	}

	public function test_register_hooks_both_checkouts(): void {
		Binding::register();
		$this->assertNotFalse( has_action( 'woocommerce_checkout_order_processed', array( Binding::class, 'on_classic_order_processed' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_store_api_checkout_order_processed', array( Binding::class, 'on_store_api_order_processed' ) ) );
	}

	public function test_external_id_is_minted_once_and_never_while_paused(): void {
		$order = \WC_Order::make( 'pending', 42 );
		$id    = Binding::external_id( $order );
		$this->assertSame( 'wc:' . Install::install_id() . ':1:42', $id );
		$this->assertSame( $id, $order->meta[ Meta::EXTERNAL_ID ] );

		$this->options[ Install::OPTION_INSTALL_ID ] = str_repeat( 'b', 32 );
		$this->assertSame( $id, Binding::external_id( $order ), 'stored ids are never recomputed' );

		$this->home = 'https://copy.example';
		$this->assertSame( '', Binding::external_id( \WC_Order::make() ), 'a copied site mints nothing under the original install id' );
		$this->home = 'https://shop.example';
		$this->assertSame( '', Binding::external_id( new \WC_Order() ), 'no id yet' );

		$this->assertSame( array( 'blog_id' => 1, 'order_id' => 42 ), Binding::parse_external_id( $id ) );
		$this->assertNull( Binding::parse_external_id( 'wc:short:1:42' ) );
		$this->assertNull( Binding::parse_external_id( 'medusa_order_1' ) );
	}

	public function test_an_external_id_copied_from_another_order_is_replaced_with_its_state(): void {
		$source = $this->bound_order();
		Binding::external_id( $source );
		$source->meta[ Meta::BOUND_AT ]          = time();
		$source->meta[ Meta::SHIP_TO_HASH ]      = 'h';
		$source->meta[ Meta::CLEARANCE_KEY ]     = 'clr:k';
		$source->meta[ Meta::SHIPMENT_REPORTED ] = time();
		$source->meta[ Meta::HELD ]              = 'yes';
		$source->meta[ Meta::REQUIRED ]          = 'yes';
		Meta::mark_released( $source, Meta::STATUS_CLEARED );

		$copy       = \WC_Order::make( 'pending' );
		$copy->meta = $source->meta; // A duplicate-order plugin, an import, a Subscriptions renewal.
		$this->assertSame( '', Binding::owned_external_id( $copy ) );
		$this->assertFalse( Binding::owns( $copy, $source->meta[ Meta::EXTERNAL_ID ] ) );

		$minted = Binding::external_id( $copy );
		$this->assertSame( 'wc:' . Install::install_id() . ':1:' . $copy->get_id(), $minted );
		$this->assertSame( $minted, Binding::owned_external_id( $copy ) );
		foreach ( array( Meta::VERIFICATION_ID, Meta::ATTACH_PENDING, Meta::BOUND_AT, Meta::SHIP_TO_HASH, Meta::CLEARANCE_KEY, Meta::SHIPMENT_REPORTED, Meta::STATUS, Meta::RELEASED_FOR ) as $key ) {
			$this->assertArrayNotHasKey( $key, $copy->meta, $key );
		}
		$this->assertSame( 'yes', $copy->meta[ Meta::REQUIRED ], 'a copied requirement only makes the order stricter' );
		$this->assertSame( 'yes', $copy->meta[ Meta::HELD ], 'hold bookkeeping follows the order\'s own status' );
		$this->assertStringContainsString( 'copied from another order', end( $copy->notes ) );
		$this->assertStringContainsString( 'dropped copied state order=' . $copy->get_id(), $this->all_logs() );
		$this->assertFalse( Binding::forget_foreign( $copy ), 'once' );

		$other_blog                         = \WC_Order::make( 'pending', 77 );
		$other_blog->meta[ Meta::EXTERNAL_ID ] = 'wc:' . Install::install_id() . ':2:77';
		$this->assertFalse( Binding::owns( $other_blog, $other_blog->meta[ Meta::EXTERNAL_ID ] ) );
		$this->assertTrue( Binding::forget_foreign( $other_blog ) );

		$this->home = 'https://copy.example';
		$paused     = \WC_Order::make();
		$paused->meta[ Meta::EXTERNAL_ID ]     = $source->meta[ Meta::EXTERNAL_ID ];
		$paused->meta[ Meta::VERIFICATION_ID ] = self::VID;
		$this->assertSame( '', Binding::external_id( $paused ), 'nothing is minted while paused, but the copy is still dropped' );
		$this->assertArrayNotHasKey( Meta::VERIFICATION_ID, $paused->meta );
	}

	public function test_attach_never_sends_a_copied_verification(): void {
		$source = $this->bound_order();
		$copy   = \WC_Order::make( 'agecheck-hold' );
		$copy->add_test_item( new \WC_Order_Item_Product( new \WC_Product( 7 ) ) );
		$copy->meta = $source->meta;
		$this->assertSame( Binding::NO_VID, Binding::attach( $copy, $this->fulfilment_client() ) );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_checkout_binds_the_session_verification(): void {
		self::$wc->session->data = array(
			Session::KEY_SID => str_repeat( 'a', 32 ),
			'other'          => 'kept',
		);
		$this->admit_checkout( self::VID, $this->us_address() );
		$this->assertSame( self::VID, self::$wc->session->data[ Session::KEY_VID ] );
		$order = $this->restricted_order();
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_PASSED', 'orderExternalId' => 'x' ) ) );

		Binding::on_classic_order_processed( $order->get_id(), array(), $order );

		$this->assertSame( array( 'AttachOrder' ), $this->rpcs() );
		$external = $order->meta[ Meta::EXTERNAL_ID ];
		$this->assertSame(
			array(
				'idempotencyKey'  => 'ao:' . $external,
				'verificationId'  => self::VID,
				'orderExternalId' => $external,
				'shipTo'          => array(
					'line1'      => '1 Main St',
					'city'       => 'Springfield',
					'region'     => 'IL',
					'postalCode' => '62701',
					'country'    => 'US',
				),
			),
			$this->request_body( 0 )
		);
		$this->assertSame( 'Bearer ' . self::LIVE_FULFILMENT_KEY, $this->http_requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( self::VID, $order->meta[ Meta::VERIFICATION_ID ] );
		$this->assertSame( 'attach', $order->meta[ Meta::BOUND_BY ] );
		$this->assertSame( 'passed', $order->meta[ Meta::VERIFICATION_STATUS ] );
		$this->assertArrayNotHasKey( Meta::ATTACH_PENDING, $order->meta );
		$this->assertSame(
			array(
				Session::KEY_SID => str_repeat( 'a', 32 ),
				'other'          => 'kept',
			),
			self::$wc->session->data,
			'the vid, guard and record leave the session; the sid stays'
		);
		$this->assertSame( OrderAddress::hash( $this->request_body( 0 )['shipTo'] ), $order->meta[ Meta::SHIP_TO_HASH ], 'the bound ship-to a retried payment is admitted at' );

		// A failed-payment retry posts the same order again: nothing more happens.
		self::$wc->session->data[ Session::KEY_VID ] = 'acv_other';
		Binding::on_store_api_order_processed( $order );
		$this->assertCount( 1, $this->http_requests );
	}

	public function test_checkout_without_a_session_verification_or_requirement_makes_no_call(): void {
		$order = $this->restricted_order();
		Binding::bind_checkout( $order );
		$this->assertSame( array(), $this->http_requests );
		$this->assertSame( 'yes', $order->meta[ Meta::REQUIRED ] );
		$this->assertArrayHasKey( Meta::EXTERNAL_ID, $order->meta );
		$this->assertGreaterThan( 0, $order->meta_saves );

		$this->admit_checkout( self::VID, $this->us_address() );
		self::$wc->session->data[ Session::KEY_VID ] = 'not-a-vid';
		Binding::bind_checkout( $this->restricted_order() );
		$this->assertSame( array(), $this->http_requests, 'a malformed session value is ignored' );

		$plain = \WC_Order::make();
		$this->admit_checkout( self::VID, $this->us_address() );
		Binding::bind_checkout( $plain );
		$this->assertSame( 'no', $plain->meta[ Meta::REQUIRED ] );
		$this->assertSame( array(), $this->http_requests );
		$this->assertSame( self::VID, self::$wc->session->data[ Session::KEY_VID ], 'an unrestricted order leaves the session alone' );

		Binding::on_classic_order_processed( 9999 );
		Binding::on_store_api_order_processed( 'nope' );
	}

	public function test_checkout_binds_only_what_the_gate_admitted_in_this_request(): void {
		// A check passed earlier in the session (its guard may have expired),
		// with no gate in this request: Store API order-pay fires the same
		// processed hook. The order takes the order-bound path (Q11b).
		$this->admit_checkout( self::VID, $this->us_address() );
		\Navexa\AgeCheck\Checkout\Gate::reset();
		$order = $this->restricted_order();
		Binding::on_store_api_order_processed( $order );
		$this->assertSame( array(), $this->http_requests );
		$this->assertSame( 'yes', $order->meta[ Meta::REQUIRED ] );
		$this->assertArrayNotHasKey( Meta::VERIFICATION_ID, $order->meta, 'a leftover session check is never bound' );
		$this->assertArrayNotHasKey( Meta::BOUND_BY, $order->meta );
		$this->assertSame( self::VID, self::$wc->session->data[ Session::KEY_VID ], 'and is left for the cart checkout it was made for' );

		// The gate admitted another verification than the session's (a
		// resumed order's own): not the session's check either.
		$this->admit_checkout( self::VID, $this->us_address() );
		self::$wc->session->data[ Session::KEY_VID ] = 'acv_01HZOTHERVERIFICATION';
		$order = $this->restricted_order();
		Binding::bind_checkout( $order );
		$this->assertSame( array(), $this->http_requests );
		$this->assertArrayNotHasKey( Meta::VERIFICATION_ID, $order->meta );

		// Accept-and-hold mode never binds a checkout verification.
		$this->admit_checkout( self::VID, $this->us_address() );
		$this->options[ \Navexa\AgeCheck\Options::MODE ] = \Navexa\AgeCheck\Options::MODE_ACCEPT_AND_HOLD;
		$order = $this->restricted_order();
		Binding::on_classic_order_processed( $order->get_id(), array(), $order );
		$this->assertSame( array(), $this->http_requests );
		$this->assertArrayNotHasKey( Meta::VERIFICATION_ID, $order->meta );
	}

	public function test_checkout_survives_an_outage_and_leaves_attach_pending(): void {
		$this->admit_checkout( self::VID, $this->us_address() );
		$order = $this->restricted_order();
		$this->respond( 503, array( 'code' => 'unavailable', 'message' => 'down' ) );
		Binding::bind_checkout( $order );
		$this->assertSame( 'yes', $order->meta[ Meta::ATTACH_PENDING ] );
		$this->assertSame( self::VID, $order->meta[ Meta::VERIFICATION_ID ] );
		$this->assertArrayNotHasKey( Session::KEY_VID, self::$wc->session->data );
		$this->assertSame( OrderAddress::hash( OrderAddress::ship_to( $order ) ), $order->meta[ Meta::SHIP_TO_HASH ], 'recorded before AttachOrder answers, so a retried payment is still admitted' );
		$this->assertSame( $order->meta, $order->saved_meta, 'saved before the call' );
	}

	public function test_checkout_never_throws(): void {
		$order = new class( 77 ) extends \WC_Order {
			public function save_meta_data() {
				throw new \LogicException( 'db' );
			}
		};
		$order->add_test_item( new \WC_Order_Item_Product( new \WC_Product( 7 ) ) );
		Binding::bind_checkout( $order );
		$this->assertStringContainsString( 'agecheck bind failed order=77 error=LogicException', $this->all_logs() );
	}

	public function test_attach_short_circuits(): void {
		$client = $this->fulfilment_client();
		$this->assertSame( Binding::NO_VID, Binding::attach( $this->restricted_order(), $client ) );

		$bound                         = $this->bound_order();
		$bound->meta[ Meta::BOUND_AT ] = time();
		unset( $bound->meta[ Meta::ATTACH_PENDING ] );
		$this->assertSame( Binding::BOUND, Binding::attach( $bound, $client ) );

		$no_address = $this->bound_order();
		$no_address->set_shipping_address_1( '' );
		$this->assertSame( Binding::REVIEW, Binding::attach( $no_address, $client ) );
		$this->assertSame( 'no_ship_to', $no_address->meta[ Meta::REASON ] );

		$abroad = $this->bound_order();
		$abroad->set_shipping_country( 'CA' );
		$this->assertSame( Binding::DENIED, Binding::attach( $abroad, $client ) );
		$this->assertSame( 'not_us', $abroad->meta[ Meta::REASON ] );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_attach_after_its_key_expired_confirms_the_binding(): void {
		$order = $this->bound_order();
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'verification is already bound to an order' ) );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID, 'orderExternalId' => $order->meta[ Meta::EXTERNAL_ID ] ) ) );
		$this->assertSame( Binding::BOUND, Binding::attach( $order, $this->fulfilment_client() ) );
		$this->assertSame( array( 'AttachOrder', 'GetVerification' ), $this->rpcs() );
		$this->assertSame( array( 'id' => self::VID ), $this->request_body( 1 ) );
	}

	public function test_attach_to_another_ship_to_or_order_needs_a_new_verification(): void {
		$order = $this->bound_order();
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'ship_to_changed' ) );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID ) ) );
		$this->assertSame( Binding::REVERIFY, Binding::attach( $order, $this->fulfilment_client() ) );
		$this->assertSame( 'ship_to_changed', $order->meta[ Meta::REASON ] );
		$this->assertArrayNotHasKey( Meta::VERIFICATION_ID, $order->meta );
		$this->assertSame( self::VID, $order->meta[ Meta::PREVIOUS_VERIFICATION_IDS ] );
		$this->assertSame( 1, $order->meta[ Meta::CREATE_ATTEMPT ] );
		$this->assertStringContainsString( 'cannot be used for this order (ship_to_changed)', end( $order->notes ) );

		$other = $this->bound_order();
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'verification is already bound to an order' ) );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID, 'orderExternalId' => 'wc:someone-else' ) ) );
		$this->assertSame( Binding::REVERIFY, Binding::attach( $other, $this->fulfilment_client() ) );
		$this->assertSame( 'verification_bound_elsewhere', $other->meta[ Meta::REASON ] );
	}

	public function test_attach_when_the_order_is_bound_to_another_verification_adopts_it(): void {
		$order    = $this->bound_order();
		$external = $order->meta[ Meta::EXTERNAL_ID ];
		$this->respond( 409, array( 'code' => 'already_exists', 'message' => 'order bound' ) );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID ) ) );
		$this->respond( 200, array( 'verifications' => array( array( 'verification' => array( 'id' => 'acv_THEOTHER', 'orderExternalId' => $external, 'status' => 'VERIFICATION_STATUS_NEEDS_DOC' ) ) ) ) );
		$this->assertSame( Binding::BOUND, Binding::attach( $order, $this->fulfilment_client() ) );
		$this->assertSame( array( 'AttachOrder', 'GetVerification', 'ListVerifications' ), $this->rpcs() );
		$this->assertSame( array( 'orderExternalId' => $external, 'pageSize' => 1 ), $this->request_body( 2 ) );
		$this->assertSame( 'acv_THEOTHER', $order->meta[ Meta::VERIFICATION_ID ] );
		$this->assertSame( 'adopt', $order->meta[ Meta::BOUND_BY ] );
		$this->assertSame( self::VID, $order->meta[ Meta::PREVIOUS_VERIFICATION_IDS ] );

		$lost = $this->bound_order();
		$this->respond( 409, array( 'code' => 'already_exists' ) );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID ) ) );
		$this->respond( 200, array() );
		$this->assertSame( Binding::REVIEW, Binding::attach( $lost, $this->fulfilment_client() ) );
		$this->assertSame( 'order_bound_elsewhere', $lost->meta[ Meta::REASON ] );
	}

	public function test_attach_refusals_needing_staff(): void {
		$order = $this->bound_order();
		$this->respond( 404, array( 'code' => 'not_found' ) );
		$this->assertSame( Binding::REVIEW, Binding::attach( $order, $this->fulfilment_client() ) );
		$this->assertSame( 'verification_not_found', $order->meta[ Meta::REASON ] );
		$this->assertSame( array( 'AttachOrder' ), $this->rpcs(), 'not_found needs no confirmation read' );

		$order = $this->bound_order();
		$this->respond( 400, array( 'code' => 'invalid_argument', 'message' => 'AgeCheck verifies US addresses only' ) );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID ) ) );
		$this->assertSame( Binding::REVIEW, Binding::attach( $order, $this->fulfilment_client() ) );
		$this->assertSame( 'invalid_ship_to', $order->meta[ Meta::REASON ] );
	}

	public function test_attach_rethrows_outages_key_problems_and_local_refusals(): void {
		foreach ( array( array( 503, 'unavailable' ), array( 403, 'permission_denied' ) ) as $case ) {
			$this->respond( $case[0], array( 'code' => $case[1] ) );
			try {
				Binding::attach( $this->bound_order(), $this->fulfilment_client() );
				$this->fail( 'expected ' . $case[1] );
			} catch ( ConnectError $e ) {
				$this->assertSame( $case[1], $e->connect_code() );
			}
		}
		$order = $this->bound_order();
		unset( $order->meta[ Meta::EXTERNAL_ID ] );
		$this->home = 'https://copy.example';
		$this->expectException( ConnectError::class );
		Binding::attach( $order, $this->fulfilment_client() );
	}

	public function test_create_sends_a_partial_identity_with_a_required_name_check(): void {
		$order = $this->restricted_order( 'agecheck-hold' );
		$this->respond(
			200,
			array(
				'verification' => array(
					'id'        => self::VID,
					'status'    => 'VERIFICATION_STATUS_NEEDS_DOC',
					'hostedUrl' => 'https://verify.navexa.co/v/secret-session',
				),
			)
		);
		$this->assertSame( Binding::BOUND, Binding::create( $order, $this->fulfilment_client() ) );
		$body     = $this->request_body( 0 );
		$external = $order->meta[ Meta::EXTERNAL_ID ];
		$this->assertSame( 'cv:' . $external . ':0', $body['idempotencyKey'] );
		$this->assertSame(
			array(
				'name'               => 'Ada Lovelace',
				'email'              => 'ada@example.com',
				'residentialAddress' => array(
					'line1'      => '1 Main St',
					'city'       => 'Springfield',
					'region'     => 'IL',
					'postalCode' => '62701',
					'country'    => 'US',
				),
			),
			$body['identity']
		);
		$this->assertArrayNotHasKey( 'dob', $body['identity'], 'never a date of birth' );
		$this->assertSame( $external, $body['orderExternalId'] );
		$this->assertSame( '62701', $body['shipTo']['postalCode'] );
		$this->assertSame( 'DOCUMENT_NAME_CHECK_REQUIRED', $body['documentNameCheck'] );
		$this->assertSame( 'https://shop.example/checkout/order-received/' . $order->get_id() . '/?key=wc_order_test', $body['returnUrl'] );
		$this->assertSame( self::VID, $order->meta[ Meta::VERIFICATION_ID ] );
		$this->assertSame( 'create', $order->meta[ Meta::BOUND_BY ] );
		$this->assertSame( 'needs_doc', $order->meta[ Meta::VERIFICATION_STATUS ] );
		$this->assertStringContainsString( 'started an age check for this order (' . self::VID . ')', end( $order->notes ) );
		$this->assertStringNotContainsString( 'secret-session', $this->all_logs() . implode( '', $order->notes ), 'the hosted URL is never logged or noted' );
	}

	public function test_create_omits_what_the_order_lacks(): void {
		$order = $this->restricted_order( 'agecheck-hold' );
		$order->set_billing_email( 'not-an-email' );
		$order->set_billing_state( '' );
		$this->home = 'https://other.example';
		\Navexa\AgeCheck\Install::confirm_site();
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID ) ) );
		Binding::create( $order, $this->fulfilment_client() );
		$body = $this->request_body( 0 );
		$this->assertSame( array( 'name' => 'Ada Lovelace' ), $body['identity'] );
		$this->assertArrayNotHasKey( 'returnUrl', $body, 'a return URL off the store origin is left out' );

		$nameless = $this->restricted_order( 'agecheck-hold' );
		$nameless->set_billing_first_name( ' ' );
		$nameless->set_billing_last_name( '' );
		$this->assertSame( Binding::REVIEW, Binding::create( $nameless, $this->fulfilment_client() ) );
		$this->assertSame( 'no_billing_name', $nameless->meta[ Meta::REASON ] );

		$abroad = $this->restricted_order( 'agecheck-hold' );
		$abroad->set_shipping_country( 'MX' );
		$this->assertSame( Binding::DENIED, Binding::create( $abroad, $this->fulfilment_client() ) );

		$nowhere = $this->restricted_order( 'agecheck-hold' );
		$nowhere->set_shipping_postcode( '' );
		$this->assertSame( Binding::REVIEW, Binding::create( $nowhere, $this->fulfilment_client() ) );
		$this->assertCount( 1, $this->http_requests );
	}

	public function test_create_refusals(): void {
		$order = $this->restricted_order( 'agecheck-hold' );
		$this->respond( 400, array( 'code' => 'invalid_argument', 'message' => 'identity.dob: is required' ) );
		$this->assertSame( Binding::REVIEW, Binding::create( $order, $this->fulfilment_client() ) );
		$this->assertSame( 'create_rejected', $order->meta[ Meta::REASON ] );
		$this->assertSame( 1, $order->meta[ Meta::CREATE_ATTEMPT ], 'the next attempt uses a new key' );
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'add a card' ) );
		$this->assertSame( Binding::REVIEW, Binding::create( $order, $this->fulfilment_client() ) );
		$this->assertSame( 'create_refused', $order->meta[ Meta::REASON ] );
		$this->assertStringEndsWith( ':1', $this->request_body( 1 )['idempotencyKey'] );

		$this->respond( 409, array( 'code' => 'already_exists' ) );
		$this->respond( 200, array( 'verifications' => array( array( 'verification' => array( 'id' => self::VID, 'orderExternalId' => $order->meta[ Meta::EXTERNAL_ID ] ) ) ) ) );
		$this->assertSame( Binding::BOUND, Binding::create( $order, $this->fulfilment_client() ) );
		$this->assertSame( self::VID, $order->meta[ Meta::VERIFICATION_ID ] );
		$this->assertArrayNotHasKey( Meta::REASON, $order->meta, 'binding clears the old review reason' );

		$this->respond( 503, array( 'code' => 'unavailable' ) );
		try {
			Binding::create( $this->restricted_order( 'agecheck-hold' ), $this->fulfilment_client() );
			$this->fail( 'outages are rethrown' );
		} catch ( ConnectError $e ) {
			$this->assertTrue( $e->is_retryable() );
		}

		$this->respond( 200, array( 'verification' => array( 'id' => 'not a vid' ) ) );
		$this->expectException( ConnectError::class );
		Binding::create( $this->restricted_order( 'agecheck-hold' ), $this->fulfilment_client() );
	}

	public function test_create_sends_a_customer_ref_for_an_account_order_only(): void {
		$guest = $this->restricted_order( 'agecheck-hold' );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_NEEDS_DOC' ) ) );
		Binding::create( $guest, $this->fulfilment_client() );
		$this->assertArrayNotHasKey( 'customerRef', $this->request_body( 0 ), 'guests get none' );

		$account                      = $this->restricted_order( 'agecheck-hold' );
		$account->data['customer_id'] = 42;
		$this->respond( 200, array( 'verification' => array( 'id' => 'acv_ACCOUNT', 'status' => 'VERIFICATION_STATUS_NEEDS_DOC' ) ) );
		Binding::create( $account, $this->fulfilment_client() );
		$body = $this->request_body( 1 );
		$this->assertSame( CustomerRef::for_user( 42 ), $body['customerRef'] );
		$this->assertMatchesRegularExpression( '/^wpc1_[0-9a-f]{64}$/', $body['customerRef'] );
		$this->assertStringEndsWith( ':0', $body['idempotencyKey'], 'the key is unchanged' );
	}

	public function test_a_reused_pass_is_recorded_with_its_class(): void {
		$order                      = $this->restricted_order( 'agecheck-hold' );
		$order->data['customer_id'] = 42;
		$this->respond( 200, array( 'verification' => $this->verification( self::VID, 'PASSED', array( 'passClass' => 'PASS_CLASS_REUSED' ) ) ) );
		$this->assertSame( Binding::BOUND, Binding::create( $order, $this->fulfilment_client() ) );
		$this->assertSame( 'passed', $order->meta[ Meta::VERIFICATION_STATUS ] );
		$this->assertSame( 'REUSED', $order->meta[ Meta::PASS_CLASS ] );

		$attached = $this->bound_order();
		$this->respond( 200, array( 'verification' => $this->verification( self::VID, 'PASSED', array( 'passClass' => 6 ) ) ) );
		$this->assertSame( Binding::BOUND, Binding::attach( $attached, $this->fulfilment_client() ) );
		$this->assertSame( 'REUSED', $attached->meta[ Meta::PASS_CLASS ], 'the enum number is read too' );

		$pending = $this->bound_order();
		$pending->meta[ Meta::PASS_CLASS ] = 'DOC_ONLY';
		$this->respond( 200, array( 'verification' => $this->verification( self::VID, 'NEEDS_DOC' ) ) );
		Binding::attach( $pending, $this->fulfilment_client() );
		$this->assertArrayNotHasKey( Meta::PASS_CLASS, $pending->meta, 'only a passed verification has a class' );
	}

	public function test_pass_class_name(): void {
		$this->assertSame( 'REUSED', Binding::pass_class_name( array( 'passClass' => 'PASS_CLASS_REUSED' ) ) );
		$this->assertSame( 'DATA_VERIFIED', Binding::pass_class_name( array( 'passClass' => 5 ) ) );
		$this->assertSame( 'SOMETHING_NEW', Binding::pass_class_name( array( 'passClass' => 'PASS_CLASS_SOMETHING_NEW' ) ) );
		$this->assertSame( '', Binding::pass_class_name( array( 'passClass' => 'PASS_CLASS_UNSPECIFIED' ) ) );
		$this->assertSame( '', Binding::pass_class_name( array( 'passClass' => 99 ) ) );
		$this->assertSame( '', Binding::pass_class_name( array( 'passClass' => '<b>x</b>' ) ) );
		$this->assertSame( '', Binding::pass_class_name( array() ) );
	}

	public function test_reverify_drops_the_link_and_pass_state(): void {
		$order = $this->bound_order();
		foreach ( array( Meta::LINK => 'sent:' . self::VID, Meta::LINK_ID => 'acl_1', Meta::LINK_EXPIRES_AT => 1, Meta::LINK_SENT_AT => 1, Meta::LINK_KEY => 'vl:k', Meta::PASS_CLASS => 'DOC_ONLY' ) as $key => $value ) {
			$order->meta[ $key ] = $value;
		}
		Binding::reverify( $order, 'verification_purged' );
		foreach ( array( Meta::LINK, Meta::LINK_ID, Meta::LINK_EXPIRES_AT, Meta::LINK_SENT_AT, Meta::LINK_KEY, Meta::PASS_CLASS ) as $key ) {
			$this->assertArrayNotHasKey( $key, $order->meta, $key );
		}
		foreach ( array( Meta::LINK_ID, Meta::LINK_EXPIRES_AT, Meta::LINK_SENT_AT, Meta::LINK_KEY, Meta::PASS_CLASS ) as $key ) {
			$this->assertContains( $key, Binding::COPIED_STATE, 'copied link state is dropped with the external id' );
		}
	}

	public function test_status_name(): void {
		$this->assertSame( 'needs_doc', Binding::status_name( array( 'status' => 'VERIFICATION_STATUS_NEEDS_DOC' ) ) );
		$this->assertSame( 'awaiting_identity', Binding::status_name( array( 'status' => 'VERIFICATION_STATUS_AWAITING_IDENTITY' ) ) );
		$this->assertSame( '', Binding::status_name( array( 'status' => 'VERIFICATION_STATUS_UNSPECIFIED' ) ) );
		$this->assertSame( '', Binding::status_name( array() ) );
	}

	/** A held order with a checkout verification still to attach. */
	private function bound_order(): \WC_Order {
		$order                                = $this->restricted_order( 'agecheck-hold' );
		$order->meta[ Meta::VERIFICATION_ID ] = self::VID;
		$order->meta[ Meta::ATTACH_PENDING ]  = 'yes';
		Binding::external_id( $order );
		return $order;
	}
}
