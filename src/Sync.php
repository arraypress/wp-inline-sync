<?php
/**
 * Sync Class
 *
 * Represents a single inline sync operation. Each registration is
 * one sync — no nested operations. Manages configuration, asset
 * loading, and provides a render helper for trigger buttons.
 *
 * @package     ArrayPress\InlineSync
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\InlineSync;

/**
 * Class Sync
 *
 * A single registered sync operation.
 *
 * @since 1.0.0
 */
final class Sync {

	/**
	 * Unique identifier for this sync.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $id;

	/**
	 * Configuration array.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $config;

	/**
	 * Default configuration values.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $defaults = [
		'hook_suffix'      => '',
		'capability'       => 'manage_options',
		'title'            => '',
		'button_label'     => 'Sync',
		'button_class'     => 'button',
		'container'        => '.wp-list-table',
		'data_callback'    => null,
		'process_callback' => null,
		'name_callback'    => null,
	];

	/**
	 * Constructor.
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
	 *     @type string       $container        CSS selector for the element the bar inserts before. Default '.wp-list-table'.
	 *     @type callable     $data_callback    Fetches items. Receives (string $cursor). Returns array with items, has_more, cursor, total.
	 *     @type callable     $process_callback Processes one item. Returns 'created'|'updated'|'skipped'|WP_Error.
	 *     @type callable     $name_callback    Extracts display name from an item. Receives (mixed $item). Returns string.
	 * }
	 */
	public function __construct( string $id, array $config ) {
		$this->id     = sanitize_key( $id );
		$this->config = wp_parse_args( $config, $this->defaults );

		Registry::register( $this->id, $this );
		RestApi::register();

		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
	}

	/**
	 * Conditionally enqueue assets on matching screens.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( ! $this->is_target_screen( $hook_suffix ) ) {
			return;
		}

		if ( ! current_user_can( $this->config['capability'] ) ) {
			return;
		}

		$this->enqueue_assets();
	}

	/**
	 * Check if the current screen matches a target hook suffix.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 *
	 * @return bool
	 */
	public function is_target_screen( string $hook_suffix ): bool {
		$targets = (array) $this->config['hook_suffix'];

		return in_array( $hook_suffix, $targets, true );
	}

	/**
	 * Enqueue JavaScript and CSS assets.
	 *
	 * Only enqueues once even if multiple syncs target the same screen.
	 * Each sync adds its config to the shared localized data.
	 *
	 * @since 1.0.0
	 */
	private function enqueue_assets(): void {
		$handle = 'inline-sync';

		// Enqueue shared assets once
		if ( ! wp_script_is( $handle, 'enqueued' ) ) {
			wp_enqueue_composer_style( $handle, __FILE__, 'css/inline-sync.css' );
			wp_enqueue_composer_script( $handle, __FILE__, 'js/inline-sync.js', [ 'jquery' ] );

			wp_localize_script( $handle, 'InlineSyncConfig', [
				'restUrl'   => rest_url( RestApi::NAMESPACE . '/' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'syncs'     => new \stdClass(),
				'i18n'      => $this->get_i18n_strings(),
			] );
		}

		// Add this sync's config to the JS data
		wp_add_inline_script( $handle, sprintf(
			'InlineSyncConfig.syncs[%s] = %s;',
			wp_json_encode( $this->id ),
			wp_json_encode( [
				'title'      => $this->config['title'],
				'container'  => $this->config['container'],
			] )
		), 'before' );
	}

	/**
	 * Get translatable strings for JavaScript.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function get_i18n_strings(): array {
		return [
			'fetching'      => __( 'Fetching...', 'arraypress' ),
			'syncing'       => __( 'Syncing...', 'arraypress' ),
			'cancel'        => __( 'Cancel', 'arraypress' ),
			'confirmCancel' => __( 'Cancel the current sync operation?', 'arraypress' ),
			'cancelled'     => __( 'Sync cancelled.', 'arraypress' ),
			'complete'      => __( 'Sync complete!', 'arraypress' ),
			'created'       => __( 'created', 'arraypress' ),
			'updated'       => __( 'updated', 'arraypress' ),
			'skipped'       => __( 'skipped', 'arraypress' ),
			'failed'        => __( 'failed', 'arraypress' ),
			'items'         => __( 'items', 'arraypress' ),
			'of'            => __( 'of', 'arraypress' ),
			'syncFailed'    => __( 'Sync failed. Please try again.', 'arraypress' ),
			'dismiss'       => __( 'Dismiss', 'arraypress' ),
		];
	}

	// =========================================================================
	// Render
	// =========================================================================

	/**
	 * Render a sync trigger button.
	 *
	 * Outputs a button with data attributes that the JS auto-binds to.
	 * No manual JavaScript calls needed.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args {
	 *     Optional. Override button attributes.
	 *
	 *     @type string $label     Button text. Default from config.
	 *     @type string $class     CSS classes. Default from config.
	 *     @type string $container Target container selector. Default from config.
	 * }
	 */
	public function render_button( array $args = [] ): void {
		if ( ! current_user_can( $this->config['capability'] ) ) {
			return;
		}

		$label     = $args['label'] ?? $this->config['button_label'];
		$class     = $args['class'] ?? $this->config['button_class'];
		$container = $args['container'] ?? $this->config['container'];

		printf(
			'<button type="button" class="%s inline-sync-trigger" data-sync-id="%s" data-container="%s">%s</button>',
			esc_attr( $class ),
			esc_attr( $this->id ),
			esc_attr( $container ),
			esc_html( $label )
		);
	}

	/**
	 * Get the sync trigger button HTML.
	 *
	 * Returns the button markup instead of echoing it.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Optional. Override button attributes. See render_button().
	 *
	 * @return string Button HTML.
	 */
	public function get_button( array $args = [] ): string {
		ob_start();
		$this->render_button( $args );

		return ob_get_clean();
	}

	// =========================================================================
	// Accessors
	// =========================================================================

	/**
	 * Get the sync ID.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Get a config value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Config key.
	 * @param mixed  $default Default value.
	 *
	 * @return mixed
	 */
	public function get_config( string $key, mixed $default = null ): mixed {
		return $this->config[ $key ] ?? $default;
	}

	/**
	 * Get the data callback.
	 *
	 * @since 1.0.0
	 *
	 * @return callable|null
	 */
	public function get_data_callback(): ?callable {
		$callback = $this->config['data_callback'];

		return is_callable( $callback ) ? $callback : null;
	}

	/**
	 * Get the process callback.
	 *
	 * @since 1.0.0
	 *
	 * @return callable|null
	 */
	public function get_process_callback(): ?callable {
		$callback = $this->config['process_callback'];

		return is_callable( $callback ) ? $callback : null;
	}

	/**
	 * Get the name callback.
	 *
	 * @since 1.0.0
	 *
	 * @return callable|null
	 */
	public function get_name_callback(): ?callable {
		$callback = $this->config['name_callback'];

		return is_callable( $callback ) ? $callback : null;
	}

}