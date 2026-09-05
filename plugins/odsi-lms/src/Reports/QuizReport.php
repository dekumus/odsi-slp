<?php
/**
 * Quiz results: totals per quiz and a per-question breakdown.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Reports;

defined( 'ABSPATH' ) || exit;

use ODSI\LMS\Database\Schema;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Repositories\QuizAttemptRepository;
use ODSI\LMS\Support\Meta;
use WP_Query;
use wpdb;

/**
 * Read-only aggregates over completed attempts (LMS-ADM-009). Open attempts
 * never count; every number is one GROUP BY, so the screen costs two queries.
 */
final class QuizReport {

	/**
	 * Database.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null $db Database, defaults to the global.
	 */
	public function __construct( ?wpdb $db = null ) {
		$this->db = $db ?? $GLOBALS['wpdb'];
	}

	/**
	 * Quizzes attached anywhere in a course, for the picker.
	 *
	 * @param int $course_id Course post id.
	 *
	 * @return int[]
	 */
	public function quizzes_for_course( int $course_id ): array {
		$query = new WP_Query(
			array(
				'post_type'              => PostTypes::QUIZ,
				'post_status'            => array( 'publish', 'private', 'draft' ),
				'posts_per_page'         => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded admin/outline read.
				'fields'                 => 'ids',
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- outline membership is meta by design.
					array(
						'key'   => Meta::COURSE_ID,
						'value' => $course_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Headline numbers for a quiz.
	 *
	 * @param int $quiz_id Quiz post id.
	 *
	 * @return array{attempts: int, learners: int, passed: int, pass_rate: float, average: float}
	 */
	public function summary( int $quiz_id ): array {
		$table = Schema::table( 'quiz_attempts' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT COUNT(*) AS attempts, COUNT(DISTINCT user_id) AS learners, SUM(passed) AS passed, AVG(percentage) AS average
				 FROM {$table} WHERE quiz_id = %d AND status = %s",
				$quiz_id,
				QuizAttemptRepository::STATUS_COMPLETED
			)
		);

		$attempts = (int) ( $row->attempts ?? 0 );
		$passed   = (int) ( $row->passed ?? 0 );

		return array(
			'attempts'  => $attempts,
			'learners'  => (int) ( $row->learners ?? 0 ),
			'passed'    => $passed,
			'pass_rate' => $attempts > 0 ? round( ( $passed / $attempts ) * 100, 1 ) : 0.0,
			'average'   => round( (float) ( $row->average ?? 0 ), 1 ),
		);
	}

	/**
	 * One row per question: how often it was answered, how often correctly,
	 * the average points and how many answers still wait for grading.
	 *
	 * @param int $quiz_id Quiz post id.
	 *
	 * @return array<int, array{question_id: int, title: string, type: string, answered: int, correct: int, correct_rate: float, average_points: float, points_possible: float, needs_grading: int}>
	 */
	public function breakdown( int $quiz_id ): array {
		$answers  = Schema::table( 'quiz_answers' );
		$attempts = Schema::table( 'quiz_attempts' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $this->db->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->prepare(
				"SELECT a.question_id, COUNT(*) AS answered, SUM(a.is_correct) AS correct, AVG(a.points_earned) AS average_points,
				        MAX(a.points_possible) AS points_possible, SUM(a.needs_grading) AS needs_grading
				 FROM {$answers} a INNER JOIN {$attempts} t ON t.id = a.attempt_id
				 WHERE t.quiz_id = %d AND t.status = %s
				 GROUP BY a.question_id",
				$quiz_id,
				QuizAttemptRepository::STATUS_COMPLETED
			)
		);

		$by_question = array();

		foreach ( $rows as $row ) {
			$by_question[ (int) $row->question_id ] = $row;
		}

		// Walk the quiz's current questions in order, then any that were
		// answered but have since been removed, so nothing is silently dropped.
		$ordered = array_merge( $this->question_ids( $quiz_id ), array_keys( $by_question ) );
		$out     = array();

		foreach ( array_unique( $ordered ) as $question_id ) {
			$row      = $by_question[ $question_id ] ?? null;
			$answered = (int) ( $row->answered ?? 0 );
			$correct  = (int) ( $row->correct ?? 0 );
			$post     = get_post( $question_id );

			$out[] = array(
				'question_id'     => $question_id,
				'title'           => $post ? (string) $post->post_title : __( '(deleted question)', 'odsi-lms' ),
				'type'            => (string) get_post_meta( $question_id, Meta::QUESTION_TYPE, true ),
				'answered'        => $answered,
				'correct'         => $correct,
				'correct_rate'    => $answered > 0 ? round( ( $correct / $answered ) * 100, 1 ) : 0.0,
				'average_points'  => round( (float) ( $row->average_points ?? 0 ), 2 ),
				'points_possible' => (float) ( $row->points_possible ?? get_post_meta( $question_id, Meta::QUESTION_POINTS, true ) ?: 1 ),
				'needs_grading'   => (int) ( $row->needs_grading ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * Stream the breakdown as CSV (same formula guard as the enrollment export).
	 *
	 * @param int      $quiz_id Quiz post id.
	 * @param resource $handle  Open stream.
	 *
	 * @return int Rows written.
	 */
	public function export_csv( int $quiz_id, $handle ): int {
		fputcsv( $handle, array( 'question_id', 'question', 'type', 'answered', 'correct', 'correct_rate', 'average_points', 'points_possible', 'needs_grading' ) );

		$rows = 0;

		foreach ( $this->breakdown( $quiz_id ) as $row ) {
			fputcsv(
				$handle,
				array(
					$row['question_id'],
					EnrollmentReport::csv_safe( $row['title'] ),
					$row['type'],
					$row['answered'],
					$row['correct'],
					$row['correct_rate'],
					$row['average_points'],
					$row['points_possible'],
					$row['needs_grading'],
				)
			);
			++$rows;
		}

		return $rows;
	}

	/**
	 * Question ids of a quiz in menu order, any status but trash.
	 *
	 * @param int $quiz_id Quiz post id.
	 *
	 * @return int[]
	 */
	private function question_ids( int $quiz_id ): array {
		$query = new WP_Query(
			array(
				'post_type'              => PostTypes::QUESTION,
				'post_status'            => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded admin/outline read.
				'fields'                 => 'ids',
				'orderby'                => array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
					'ID'         => 'ASC',
				),
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- outline membership is meta by design.
					array(
						'key'   => Meta::QUIZ_ID,
						'value' => $quiz_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}
}
