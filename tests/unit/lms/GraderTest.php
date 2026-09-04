<?php
/**
 * Grader unit tests. Spec: LMS-QZ question type table, LMS-QZ-023.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Unit\LMS;

use ODSI\LMS\Quizzes\Grader;
use ODSI\LMS\Support\Meta;
use ODSI\Tests\Unit\TestCase;

final class GraderTest extends TestCase {

	private const QUESTION = 10;

	private function question( string $type, array $answers, int $points = 1 ): void {
		$this->stub_post_meta(
			array(
				self::QUESTION => array(
					Meta::QUESTION_TYPE    => $type,
					Meta::QUESTION_POINTS  => $points,
					Meta::QUESTION_ANSWERS => $answers,
				),
			)
		);
	}

	private function choices( array $correct_flags ): array {
		return array_map(
			static fn ( bool $correct, int $i ): array => array(
				'text' => "Option {$i}",
				'correct' => $correct,
			),
			$correct_flags,
			array_keys( $correct_flags )
		);
	}

	public function test_single_choice_correct_index_scores_full_points(): void {
		$this->question( 'single', $this->choices( array( false, true, false ) ), 3 );

		$grade = ( new Grader() )->grade( self::QUESTION, 1 );

		self::assertTrue( $grade['is_correct'] );
		self::assertSame( 3.0, $grade['points_earned'] );
		self::assertSame( 3.0, $grade['points_possible'] );
		self::assertFalse( $grade['needs_grading'] );
	}

	public function test_single_choice_wrong_index_scores_zero(): void {
		$this->question( 'single', $this->choices( array( false, true, false ) ), 3 );

		$grade = ( new Grader() )->grade( self::QUESTION, 0 );

		self::assertFalse( $grade['is_correct'] );
		self::assertSame( 0.0, $grade['points_earned'] );
		self::assertSame( 3.0, $grade['points_possible'] );
	}

	public function test_single_choice_accepts_index_wrapped_in_array(): void {
		$this->question( 'single', $this->choices( array( true, false ) ) );

		self::assertTrue( ( new Grader() )->grade( self::QUESTION, array( 0 ) )['is_correct'] );
	}

	/**
	 * @dataProvider empty_answers
	 */
	public function test_single_choice_empty_or_missing_answer_is_wrong( mixed $submitted ): void {
		$this->question( 'single', $this->choices( array( false, true ) ) );

		self::assertFalse( ( new Grader() )->grade( self::QUESTION, $submitted )['is_correct'] );
	}

	public function empty_answers(): array {
		return array(
			'null'         => array( null ),
			'empty string' => array( '' ),
			'empty array'  => array( array() ),
			'out of range' => array( 99 ),
			'negative'     => array( -1 ),
		);
	}

	public function test_single_choice_with_no_correct_option_never_passes(): void {
		$this->question( 'single', $this->choices( array( false, false ) ) );

		$grader = new Grader();

		self::assertFalse( $grader->grade( self::QUESTION, 0 )['is_correct'] );
		self::assertFalse( $grader->grade( self::QUESTION, 1 )['is_correct'] );
		self::assertSame( 1.0, $grader->grade( self::QUESTION, 0 )['points_possible'], 'A broken question still counts toward the possible total.' );
	}

	public function test_true_false_behaves_as_single(): void {
		$this->question( 'true_false', $this->choices( array( true, false ) ) );

		$grader = new Grader();

		self::assertTrue( $grader->grade( self::QUESTION, 0 )['is_correct'] );
		self::assertFalse( $grader->grade( self::QUESTION, 1 )['is_correct'] );
	}

	public function test_multiple_requires_exact_set(): void {
		$this->question( 'multiple', $this->choices( array( true, false, true, false ) ), 2 );

		$grader = new Grader();

		self::assertTrue( $grader->grade( self::QUESTION, array( 0, 2 ) )['is_correct'] );
		self::assertTrue( $grader->grade( self::QUESTION, array( 2, 0 ) )['is_correct'], 'Order must not matter.' );
		self::assertTrue( $grader->grade( self::QUESTION, array( '2', '0' ) )['is_correct'], 'String indexes from a form must be accepted.' );
		self::assertFalse( $grader->grade( self::QUESTION, array( 0 ) )['is_correct'], 'Missing a correct option fails.' );
		self::assertFalse( $grader->grade( self::QUESTION, array( 0, 1, 2 ) )['is_correct'], 'An extra wrong option fails.' );
		self::assertFalse( $grader->grade( self::QUESTION, array() )['is_correct'] );
		self::assertSame( 0.0, $grader->grade( self::QUESTION, array( 0 ) )['points_earned'], 'No partial credit in v1.' );
	}

	public function test_multiple_with_no_correct_options_never_passes(): void {
		$this->question( 'multiple', $this->choices( array( false, false ) ) );

		self::assertFalse( ( new Grader() )->grade( self::QUESTION, array() )['is_correct'] );
	}

	public function test_fill_blank_is_case_and_whitespace_insensitive(): void {
		$this->question( 'fill_blank', array( array( 'text' => 'Paris' ), array( 'text' => 'the City of Light' ) ) );

		$grader = new Grader();

		self::assertTrue( $grader->grade( self::QUESTION, 'paris' )['is_correct'] );
		self::assertTrue( $grader->grade( self::QUESTION, '  PARIS ' )['is_correct'] );
		self::assertTrue( $grader->grade( self::QUESTION, "the   city\tof light" )['is_correct'] );
		self::assertFalse( $grader->grade( self::QUESTION, 'Pari' )['is_correct'] );
		self::assertFalse( $grader->grade( self::QUESTION, '' )['is_correct'] );
		self::assertFalse( $grader->grade( self::QUESTION, null )['is_correct'] );
	}

	public function test_fill_blank_with_no_accepted_answers_never_passes(): void {
		$this->question( 'fill_blank', array() );

		self::assertFalse( ( new Grader() )->grade( self::QUESTION, 'anything' )['is_correct'] );
	}

	public function test_essay_needs_grading_and_awards_nothing_yet(): void {
		$this->question( 'essay', array(), 5 );

		$grade = ( new Grader() )->grade( self::QUESTION, 'A thoughtful paragraph.' );

		self::assertTrue( $grade['needs_grading'] );
		self::assertFalse( $grade['is_correct'] );
		self::assertSame( 0.0, $grade['points_earned'] );
		self::assertSame( 5.0, $grade['points_possible'] );
	}

	public function test_unknown_type_fails_closed(): void {
		$this->question( 'hologram', $this->choices( array( true ) ), 4 );

		$grade = ( new Grader() )->grade( self::QUESTION, 0 );

		self::assertFalse( $grade['needs_grading'] );
		self::assertSame( 4.0, $grade['points_possible'] );
		// The default branch grades as single choice; with a correct option at 0 it
		// would pass. The spec (LMS-QZ-023) requires unknown types to fail closed.
		self::assertFalse( $grade['is_correct'], 'LMS-QZ-023: an unknown question type must not pass.' );
	}

	public function test_missing_type_defaults_to_single(): void {
		$this->stub_post_meta(
			array(
				self::QUESTION => array(
					Meta::QUESTION_ANSWERS => $this->choices( array( true ) ),
				),
			)
		);

		$grade = ( new Grader() )->grade( self::QUESTION, 0 );

		self::assertTrue( $grade['is_correct'] );
		self::assertSame( 1.0, $grade['points_possible'], 'Missing points default to 1.' );
	}

	public function test_filter_can_override_the_grade(): void {
		$this->question( 'single', $this->choices( array( true ) ) );

		\Brain\Monkey\Filters\expectApplied( 'odsi_lms_grade_answer' )
			->once()
			->andReturn(
				array(
					'points_earned'   => 0.5,
					'points_possible' => 1.0,
					'is_correct'      => false,
					'needs_grading'   => false,
				)
			);

		self::assertSame( 0.5, ( new Grader() )->grade( self::QUESTION, 0 )['points_earned'] );
	}
}
