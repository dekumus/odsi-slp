<?php
/**
 * Custom post type registration.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\PostTypes;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every LMS content type.
 *
 * Authored content is stored as posts on purpose: the block editor, revisions,
 * media handling, permalinks and the core REST API then work with no extra code.
 */
final class PostTypes implements Bootable {

	public const COURSE      = 'odsi_course';
	public const LESSON      = 'odsi_lesson';
	public const TOPIC       = 'odsi_topic';
	public const QUIZ        = 'odsi_quiz';
	public const QUESTION    = 'odsi_question';
	public const CERTIFICATE = 'odsi_certificate';
	public const COHORT      = 'odsi_cohort';

	/**
	 * Content types a learner can complete, in outline order.
	 *
	 * @return string[]
	 */
	public static function trackable(): array {
		return array( self::LESSON, self::TOPIC, self::QUIZ );
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register' ), 5 );
	}

	/**
	 * Register all post types.
	 */
	public function register(): void {
		if ( '1' === get_option( 'odsi_lms_flush_rewrites' ) ) {
			delete_option( 'odsi_lms_flush_rewrites' );
			add_action( 'wp_loaded', 'flush_rewrite_rules' );
		}

		foreach ( self::definitions() as $post_type => $args ) {
			register_post_type( $post_type, $args );
		}
	}

	/**
	 * Post type arguments keyed by post type name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$definitions = array(
			self::COURSE      => self::args(
				__( 'Courses', 'odsi-lms' ),
				__( 'Course', 'odsi-lms' ),
				array(
					'has_archive'  => sanitize_title( (string) ( new \ODSI\LMS\Support\Settings() )->get( 'course_archive_slug' ) ) ?: 'courses',
					'rewrite'      => array(
						'slug'       => 'course',
						'with_front' => false,
					),
					'menu_icon'    => 'dashicons-welcome-learn-more',
					'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'revisions', 'custom-fields', 'page-attributes' ),
					'taxonomies'   => array( Taxonomies::COURSE_CATEGORY, Taxonomies::COURSE_TAG, Taxonomies::COURSE_LEVEL ),
					'capabilities' => Capabilities::post_type_caps( 'odsi_course', 'odsi_courses' ),
				)
			),
			self::LESSON      => self::args(
				__( 'Lessons', 'odsi-lms' ),
				__( 'Lesson', 'odsi-lms' ),
				array(
					'rewrite'      => array(
						'slug'       => 'lesson',
						'with_front' => false,
					),
					'menu_icon'    => 'dashicons-media-document',
					'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'revisions', 'custom-fields', 'page-attributes' ),
					'capabilities' => Capabilities::post_type_caps( 'odsi_lesson', 'odsi_lessons' ),
				)
			),
			self::TOPIC       => self::args(
				__( 'Topics', 'odsi-lms' ),
				__( 'Topic', 'odsi-lms' ),
				array(
					'rewrite'      => array(
						'slug'       => 'topic',
						'with_front' => false,
					),
					'menu_icon'    => 'dashicons-media-text',
					'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'revisions', 'custom-fields', 'page-attributes' ),
					'capabilities' => Capabilities::post_type_caps( 'odsi_topic', 'odsi_topics' ),
				)
			),
			self::QUIZ        => self::args(
				__( 'Quizzes', 'odsi-lms' ),
				__( 'Quiz', 'odsi-lms' ),
				array(
					'rewrite'      => array(
						'slug'       => 'quiz',
						'with_front' => false,
					),
					'menu_icon'    => 'dashicons-forms',
					'supports'     => array( 'title', 'editor', 'thumbnail', 'author', 'revisions', 'custom-fields' ),
					'capabilities' => Capabilities::post_type_caps( 'odsi_quiz', 'odsi_quizzes' ),
				)
			),
			self::QUESTION    => self::args(
				__( 'Questions', 'odsi-lms' ),
				__( 'Question', 'odsi-lms' ),
				array(
					// Questions are authored inside the quiz builder and have no
					// meaningful standalone URL, so they stay out of the front end.
					'public'             => false,
					'publicly_queryable' => false,
					'show_ui'            => true,
					'show_in_menu'       => 'odsi-lms',
					'has_archive'        => false,
					'rewrite'            => false,
					'supports'           => array( 'title', 'editor', 'author', 'revisions', 'custom-fields' ),
					'taxonomies'         => array( Taxonomies::QUESTION_CATEGORY ),
					'capabilities'       => Capabilities::post_type_caps( 'odsi_question', 'odsi_questions' ),
				)
			),
			self::CERTIFICATE => self::args(
				__( 'Certificates', 'odsi-lms' ),
				__( 'Certificate', 'odsi-lms' ),
				array(
					'public'             => false,
					'publicly_queryable' => false,
					'show_ui'            => true,
					'show_in_menu'       => 'odsi-lms',
					'has_archive'        => false,
					'rewrite'            => false,
					'supports'           => array( 'title', 'editor', 'thumbnail', 'revisions', 'custom-fields' ),
					'capabilities'       => Capabilities::post_type_caps( 'odsi_certificate', 'odsi_certificates' ),
				)
			),
			self::COHORT      => self::args(
				__( 'Course Groups', 'odsi-lms' ),
				__( 'Course Group', 'odsi-lms' ),
				array(
					// A cohort is an administrative grouping (a class, a cohort, a
					// corporate team). Social groups live in the odsi-social plugin.
					'public'             => false,
					'publicly_queryable' => false,
					'show_ui'            => true,
					'show_in_menu'       => 'odsi-lms',
					'has_archive'        => false,
					'rewrite'            => false,
					'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
					'capabilities'       => Capabilities::post_type_caps( 'odsi_cohort', 'odsi_cohorts' ),
				)
			),
		);

		// Lessons, topics and quizzes are reached through their course, never
		// through site search, where an excerpt would leak locked content.
		foreach ( self::trackable() as $step_type ) {
			$definitions[ $step_type ]['exclude_from_search'] = true;
		}

		/**
		 * Filters the LMS post type definitions before registration.
		 *
		 * @param array<string, array<string, mixed>> $definitions Post type args keyed by name.
		 */
		return (array) apply_filters( 'odsi_lms_post_type_definitions', $definitions );
	}

	/**
	 * Build post type args from a plural/singular label pair plus overrides.
	 *
	 * @param string               $plural    Plural label.
	 * @param string               $singular  Singular label.
	 * @param array<string, mixed> $overrides Args merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function args( string $plural, string $singular, array $overrides = array() ): array {
		$defaults = array(
			'labels'             => self::labels( $plural, $singular ),
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => 'odsi-lms',
			'show_in_rest'       => true,
			'hierarchical'       => false,
			'has_archive'        => false,
			'map_meta_cap'       => true,
			'menu_position'      => 30,
			'supports'           => array( 'title', 'editor' ),
		);

		return array_merge( $defaults, $overrides );
	}

	/**
	 * Standard label set.
	 *
	 * @param string $plural   Plural label.
	 * @param string $singular Singular label.
	 *
	 * @return array<string, string>
	 */
	private static function labels( string $plural, string $singular ): array {
		return array(
			'name'               => $plural,
			'singular_name'      => $singular,
			'menu_name'          => $plural,
			'all_items'          => $plural,
			/* translators: %s: singular content type label. */
			'add_new_item'       => sprintf( __( 'Add New %s', 'odsi-lms' ), $singular ),
			/* translators: %s: singular content type label. */
			'edit_item'          => sprintf( __( 'Edit %s', 'odsi-lms' ), $singular ),
			/* translators: %s: singular content type label. */
			'new_item'           => sprintf( __( 'New %s', 'odsi-lms' ), $singular ),
			/* translators: %s: singular content type label. */
			'view_item'          => sprintf( __( 'View %s', 'odsi-lms' ), $singular ),
			/* translators: %s: plural content type label. */
			'search_items'       => sprintf( __( 'Search %s', 'odsi-lms' ), $plural ),
			/* translators: %s: lowercase plural content type label. */
			'not_found'          => sprintf( __( 'No %s found.', 'odsi-lms' ), strtolower( $plural ) ),
			/* translators: %s: lowercase plural content type label. */
			'not_found_in_trash' => sprintf( __( 'No %s found in Trash.', 'odsi-lms' ), strtolower( $plural ) ),
		);
	}
}
