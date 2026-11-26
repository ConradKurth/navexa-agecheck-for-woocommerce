<?php
/**
 * Uninstall (W4 PR 16): everything the plugin stored goes, order meta stays
 * unless "Delete data on uninstall" is on, held orders are moved to On hold.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Brain\Monkey\Functions;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Tests\TestCase;
use Navexa\AgeCheck\Uninstall;

/**
 * $wpdb for Uninstall's statements: the option-name scan, SHOW TABLES, the
 * held-order id queries (by status, after an id), DROP TABLE and the meta
 * DELETEs (recorded).
 */
final class UninstallWpdb {
	public $prefix   = 'wp_';
	public $options  = 'wp_options';
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	/** @var string[] */
	public $tables = array( 'wp_wc_orders', 'wp_wc_orders_meta' );
	/** @var string[] */
	public $queries = array();
	/** @var array<string, mixed> */
	private $store;

	public function __construct( array &$store ) {
		$this->store = &$store;
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[sd]/',
			static function ( $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function get_col( $sql ) {
		$this->queries[] = $sql;
		if ( 0 === strpos( $sql, 'SELECT option_name FROM wp_options' ) ) {
			return array_values(
				array_filter(
					array_keys( $this->store ),
					static function ( $name ) {
						return 0 === strpos( $name, 'navexa_agecheck_' ) || 0 === strpos( $name, '_transient_navexa_agecheck_' ) || 0 === strpos( $name, '_transient_timeout_navexa_agecheck_' );
					}
				)
			);
		}
		if ( preg_match( "/^SELECT (?:id|ID) FROM (wp_wc_orders|wp_posts) WHERE (?:post_)?status = 'wc-agecheck-hold'.* > (\\d+) ORDER BY (?:id|ID) LIMIT (\\d+)$/", $sql, $m ) ) {
			$ids = array();
			foreach ( \WC_Order::$store as $order ) {
				if ( 'agecheck-hold' === $order->get_status() && $order->get_id() > (int) $m[2] ) {
					$ids[] = (string) $order->get_id();
				}
			}
			sort( $ids );
			return array_slice( $ids, 0, (int) $m[3] );
		}
		throw new \RuntimeException( 'UninstallWpdb: unexpected query ' . $sql );
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( "/^SHOW TABLES LIKE '(.*)'$/", $sql, $m ) ) {
			$table = stripslashes( stripcslashes( $m[1] ) );
			$table = str_replace( '\\', '', $table );
			return in_array( $table, $this->tables, true ) ? $table : null;
		}
		throw new \RuntimeException( 'UninstallWpdb: unexpected query ' . $sql );
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		return 1;
	}

	/** Recorded statements that start with $prefix. */
	public function ran( string $prefix ): array {
		return array_values(
			array_filter(
				$this->queries,
				static function ( $sql ) use ( $prefix ) {
					return 0 === strpos( $sql, $prefix );
				}
			)
		);
	}
}

final class UninstallTest extends TestCase {

	/** @var UninstallWpdb */
	private $db;

	/** @var array<int, array> */
	private $calls = array();

	protected function setUp(): void {
		parent::setUp();
		$this->db        = new UninstallWpdb( $this->options );
		$GLOBALS['wpdb'] = $this->db;
		OrderUtil::$hpos = false;
		$test            = $this;
		foreach ( array( 'as_unschedule_all_actions', 'wp_clear_scheduled_hook', 'delete_metadata', 'wp_cache_flush', 'switch_to_blog', 'restore_current_blog' ) as $fn ) {
			Functions\when( $fn )->alias(
				static function ( ...$args ) use ( $test, $fn ) {
					$test->calls[] = array_merge( array( $fn ), $args );
					return true;
				}
			);
		}
		Functions\when( 'is_multisite' )->justReturn( false );
		$this->options[ Options::START_KEY ]                     = self::LIVE_START_KEY;
		$this->options['navexa_agecheck_install_id']             = 'abc';
		$this->options['navexa_agecheck_lock_release_101']       = '1';
		$this->options['_transient_navexa_agecheck_info_start']  = array();
		$this->options['_transient_timeout_navexa_agecheck_x']   = 1;
		$this->options['woocommerce_store_address']              = 'kept';
	}

	protected function tearDown(): void {
		OrderUtil::$hpos = false;
		parent::tearDown();
	}

	private function calls_to( string $fn ): array {
		return array_values(
			array_filter(
				$this->calls,
				static function ( $call ) use ( $fn ) {
					return $fn === $call[0];
				}
			)
		);
	}

	public function test_removes_options_transients_table_actions_cron_and_user_meta(): void {
		Uninstall::run();

		$this->assertSame( array( 'woocommerce_store_address' ), array_keys( $this->options ), 'only other plugins\' options remain' );
		$this->assertSame( array( 'DROP TABLE IF EXISTS wp_navexa_agecheck_rate_limits' ), $this->db->ran( 'DROP TABLE' ) );
		$this->assertSame( array( array( 'as_unschedule_all_actions', '', array(), 'navexa-agecheck' ) ), $this->calls_to( 'as_unschedule_all_actions' ) );
		$this->assertSame( array( array( 'wp_clear_scheduled_hook', 'navexa_agecheck_sweep_cron' ) ), $this->calls_to( 'wp_clear_scheduled_hook' ) );
		$this->assertSame(
			array(
				array( 'delete_metadata', 'user', 0, 'navexa_agecheck_dismissed_counts', '', true ),
				array( 'delete_metadata', 'user', 0, 'navexa_agecheck_dismissed_links', '', true ),
				array( 'delete_metadata', 'user', 0, 'navexa_agecheck_dismissed_test', '', true ),
				array( 'delete_metadata', 'user', 0, 'navexa_agecheck_dismissed_paused', '', true ),
				array( 'delete_metadata', 'user', 0, 'navexa_agecheck_dismissed_capped', '', true ),
				array( 'delete_metadata', 'user', 0, 'navexa_agecheck_dismissed_setup', '', true ),
			),
			$this->calls_to( 'delete_metadata' )
		);
		$this->assertSame( \Navexa\AgeCheck\Admin\Notices::USER_META_LINKS, Uninstall::USER_META_LINKS );
		$this->assertSame( \Navexa\AgeCheck\Admin\Notices::USER_META_TEST, Uninstall::USER_META_TEST );
		$this->assertSame( \Navexa\AgeCheck\Admin\Notices::USER_META_PAUSED, Uninstall::USER_META_PAUSED );
		$this->assertSame( \Navexa\AgeCheck\Admin\Notices::USER_META_CAPPED, Uninstall::USER_META_CAPPED );
		$this->assertSame( \Navexa\AgeCheck\Admin\Notices::USER_META_SETUP, Uninstall::USER_META_SETUP );
		$this->assertSame( array(), $this->db->ran( 'DELETE' ), 'order and product meta kept by default' );
		$this->assertSame( array(), $this->calls_to( 'wp_cache_flush' ) );
	}

	public function test_delete_data_on_removes_order_and_product_meta(): void {
		$this->options[ Options::DELETE_DATA ] = 'yes';

		Uninstall::run();

		$this->assertSame(
			array(
				"DELETE FROM wp_wc_orders_meta WHERE meta_key LIKE '\\\\_navexa\\\\_agecheck\\\\_%'",
				"DELETE FROM wp_postmeta WHERE meta_key LIKE '\\\\_navexa\\\\_agecheck\\\\_%'",
			),
			$this->db->ran( 'DELETE' )
		);
		$this->assertCount( 1, $this->calls_to( 'wp_cache_flush' ) );
		$this->assertArrayNotHasKey( Options::DELETE_DATA, $this->options, 'the setting goes too' );
	}

	public function test_delete_data_without_hpos_tables_touches_post_meta_only(): void {
		$this->options[ Options::DELETE_DATA ] = 'yes';
		$this->db->tables                      = array();
		Uninstall::run();
		$this->assertCount( 1, $this->db->ran( 'DELETE' ) );
		$this->assertStringStartsWith( 'DELETE FROM wp_postmeta', $this->db->ran( 'DELETE' )[0] );
	}

	public function test_held_orders_move_to_on_hold_never_released(): void {
		$held  = array();
		$other = \WC_Order::make( 'processing' );
		for ( $i = 0; $i < 3; $i++ ) {
			$held[] = \WC_Order::make( 'agecheck-hold' );
		}

		Uninstall::run();

		foreach ( $held as $order ) {
			$this->assertSame( 'on-hold', $order->get_status() );
			$this->assertStringContainsString( 'It was not verified', end( $order->notes ) );
		}
		$this->assertSame( 'processing', $other->get_status(), 'other orders untouched' );
		$this->assertNotEmpty( $this->db->ran( 'SELECT ID FROM wp_posts' ) );
		$this->assertSame( array(), $this->db->ran( 'SELECT id FROM wp_wc_orders' ) );
	}

	public function test_the_move_knows_the_hold_status_and_sends_no_email(): void {
		$added = array();
		foreach ( array( 'wc_order_statuses', 'woocommerce_email_enabled_customer_on_hold_order', 'woocommerce_email_enabled_new_order' ) as $hook ) {
			\Brain\Monkey\Filters\expectAdded( $hook )->once()->whenHappen(
				static function ( $callback ) use ( &$added, $hook ) {
					$added[ $hook ] = $callback;
				}
			);
		}
		$order = new class( 990 ) extends \WC_Order {
			/** @var array */
			public $active = array();

			public function update_status( $new_status, $note = '', $manual = false ) {
				foreach ( array( 'wc_order_statuses', 'woocommerce_email_enabled_customer_on_hold_order', 'woocommerce_email_enabled_new_order' ) as $hook ) {
					$this->active[ $hook ] = (bool) has_filter( $hook );
				}
				return parent::update_status( $new_status, $note, $manual );
			}
		};
		$order->data['status'] = 'agecheck-hold';
		\WC_Order::$store[990] = $order;

		Uninstall::release_hold_status();

		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( array_fill_keys( array_keys( $added ), true ), $order->active, 'all three filters are in place during the move' );
		$statuses = $added['wc_order_statuses']( array( 'wc-on-hold' => 'On hold' ) );
		$this->assertArrayHasKey( 'wc-agecheck-hold', $statuses, 'WooCommerce must not read the old status as pending (pending to on-hold emails the shopper)' );
		$this->assertFalse( $added['woocommerce_email_enabled_customer_on_hold_order']() );
		$this->assertFalse( $added['woocommerce_email_enabled_new_order']() );
		$this->assertFalse( has_filter( 'wc_order_statuses' ), 'removed after the move' );
		$this->assertFalse( has_filter( 'woocommerce_email_enabled_new_order' ) );
	}

	public function test_held_orders_are_read_from_the_hpos_table_when_it_is_authoritative(): void {
		OrderUtil::$hpos = true;
		$order           = \WC_Order::make( 'agecheck-hold' );
		Uninstall::run();
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertNotEmpty( $this->db->ran( 'SELECT id FROM wp_wc_orders' ) );
		$this->assertSame( array(), $this->db->ran( 'SELECT ID FROM wp_posts' ) );
	}

	public function test_an_order_that_cannot_be_loaded_is_skipped_once(): void {
		$ghost = \WC_Order::make( 'agecheck-hold' );
		$real  = \WC_Order::make( 'agecheck-hold' );
		$this->orders[ $ghost->get_id() ] = false;
		$test                             = $this;
		Functions\when( 'wc_get_order' )->alias(
			static function ( $id ) use ( $test, $ghost ) {
				return (int) $id === $ghost->get_id() ? false : \WC_Order::$store[ (int) $id ];
			}
		);

		Uninstall::release_hold_status();

		$this->assertSame( 'agecheck-hold', $ghost->get_status() );
		$this->assertSame( 'on-hold', $real->get_status() );
		$this->assertCount( 2, $this->db->ran( 'SELECT ID FROM wp_posts' ), 'one batch, then an empty one: no loop' );
	}

	public function test_multisite_uninstalls_every_site(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->justReturn( array( 1, 2 ) );
		Uninstall::run();
		$this->assertSame( array( array( 'switch_to_blog', 1 ), array( 'switch_to_blog', 2 ) ), $this->calls_to( 'switch_to_blog' ) );
		$this->assertCount( 2, $this->calls_to( 'restore_current_blog' ) );
		$this->assertCount( 2, $this->db->ran( 'DROP TABLE' ) );
		$this->assertCount( 6, $this->calls_to( 'delete_metadata' ), 'user meta is network-wide: once per key' );
	}

	public function test_uninstall_php_loads_the_class_itself(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' );
		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;", $source );
		$this->assertStringContainsString( "require_once __DIR__ . '/includes/Uninstall.php';", $source );
		$class = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Uninstall.php' );
		$this->assertSame( 0, preg_match( '/\b(Options|Install|RateLimit|Sweep|Release|Meta|Hold)::/', $class ), 'no other plugin class: the autoloader is not loaded at uninstall' );
	}
}
