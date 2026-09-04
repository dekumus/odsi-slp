/**
 * The community flow that proves the social plugin end to end:
 *
 *   two members: one posts an update, the other comments and likes it, they
 *   connect, one creates a group the other joins, one messages the other, and
 *   the notifications page shows what happened.
 */
const { test, expect } = require( '@playwright/test' );
const { ADMIN_USER, ADMIN_PASS, login, logout, createUser } = require( './helpers' );

test.describe( 'Social member flow', () => {
	const stamp = Date.now();
	const ana = { username: `ana${ stamp }`, password: `Pw-${ stamp }-a` };
	const ben = { username: `ben${ stamp }`, password: `Pw-${ stamp }-b` };
	const ids = {};

	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await login( page, ADMIN_USER, ADMIN_PASS );
		ids.ana = await createUser( page, ana.username, ana.password );
		ids.ben = await createUser( page, ben.username, ben.password );
		await page.close();
	} );

	test( 'post, comment, like, connect, group, message, notifications', async ( { browser } ) => {
		const anaContext = await browser.newContext();
		const benContext = await browser.newContext();
		const anaPage = await anaContext.newPage();
		const benPage = await benContext.newPage();

		await login( anaPage, ana.username, ana.password );
		await login( benPage, ben.username, ben.password );

		// Ana posts a public update from the activity page.
		await anaPage.goto( '/activity/' );
		const postForm = anaPage.locator( '.odsi-social-post-form' );
		await expect( postForm ).toBeVisible();
		await postForm.locator( 'textarea' ).fill( `Hello from Ana ${ stamp }` );
		await postForm.locator( 'select[name="privacy"]' ).selectOption( 'public' );
		await postForm.getByRole( 'button', { name: /^post$/i } ).click();
		await expect( anaPage.locator( '.odsi-social-item' ).first() ).toContainText( `Hello from Ana ${ stamp }` );

		// Ben sees it on the site feed (it is public), comments and likes.
		await benPage.goto( '/activity/' );
		const item = benPage.locator( '.odsi-social-item', { hasText: `Hello from Ana ${ stamp }` } ).first();
		await expect( item ).toBeVisible();
		await item.locator( '.odsi-social-comment-toggle' ).click();
		await item.locator( '.odsi-social-comment-form textarea' ).fill( `Nice to meet you ${ stamp }` );
		await item.getByRole( 'button', { name: /post comment/i } ).click();
		await expect( benPage.locator( '.odsi-social-item', { hasText: `Hello from Ana ${ stamp }` } ).first().locator( '.odsi-social-comment', { hasText: `Nice to meet you ${ stamp }` } ) ).toBeVisible();

		const like = benPage.locator( '.odsi-social-item', { hasText: `Hello from Ana ${ stamp }` } ).first().locator( '.odsi-social-react' );
		await like.click();
		await expect( like ).toHaveClass( /is-active/ );
		await expect( like.locator( '.odsi-social-count' ) ).toHaveText( '1' );

		// Ben sends Ana a connection request from her profile; Ana accepts.
		await benPage.goto( `/members/${ ana.username }/` );
		await expect( benPage.locator( '.odsi-social-profile' ) ).toBeVisible();
		await benPage.locator( '.odsi-social-connect' ).click();
		await expect( benPage.locator( '.odsi-social-connect' ) ).toHaveText( /withdraw/i );

		await anaPage.goto( `/members/${ ben.username }/` );
		await expect( anaPage.locator( '.odsi-social-connect' ) ).toHaveText( /accept/i );
		await anaPage.locator( '.odsi-social-connect' ).click();
		await expect( anaPage.locator( '.odsi-social-connect' ) ).toHaveText( /remove/i );

		// Ana creates a public group; Ben joins it from the group page.
		await anaPage.goto( '/groups/' );
		const create = anaPage.locator( '.odsi-social-create-group' );
		await create.locator( 'input[name="name"]' ).fill( `Study circle ${ stamp }` );
		await create.locator( 'select[name="visibility"]' ).selectOption( 'public' );
		await create.getByRole( 'button', { name: /create group/i } ).click();
		await expect( anaPage.locator( '.odsi-social-group' ) ).toBeVisible();
		await expect( anaPage.locator( '.odsi-social-membership' ) ).toHaveText( /leave group/i );
		const groupUrl = anaPage.url();

		await benPage.goto( groupUrl );
		await benPage.locator( '.odsi-social-membership' ).click();
		await expect( benPage.locator( '.odsi-social-membership' ) ).toHaveText( /leave group/i );
		await expect( benPage.locator( '.odsi-social-profile__counts' ) ).toContainText( '2 members' );

		// Ben messages Ana; Ana reads it.
		await benPage.goto( `/messages/?to=${ ids.ana }` );
		const compose = benPage.locator( '.odsi-social-message-form--new' );
		await expect( compose ).toBeVisible();
		await compose.locator( 'textarea' ).fill( 'Shall we study together?' );
		await compose.getByRole( 'button', { name: /send/i } ).click();
		await expect( benPage.locator( '.odsi-social-thread' ).first() ).toContainText( 'Shall we study together?' );

		await anaPage.goto( '/messages/' );
		await expect( anaPage.locator( '.odsi-social-thread.is-unread' ).first() ).toBeVisible();
		await anaPage.locator( '.odsi-social-thread a' ).first().click();
		await expect( anaPage.locator( '.odsi-social-message' ).first() ).toContainText( 'Shall we study together?' );
		await anaPage.locator( '.odsi-social-message-form textarea' ).fill( 'Yes, tomorrow.' );
		await anaPage.locator( '.odsi-social-message-form' ).getByRole( 'button', { name: /send/i } ).click();
		await expect( anaPage.locator( '.odsi-social-message.is-mine' ).last() ).toContainText( 'Yes, tomorrow.' );

		// Ana's notifications: comment, like, connection request, group join, message.
		await anaPage.goto( '/notifications/' );
		const list = anaPage.locator( '.odsi-social-notification' );
		await expect( list ).toHaveCount( 4 );
		await expect( anaPage.locator( '.odsi-social-notifications__list' ) ).toContainText( 'commented on your post' );
		await expect( anaPage.locator( '.odsi-social-notifications__list' ) ).toContainText( 'liked your post' );
		await expect( anaPage.locator( '.odsi-social-notifications__list' ) ).toContainText( 'sent you a connection request' );
		await expect( anaPage.locator( '.odsi-social-notifications__list' ) ).toContainText( 'sent you a message' );
		await anaPage.locator( '.odsi-social-read-all' ).click();
		await expect( anaPage.locator( '.odsi-social-notification.is-new' ) ).toHaveCount( 0 );

		// Ben's: connection accepted.
		await benPage.goto( '/notifications/' );
		await expect( benPage.locator( '.odsi-social-notifications__list' ) ).toContainText( 'accepted your connection request' );

		await logout( anaPage );
		await logout( benPage );
		await anaContext.close();
		await benContext.close();
	} );
} );
