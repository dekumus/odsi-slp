<?php
/**
 * LMS fixture factory.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Fixtures;

use ODSI\LMS\Courses\Enrollment;
use ODSI\LMS\Plugin;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Meta;
use WP_UnitTest_Factory;

/**
 * Builds LMS worlds for integration tests.
 *
 * Every integration test builds its fixtures through here rather than with
 * inline `wp_insert_post()` calls, so that a change to how relationships are
 * stored is a change to one file.
 */
final class LmsFactory {

	/**
	 * Constructor.
	 *
	 * @param WP_UnitTest_Factory $factory Core factory for posts and users.
	 */
	public function __construct( private WP_UnitTest_Factory $factory ) {
	}

	/**
	 * Create a published course.
	 *
	 * @param array<string, mixed> $args Post args plus optional `meta` map.
	 */
	public function course( array $args = array() ): int {
		return $this->post(
			PostTypes::COURSE,
			$args,
			array(
				Meta::ACCESS_MODE => 'free',
				Meta::LINEAR_PROGRESSION => 1,
			)
		);
	}

	/**
	 * Create a published lesson under a course.
	 *
	 * @param int                  $course_id Course post id.
	 * @param array<string, mixed> $args      Post args plus optional `meta` map.
	 */
	public function lesson( int $course_id, array $args = array() ): int {
		return $this->post( PostTypes::LESSON, $args, array( Meta::COURSE_ID => $course_id ) );
	}

	/**
	 * Create a published topic under a lesson.
	 *
	 * @param int                  $lesson_id Lesson post id.
	 * @param array<string, mixed> $args      Post args plus optional `meta` map.
	 */
	public function topic( int $lesson_id, array $args = array() ): int {
		$course_id = (int) get_post_meta( $lesson_id, Meta::COURSE_ID, true );

		return $this->post(
			PostTypes::TOPIC,
			$args,
			array(
				Meta::COURSE_ID => $course_id,
				Meta::LESSON_ID => $lesson_id,
			)
		);
	}

	/**
	 * Create a published quiz attached to a course, lesson or topic.
	 *
	 * @param int                  $course_id Course post id.
	 * @param int                  $parent_id Lesson or topic id, or 0 for course level.
	 * @param array<string, mixed> $args      Post args plus optional `meta` map.
	 */
	public function quiz( int $course_id, int $parent_id = 0, array $args = array() ): int {
		return $this->post(
			PostTypes::QUIZ,
			$args,
			array(
				Meta::COURSE_ID    => $course_id,
				Meta::LESSON_ID    => $parent_id,
				Meta::PASS_MARK    => 80,
				Meta::MAX_ATTEMPTS => 0,
				Meta::TIME_LIMIT   => 0,
			)
		);
	}

	/**
	 * Create a published question in a quiz.
	 *
	 * @param int                              $quiz_id Quiz post id.
	 * @param string                           $type    Question type.
	 * @param array<int, array<string, mixed>> $answers Answer definitions.
	 * @param int                              $points  Points.
	 * @param array<string, mixed>             $args    Post args.
	 */
	public function question( int $quiz_id, string $type, array $answers, int $points = 1, array $args = array() ): int {
		$id = $this->post(
			PostTypes::QUESTION,
			$args,
			array(
				Meta::QUIZ_ID         => $quiz_id,
				Meta::QUESTION_TYPE   => $type,
				Meta::QUESTION_POINTS => $points,
			)
		);

		update_post_meta( $id, Meta::QUESTION_ANSWERS, $answers );

		return $id;
	}

	/**
	 * A single-choice question whose first option is correct.
	 *
	 * @param int $quiz_id Quiz post id.
	 * @param int $points  Points.
	 */
	public function single_choice_question( int $quiz_id, int $points = 1 ): int {
		return $this->question(
			$quiz_id,
			'single',
			array(
				array(
					'text' => 'Right',
					'correct' => true,
				),
				array(
					'text' => 'Wrong',
					'correct' => false,
				),
			),
			$points
		);
	}

	/**
	 * A course with a fixed, well-known shape:
	 *
	 *   Lesson 1 (leaf)
	 *   Lesson 2 (section)
	 *     Topic 2.1
	 *     Topic 2.2
	 *     Quiz 2 (lesson-level, one single-choice question)
	 *   Lesson 3 (leaf)
	 *
	 * @param array<string, mixed> $course_args Extra course args.
	 *
	 * @return array{course: int, lesson1: int, lesson2: int, topic21: int, topic22: int, quiz2: int, question: int, lesson3: int}
	 */
	public function standard_course( array $course_args = array() ): array {
		$course  = $this->course( $course_args );
		$lesson1 = $this->lesson(
			$course,
			array(
				'menu_order' => 1,
				'post_title' => 'Lesson 1',
			)
		);
		$lesson2 = $this->lesson(
			$course,
			array(
				'menu_order' => 2,
				'post_title' => 'Lesson 2',
			)
		);
		$topic21 = $this->topic(
			$lesson2,
			array(
				'menu_order' => 1,
				'post_title' => 'Topic 2.1',
			)
		);
		$topic22 = $this->topic(
			$lesson2,
			array(
				'menu_order' => 2,
				'post_title' => 'Topic 2.2',
			)
		);
		$quiz2   = $this->quiz( $course, $lesson2, array( 'post_title' => 'Quiz 2' ) );
		$q       = $this->single_choice_question( $quiz2 );
		$lesson3 = $this->lesson(
			$course,
			array(
				'menu_order' => 3,
				'post_title' => 'Lesson 3',
			)
		);

		return compact( 'course', 'lesson1', 'lesson2', 'topic21', 'topic22', 'quiz2', 'lesson3' ) + array( 'question' => $q );
	}

	/**
	 * Create a subscriber and enroll them on a course.
	 *
	 * @param int                  $course_id Course post id.
	 * @param array<string, mixed> $args      Enrollment args.
	 *
	 * @return int User id.
	 */
	public function enrolled_learner( int $course_id, array $args = array() ): int {
		$user_id = $this->learner();

		$this->enrollment()->enroll( $user_id, $course_id, $args );

		return $user_id;
	}

	/**
	 * Create a subscriber.
	 */
	public function learner(): int {
		return $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Create an instructor.
	 */
	public function instructor(): int {
		return $this->factory->user->create( array( 'role' => 'odsi_instructor' ) );
	}

	/**
	 * Enrollment service from the live container.
	 */
	public function enrollment(): Enrollment {
		return Plugin::instance()->container()->get( Enrollment::class );
	}

	/**
	 * Create a published post of a type with base meta, then overrides.
	 *
	 * @param string               $type Post type.
	 * @param array<string, mixed> $args Post args; `meta` key is a meta map.
	 * @param array<string, mixed> $base Base meta applied before `meta`.
	 */
	private function post( string $type, array $args, array $base ): int {
		$meta = array_merge( $base, (array) ( $args['meta'] ?? array() ) );
		unset( $args['meta'] );

		$id = $this->factory->post->create(
			array_merge(
				array(
					'post_type'   => $type,
					'post_status' => 'publish',
					'post_title'  => ucfirst( str_replace( 'odsi_', '', $type ) ) . ' ' . wp_rand( 1000, 9999 ),
				),
				$args
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}
}
