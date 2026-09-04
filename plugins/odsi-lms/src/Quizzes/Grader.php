<?php
/**
 * Answer grading.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Quizzes;

use ODSI\LMS\Support\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Grades a single submitted answer against its question definition.
 *
 * Grading is deliberately separate from persistence so it can be unit tested
 * without a database, and so alternative question types can be added by
 * filtering a single result array.
 */
final class Grader {

	public const TYPE_SINGLE     = 'single';
	public const TYPE_MULTIPLE   = 'multiple';
	public const TYPE_TRUE_FALSE = 'true_false';
	public const TYPE_FILL_BLANK = 'fill_blank';
	public const TYPE_ESSAY      = 'essay';

	/**
	 * Question types that a human has to mark.
	 *
	 * @return string[]
	 */
	public static function manually_graded(): array {
		return array( self::TYPE_ESSAY );
	}

	/**
	 * Grade one answer.
	 *
	 * @param int   $question_id Question post id.
	 * @param mixed $submitted   Raw submitted answer.
	 *
	 * @return array{points_earned: float, points_possible: float, is_correct: bool, needs_grading: bool}
	 */
	public function grade( int $question_id, mixed $submitted ): array {
		$type    = (string) get_post_meta( $question_id, Meta::QUESTION_TYPE, true ) ?: self::TYPE_SINGLE;
		$points  = (float) get_post_meta( $question_id, Meta::QUESTION_POINTS, true ) ?: 1.0;
		$answers = (array) get_post_meta( $question_id, Meta::QUESTION_ANSWERS, true );
		$correct = false;
		$manual  = in_array( $type, self::manually_graded(), true );

		if ( ! $manual ) {
			// An unrecognised type fails closed (LMS-QZ-023): a custom type that
			// nobody handles through the filter below must not award points.
			$correct = match ( $type ) {
				self::TYPE_SINGLE,
				self::TYPE_TRUE_FALSE => $this->grade_single( $answers, $submitted ),
				self::TYPE_MULTIPLE   => $this->grade_multiple( $answers, $submitted ),
				self::TYPE_FILL_BLANK => $this->grade_fill_blank( $answers, $submitted ),
				default               => false,
			};
		}

		$result = array(
			'points_earned'   => $correct ? $points : 0.0,
			'points_possible' => $points,
			'is_correct'      => $correct,
			'needs_grading'   => $manual,
		);

		/**
		 * Filters the grade awarded for a single answer.
		 *
		 * Add support for a custom question type by returning your own result here.
		 *
		 * @param array{points_earned: float, points_possible: float, is_correct: bool, needs_grading: bool} $result      Grade.
		 * @param int                                                                                       $question_id Question post id.
		 * @param mixed                                                                                     $submitted   Submitted answer.
		 * @param string                                                                                    $type        Question type.
		 */
		return (array) apply_filters( 'odsi_lms_grade_answer', $result, $question_id, $submitted, $type );
	}

	/**
	 * Grade a single-choice or true/false answer.
	 *
	 * @param array<int, array<string, mixed>> $answers   Answer definitions.
	 * @param mixed                            $submitted Submitted answer index.
	 */
	private function grade_single( array $answers, mixed $submitted ): bool {
		if ( $this->is_blank( $submitted ) ) {
			return false;
		}

		$value = is_array( $submitted ) ? reset( $submitted ) : $submitted;

		if ( ! is_numeric( $value ) ) {
			return false;
		}

		return ! empty( $answers[ (int) $value ]['correct'] );
	}

	/**
	 * Whether a submission is empty: no answer given at all.
	 *
	 * An unanswered question must never be read as "option 0" (LMS-QZ-007).
	 *
	 * @param mixed $submitted Raw submission.
	 */
	private function is_blank( mixed $submitted ): bool {
		if ( null === $submitted || '' === $submitted || array() === $submitted ) {
			return true;
		}

		return is_array( $submitted ) && '' === trim( (string) reset( $submitted ) );
	}

	/**
	 * Grade a multiple-response answer. Every correct option, and only those.
	 *
	 * @param array<int, array<string, mixed>> $answers   Answer definitions.
	 * @param mixed                            $submitted Submitted answer indexes.
	 */
	private function grade_multiple( array $answers, mixed $submitted ): bool {
		if ( $this->is_blank( $submitted ) ) {
			return false;
		}

		$expected = array();

		foreach ( $answers as $index => $answer ) {
			if ( ! empty( $answer['correct'] ) ) {
				$expected[] = (int) $index;
			}
		}

		$given = array_map( 'intval', (array) $submitted );

		sort( $expected );
		sort( $given );

		return ! empty( $expected ) && $expected === $given;
	}

	/**
	 * Grade a fill-in-the-blank answer against the accepted strings.
	 *
	 * Comparison is case and whitespace insensitive, which is what graders
	 * almost always want and what learners almost always expect.
	 *
	 * @param array<int, array<string, mixed>> $answers   Answer definitions.
	 * @param mixed                            $submitted Submitted text.
	 */
	private function grade_fill_blank( array $answers, mixed $submitted ): bool {
		$given = $this->normalise( is_array( $submitted ) ? (string) reset( $submitted ) : (string) $submitted );

		if ( '' === $given ) {
			return false;
		}

		foreach ( $answers as $answer ) {
			if ( $this->normalise( (string) ( $answer['text'] ?? '' ) ) === $given ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lowercase and collapse whitespace for text comparison.
	 *
	 * @param string $value Raw text.
	 */
	private function normalise( string $value ): string {
		return strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $value ) ) );
	}
}
