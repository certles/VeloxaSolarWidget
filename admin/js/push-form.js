/**
 * Formularz kanału push: zmiana jednostki albo „Odwróć znak” przy polu
 * dodatkowym (bateria, sieć, dom) zaznacza to pole. Bez tego łatwo było
 * ustawić jednostkę i nie zaznaczyć pola — kanał odrzucał potem te wartości
 * (przegląd demo 2026-10-22).
 */
( function () {
	'use strict';

	document.addEventListener( 'change', function ( event ) {
		var target = event.target;
		var match;
		var enabled;

		if ( ! target || ! target.name ) {
			return;
		}

		match = target.name.match( /^fields\[([a-z_]+)\]\[(unit|invert)\]$/ );

		if ( ! match ) {
			return;
		}

		enabled = document.querySelector(
			'input[type="checkbox"][name="fields[' + match[ 1 ] + '][enabled]"]'
		);

		if ( enabled && ! enabled.checked ) {
			enabled.checked = true;
		}
	} );
} )();
