<?php
/**
 * Formularz kanału push — dodanie albo edycja ($editing).
 *
 * @package Veloxa\Solar
 *
 * @var array<string, mixed>|null                                             $editing     Edytowane połączenie albo null przy dodawaniu.
 * @var array<string, mixed>                                                  $push_values Wartości do wypełnienia.
 * @var array{form: string, errors: string[], values: array<string, mixed>} $form_state  Stan ostatniego nieudanego zapisu.
 */

use Veloxa\Solar\Model\PV_Reading;
use Veloxa\Solar\Model\Unit_Converter;
use Veloxa\Solar\Sources\Abstract_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- szablon dołączany (require) wewnątrz metody Connections_Page; zmienne są lokalne dla tej metody, nie globalne.

$form_id   = 'veloxa-solar-push-form';
$form_note = '';
?>
<?php if ( null === $editing ) : ?>
	<h2 id="veloxa-solar-push-form"><?php esc_html_e( 'Add push channel', 'veloxa-solar-widget' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Your device, Home Assistant automation or script sends readings to this site. Choose what it will send and in which units — the next screen shows the address, the token and a ready-to-use example.', 'veloxa-solar-widget' ); ?></p>
<?php endif; ?>
<p class="description"><?php esc_html_e( 'Tick the optional values (battery, grid, home) that the device sends — values that are not ticked are not accepted.', 'veloxa-solar-widget' ); ?></p>
<?php if ( null !== $editing ) : ?>
	<p id="veloxa-solar-push-form" class="description"><?php esc_html_e( 'The token and the address stay the same. Values that are no longer selected stop being accepted from the device.', 'veloxa-solar-widget' ); ?></p>
<?php endif; ?>
<?php require __DIR__ . '/form-errors.php'; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="veloxa_solar_save_connection" />
	<?php if ( null !== $editing ) : ?>
		<input type="hidden" name="id" value="<?php echo esc_attr( $editing['id'] ); ?>" />
	<?php endif; ?>
	<?php wp_nonce_field( 'veloxa_solar_save_connection' ); ?>
	<table class="form-table">
		<tr>
			<th><label for="veloxa-solar-push-name"><?php esc_html_e( 'Name', 'veloxa-solar-widget' ); ?></label></th>
			<td>
				<input type="text" id="veloxa-solar-push-name" name="name" class="regular-text" value="<?php echo esc_attr( $push_values['name'] ?? '' ); ?>" required />
				<?php require __DIR__ . '/rename-note.php'; ?>
			</td>
		</tr>
		<?php foreach ( PV_Reading::FIELD_UNITS as $field => $internal_unit ) : ?>
			<?php
			$is_core = in_array( $field, PV_Reading::CORE_FIELDS, true );
			$mapping = $push_values['fields'][ $field ] ?? null;
			?>
			<tr>
				<th>
					<?php if ( $is_core ) : ?>
						<?php echo esc_html( Abstract_Source::field_label( $field ) ); ?>
					<?php else : ?>
						<label><input type="checkbox" name="fields[<?php echo esc_attr( $field ); ?>][enabled]" value="1" <?php checked( null !== $mapping ); ?> /> <?php echo esc_html( Abstract_Source::field_label( $field ) ); ?></label>
					<?php endif; ?>
				</th>
				<td>
					<code><?php echo esc_html( $field ); ?></code>
					<select name="fields[<?php echo esc_attr( $field ); ?>][unit]" aria-label="<?php esc_attr_e( 'Unit', 'veloxa-solar-widget' ); ?>">
						<?php foreach ( Unit_Converter::units_for( $field ) as $unit ) : ?>
							<option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $mapping['unit'] ?? ( 'W' === $internal_unit ? 'kW' : ( 'Wh' === $internal_unit ? 'kWh' : '%' ) ), $unit ); ?>><?php echo esc_html( $unit ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( in_array( $field, Abstract_Source::SIGNED_FIELDS, true ) ) : ?>
						<label><input type="checkbox" name="fields[<?php echo esc_attr( $field ); ?>][invert]" value="1" <?php checked( ! empty( $mapping['invert'] ) ); ?> /> <?php esc_html_e( 'Invert sign', 'veloxa-solar-widget' ); ?></label>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		<tr>
			<th><label for="veloxa-solar-push-interval"><?php esc_html_e( 'Device sends every (minutes)', 'veloxa-solar-widget' ); ?></label></th>
			<td>
				<input type="number" id="veloxa-solar-push-interval" name="refresh_minutes" min="<?php echo esc_attr( Abstract_Source::MIN_REFRESH_MINUTES ); ?>" value="<?php echo esc_attr( $push_values['refresh_minutes'] ?? Abstract_Source::MIN_REFRESH_MINUTES ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'Widgets mark data as outdated after 3× this time without new readings.', 'veloxa-solar-widget' ); ?></p>
			</td>
		</tr>
		<?php
		$option_values = $push_values;
		$option_prefix = 'veloxa-solar-push';
		require __DIR__ . '/source-options.php';
		?>
	</table>
	<?php submit_button( null === $editing ? __( 'Add push channel', 'veloxa-solar-widget' ) : __( 'Save changes', 'veloxa-solar-widget' ) ); ?>
</form>
