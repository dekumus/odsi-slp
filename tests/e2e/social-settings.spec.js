/**
 * Settings forms: a member edits their profile (photo, message setting) and
 * an organiser manages a group (rename, approve a request) without any
 * JavaScript being required.
 */
const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );
const { ADMIN_USER, ADMIN_PASS, login, logout, socialRest, createUser } = require( './helpers' );

test.describe( 'Social settings', () => {
	const stamp = Date.now();
	const owner = { username: `owner${ stamp }`, password: `Pw-${ stamp }-o` };
	const joiner = { username: `joiner${ stamp }`, password: `Pw-${ stamp }-j` };
	const ids = {};

	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await login( page, ADMIN_USER, ADMIN_PASS );
		ids.owner = await createUser( page, owner.username, owner.password );
		ids.joiner = await createUser( page, joiner.username, joiner.password );
		await page.close();

		const ownerPage = await browser.newPage();
		await login( ownerPage, owner.username, owner.password );
		const group = await socialRest( ownerPage, 'POST', '/groups', {
			name: `Private circle ${ stamp }`,
			description: 'Members approved by hand.',
			visibility: 'private',
		} );
		ids.group = group.id;
		ids.groupSlug = group.slug;
		await ownerPage.close();

		const joinerPage = await browser.newPage();
		await login( joinerPage, joiner.username, joiner.password );
		await socialRest( joinerPage, 'POST', `/groups/${ ids.group }/membership`, {} );
		await joinerPage.close();
	} );

	test( 'member edits profile, organiser manages group', async ( { page } ) => {
		await login( page, owner.username, owner.password );

		// Profile: the edit link is only on one's own profile.
		await page.goto( `/members/${ owner.username }/` );
		await page.getByRole( 'link', { name: /edit profile/i } ).click();
		await expect( page ).toHaveURL( new RegExp( `/members/${ owner.username }/edit/` ) );

		await page.locator( '#odsi-avatar' ).setInputFiles( path.join( __dirname, 'fixtures', 'avatar.png' ) );
		await page.locator( '#odsi-message-setting' ).selectOption( 'connections' );
		await page.getByRole( 'button', { name: /save changes/i } ).click();

		await expect( page.locator( '.odsi-social-notice--success' ) ).toBeVisible();
		await expect( page.locator( '#odsi-message-setting' ) ).toHaveValue( 'connections' );
		const avatar = page.locator( '.odsi-social-settings__image img' ).first();
		await expect( avatar ).toHaveAttribute( 'src', /avatar.*\.png/ );

		// Group: rename and approve the pending request.
		await page.goto( `/groups/${ ids.groupSlug }/` );
		await page.getByRole( 'link', { name: /manage group/i } ).click();
		await expect( page ).toHaveURL( new RegExp( `/groups/${ ids.groupSlug }/manage/` ) );
		await expect( page.locator( '.odsi-social-settings' ) ).toContainText( '1 request to join' );

		await page.locator( '#odsi-group-name' ).fill( `Private circle ${ stamp } renamed` );
		await page.getByRole( 'button', { name: /save settings/i } ).click();
		await expect( page.locator( '.odsi-social-notice--success' ) ).toBeVisible();
		await expect( page.locator( 'h1' ) ).toContainText( 'renamed' );

		await page.getByRole( 'button', { name: /^approve$/i } ).click();
		await expect( page.locator( '.odsi-social-settings' ) ).not.toContainText( 'request to join' );
		await expect( page.locator( '.odsi-social-member-list' ) ).toContainText( joiner.username );
		await logout( page );

		// Another member cannot reach the manage page.
		await login( page, joiner.username, joiner.password );
		await page.goto( `/groups/${ ids.groupSlug }/manage/` );
		await expect( page.locator( '.odsi-social-settings' ) ).toHaveCount( 0 );
		await logout( page );
	} );
} );
