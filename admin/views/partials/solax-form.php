<?php
/**
 * Formularz „SolaX Cloud (beta)” — tworzy zwykłe źródło Generic REST
 * z gotowym mapowaniem (Rest_Source::solax_config()). Potem edytowalne
 * jak każde źródło REST.
 *
 * @package Veloxa\Solar
 *
 * @var array<string, mixed>                                                  $solax_values Wartości do wypełnienia (bez tokenu).
 * @var array{form: string, errors: string[], values: array<string, mixed>} $form_state   Stan ostatniego nieudanego zapisu.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- szablon dołączany (require) wewnątrz metody Connections_Page; zmienne są lokalne dla tej metody, nie globalne.

$form_id   = 'veloxa-solar-solax-form';
$form_note = __( 'Enter the token ID again — it is not kept after a failed attempt.', 'veloxa-solar-widget' );
?>
<h2 id="veloxa-solar-solax-form"><?php esc_html_e( 'Add SolaX Cloud', 'veloxa-solar-widget' ); ?> <span class="veloxa-solar-beta" style="display:inline-block;padding:0 6px;border-radius:3px;background:#f0c33c;color:#1d2327;font-size:11px;font-weight:600;line-height:18px;vertical-align:middle;"><?php esc_html_e( 'beta', 'veloxa-solar-widget' ); ?></span></h2>
<p class="description"><?php esc_html_e( 'For SolaX inverters monitored in SolaxCloud. Your token ID is in SolaxCloud under Service → API (free for every user); the registration number is printed on the Wi-Fi/LAN dongle and shown in SolaxCloud next to the inverter. Beta: set up from the SolaX documentation and not yet confirmed on a real installation — compare the values with the SolaX app and, if something differs, send us the diagnostic report.', 'veloxa-solar-widget' ); ?></p>
<?php require __DIR__ . '/form-errors.php'; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="veloxa_solar_save_solax_connection" />
	<?php wp_nonce_field( 'veloxa_solar_save_solax_connection' ); ?>
	<table class="form-table">
		<tr>
			<th><label for="veloxa-solar-solax-name"><?php esc_html_e( 'Name', 'veloxa-solar-widget' ); ?></label></th>
			<td><input type="text" id="veloxa-solar-solax-name" name="name" class="regular-text" value="<?php echo esc_attr( $solax_values['name'] ?? '' ); ?>" required /></td>
		</tr>
		<tr>
			<th><label for="veloxa-solar-solax-token"><?php esc_html_e( 'Token ID', 'veloxa-solar-widget' ); ?></label></th>
			<td><input type="password" id="veloxa-solar-solax-token" name="solax_token" class="regular-text" autocomplete="off" required /></td>
		</tr>
		<tr>
			<th><label for="veloxa-solar-solax-sn"><?php esc_html_e( 'Registration number (SN)', 'veloxa-solar-widget' ); ?></label></th>
			<td><input type="text" id="veloxa-solar-solax-sn" name="solax_sn" class="regular-text" value="<?php echo esc_attr( $solax_values['solax_sn'] ?? '' ); ?>" required /></td>
		</tr>
	</table>
	<?php submit_button( __( 'Connect to SolaX Cloud', 'veloxa-solar-widget' ) ); ?>
</form>
