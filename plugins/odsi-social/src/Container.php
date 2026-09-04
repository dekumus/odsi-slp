<?php
/**
 * Minimal service container.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * A deliberately small service locator.
 *
 * Services are registered as factories and resolved lazily, so a request that
 * only touches the front end never instantiates the admin or REST services.
 */
final class Container {

	/**
	 * Registered factories, keyed by service id.
	 *
	 * @var array<string, callable(self): object>
	 */
	private array $factories = array();

	/**
	 * Already resolved instances, keyed by service id.
	 *
	 * @var array<string, object>
	 */
	private array $resolved = array();

	/**
	 * Ids currently being resolved, used to detect circular dependencies.
	 *
	 * @var array<string, true>
	 */
	private array $resolving = array();

	/**
	 * Register a factory for a service id.
	 *
	 * @param string                 $id      Service id, conventionally a class name.
	 * @param callable(self): object $factory Builds the service.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;

		unset( $this->resolved[ $id ] );
	}

	/**
	 * Whether a factory is registered for the given id.
	 *
	 * @param string $id Service id.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Resolve a service, building it on first use.
	 *
	 * @param string $id Service id.
	 *
	 * @throws InvalidArgumentException When the id is unknown or circular.
	 */
	public function get( string $id ): object {
		if ( isset( $this->resolved[ $id ] ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new InvalidArgumentException(
				esc_html( sprintf( 'ODSI Social: no service registered for "%s".', $id ) )
			);
		}

		if ( isset( $this->resolving[ $id ] ) ) {
			throw new InvalidArgumentException(
				esc_html( sprintf( 'ODSI Social: circular dependency while resolving "%s".', $id ) )
			);
		}

		$this->resolving[ $id ] = true;

		try {
			$this->resolved[ $id ] = ( $this->factories[ $id ] )( $this );
		} finally {
			unset( $this->resolving[ $id ] );
		}

		return $this->resolved[ $id ];
	}

	/**
	 * All registered service ids.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_keys( $this->factories );
	}
}
