/**
 * Prośba o opinię: przyciski i krzyżyk bez przeładowania strony.
 * Bez JS te same akcje działają przez linki admin-post (z nonce).
 * „Leave a review” otwiera nową kartę przez admin-post, który zapisuje
 * zamknięcie i przekierowuje na WordPress.org — tu tylko chowamy komunikat.
 */
( function () {
	'use strict';

	function send( notice, action ) {
		var body = new URLSearchParams();

		body.append( 'action', 'veloxa_solar_review_notice' );
		body.append( 'notice_action', action );
		body.append( 'nonce', notice.getAttribute( 'data-nonce' ) || '' );

		window.fetch( notice.getAttribute( 'data-ajax-url' ) || '', {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).catch( function () {} );
	}

	document.addEventListener( 'click', function ( event ) {
		var notice = event.target.closest( '.veloxa-solar-review-notice' );
		var link;
		var action;

		if ( ! notice ) {
			return;
		}

		// Standardowy krzyżyk WordPressa = „Maybe later”.
		if ( event.target.closest( '.notice-dismiss' ) ) {
			send( notice, 'later' );
			return;
		}

		link = event.target.closest( 'a[data-review-action]' );

		if ( ! link ) {
			return;
		}

		action = link.getAttribute( 'data-review-action' );

		if ( 'review' !== action ) {
			event.preventDefault();
			send( notice, action );
		}

		notice.parentNode.removeChild( notice );
	} );
}() );
