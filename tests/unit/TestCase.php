<?php
/**
 * Base class for unit tests.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Sets Brain Monkey up and down and stubs the always-safe WordPress helpers.
 */
abstract class TestCase extends PHPUnitTestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Start Brain Monkey and stub escaping and translation functions.
	 */
	protected function setUp(): void {
		parent::setUp();

		Monkey\setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'absint' )->alias( static fn ( $value ): int => abs( (int) $value ) );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
	}

	/**
	 * Tear Brain Monkey down.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();

		parent::tearDown();
	}

	/**
	 * Stub `get_post_meta()` from a nested map of post id => meta key => value.
	 *
	 * @param array<int, array<string, mixed>> $meta Meta values.
	 */
	protected function stub_post_meta( array $meta ): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key = '', bool $single = false ) use ( $meta ) {
				if ( '' === $key ) {
					return $meta[ $post_id ] ?? array();
				}

				return $meta[ $post_id ][ $key ] ?? ( $single ? '' : array() );
			}
		);
	}
}
