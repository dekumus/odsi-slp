/**
 * The one flow that proves the LMS end to end:
 *
 *   publish a course with two lessons and a quiz, enroll as a learner, be
 *   blocked from lesson 2, complete lesson 1, pass the quiz, see 100%.
 *
 * Course content is created through the REST API as an administrator because
 * the point of this test is the learner's experience, not the editor's.
 */
const { test, expect } = require( '@playwright/test' );
const { ADMIN_USER, ADMIN_PASS, login, logout, rest, createPost, createUser } = require( './helpers' );

test.describe( 'LMS learner flow', () => {
	const stamp = Date.now();
	const learner = { username: `learner${ stamp }`, password: `Pw-${ stamp }-x` };
	const ids = {};

	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await login( page, ADMIN_USER, ADMIN_PASS );

		ids.course = await createPost( page, 'odsi_course', {
			title: `E2E course ${ stamp }`,
			content: 'A course created by the end-to-end suite.',
			meta: { _odsi_access_mode: 'free', _odsi_linear_progression: true },
		} );
		ids.lesson1 = await createPost( page, 'odsi_lesson', {
			title: 'Lesson one',
			content: 'First lesson.',
			menu_order: 1,
			meta: { _odsi_course_id: ids.course },
		} );
		ids.quiz = await createPost( page, 'odsi_quiz', {
			title: 'Checkpoint quiz',
			content: 'One question.',
			meta: { _odsi_course_id: ids.course, _odsi_lesson_id: ids.lesson1, _odsi_pass_mark: 50 },
		} );
		ids.lesson2 = await createPost( page, 'odsi_lesson', {
			title: 'Lesson two',
			content: 'Second lesson.',
			menu_order: 2,
			meta: { _odsi_course_id: ids.course },
		} );

		// Questions carry a nested answer definition that is not exposed over
		// REST by design, so the suite seeds it through the plugin's own
		// test-only route (registered when ODSI_E2E is defined).
		ids.question = await createPost( page, 'odsi_question', {
			title: 'Is this the right answer?',
			meta: { _odsi_quiz_id: ids.quiz, _odsi_question_type: 'single', _odsi_question_points: 1 },
		} );
		await rest( page, 'POST', `/odsi-lms/v1/e2e/questions/${ ids.question }/answers`, {
			answers: [
				{ text: 'Yes', correct: true },
				{ text: 'No', correct: false },
			],
		} );

		ids.learner = await createUser( page, learner.username, learner.password );
		await page.close();
	} );

	test( 'learner enrolls, is gated, completes, passes and finishes', async ( { page } ) => {
		await login( page, learner.username, learner.password );

		// Enroll from the course page.
		await page.goto( `/?p=${ ids.course }` );
		await page.getByRole( 'button', { name: /enroll/i } ).click();
		await expect( page.getByRole( 'link', { name: /continue course/i } ) ).toBeVisible();

		// Lesson two is locked behind lesson one and the quiz.
		await page.goto( `/?p=${ ids.lesson2 }` );
		await expect( page.locator( '.odsi-lms-locked' ) ).toBeVisible();
		await expect( page.getByText( 'Second lesson.' ) ).toHaveCount( 0 );

		// Complete lesson one.
		await page.goto( `/?p=${ ids.lesson1 }` );
		await expect( page.getByText( 'First lesson.' ) ).toBeVisible();
		await page.getByRole( 'button', { name: /mark complete/i } ).click();
		await expect( page.getByRole( 'button', { name: /completed/i } ) ).toBeVisible();

		// Take and pass the quiz.
		await page.goto( `/?p=${ ids.quiz }` );
		const player = page.locator( '.odsi-lms-quiz__player' );
		await expect( player ).toBeVisible();
		await player.getByRole( 'button', { name: /start/i } ).click();
		await player.getByLabel( 'Yes' ).check();
		await player.getByRole( 'button', { name: /submit/i } ).click();
		await expect( player.getByText( /passed/i ) ).toBeVisible();

		// Lesson two is now open; completing it finishes the course.
		await page.goto( `/?p=${ ids.lesson2 }` );
		await expect( page.getByText( 'Second lesson.' ) ).toBeVisible();
		await page.getByRole( 'button', { name: /mark complete/i } ).click();
		await expect( page.getByRole( 'button', { name: /completed/i } ) ).toBeVisible();

		await page.goto( `/?p=${ ids.course }` );
		await expect( page.locator( '.odsi-lms-progress__label' ) ).toContainText( '100' );

		await logout( page );
	} );
} );
