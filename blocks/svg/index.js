/**
 * SVG Support — Inline SVG block (editor script).
 *
 * Deliberately build-free: plain wp.element.createElement against the block
 * editor APIs, shipped as-is. The block is dynamic — the server renders the
 * final inline SVG through the same engine the front end uses, so the saved
 * content is just the block comment.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var MediaPlaceholder = wp.blockEditor.MediaPlaceholder;
	var MediaReplaceFlow = wp.blockEditor.MediaReplaceFlow;
	var BlockControls = wp.blockEditor.BlockControls;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var ToggleControl = wp.components.ToggleControl;

	var blockIcon = el(
		'svg',
		{ width: 24, height: 24, viewBox: '0 0 24 24', fill: 'none', 'aria-hidden': true },
		el( 'path', {
			d: 'M12 3 3 21h18L12 3z',
			stroke: 'currentColor',
			strokeWidth: 2,
			strokeLinejoin: 'round',
			fill: 'none'
		} )
	);

	function onSelectMedia( props ) {
		return function ( media ) {
			if ( ! media || ! media.id ) {
				return;
			}
			props.setAttributes( {
				id: media.id,
				url: media.url,
				alt: media.alt || ''
			} );
		};
	}

	registerBlockType( 'svg-support/inline-svg', {
		icon: blockIcon,

		edit: function ( props ) {
			var attributes = props.attributes;
			var blockProps = useBlockProps( {
				className: 'svg-support-block-editor-preview'
			} );

			// No SVG chosen yet: show the media placeholder.
			if ( ! attributes.id ) {
				return el(
					'div',
					blockProps,
					el( MediaPlaceholder, {
						icon: blockIcon,
						labels: {
							title: __( 'Inline SVG', 'svg-support' ),
							instructions: __( 'Choose an SVG from your media library or upload a new one. It renders as true inline SVG on your site.', 'svg-support' )
						},
						accept: 'image/svg+xml',
						allowedTypes: [ 'image/svg+xml' ],
						onSelect: onSelectMedia( props )
					} )
				);
			}

			var previewStyle = {};
			if ( attributes.width ) {
				previewStyle.width = attributes.width;
			}
			if ( attributes.height ) {
				previewStyle.height = attributes.height;
			}

			return el(
				'div',
				blockProps,
				el(
					BlockControls,
					{ group: 'other' },
					el( MediaReplaceFlow, {
						mediaId: attributes.id,
						mediaURL: attributes.url,
						accept: 'image/svg+xml',
						allowedTypes: [ 'image/svg+xml' ],
						name: __( 'Replace SVG', 'svg-support' ),
						onSelect: onSelectMedia( props )
					} )
				),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'SVG settings', 'svg-support' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Width', 'svg-support' ),
							help: __( 'Any CSS length (e.g. 120px, 10rem, 100%). Leave blank to use the file’s own size.', 'svg-support' ),
							value: attributes.width,
							onChange: function ( value ) {
								props.setAttributes( { width: value } );
							}
						} ),
						el( TextControl, {
							label: __( 'Height', 'svg-support' ),
							value: attributes.height,
							onChange: function ( value ) {
								props.setAttributes( { height: value } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Inherit text color', 'svg-support' ),
							help: __( 'Maps the SVG’s fill colors to currentColor so it follows your theme’s text color.', 'svg-support' ),
							checked: !! attributes.useCurrentColor,
							onChange: function ( value ) {
								props.setAttributes( { useCurrentColor: !! value } );
							}
						} ),
						el( TextControl, {
							label: __( 'Custom ID', 'svg-support' ),
							help: __( 'Optional id attribute for CSS/JS targeting.', 'svg-support' ),
							value: attributes.customId,
							onChange: function ( value ) {
								props.setAttributes( { customId: value } );
							}
						} ),
						el( TextareaControl, {
							label: __( 'Alternative text', 'svg-support' ),
							help: __( 'Describes the graphic for screen readers. Leave empty if purely decorative.', 'svg-support' ),
							value: attributes.alt,
							onChange: function ( value ) {
								props.setAttributes( { alt: value } );
							}
						} )
					)
				),
				// Editor preview: the raw file as an <img> (safe — scripts never
				// run in an image context). The real inline markup is produced
				// server-side on render.
				el( 'img', {
					src: attributes.url,
					alt: attributes.alt,
					style: previewStyle
				} )
			);
		},

		// Dynamic block: the server renders it.
		save: function () {
			return null;
		}
	} );
} )( window.wp );
