<?php
/**
 * Meta key registry.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Support;

use ODSI\LMS\PostTypes\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Central registry of post meta keys and their REST schemas.
 *
 * Keeping the keys in one place stops the usual drift where the admin screen, the
 * REST controller and the front end each invent a slightly different string.
 */
final class Meta {

	/** Course id a lesson, topic or quiz belongs to. */
	public const COURSE_ID = '_odsi_course_id';

	/** Lesson id a topic or quiz belongs to. */
	public const LESSON_ID = '_odsi_lesson_id';

	/** Quiz id that must be passed to complete a lesson or topic. */
	public const QUIZ_ID = '_odsi_quiz_id';

	/** Certificate post id awarded on course completion. */
	public const CERTIFICATE_ID = '_odsi_certificate_id';

	/** Access mode: `open`, `free`, `paid` or `closed`. */
	public const ACCESS_MODE = '_odsi_access_mode';

	/** Course price, when the access mode is `paid`. */
	public const PRICE = '_odsi_price';

	/** Days of access granted at enrollment. Zero means unlimited. */
	public const ACCESS_DAYS = '_odsi_access_days';

	/** Whether steps must be completed in order. */
	public const LINEAR_PROGRESSION = '_odsi_linear_progression';

	/** Estimated duration in minutes. */
	public const DURATION = '_odsi_duration';

	/** Drip schedule: `none`, `days_after_enrollment` or `date`. */
	public const DRIP_TYPE = '_odsi_drip_type';

	/** Drip value: day offset or a Y-m-d date. */
	public const DRIP_VALUE = '_odsi_drip_value';

	/** Quiz passing percentage. */
	public const PASS_MARK = '_odsi_pass_mark';

	/** Maximum quiz attempts. Zero means unlimited. */
	public const MAX_ATTEMPTS = '_odsi_max_attempts';

	/** Quiz time limit in minutes. Zero means untimed. */
	public const TIME_LIMIT = '_odsi_time_limit';

	/** Question type: `single`, `multiple`, `true_false`, `fill_blank`, `essay`. */
	public const QUESTION_TYPE = '_odsi_question_type';

	/** Question answer definition, stored as an array. */
	public const QUESTION_ANSWERS = '_odsi_question_answers';

	/** Points a question is worth. */
	public const QUESTION_POINTS = '_odsi_question_points';

	/** Whether a lesson or topic requires an assignment before it completes. */
	public const ASSIGNMENT_REQUIRED = '_odsi_assignment_required';

	/** Points an assignment is worth. Zero means approve/reject only. */
	public const ASSIGNMENT_POINTS = '_odsi_assignment_points';

	/** Whether submissions are approved on receipt. */
	public const ASSIGNMENT_AUTO_APPROVE = '_odsi_assignment_auto_approve';

	/**
	 * Meta definitions keyed by post type, then meta key.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function definitions(): array {
		$int    = array(
			'type'    => 'integer',
			'default' => 0,
		);
		$string = array(
			'type'    => 'string',
			'default' => '',
		);
		$bool   = array(
			'type'    => 'boolean',
			'default' => false,
		);

		return array(
			PostTypes::COURSE   => array(
				self::ACCESS_MODE        => $string + array( 'default' => 'free' ),
				self::PRICE              => array(
					'type'    => 'number',
					'default' => 0,
				),
				self::ACCESS_DAYS        => $int,
				self::LINEAR_PROGRESSION => $bool + array( 'default' => true ),
				self::DURATION           => $int,
				self::CERTIFICATE_ID     => $int,
			),
			PostTypes::LESSON   => array(
				self::COURSE_ID               => $int,
				self::QUIZ_ID                 => $int,
				self::DRIP_TYPE               => $string + array( 'default' => 'none' ),
				self::DRIP_VALUE              => $string,
				self::DURATION                => $int,
				self::ASSIGNMENT_REQUIRED     => $bool,
				self::ASSIGNMENT_POINTS       => $int,
				self::ASSIGNMENT_AUTO_APPROVE => $bool,
			),
			PostTypes::TOPIC    => array(
				self::COURSE_ID               => $int,
				self::LESSON_ID               => $int,
				self::QUIZ_ID                 => $int,
				self::DURATION                => $int,
				self::ASSIGNMENT_REQUIRED     => $bool,
				self::ASSIGNMENT_POINTS       => $int,
				self::ASSIGNMENT_AUTO_APPROVE => $bool,
			),
			PostTypes::QUIZ     => array(
				self::COURSE_ID    => $int,
				self::LESSON_ID    => $int,
				self::PASS_MARK    => array(
					'type'    => 'number',
					'default' => 80,
				),
				self::MAX_ATTEMPTS => $int,
				self::TIME_LIMIT   => $int,
			),
			PostTypes::QUESTION => array(
				self::QUIZ_ID         => $int,
				self::QUESTION_TYPE   => $string + array( 'default' => 'single' ),
				self::QUESTION_POINTS => $int + array( 'default' => 1 ),
			),
		);
	}

	/**
	 * Register every meta key so it is exposed and sanitised by the REST API.
	 */
	public static function register(): void {
		foreach ( self::definitions() as $post_type => $keys ) {
			foreach ( $keys as $key => $schema ) {
				register_post_meta(
					$post_type,
					$key,
					array_merge(
						array(
							'single'        => true,
							'show_in_rest'  => true,
							'auth_callback' => static fn (): bool => current_user_can( Capabilities::MANAGE ),
						),
						$schema
					)
				);
			}
		}

		// Answers are a nested structure the block editor never edits directly, so
		// they are kept out of REST and written only through the quiz builder.
		register_post_meta(
			PostTypes::QUESTION,
			self::QUESTION_ANSWERS,
			array(
				'single'        => true,
				'type'          => 'array',
				'show_in_rest'  => false,
				'default'       => array(),
				'auth_callback' => static fn (): bool => current_user_can( Capabilities::MANAGE ),
			)
		);
	}
}
