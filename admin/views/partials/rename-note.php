<?php
/**
 * Uwaga pod polem nazwy w trybie edycji: blok, shortcode i ekran
 * pełnoekranowy używają ID, więc zmiana nazwy niczego nie psuje.
 *
 * @package Veloxa\Solar
 *
 * @var array<string, mixed>|null $editing Edytowane połączenie albo null przy dodawaniu.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- szablon dołączany (require) wewnątrz metody Connections_Page; zmienne są lokalne dla tej metody, nie globalne.

if ( null === $editing ) {
	return;
}
?>
<p class="description">
	<?php
	printf(
		/* translators: %s: connection ID */
		esc_html__( 'You can rename freely: blocks, shortcodes and the full-screen display use the ID, which never changes: %s', 'veloxa-solar-widget' ),
		'<code>' . esc_html( $editing['id'] ) . '</code>'
	);
	?>
</p>
