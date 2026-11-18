<?php
/**
 * Formularz Home Assistant (krok 1: adres + token) — dodanie albo edycja
 * ($editing). Przy edycji pusty token = bez zmian, mapowanie czujników zostaje.
 *
 * @package Veloxa\Solar
 *
 * @var array<string, mixed>|null                                             $editing    Edytowane połączenie albo null przy dodawaniu.
 * @var array<string, mixed>                                                  $ha_values  Wartości do wypełnienia (bez tokenu).
 * @var array{form: string, errors: string[], values: array<string, mixed>} $form_state Stan ostatniego nieudanego zapisu.
 */

use Veloxa\Solar\Sources\Abstract_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- szablon dołączany (require) wewnątrz metody Connections_Page; zmienne są lokalne dla tej metody, nie globalne.

$form_id   = 'veloxa-solar-ha-form';
$form_note = null === $editing
	? __( 'Enter the access token again — it is not kept after a failed attempt.', 'veloxa-solar-widget' )
	: __( 'Nothing was changed. A token left empty keeps the current one.', 'veloxa-solar-widget' );
?>
<?php if ( null === $editing ) : ?>
	<h2 id="veloxa-solar-ha-form"><?php esc_html_e( 'Add Home Assistant', 'veloxa-solar-widget' ); ?></h2>
<?php else : ?>
	<span id="veloxa-solar-ha-form"></span>
<?php endif; ?>
<p class="description"><?php esc_html_e( 'Home Assistant must be reachable from your web server — usually through Home Assistant Cloud (Nabu Casa) or your own reverse proxy. Local addresses (192.168.x.x, homeassistant.local) are blocked unless "Allow local network addresses" is on in the settings at the bottom of the Connections screen — use it only when this site runs in the same network as Home Assistant. If your site cannot reach Home Assistant, use a push channel with a Home Assistant automation instead.', 'veloxa-solar-widget' ); ?></p>
<?php require __DIR__ . '/form-errors.php'; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="veloxa_solar_save_ha_connection" />
	<?php if ( null !== $editing ) : ?>
		<input type="hidden" name="id" value="<?php echo esc_attr( $editing['id'] ); ?>" />
	<?php endif; ?>
	<?php wp_nonce_field( 'veloxa_solar_save_ha_connection' ); ?>
	<table class="form-table">
		<tr>
			<th><label for="veloxa-solar-ha-name"><?php esc_html_e( 'Name', 'veloxa-solar-widget' ); ?></label></th>
			<td>
				<input type="text" id="veloxa-solar-ha-name" name="name" class="regular-text" value="<?php echo esc_attr( $ha_values['name'] ?? '' ); ?>" required />
				<?php require __DIR__ . '/rename-note.php'; ?>
			</td>
		</tr>
		<tr>
			<th><label for="veloxa-solar-ha-url"><?php esc_html_e( 'Home Assistant address', 'veloxa-solar-widget' ); ?></label></th>
			<td><input type="url" id="veloxa-solar-ha-url" name="url" class="large-text" placeholder="https://example.ui.nabu.casa" value="<?php echo esc_attr( $ha_values['url'] ?? '' ); ?>" required /></td>
		</tr>
		<tr>
			<th><label for="veloxa-solar-ha-token"><?php esc_html_e( 'Long-lived access token', 'veloxa-solar-widget' ); ?></label></th>
			<td>
				<input type="password" id="veloxa-solar-ha-token" name="token" class="large-text" autocomplete="off" <?php echo null === $editing ? 'required' : ''; ?> />
				<?php if ( null !== $editing ) : ?>
					<p class="description"><strong><?php esc_html_e( 'Leave empty to keep the current token. Paste a new one to replace it — the sensor choices are kept.', 'veloxa-solar-widget' ); ?></strong></p>
				<?php endif; ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to Home Assistant's user management docs */
						esc_html__( 'This token inherits the full permissions of the account that created it — Home Assistant has no way to limit it to read-only. We recommend creating a separate, non-administrator account just for this token: %s', 'veloxa-solar-widget' ),
						'<a href="https://www.home-assistant.io/docs/authentication/multi-factor-auth/#managing-users" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Home Assistant user management docs', 'veloxa-solar-widget' ) . '</a>'
					);
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th><label for="veloxa-solar-ha-refresh"><?php esc_html_e( 'Refresh every (minutes)', 'veloxa-solar-widget' ); ?></label></th>
			<td><input type="number" id="veloxa-solar-ha-refresh" name="refresh_minutes" min="<?php echo esc_attr( Abstract_Source::MIN_REFRESH_MINUTES ); ?>" value="<?php echo esc_attr( $ha_values['refresh_minutes'] ?? Abstract_Source::MIN_REFRESH_MINUTES ); ?>" class="small-text" /></td>
		</tr>
	</table>
	<?php submit_button( null === $editing ? __( 'Connect and choose sensors', 'veloxa-solar-widget' ) : __( 'Save changes', 'veloxa-solar-widget' ) ); ?>
</form>
