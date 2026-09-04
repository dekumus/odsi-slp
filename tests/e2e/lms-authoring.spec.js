/**
 * Classic authoring boxes inside the block editor: an administrator sets a
 * course's access mode and pass-mark-bearing quiz settings, and writes a
 * question's answers through the plain-text editor. Each save is verified
 * over REST, the way the quiz player will read it.
 */
const { test, expect } = require( '@playwright/test' );
const { ADMIN_USER, ADMIN_PASS, login, openEditor, rest, createPost } = require( './helpers' );

/**
 * Save the open post through the editor's own store and wait for both the
 * post and its meta boxes to finish saving.
 *
 * @param {import('@playwright/test').Page} page Editor page.
 */
async function saveAndWait( page ) {
	await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).savePost() );
	await page.waitForFunction( () => {
		const editor = window.wp.data.select( 'core/editor' );
		const editPost = window.wp.data.select( 'core/edit-post' );
		const metaBoxesSaving = editPost && editPost.isSavingMetaBoxes ? editPost.isSavingMetaBoxes() : false;
		return ! editor.isSavingPost() && ! editor.isAutosavingPost() && ! metaBoxesSaving && ! editor.isEditedPostDirty();
	} );
}

/**
 * Normal-context meta boxes live in a collapsible "Meta Boxes" area under the
 * canvas; open it when it is closed.
 *
 * @param {import('@playwright/test').Page} page Editor page.
 */
async function expandMetaBoxes( page ) {
	const toggle = page.getByRole( 'button', { name: /^Meta Boxes/i } );

	if ( await toggle.isVisible().catch( () => false ) ) {
		const expanded = await toggle.getAttribute( 'aria-expanded' );

		if ( 'true' !== expanded ) {
			// A resize handle overlaps the toggle, so click it directly.
			await toggle.dispatchEvent( 'click' );
			await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		}
	}
}

test.describe( 'Authoring boxes', () => {
	const stamp = Date.now();
	const ids = {};

	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await login( page, ADMIN_USER, ADMIN_PASS );
		ids.course = await createPost( page, 'odsi_course', { title: `Authoring course ${ stamp }`, content: 'Authored.' } );
		ids.quiz = await createPost( page, 'odsi_quiz', { title: `Authoring quiz ${ stamp }`, meta: { _odsi_course_id: ids.course } } );
		ids.question = await createPost( page, 'odsi_question', { title: 'Which is a colour?', content: 'Pick one.', meta: { _odsi_quiz_id: ids.quiz } } );
		await page.close();
	} );

	test( 'course settings box writes registered meta', async ( { page } ) => {
		await login( page, ADMIN_USER, ADMIN_PASS );
		await openEditor( page, ids.course );

		const box = page.locator( '#odsi-lms-course-settings' );
		await expect( box ).toBeVisible();
		await box.locator( 'select[name="_odsi_access_mode"]' ).selectOption( 'closed' );
		await box.locator( 'input[name="_odsi_access_days"]' ).fill( '30' );
		await box.locator( 'input[name="_odsi_linear_progression"]' ).check();
		await saveAndWait( page );

		const course = await rest( page, 'GET', `/wp/v2/odsi_course/${ ids.course }` );
		expect( course.meta._odsi_access_mode ).toBe( 'closed' );
		expect( course.meta._odsi_access_days ).toBe( 30 );
		expect( course.meta._odsi_linear_progression ).toBe( true );
	} );

	test( 'quiz settings and the question editor feed the player', async ( { page } ) => {
		await login( page, ADMIN_USER, ADMIN_PASS );
		await openEditor( page, ids.quiz );

		const quizBox = page.locator( '#odsi-lms-quiz-settings' );
		await expect( quizBox ).toBeVisible();
		await quizBox.locator( 'input[name="_odsi_pass_mark"]' ).fill( '65' );
		await quizBox.locator( 'input[name="_odsi_max_attempts"]' ).fill( '3' );
		await saveAndWait( page );

		const quiz = await rest( page, 'GET', `/wp/v2/odsi_quiz/${ ids.quiz }` );
		expect( quiz.meta._odsi_pass_mark ).toBe( 65 );
		expect( quiz.meta._odsi_max_attempts ).toBe( 3 );

		await openEditor( page, ids.question );
		await expandMetaBoxes( page );
		const box = page.locator( '#odsi-lms-question' );
		await expect( box ).toBeVisible();
		await box.locator( 'select[name="_odsi_question_type"]' ).selectOption( 'multiple' );
		await box.locator( 'input[name="_odsi_question_points"]' ).fill( '2' );
		await box.locator( 'textarea[name="odsi_question_options"]' ).fill( 'Granite\n*Teal\n*Ochre\nBasalt' );
		await saveAndWait( page );

		// The player reads the stored answers, so the text round-trips and the
		// key stays private (the correct flags are never part of the payload).
		const payload = await rest( page, 'GET', `/odsi-lms/v1/quizzes/${ ids.quiz }/questions` );
		const questions = payload.questions;
		const question = questions.find( ( q ) => q.id === ids.question );
		expect( question.type ).toBe( 'multiple' );
		expect( question.points ).toBe( 2 );
		expect( question.options.map( ( o ) => o.text ) ).toEqual( [ 'Granite', 'Teal', 'Ochre', 'Basalt' ] );
		expect( JSON.stringify( question ) ).not.toContain( 'correct' );

		// Reopening shows the same text with the markers back in place.
		await openEditor( page, ids.question );
		await expandMetaBoxes( page );
		await expect( box.locator( 'textarea[name="odsi_question_options"]' ) ).toHaveValue( 'Granite\n*Teal\n*Ochre\nBasalt' );
	} );
} );
