/**
 * Editor side of the LMS blocks. Every block is dynamic: the editor shows the
 * server-rendered output and offers a few controls in the sidebar.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, RangeControl, TextControl, Placeholder, Spinner } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Sidebar picker for a course, or "current course" when inside one.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.value    Selected course id.
 * @param {Function} props.onChange Change handler.
 * @return {JSX.Element} Control.
 */
function CoursePicker( { value, onChange } ) {
	const courses = useSelect(
		( select ) =>
			select( 'core' ).getEntityRecords( 'postType', 'odsi_course', {
				per_page: 100,
				status: [ 'publish', 'draft', 'private' ],
				_fields: 'id,title',
			} ),
		[],
	);

	if ( ! courses ) {
		return <Spinner />;
	}

	return (
		<SelectControl
			label={ __( 'Course', 'odsi-lms' ) }
			value={ String( value || 0 ) }
			options={ [
				{ value: '0', label: __( 'Current course (from context)', 'odsi-lms' ) },
				...courses.map( ( course ) => ( {
					value: String( course.id ),
					label: course.title.rendered || `#${ course.id }`,
				} ) ),
			] }
			onChange={ ( next ) => onChange( parseInt( next, 10 ) || 0 ) }
		/>
	);
}

/**
 * Editor preview shared by every block.
 *
 * @param {Object} props            Props.
 * @param {string} props.block      Block name.
 * @param {Object} props.attributes Attributes.
 * @param {string} props.title      Block title, for the empty state.
 * @return {JSX.Element} Preview.
 */
function Preview( { block, attributes, title } ) {
	return (
		<div { ...useBlockProps() }>
			<ServerSideRender
				block={ block }
				attributes={ attributes }
				EmptyResponsePlaceholder={ () => (
					<Placeholder label={ title }>
						{ __( 'Nothing to show here yet. This block renders for the visitor based on their enrollment and the course it sits in.', 'odsi-lms' ) }
					</Placeholder>
				) }
			/>
		</div>
	);
}

const courseBlocks = {
	'odsi-lms/course-outline': __( 'Course outline', 'odsi-lms' ),
	'odsi-lms/course-progress': __( 'Course progress', 'odsi-lms' ),
	'odsi-lms/enroll-button': __( 'Enroll button', 'odsi-lms' ),
};

Object.keys( courseBlocks ).forEach( ( name ) => {
	registerBlockType( name, {
		edit: ( { attributes, setAttributes } ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Course', 'odsi-lms' ) }>
						<CoursePicker value={ attributes.courseId } onChange={ ( courseId ) => setAttributes( { courseId } ) } />
					</PanelBody>
				</InspectorControls>
				<Preview block={ name } attributes={ attributes } title={ courseBlocks[ name ] } />
			</>
		),
		save: () => null,
	} );
} );

registerBlockType( 'odsi-lms/my-courses', {
	edit: ( { attributes, setAttributes } ) => (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Filter', 'odsi-lms' ) }>
					<SelectControl
						label={ __( 'Enrollment status', 'odsi-lms' ) }
						value={ attributes.status }
						options={ [
							{ value: '', label: __( 'All', 'odsi-lms' ) },
							{ value: 'active', label: __( 'In progress', 'odsi-lms' ) },
							{ value: 'completed', label: __( 'Completed', 'odsi-lms' ) },
						] }
						onChange={ ( status ) => setAttributes( { status } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<Preview block="odsi-lms/my-courses" attributes={ attributes } title={ __( 'My courses', 'odsi-lms' ) } />
		</>
	),
	save: () => null,
} );

registerBlockType( 'odsi-lms/course-grid', {
	edit: ( { attributes, setAttributes } ) => (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Courses', 'odsi-lms' ) }>
					<RangeControl
						label={ __( 'Courses to show', 'odsi-lms' ) }
						value={ attributes.perPage }
						min={ 1 }
						max={ 48 }
						onChange={ ( perPage ) => setAttributes( { perPage } ) }
					/>
					<TextControl
						label={ __( 'Category slug', 'odsi-lms' ) }
						help={ __( 'Leave empty for every course.', 'odsi-lms' ) }
						value={ attributes.category }
						onChange={ ( category ) => setAttributes( { category } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<Preview block="odsi-lms/course-grid" attributes={ attributes } title={ __( 'Course grid', 'odsi-lms' ) } />
		</>
	),
	save: () => null,
} );
