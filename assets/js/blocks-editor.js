/**
 * Init View Count — Block Editor integration.
 *
 * Viết bằng vanilla JS (không JSX, không build step) để deploy trực tiếp lên
 * SVN của WordPress.org mà không cần Node/webpack. Mỗi block dùng
 * ServerSideRender để xem trước, và PHP render.php tương ứng (đăng ký qua
 * "render" trong block.json) để xuất HTML — dùng lại 100% logic shortcode
 * đã có, không lặp lại code.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var SelectControl = wp.components.SelectControl;
	var RangeControl = wp.components.RangeControl;
	var ServerSideRender = wp.serverSideRender;

	function toInt( value, fallback ) {
		var parsed = parseInt( value, 10 );
		return isNaN( parsed ) ? fallback : parsed;
	}

	// ---------------------------------------------------------------------
	// init-view-count/view-count
	// ---------------------------------------------------------------------
	registerBlockType( 'init-view-count/view-count', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			return el(
				Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'View Count Settings', 'init-view-count' ) },
						el( TextControl, {
							label: __( 'Post ID (0 = current post)', 'init-view-count' ),
							help: __( 'Leave as 0 to always show the view count of whichever post/page this block is on.', 'init-view-count' ),
							type: 'number',
							min: 0,
							value: attributes.postId,
							onChange: function ( value ) {
								setAttributes( { postId: toInt( value, 0 ) } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Field', 'init-view-count' ),
							value: attributes.field,
							options: [
								{ label: __( 'Total', 'init-view-count' ), value: 'total' },
								{ label: __( 'Today', 'init-view-count' ), value: 'day' },
								{ label: __( 'This Week', 'init-view-count' ), value: 'week' },
								{ label: __( 'This Month', 'init-view-count' ), value: 'month' },
							],
							onChange: function ( value ) {
								setAttributes( { field: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Format', 'init-view-count' ),
							value: attributes.format,
							options: [
								{ label: __( 'Formatted (1,234)', 'init-view-count' ), value: 'formatted' },
								{ label: __( 'Short (1.2K)', 'init-view-count' ), value: 'short' },
								{ label: __( 'Raw', 'init-view-count' ), value: 'raw' },
							],
							onChange: function ( value ) {
								setAttributes( { format: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Show time since published', 'init-view-count' ),
							checked: !! attributes.showTime,
							onChange: function ( value ) {
								setAttributes( { showTime: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Show eye icon', 'init-view-count' ),
							checked: !! attributes.showIcon,
							onChange: function ( value ) {
								setAttributes( { showIcon: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Enable Schema.org markup', 'init-view-count' ),
							checked: !! attributes.showSchema,
							onChange: function ( value ) {
								setAttributes( { showSchema: value } );
							},
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block: 'init-view-count/view-count',
						attributes: attributes,
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );

	// ---------------------------------------------------------------------
	// init-view-count/view-list
	// ---------------------------------------------------------------------
	registerBlockType( 'init-view-count/view-list', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			return el(
				Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'List Settings', 'init-view-count' ) },
						el( TextControl, {
							label: __( 'Title', 'init-view-count' ),
							placeholder: __( 'Popular Posts', 'init-view-count' ),
							help: __( 'Leave empty to use the default title.', 'init-view-count' ),
							value: attributes.title,
							onChange: function ( value ) {
								setAttributes( { title: value } );
							},
						} ),
						el( RangeControl, {
							label: __( 'Number of posts', 'init-view-count' ),
							min: 1,
							max: 50,
							value: attributes.number,
							onChange: function ( value ) {
								setAttributes( { number: toInt( value, 10 ) } );
							},
						} ),
						el( TextControl, {
							label: __( 'Post type', 'init-view-count' ),
							help: __( 'Post type slug, e.g. "post" or a custom post type.', 'init-view-count' ),
							value: attributes.postType,
							onChange: function ( value ) {
								setAttributes( { postType: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Template', 'init-view-count' ),
							value: attributes.template,
							options: [
								{ label: __( 'Sidebar', 'init-view-count' ), value: 'sidebar' },
								{ label: __( 'Grid', 'init-view-count' ), value: 'grid' },
								{ label: __( 'Details', 'init-view-count' ), value: 'details' },
								{ label: __( 'Full', 'init-view-count' ), value: 'full' },
							],
							onChange: function ( value ) {
								setAttributes( { template: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'View range', 'init-view-count' ),
							value: attributes.range,
							options: [
								{ label: __( 'All Time', 'init-view-count' ), value: 'total' },
								{ label: __( 'Today', 'init-view-count' ), value: 'day' },
								{ label: __( 'This Week', 'init-view-count' ), value: 'week' },
								{ label: __( 'This Month', 'init-view-count' ), value: 'month' },
							],
							onChange: function ( value ) {
								setAttributes( { range: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Category (slug)', 'init-view-count' ),
							value: attributes.category,
							onChange: function ( value ) {
								setAttributes( { category: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Tag (slug)', 'init-view-count' ),
							value: attributes.tag,
							onChange: function ( value ) {
								setAttributes( { tag: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Empty state text', 'init-view-count' ),
							help: __( 'Shown when no posts match. Leave empty to render nothing.', 'init-view-count' ),
							value: attributes.emptyText,
							onChange: function ( value ) {
								setAttributes( { emptyText: value } );
							},
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block: 'init-view-count/view-list',
						attributes: attributes,
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );

	// ---------------------------------------------------------------------
	// init-view-count/view-ranking
	// ---------------------------------------------------------------------
	registerBlockType( 'init-view-count/view-ranking', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			return el(
				Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Ranking Settings', 'init-view-count' ) },
						el( TextControl, {
							label: __( 'Tabs', 'init-view-count' ),
							help: __( 'Comma-separated: total, day, week, month, yesterday, last_week, last_month.', 'init-view-count' ),
							value: attributes.tabs,
							onChange: function ( value ) {
								setAttributes( { tabs: value } );
							},
						} ),
						el( RangeControl, {
							label: __( 'Number of posts per tab', 'init-view-count' ),
							min: 1,
							max: 20,
							value: attributes.number,
							onChange: function ( value ) {
								setAttributes( { number: toInt( value, 5 ) } );
							},
						} ),
						el( TextControl, {
							label: __( 'Post type', 'init-view-count' ),
							help: __( 'Optional. Leave empty to use the site default.', 'init-view-count' ),
							value: attributes.postType,
							onChange: function ( value ) {
								setAttributes( { postType: value } );
							},
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block: 'init-view-count/view-ranking',
						attributes: attributes,
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
