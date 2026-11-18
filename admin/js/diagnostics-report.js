/**
 * Kopiuje raport diagnostyczny do schowka. Wzorowane na
 * admin/js/diagnostics-report.js z IoT Data Widget (commit b10c54c).
 * navigator.clipboard wymaga HTTPS albo localhost — inaczej pokazujemy
 * pole z raportem zaznaczone do ręcznego skopiowania (execCommand jest
 * przestarzałe).
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var button   = document.getElementById( 'veloxa-solar-copy-report' );
		var textarea = document.getElementById( 'veloxa-solar-report-text' );
		var status   = document.getElementById( 'veloxa-solar-copy-report-status' );

		if ( ! button || ! textarea || ! status ) {
			return;
		}

		function showManual() {
			textarea.hidden = false;
			textarea.focus();
			textarea.select();
			status.textContent = veloxaSolarDiagnosticsReport.failed;
		}

		button.addEventListener( 'click', function () {
			if ( ! navigator.clipboard || ! navigator.clipboard.writeText ) {
				showManual();
				return;
			}

			// writeText() potrafi nigdy się nie rozstrzygnąć (okno bez fokusu,
			// przeglądarka sterowana automatycznie) — wtedy po 2 s pokazujemy
			// pole do ręcznego skopiowania zamiast martwego przycisku.
			var settled = false;
			var timer   = setTimeout( function () {
				if ( ! settled ) {
					settled = true;
					showManual();
				}
			}, 2000 );

			navigator.clipboard.writeText( textarea.value ).then( function () {
				if ( ! settled ) {
					settled = true;
					clearTimeout( timer );
					status.textContent = veloxaSolarDiagnosticsReport.copied;
				}
			}, function () {
				if ( ! settled ) {
					settled = true;
					clearTimeout( timer );
					showManual();
				}
			} );
		} );
	} );
} )();
