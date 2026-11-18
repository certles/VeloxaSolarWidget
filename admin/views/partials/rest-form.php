<?php
/**
 * Formularz źródła Generic REST — dodanie albo edycja ($editing). Przy
 * edycji URL i nagłówek nie są pokazywane (sekrety); puste = bez zmian.
 *
 * @package Veloxa\Solar
 *
 * @var array<string, mixed>|null                                             $editing     Edytowane połączenie albo null przy dodawaniu.
 * @var array<string, mixed>                                                  $rest_values Wartości do wypełnienia (bez sekretów).
 * @var array{form: string, errors: string[], values: array<string, mixed>} $form_state  Stan ostatniego nieudanego zapisu.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- szablon dołączany (require) wewnątrz metody Connections_Page; zmienne są lokalne dla tej metody, nie globalne.

$form_id   = 'veloxa-solar-rest-form';
$form_note = null === $editing
	? __( 'Enter the URL and auth header again — they are not kept, because they may contain secrets.', 'veloxa-solar-widget' )
	: __( 'Nothing was changed. The URL and auth header left empty keep their current values.', 'veloxa-solar-widget' );
?>
<?php if ( null === $editing ) : ?>
	<h2 id="veloxa-solar-rest-form"><?php esc_html_e( 'Add Generic REST / JSON source', 'veloxa-solar-widget' ); ?></h2>
<?php else : ?>
	<span id="veloxa-solar-rest-form"></span>
<?php endif; ?>
<p class="description"><?php esc_html_e( 'Any cloud API that returns JSON. Point each value at its place in the response with a dot path (e.g. result.acpower) and pick the unit the API uses. Data is fetched in the background, never while a visitor loads a page.', 'veloxa-solar-widget' ); ?></p>
<?php require __DIR__ . '/form-errors.php'; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="veloxa_solar_save_rest_connection" />
	<?php if ( null !== $editing ) : ?>
		<input type="hidden" name="id" value="<?php echo esc_attr( $editing['id'] ); ?>" />
	<?php endif; ?>
	<?php wp_nonce_field( 'veloxa_solar_save_rest_connection' ); ?>
	<table class="form-table">
		<tr>
			<th><label for="veloxa-solar-rest-name"><?php esc_html_e( 'Name', 'veloxa-solar-widget' ); ?></label></th>
			<td><input type="text" id="veloxa-solar-rest-name" name="name" class="regular-text" value="<?php echo esc_attr( $rest_values['name'] ?? '' ); ?>" required />
				<?php require __DIR__ . '/rename-note.php'; ?>
			</td>
		</tr>
		<tr>
			<th><label for="veloxa-solar-rest-url"><?php esc_html_e( 'URL', 'veloxa-solar-widget' ); ?></label></th>
			<td>
				<input type="url" id="veloxa-solar-rest-url" name="url" class="large-text" autocomplete="off" <?php echo null === $editing ? 'required' : ''; ?> />
				<?php if ( null !== $editing ) : ?>
					<p class="description"><strong><?php esc_html_e( 'Leave empty to keep the current URL. Enter a new one to replace it, e.g. with a new API key — the mapping below is kept.', 'veloxa-solar-widget' ); ?></strong></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Stored encrypted — it may contain an API key.', 'veloxa-solar-widget' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="veloxa-solar-rest-token"><?php esc_html_e( 'Auth header (optional)', 'veloxa-solar-widget' ); ?></label></th>
			<td>
				<input type="password" id="veloxa-solar-rest-token" name="token" class="regular-text" autocomplete="off" placeholder="Authorization: Bearer ..." />
				<?php if ( null !== $editing ) : ?>
					<p class="description"><?php esc_html_e( 'Leave empty to keep the current header.', 'veloxa-solar-widget' ); ?></p>
					<?php if ( '' !== (string) ( $editing['token'] ?? '' ) ) : ?>
						<label><input type="checkbox" name="clear_token" value="1" /> <?php esc_html_e( 'Remove the auth header', 'veloxa-solar-widget' ); ?></label>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php foreach ( \Veloxa\Solar\Model\PV_Reading::FIELD_UNITS as $field => $internal_unit ) : ?>
			<?php
			$is_core = in_array( $field, \Veloxa\Solar\Model\PV_Reading::CORE_FIELDS, true );
			$mapping = $rest_values['fields'][ $field ] ?? array();
			?>
			<tr>
				<th>
					<label for="veloxa-solar-rest-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( \Veloxa\Solar\Sources\Abstract_Source::field_label( $field ) ); ?></label>
					<?php if ( ! $is_core ) : ?>
						<br><small><?php esc_html_e( '(optional)', 'veloxa-solar-widget' ); ?></small>
					<?php endif; ?>
				</th>
				<td>
					<input type="text" id="veloxa-solar-rest-<?php echo esc_attr( $field ); ?>" name="fields[<?php echo esc_attr( $field ); ?>][path]" class="regular-text" placeholder="<?php esc_attr_e( 'JSON path', 'veloxa-solar-widget' ); ?>" value="<?php echo esc_attr( $mapping['path'] ?? '' ); ?>" <?php echo $is_core ? 'required' : ''; ?> />
					<select name="fields[<?php echo esc_attr( $field ); ?>][unit]" aria-label="<?php esc_attr_e( 'Unit', 'veloxa-solar-widget' ); ?>">
						<?php foreach ( \Veloxa\Solar\Model\Unit_Converter::units_for( $field ) as $unit ) : ?>
							<option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $mapping['unit'] ?? ( 'W' === $internal_unit ? 'kW' : ( 'Wh' === $internal_unit ? 'kWh' : '%' ) ), $unit ); ?>><?php echo esc_html( $unit ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( in_array( $field, \Veloxa\Solar\Sources\Abstract_Source::SIGNED_FIELDS, true ) ) : ?>
						<label><input type="checkbox" name="fields[<?php echo esc_attr( $field ); ?>][invert]" value="1" <?php checked( ! empty( $mapping['invert'] ) ); ?> /> <?php esc_html_e( 'Invert sign', 'veloxa-solar-widget' ); ?></label>
						<p class="description">
							<?php
							$sign_hints = array(
								'battery_power' => __( 'Expected: positive = charging, negative = discharging. Tick "Invert sign" if your API uses the opposite.', 'veloxa-solar-widget' ),
								'grid_power'    => __( 'Expected: positive = drawing from the grid, negative = exporting. Tick "Invert sign" if your API uses the opposite.', 'veloxa-solar-widget' ),
								'home_power'    => __( 'Expected: positive = power used by the home. Tick "Invert sign" if your API sends it as a negative number.', 'veloxa-solar-widget' ),
							);
							echo esc_html( $sign_hints[ $field ] ?? '' );
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		<tr>
			<th><label for="veloxa-solar-rest-updated"><?php esc_html_e( 'Measurement time (optional)', 'veloxa-solar-widget' ); ?></label></th>
			<td>
				<input type="text" id="veloxa-solar-rest-updated" name="updated_at_path" class="regular-text" placeholder="<?php esc_attr_e( 'JSON path', 'veloxa-solar-widget' ); ?>" value="<?php echo esc_attr( $rest_values['updated_at_path'] ?? '' ); ?>" />
				<p class="description"><?php esc_html_e( 'Unix timestamp or date. Without it, the fetch time is shown and marked as approximate.', 'veloxa-solar-widget' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="veloxa-solar-rest-timezone"><?php esc_html_e( 'Source timezone (optional)', 'veloxa-solar-widget' ); ?></label></th>
			<td>
				<input type="text" id="veloxa-solar-rest-timezone" name="timezone" class="regular-text" placeholder="Europe/Warsaw" value="<?php echo esc_attr( $rest_values['timezone'] ?? '' ); ?>" />
				<p class="description"><?php esc_html_e( 'Only for dates without a UTC offset. Empty = the site timezone.', 'veloxa-solar-widget' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="veloxa-solar-rest-refresh"><?php esc_html_e( 'Refresh every (minutes)', 'veloxa-solar-widget' ); ?></label></th>
			<td><input type="number" id="veloxa-solar-rest-refresh" name="refresh_minutes" min="<?php echo esc_attr( \Veloxa\Solar\Sources\Abstract_Source::MIN_REFRESH_MINUTES ); ?>" value="<?php echo esc_attr( $rest_values['refresh_minutes'] ?? \Veloxa\Solar\Sources\Abstract_Source::MIN_REFRESH_MINUTES ); ?>" class="small-text" /></td>
		</tr>
		<?php
		$option_values = $rest_values;
		$option_prefix = 'veloxa-solar-rest';
		require __DIR__ . '/source-options.php';
		?>
	</table>
	<?php submit_button( null === $editing ? __( 'Add REST source', 'veloxa-solar-widget' ) : __( 'Save changes', 'veloxa-solar-widget' ) ); ?>
</form>
