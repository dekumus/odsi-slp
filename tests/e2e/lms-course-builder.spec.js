/**
 * The course builder panel in the block editor: an instructor opens a course,
 * sees its outline, adds a lesson, adds a topic under it, and reorders.
 */
const { test, expect } = require( '@playwright/test' );
const { ADMIN_USER, ADMIN_PASS, login, createPost } = require( './helpers' );

test.describe( 'Course builder', () => {
	const stamp = Date.now();
	const ids = {};

	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await login( page, ADMIN_USER, ADMIN_PASS );
		ids.course = await createPost( page, 'odsi_course', { title: `Builder course ${ stamp }`, content: 'Built.' } );
		ids.lesson = await createPost( page, 'odsi_lesson', { title: 'Existing lesson', menu_order: 1, meta: { _odsi_course_id: ids.course } } );
		await page.close();
	} );

	test( 'shows the outline and edits it', async ( { page } ) => {
		await login( page, ADMIN_USER, ADMIN_PASS );
		await page.goto( `/wp-admin/post.php?post=${ ids.course }&action=edit` );

		// The editor re-renders its sidebar while loading, which makes clicking a
		// panel toggle flaky; open the panel through the editor's own store.
		await page.waitForFunction( () => window.wp && window.wp.data && window.wp.data.select( 'core/editor' ).getCurrentPostId() );
		await page.evaluate( () => {
			const name = 'odsi-course-builder/odsi-course-builder';
			const editor = window.wp.data.select( 'core/editor' );
			const dispatch = window.wp.data.dispatch( 'core/editor' );
			const editPost = window.wp.data.dispatch( 'core/edit-post' );
			if ( editPost && editPost.closeGeneralSidebar ) {
				editPost.openGeneralSidebar( 'edit-post/document' );
			}
			if ( editor.isEditorPanelOpened && ! editor.isEditorPanelOpened( name ) ) {
				dispatch.toggleEditorPanelOpened( name );
			}
		} );

		const panel = page.locator( '.odsi-builder' );
		await expect( panel.locator( '.odsi-builder__row--Lesson' ) ).toHaveCount( 1 );
		await expect( panel ).toContainText( 'Existing lesson' );

		// Add a lesson.
		await panel.getByRole( 'button', { name: '+ Lesson' } ).click();
		await panel.getByPlaceholder( 'Title' ).fill( 'Second lesson' );
		await panel.getByRole( 'button', { name: 'Add', exact: true } ).click();
		await expect( panel.locator( '.odsi-builder__row--Lesson' ) ).toHaveCount( 2 );
		await expect( panel.locator( '.odsi-builder__row--Lesson' ).nth( 1 ) ).toContainText( 'Second lesson' );

		// Add a topic under the second lesson.
		await panel.getByRole( 'button', { name: '+ Topic' } ).nth( 1 ).click();
		await panel.getByPlaceholder( 'Title' ).fill( 'A topic' );
		await panel.getByRole( 'button', { name: 'Add', exact: true } ).click();
		await expect( panel.locator( '.odsi-builder__row--Topic' ) ).toHaveCount( 1 );

		// Move the second lesson up.
		await panel.locator( '.odsi-builder__row--Lesson' ).nth( 1 ).getByRole( 'button', { name: 'Move up' } ).click();
		await expect( panel.locator( '.odsi-builder__row--Lesson' ).first() ).toContainText( 'Second lesson' );

		// New nodes are drafts, so the learner outline still shows only the
		// published lesson; once published, the new order is what learners see.
		let outline = await page.request.get( `/wp-json/odsi-lms/v1/courses/${ ids.course }/outline` ).then( ( r ) => r.json() );
		expect( outline.steps.map( ( s ) => s.title ) ).toEqual( [ 'Existing lesson' ] );

		const tree = await page.evaluate( ( courseId ) => window.wp.apiFetch( { path: `/odsi-lms/v1/courses/${ courseId }/builder` } ), ids.course );
		const second = tree.lessons.find( ( l ) => l.title === 'Second lesson' );
		await page.evaluate( ( id ) => window.wp.apiFetch( { path: `/wp/v2/odsi_lesson/${ id }`, method: 'POST', data: { status: 'publish' } } ), second.id );

		outline = await page.request.get( `/wp-json/odsi-lms/v1/courses/${ ids.course }/outline` ).then( ( r ) => r.json() );
		expect( outline.steps[ 0 ].title ).toBe( 'Second lesson' );
	} );
} );
