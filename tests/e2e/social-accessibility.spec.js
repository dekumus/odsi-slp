/**
 * What only a browser can prove about the community pages: the report
 * dialog's focus management, keyboard operation of the feed controls,
 * inline error reporting, and that no routed page overflows at phone,
 * tablet and desktop widths with 40px touch targets.
 */
const { test, expect } = require( '@playwright/test' );
const { ADMIN_USER, ADMIN_PASS, login, logout, createUser, socialRest } = require( './helpers' );

test.describe( 'Social accessibility and layout', () => {
	const stamp = Date.now();
	const cara = { username: `cara${ stamp }`, password: `Pw-${ stamp }-c` };
	const dan = { username: `dan${ stamp }`, password: `Pw-${ stamp }-d` };
	const ids = {};

	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await login( page, ADMIN_USER, ADMIN_PASS );
		ids.cara = await createUser( page, cara.username, cara.password );
		ids.dan = await createUser( page, dan.username, dan.password );
		await page.close();

		const danPage = await browser.newPage();
		await login( danPage, dan.username, dan.password );
		const item = await socialRest( danPage, 'POST', '/activity', { content: `Update from Dan ${ stamp }`, privacy: 'public' } );
		ids.item = item.id;
		const group = await socialRest( danPage, 'POST', '/groups', { name: `Layout group ${ stamp }`, visibility: 'public' } );
		ids.groupSlug = group.slug;
		await socialRest( danPage, 'POST', `/messages/to/${ ids.cara }`, { content: 'Hello Cara' } );
		await danPage.close();
	} );

	test( 'report dialog, keyboard reactions and inline errors', async ( { page } ) => {
		await login( page, cara.username, cara.password );
		await page.goto( '/activity/' );

		const item = page.locator( '.odsi-social-item', { hasText: `Update from Dan ${ stamp }` } ).first();
		await expect( item ).toHaveAttribute( 'aria-labelledby', /odsi-social-item-\d+-action/ );

		// Like with the keyboard: Enter on the focused button flips aria-pressed.
		const like = item.locator( '.odsi-social-item__react' );
		await like.focus();
		await page.keyboard.press( 'Enter' );
		await expect( like ).toHaveAttribute( 'aria-pressed', 'true' );
		await page.keyboard.press( 'Enter' );
		await expect( like ).toHaveAttribute( 'aria-pressed', 'false' );

		// The report dialog is modal, takes focus, closes on Escape and gives focus back.
		const report = item.locator( '.odsi-social-item__report' );
		await report.click();
		const dialog = page.locator( 'dialog.odsi-social-report-dialog' );
		await expect( dialog ).toBeVisible();
		await expect( dialog ).toHaveAttribute( 'open', '' );
		await expect( page.locator( '#odsi-social-report-reason' ) ).toBeFocused();
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
		await expect( report ).toBeFocused();

		await report.click();
		await dialog.locator( 'select[name="reason"]' ).selectOption( 'spam' );
		await dialog.getByRole( 'button', { name: /send report/i } ).click();
		await expect( dialog ).toBeHidden();
		await expect( item.locator( '.odsi-social-status' ) ).toContainText( /moderator/i );

		// A refusal from the server (here: an object that does not exist) is
		// shown inside the dialog, not swallowed. A repeat of the same report
		// is idempotent by design, so the target is changed under the form.
		await report.click();
		await dialog.locator( 'input[name="object_id"]' ).evaluate( ( el ) => {
			el.value = '999999';
		} );
		await dialog.getByRole( 'button', { name: /send report/i } ).click();
		await expect( dialog.locator( '.odsi-social-error' ) ).toBeVisible();
		await page.keyboard.press( 'Escape' );

		// The comment toggle discloses a labelled form; the comment appears without a reload.
		const toggle = item.locator( '.odsi-social-item__comment-toggle' );
		await toggle.click();
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		const form = item.locator( '.odsi-social-comment-form' );
		await expect( form.locator( 'textarea' ) ).toBeFocused();
		await form.locator( 'textarea' ).fill( `Keyboard comment ${ stamp }` );
		await form.getByRole( 'button', { name: /post comment/i } ).click();
		await expect( item.locator( '.odsi-social-comment-list .odsi-social-comment' ).last() ).toContainText( `Keyboard comment ${ stamp }` );
		await expect( toggle.locator( '.odsi-social-item__count' ) ).toHaveText( '1' );

		// The character counter follows the limit.
		const post = page.locator( '.odsi-social-post-form' );
		await post.locator( 'textarea' ).fill( 'abc' );
		await expect( post.locator( '.odsi-social-post-form__count' ) ).toContainText( '4997 characters left' );

		await logout( page );
	} );

	for ( const width of [ 360, 768, 1200 ] ) {
		test( `no horizontal overflow and 40px targets at ${ width }px`, async ( { page } ) => {
			await page.setViewportSize( { width, height: 900 } );
			await login( page, cara.username, cara.password );

			const thread = ( await socialRest( page, 'GET', '/messages' ) ).threads[ 0 ];
			const pages = [ '/activity/', '/members/', `/members/${ dan.username }/`, `/members/${ cara.username }/edit/`, '/groups/', `/groups/${ ids.groupSlug }/`, '/notifications/', '/messages/', `/messages/${ thread.thread_id }/` ];

			for ( const path of pages ) {
				await page.goto( path );
				await expect( page.locator( 'h1' ) ).toBeVisible();
				const overflow = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
				expect( overflow, `${ path } overflows at ${ width }px` ).toBeLessThanOrEqual( 0 );

				const small = await page.evaluate( () => {
					return Array.from( document.querySelectorAll( '[class*="odsi-social"] button, [class*="odsi-social"] a.odsi-social-button, .odsi-social-feed__tab' ) )
						.filter( ( el ) => el.offsetParent !== null )
						.map( ( el ) => ( { label: el.textContent.trim(), height: el.getBoundingClientRect().height } ) )
						.filter( ( el ) => el.height < 40 );
				} );
				expect( small, `${ path } has small targets at ${ width }px: ${ JSON.stringify( small ) }` ).toEqual( [] );
			}

			// The inbox is two panes from tablet width and a single column on a phone.
			await page.goto( `/messages/${ thread.thread_id }/` );
			const columns = await page.evaluate( () => window.getComputedStyle( document.querySelector( '.odsi-social-messages__layout' ) ).gridTemplateColumns.split( ' ' ).length );
			expect( columns ).toBe( width >= 768 ? 2 : 1 );

			await logout( page );
		} );
	}
} );
