/**
 * Assignments end to end: a learner hands work in on a lesson that requires
 * it, cannot mark the lesson complete, an administrator approves it from the
 * Grading screen, and the lesson completes.
 */
const { test, expect } = require( '@playwright/test' );
const { ADMIN_USER, ADMIN_PASS, login, logout, createPost, createUser } = require( './helpers' );

test.describe( 'LMS assignments', () => {
	const stamp = Date.now();
	const learner = { username: `handin${ stamp }`, password: `Pw-${ stamp }-y` };
	const ids = {};

	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await login( page, ADMIN_USER, ADMIN_PASS );

		ids.course = await createPost( page, 'odsi_course', {
			title: `E2E assignment course ${ stamp }`,
			content: 'A course with an assignment.',
			meta: { _odsi_access_mode: 'free', _odsi_linear_progression: true },
		} );
		ids.lesson = await createPost( page, 'odsi_lesson', {
			title: 'Hand something in',
			content: 'Write a paragraph and hand it in.',
			menu_order: 1,
			meta: { _odsi_course_id: ids.course, _odsi_assignment_required: true, _odsi_assignment_points: 10 },
		} );
		ids.learner = await createUser( page, learner.username, learner.password );
		await page.close();
	} );

	test( 'learner hands in, admin approves, lesson completes', async ( { page } ) => {
		const essay = `My paragraph ${ stamp }`;

		await login( page, learner.username, learner.password );
		await page.goto( `/?p=${ ids.course }` );
		await page.getByRole( 'button', { name: /enroll/i } ).click();
		await expect( page.getByRole( 'link', { name: /continue course/i } ) ).toBeVisible();

		await page.goto( `/?p=${ ids.lesson }` );
		const assignment = page.locator( '.odsi-lms-assignment' );
		await expect( assignment ).toBeVisible();
		await expect( page.getByRole( 'button', { name: /mark complete/i } ) ).toHaveCount( 0 );

		await assignment.getByLabel( /your answer/i ).fill( essay );
		await assignment.getByRole( 'button', { name: /hand in/i } ).click();
		await expect( page.locator( '.odsi-lms-assignment__status--pending' ) ).toBeVisible();
		await expect( page.locator( '.odsi-lms-assignment__form' ) ).toHaveCount( 0 );
		await logout( page );

		await login( page, ADMIN_USER, ADMIN_PASS );
		await page.goto( '/wp-admin/admin.php?page=odsi-lms-grading' );
		const card = page.locator( '.card', { hasText: essay } );
		await expect( card ).toBeVisible();
		await card.getByLabel( /points/i ).fill( '9' );
		await card.getByLabel( /feedback/i ).fill( 'Well argued.' );
		await card.getByRole( 'button', { name: /approve/i } ).click();
		await expect( page.locator( '.card', { hasText: essay } ) ).toHaveCount( 0 );
		await logout( page );

		await login( page, learner.username, learner.password );
		await page.goto( `/?p=${ ids.lesson }` );
		await expect( page.locator( '.odsi-lms-assignment__status--approved' ) ).toBeVisible();
		await expect( page.locator( '.odsi-lms-assignment__points' ) ).toContainText( '9 / 10' );
		await expect( page.locator( '.odsi-lms-assignment__feedback' ) ).toContainText( 'Well argued.' );

		await page.goto( `/?p=${ ids.course }` );
		await expect( page.locator( '.odsi-lms-progress__label' ) ).toContainText( '100' );
		await logout( page );
	} );
} );
