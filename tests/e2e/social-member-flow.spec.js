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
		await expect( anaPage.locator( '.odsi-social-feed__items > li article.odsi-social-item' ).first() ).toContainText( `Hello from Ana ${ stamp }` );
		await expect( postForm.locator( 'textarea' ) ).toHaveValue( '' );
		await expect( anaPage.locator( 'h1' ) ).toHaveText( 'Activity' );

		// Ben sees it on the site feed (it is public), comments and likes.
		await benPage.goto( '/activity/' );
		const item = benPage.locator( '.odsi-social-item', { hasText: `Hello from Ana ${ stamp }` } ).first();
		await expect( item ).toBeVisible();
		await item.locator( '.odsi-social-item__comment-toggle' ).click();
		await item.locator( '.odsi-social-comment-form textarea' ).fill( `Nice to meet you ${ stamp }` );
		await item.getByRole( 'button', { name: /post comment/i } ).click();
		await expect( benPage.locator( '.odsi-social-item', { hasText: `Hello from Ana ${ stamp }` } ).first().locator( '.odsi-social-comment', { hasText: `Nice to meet you ${ stamp }` } ) ).toBeVisible();

		const like = benPage.locator( '.odsi-social-item', { hasText: `Hello from Ana ${ stamp }` } ).first().locator( '.odsi-social-item__react' );
		await like.click();
		await expect( like ).toHaveClass( /is-active/ );
		await expect( like ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( like.locator( '.odsi-social-item__count' ) ).toHaveText( '1' );

		// Ben sends Ana a connection request from her profile; Ana accepts.
		await benPage.goto( `/members/${ ana.username }/` );
		await expect( benPage.locator( '.odsi-social-profile' ) ).toBeVisible();
		await benPage.locator( '.odsi-social-hero__connect' ).click();
		await expect( benPage.locator( '.odsi-social-hero__connect' ) ).toHaveText( /withdraw/i );

		await anaPage.goto( `/members/${ ben.username }/` );
		await expect( anaPage.locator( '.odsi-social-hero__connect' ) ).toHaveText( /accept/i );
		await anaPage.locator( '.odsi-social-hero__connect' ).click();
		await expect( anaPage.locator( '.odsi-social-hero__connect' ) ).toHaveText( /remove/i );
		await expect( anaPage.locator( '.odsi-social-hero__connect' ) ).toHaveAttribute( 'aria-pressed', 'true' );

		// Ana creates a public group; Ben joins it from the group page.
		await anaPage.goto( '/groups/' );
		const create = anaPage.locator( '.odsi-social-create-group' );
		await create.locator( 'input[name="name"]' ).fill( `Study circle ${ stamp }` );
		await create.locator( 'select[name="visibility"]' ).selectOption( 'public' );
		await create.getByRole( 'button', { name: /create group/i } ).click();
		await expect( anaPage.locator( '.odsi-social-group' ) ).toBeVisible();
		await expect( anaPage.locator( '.odsi-social-hero__membership' ) ).toHaveText( /leave group/i );
		const groupUrl = anaPage.url();

		await benPage.goto( groupUrl );
		await benPage.locator( '.odsi-social-hero__membership' ).click();
		await expect( benPage.locator( '.odsi-social-hero__membership' ) ).toHaveText( /leave group/i );
		await expect( benPage.locator( '.odsi-social-hero__counts' ) ).toContainText( '2 members' );

		// Ben messages Ana; Ana reads it.
		await benPage.goto( `/messages/?to=${ ids.ana }` );
		const compose = benPage.locator( '.odsi-social-message-form--new' );
		await expect( compose ).toBeVisible();
		await compose.locator( 'textarea' ).fill( 'Shall we study together?' );
		await compose.getByRole( 'button', { name: /send/i } ).click();
		// A first message opens the new conversation's own page.
		await expect( benPage ).toHaveURL( /\/messages\/\d+\/?$/ );
		await expect( benPage.locator( '.odsi-social-message' ).first() ).toContainText( 'Shall we study together?' );

		await anaPage.goto( '/messages/' );
		await expect( anaPage.locator( '.odsi-social-thread.is-unread' ).first() ).toBeVisible();
		await anaPage.locator( '.odsi-social-thread__link' ).first().click();
		await expect( anaPage.locator( '.odsi-social-message' ).first() ).toContainText( 'Shall we study together?' );
		await anaPage.locator( '.odsi-social-message-form textarea' ).fill( 'Yes, tomorrow.' );
		await anaPage.locator( '.odsi-social-message-form' ).getByRole( 'button', { name: /send/i } ).click();
		// The reply is appended to the log without a reload.
		await expect( anaPage.locator( '.odsi-social-conversation .odsi-social-message.is-mine' ).last() ).toContainText( 'Yes, tomorrow.' );
		await expect( anaPage.locator( '.odsi-social-message-form textarea' ) ).toHaveValue( '' );

		// Ana's notifications: comment, like, connection request, group join, message.
		await anaPage.goto( '/notifications/' );
		const list = anaPage.locator( '.odsi-social-notification' );
		await expect( list ).toHaveCount( 4 );
		await expect( anaPage.locator( '.odsi-social-notifications__list' ) ).toContainText( 'commented on your post' );
		await expect( anaPage.locator( '.odsi-social-notifications__list' ) ).toContainText( 'liked your post' );
		await expect( anaPage.locator( '.odsi-social-notifications__list' ) ).toContainText( 'sent you a connection request' );
		await expect( anaPage.locator( '.odsi-social-notifications__list' ) ).toContainText( 'sent you a message' );
		// Opening the thread already read the message notification (SOC-MSG-004).
		await expect( anaPage.locator( '.odsi-social-notification.is-new' ) ).toHaveCount( 3 );
		await anaPage.locator( '.odsi-social-notification__read' ).first().click();
		await expect( anaPage.locator( '.odsi-social-notification.is-new' ) ).toHaveCount( 2 );
		await expect( anaPage.locator( '.odsi-social-notifications__count' ) ).toHaveText( '2 unread' );
		await anaPage.locator( '.odsi-social-notifications__read-all' ).click();
		await expect( anaPage.locator( '.odsi-social-notification.is-new' ) ).toHaveCount( 0 );
		await expect( anaPage.locator( '.odsi-social-notifications__count' ) ).toHaveText( '0 unread' );
		await expect( anaPage.locator( '.odsi-social-notifications__read-all' ) ).toHaveCount( 0 );

		// Ben's: connection accepted.
		await benPage.goto( '/notifications/' );
		await expect( benPage.locator( '.odsi-social-notifications__list' ) ).toContainText( 'accepted your connection request' );

		await logout( anaPage );
		await logout( benPage );
		await anaContext.close();
		await benContext.close();
	} );
} );
