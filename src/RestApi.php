<?php
/**
 * REST API
 *
 * Single REST endpoint for inline sync batch processing. Routes
 * requests to the correct sync registration using the sync_id
 * parameter. One endpoint serves all registered syncs.
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
 * Single REST endpoint for all inline sync operations.
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
		register_rest_route( self::NAMESPACE, '/batch', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'handle_batch' ],
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
	}

	/**
	 * Check if the current user has permission.
	 *
	 * Resolves the sync registration and checks against its
	 * configured capability.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return bool|WP_Error
	 * @since 1.0.0
	 *
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

	/**
	 * Handle a sync batch request.
	 *
	 * Fetches items via the data_callback, processes each through
	 * the process_callback, extracts display names, and returns
	 * aggregated results with cursor for pagination.
	 *
	 * The data_callback must return:
	 *   - items    (array)    Items to process.
	 *   - has_more (bool)     Whether more items exist.
	 *   - cursor   (string)   Cursor for next batch.
	 *   - total    (int|null) Total items if known.
	 *
	 * The process_callback must return one of:
	 *   - 'created'  Item was newly created.
	 *   - 'updated'  Item was updated.
	 *   - 'skipped'  Item was skipped.
	 *   - WP_Error   Item failed with error message.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.0.0
	 *
	 */
	public static function handle_batch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$sync_id = $request->get_param( 'sync_id' );
		$cursor  = $request->get_param( 'cursor' );

		// Resolve sync
		$sync = Registry::instance()->get( $sync_id );

		if ( ! $sync ) {
			return new WP_Error(
				'invalid_sync',
				__( 'Sync registration not found.', 'arraypress' ),
				[ 'status' => 400 ]
			);
		}

		// Validate callbacks
		$data_callback    = $sync->get_data_callback();
		$process_callback = $sync->get_process_callback();
		$name_callback    = $sync->get_name_callback();

		if ( ! $data_callback ) {
			return new WP_Error(
				'no_data_callback',
				__( 'No data callback defined.', 'arraypress' ),
				[ 'status' => 500 ]
			);
		}

		if ( ! $process_callback ) {
			return new WP_Error(
				'no_process_callback',
				__( 'No process callback defined.', 'arraypress' ),
				[ 'status' => 500 ]
			);
		}

		// Fetch items
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

		$items      = $fetch['items'] ?? [];
		$has_more   = $fetch['has_more'] ?? false;
		$new_cursor = $fetch['cursor'] ?? '';
		$total      = $fetch['total'] ?? null;

		// Process items
		$results = [
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'processed' => 0,
			'items'     => [],
		];

		foreach ( $items as $item ) {
			$results['processed'] ++;

			// Extract display name
			$item_name = $name_callback
				? (string) call_user_func( $name_callback, $item )
				: self::guess_item_name( $item );

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
					// Truthy = assume created
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

		return new WP_REST_Response( [
			'success'   => true,
			'processed' => $results['processed'],
			'created'   => $results['created'],
			'updated'   => $results['updated'],
			'skipped'   => $results['skipped'],
			'failed'    => $results['failed'],
			'items'     => $results['items'],
			'has_more'  => $has_more,
			'cursor'    => $new_cursor,
			'total'     => $total,
		], 200 );
	}

	/**
	 * Guess a display name from an item.
	 *
	 * Tries common name fields on objects and arrays. Used as
	 * a fallback when no name_callback is provided.
	 *
	 * @param mixed $item Item from the data callback.
	 *
	 * @return string Display name or empty string.
	 * @since 1.0.0
	 *
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
