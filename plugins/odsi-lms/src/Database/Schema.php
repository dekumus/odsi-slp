<?php
/**
 * Custom table definitions.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Declarative schema for the plugin's custom tables.
 *
 * Authored content (courses, lessons, quizzes) lives in `wp_posts` so that the
 * block editor, revisions, media and the core REST API work unchanged. The
 * tables below hold the high-volume relational data that would otherwise turn
 * into millions of `wp_postmeta` rows: who is enrolled, what they completed,
 * and how they answered.
 */
final class Schema {

	/**
	 * Bumped whenever any table definition below changes.
	 */
	public const DB_VERSION = '1.0.0';

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'odsi_lms_db_version';

	/**
	 * Short table keys mapped to their unprefixed names.
	 *
	 * @var array<string, string>
	 */
	private const TABLES = array(
		'enrollments'   => 'odsi_lms_enrollments',
		'progress'      => 'odsi_lms_progress',
		'quiz_attempts' => 'odsi_lms_quiz_attempts',
		'quiz_answers'  => 'odsi_lms_quiz_answers',
		'submissions'   => 'odsi_lms_submissions',
		'certificates'  => 'odsi_lms_certificates',
	);

	/**
	 * Resolve a prefixed table name from its short key.
	 *
	 * @param string $key One of the keys in self::TABLES.
	 */
	public static function table( string $key ): string {
		global $wpdb;

		if ( ! isset( self::TABLES[ $key ] ) ) {
			return '';
		}

		return $wpdb->prefix . self::TABLES[ $key ];
	}

	/**
	 * Every prefixed table name the plugin owns.
	 *
	 * @return string[]
	 */
	public static function all_tables(): array {
		return array_map( array( self::class, 'table' ), array_keys( self::TABLES ) );
	}

	/**
	 * The dbDelta-compatible CREATE TABLE statements.
	 *
	 * @return string[]
	 */
	public static function statements(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();

		$statements = array();

		// One row per user per course. `status` drives access; `expires_at` supports
		// subscription and drip style access without a second table.
		$table        = self::table( 'enrollments' );
		$statements[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			source varchar(40) NOT NULL DEFAULT 'manual',
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			enrolled_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			expires_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY user_course (user_id,course_id),
			KEY course_status (course_id,status),
			KEY user_status (user_id,status),
			KEY expires_at (expires_at)
		) {$collate};";

		// One row per user per trackable object (course, lesson, topic, quiz).
		// `course_id` is denormalised so course-wide reports never need a join.
		$table        = self::table( 'progress' );
		$statements[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			object_type varchar(20) NOT NULL DEFAULT 'lesson',
			status varchar(20) NOT NULL DEFAULT 'in_progress',
			percentage decimal(5,2) NOT NULL DEFAULT 0.00,
			time_spent int(10) unsigned NOT NULL DEFAULT 0,
			started_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			completed_at datetime DEFAULT NULL,
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY user_object (user_id,object_id),
			KEY user_course (user_id,course_id),
			KEY course_object (course_id,object_id),
			KEY course_status (course_id,status)
		) {$collate};";

		// One row per quiz sitting. Retained after completion so learners and
		// reports can see attempt history rather than a single last-value.
		$table        = self::table( 'quiz_attempts' );
		$statements[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			quiz_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned NOT NULL DEFAULT 0,
			attempt_number smallint(5) unsigned NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'in_progress',
			points_earned decimal(10,2) NOT NULL DEFAULT 0.00,
			points_possible decimal(10,2) NOT NULL DEFAULT 0.00,
			percentage decimal(5,2) NOT NULL DEFAULT 0.00,
			passed tinyint(1) NOT NULL DEFAULT 0,
			time_spent int(10) unsigned NOT NULL DEFAULT 0,
			started_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_quiz (user_id,quiz_id),
			KEY quiz_status (quiz_id,status),
			KEY course_user (course_id,user_id)
		) {$collate};";

		// One row per answered question within an attempt. `answer` is JSON so a
		// single column serves single choice, multi choice, fill-in and essay.
		$table        = self::table( 'quiz_answers' );
		$statements[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attempt_id bigint(20) unsigned NOT NULL,
			question_id bigint(20) unsigned NOT NULL,
			answer longtext NULL,
			points_earned decimal(10,2) NOT NULL DEFAULT 0.00,
			points_possible decimal(10,2) NOT NULL DEFAULT 0.00,
			is_correct tinyint(1) NOT NULL DEFAULT 0,
			needs_grading tinyint(1) NOT NULL DEFAULT 0,
			answered_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY attempt_question (attempt_id,question_id),
			KEY question_id (question_id),
			KEY needs_grading (needs_grading)
		) {$collate};";

		// Assignment / graded upload submissions.
		$table        = self::table( 'submissions' );
		$statements[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned NOT NULL DEFAULT 0,
			lesson_id bigint(20) unsigned NOT NULL DEFAULT 0,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			content longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			points_earned decimal(10,2) NOT NULL DEFAULT 0.00,
			points_possible decimal(10,2) NOT NULL DEFAULT 0.00,
			feedback longtext NULL,
			graded_by bigint(20) unsigned NOT NULL DEFAULT 0,
			submitted_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			graded_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_lesson (user_id,lesson_id),
			KEY course_status (course_id,status),
			KEY status (status)
		) {$collate};";

		// Issued certificates. The rendered PDF is regenerated on demand; only the
		// immutable award record and its public code are stored.
		$table        = self::table( 'certificates' );
		$statements[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned NOT NULL,
			certificate_id bigint(20) unsigned NOT NULL DEFAULT 0,
			code varchar(64) NOT NULL DEFAULT '',
			percentage decimal(5,2) NOT NULL DEFAULT 0.00,
			issued_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			expires_at datetime DEFAULT NULL,
			revoked_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY user_course (user_id,course_id),
			KEY course_id (course_id)
		) {$collate};";

		return $statements;
	}
}
