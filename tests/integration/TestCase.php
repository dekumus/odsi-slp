<?php
/**
 * Base class for integration tests.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration;

use ODSI\Tests\Fixtures\LmsFactory;
use ODSI\Tests\Fixtures\SocialFactory;
use WP_UnitTestCase;

/**
 * Adds LMS fixtures and small helpers to the WordPress core test case.
 *
 * Every test runs inside a database transaction that the core framework rolls
 * back, so custom-table rows written here vanish with the test.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * LMS fixture factory for the current test.
	 */
	protected LmsFactory $lms;

	/**
	 * Social fixture factory for the current test.
	 */
	protected SocialFactory $social;

	/**
	 * Set up fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		// The core framework unregisters every meta key in tear_down (its
		// `unregister_all_meta_keys`), but plugins register at `init`, which
		// fires once per process. Re-register so REST meta writes and
		// auth_callbacks behave in every test, not only the first.
		foreach ( array( '\\ODSI\\LMS\\Support\\Meta', '\\ODSI\\Social\\Support\\Meta' ) as $meta ) {
			if ( class_exists( $meta ) && method_exists( $meta, 'register' ) ) {
				$meta::register();
			}
		}

		$this->lms    = new LmsFactory( $this->factory() );
		$this->social = new SocialFactory( $this->factory() );
	}

	/**
	 * Run a callback as a given user, restoring the previous user afterwards.
	 *
	 * @param int      $user_id  User to impersonate.
	 * @param callable $callback Work to do.
	 *
	 * @return mixed The callback's return value.
	 */
	protected function as_user( int $user_id, callable $callback ): mixed {
		$previous = get_current_user_id();

		wp_set_current_user( $user_id );

		try {
			return $callback();
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * Dispatch a REST request and return the response.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route including the namespace, e.g. `/odsi-lms/v1/...`.
	 * @param array<string, mixed> $params Body or query parameters.
	 */
	protected function rest( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		return rest_get_server()->dispatch( $request );
	}
}
