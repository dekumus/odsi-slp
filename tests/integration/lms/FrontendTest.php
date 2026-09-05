<?php
/**
 * Front-end markup, states and accessibility. Spec: LMS-IF-005, LMS-IF-006,
 * LMS-IF-007, plus the excerpt regression in ContentDecorator.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\LMS;

use ODSI\LMS\Assignments\Assignments;
use ODSI\LMS\Certificates\Certificates;
use ODSI\LMS\Courses\Access;
use ODSI\LMS\Courses\Progress;
use ODSI\LMS\Courses\Structure;
use ODSI\LMS\Plugin;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Quizzes\QuizService;
use ODSI\LMS\Repositories\CertificateRepository;
use ODSI\LMS\Repositories\EnrollmentRepository;
use ODSI\LMS\Support\Meta;
use ODSI\Tests\Integration\TestCase;

final class FrontendTest extends TestCase {

	private Progress $progress;

	public function set_up(): void {
		parent::set_up();
		Plugin::instance()->container()->get( Structure::class )->flush();
		Plugin::instance()->container()->get( Access::class )->forget();
		$this->progress = Plugin::instance()->container()->get( Progress::class );
	}

	/**
	 * Render a post the way a theme does: the main query, in the loop.
	 */
	private function render( int $user_id, int $post_id ): string {
		return $this->as_user(
			$user_id,
			function () use ( $post_id ): string {
				$this->go_to( (string) get_permalink( $post_id ) );
				the_post();

				return (string) apply_filters( 'the_content', get_post( $post_id )->post_content );
			}
		);
	}

	public function test_if_007_outline_marks_state_as_text_and_the_current_step(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );
		$this->progress->complete_step( $user, $c['lesson1'] );

		$html = $this->render( $user, $c['lesson1'] );

		self::assertStringContainsString( '<section class="odsi-lms-outline-section odsi-lms-outline-section--step" aria-labelledby="odsi-lms-outline-heading-' . $c['course'] . '">', $html );
		self::assertStringContainsString( '<h2 class="odsi-lms-outline-section__heading"', $html, 'The theme owns the h1; plugin sections start at h2.' );
		self::assertMatchesRegularExpression( '/odsi-lms-outline__item--complete[^>]*data-step-id="' . $c['lesson1'] . '"/', $html );
		self::assertStringContainsString( 'aria-current="page"', $html );
		self::assertStringContainsString( 'odsi-lms-outline__status--complete">Completed<', $html );
		self::assertStringContainsString( 'odsi-lms-outline__status--locked">Locked<', $html, 'Locked is written out, not only coloured.' );
		self::assertMatchesRegularExpression( '/odsi-lms-outline__item--section[^>]*data-step-id="' . $c['lesson2'] . '"/', $html );
		self::assertStringNotContainsString( 'is-locked', $html );
		self::assertStringNotContainsString( 'is-complete', $html );
		self::assertStringNotContainsString( 'aria-disabled', $html, 'A span is not a control; no aria-disabled on it.' );
	}

	public function test_if_005_step_pages_carry_navigation_that_respects_locks(): void {
		$c    = $this->lms->standard_course();
		$user = $this->lms->enrolled_learner( $c['course'] );

		$html = $this->render( $user, $c['lesson1'] );
		self::assertStringContainsString( '<nav class="odsi-lms-step-nav" aria-label="Course navigation">', $html );
		self::assertStringContainsString( 'odsi-lms-step-nav__link--course" href="' . esc_url( get_permalink( $c['course'] ) ) . '"', $html );
		self::assertStringNotContainsString( 'odsi-lms-step-nav__link--previous', $html, 'First step has no previous.' );
		self::assertStringContainsString( 'odsi-lms-step-nav__link--next odsi-lms-step-nav__link--locked" data-step-id="' . $c['lesson2'] . '"', $html, 'Gated next step is text, not a link.' );

		$this->progress->complete_step( $user, $c['lesson1'] );
		Plugin::instance()->container()->get( Access::class )->forget();
		$html = $this->render( $user, $c['lesson1'] );
		self::assertStringContainsString( 'odsi-lms-step-nav__link--next" rel="next" href="' . esc_url( get_permalink( $c['lesson2'] ) ) . '"', $html );

		$topic = $this->render( $user, $c['topic21'] );
		self::assertStringContainsString( 'odsi-lms-step-nav__link--previous" rel="prev" href="' . esc_url( get_permalink( $c['lesson2'] ) ) . '"', $topic );
		self::assertStringContainsString( '<footer class="odsi-lms-lesson__footer"><button type="button" class="odsi-lms-button odsi-lms-complete" data-step-id="' . $c['topic21'] . '">Mark complete</button><p class="odsi-lms-lesson__status" role="status" aria-live="polite"></p><p class="odsi-lms-lesson__error" role="alert" hidden></p></footer>', $topic );

		// A quiz page has the same navigation and outline as a lesson.
		foreach ( array( $c['topic21'], $c['topic22'] ) as $step ) {
			$this->progress->complete_step( $user, $step );
		}
		Plugin::instance()->container()->get( Access::class )->forget();
		$quiz = $this->render( $user, $c['quiz2'] );
		self::assertStringContainsString( '<div class="odsi-lms-quiz__player" data-quiz-id="' . $c['quiz2'] . '" tabindex="-1">', $quiz );
		self::assertStringContainsString( 'odsi-lms-step-nav__link--next odsi-lms-step-nav__link--locked" data-step-id="' . $c['lesson3'] . '"', $quiz, 'Lesson 3 waits for the quiz.' );
		self::assertStringContainsString( 'odsi-lms-outline-section--step', $quiz );
	}

	public function test_if_006_visitor_on_a_locked_quiz_gets_one_message_with_a_login_link(): void {
		$c = $this->lms->standard_course();

		$html = $this->render( 0, $c['quiz2'] );
		self::assertStringContainsString( 'odsi-lms-locked--enroll', $html );
		self::assertStringContainsString( 'odsi-lms-locked__link--login', $html );
		self::assertSame( 1, substr_count( $html, 'wp-login.php' ), 'The locked notice is the only login prompt.' );
		self::assertStringNotContainsString( 'odsi-lms-quiz__login', $html );
		self::assertStringNotContainsString( 'odsi-lms-quiz__player', $html );

		$stranger = $this->render( $this->lms->learner(), $c['lesson1'] );
		self::assertStringNotContainsString( 'wp-login.php', $stranger, 'A logged-in learner is told to enroll, not to log in.' );
		self::assertStringContainsString( 'odsi-lms-locked__link--course', $stranger );

		$open = $this->lms->course( array( 'meta' => array( Meta::ACCESS_MODE => 'open' ) ) );
		$quiz = $this->lms->quiz( $open, 0 );
		$this->lms->single_choice_question( $quiz );
		$html = $this->render( 0, $quiz );
		self::assertStringNotContainsString( 'odsi-lms-locked', $html );
		self::assertStringContainsString( 'odsi-lms-quiz__login', $html, 'Open course: a visitor may read the quiz but must log in to sit it.' );
	}

	public function test_if_006_enroll_button_states(): void {
		$open = $this->lms->course( array( 'meta' => array( Meta::ACCESS_MODE => 'open' ) ) );
		$lesson = $this->lms->lesson( $open );
		$free = $this->lms->course( array( 'meta' => array( Meta::ACCESS_MODE => 'free' ) ) );
		$this->lms->lesson( $free );
		$paid = $this->lms->course( array( 'meta' => array( Meta::ACCESS_MODE => 'paid' ) ) );
		$closed = $this->lms->course( array( 'meta' => array( Meta::ACCESS_MODE => 'closed' ) ) );
		$user = $this->lms->learner();
		$repo = $this->lms->enrollment()->repository();

		$button = fn ( int $course ): string => do_shortcode( '[odsi_enroll_button course_id="' . $course . '"]' );

		self::assertStringContainsString( 'odsi-lms-enroll__start" href="' . esc_url( get_permalink( $lesson ) ) . '"', $button( $open ), 'A visitor may start an open course.' );
		self::assertStringContainsString( 'odsi-lms-enroll__login', $button( $free ) );

		$this->as_user(
			$user,
			function () use ( $button, $free, $paid, $closed, $repo, $user ): void {
				$fresh = $button( $free );
				self::assertStringContainsString( 'odsi-lms-enroll__button" data-course-id="' . $free . '">', $fresh );
				self::assertStringContainsString( 'Enroll on this course', $fresh );
				self::assertStringContainsString( '<p class="odsi-lms-enroll__error" role="alert" hidden></p>', $fresh, 'Failures are shown inline.' );

				self::assertStringContainsString( 'odsi-lms-enroll__notice--paid', $button( $paid ) );
				self::assertStringContainsString( 'odsi-lms-enroll__notice--closed', $button( $closed ) );

				$repo->enroll( $user, $free );
				self::assertStringContainsString( 'Continue course', $button( $free ) );

				$repo->set_expiry( $user, $free, '2000-01-01 00:00:00' );
				$expired = $button( $free );
				self::assertStringContainsString( 'odsi-lms-enroll__notice--expired', $expired );
				self::assertStringContainsString( 'ended on', $expired );
				self::assertStringContainsString( 'Enroll again', $expired, 'LMS-ENR-003: re-enrolling restarts access.' );

				$repo->set_expiry( $user, $free, null );
				$repo->set_status( $user, $free, EnrollmentRepository::STATUS_COMPLETED );
				$done = $button( $free );
				self::assertStringContainsString( 'odsi-lms-enroll__notice--completed', $done );
				self::assertStringContainsString( 'Review course', $done );
				self::assertStringNotContainsString( 'odsi-lms-enroll__button', $done );

				$repo->enroll( $user, $paid, array( 'status' => 'pending' ) );
				self::assertStringContainsString( 'odsi-lms-enroll__notice--pending', $button( $paid ) );
			}
		);
	}

	public function test_if_006_my_courses_shows_status_and_an_empty_state_with_a_next_action(): void {
		$c1   = $this->lms->standard_course();
		$c2   = $this->lms->course();
		$user = $this->lms->enrolled_learner( $c1['course'] );
		$this->lms->enrollment()->enroll( $user, $c2 );
		$this->lms->enrollment()->repository()->set_status( $user, $c2, EnrollmentRepository::STATUS_COMPLETED );
		$this->progress->complete_step( $user, $c1['lesson1'] );

		$html = $this->as_user( $user, static fn (): string => do_shortcode( '[odsi_my_courses]' ) );
		self::assertStringContainsString( 'odsi-lms-my-courses__status--active">In progress<', $html );
		self::assertStringContainsString( 'odsi-lms-my-courses__status--completed">Completed<', $html );
		self::assertStringContainsString( '<div class="odsi-lms-progress odsi-lms-my-courses__progress" data-course-id="' . $c1['course'] . '">', $html, 'Same progress block as the course page, so the script can repaint it.' );
		self::assertStringContainsString( 'aria-valuenow="16.67"', $html );

		$active = $this->as_user( $user, static fn (): string => do_shortcode( '[odsi_my_courses status="active"]' ) );
		self::assertStringNotContainsString( get_the_title( $c2 ), $active );

		wp_trash_post( $c2 );
		$after = $this->as_user( $user, static fn (): string => do_shortcode( '[odsi_my_courses]' ) );
		self::assertStringNotContainsString( get_the_title( $c2 ), $after, 'An unpublished course is not listed.' );

		$empty = $this->as_user( $this->lms->learner(), static fn (): string => do_shortcode( '[odsi_my_courses]' ) );
		self::assertStringContainsString( 'odsi-lms-my-courses__empty', $empty );
		self::assertStringContainsString( 'Browse courses', $empty );
	}

	public function test_if_007_course_grid_cards_carry_one_link_and_a_second_level_heading(): void {
		$course = $this->lms->course(
			array(
				'post_content' => 'Sales copy for the card.',
				'post_excerpt' => '',
			)
		);
		$html   = do_shortcode( '[odsi_course_grid per_page="50"]' );

		preg_match( '/<article class="odsi-lms-card">.*?<\/article>/s', $html, $card );
		self::assertNotEmpty( $card );
		self::assertSame( 1, substr_count( $card[0], '<a ' ), 'Exactly one link per card.' );
		self::assertStringContainsString( '<h2 class="odsi-lms-card__title">', $card[0] );
		self::assertStringContainsString( get_the_title( $course ), $html );
		self::assertStringContainsString( 'Sales copy for the card.', $html );

		self::assertStringContainsString( 'odsi-lms-grid__empty', do_shortcode( '[odsi_course_grid category="no-such-category"]' ) );
	}

	public function test_if_007_assignment_form_states_the_limits_the_server_enforces(): void {
		$c = $this->lms->standard_course();
		update_post_meta( $c['lesson1'], Meta::ASSIGNMENT_REQUIRED, 1 );
		$user = $this->lms->enrolled_learner( $c['course'] );

		$html = $this->render( $user, $c['lesson1'] );
		self::assertStringContainsString( '<section class="odsi-lms-assignment" data-step-id="' . $c['lesson1'] . '" aria-labelledby="odsi-lms-assignment-heading-' . $c['lesson1'] . '">', $html );
		self::assertStringContainsString( 'data-max-bytes="' . Assignments::max_bytes() . '"', $html );
		self::assertStringContainsString( 'aria-describedby="odsi-lms-assignment-hint-' . $c['lesson1'] . '"', $html );
		self::assertStringContainsString( 'Accepted types: pdf, doc, docx', $html );
		self::assertStringContainsString( 'Maximum size: ' . size_format( Assignments::max_bytes() ), $html );
		self::assertStringContainsString( 'id="odsi-lms-assignment-error-' . $c['lesson1'] . '" role="alert" hidden', $html );
		self::assertStringNotContainsString( 'odsi-lms-complete', $html, 'An assignment step has no mark-complete button.' );
	}

	public function test_excerpts_and_repeated_filtering_are_never_decorated(): void {
		$c    = $this->lms->standard_course(
			array(
				'post_content' => 'Plain course copy.',
				'post_excerpt' => '',
			)
		);
		$user = $this->lms->enrolled_learner( $c['course'] );

		$this->as_user(
			$user,
			function () use ( $c ): void {
				$this->go_to( (string) get_permalink( $c['course'] ) );
				the_post();

				$excerpt = get_the_excerpt();
				self::assertStringContainsString( 'Plain course copy.', $excerpt );
				self::assertStringNotContainsString( 'odsi-lms', $excerpt, 'An automatic excerpt runs the_content; it must not pick up the course UI.' );
				self::assertStringNotContainsString( 'Course content', $excerpt );

				$body = (string) apply_filters( 'the_content', get_post( $c['course'] )->post_content );
				self::assertStringContainsString( 'odsi-lms-outline-section--course', $body, 'The page body still is.' );
				self::assertSame( 1, substr_count( $body, 'odsi-lms-outline-section--course' ) );
				$again = (string) apply_filters( 'the_content', $body );
				self::assertSame( 1, substr_count( $again, 'odsi-lms-outline-section--course' ), 'Filtering decorated content again decorates nothing twice.' );
				self::assertSame( 1, substr_count( $again, '<div class="odsi-lms-enroll"' ) );
				self::assertSame( 1, substr_count( $body, '<div class="odsi-lms-enroll"' ) );
				self::assertSame( 1, substr_count( $body, '<div class="odsi-lms-progress"' ) );
				self::assertLessThan( strpos( $body, 'odsi-lms-enroll' ), strpos( $body, 'odsi-lms-progress' ), 'Progress, content, enroll, outline.' );
				self::assertLessThan( strpos( $body, 'odsi-lms-outline-section' ), strpos( $body, 'odsi-lms-enroll' ) );
			}
		);
	}

	public function test_certificate_page_is_a_printable_document(): void {
		$template = self::factory()->post->create(
			array(
				'post_type'    => PostTypes::CERTIFICATE,
				'post_status'  => 'publish',
				'post_title'   => 'Certificate of Completion',
				'post_content' => 'Awarded to {name}.',
			)
		);
		$c     = $this->lms->standard_course( array( 'meta' => array( Meta::CERTIFICATE_ID => $template ) ) );
		$user  = $this->lms->enrolled_learner( $c['course'] );
		$certs = Plugin::instance()->container()->get( Certificates::class );
		$code  = $certs->issue( $user, $c['course'] );
		$row   = Plugin::instance()->container()->get( CertificateRepository::class )->find_by_code( $code );

		$html = $certs->render( $row );
		self::assertStringContainsString( '<h1 class="odsi-lms-certificate__title">Certificate of Completion</h1>', $html );
		self::assertStringContainsString( '@media print', $html );
		self::assertStringContainsString( 'odsi-lms-certificate__print', $html );
		self::assertStringContainsString( '<meta name="robots" content="noindex" />', $html );
		self::assertStringNotContainsString( 'wp_head', $html );
	}

	public function test_front_end_scripts_translate_through_wp_i18n_and_carry_the_upload_limit(): void {
		$c = $this->lms->standard_course();
		$this->go_to( (string) get_permalink( $c['quiz2'] ) );
		do_action( 'wp_enqueue_scripts' );

		$scripts = wp_scripts();
		self::assertContains( 'wp-i18n', $scripts->registered['odsi-lms']->deps );
		self::assertNotContains( 'wp-api-fetch', $scripts->registered['odsi-lms']->deps, 'The scripts use fetch(); the unused dependency is gone.' );
		self::assertContains( 'wp-i18n', $scripts->registered['odsi-lms-quiz-player']->deps );
		self::assertSame( 'odsi-lms', $scripts->registered['odsi-lms']->textdomain );
		self::assertTrue( wp_script_is( 'odsi-lms-quiz-player', 'enqueued' ) );

		$data = (string) $scripts->get_data( 'odsi-lms', 'data' );
		self::assertStringContainsString( '"maxBytes":' . Assignments::max_bytes(), $data );
		self::assertStringNotContainsString( '"i18n"', $data, 'No hand-rolled string table.' );
	}

	public function test_if_007_every_emitted_class_is_styled_or_a_documented_hook(): void {
		$root = dirname( __DIR__, 3 ) . '/plugins/odsi-lms/';
		$css  = (string) file_get_contents( $root . 'assets/css/frontend.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$bem  = '/^odsi-lms-[a-z0-9]+(?:-[a-z0-9]+)*(?:__[a-z0-9]+(?:-[a-z0-9]+)*)?(?:--[a-z0-9]+(?:-[a-z0-9]+)*)?$/';

		preg_match( '/Hooks without rules:(.*?)\*\//s', $css, $hook_block );
		self::assertNotEmpty( $hook_block, 'The stylesheet documents its hooks.' );
		preg_match_all( '/odsi-lms-[a-z0-9_-]+/', $hook_block[1], $m );
		$hooks = array_unique( $m[0] );

		$rules = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		preg_match_all( '/\.(odsi-lms-[a-z0-9_-]+)/', $rules, $m );
		$styled = array_unique( $m[1] );

		self::assertSame( array(), array_values( array_intersect( $hooks, $styled ) ), 'A class is a hook or styled, not both.' );

		$files = array_merge(
			glob( $root . 'templates/parts/*.php' ) ?: array(),
			array(
				$root . 'templates/archive-course.php',
				$root . 'templates/single-course.php',
				$root . 'templates/single-lesson.php',
				$root . 'templates/single-quiz.php',
				$root . 'src/Frontend/ContentDecorator.php',
				$root . 'src/Courses/Access.php',
				$root . 'src/Commerce/WooCommerce.php',
				$root . 'assets/js/frontend.js',
				$root . 'assets/js/quiz-player.js',
			)
		);

		// Element ids share the prefix but are not classes.
		$ids = array( 'odsi-lms-assignment-content-', 'odsi-lms-assignment-file-', 'odsi-lms-assignment-hint-', 'odsi-lms-assignment-error-', 'odsi-lms-assignment-heading-', 'odsi-lms-outline-heading-', 'odsi-lms-progress-label-', 'odsi-lms-verify-code' );

		$literal  = array();
		$prefixes = array();

		foreach ( $files as $file ) {
			preg_match_all( '/odsi-lms-[a-z0-9_-]+/', (string) file_get_contents( $file ), $m ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.

			foreach ( array_unique( $m[0] ) as $token ) {
				if ( in_array( $token, $ids, true ) ) {
					continue;
				}

				if ( str_ends_with( $token, '-' ) ) {
					$prefixes[] = $token;
				} else {
					$literal[ $token ] = basename( $file );
				}
			}
		}

		$known = array_merge( $styled, $hooks );

		foreach ( $literal as $class => $file ) {
			self::assertMatchesRegularExpression( $bem, $class, "{$class} in {$file} is BEM." );
			self::assertContains( $class, $known, "{$class} (emitted by {$file}) has a rule or is a documented hook." );
		}

		foreach ( $known as $class ) {
			self::assertMatchesRegularExpression( $bem, $class, "{$class} in the stylesheet is BEM." );

			$emitted = isset( $literal[ $class ] );

			foreach ( $prefixes as $prefix ) {
				if ( str_starts_with( $class, $prefix ) ) {
					$emitted = true;
				}
			}

			self::assertTrue( $emitted, "{$class} is styled or documented but nothing emits it: a dead rule." );
		}
	}
}
