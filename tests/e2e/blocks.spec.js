/**
 * Blocks: a page built from LMS and community blocks renders for a visitor,
 * and the editor shows live server-rendered previews of the same blocks.
 */
const { test, expect } = require( '@playwright/test' );
const { ADMIN_USER, ADMIN_PASS, login, logout, createPost } = require( './helpers' );

test.describe( 'Blocks', () => {
	const stamp = Date.now();
	const ids = {};

	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await login( page, ADMIN_USER, ADMIN_PASS );

		ids.course = await createPost( page, 'odsi_course', {
			title: `Block course ${ stamp }`,
			content: 'Listed by the course grid block.',
			meta: { _odsi_access_mode: 'free' },
		} );
		ids.page = await createPost( page, 'pages', {
			title: `Blocks page ${ stamp }`,
			content: [
				'<!-- wp:odsi-lms/course-grid {"perPage":6} /-->',
				`<!-- wp:odsi-lms/enroll-button {"courseId":${ ids.course }} /-->`,
				'<!-- wp:odsi-social/member-directory /-->',
			].join( '\n' ),
		} );
		await page.close();
	} );

	test( 'front end renders every block and the editor previews them', async ( { page } ) => {
		await page.goto( `/?page_id=${ ids.page }` );
		const grid = page.locator( '.wp-block-odsi-lms-course-grid' );
		await expect( grid ).toBeVisible();
		await expect( grid ).toContainText( `Block course ${ stamp }` );
		await expect( page.locator( '.wp-block-odsi-lms-enroll-button' ).getByRole( 'link', { name: /log in to enroll/i } ) ).toBeVisible();
		await expect( page.locator( '.wp-block-odsi-social-member-directory .odsi-social-directory' ) ).toBeVisible();

		// The plugin stylesheets load on a normal page because blocks are present.
		const lmsStyle = page.locator( 'link#odsi-lms-css' );
		await expect( lmsStyle ).toHaveCount( 1 );

		await login( page, ADMIN_USER, ADMIN_PASS );
		await page.goto( `/wp-admin/post.php?post=${ ids.page }&action=edit` );
		await page.waitForFunction( () => window.wp && window.wp.data && window.wp.data.select( 'core/block-editor' ).getBlocks().length > 0 );

		const editorGrid = page.frameLocator( 'iframe[name="editor-canvas"]' ).locator( '.wp-block-odsi-lms-course-grid .odsi-lms-grid' );
		const inlineGrid = page.locator( '.wp-block-odsi-lms-course-grid .odsi-lms-grid' );
		await expect( editorGrid.or( inlineGrid ).first() ).toContainText( `Block course ${ stamp }`, { timeout: 20000 } );

		const registered = await page.evaluate( () => window.wp.blocks.getBlockTypes().filter( ( b ) => b.name.startsWith( 'odsi-' ) ).map( ( b ) => b.name ).sort() );
		expect( registered ).toEqual( [
			'odsi-lms/course-grid',
			'odsi-lms/course-outline',
			'odsi-lms/course-progress',
			'odsi-lms/enroll-button',
			'odsi-lms/my-courses',
			'odsi-social/activity-feed',
			'odsi-social/group-directory',
			'odsi-social/member-directory',
		] );

		await logout( page );
	} );
} );
