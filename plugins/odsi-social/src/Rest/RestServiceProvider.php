<?php
/**
 * REST API wiring.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Rest;

use ODSI\Social\Activity\Activity;
use ODSI\Social\Activity\Feed;
use ODSI\Social\Activity\Reactions;
use ODSI\Social\Connections\Connections;
use ODSI\Social\Connections\Follows;
use ODSI\Social\Container;
use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Groups\Groups;
use ODSI\Social\Groups\Membership;
use ODSI\Social\Members\Directory;
use ODSI\Social\Members\Profiles;
use ODSI\Social\Messages\Messages;
use ODSI\Social\Notifications\Notifications;
use ODSI\Social\Repositories\GroupMemberRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every controller under `odsi-social/v1`.
 */
final class RestServiceProvider implements Bootable {

	public const NAMESPACE = 'odsi-social/v1';

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( private Container $container ) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Instantiate and register the controllers.
	 */
	public function register_routes(): void {
		$c = $this->container;

		$controllers = array(
			new MembersController( $c->get( Directory::class ), $c->get( Profiles::class ) ),
			new ActivityController( $c->get( Activity::class ), $c->get( Feed::class ), $c->get( Reactions::class ) ),
			new GroupsController( $c->get( Groups::class ), $c->get( Membership::class ), $c->get( GroupMemberRepository::class ), $c->get( \ODSI\Social\Repositories\GroupRepository::class ) ),
			new ConnectionsController( $c->get( Connections::class ), $c->get( Follows::class ) ),
			new NotificationsController( $c->get( Notifications::class ) ),
			new MessagesController( $c->get( Messages::class ) ),
		);

		/**
		 * Filters the REST controllers registered by the community plugin.
		 *
		 * @param object[]  $controllers Controllers exposing `register_routes()`.
		 * @param Container $container   Container.
		 */
		$controllers = (array) apply_filters( 'odsi_social_rest_controllers', $controllers, $c );

		foreach ( $controllers as $controller ) {
			if ( method_exists( $controller, 'register_routes' ) ) {
				$controller->register_routes();
			}
		}
	}

	/**
	 * Shared permission callback: logged in.
	 */
	public static function logged_in(): bool {
		return is_user_logged_in();
	}

	/**
	 * Convert a service result to a response, mapping WP_Error status data.
	 *
	 * @param mixed $result  Service result.
	 * @param int   $success Status for success.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function respond( mixed $result, int $success = 200 ): \WP_REST_Response|\WP_Error {
		if ( $result instanceof \WP_Error ) {
			$data = (array) $result->get_error_data();

			if ( empty( $data['status'] ) ) {
				$result->add_data( array( 'status' => 400 ) );
			}

			return $result;
		}

		return new \WP_REST_Response( $result, $success );
	}

	/**
	 * Integer route argument definition.
	 *
	 * @return array<string, mixed>
	 */
	public static function int_arg(): array {
		return array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
		);
	}
}
