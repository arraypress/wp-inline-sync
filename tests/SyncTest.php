<?php
/**
 * Sync engine tests.
 *
 * @package ArrayPress\InlineSync
 */

declare( strict_types=1 );

namespace ArrayPress\InlineSync\Tests;

use ArrayPress\InlineSync\Registry;
use ArrayPress\InlineSync\RestApi;
use ArrayPress\InlineSync\Sync;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * A sync fetches a page from somewhere else, processes it, and reports what
 * happened -- repeatedly, until the source says there is no more.
 *
 * The failures worth guarding are the quiet ones. A permission check that
 * reads the wrong capability lets any subscriber re-import a price list. A
 * callback that throws takes the whole admin screen with it. And a
 * registration for a sync that does not exist has to be a refusal rather than
 * a fetch against nothing.
 */
final class SyncTest extends TestCase {

	/**
	 * Empty the registry and the stubbed WordPress.
	 */
	protected function setUp(): void {
		is_reset_globals();

		foreach ( array_keys( Registry::instance()->all() ) as $id ) {
			Registry::instance()->unregister( (string) $id );
		}
	}

	/**
	 * And again.
	 */
	protected function tearDown(): void {
		$this->setUp();
	}

	/**
	 * Register a sync that returns one page.
	 *
	 * @param array $config Overrides.
	 *
	 * @return Sync
	 */
	private function sync( array $config = array() ): Sync {
		$sync = new Sync(
			'stripe_prices',
			array_merge(
				array(
					'hook_suffix'      => 'toplevel_page_shop',
					'title'            => 'Sync Prices',
					'data_callback'    => static fn( $cursor ) => array(
						'items'    => array( (object) array( 'id' => 'price_1', 'name' => 'Small' ) ),
						'has_more' => false,
						'cursor'   => '',
						'total'    => 1,
					),
					'process_callback' => static fn( $item ) => true,
				),
				$config
			)
		);

		Registry::register( 'stripe_prices', $sync );

		return $sync;
	}

	/**
	 * A registered sync can be found again.
	 */
	public function test_a_sync_registers_and_is_found(): void {
		$this->sync();

		$this->assertTrue( Registry::instance()->has( 'stripe_prices' ) );
		$this->assertInstanceOf( Sync::class, Registry::instance()->get( 'stripe_prices' ) );
		$this->assertNull( Registry::instance()->get( 'nothing' ) );
	}

	/**
	 * Syncs are found by the screen they belong to.
	 *
	 * A products table asks for its own syncs, not every sync on the site.
	 */
	public function test_syncs_are_found_by_screen(): void {
		$this->sync();

		$this->assertCount( 1, Registry::instance()->get_for_screen( 'toplevel_page_shop' ) );
		$this->assertCount( 0, Registry::instance()->get_for_screen( 'edit.php' ) );
	}

	/**
	 * The capability is the sync's own, not a fixed one.
	 *
	 * A price import and a customer export are not the same permission, and a
	 * library that hardcodes manage_options makes them so.
	 */
	public function test_the_capability_comes_from_the_sync(): void {
		$this->sync( array( 'capability' => 'manage_shop' ) );

		$request = new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) );

		$GLOBALS['is_caps'] = array( 'manage_options' => true );
		$this->assertInstanceOf( WP_Error::class, RestApi::check_permission( $request ) );

		$GLOBALS['is_caps'] = array( 'manage_shop' => true );
		$this->assertTrue( RestApi::check_permission( $request ) );
	}

	/**
	 * An unregistered sync is refused rather than defaulted.
	 *
	 * The dangerous alternative is falling back to a permissive capability for
	 * a sync id nobody registered.
	 */
	public function test_an_unknown_sync_is_refused(): void {
		$request = new WP_REST_Request( array( 'sync_id' => 'nothing' ) );

		$GLOBALS['is_caps'] = array();

		$this->assertInstanceOf( WP_Error::class, RestApi::check_permission( $request ) );
	}

	/**
	 * Fetching a page returns its items.
	 */
	public function test_a_page_is_fetched(): void {
		$this->sync();

		$response = RestApi::handle_fetch( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $response );

		$data = $response->get_data();

		// The fetch stages a page and reports how much it staged; the items
		// themselves go into a transient for the process pass to pick up.
		$this->assertSame( 1, $data['fetched'] );
		$this->assertFalse( $data['has_more'] );
		$this->assertSame( 1, $data['total'] );
	}

	/**
	 * A fetch for a sync nobody registered is an error, not a crash.
	 */
	public function test_fetching_an_unknown_sync_errors(): void {
		$response = RestApi::handle_fetch( new WP_REST_Request( array( 'sync_id' => 'nothing' ) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_sync', $response->get_error_code() );
	}

	/**
	 * A callback that throws becomes an error response.
	 *
	 * The source is somebody else's API. It will time out, rate-limit and
	 * return nonsense, and none of those should be an uncaught exception on an
	 * admin screen.
	 */
	public function test_a_throwing_callback_becomes_an_error(): void {
		$this->sync(
			array(
				'data_callback' => static function ( $cursor ) {
					throw new \RuntimeException( 'Stripe is down' );
				},
			)
		);

		$response = RestApi::handle_fetch( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'fetch_error', $response->get_error_code() );
		$this->assertSame( 'Stripe is down', $response->get_error_message() );
	}

	/**
	 * A callback returning the wrong shape is an error, not a silent success.
	 *
	 * Returning a bare array of items rather than the documented envelope is
	 * the obvious mistake, and without this it looks like a sync of nothing.
	 */
	public function test_a_malformed_callback_result_is_refused(): void {
		$this->sync( array( 'data_callback' => static fn( $cursor ) => array( 'nope' ) ) );

		$response = RestApi::handle_fetch( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_fetch_result', $response->get_error_code() );
	}

	/**
	 * The button carries the sync it belongs to.
	 */
	public function test_the_button_names_its_sync(): void {
		$html = $this->sync()->get_button();

		$this->assertStringContainsString( 'data-sync-id="stripe_prices"', $html );
		$this->assertStringContainsString( 'inline-sync-trigger', $html );
	}

	/**
	 * Assets load on the sync's own screen and nowhere else.
	 */
	public function test_assets_load_on_the_right_screen_only(): void {
		$sync = $this->sync();

		$sync->maybe_enqueue_assets( 'edit.php' );
		$this->assertSame( array(), $GLOBALS['is_scripts'] );

		$sync->maybe_enqueue_assets( 'toplevel_page_shop' );
		$this->assertArrayHasKey( 'inline-sync', $GLOBALS['is_scripts'] );
	}

	/**
	 * The configuration is merged into a shared object rather than assigned.
	 *
	 * Two prefixed copies both enqueue and both localise. Assigning would mean
	 * whichever ran last owned the object and the other plugin's syncs were
	 * simply absent, with nothing reporting it.
	 */
	public function test_the_javascript_config_is_merged_not_assigned(): void {
		$sync = $this->sync();
		$sync->maybe_enqueue_assets( 'toplevel_page_shop' );

		$inline = implode( "\n", $GLOBALS['is_inline']['inline-sync'] ?? array() );

		$this->assertStringContainsString( 'window.InlineSyncConfig = window.InlineSyncConfig ||', $inline );
		$this->assertStringContainsString( 'window.InlineSyncConfig.syncs["stripe_prices"]', $inline );
	}

	/**
	 * Each sync carries its own endpoint.
	 *
	 * Every prefixed build has its own REST namespace, so there is no single
	 * endpoint a shared object could hold.
	 */
	public function test_each_sync_carries_its_own_endpoint(): void {
		$sync = $this->sync();
		$sync->maybe_enqueue_assets( 'toplevel_page_shop' );

		$inline = implode( "\n", $GLOBALS['is_inline']['inline-sync'] ?? array() );

		$this->assertStringContainsString( 'restUrl', $inline );
		$this->assertStringContainsString( 'restNonce', $inline );

		// json_encode escapes the slashes, so the namespace is compared in
		// the form it actually reaches the page in.
		$this->assertStringContainsString(
			trim( (string) wp_json_encode( RestApi::rest_namespace() ), '"' ),
			$inline
		);
	}
}
