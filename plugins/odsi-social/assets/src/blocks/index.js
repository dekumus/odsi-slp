/**
 * Editor side of the community blocks: server-rendered previews with a few
 * sidebar controls.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, RangeControl, ToggleControl, Placeholder } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

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
					<Placeholder label={ title }>{ __( 'Nothing to show yet. Members will see the live community here.', 'odsi-social' ) }</Placeholder>
				) }
			/>
		</div>
	);
}

registerBlockType( 'odsi-social/activity-feed', {
	edit: ( { attributes, setAttributes } ) => (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Feed', 'odsi-social' ) }>
					<SelectControl
						label={ __( 'Scope', 'odsi-social' ) }
						value={ attributes.scope }
						options={ [
							{ value: 'site', label: __( 'Whole site', 'odsi-social' ) },
							{ value: 'personal', label: __( 'People the viewer follows', 'odsi-social' ) },
						] }
						onChange={ ( scope ) => setAttributes( { scope } ) }
					/>
					<RangeControl
						label={ __( 'Items per page', 'odsi-social' ) }
						help={ __( 'Zero uses the community setting.', 'odsi-social' ) }
						value={ attributes.perPage }
						min={ 0 }
						max={ 50 }
						onChange={ ( perPage ) => setAttributes( { perPage } ) }
					/>
					<ToggleControl
						label={ __( 'Show the All / Following tabs', 'odsi-social' ) }
						checked={ attributes.showTabs }
						onChange={ ( showTabs ) => setAttributes( { showTabs } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<Preview block="odsi-social/activity-feed" attributes={ attributes } title={ __( 'Activity feed', 'odsi-social' ) } />
		</>
	),
	save: () => null,
} );

registerBlockType( 'odsi-social/member-directory', {
	edit: () => <Preview block="odsi-social/member-directory" attributes={ {} } title={ __( 'Member directory', 'odsi-social' ) } />,
	save: () => null,
} );

registerBlockType( 'odsi-social/group-directory', {
	edit: () => <Preview block="odsi-social/group-directory" attributes={ {} } title={ __( 'Group directory', 'odsi-social' ) } />,
	save: () => null,
} );
