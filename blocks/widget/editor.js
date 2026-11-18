/**
 * Edytor bloku veloxa-solar-widget/widget — bez kroku budowania. Ustawienia w
 * panelu bocznym, podgląd renderowany przez serwer (ten sam HTML co na
 * froncie i w shortcodzie).
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var CheckboxControl = wp.components.CheckboxControl;
	var Notice = wp.components.Notice;
	var ServerSideRender = wp.serverSideRender;
	var data = window.veloxaSolarBlock || { widgets: {}, styles: {}, connections: [] };
	var proWidgets = data.proWidgets || {};
	var agencyWidgets = data.agencyWidgets || {};
	var noSource = data.noSource || [];
	var attributeOptions = data.attributes || {};

	function toOptions( map ) {
		return Object.keys( map ).map( function ( key ) {
			return { value: key, label: map[ key ] };
		} );
	}

	wp.blocks.registerBlockType( 'veloxa-solar-widget/widget', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var connectionOptions = data.connections.map( function ( connection ) {
				return { value: connection.id, label: connection.name };
			} );

			if ( connectionOptions.length > 1 || ! attributes.connection ) {
				connectionOptions.unshift( { value: '', label: __( '— choose —', 'veloxa-solar-widget' ) } );
			}

			var selected = data.connections.find( function ( connection ) {
				return connection.id === attributes.connection;
			} ) || ( 1 === data.connections.length ? data.connections[ 0 ] : null );

			// Tylko widgety, dla których wybrane źródło ma dane.
			var widgetOptions = toOptions( data.widgets ).filter( function ( option ) {
				return ! selected || ! selected.widgets || -1 !== selected.widgets.indexOf( option.value ) || option.value === attributes.widget;
			} );
			var isPro = Object.prototype.hasOwnProperty.call( proWidgets, attributes.widget );
			var isAgency = Object.prototype.hasOwnProperty.call( agencyWidgets, attributes.widget );
			var withoutSource = -1 !== noSource.indexOf( attributes.widget );
			var unsupported = ! isPro && ! isAgency && ! withoutSource && selected && selected.widgets && -1 === selected.widgets.indexOf( attributes.widget );

			// Widgety Pro bez działającego kodu Pro: na liście z oznaczeniem,
			// po wybraniu komunikat zamiast podglądu (bez błędu, bez danych).
			Object.keys( proWidgets ).forEach( function ( key ) {
				widgetOptions.push( { value: key, label: proWidgets[ key ] + ' (Pro)' } );
			} );
			Object.keys( agencyWidgets ).forEach( function ( key ) {
				widgetOptions.push( { value: key, label: agencyWidgets[ key ] + ' (Agency)' } );
			} );

			// Atrybuty zależne od widgetu (np. period, show) — opcje z kodu Pro.
			var extra = attributeOptions[ attributes.widget ] || {};
			var extraControls = Object.keys( extra ).map( function ( name ) {
				var config = extra[ name ];
				var current = attributes[ name ] || '';

				if ( ! config.multiple ) {
					return el( SelectControl, {
						key: name,
						label: config.label,
						value: current || Object.keys( config.options )[ 0 ],
						options: toOptions( config.options ),
						onChange: function ( value ) {
							var update = {};
							update[ name ] = value;
							props.setAttributes( update );
						},
					} );
				}

				// Wiele wartości → lista po przecinku; puste = wszystkie.
				var chosen = current ? current.split( ',' ) : Object.keys( config.options );

				return el(
					'fieldset',
					{ key: name },
					el( 'legend', null, config.label ),
					Object.keys( config.options ).map( function ( option ) {
						return el( CheckboxControl, {
							key: option,
							label: config.options[ option ],
							checked: -1 !== chosen.indexOf( option ),
							onChange: function ( checked ) {
								var next = Object.keys( config.options ).filter( function ( key ) {
									return key === option ? checked : -1 !== chosen.indexOf( key );
								} );
								var update = {};
								update[ name ] = next.length === Object.keys( config.options ).length ? '' : next.join( ',' );
								props.setAttributes( update );
							},
						} );
					} )
				);
			} );

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Widget', 'veloxa-solar-widget' ) },
						el( SelectControl, {
							label: __( 'Show', 'veloxa-solar-widget' ),
							value: attributes.widget,
							options: widgetOptions,
							onChange: function ( value ) {
								props.setAttributes( { widget: value } );
							},
						} ),
						extraControls,
						withoutSource
							? el( 'p', { className: 'description' }, __( 'Shows all data sources that are visible on public pages.', 'veloxa-solar-widget' ) )
							: el( SelectControl, {
								label: __( 'Data source', 'veloxa-solar-widget' ),
								value: attributes.connection,
								options: connectionOptions,
								onChange: function ( value ) {
									props.setAttributes( { connection: value } );
								},
							} ),
						el( SelectControl, {
							label: __( 'Style', 'veloxa-solar-widget' ),
							value: attributes.style,
							options: toOptions( data.styles ),
							onChange: function ( value ) {
								props.setAttributes( { style: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Label (optional)', 'veloxa-solar-widget' ),
							value: attributes.label,
							onChange: function ( value ) {
								props.setAttributes( { label: value } );
							},
						} ),
						unsupported
							? el(
								Notice,
								{ status: 'warning', isDismissible: false },
								__( 'This data source does not provide the data for the selected widget — choose another widget or source.', 'veloxa-solar-widget' )
							)
							: null,
						! withoutSource && selected && ! selected.public
							? el(
								Notice,
								{ status: 'warning', isDismissible: false },
								__( 'This source is not public yet — visitors will not see the widget until you enable "Show on public pages" in Solar Widget → Connections.', 'veloxa-solar-widget' )
							)
							: null
					)
				),
				isPro || isAgency
					? el( Notice, { status: 'info', isDismissible: false }, isAgency ? data.agencyNotice : data.proNotice )
					: el( ServerSideRender, {
						block: 'veloxa-solar-widget/widget',
						attributes: attributes,
					} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
