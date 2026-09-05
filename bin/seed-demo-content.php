<?php
/**
 * Seed demo content across all three plugins for a fresh local install.
 *
 * Run with WP-CLI once the site is installed and the plugins are active:
 *
 *   php .cache/wp-cli.phar --path=/tmp/odsi-site --allow-root eval-file bin/seed-demo-content.php
 *
 * (adjust --path to your ODSI_SITE_DIR if different). Safe to re-run: it
 * looks up existing users/posts by name/title before creating new ones, so
 * running it twice does not duplicate content, though it will re-post a
 * fresh activity item and re-issue enrollments each time.
 *
 * @package ODSI\Demo
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this through wp-cli's eval-file, not directly with php.\n" );
	exit( 1 );
}

/**
 * Get or create a user with a role, returning the user id.
 *
 * @param string $login Username.
 * @param string $role  Role slug.
 * @param string $name  Display name.
 */
function odsi_demo_user( string $login, string $role, string $name ): int {
	$user = get_user_by( 'login', $login );

	if ( $user ) {
		return (int) $user->ID;
	}

	$id = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => 'demo-pass-' . wp_generate_password( 8, false ),
			'user_email'   => $login . '@example.org',
			'display_name' => $name,
			'role'         => $role,
		)
	);

	return is_wp_error( $id ) ? 0 : (int) $id;
}

/**
 * Find a post by title and type without creating one.
 *
 * @param string $type  Post type.
 * @param string $title Title.
 */
function odsi_demo_find( string $type, string $title ): int {
	$found = get_posts(
		array(
			'post_type'              => $type,
			'title'                  => $title,
			'post_status'            => 'any',
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	return array() !== $found ? (int) $found[0]->ID : 0;
}

/**
 * Get or create a post by title and type, returning its id.
 *
 * @param string               $type  Post type.
 * @param string               $title Title.
 * @param array<string, mixed> $args  Extra wp_insert_post args.
 */
function odsi_demo_post( string $type, string $title, array $args = array() ): int {
	$found = get_posts(
		array(
			'post_type'              => $type,
			'title'                  => $title,
			'post_status'            => 'any',
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	if ( array() !== $found ) {
		return (int) $found[0]->ID;
	}

	$id = wp_insert_post(
		array_merge(
			array(
				'post_type'   => $type,
				'post_title'  => $title,
				'post_status' => 'publish',
			),
			$args
		),
		true
	);

	return is_wp_error( $id ) ? 0 : (int) $id;
}

echo "Seeding LMS demo content...\n";

if ( class_exists( '\ODSI\LMS\Plugin' ) ) {
	$lms = \ODSI\LMS\Plugin::instance()->container();

	$instructor = odsi_demo_user( 'instructor', 'odsi_instructor', 'Priya Instructor' );
	$learner1   = odsi_demo_user( 'learner1', 'subscriber', 'Sam Learner' );
	$learner2   = odsi_demo_user( 'learner2', 'subscriber', 'Alex Student' );

	$course = odsi_demo_post(
		'odsi_course',
		'Introduction to Sourdough Baking',
		array(
			'post_content' => 'Learn to bake naturally leavened bread from scratch, from starter to loaf.',
			'post_author'  => $instructor,
		)
	);
	update_post_meta( $course, \ODSI\LMS\Support\Meta::ACCESS_MODE, 'free' );
	update_post_meta( $course, \ODSI\LMS\Support\Meta::LINEAR_PROGRESSION, true );

	$lesson1 = odsi_demo_post( 'odsi_lesson', 'Kitchen Basics', array( 'post_content' => 'The tools and ingredients you need before you start.', 'post_author' => $instructor, 'menu_order' => 1 ) );
	update_post_meta( $lesson1, \ODSI\LMS\Support\Meta::COURSE_ID, $course );

	$topic11 = odsi_demo_post( 'odsi_topic', 'Tools of the Trade', array( 'post_content' => 'A bench scraper, a Dutch oven, and a kitchen scale.', 'post_author' => $instructor, 'menu_order' => 1 ) );
	update_post_meta( $topic11, \ODSI\LMS\Support\Meta::LESSON_ID, $lesson1 );
	update_post_meta( $topic11, \ODSI\LMS\Support\Meta::COURSE_ID, $course );

	$topic12 = odsi_demo_post( 'odsi_topic', 'Reading a Recipe', array( 'post_content' => "Baker's percentages explained.", 'post_author' => $instructor, 'menu_order' => 2 ) );
	update_post_meta( $topic12, \ODSI\LMS\Support\Meta::LESSON_ID, $lesson1 );
	update_post_meta( $topic12, \ODSI\LMS\Support\Meta::COURSE_ID, $course );

	$lesson2 = odsi_demo_post( 'odsi_lesson', 'Your First Loaf', array( 'post_content' => 'Mix, fold, shape, bake. Photograph your result below.', 'post_author' => $instructor, 'menu_order' => 2 ) );
	update_post_meta( $lesson2, \ODSI\LMS\Support\Meta::COURSE_ID, $course );
	update_post_meta( $lesson2, \ODSI\LMS\Support\Meta::ASSIGNMENT_REQUIRED, true );
	update_post_meta( $lesson2, \ODSI\LMS\Support\Meta::ASSIGNMENT_POINTS, 10 );

	$quiz = odsi_demo_post( 'odsi_quiz', 'Baking Basics Quiz', array( 'post_author' => $instructor ) );
	update_post_meta( $quiz, \ODSI\LMS\Support\Meta::COURSE_ID, $course );
	update_post_meta( $quiz, \ODSI\LMS\Support\Meta::LESSON_ID, $lesson1 );
	update_post_meta( $quiz, \ODSI\LMS\Support\Meta::PASS_MARK, 70 );

	$q1 = odsi_demo_post( 'odsi_question', 'Which flour has the highest protein content?', array( 'post_author' => $instructor ) );
	update_post_meta( $q1, \ODSI\LMS\Support\Meta::QUIZ_ID, $quiz );
	update_post_meta( $q1, \ODSI\LMS\Support\Meta::QUESTION_TYPE, \ODSI\LMS\Quizzes\Grader::TYPE_SINGLE );
	update_post_meta( $q1, \ODSI\LMS\Support\Meta::QUESTION_POINTS, 1 );
	update_post_meta(
		$q1,
		\ODSI\LMS\Support\Meta::QUESTION_ANSWERS,
		\ODSI\LMS\Admin\QuestionMetaBox::parse( \ODSI\LMS\Quizzes\Grader::TYPE_SINGLE, "Cake flour\n*Bread flour\nPastry flour", false )
	);

	$q2 = odsi_demo_post( 'odsi_question', 'Sourdough starter uses commercial yeast.', array( 'post_author' => $instructor ) );
	update_post_meta( $q2, \ODSI\LMS\Support\Meta::QUIZ_ID, $quiz );
	update_post_meta( $q2, \ODSI\LMS\Support\Meta::QUESTION_TYPE, \ODSI\LMS\Quizzes\Grader::TYPE_TRUE_FALSE );
	update_post_meta( $q2, \ODSI\LMS\Support\Meta::QUESTION_POINTS, 1 );
	update_post_meta( $q2, \ODSI\LMS\Support\Meta::QUESTION_ANSWERS, \ODSI\LMS\Admin\QuestionMetaBox::parse( \ODSI\LMS\Quizzes\Grader::TYPE_TRUE_FALSE, '', false ) );

	$certificate = odsi_demo_post( 'odsi_certificate', 'Sourdough Certificate', array( 'post_content' => '{name} completed {course} on {date}. Certificate {code}.', 'post_author' => $instructor ) );
	update_post_meta( $course, \ODSI\LMS\Support\Meta::CERTIFICATE_ID, $certificate );

	// A second, paid course to show that access mode in the UI.
	$course2 = odsi_demo_post(
		'odsi_course',
		'Advanced Sourdough Techniques',
		array( 'post_content' => 'Levain builds, high-hydration doughs, and open crumb.', 'post_author' => $instructor )
	);
	update_post_meta( $course2, \ODSI\LMS\Support\Meta::ACCESS_MODE, 'paid' );
	update_post_meta( $course2, \ODSI\LMS\Support\Meta::PRICE, 49 );
	update_post_meta( $course2, \ODSI\LMS\Support\Meta::PREREQUISITES, array( $course ) );

	$enrollment = $lms->get( \ODSI\LMS\Courses\Enrollment::class );
	$progress   = $lms->get( \ODSI\LMS\Courses\Progress::class );

	$enrollment->enroll( $learner1, $course, array( 'source' => 'manual', 'source_id' => $instructor ) );

	$enrollment->enroll( $learner2, $course, array( 'source' => 'manual', 'source_id' => $instructor ) );
	foreach ( array( $lesson1, $topic11, $topic12 ) as $step ) {
		$progress->complete_step( $learner2, $step );
	}

	echo "LMS: course '{$course}' with 2 lessons, 2 topics, a quiz and a paid follow-on course. Learners enrolled.\n";

	// The course archive is automatic (the plugin registers it), but
	// "My Courses" is a personalised shortcode with no default route, so a
	// fresh install has no page for it. Create one.
	$my_courses = odsi_demo_post( 'page', 'My Courses', array( 'post_content' => '<!-- wp:shortcode -->[odsi_my_courses]<!-- /wp:shortcode -->' ) );
	echo "LMS: 'My Courses' page ready at " . get_permalink( $my_courses ) . "\n";
} else {
	echo "LMS plugin not active, skipping.\n";
}

echo "Seeding community demo content...\n";

if ( class_exists( '\ODSI\Social\Plugin' ) ) {
	$social = \ODSI\Social\Plugin::instance()->container();

	$instructor = get_user_by( 'login', 'instructor' );
	$learner1   = get_user_by( 'login', 'learner1' );
	$learner2   = get_user_by( 'login', 'learner2' );

	$instructor_id = $instructor ? (int) $instructor->ID : 0;
	$learner1_id   = $learner1 ? (int) $learner1->ID : 0;
	$learner2_id   = $learner2 ? (int) $learner2->ID : 0;

	if ( $instructor_id && $learner1_id && $learner2_id ) {
		$groups = $social->get( \ODSI\Social\Groups\Groups::class );
		$members = $social->get( \ODSI\Social\Repositories\GroupMemberRepository::class );

		$group_id = odsi_demo_find( 'odsi_social_group', 'Home Bakers Circle' );

		if ( ! $group_id ) {
			$created  = $groups->create(
				$instructor_id,
				array(
					'name'        => 'Home Bakers Circle',
					'description' => 'Share your bakes, ask questions, swap starters.',
					'visibility'  => 'public',
				)
			);
			$group_id = is_wp_error( $created ) ? 0 : $created;
		}

		if ( $group_id && ! $members->find_for( $group_id, $learner1_id ) ) {
			$members->put( $group_id, $learner1_id, \ODSI\Social\Repositories\GroupMemberRepository::ROLE_MEMBER, \ODSI\Social\Repositories\GroupMemberRepository::STATUS_ACTIVE );
		}

		if ( $group_id && ! $members->find_for( $group_id, $learner2_id ) ) {
			$members->put( $group_id, $learner2_id, \ODSI\Social\Repositories\GroupMemberRepository::ROLE_MEMBER, \ODSI\Social\Repositories\GroupMemberRepository::STATUS_ACTIVE );
		}

		$activity = $social->get( \ODSI\Social\Activity\Activity::class );

		$post = $activity->post(
			array(
				'user_id'  => $instructor_id,
				'content'  => 'Welcome to the bakery! Post your first loaf below.',
				'privacy'  => 'public',
			)
		);
		$post_id = is_wp_error( $post ) ? 0 : (int) $post->id;

		if ( $post_id ) {
			$activity->comment( $learner1_id, $post_id, 'Excited to get started!' );
			$reactions = $social->get( \ODSI\Social\Activity\Reactions::class );
			$reactions->set( $learner2_id, $post_id, 'like' );
		}

		if ( $group_id ) {
			$activity->post(
				array(
					'user_id'  => $learner1_id,
					'content'  => 'My starter is finally bubbly after a week!',
					'group_id' => $group_id,
				)
			);
		}

		$connections = $social->get( \ODSI\Social\Connections\Connections::class );
		$connections->request( $learner1_id, $learner2_id );
		$connections->accept( $learner2_id, $learner1_id );

		$messages = $social->get( \ODSI\Social\Messages\Messages::class );
		$messages->send( $learner1_id, $instructor_id, 'Do you have a gluten-free starter recipe?' );

		echo "Community: group '{$group_id}', two members, a feed post with a comment and a like, a group post, a connection and a message.\n";
	} else {
		echo "Community: LMS demo users not found, skipping (run the LMS section first).\n";
	}
} else {
	echo "Community plugin not active, skipping.\n";
}

echo "Done.\n";
