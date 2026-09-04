<?php
/**
 * LMS-AUT-013: duplicating a course with its outline.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Courses\Cloner;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\Plugin;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Meta;
use ODSI\Tests\Integration\TestCase;

final class ClonerTest extends TestCase {

	public function test_aut_013_the_copy_is_a_draft_tree_wired_to_itself(): void {
		$c     = $this->lms->standard_course(
			array(
				'meta' => array(
					Meta::WC_PRODUCT_ID => 77,
					Meta::PASS_MARK => 70,
				),
			)
		);
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		update_post_meta( $c['quiz2'], Meta::TIME_LIMIT, 15 );
		wp_update_post(
			array(
				'ID' => $c['lesson3'],
				'post_status' => 'draft',
			)
		);
		$learner = $this->lms->enrolled_learner( $c['course'] );
		$map     = array();
		add_action(
			'odsi_lms_course_duplicated',
			static function ( int $copy_id, int $source_id, array $m ) use ( &$map ): void {
				$map = $m;
			},
			10,
			3
		);

		$copy = Plugin::instance()->container()->get( Cloner::class )->duplicate( $c['course'], $admin );

		self::assertGreaterThan( 0, $copy );
		self::assertSame( 'draft', get_post_status( $copy ) );
		self::assertSame( $admin, (int) get_post_field( 'post_author', $copy ) );
		self::assertStringStartsWith( 'Copy of ', (string) get_the_title( $copy ) );
		self::assertSame( '', (string) get_post_meta( $copy, Meta::WC_PRODUCT_ID, true ) ?: '', 'The product link is not copied.' );
		self::assertSame( 8, count( $map ), 'Course, three lessons, two topics, a quiz and a question.' );

		$lessons = get_posts(
			array(
				'post_type' => PostTypes::LESSON,
				'post_status' => 'any',
				'meta_key' => Meta::COURSE_ID,
				'meta_value' => $copy,
				'fields' => 'ids',
				'orderby' => 'menu_order',
				'order' => 'ASC',
				'posts_per_page' => 10,
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery
		self::assertCount( 3, $lessons );
		self::assertSame( 'draft', get_post_status( $lessons[0] ) );
		self::assertNotContains( $c['lesson1'], $lessons );

		$new_lesson2 = $map[ $c['lesson2'] ];
		$topics      = get_posts(
			array(
				'post_type' => PostTypes::TOPIC,
				'post_status' => 'any',
				'meta_key' => Meta::LESSON_ID,
				'meta_value' => $new_lesson2,
				'fields' => 'ids',
				'posts_per_page' => 10,
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery
		self::assertCount( 2, $topics );
		self::assertSame( $copy, (int) get_post_meta( $topics[0], Meta::COURSE_ID, true ) );

		$new_quiz = $map[ $c['quiz2'] ];
		self::assertSame( $copy, (int) get_post_meta( $new_quiz, Meta::COURSE_ID, true ) );
		self::assertSame( $new_lesson2, (int) get_post_meta( $new_quiz, Meta::LESSON_ID, true ) );
		self::assertSame( 15, (int) get_post_meta( $new_quiz, Meta::TIME_LIMIT, true ) );

		$new_question = $map[ $c['question'] ];
		self::assertSame( $new_quiz, (int) get_post_meta( $new_question, Meta::QUIZ_ID, true ) );
		self::assertSame( get_post_meta( $c['question'], Meta::QUESTION_ANSWERS, true ), get_post_meta( $new_question, Meta::QUESTION_ANSWERS, true ) );

		// The source is untouched and its learner data stays with it.
		self::assertSame( 'publish', get_post_status( $c['course'] ) );
		self::assertTrue( $this->lms->enrollment()->is_enrolled( $learner, $c['course'] ) );
		self::assertFalse( $this->lms->enrollment()->is_enrolled( $learner, $copy ) );

		// Published, the copy is a working course of its own.
		foreach ( $map as $new_id ) {
			wp_update_post(
				array(
					'ID' => $new_id,
					'post_status' => 'publish',
				)
			);
		}
		Plugin::instance()->container()->get( Structure::class )->flush();
		self::assertCount( count( Plugin::instance()->container()->get( Structure::class )->outline( $c['course'] ) ) + 1, Plugin::instance()->container()->get( Structure::class )->outline( $copy ), 'Every node, including the lesson that was a draft in the source.' );
	}

	public function test_aut_013_only_someone_who_may_edit_the_source_and_create_courses_duplicates(): void {
		$owner    = $this->lms->instructor();
		$other    = $this->lms->instructor();
		$learner  = $this->lms->learner();
		$course   = $this->lms->course( array( 'post_author' => $owner ) );
		$cloner   = Plugin::instance()->container()->get( Cloner::class );

		self::assertSame( 0, $cloner->duplicate( $course, $other ) );
		self::assertSame( 0, $cloner->duplicate( $course, $learner ) );
		self::assertSame( 0, $cloner->duplicate( 999999, $owner ) );
		self::assertGreaterThan( 0, $cloner->duplicate( $course, $owner ) );
	}
}
