<?php
/**
 * Course builder REST routes.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Courses\Structure;
use ODSI\LMS\Plugin;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Support\Meta;
use ODSI\Tests\Integration\TestCase;

final class BuilderTest extends TestCase {

	private const NS = '/odsi-lms/v1';

	public function set_up(): void {
		parent::set_up();
		Plugin::instance()->container()->get( Structure::class )->flush();
		do_action( 'rest_api_init' );
	}

	public function test_only_editors_of_the_course_may_use_the_builder(): void {
		$owner  = $this->lms->instructor();
		$other  = $this->lms->instructor();
		$course = $this->lms->course( array( 'post_author' => $owner ) );

		self::assertSame( 401, $this->rest( 'GET', self::NS . "/courses/{$course}/builder" )->get_status() );
		self::assertSame( 403, $this->as_user( $other, fn () => $this->rest( 'GET', self::NS . "/courses/{$course}/builder" ) )->get_status() );
		self::assertSame( 403, $this->as_user( $this->lms->learner(), fn () => $this->rest( 'GET', self::NS . "/courses/{$course}/builder" ) )->get_status() );
		self::assertSame( 200, $this->as_user( $owner, fn () => $this->rest( 'GET', self::NS . "/courses/{$course}/builder" ) )->get_status() );

		$page = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_author' => $owner,
			)
		);
		self::assertSame( 403, $this->as_user( $owner, fn () => $this->rest( 'GET', self::NS . "/courses/{$page}/builder" ) )->get_status(), 'Not a course.' );
	}

	public function test_tree_add_reorder_and_detach(): void {
		$owner = $this->lms->instructor();
		$c     = $this->lms->standard_course( array( 'post_author' => $owner ) );
		$call  = fn ( string $method, string $path, array $params = array() ) => $this->as_user( $owner, fn () => $this->rest( $method, self::NS . "/courses/{$c['course']}/builder{$path}", $params ) );

		$tree = $call( 'GET', '' )->get_data();
		self::assertSame( array( $c['lesson1'], $c['lesson2'], $c['lesson3'] ), array_column( $tree['lessons'], 'id' ) );
		self::assertSame( array( $c['topic21'], $c['topic22'] ), array_column( $tree['lessons'][1]['topics'], 'id' ) );
		self::assertSame( array( $c['quiz2'] ), array_column( $tree['lessons'][1]['quizzes'], 'id' ) );

		// Add a draft lesson at the end, and a topic under it.
		$added = $call(
			'POST',
			'',
			array(
				'type' => PostTypes::LESSON,
				'title' => 'Lesson 4',
			)
		);
		self::assertSame( 201, $added->get_status() );
		$lesson4 = $added->get_data()['lessons'][3];
		self::assertSame( 'draft', $lesson4['status'] );
		self::assertSame( 4, $lesson4['order'] );

		$topic = $call(
			'POST',
			'',
			array(
				'type' => PostTypes::TOPIC,
				'title' => 'Topic 4.1',
				'parent' => $lesson4['id'],
			)
		);
		self::assertSame( $c['course'], (int) get_post_meta( $topic->get_data()['lessons'][3]['topics'][0]['id'], Meta::COURSE_ID, true ) );

		self::assertSame(
			400,
			$call(
				'POST',
				'',
				array(
					'type' => PostTypes::TOPIC,
					'title' => 'Orphan',
					'parent' => $c['course'],
				)
			)->get_status(),
			'Topics need a lesson parent.'
		);
		self::assertSame(
			400,
			$call(
				'POST',
				'',
				array(
					'type' => PostTypes::LESSON,
					'title' => '   ',
				)
			)->get_status()
		);

		// Reorder: move lesson 3 first and topic 2.2 before 2.1.
		$reordered = $call(
			'POST',
			'/reorder',
			array(
				'items' => array(
					array(
						'id' => $c['lesson3'],
						'parent' => 0,
						'order' => 1,
					),
					array(
						'id' => $c['lesson1'],
						'parent' => 0,
						'order' => 2,
					),
					array(
						'id' => $c['lesson2'],
						'parent' => 0,
						'order' => 3,
					),
					array(
						'id' => $c['topic22'],
						'parent' => $c['lesson2'],
						'order' => 1,
					),
					array(
						'id' => $c['topic21'],
						'parent' => $c['lesson2'],
						'order' => 2,
					),
				),
			)
		)->get_data();
		self::assertSame( $c['lesson3'], $reordered['lessons'][0]['id'] );
		self::assertSame( array( $c['topic22'], $c['topic21'] ), array_column( $reordered['lessons'][2]['topics'], 'id' ) );

		$structure = Plugin::instance()->container()->get( Structure::class );
		self::assertSame( $c['lesson3'], $structure->step_ids( $c['course'] )[0], 'The learner-facing outline follows.' );

		// A foreign node in the payload is ignored.
		$foreign = $this->lms->lesson( $this->lms->course() );
		$call(
			'POST',
			'/reorder',
			array(
				'items' => array(
					array(
						'id' => $foreign,
						'parent' => 0,
						'order' => 9,
					),
				),
			)
		);
		self::assertNotSame( 9, (int) get_post_field( 'menu_order', $foreign ) );

		// Detach lesson 2 with its topics; nothing is deleted.
		$detached = $call( 'DELETE', "/{$c['lesson2']}" )->get_data();
		self::assertNotContains( $c['lesson2'], array_column( $detached['lessons'], 'id' ) );
		self::assertSame( 'publish', get_post_status( $c['lesson2'] ) );
		self::assertSame( 0, (int) get_post_meta( $c['topic21'], Meta::COURSE_ID, true ), 'Registered meta falls back to its default once detached.' );
		self::assertSame( 404, $call( 'DELETE', "/{$foreign}" )->get_status() );
	}
}
