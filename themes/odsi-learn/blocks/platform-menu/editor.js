/**
 * Editor registration for the platform menu block.
 *
 * The block is rendered on the server (render.php); the editor shows the
 * same markup through ServerSideRender and offers the variant switch.
 *
 * @param {Object} wp The WordPress global.
 */
( function( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, SelectControl, ToggleControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const { __ } = wp.i18n;

	registerBlockType( 'odsi-learn/platform-menu', {
		edit( props ) {
			const { attributes, setAttributes } = props;
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Platform menu', 'odsi-learn' ) },
						el( SelectControl, {
							label: __( 'Layout', 'odsi-learn' ),
							value: attributes.variant,
							options: [
								{ label: __( 'Header', 'odsi-learn' ), value: 'header' },
								{ label: __( 'Footer', 'odsi-learn' ), value: 'footer' },
								{ label: __( 'Inline', 'odsi-learn' ), value: 'inline' },
							],
							onChange( variant ) {
								setAttributes( { variant } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Show account links', 'odsi-learn' ),
							help: __( 'Notifications, messages and log in / log out.', 'odsi-learn' ),
							checked: !! attributes.showAccount,
							onChange( showAccount ) {
								setAttributes( { showAccount } );
							},
						} ),
					),
				),
				el(
					'div',
					useBlockProps(),
					el( ServerSideRender, { block: 'odsi-learn/platform-menu', attributes } ),
				),
			);
		},
		save() {
			return null;
		},
	} );
}( window.wp ) );
