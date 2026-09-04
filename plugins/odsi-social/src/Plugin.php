<?php
/**
 * Plugin kernel.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social;

use ODSI\Social\Activity\Activity;
use ODSI\Social\Activity\Feed;
use ODSI\Social\Activity\Mentions;
use ODSI\Social\Activity\Privacy;
use ODSI\Social\Activity\Reactions;
use ODSI\Social\Activity\Renderers as ActivityRenderers;
use ODSI\Social\Admin\AdminMenu;
use ODSI\Social\Connections\Connections;
use ODSI\Social\Connections\Follows;
use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Database\Migrator;
use ODSI\Social\Frontend\Router;
use ODSI\Social\Frontend\Shortcodes;
use ODSI\Social\Frontend\Templates;
use ODSI\Social\Groups\GroupActivity;
use ODSI\Social\Groups\Groups;
use ODSI\Social\Groups\Membership;
use ODSI\Social\Members\Directory;
use ODSI\Social\Members\Lifecycle;
use ODSI\Social\Members\Presence;
use ODSI\Social\Members\ProfileFields;
use ODSI\Social\Members\Profiles;
use ODSI\Social\Messages\Messages;
use ODSI\Social\Notifications\Listeners;
use ODSI\Social\Notifications\Notifications;
use ODSI\Social\Notifications\Renderers as NotificationRenderers;
use ODSI\Social\PostTypes\GroupPostType;
use ODSI\Social\Repositories\ActivityMetaRepository;
use ODSI\Social\Repositories\ActivityRepository;
use ODSI\Social\Repositories\ConnectionRepository;
use ODSI\Social\Repositories\FollowRepository;
use ODSI\Social\Repositories\GroupMemberRepository;
use ODSI\Social\Repositories\GroupRepository;
use ODSI\Social\Repositories\MemberRepository;
use ODSI\Social\Repositories\MessageRepository;
use ODSI\Social\Repositories\NotificationRepository;
use ODSI\Social\Repositories\ProfileDataRepository;
use ODSI\Social\Repositories\ProfileFieldRepository;
use ODSI\Social\Repositories\ReactionRepository;
use ODSI\Social\Repositories\ThreadRepository;
use ODSI\Social\Rest\RestServiceProvider;
use ODSI\Social\Support\Assets;
use ODSI\Social\Support\Meta;
use ODSI\Social\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's services together and boots them.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private constructor; use `instance()`.
	 */
	private function __construct() {
		$this->container = new Container();

		$this->register_services();
	}

	/**
	 * Shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * The service container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Absolute plugin path with trailing slash.
	 */
	public static function path(): string {
		return plugin_dir_path( PLUGIN_FILE );
	}

	/**
	 * Public plugin URL with trailing slash.
	 */
	public static function url(): string {
		return plugin_dir_url( PLUGIN_FILE );
	}

	/**
	 * Register every service factory.
	 */
	private function register_services(): void {
		$c = $this->container;

		// Repositories.
		foreach (
			array(
				MemberRepository::class,
				GroupRepository::class,
				GroupMemberRepository::class,
				ProfileFieldRepository::class,
				ProfileDataRepository::class,
				ConnectionRepository::class,
				FollowRepository::class,
				ActivityRepository::class,
				ActivityMetaRepository::class,
				ReactionRepository::class,
				NotificationRepository::class,
				ThreadRepository::class,
				MessageRepository::class,
			) as $repository
		) {
			$c->set( $repository, static fn (): object => new $repository() );
		}

		// Support.
		$c->set( Settings::class, static fn (): object => new Settings() );
		$c->set( Router::class, static fn ( Container $c ): object => new Router( $c->get( Settings::class ) ) );
		$c->set( Templates::class, static fn (): object => new Templates() );
		$c->set( Assets::class, static fn ( Container $c ): object => new Assets( $c->get( Router::class ) ) );
		$c->set( GroupPostType::class, static fn (): object => new GroupPostType() );

		// Activity.
		$c->set( ActivityRenderers::class, static fn (): object => new ActivityRenderers() );
		$c->set(
			Privacy::class,
			static fn ( Container $c ): object => new Privacy(
				$c->get( ConnectionRepository::class ),
				$c->get( GroupMemberRepository::class ),
				$c->get( GroupRepository::class ),
				$c->get( ActivityRepository::class )
			)
		);
		$c->set(
			Activity::class,
			static fn ( Container $c ): object => new Activity(
				$c->get( ActivityRepository::class ),
				$c->get( ActivityMetaRepository::class ),
				$c->get( ReactionRepository::class ),
				$c->get( GroupMemberRepository::class ),
				$c->get( GroupRepository::class ),
				$c->get( MemberRepository::class ),
				$c->get( Privacy::class ),
				$c->get( Settings::class )
			)
		);
		$c->set(
			Feed::class,
			static fn ( Container $c ): object => new Feed(
				$c->get( ActivityRepository::class ),
				$c->get( ReactionRepository::class ),
				$c->get( MemberRepository::class ),
				$c->get( Privacy::class ),
				$c->get( ActivityRenderers::class ),
				$c->get( Settings::class )
			)
		);
		$c->set(
			Reactions::class,
			static fn ( Container $c ): object => new Reactions(
				$c->get( ReactionRepository::class ),
				$c->get( ActivityRepository::class ),
				$c->get( Privacy::class )
			)
		);
		$c->set( Mentions::class, static fn ( Container $c ): object => new Mentions( $c->get( Privacy::class ) ) );

		// Connections.
		$c->set( Connections::class, static fn ( Container $c ): object => new Connections( $c->get( ConnectionRepository::class ), $c->get( MemberRepository::class ) ) );
		$c->set( Follows::class, static fn ( Container $c ): object => new Follows( $c->get( FollowRepository::class ), $c->get( MemberRepository::class ) ) );

		// Groups.
		$c->set(
			Groups::class,
			static fn ( Container $c ): object => new Groups(
				$c->get( GroupRepository::class ),
				$c->get( GroupMemberRepository::class ),
				$c->get( ActivityRepository::class ),
				$c->get( Activity::class ),
				$c->get( Settings::class )
			)
		);
		$c->set( GroupActivity::class, static fn ( Container $c ): object => new GroupActivity( $c->get( Activity::class ), $c->get( Groups::class ), $c->get( ActivityRenderers::class ) ) );
		$c->set(
			Membership::class,
			static fn ( Container $c ): object => new Membership(
				$c->get( GroupMemberRepository::class ),
				$c->get( GroupRepository::class ),
				$c->get( Groups::class )
			)
		);

		// Notifications.
		$c->set( NotificationRenderers::class, static fn (): object => new NotificationRenderers() );
		$c->set( Notifications::class, static fn ( Container $c ): object => new Notifications( $c->get( NotificationRepository::class ), $c->get( NotificationRenderers::class ) ) );
		$c->set(
			Listeners::class,
			static fn ( Container $c ): object => new Listeners(
				$c->get( Notifications::class ),
				$c->get( ActivityRepository::class ),
				$c->get( GroupMemberRepository::class )
			)
		);

		// Messages.
		$c->set(
			Messages::class,
			static fn ( Container $c ): object => new Messages(
				$c->get( ThreadRepository::class ),
				$c->get( MessageRepository::class ),
				$c->get( MemberRepository::class ),
				$c->get( Connections::class ),
				$c->get( Settings::class )
			)
		);

		// Members.
		$c->set( Presence::class, static fn ( Container $c ): object => new Presence( $c->get( MemberRepository::class ) ) );
		$c->set( ProfileFields::class, static fn ( Container $c ): object => new ProfileFields( $c->get( ProfileFieldRepository::class ) ) );
		$c->set(
			Profiles::class,
			static fn ( Container $c ): object => new Profiles(
				$c->get( MemberRepository::class ),
				$c->get( ProfileDataRepository::class ),
				$c->get( ProfileFields::class ),
				$c->get( Connections::class )
			)
		);
		$c->set( Directory::class, static fn ( Container $c ): object => new Directory( $c->get( MemberRepository::class ), $c->get( Profiles::class ), $c->get( Settings::class ) ) );
		$c->set(
			Lifecycle::class,
			static fn ( Container $c ): object => new Lifecycle(
				$c->get( Profiles::class ),
				$c->get( Connections::class ),
				$c->get( Follows::class ),
				$c->get( Membership::class ),
				$c->get( Notifications::class ),
				$c->get( ReactionRepository::class ),
				$c->get( ActivityRepository::class ),
				$c->get( Activity::class ),
				$c->get( Settings::class )
			)
		);

		// Interfaces.
		$c->set( Shortcodes::class, static fn ( Container $c ): object => new Shortcodes( $c ) );
		$c->set( RestServiceProvider::class, static fn ( Container $c ): object => new RestServiceProvider( $c ) );
		$c->set( AdminMenu::class, static fn ( Container $c ): object => new AdminMenu( $c->get( Settings::class ), $c->get( ProfileFields::class ) ) );
		$c->set( Maintenance::class, static fn ( Container $c ): object => new Maintenance( $c->get( Notifications::class ), $c->get( Messages::class ), $c->get( Settings::class ) ) );

		/**
		 * Fires after the plugin's own services are registered.
		 *
		 * @param Container $container Service container.
		 */
		do_action( 'odsi_social_register_services', $c );
	}

	/**
	 * Bootable services, in order.
	 *
	 * @return string[]
	 */
	private function bootable_services(): array {
		$services = array(
			GroupPostType::class,
			Router::class,
			Templates::class,
			Assets::class,
			Groups::class,
			GroupActivity::class,
			Mentions::class,
			Listeners::class,
			Presence::class,
			Profiles::class,
			Lifecycle::class,
			Shortcodes::class,
			RestServiceProvider::class,
			Maintenance::class,
		);

		if ( is_admin() ) {
			$services[] = AdminMenu::class;
		}

		/**
		 * Filters the services booted on this request.
		 *
		 * @param string[] $services Container ids implementing Bootable.
		 */
		return (array) apply_filters( 'odsi_social_bootable_services', $services );
	}

	/**
	 * Boot the plugin. Safe to call more than once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'admin_init', array( Migrator::class, 'maybe_migrate' ) );
		add_action( Installer::CRON_HOOK, array( Migrator::class, 'maybe_migrate' ), 1 );
		add_action( 'init', array( Meta::class, 'register' ), 6 );

		foreach ( $this->bootable_services() as $id ) {
			if ( ! $this->container->has( $id ) ) {
				continue;
			}

			$service = $this->container->get( $id );

			if ( $service instanceof Bootable ) {
				$service->boot();
			}
		}

		/**
		 * Fires once the community plugin has booted.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'odsi_social_booted', $this );
	}
}
