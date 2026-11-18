/**
 * "Sprawdź, czy dane docierają" — kryterium akceptacji Fazy 3 briefu.
 * Wzorowane na admin/js/push-check-receiving.js z IoT Data Widget:
 * migawka czasów odbioru przed nasłuchem, potem odpytywanie statusu kanału
 * co kilka sekund i sukces przy pierwszym NOWYM odczycie (nie przy
 * czymkolwiek niepustym — kanał mógł już coś odebrać wcześniej). Tabela
 * encji odświeżana przy każdym tyknięciu, bo server-side render jest
 * migawką sprzed wysłania danych (ten sam błąd był w IoT, 2026-09-07).
 *
 * Adres statusu przychodzi z PHP (rest_url()), nigdy nie jest sklejany
 * tutaj — działa też przy bezpośrednich permalinkach (?rest_route=).
 */
( function () {
	'use strict';

	var POLL_INTERVAL_MS = 3000;
	var TOTAL_TIMEOUT_MS = 120000;

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'veloxa-solar-push-check' );
		var result = document.getElementById( 'veloxa-solar-push-check-result' );
		var config = window.veloxaSolarPushCheck;

		if ( ! button || ! result || ! config ) {
			return;
		}

		function fetchStatus() {
			return fetch( config.statusUrl, {
				headers: { 'X-WP-Nonce': config.nonce },
				credentials: 'same-origin',
			} ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}
				return response.json();
			} );
		}

		function refreshTable( entities ) {
			( entities || [] ).forEach( function ( entity ) {
				var row = document.querySelector( '[data-entity-row="' + entity.entity_key + '"]' );

				if ( ! row ) {
					return;
				}

				var valueCell = row.querySelector( '[data-entity-value]' );
				var timeCell = row.querySelector( '[data-entity-time]' );

				if ( valueCell ) {
					if ( ! entity.last_recorded_at ) {
						valueCell.textContent = config.noData;
					} else {
						var value = null !== entity.last_value_txt && undefined !== entity.last_value_txt ? entity.last_value_txt : entity.last_value_num;
						valueCell.textContent = value + ( entity.unit ? ' ' + entity.unit : '' );
					}
				}

				if ( timeCell ) {
					timeCell.textContent = entity.last_recorded_at || '—';
				}
			} );
		}

		function refreshReading( data ) {
			var box = document.getElementById( 'veloxa-solar-push-reading' );

			if ( ! box ) {
				return;
			}

			box.textContent = '';

			if ( data.reading ) {
				Object.keys( data.reading.formatted ).forEach( function ( field ) {
					var line = document.createElement( 'div' );
					var strong = document.createElement( 'strong' );

					strong.textContent = data.reading.formatted[ field ];
					line.textContent = ( config.labels[ field ] || field ) + ': ';
					line.appendChild( strong );
					box.appendChild( line );
				} );
			}

			if ( data.waiting ) {
				var note = document.createElement( 'p' );
				note.className = 'description';
				note.textContent = data.waiting;
				box.appendChild( note );
			}
		}

		function finish( message, color ) {
			result.textContent = message;
			result.style.color = color;
			button.disabled = false;
		}

		var baseline = {};

		function poll( startedAt ) {
			if ( Date.now() - startedAt > TOTAL_TIMEOUT_MS ) {
				finish( config.timeout, '#996800' );
				return;
			}

			fetchStatus()
				.then( function ( data ) {
					refreshTable( data.entities );

					var received = ( data.entities || [] ).some( function ( entity ) {
						return entity.last_recorded_at && entity.last_recorded_at !== baseline[ entity.entity_key ];
					} );

					if ( received ) {
						refreshReading( data );
						finish( config.received + ( data.waiting ? ' ' + data.waiting : '' ), '#008a20' );
						return;
					}

					setTimeout( function () {
						poll( startedAt );
					}, POLL_INTERVAL_MS );
				} )
				.catch( function () {
					finish( config.networkError, '#d63638' );
				} );
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			result.textContent = config.listening;
			result.style.color = '#646970';

			fetchStatus()
				.then( function ( data ) {
					baseline = {};
					( data.entities || [] ).forEach( function ( entity ) {
						baseline[ entity.entity_key ] = entity.last_recorded_at;
					} );
					refreshTable( data.entities );
					poll( Date.now() );
				} )
				.catch( function () {
					finish( config.networkError, '#d63638' );
				} );
		} );
	} );
} )();
