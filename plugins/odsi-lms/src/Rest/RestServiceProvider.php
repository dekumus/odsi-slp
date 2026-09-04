<?php
/**
 * REST API wiring.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Rest;

use ODSI\LMS\Assignments\Assignments;
use ODSI\LMS\Container;
use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Courses\Access;
use ODSI\LMS\Courses\Enrollment;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\Quizzes\QuizService;
use ODSI\LMS\Reports\EnrollmentReport;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every REST controller under the `odsi-lms/v1` namespace.
 */
final class RestServiceProvider implements Bootable {

	public const NAMESPACE = 'odsi-lms/v1';

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
		$controllers = array(
			new CourseController(
				$this->container->get( Structure::class ),
				$this->container->get( Progress::class ),
				$this->container->get( Enrollment::class ),
				$this->container->get( Access::class )
			),
			new ProgressController(
				$this->container->get( Progress::class ),
				$this->container->get( Access::class )
			),
			new QuizController(
				$this->container->get( QuizService::class ),
				$this->container->get( Access::class )
			),
			new BuilderController( $this->container->get( Structure::class ) ),
			new SubmissionController( $this->container->get( Assignments::class ), $this->container->get( EnrollmentReport::class ) ),
		);

		/**
		 * Filters the REST controllers registered by the LMS.
		 *
		 * @param object[]  $controllers Controller instances exposing `register_routes()`.
		 * @param Container $container   Service container.
		 */
		$controllers = (array) apply_filters( 'odsi_lms_rest_controllers', $controllers, $this->container );

		foreach ( $controllers as $controller ) {
			if ( method_exists( $controller, 'register_routes' ) ) {
				$controller->register_routes();
			}
		}
	}
}
