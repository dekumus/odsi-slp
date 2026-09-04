/**
 * Shared helpers for end-to-end tests.
 */
const { expect } = require( '@playwright/test' );

const ADMIN_USER = process.env.ODSI_E2E_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.ODSI_E2E_ADMIN_PASS || 'password';

/**
 * Log in through wp-login.php.
 *
 * @param {import('@playwright/test').Page} page     Page.
 * @param {string}                          username Username.
 * @param {string}                          password Password.
 */
async function login( page, username, password ) {
	await page.goto( '/wp-login.php', { waitUntil: 'networkidle' } );

	// wp-login.php moves focus around on load; fill each field explicitly and
	// confirm the values landed before submitting, so a focus race cannot put
	// the password into the username box.
	const user = page.locator( '#user_login' );
	const pass = page.locator( '#user_pass' );
	await user.click();
	await user.fill( username );
	await pass.click();
	await pass.fill( password );
	await expect( user ).toHaveValue( username );
	await expect( pass ).toHaveValue( password );

	await Promise.all( [ page.waitForURL( ( url ) => ! url.pathname.endsWith( 'wp-login.php' ) ), page.click( '#wp-submit' ) ] );
}

/**
 * Log the current session out.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function logout( page ) {
	await page.context().clearCookies();
}

/**
 * Open a post in the block editor with the welcome guide out of the way.
 *
 * A fresh site shows the "Welcome to the editor" dialog on first open, which
 * marks the whole editor aria-hidden, so role-based locators find nothing
 * behind it. The dismissal is stored as a user preference, so it is only
 * needed once per user but is idempotent.
 *
 * @param {import('@playwright/test').Page} page   Logged-in page.
 * @param {number}                          postId Post to edit.
 */
async function openEditor( page, postId ) {
	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
	await page.waitForFunction( () => window.wp && window.wp.data && window.wp.data.select( 'core/editor' ).getCurrentPostId() );
	await page.evaluate( () => {
		const prefs = window.wp.data.dispatch( 'core/preferences' );
		if ( prefs && prefs.set ) {
			prefs.set( 'core/edit-post', 'welcomeGuide', false );
			prefs.set( 'core/edit-post', 'welcomeGuideTemplate', false );
			prefs.set( 'core', 'welcomeGuide', false );
		}
	} );

	const guide = page.getByRole( 'dialog', { name: /welcome/i } );

	if ( await guide.isVisible().catch( () => false ) ) {
		await guide.getByRole( 'button', { name: /close/i } ).first().click();
		await expect( guide ).toBeHidden();
	}
}

/**
 * Call the WordPress REST API with a nonce obtained from an admin page.
 *
 * @param {import('@playwright/test').Page} page   Logged-in page.
 * @param {string}                          method HTTP method.
 * @param {string}                          path   Route path from /wp-json.
 * @param {Object}                          [body] JSON body.
 * @return {Promise<Object>} Parsed JSON.
 */
async function rest( page, method, path, body ) {
	await page.goto( '/wp-admin/' );
	const nonce = await page.evaluate( () => window.wpApiSettings.nonce );

	const response = await page.request.fetch( `/wp-json${ path }`, {
		method,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
		},
		data: body ? JSON.stringify( body ) : undefined,
	} );

	const json = await response.json();

	if ( ! response.ok() ) {
		throw new Error( `${ method } ${ path } failed: ${ JSON.stringify( json ) }` );
	}

	return json;
}

/**
 * Call the community plugin's REST API as whoever is logged in, using the
 * nonce the front end carries, so members (not only admins) can seed data.
 *
 * @param {import('@playwright/test').Page} page   Logged-in page.
 * @param {string}                          method HTTP method.
 * @param {string}                          path   Route path from /wp-json/odsi-social/v1.
 * @param {Object}                          [body] JSON body.
 * @return {Promise<Object>} Parsed JSON.
 */
async function socialRest( page, method, path, body ) {
	await page.goto( '/members/' );
	const config = await page.evaluate( () => window.odsiSocial );

	const response = await page.request.fetch( `${ config.restUrl }${ path }`, {
		method,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		data: body ? JSON.stringify( body ) : undefined,
	} );

	const json = await response.json();

	if ( ! response.ok() ) {
		throw new Error( `${ method } ${ path } failed: ${ JSON.stringify( json ) }` );
	}

	return json;
}

/**
 * Create a post of any type through the core REST API and return its id.
 *
 * @param {import('@playwright/test').Page} page Logged-in admin page.
 * @param {string}                          type REST base, e.g. `odsi_course`.
 * @param {Object}                          data Post fields.
 * @return {Promise<number>} Post id.
 */
async function createPost( page, type, data ) {
	const json = await rest( page, 'POST', `/wp/v2/${ type }`, {
		status: 'publish',
		...data,
	} );

	return json.id;
}

/**
 * Create a subscriber through the REST API.
 *
 * @param {import('@playwright/test').Page} page     Logged-in admin page.
 * @param {string}                          username Username.
 * @param {string}                          password Password.
 * @return {Promise<number>} User id.
 */
async function createUser( page, username, password ) {
	const json = await rest( page, 'POST', '/wp/v2/users', {
		username,
		password,
		email: `${ username }@example.org`,
		roles: [ 'subscriber' ],
	} );

	return json.id;
}

module.exports = { ADMIN_USER, ADMIN_PASS, login, logout, openEditor, rest, socialRest, createPost, createUser };
