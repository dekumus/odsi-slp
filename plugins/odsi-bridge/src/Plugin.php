<?php
/**
 * Bridge kernel.
 *
 * @package ODSI\Bridge
 */

declare( strict_types = 1 );

namespace ODSI\Bridge;

use ODSI\Bridge\Admin\SettingsScreen;
use ODSI\Bridge\Contracts\Bootable;
use ODSI\Bridge\Database\Migrator;
use ODSI\Bridge\Modules\CourseActivity;
use ODSI\Bridge\Modules\GroupLinkage;
use ODSI\Bridge\Modules\ProgressVisibility;
use ODSI\Bridge\Repositories\LinkRepository;
use ODSI\Bridge\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the three integration modules. Each resolves the other plugins'
 * services from their containers lazily, at first use inside a hook, never
 * at construction: both plugins are guaranteed loaded by then, and the
 * bridge stays free of hard references at boot (the documented exception
 * to the no-service-locator rule in docs/02-conventions.md).
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Booted flag.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->container = new Container();

		$c = $this->container;

		$c->set( LinkRepository::class, static fn (): object => new LinkRepository() );
		$c->set( Settings::class, static fn (): object => new Settings() );
		$c->set( CourseActivity::class, static fn ( Container $c ): object => new CourseActivity( $c->get( LinkRepository::class ), $c->get( Settings::class ) ) );
		$c->set( GroupLinkage::class, static fn ( Container $c ): object => new GroupLinkage( $c->get( LinkRepository::class ), $c->get( Settings::class ) ) );
		$c->set( ProgressVisibility::class, static fn ( Container $c ): object => new ProgressVisibility( $c->get( LinkRepository::class ), $c->get( Settings::class ) ) );
		$c->set( SettingsScreen::class, static fn ( Container $c ): object => new SettingsScreen( $c->get( Settings::class ) ) );

		/**
		 * Fires after the bridge's services are registered.
		 *
		 * @param Container $container Container.
		 */
		do_action( 'odsi_bridge_register_services', $c );
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
	 * Container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Boot.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'admin_init', array( Migrator::class, 'maybe_migrate' ) );

		$services = array( CourseActivity::class, GroupLinkage::class, ProgressVisibility::class );

		if ( is_admin() ) {
			$services[] = SettingsScreen::class;
		}

		/**
		 * Filters the bridge services booted on this request.
		 *
		 * @param string[] $services Container ids.
		 */
		foreach ( (array) apply_filters( 'odsi_bridge_bootable_services', $services ) as $id ) {
			$service = $this->container->get( $id );

			if ( $service instanceof Bootable ) {
				$service->boot();
			}
		}

		/**
		 * Fires once the bridge has booted.
		 *
		 * @param Plugin $plugin Plugin.
		 */
		do_action( 'odsi_bridge_booted', $this );
	}
}
