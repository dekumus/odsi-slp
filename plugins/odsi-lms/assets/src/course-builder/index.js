/**
 * Course builder: a document-settings panel in the block editor that shows a
 * course's outline as a tree and lets an instructor add, reorder and detach
 * lessons, topics and quizzes without leaving the course.
 *
 * It writes the same relationship meta and menu_order the classic meta boxes
 * write, through odsi-lms/v1/courses/{id}/builder, so the two stay in step.
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Flex, FlexItem, Notice, Spinner, TextControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

import './style.css';

const LESSON = 'odsi_lesson';
const TOPIC = 'odsi_topic';
const QUIZ = 'odsi_quiz';

/**
 * Move an item within a list and return the reorder payload for the list.
 *
 * @param {Array}  list      Sibling nodes.
 * @param {number} index     Index to move.
 * @param {number} direction -1 up, +1 down.
 * @param {number} parent    Parent id for the payload.
 * @return {Array} Payload items.
 */
function moved( list, index, direction, parent ) {
	const next = list.slice();
	const target = index + direction;

	if ( target < 0 || target >= next.length ) {
		return null;
	}

	[ next[ index ], next[ target ] ] = [ next[ target ], next[ index ] ];

	return next.map( ( node, i ) => ( { id: node.id, parent, order: i + 1 } ) );
}

/**
 * Inline "add" form.
 *
 * @param {Object}   props
 * @param {string}   props.label    Button label.
 * @param {Function} props.onSubmit Called with the title.
 */
function AddForm( { label, onSubmit } ) {
	const [ title, setTitle ] = useState( '' );
	const [ open, setOpen ] = useState( false );

	if ( ! open ) {
		return (
			<Button variant="link" onClick={ () => setOpen( true ) } className="odsi-builder__add">
				{ label }
			</Button>
		);
	}

	return (
		<form
			className="odsi-builder__add-form"
			onSubmit={ ( event ) => {
				event.preventDefault();
				if ( title.trim() ) {
					onSubmit( title.trim() );
					setTitle( '' );
					setOpen( false );
				}
			} }
		>
			<TextControl value={ title } onChange={ setTitle } placeholder={ __( 'Title', 'odsi-lms' ) } __nextHasNoMarginBottom />
			<Flex justify="flex-end">
				<FlexItem>
					<Button variant="tertiary" onClick={ () => setOpen( false ) }>
						{ __( 'Cancel', 'odsi-lms' ) }
					</Button>
				</FlexItem>
				<FlexItem>
					<Button variant="primary" type="submit">
						{ __( 'Add', 'odsi-lms' ) }
					</Button>
				</FlexItem>
			</Flex>
		</form>
	);
}

/**
 * One row: title, status, move and detach controls.
 *
 * @param {Object}   props
 * @param {Object}   props.node     Node.
 * @param {Function} props.onMove   Called with direction.
 * @param {Function} props.onDetach Called to detach.
 * @param {string}   props.kind     Display kind.
 */
function Row( { node, onMove, onDetach, kind } ) {
	return (
		<div className={ `odsi-builder__row odsi-builder__row--${ kind }` }>
			<span className="odsi-builder__kind">{ kind }</span>
			<a className="odsi-builder__title" href={ node.edit }>
				{ node.title }
				{ node.status !== 'publish' && <em className="odsi-builder__status"> ({ node.status })</em> }
			</a>
			<Button icon="arrow-up-alt2" label={ __( 'Move up', 'odsi-lms' ) } size="small" onClick={ () => onMove( -1 ) } />
			<Button icon="arrow-down-alt2" label={ __( 'Move down', 'odsi-lms' ) } size="small" onClick={ () => onMove( 1 ) } />
			<Button icon="no-alt" label={ __( 'Remove from course', 'odsi-lms' ) } size="small" isDestructive onClick={ onDetach } />
		</div>
	);
}

/**
 * The panel.
 */
function CourseBuilder() {
	const { postId, postType } = useSelect( ( select ) => ( {
		postId: select( 'core/editor' ).getCurrentPostId(),
		postType: select( 'core/editor' ).getCurrentPostType(),
	} ), [] );

	const [ tree, setTree ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const base = `/odsi-lms/v1/courses/${ postId }/builder`;

	const load = useCallback( () => {
		if ( ! postId ) {
			return;
		}
		apiFetch( { path: base } ).then( setTree ).catch( ( e ) => setError( e.message ) );
	}, [ postId, base ] );

	useEffect( load, [ load ] );

	const call = ( path, data ) => {
		setBusy( true );
		setError( '' );
		return apiFetch( { path: base + path, method: data ? 'POST' : 'DELETE', data } )
			.then( setTree )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};

	if ( postType !== 'odsi_course' ) {
		return null;
	}

	if ( ! postId ) {
		return (
			<PluginDocumentSettingPanel name="odsi-course-builder" title={ __( 'Course builder', 'odsi-lms' ) }>
				<p>{ __( 'Save the course once to start adding lessons.', 'odsi-lms' ) }</p>
			</PluginDocumentSettingPanel>
		);
	}

	const reorder = ( list, index, direction, parent ) => {
		const items = moved( list, index, direction, parent );
		if ( items ) {
			call( '/reorder', { items } );
		}
	};

	return (
		<PluginDocumentSettingPanel name="odsi-course-builder" title={ __( 'Course builder', 'odsi-lms' ) } className="odsi-builder">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! tree && ! error && <Spinner /> }
			{ tree && (
				<div className={ busy ? 'odsi-builder__tree is-busy' : 'odsi-builder__tree' }>
					{ tree.lessons.length === 0 && tree.quizzes.length === 0 && <p>{ __( 'No content yet. Add a lesson to begin.', 'odsi-lms' ) }</p> }
					{ tree.lessons.map( ( lesson, li ) => (
						<div className="odsi-builder__lesson" key={ lesson.id }>
							<Row node={ lesson } kind={ __( 'Lesson', 'odsi-lms' ) } onMove={ ( d ) => reorder( tree.lessons, li, d, 0 ) } onDetach={ () => call( `/${ lesson.id }` ) } />
							<div className="odsi-builder__children">
								{ lesson.topics.map( ( topic, ti ) => (
									<div key={ topic.id }>
										<Row node={ topic } kind={ __( 'Topic', 'odsi-lms' ) } onMove={ ( d ) => reorder( lesson.topics, ti, d, lesson.id ) } onDetach={ () => call( `/${ topic.id }` ) } />
										<div className="odsi-builder__children">
											{ topic.quizzes.map( ( quiz, qi ) => (
												<Row key={ quiz.id } node={ quiz } kind={ __( 'Quiz', 'odsi-lms' ) } onMove={ ( d ) => reorder( topic.quizzes, qi, d, topic.id ) } onDetach={ () => call( `/${ quiz.id }` ) } />
											) ) }
											<AddForm label={ __( '+ Quiz', 'odsi-lms' ) } onSubmit={ ( title ) => call( '', { type: QUIZ, title, parent: topic.id } ) } />
										</div>
									</div>
								) ) }
								{ lesson.quizzes.map( ( quiz, qi ) => (
									<Row key={ quiz.id } node={ quiz } kind={ __( 'Quiz', 'odsi-lms' ) } onMove={ ( d ) => reorder( lesson.quizzes, qi, d, lesson.id ) } onDetach={ () => call( `/${ quiz.id }` ) } />
								) ) }
								<AddForm label={ __( '+ Topic', 'odsi-lms' ) } onSubmit={ ( title ) => call( '', { type: TOPIC, title, parent: lesson.id } ) } />
								<AddForm label={ __( '+ Quiz', 'odsi-lms' ) } onSubmit={ ( title ) => call( '', { type: QUIZ, title, parent: lesson.id } ) } />
							</div>
						</div>
					) ) }
					{ tree.quizzes.map( ( quiz, qi ) => (
						<Row key={ quiz.id } node={ quiz } kind={ __( 'Quiz', 'odsi-lms' ) } onMove={ ( d ) => reorder( tree.quizzes, qi, d, 0 ) } onDetach={ () => call( `/${ quiz.id }` ) } />
					) ) }
					<AddForm label={ __( '+ Lesson', 'odsi-lms' ) } onSubmit={ ( title ) => call( '', { type: LESSON, title } ) } />
					<AddForm label={ __( '+ Course quiz', 'odsi-lms' ) } onSubmit={ ( title ) => call( '', { type: QUIZ, title, parent: 0 } ) } />
				</div>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'odsi-course-builder', { render: CourseBuilder, icon: 'welcome-learn-more' } );
