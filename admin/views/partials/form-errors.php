<?php
/**
 * Błędy walidacji jednego formularza (z Connections_Page::fail_form()).
 *
 * @package Veloxa\Solar
 *
 * @var array{form: string, errors: string[], values: array<string, mixed>} $form_state Stan ostatniego nieudanego zapisu.
 * @var string                                                               $form_id    Identyfikator tego formularza.
 * @var string                                                               $form_note  Dodatkowa uwaga pod listą błędów (może być pusta).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- szablon dołączany (require) wewnątrz metody Connections_Page; zmienne są lokalne dla tej metody, nie globalne.

if ( $form_id !== $form_state['form'] || empty( $form_state['errors'] ) ) {
	return;
}
?>
<div class="notice notice-error inline">
	<p><strong><?php esc_html_e( 'Not saved:', 'veloxa-solar-widget' ); ?></strong></p>
	<ul>
		<?php foreach ( $form_state['errors'] as $form_error ) : ?>
			<li><?php echo esc_html( $form_error ); ?></li>
		<?php endforeach; ?>
	</ul>
	<?php if ( '' !== $form_note ) : ?>
		<p><?php echo esc_html( $form_note ); ?></p>
	<?php endif; ?>
</div>
