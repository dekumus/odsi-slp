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
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', username );
	await page.fill( '#user_pass', password );
	await page.click( '#wp-submit' );
	await expect( page ).not.toHaveURL( /wp-login\.php/ );
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
 * Create a post of any type through the core REST API and return its id.
 *
 * @param {import('@playwright/test').Page} page  Logged-in admin page.
 * @param {string}                          type  REST base, e.g. `odsi_course`.
 * @param {Object}                          data  Post fields.
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

module.exports = { ADMIN_USER, ADMIN_PASS, login, logout, rest, createPost, createUser };
