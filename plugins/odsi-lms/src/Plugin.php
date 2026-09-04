<?php
/**
 * Plugin kernel.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS;

use ODSI\LMS\Admin\AdminMenu;
use ODSI\LMS\Admin\AssignmentMetaBox;
use ODSI\LMS\Admin\CohortMetaBox;
use ODSI\LMS\Admin\CourseActions;
use ODSI\LMS\Admin\CourseBuilder;
use ODSI\LMS\Admin\GradingScreen;
use ODSI\LMS\Admin\QuestionMetaBox;
use ODSI\LMS\Admin\ReportsScreen;
use ODSI\LMS\Admin\SettingsMetaBoxes;
use ODSI\LMS\Admin\SettingsScreen;
use ODSI\LMS\Assignments\Assignments;
use ODSI\LMS\Blocks\Blocks;
use ODSI\LMS\Certificates\Certificates;
use ODSI\LMS\Commerce\Purchases;
use ODSI\LMS\Commerce\WooCommerce;
use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Courses\Access;
use ODSI\LMS\Courses\Cloner;
use ODSI\LMS\Courses\Cohorts;
use ODSI\LMS\Courses\Enrollment;
use ODSI\LMS\Courses\Maintenance;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\Database\Migrator;
use ODSI\LMS\Frontend\ContentDecorator;
use ODSI\LMS\Frontend\Shortcodes;
use ODSI\LMS\Frontend\Templates;
use ODSI\LMS\Notifications\Emails;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\PostTypes\Taxonomies;
use ODSI\LMS\Quizzes\Grader;
use ODSI\LMS\Quizzes\QuizService;
use ODSI\LMS\Reports\EnrollmentReport;
use ODSI\LMS\Reports\QuizReport;
use ODSI\LMS\Repositories\CertificateRepository;
use ODSI\LMS\Repositories\EnrollmentRepository;
use ODSI\LMS\Repositories\ProgressRepository;
use ODSI\LMS\Repositories\QuizAttemptRepository;
use ODSI\LMS\Repositories\SubmissionRepository;
use ODSI\LMS\Rest\CoreGuards;
use ODSI\LMS\Rest\RestServiceProvider;
use ODSI\LMS\Support\ObjectCache;
use ODSI\LMS\Support\Assets;
use ODSI\LMS\Support\Lifecycle;
use ODSI\LMS\Support\Meta;
use ODSI\LMS\Support\Settings;

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
	 * Whether boot() has already run.
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
	 * The service container, for add-ons that need to reach plugin services.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Absolute path to the plugin directory, with a trailing slash.
	 */
	public static function path(): string {
		return plugin_dir_path( PLUGIN_FILE );
	}

	/**
	 * Public URL of the plugin directory, with a trailing slash.
	 */
	public static function url(): string {
		return plugin_dir_url( PLUGIN_FILE );
	}

	/**
	 * Register every service factory.
	 */
	private function register_services(): void {
		$c = $this->container;

		$c->set( Settings::class, static fn (): object => new Settings() );

		// Storage.
		$c->set( EnrollmentRepository::class, static fn (): object => new EnrollmentRepository() );
		$c->set( ProgressRepository::class, static fn (): object => new ProgressRepository() );
		$c->set( QuizAttemptRepository::class, static fn (): object => new QuizAttemptRepository() );
		$c->set( SubmissionRepository::class, static fn (): object => new SubmissionRepository() );

		// Domain services.
		$c->set( Structure::class, static fn (): object => new Structure() );
		$c->set(
			Enrollment::class,
			static fn ( Container $c ): object => new Enrollment(
				$c->get( EnrollmentRepository::class ),
				$c->get( ProgressRepository::class )
			)
		);
		$c->set(
			Progress::class,
			static fn ( Container $c ): object => new Progress(
				$c->get( ProgressRepository::class ),
				$c->get( EnrollmentRepository::class ),
				$c->get( Structure::class )
			)
		);
		$c->set( Grader::class, static fn (): object => new Grader() );
		$c->set(
			QuizService::class,
			static fn ( Container $c ): object => new QuizService(
				$c->get( QuizAttemptRepository::class ),
				$c->get( Grader::class ),
				$c->get( Progress::class )
			)
		);

		// Bootable components.
		$c->set( Taxonomies::class, static fn (): object => new Taxonomies() );
		$c->set( PostTypes::class, static fn (): object => new PostTypes() );
		$c->set(
			Access::class,
			static fn ( Container $c ): object => new Access(
				$c->get( EnrollmentRepository::class ),
				$c->get( ProgressRepository::class ),
				$c->get( Structure::class ),
				$c->get( Enrollment::class )
			)
		);
		$c->set(
			Assignments::class,
			static fn ( Container $c ): object => new Assignments(
				$c->get( SubmissionRepository::class ),
				$c->get( Structure::class ),
				$c->get( Access::class ),
				$c->get( Progress::class )
			)
		);
		$c->set( Maintenance::class, static fn ( Container $c ): object => new Maintenance( $c->get( EnrollmentRepository::class ) ) );
		$c->set( Assets::class, static fn (): object => new Assets() );
		$c->set( Templates::class, static fn (): object => new Templates() );
		$c->set(
			Shortcodes::class,
			static fn ( Container $c ): object => new Shortcodes(
				$c->get( Structure::class ),
				$c->get( Progress::class ),
				$c->get( Enrollment::class ),
				$c->get( Access::class ),
				$c->get( Templates::class )
			)
		);
		$c->set( Blocks::class, static fn ( Container $c ): object => new Blocks( $c->get( Shortcodes::class ) ) );
		$c->set( CertificateRepository::class, static fn (): object => new CertificateRepository() );
		$c->set( EnrollmentReport::class, static fn ( Container $c ): object => new EnrollmentReport( $c->get( Progress::class ), $c->get( Enrollment::class ) ) );
		$c->set( QuizReport::class, static fn (): object => new QuizReport() );
		$c->set( Cloner::class, static fn (): object => new Cloner() );
		$c->set( CourseActions::class, static fn ( Container $c ): object => new CourseActions( $c->get( Cloner::class ) ) );
		$c->set( Certificates::class, static fn ( Container $c ): object => new Certificates( $c->get( CertificateRepository::class ), $c->get( Templates::class ), $c->get( Settings::class ) ) );
		$c->set( Cohorts::class, static fn ( Container $c ): object => new Cohorts( $c->get( Enrollment::class ) ) );
		$c->set( Emails::class, static fn ( Container $c ): object => new Emails( $c->get( Settings::class ), $c->get( CertificateRepository::class ), $c->get( Certificates::class ) ) );
		$c->set( SettingsScreen::class, static fn ( Container $c ): object => new SettingsScreen( $c->get( Settings::class ) ) );
		$c->set( ObjectCache::class, static fn ( Container $c ): object => new ObjectCache( $c->get( Structure::class ) ) );
		$c->set( ReportsScreen::class, static fn ( Container $c ): object => new ReportsScreen( $c->get( EnrollmentReport::class ), $c->get( Enrollment::class ), $c->get( QuizReport::class ) ) );
		$c->set( GradingScreen::class, static fn ( Container $c ): object => new GradingScreen( $c->get( EnrollmentReport::class ), $c->get( QuizService::class ), $c->get( Assignments::class ) ) );
		$c->set( AssignmentMetaBox::class, static fn (): object => new AssignmentMetaBox() );
		$c->set( SettingsMetaBoxes::class, static fn ( Container $c ): object => new SettingsMetaBoxes( $c->get( Settings::class ) ) );
		$c->set( QuestionMetaBox::class, static fn (): object => new QuestionMetaBox() );
		$c->set( Purchases::class, static fn ( Container $c ): object => new Purchases( $c->get( Enrollment::class ) ) );
		$c->set( WooCommerce::class, static fn ( Container $c ): object => new WooCommerce( $c->get( Purchases::class ) ) );
		$c->set(
			Lifecycle::class,
			static fn ( Container $c ): object => new Lifecycle(
				$c->get( EnrollmentRepository::class ),
				$c->get( ProgressRepository::class ),
				$c->get( QuizAttemptRepository::class ),
				$c->get( SubmissionRepository::class ),
				$c->get( CertificateRepository::class )
			)
		);
		$c->set( CohortMetaBox::class, static fn ( Container $c ): object => new CohortMetaBox( $c->get( Cohorts::class ) ) );
		$c->set(
			ContentDecorator::class,
			static fn ( Container $c ): object => new ContentDecorator(
				$c->get( Structure::class ),
				$c->get( Progress::class ),
				$c->get( Access::class ),
				$c->get( Shortcodes::class ),
				$c->get( Assignments::class ),
				$c->get( Templates::class )
			)
		);
		$c->set( AdminMenu::class, static fn ( Container $c ): object => new AdminMenu( $c->get( ReportsScreen::class ), $c->get( GradingScreen::class ), $c->get( SettingsScreen::class ) ) );
		$c->set( CourseBuilder::class, static fn ( Container $c ): object => new CourseBuilder( $c->get( Structure::class ) ) );
		$c->set( RestServiceProvider::class, static fn ( Container $c ): object => new RestServiceProvider( $c ) );
		$c->set( CoreGuards::class, static fn ( Container $c ): object => new CoreGuards( $c->get( Access::class ) ) );

		/**
		 * Fires after the plugin's own services are registered.
		 *
		 * Add-ons should register their services here rather than instantiating
		 * plugin classes directly.
		 *
		 * @param Container $container Service container.
		 */
		do_action( 'odsi_lms_register_services', $c );
	}

	/**
	 * Service ids that implement Bootable and should be booted, in order.
	 *
	 * @return string[]
	 */
	private function bootable_services(): array {
		$services = array(
			Taxonomies::class,
			PostTypes::class,
			Structure::class,
			ObjectCache::class,
			Access::class,
			Assignments::class,
			Maintenance::class,
			QuizService::class,
			Certificates::class,
			Cohorts::class,
			Emails::class,
			Assets::class,
			Templates::class,
			Shortcodes::class,
			Blocks::class,
			ContentDecorator::class,
			RestServiceProvider::class,
			CoreGuards::class,
			Lifecycle::class,
			Purchases::class,
			WooCommerce::class,
		);

		if ( is_admin() ) {
			$services[] = AdminMenu::class;
			$services[] = CourseBuilder::class;
			$services[] = ReportsScreen::class;
			$services[] = GradingScreen::class;
			$services[] = CohortMetaBox::class;
			$services[] = AssignmentMetaBox::class;
			$services[] = SettingsScreen::class;
			$services[] = SettingsMetaBoxes::class;
			$services[] = QuestionMetaBox::class;
			$services[] = CourseActions::class;
		}

		/**
		 * Filters the list of services booted on this request.
		 *
		 * @param string[] $services Container ids implementing Bootable.
		 */
		return (array) apply_filters( 'odsi_lms_bootable_services', $services );
	}

	/**
	 * Boot the plugin. Safe to call more than once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		// Schema upgrades run from the admin and from cron, never from a
		// front-end request, so a version check can never sit in the hot path.
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
		 * Fires once the LMS has finished booting.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'odsi_lms_booted', $this );
	}
}
