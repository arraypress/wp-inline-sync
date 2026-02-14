<?php
/**
 * Sync Registry
 *
 * Singleton registry for managing inline sync registrations.
 * Each registration represents a single sync operation bound
 * to one or more admin screens.
 *
 * @package     ArrayPress\InlineSync
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\InlineSync;

/**
 * Class Registry
 *
 * Central store for all registered sync configurations.
 *
 * @since 1.0.0
 */
final class Registry {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var Registry|null
	 */
	private static ?Registry $instance = null;

	/**
	 * Registered sync instances keyed by ID.
	 *
	 * @since 1.0.0
	 * @var array<string, Sync>
	 */
	private array $syncs = [];

	/**
	 * Get singleton instance.
	 *
	 * @return Registry
	 * @since 1.0.0
	 *
	 */
	public static function instance(): Registry {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor for singleton.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
	}

	/**
	 * Register a sync instance.
	 *
	 * @param string $id   Unique identifier.
	 * @param Sync   $sync Sync instance.
	 *
	 * @since 1.0.0
	 *
	 */
	public static function register( string $id, Sync $sync ): void {
		self::instance()->syncs[ $id ] = $sync;
	}

	/**
	 * Get a registered sync instance.
	 *
	 * @param string $id Sync ID.
	 *
	 * @return Sync|null
	 * @since 1.0.0
	 *
	 */
	public function get( string $id ): ?Sync {
		return $this->syncs[ $id ] ?? null;
	}

	/**
	 * Check if a sync is registered.
	 *
	 * @param string $id Sync ID.
	 *
	 * @return bool
	 * @since 1.0.0
	 *
	 */
	public function has( string $id ): bool {
		return isset( $this->syncs[ $id ] );
	}

	/**
	 * Get all registered sync instances.
	 *
	 * @return array<string, Sync>
	 * @since 1.0.0
	 *
	 */
	public function all(): array {
		return $this->syncs;
	}

	/**
	 * Get all syncs registered for a specific hook suffix.
	 *
	 * @param string $hook_suffix Admin screen hook suffix.
	 *
	 * @return array<string, Sync>
	 * @since 1.0.0
	 *
	 */
	public function get_for_screen( string $hook_suffix ): array {
		$matches = [];

		foreach ( $this->syncs as $id => $sync ) {
			if ( $sync->is_target_screen( $hook_suffix ) ) {
				$matches[ $id ] = $sync;
			}
		}

		return $matches;
	}

	/**
	 * Unregister a sync instance.
	 *
	 * @param string $id Sync ID.
	 *
	 * @return bool True if removed, false if not found.
	 * @since 1.0.0
	 *
	 */
	public function unregister( string $id ): bool {
		if ( isset( $this->syncs[ $id ] ) ) {
			unset( $this->syncs[ $id ] );

			return true;
		}

		return false;
	}

}
