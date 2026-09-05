<?php
/**
 * REST routes. Spec: LMS-IF section 8, LMS-ACC-007, LMS-ENR-008, LMS-QZ-005.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\Plugin;
use ODSI\LMS\Quizzes\QuizService;
use ODSI\LMS\Support\Meta;
use ODSI\Tests\Integration\TestCase;

final class RestTest extends TestCase {

	private const NS = '/odsi-lms/v1';

	public function set_up(): void {
		parent::set_up();
		Plugin::instance()->container()->get( Structure::class )->flush();
		do_action( 'rest_api_init' );
	}

	public function test_outline_for_a_visitor_and_a_learner(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );

		$visitor = $this->rest( 'GET', self::NS . "/courses/{$c['course']}/outline" );
		self::assertSame( 200, $visitor->get_status() );
		self::assertFalse( $visitor->get_data()['enrolled'] );
		self::assertCount( 6, $visitor->get_data()['steps'] );
		self::assertTrue( $visitor->get_data()['steps'][0]['locked'] );

		$learner = $this->as_user( $user, fn () => $this->rest( 'GET', self::NS . "/courses/{$c['course']}/outline" ) );
		$data    = $learner->get_data();
		self::assertTrue( $data['enrolled'] );
		self::assertFalse( $data['steps'][0]['locked'] );
		self::assertTrue( $data['steps'][1]['locked'] );
		self::assertSame( $c['lesson1'], $data['resume'], 'LMS-IF: resume node id.' );
		self::assertSame( 0.0, $data['percentage'] );
	}

	public function test_outline_404_for_a_non_course(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		self::assertSame( 404, $this->rest( 'GET', self::NS . "/courses/{$page}/outline" )->get_status() );
	}

	public function test_enroll_self_serve_rules(): void {
		$free   = $this->lms->course();
		$paid   = $this->lms->course( array( 'meta' => array( Meta::ACCESS_MODE => 'paid' ) ) );
		$closed = $this->lms->course( array( 'meta' => array( Meta::ACCESS_MODE => 'closed' ) ) );
		$user   = $this->lms->learner();

		self::assertSame( 401, $this->rest( 'POST', self::NS . "/courses/{$free}/enroll" )->get_status(), 'Logged out.' );

		$ok = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/courses/{$free}/enroll" ) );
		self::assertSame( 201, $ok->get_status() );
		self::assertTrue( $this->lms->enrollment()->is_enrolled( $user, $free ) );
		self::assertSame( 'self', $this->lms->enrollment()->repository()->find_for( $user, $free )->source );

		self::assertSame( 403, $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/courses/{$paid}/enroll" ) )->get_status(), 'LMS-ENR-008' );
		self::assertSame( 403, $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/courses/{$closed}/enroll" ) )->get_status() );
		self::assertFalse( $this->lms->enrollment()->is_enrolled( $user, $paid ) );
	}

	public function test_complete_step_enforces_the_gate_and_node_kind(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );

		self::assertSame( 401, $this->rest( 'POST', self::NS . "/steps/{$c['lesson1']}/complete" )->get_status() );

		$locked = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/steps/{$c['lesson3']}/complete" ) );
		self::assertSame( 403, $locked->get_status(), 'LMS-ACC-007: the API cannot skip the course.' );

		$ok = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/steps/{$c['lesson1']}/complete" ) );
		self::assertSame( 200, $ok->get_status() );
		self::assertSame( 16.67, $ok->get_data()['percentage'] );

		$section = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/steps/{$c['lesson2']}/complete" ) );
		self::assertSame( 400, $section->get_status(), 'LMS-PRG-003 over REST.' );

		$this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/steps/{$c['topic21']}/complete" ) );
		$this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/steps/{$c['topic22']}/complete" ) );
		$quiz = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/steps/{$c['quiz2']}/complete" ) );
		self::assertSame( 400, $quiz->get_status(), 'LMS-PRG-002 over REST.' );

		self::assertSame( 404, $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . '/steps/999999/complete' ) )->get_status() );
	}

	public function test_quiz_attempt_flow_over_rest(): void {
		$course = $this->lms->course( array( 'meta' => array( Meta::LINEAR_PROGRESSION => 0 ) ) );
		$quiz   = $this->lms->quiz( $course, 0 );
		$q      = $this->lms->single_choice_question( $quiz );
		$user   = $this->lms->enrolled_learner( $course );
		$other  = $this->lms->enrolled_learner( $course );

		$questions = $this->as_user( $user, fn () => $this->rest( 'GET', self::NS . "/quizzes/{$quiz}/questions" ) );
		self::assertSame( 200, $questions->get_status() );
		self::assertSame( $q, $questions->get_data()['questions'][0]['id'] );
		self::assertArrayNotHasKey( 'correct', $questions->get_data()['questions'][0]['options'][0], 'Never leaks the key.' );

		$start = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/quizzes/{$quiz}/attempts" ) );
		self::assertSame( 201, $start->get_status() );
		$attempt = $start->get_data()['attempt_id'];
		self::assertFalse( $start->get_data()['resumed'] );

		$again = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/quizzes/{$quiz}/attempts" ) );
		self::assertSame( $attempt, $again->get_data()['attempt_id'] );
		self::assertTrue( $again->get_data()['resumed'], 'LMS-QZ-001' );

		$stolen = $this->as_user( $other, fn () => $this->rest( 'POST', self::NS . "/attempts/{$attempt}/submit", array( 'answers' => array( $q => 0 ) ) ) );
		self::assertSame( 404, $stolen->get_status(), 'LMS-QZ-005 / ADR-011.' );

		$submit = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/attempts/{$attempt}/submit", array( 'answers' => array( $q => 0 ) ) ) );
		self::assertSame( 200, $submit->get_status() );
		self::assertTrue( $submit->get_data()['passed'] );

		$closed = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/attempts/{$attempt}/submit", array( 'answers' => array() ) ) );
		self::assertSame( 400, $closed->get_status() );
	}

	public function test_if_005_complete_and_submit_say_where_to_go_next(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );

		$done = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/steps/{$c['lesson1']}/complete" ) )->get_data();
		self::assertSame( 1, $done['completed_count'] );
		self::assertSame( 6, $done['total'] );
		self::assertFalse( $done['course_complete'] );
		self::assertSame( get_permalink( $c['course'] ), $done['course_url'] );
		self::assertSame( $c['lesson2'], $done['next_id'], 'Lesson 1 gated lesson 2; completing it unlocks the next link.' );
		self::assertSame( get_permalink( $c['lesson2'] ), $done['next_url'] );

		$this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/steps/{$c['topic21']}/complete" ) );
		$topic = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/steps/{$c['topic22']}/complete" ) )->get_data();
		self::assertSame( $c['quiz2'], $topic['next_id'] );

		$questions = $this->as_user( $user, fn () => $this->rest( 'GET', self::NS . "/quizzes/{$c['quiz2']}/questions" ) )->get_data();
		self::assertNull( $questions['best'], 'No closed attempt yet.' );
		self::assertFalse( $questions['has_open_attempt'] );

		$attempt = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/quizzes/{$c['quiz2']}/attempts" ) )->get_data()['attempt_id'];
		self::assertTrue( $this->as_user( $user, fn () => $this->rest( 'GET', self::NS . "/quizzes/{$c['quiz2']}/questions" ) )->get_data()['has_open_attempt'] );

		$failed = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/attempts/{$attempt}/submit", array( 'answers' => array( $c['question'] => 1 ) ) ) )->get_data();
		self::assertFalse( $failed['passed'] );
		self::assertSame( '', $failed['next_url'], 'Lesson 3 stays locked behind a failed quiz.' );
		self::assertSame( get_permalink( $c['course'] ), $failed['course_url'] );
		self::assertNull( $failed['attempts_remaining'], 'Unlimited attempts.' );

		$best = $this->as_user( $user, fn () => $this->rest( 'GET', self::NS . "/quizzes/{$c['quiz2']}/questions" ) )->get_data()['best'];
		self::assertSame(
			array(
				'percentage' => 0.0,
				'passed'     => false,
			),
			$best
		);

		$attempt = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/quizzes/{$c['quiz2']}/attempts" ) )->get_data()['attempt_id'];
		$passed  = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/attempts/{$attempt}/submit", array( 'answers' => array( $c['question'] => 0 ) ) ) )->get_data();
		self::assertTrue( $passed['passed'] );
		self::assertSame( get_permalink( $c['lesson3'] ), $passed['next_url'] );

		$last = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/steps/{$c['lesson3']}/complete" ) )->get_data();
		self::assertTrue( $last['course_complete'] );
		self::assertSame( '', $last['next_url'] );
		self::assertSame( 100.0, $last['percentage'] );
	}

	public function test_quiz_locked_behind_gate_over_rest(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );

		self::assertSame( 403, $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . "/quizzes/{$c['quiz2']}/attempts" ) )->get_status() );
		self::assertSame( 403, $this->as_user( $user, fn () => $this->rest( 'GET', self::NS . "/quizzes/{$c['quiz2']}/questions" ) )->get_status() );
	}

	public function test_me_courses(): void {
		$c1   = $this->lms->standard_course();
		$c2   = $this->lms->course();
		$user = $this->lms->enrolled_learner( $c1['course'] );
		$this->lms->enrollment()->enroll( $user, $c2 );
		Plugin::instance()->container()->get( Progress::class )->complete_step( $user, $c1['lesson1'] );

		self::assertSame( 401, $this->rest( 'GET', self::NS . '/me/courses' )->get_status() );

		$mine = $this->as_user( $user, fn () => $this->rest( 'GET', self::NS . '/me/courses' ) );
		self::assertSame( 200, $mine->get_status() );
		$by_id = array_column( $mine->get_data()['courses'], null, 'id' );
		self::assertSame( 16.67, $by_id[ $c1['course'] ]['percentage'] );
		self::assertSame( 'active', $by_id[ $c2 ]['status'] );
	}

	public function test_errors_carry_plugin_codes(): void {
		$user = $this->lms->learner();

		$response = $this->as_user( $user, fn () => $this->rest( 'POST', self::NS . '/steps/999999/complete' ) );
		self::assertStringStartsWith( 'odsi_lms_', $response->get_data()['code'] );
	}
}
