<?php
/**
 * Outline derivation. Spec: LMS-OUT-001..006, LMS-AUT-005/006.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Courses\Structure;
use ODSI\LMS\Plugin;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\Tests\Integration\TestCase;

final class StructureTest extends TestCase {

	private Structure $structure;

	public function set_up(): void {
		parent::set_up();
		$this->structure = Plugin::instance()->container()->get( Structure::class );
		$this->structure->flush();
	}

	public function test_out_001_outline_order_for_the_standard_course(): void {
		$c = $this->lms->standard_course();

		$expected = array( $c['lesson1'], $c['lesson2'], $c['topic21'], $c['topic22'], $c['quiz2'], $c['lesson3'] );
		self::assertSame( $expected, $this->structure->step_ids( $c['course'] ) );

		$types = array_column( $this->structure->outline( $c['course'] ), 'type' );
		self::assertSame( array( PostTypes::LESSON, PostTypes::LESSON, PostTypes::TOPIC, PostTypes::TOPIC, PostTypes::QUIZ, PostTypes::LESSON ), $types );
	}

	public function test_out_001_topic_quizzes_follow_their_topic_and_course_quizzes_come_last(): void {
		$course = $this->lms->course();
		$lesson = $this->lms->lesson( $course );
		$topic  = $this->lms->topic( $lesson );
		$tquiz  = $this->lms->quiz( $course, $topic );
		$cquiz  = $this->lms->quiz( $course, 0 );
		$lquiz  = $this->lms->quiz( $course, $lesson );

		self::assertSame( array( $lesson, $topic, $tquiz, $lquiz, $cquiz ), $this->structure->step_ids( $course ) );
	}

	public function test_aut_005_menu_order_then_date_then_id(): void {
		$course = $this->lms->course();
		$b      = $this->lms->lesson( $course, array( 'menu_order' => 2, 'post_date' => '2024-01-01 00:00:00' ) );
		$a      = $this->lms->lesson( $course, array( 'menu_order' => 1, 'post_date' => '2024-06-01 00:00:00' ) );
		$c      = $this->lms->lesson( $course, array( 'menu_order' => 2, 'post_date' => '2024-03-01 00:00:00' ) );

		self::assertSame( array( $a, $b, $c ), $this->structure->step_ids( $course ) );
	}

	public function test_aut_006_only_published_nodes_appear(): void {
		$course = $this->lms->course();
		$live   = $this->lms->lesson( $course );
		$draft  = $this->lms->lesson( $course, array( 'post_status' => 'draft' ) );
		$this->lms->lesson( $course, array( 'post_status' => 'private' ) );
		$this->lms->lesson( $course, array( 'post_status' => 'trash' ) );

		self::assertSame( array( $live ), $this->structure->step_ids( $course ) );
		self::assertNotContains( $draft, $this->structure->step_ids( $course ) );
	}

	public function test_out_004_empty_course(): void {
		$course = $this->lms->course();

		self::assertSame( array(), $this->structure->outline( $course ) );
		self::assertSame( 0, $this->structure->total_steps( $course ) );
		self::assertNull( $this->structure->next_step( $course, 0 ) );
	}

	public function test_out_005_next_and_previous_at_boundaries(): void {
		$c = $this->lms->standard_course();

		self::assertNull( $this->structure->previous_step( $c['course'], $c['lesson1'] ) );
		self::assertSame( $c['lesson2'], $this->structure->next_step( $c['course'], $c['lesson1'] )['id'] );
		self::assertSame( $c['quiz2'], $this->structure->previous_step( $c['course'], $c['lesson3'] )['id'] );
		self::assertNull( $this->structure->next_step( $c['course'], $c['lesson3'] ) );
	}

	public function test_out_005_gate_skips_section_lessons(): void {
		$c = $this->lms->standard_course();

		self::assertTrue( method_exists( $this->structure, 'gate' ), 'LMS-OUT-005: Structure::gate() is required.' );

		self::assertNull( $this->structure->gate( $c['course'], $c['lesson1'] ) );
		self::assertSame( $c['lesson1'], $this->structure->gate( $c['course'], $c['lesson2'] )['id'], 'A section is gated by the previous leaf.' );
		self::assertSame( $c['lesson1'], $this->structure->gate( $c['course'], $c['topic21'] )['id'], 'The first topic is gated by the node before its section, not by the section.' );
		self::assertSame( $c['topic21'], $this->structure->gate( $c['course'], $c['topic22'] )['id'] );
		self::assertSame( $c['topic22'], $this->structure->gate( $c['course'], $c['quiz2'] )['id'] );
		self::assertSame( $c['quiz2'], $this->structure->gate( $c['course'], $c['lesson3'] )['id'] );
	}

	public function test_out_002_section_classification_is_derived(): void {
		$c = $this->lms->standard_course();

		self::assertTrue( method_exists( $this->structure, 'is_section' ) );
		self::assertFalse( $this->structure->is_section( $c['lesson1'] ) );
		self::assertTrue( $this->structure->is_section( $c['lesson2'] ) );

		wp_update_post( array( 'ID' => $c['topic21'], 'post_status' => 'draft' ) );
		wp_update_post( array( 'ID' => $c['topic22'], 'post_status' => 'draft' ) );
		$this->structure->flush();

		self::assertFalse( $this->structure->is_section( $c['lesson2'] ), 'Unpublishing every topic makes a leaf.' );
	}

	public function test_out_006_outline_recomputes_after_a_save_without_manual_flush(): void {
		$c = $this->lms->standard_course();
		self::assertCount( 6, $this->structure->outline( $c['course'] ) );

		$new = $this->lms->lesson( $c['course'], array( 'menu_order' => 9 ) );
		self::assertContains( $new, $this->structure->step_ids( $c['course'] ), 'LMS-OUT-006: save_post must invalidate.' );

		wp_trash_post( $new );
		self::assertNotContains( $new, $this->structure->step_ids( $c['course'] ), 'LMS-OUT-006: trash must invalidate.' );
	}

	public function test_course_id_for_resolves_through_meta(): void {
		$c = $this->lms->standard_course();

		self::assertSame( $c['course'], $this->structure->course_id_for( $c['topic21'] ) );
		self::assertSame( 0, $this->structure->course_id_for( 999999 ) );
	}
}
