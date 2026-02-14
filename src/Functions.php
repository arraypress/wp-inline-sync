<?php
/**
 * Global Helper Functions
 *
 * Convenience functions for registering, retrieving, and rendering
 * inline sync operations without touching the class API directly.
 *
 * @package     ArrayPress\InlineSync
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

use ArrayPress\InlineSync\Registry;
use ArrayPress\InlineSync\Sync;

if ( ! function_exists( 'register_sync' ) ) {
	/**
	 * Register an inline sync operation.
	 *
	 * Each call registers a single sync. Use a unique ID per sync
	 * (e.g., 'stripe_prices', 'stripe_customers'). Multiple syncs
	 * can target the same admin screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id     Unique identifier for this sync.
	 * @param array  $config {
	 *     Configuration array.
	 *
	 *     @type string|array $hook_suffix      Admin screen hook suffix(es) where assets load.
	 *     @type string       $capability       Required user capability. Default 'manage_options'.
	 *     @type string       $title            Display title shown in the progress bar.
	 *     @type string       $button_label     Button text. Default 'Sync'.
	 *     @type string       $button_class     CSS class for the button. Default 'button'.
	 *     @type string       $container        CSS selector the progress bar inserts before. Default '.wp-list-table'.
	 *     @type callable     $data_callback    Fetches items. Receives (string $cursor). Returns array with items, has_more, cursor, total.
	 *     @type callable     $process_callback Processes one item. Returns 'created'|'updated'|'skipped'|WP_Error.
	 *     @type callable     $name_callback    Extracts display name from an item. Receives (mixed $item). Returns string.
	 * }
	 *
	 * @return Sync The registered sync instance.
	 */
	function register_sync( string $id, array $config ): Sync {
		return new Sync( $id, $config );
	}
}

if ( ! function_exists( 'get_sync' ) ) {
	/**
	 * Get a registered sync instance.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Sync ID.
	 *
	 * @return Sync|null Sync instance or null if not found.
	 */
	function get_sync( string $id ): ?Sync {
		return Registry::instance()->get( $id );
	}
}

if ( ! function_exists( 'has_sync' ) ) {
	/**
	 * Check if a sync is registered.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Sync ID.
	 *
	 * @return bool
	 */
	function has_sync( string $id ): bool {
		return Registry::instance()->has( $id );
	}
}

if ( ! function_exists( 'render_sync_button' ) ) {
	/**
	 * Render a sync trigger button.
	 *
	 * Outputs a button with data attributes that the JS auto-binds
	 * to on click. No manual JavaScript calls needed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id   Sync ID.
	 * @param array  $args {
	 *     Optional. Override button attributes.
	 *
	 *     @type string $label     Button text.
	 *     @type string $class     CSS classes.
	 *     @type string $container Target container selector.
	 * }
	 */
	function render_sync_button( string $id, array $args = [] ): void {
		$sync = Registry::instance()->get( $id );

		if ( $sync ) {
			$sync->render_button( $args );
		}
	}
}

if ( ! function_exists( 'get_sync_button' ) ) {
	/**
	 * Get sync trigger button HTML.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id   Sync ID.
	 * @param array  $args Optional. Override button attributes.
	 *
	 * @return string Button HTML or empty string if sync not found.
	 */
	function get_sync_button( string $id, array $args = [] ): string {
		$sync = Registry::instance()->get( $id );

		return $sync ? $sync->get_button( $args ) : '';
	}
}
