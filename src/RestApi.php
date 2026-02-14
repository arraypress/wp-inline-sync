<?php
/**
 * REST API
 *
 * Two-phase REST endpoint for inline sync batch processing.
 *
 * Phase 1 (fetch): Calls data_callback, stores items in a transient,
 *                   returns total count so JS can show a real progress bar.
 *
 * Phase 2 (process): Pulls a small chunk from the transient, processes
 *                     each item, returns per-item results. JS calls this
 *                     repeatedly until all items are processed, then
 *                     fetches the next page if has_more is true.
 *
 * This separation ensures the progress bar moves visibly even when all
 * items fit in a single API page (e.g., 27 Stripe prices in one call).
 *
 * @package     ArrayPress\InlineSync
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\InlineSync;

use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class RestApi
 *
 * Two-phase REST endpoint for all inline sync operations.
 *
 * @since 1.0.0
 */
final class RestApi {

	/**
	 * REST namespace.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const NAMESPACE = 'inline-sync/v1';

	/**
	 * Default number of items to process per chunk.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const CHUNK_SIZE = 5;

	/**
	 * Transient TTL in seconds (10 minutes).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const TRANSIENT_TTL = 600;

	/**
	 * Whether the API has been registered.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register REST API routes.
	 *
	 * Safe to call multiple times — only registers once.
	 *
	 * @since 1.0.0
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );

		self::$registered = true;
	}

	/**
	 * Register REST routes.
	 *
	 * @since 1.0.0
	 */
	public static function register_routes(): void {
		// Phase 1: Fetch items from source and cache them
		register_rest_route( self::NAMESPACE, '/fetch', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'handle_fetch' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
			'args'                => [
				'sync_id' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'cursor'  => [
					'default' => '',
					'type'    => 'string',
				],
			],
		] );

		// Phase 2: Process a chunk of cached items
		register_rest_route( self::NAMESPACE, '/process', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'handle_process' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
			'args'                => [
				'sync_id' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );
	}

	/**
	 * Check if the current user has permission.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission( WP_REST_Request $request ): bool|WP_Error {
		$sync_id = $request->get_param( 'sync_id' );
		$sync    = $sync_id ? Registry::instance()->get( $sync_id ) : null;

		$capability = $sync
			? $sync->get_config( 'capability', 'manage_options' )
			: 'manage_options';

		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'arraypress' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	// =========================================================================
	// Phase 1: Fetch
	// =========================================================================

	/**
	 * Handle a fetch request.
	 *
	 * Calls the data_callback to retrieve items from the external source,
	 * stores them in a transient keyed by sync_id + user_id, and returns
	 * the count and pagination info so JS can set up the progress bar.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_fetch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$sync_id = $request->get_param( 'sync_id' );
		$cursor  = $request->get_param( 'cursor' );

		$sync = Registry::instance()->get( $sync_id );

		if ( ! $sync ) {
			return new WP_Error(
				'invalid_sync',
				__( 'Sync registration not found.', 'arraypress' ),
				[ 'status' => 400 ]
			);
		}

		$data_callback = $sync->get_data_callback();

		if ( ! $data_callback ) {
			return new WP_Error(
				'no_data_callback',
				__( 'No data callback defined.', 'arraypress' ),
				[ 'status' => 500 ]
			);
		}

		// Fetch items from source
		try {
			$fetch = call_user_func( $data_callback, $cursor );
		} catch ( Exception $e ) {
			return new WP_Error(
				'fetch_error',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}

		if ( ! is_array( $fetch ) || ! isset( $fetch['items'] ) ) {
			return new WP_Error(
				'invalid_fetch_result',
				__( 'Data callback returned an invalid result.', 'arraypress' ),
				[ 'status' => 500 ]
			);
		}

		$items    = $fetch['items'] ?? [];
		$has_more = $fetch['has_more'] ?? false;
		$cursor   = $fetch['cursor'] ?? '';
		$total    = $fetch['total'] ?? null;

		// Extract names before caching (for progress display)
		$name_callback = $sync->get_name_callback();
		$named_items   = [];

		foreach ( $items as $item ) {
			$named_items[] = [
				'data' => $item,
				'name' => $name_callback
					? (string) call_user_func( $name_callback, $item )
					: self::guess_item_name( $item ),
			];
		}

		// Store in transient for processing
		$transient_key = self::get_transient_key( $sync_id );

		set_transient( $transient_key, [
			'items'  => $named_items,
			'offset' => 0,
		], self::TRANSIENT_TTL );

		return new WP_REST_Response( [
			'success'  => true,
			'fetched'  => count( $items ),
			'has_more' => $has_more,
			'cursor'   => $cursor,
			'total'    => $total,
		], 200 );
	}

	// =========================================================================
	// Phase 2: Process
	// =========================================================================

	/**
	 * Handle a process request.
	 *
	 * Pulls the next chunk of items from the transient, processes each
	 * through the process_callback, advances the offset, and returns
	 * per-item results so JS can update the progress bar after each chunk.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_process( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$sync_id = $request->get_param( 'sync_id' );

		$sync = Registry::instance()->get( $sync_id );

		if ( ! $sync ) {
			return new WP_Error(
				'invalid_sync',
				__( 'Sync registration not found.', 'arraypress' ),
				[ 'status' => 400 ]
			);
		}

		$process_callback = $sync->get_process_callback();

		if ( ! $process_callback ) {
			return new WP_Error(
				'no_process_callback',
				__( 'No process callback defined.', 'arraypress' ),
				[ 'status' => 500 ]
			);
		}

		// Load cached items
		$transient_key = self::get_transient_key( $sync_id );
		$cache         = get_transient( $transient_key );

		if ( ! $cache || empty( $cache['items'] ) ) {
			return new WP_Error(
				'no_cached_items',
				__( 'No items to process. Fetch first.', 'arraypress' ),
				[ 'status' => 400 ]
			);
		}

		$all_items = $cache['items'];
		$offset    = $cache['offset'] ?? 0;
		$chunk     = array_slice( $all_items, $offset, self::CHUNK_SIZE );

		if ( empty( $chunk ) ) {
			// All items in this page processed — clean up
			delete_transient( $transient_key );

			return new WP_REST_Response( [
				'success'   => true,
				'processed' => 0,
				'created'   => 0,
				'updated'   => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'items'     => [],
				'page_done' => true,
				'remaining' => 0,
			], 200 );
		}

		// Process the chunk
		$results = [
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'processed' => 0,
			'items'     => [],
		];

		foreach ( $chunk as $entry ) {
			$item      = $entry['data'];
			$item_name = $entry['name'];

			$results['processed'] ++;

			try {
				$result = call_user_func( $process_callback, $item );

				if ( is_wp_error( $result ) ) {
					$results['failed'] ++;
					$results['items'][] = [
						'name'   => $item_name,
						'status' => 'failed',
						'error'  => $result->get_error_message(),
					];
				} elseif ( in_array( $result, [ 'created', 'updated', 'skipped' ], true ) ) {
					$results[ $result ] ++;
					$results['items'][] = [
						'name'   => $item_name,
						'status' => $result,
					];
				} else {
					$results['created'] ++;
					$results['items'][] = [
						'name'   => $item_name,
						'status' => 'created',
					];
				}
			} catch ( Exception $e ) {
				$results['failed'] ++;
				$results['items'][] = [
					'name'   => $item_name,
					'status' => 'failed',
					'error'  => $e->getMessage(),
				];
			}
		}

		// Advance offset
		$new_offset = $offset + self::CHUNK_SIZE;
		$remaining  = max( 0, count( $all_items ) - $new_offset );

		if ( $remaining > 0 ) {
			// More items in this page — update transient
			set_transient( $transient_key, [
				'items'  => $all_items,
				'offset' => $new_offset,
			], self::TRANSIENT_TTL );
		} else {
			// Page exhausted — clean up
			delete_transient( $transient_key );
		}

		return new WP_REST_Response( [
			'success'   => true,
			'processed' => $results['processed'],
			'created'   => $results['created'],
			'updated'   => $results['updated'],
			'skipped'   => $results['skipped'],
			'failed'    => $results['failed'],
			'items'     => $results['items'],
			'page_done' => $remaining === 0,
			'remaining' => $remaining,
		], 200 );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Build the transient key for a sync operation.
	 *
	 * Scoped to the current user to prevent collisions when
	 * multiple admins sync simultaneously.
	 *
	 * @since 1.0.0
	 *
	 * @param string $sync_id Sync ID.
	 *
	 * @return string Transient key.
	 */
	private static function get_transient_key( string $sync_id ): string {
		$user_id = get_current_user_id();

		return 'inline_sync_' . $sync_id . '_' . $user_id;
	}

	/**
	 * Guess a display name from an item.
	 *
	 * Tries common name fields on objects and arrays. Used as
	 * a fallback when no name_callback is provided.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $item Item from the data callback.
	 *
	 * @return string Display name or empty string.
	 */
	private static function guess_item_name( mixed $item ): string {
		$fields = [ 'name', 'title', 'label', 'product_name', 'email', 'id' ];

		if ( is_object( $item ) ) {
			foreach ( $fields as $field ) {
				if ( isset( $item->$field ) && $item->$field !== '' ) {
					return (string) $item->$field;
				}
			}
		}

		if ( is_array( $item ) ) {
			foreach ( $fields as $field ) {
				if ( ! empty( $item[ $field ] ) ) {
					return (string) $item[ $field ];
				}
			}
		}

		return '';
	}

}