/**
 * The odsi-learn theme: skipped unless the site runs it.
 *
 *   the platform menu adapts to the visitor, the front page shows courses
 *   and community, course pages use the course template, and the community
 *   pages render through the page template rather than the front page.
 */
const { test, expect } = require( '@playwright/test' );
const { ADMIN_USER, ADMIN_PASS, login, logout, createPost, createUser } = require( './helpers' );

test.describe( 'ODSI Learn theme', () => {
	const stamp = Date.now();
	const learner = { username: `theme${ stamp }`, password: `Pw-${ stamp }-t` };
	let courseUrl = '';

	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await page.goto( '/' );
		const isTheme = ( await page.locator( 'link#odsi-learn-css' ).count() ) > 0;
		if ( ! isTheme ) {
			await page.close();
			return;
		}
		await login( page, ADMIN_USER, ADMIN_PASS );
		await createUser( page, learner.username, learner.password );
		const courseId = await createPost( page, 'odsi_course', {
			title: `Theme course ${ stamp }`,
			content: 'Rendered by the course template.',
		} );
		courseUrl = await page.evaluate( async ( id ) => {
			const res = await window.wp.apiFetch( { path: `/wp/v2/odsi_course/${ id }` } );
			return res.link;
		}, courseId );
		await logout( page );
		await page.close();
	} );

	test.beforeEach( async ( { page } ) => {
		await page.goto( '/' );
		test.skip( ( await page.locator( 'link#odsi-learn-css' ).count() ) === 0, 'odsi-learn is not the active theme' );
	} );

	test( 'visitor sees courses, community and a log in link', async ( { page } ) => {
		const menu = page.locator( 'header .odsi-learn-menu' );
		await expect( menu.getByRole( 'link', { name: 'Courses', exact: true } ) ).toBeVisible();
		await expect( menu.getByRole( 'link', { name: 'Activity' } ) ).toBeVisible();
		await expect( menu.getByRole( 'link', { name: 'Log in' } ) ).toBeVisible();
		await expect( menu.getByRole( 'link', { name: 'Notifications' } ) ).toHaveCount( 0 );
		await expect( page.locator( '.odsi-learn-hero' ) ).toBeVisible();
		await expect( page.locator( '.odsi-lms-grid' ) ).toBeVisible();
	} );

	test( 'logged-in member gets account links and the current section is marked', async ( { page } ) => {
		await login( page, learner.username, learner.password );
		await page.goto( '/' );
		const menu = page.locator( 'header .odsi-learn-menu' );
		await expect( menu.getByRole( 'link', { name: 'Notifications' } ) ).toBeVisible();
		await expect( menu.getByRole( 'link', { name: 'Log out' } ) ).toBeVisible();

		await menu.getByRole( 'link', { name: 'Courses', exact: true } ).click();
		await expect( page ).toHaveURL( /\/courses\/?$/ );
		await expect( page.locator( 'header .odsi-learn-menu a[aria-current="page"]' ) ).toHaveText( /Courses/ );
		await logout( page );
	} );

	test( 'a course renders through the course template', async ( { page } ) => {
		await page.goto( courseUrl );
		await expect( page.locator( 'main.odsi-learn-course' ) ).toBeVisible();
		await expect( page.locator( '.odsi-learn-course__hero h1' ) ).toHaveText( `Theme course ${ stamp }` );
		await expect( page.locator( '.odsi-learn-course__body .odsi-lms-enroll' ) ).toBeVisible();
	} );
} );
