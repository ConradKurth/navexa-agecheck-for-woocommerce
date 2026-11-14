<?php
/**
 * Binding tests: external ids, checkout binding (AttachOrder), order-bound
 * CreateVerification and the verification link.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Filters;
use Navexa\AgeCheck\Binding;
use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\ConnectError;
use Navexa\AgeCheck\EngineLinkSender;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\LinkSender;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Tests\TestCase;
use Navexa\AgeCheck\UnavailableLinkSender;

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
		$this->wc->session->data = array(
			Binding::SESSION_VID   => self::VID,
			Binding::SESSION_GUARD => 'guard-token',
			'other'                => 'kept',
		);
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
		$this->assertSame( array( 'other' => 'kept' ), $this->wc->session->data, 'the vid and guard leave the session' );

		// A failed-payment retry posts the same order again: nothing more happens.
		$this->wc->session->data[ Binding::SESSION_VID ] = 'acv_other';
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

		$this->wc->session->data[ Binding::SESSION_VID ] = 'not-a-vid';
		Binding::bind_checkout( $this->restricted_order() );
		$this->assertSame( array(), $this->http_requests, 'a malformed session value is ignored' );

		$plain                                           = \WC_Order::make();
		$this->wc->session->data[ Binding::SESSION_VID ] = self::VID;
		Binding::bind_checkout( $plain );
		$this->assertSame( 'no', $plain->meta[ Meta::REQUIRED ] );
		$this->assertSame( array(), $this->http_requests );
		$this->assertSame( self::VID, $this->wc->session->data[ Binding::SESSION_VID ], 'an unrestricted order leaves the session alone' );

		Binding::on_classic_order_processed( 9999 );
		Binding::on_store_api_order_processed( 'nope' );
	}

	public function test_checkout_survives_an_outage_and_leaves_attach_pending(): void {
		$this->wc->session->data[ Binding::SESSION_VID ] = self::VID;
		$order = $this->restricted_order();
		$this->respond( 503, array( 'code' => 'unavailable', 'message' => 'down' ) );
		Binding::bind_checkout( $order );
		$this->assertSame( 'yes', $order->meta[ Meta::ATTACH_PENDING ] );
		$this->assertSame( self::VID, $order->meta[ Meta::VERIFICATION_ID ] );
		$this->assertArrayNotHasKey( Binding::SESSION_VID, $this->wc->session->data );
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

	public function test_send_link_unavailable_until_the_engine_has_it(): void {
		$order = $this->created_order();
		$this->assertSame( LinkSender::RESULT_UNAVAILABLE, Binding::send_link( $order, $this->fulfilment_client(), new UnavailableLinkSender() ) );
		$this->assertSame( 'unavailable:' . self::VID, $order->meta[ Meta::LINK ] );
		$this->assertStringContainsString( 'verification emails are not available yet', end( $order->notes ) );
		$notes = count( $order->notes );
		$this->assertSame( LinkSender::RESULT_UNAVAILABLE, Binding::send_link( $order, $this->fulfilment_client(), new UnavailableLinkSender() ) );
		$this->assertCount( $notes, $order->notes, 'asked again later, noted once' );
		$this->assertSame( array(), $this->http_requests );
		$this->assertSame( '', Binding::send_link( $this->restricted_order(), $this->fulfilment_client(), new UnavailableLinkSender() ) );
	}

	public function test_send_link_through_the_engine(): void {
		$order = $this->created_order();
		$this->respond( 200, array( 'linkId' => 'acl_1', 'expiresAt' => '2026-11-21T00:00:00Z', 'emailQueued' => true ) );
		$this->assertSame( LinkSender::RESULT_SENT, Binding::send_link( $order, $this->fulfilment_client(), new EngineLinkSender() ) );
		$this->assertSame( array( 'idempotencyKey' => 'vl:' . self::VID, 'verificationId' => self::VID ), $this->request_body( 0 ) );
		$this->assertSame( LinkSender::RESULT_SENT, Binding::send_link( $order, $this->fulfilment_client(), new EngineLinkSender() ) );
		$this->assertCount( 1, $this->http_requests, 'sent once per verification' );
		$this->assertStringContainsString( 'emailed the shopper a link', end( $order->notes ) );
	}

	public function test_only_order_bound_verifications_this_plugin_created_are_emailed(): void {
		$sender = new class() implements LinkSender {
			public $calls = 0;
			public function send( Client $client, string $verification_id, string $idempotency_key ): string {
				++$this->calls;
				return LinkSender::RESULT_SENT;
			}
		};
		foreach ( array( 'attach', 'adopt', '' ) as $by ) {
			$order = $this->bound_order();
			if ( '' !== $by ) {
				$order->meta[ Meta::BOUND_BY ] = $by;
			}
			$this->assertSame( Binding::LINK_NOT_ELIGIBLE, Binding::send_link( $order, $this->fulfilment_client(), $sender ), $by );
			$this->assertSame( Binding::LINK_NOT_ELIGIBLE . ':' . self::VID, $order->meta[ Meta::LINK ] );
			$this->assertStringContainsString( 'match the ID to the billing name, so none was sent', end( $order->notes ) );
			$notes = count( $order->notes );
			Binding::send_link( $order, $this->fulfilment_client(), $sender );
			$this->assertCount( $notes, $order->notes, 'noted once per verification' );
		}
		$this->assertSame( 0, $sender->calls, 'a checkout verification (name check NONE) is never emailed (Q7)' );
	}

	public function test_engine_link_sender_maps_answers(): void {
		$sender = new EngineLinkSender();
		$client = $this->fulfilment_client();
		$this->respond( 404, '404 page not found' );
		$this->assertSame( LinkSender::RESULT_UNAVAILABLE, $sender->send( $client, self::VID, 'k' ) );
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'no email' ) );
		$this->assertSame( LinkSender::RESULT_REFUSED, $sender->send( $client, self::VID, 'k' ) );
		$this->respond( 200, array( 'linkId' => 'acl_1' ) );
		$this->assertSame( LinkSender::RESULT_REFUSED, $sender->send( $client, self::VID, 'k' ), 'no email queued' );
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		$this->expectException( ConnectError::class );
		$sender->send( $client, self::VID, 'k' );
	}

	public function test_a_refused_link_is_final_and_odd_sender_answers_are_refusals(): void {
		$order  = $this->created_order();
		$sender = new class() implements LinkSender {
			public $calls = 0;
			public function send( Client $client, string $verification_id, string $idempotency_key ): string {
				++$this->calls;
				return 'weird';
			}
		};
		$this->assertSame( LinkSender::RESULT_REFUSED, Binding::send_link( $order, $this->fulfilment_client(), $sender ) );
		$this->assertSame( LinkSender::RESULT_REFUSED, Binding::send_link( $order, $this->fulfilment_client(), $sender ) );
		$this->assertSame( 1, $sender->calls );
	}

	public function test_link_sender_default_and_filter(): void {
		$this->assertInstanceOf( UnavailableLinkSender::class, Binding::link_sender() );
		Filters\expectApplied( 'navexa_agecheck_link_sender' )->andReturn( new EngineLinkSender() );
		$this->assertInstanceOf( EngineLinkSender::class, Binding::link_sender() );
	}

	public function test_link_sender_filter_cannot_return_junk(): void {
		Filters\expectApplied( 'navexa_agecheck_link_sender' )->andReturn( 'junk' );
		$this->assertInstanceOf( UnavailableLinkSender::class, Binding::link_sender() );
	}

	public function test_status_name(): void {
		$this->assertSame( 'needs_doc', Binding::status_name( array( 'status' => 'VERIFICATION_STATUS_NEEDS_DOC' ) ) );
		$this->assertSame( 'awaiting_identity', Binding::status_name( array( 'status' => 'VERIFICATION_STATUS_AWAITING_IDENTITY' ) ) );
		$this->assertSame( '', Binding::status_name( array( 'status' => 'VERIFICATION_STATUS_UNSPECIFIED' ) ) );
		$this->assertSame( '', Binding::status_name( array() ) );
	}

	/** A held order bound to an order-bound verification this plugin created. */
	private function created_order(): \WC_Order {
		$order                         = $this->bound_order();
		$order->meta[ Meta::BOUND_BY ] = 'create';
		unset( $order->meta[ Meta::ATTACH_PENDING ] );
		return $order;
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
