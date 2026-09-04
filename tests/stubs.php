<?php
/**
 * Enough WordPress to register a sync against.
 *
 * Every declaration is guarded, because wp-composer-assets is a real
 * dependency and ships its own arraypress_enqueue_composer_* functions --
 * redeclaring one is a fatal in the bootstrap, which reads as the suite being
 * broken. This file loads before the autoloader, so these stubs win.
 *
 * @package ArrayPress\InlineSync
 */

declare( strict_types=1 );

// phpcs:disable

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public array $data = [] ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params = [];
		public function __construct( array $params = [] ) { $this->params = $params; }
		public function get_param( $key ) { return $this->params[ $key ] ?? null; }
		public function set_param( $key, $value ) { $this->params[ $key ] = $value; }
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public function __construct( public $data = null, public int $status = 200 ) {}
		public function get_data() { return $this->data; }
		public function get_status() { return $this->status; }
	}
}

/**
 * Empty everything a test might have filled.
 */
function is_reset_globals(): void {
	$GLOBALS['is_actions']    = [];
	$GLOBALS['is_scripts']    = [];
	$GLOBALS['is_inline']     = [];
	$GLOBALS['is_routes']     = [];
	$GLOBALS['is_transients'] = [];
	$GLOBALS['is_caps']       = [ 'manage_options' => true ];
	$GLOBALS['is_user_id']    = 1;
}

is_reset_globals();

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['is_actions'][ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$rest ) { return $value; }
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		foreach ( $GLOBALS['is_actions'][ $hook ] ?? [] as $cb ) { $cb( ...$args ); }
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) { return 1; }
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = [] ) {
		$GLOBALS['is_routes'][ $namespace . $route ] = $args;
		return true;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ); }
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) { return 'nonce-' . $action; }
}

if ( ! function_exists( 'arraypress_enqueue_composer_style' ) ) {
	function arraypress_enqueue_composer_style( $handle, $file, $path, $deps = [] ) { $GLOBALS['is_scripts'][ $handle ] = true; }
}

if ( ! function_exists( 'arraypress_enqueue_composer_script' ) ) {
	function arraypress_enqueue_composer_script( $handle, $file, $path, $deps = [] ) { $GLOBALS['is_scripts'][ $handle ] = true; }
}

if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $handle, $list = 'enqueued' ) { return isset( $GLOBALS['is_scripts'][ $handle ] ); }
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( $handle, $data, $position = 'after' ) {
		$GLOBALS['is_inline'][ $handle ][] = $data;
		return true;
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $name, $data ) {
		$GLOBALS['is_inline'][ $handle ][] = $name;
		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return $GLOBALS['is_user_id']; }
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) { return ! empty( $GLOBALS['is_caps'][ $cap ] ); }
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['is_transients'][ $key ] = $value; return true; }
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) { return $GLOBALS['is_transients'][ $key ] ?? false; }
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) { unset( $GLOBALS['is_transients'][ $key ] ); return true; }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return (string) $url; }
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) { return $text; }
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) { return $text; }
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = [] ) { return array_merge( $defaults, (array) $args ); }
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		return preg_replace( '|(?<=.)/+|', '/', $path );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) { return rtrim( (string) $string, '/\\' ) . '/'; }
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $string ) { return rtrim( (string) $string, '/\\' ); }
}

if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) { return 'https://example.test/wp-content/plugins/' . ltrim( (string) $path, '/' ); }
}

if ( ! function_exists( 'content_url' ) ) {
	function content_url( $path = '' ) { return 'https://example.test/wp-content/' . ltrim( (string) $path, '/' ); }
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '', $scheme = null ) { return 'https://example.test/' . ltrim( (string) $path, '/' ); }
}

if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( ...$args ) { return true; }
}

if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( ...$args ) { return true; }
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, ...$rest ) { $GLOBALS['is_scripts'][ $handle ] = true; return true; }
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, ...$rest ) { $GLOBALS['is_scripts'][ $handle ] = true; return true; }
}

if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( $handle, $list = 'enqueued' ) { return isset( $GLOBALS['is_scripts'][ $handle ] ); }
}
