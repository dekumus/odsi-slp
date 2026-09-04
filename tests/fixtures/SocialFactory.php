<?php
/**
 * Social fixture factory.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Fixtures;

use ODSI\Social\Activity\Activity;
use ODSI\Social\Connections\Connections;
use ODSI\Social\Connections\Follows;
use ODSI\Social\Groups\Groups;
use ODSI\Social\Groups\Membership;
use ODSI\Social\Plugin;
use WP_UnitTest_Factory;

/**
 * Builds community worlds for integration tests.
 */
final class SocialFactory {

	/**
	 * Constructor.
	 *
	 * @param WP_UnitTest_Factory $factory Core factory.
	 */
	public function __construct( private WP_UnitTest_Factory $factory ) {
	}

	/**
	 * Resolve a service.
	 *
	 * @template T of object
	 * @param class-string<T> $id Service id.
	 * @return T
	 */
	public function service( string $id ): object {
		return Plugin::instance()->container()->get( $id );
	}

	/**
	 * A subscriber.
	 */
	public function member( string $nicename = '' ): int {
		$args = array( 'role' => 'subscriber' );

		if ( '' !== $nicename ) {
			$args['user_login']    = $nicename;
			$args['user_nicename'] = $nicename;
		}

		return $this->factory->user->create( $args );
	}

	/**
	 * An administrator.
	 */
	public function admin(): int {
		return $this->factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Connect two members.
	 */
	public function connect( int $a, int $b ): void {
		$connections = $this->service( Connections::class );
		$connections->request( $a, $b );
		$connections->accept( $b, $a );
	}

	/**
	 * Make one member follow another.
	 */
	public function follow( int $follower, int $following ): void {
		$this->service( Follows::class )->follow( $follower, $following );
	}

	/**
	 * A group with its creator as organiser.
	 */
	public function group( int $creator, string $visibility = 'public', string $name = '' ): int {
		$result = $this->service( Groups::class )->create(
			$creator,
			array(
				'name'       => '' !== $name ? $name : 'Group ' . wp_rand( 1000, 9999 ),
				'visibility' => $visibility,
			)
		);

		if ( $result instanceof \WP_Error ) {
			throw new \RuntimeException( $result->get_error_message() );
		}

		return $result;
	}

	/**
	 * Add an active member to a group directly (bypassing the state machine).
	 */
	public function add_to_group( int $group_id, int $user_id, string $role = 'member' ): void {
		$repo = $this->service( \ODSI\Social\Repositories\GroupMemberRepository::class );
		$repo->put( $group_id, $user_id, $role, 'active' );
		$this->service( \ODSI\Social\Repositories\GroupRepository::class )->adjust( $group_id, 'member_count', 1 );
	}

	/**
	 * Post an update and return its id.
	 */
	public function update( int $author, string $content = 'Hello', string $privacy = 'public', int $group_id = 0 ): int {
		$item = $this->service( Activity::class )->post_update( $author, $content, $privacy, $group_id );

		if ( $item instanceof \WP_Error ) {
			throw new \RuntimeException( $item->get_error_message() );
		}

		return (int) $item->id;
	}

	/**
	 * Comment and return its id.
	 */
	public function comment( int $author, int $parent_id, string $content = 'Nice' ): int {
		$item = $this->service( Activity::class )->comment( $author, $parent_id, $content );

		if ( $item instanceof \WP_Error ) {
			throw new \RuntimeException( $item->get_error_message() );
		}

		return (int) $item->id;
	}
}
