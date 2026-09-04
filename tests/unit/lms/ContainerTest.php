<?php
/**
 * Container unit tests.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Unit\LMS;

use InvalidArgumentException;
use ODSI\LMS\Container;
use ODSI\Tests\Unit\TestCase;
use stdClass;

final class ContainerTest extends TestCase {

	public function test_resolves_lazily_and_once(): void {
		$container = new Container();
		$built     = 0;

		$container->set(
			'svc',
			static function () use ( &$built ): object {
				++$built;

				return new stdClass();
			}
		);

		self::assertSame( 0, $built, 'Registering must not build.' );
		self::assertTrue( $container->has( 'svc' ) );

		$first  = $container->get( 'svc' );
		$second = $container->get( 'svc' );

		self::assertSame( $first, $second );
		self::assertSame( 1, $built );
	}

	public function test_factories_receive_the_container(): void {
		$container = new Container();

		$container->set( 'a', static fn (): object => new stdClass() );
		$container->set( 'b', static fn ( Container $c ): object => (object) array( 'dep' => $c->get( 'a' ) ) );

		self::assertSame( $container->get( 'a' ), $container->get( 'b' )->dep );
	}

	public function test_re_registering_discards_the_resolved_instance(): void {
		$container = new Container();

		$container->set( 'svc', static fn (): object => (object) array( 'v' => 1 ) );
		self::assertSame( 1, $container->get( 'svc' )->v );

		$container->set( 'svc', static fn (): object => (object) array( 'v' => 2 ) );
		self::assertSame( 2, $container->get( 'svc' )->v );
	}

	public function test_unknown_id_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no service registered for' );

		( new Container() )->get( 'missing' );
	}

	public function test_circular_dependency_is_detected_not_infinite(): void {
		$container = new Container();

		$container->set( 'a', static fn ( Container $c ): object => $c->get( 'b' ) );
		$container->set( 'b', static fn ( Container $c ): object => $c->get( 'a' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'circular dependency' );

		$container->get( 'a' );
	}

	public function test_failed_resolution_does_not_poison_the_container(): void {
		$container = new Container();
		$attempts  = 0;

		$container->set(
			'flaky',
			static function () use ( &$attempts ): object {
				++$attempts;

				if ( 1 === $attempts ) {
					throw new \RuntimeException( 'first try fails' );
				}

				return new stdClass();
			}
		);

		$threw = false;

		try {
			$container->get( 'flaky' );
		} catch ( \RuntimeException ) {
			$threw = true;
		}

		self::assertTrue( $threw );

		self::assertInstanceOf( stdClass::class, $container->get( 'flaky' ), 'The resolving guard must be cleared after a failure.' );
	}

	public function test_ids_lists_everything_registered(): void {
		$container = new Container();

		$container->set( 'x', static fn (): object => new stdClass() );
		$container->set( 'y', static fn (): object => new stdClass() );

		self::assertSame( array( 'x', 'y' ), $container->ids() );
	}
}
