<?php
/**
 * Widok: krok 2 kreatora Home Assistant — wybór czujników dla pól PV.
 * Listy zawierają WYŁĄCZNIE czujniki pasujące do pola po device_class i
 * jednostce (brief 3.3); pole bez kandydatów nie jest oferowane (brief:
 * UI nie sugeruje danych, których źródło nie dostarcza).
 *
 * @package Veloxa\Solar
 *
 * @var array<string, mixed>                                                 $connection Połączenie HA.
 * @var array<string, array<int, array<string, string>>>|\WP_Error          $candidates Kandydaci per pole albo błąd.
 * @var array{form: string, errors: string[], values: array<string, mixed>} $form_state Stan ostatniego nieudanego zapisu.
 */

use Veloxa\Solar\Admin\Connections_Page;
use Veloxa\Solar\Model\PV_Reading;
use Veloxa\Solar\Sources\Abstract_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- szablon dołączany (require) wewnątrz metody Connections_Page; zmienne są lokalne dla tej metody, nie globalne.

$current   = 'veloxa-solar-ha-mapping' === $form_state['form'] ? (array) $form_state['values']['fields'] : (array) ( $connection['fields'] ?? array() );
$form_id   = 'veloxa-solar-ha-mapping';
$form_note = '';

// Podpowiedź z presetu tylko przy pierwszym wyborze — nigdy nie nadpisuje
// mapowania zapisanego albo właśnie odrzuconego przez walidację.
$suggested = null;

if ( empty( $current ) && ! is_wp_error( $candidates ) ) {
	$suggested = \Veloxa\Solar\Sources\Ha_Source::suggest_mapping( $candidates ) ?? \Veloxa\Solar\Sources\Ha_Source::suggest_by_name( $candidates );

	foreach ( (array) ( $suggested['fields'] ?? array() ) as $suggested_field => $suggested_entity ) {
		$current[ $suggested_field ] = array( 'entity' => $suggested_entity );
	}
}
?>
<div class="wrap">
	<p><a href="<?php echo esc_url( Connections_Page::page_url() ); ?>">&larr; <?php esc_html_e( 'All connections', 'veloxa-solar-widget' ); ?></a></p>
	<h1 id="veloxa-solar-ha-mapping">
		<?php
		/* translators: %s: connection name */
		echo esc_html( sprintf( __( 'Home Assistant: %s — choose sensors', 'veloxa-solar-widget' ), $connection['name'] ) );
		?>
	</h1>

	<?php require __DIR__ . '/partials/form-errors.php'; ?>

	<?php if ( is_wp_error( $candidates ) ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $candidates->get_error_message() ); ?></p></div>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'Only sensors that match each value by type and unit are listed. The unit is taken from Home Assistant, so there is nothing to convert by hand.', 'veloxa-solar-widget' ); ?></p>
		<?php if ( null !== $suggested ) : ?>
			<div class="notice notice-info inline"><p>
				<?php if ( ! empty( $suggested['beta'] ) ) : ?>
					<?php esc_html_e( 'Beta: sensors were pre-selected by their names (Huawei Solar, Fronius, GoodWe, SMA, Enphase, SolarEdge and similar integrations). Check each one, and the signs of grid and battery power — the energy flow widget warns you if they do not add up. Nothing is saved until you do.', 'veloxa-solar-widget' ); ?>
				<?php else : ?>
					<?php
					/* translators: %s: integration name, e.g. "Solarman / Sofar" */
					echo esc_html( sprintf( __( 'Sensors of a %s inverter were recognised and pre-selected. Check them and save — nothing is saved until you do.', 'veloxa-solar-widget' ), $suggested['preset'] ) );
					?>
				<?php endif; ?>
			</p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="veloxa_solar_save_ha_mapping" />
			<input type="hidden" name="id" value="<?php echo esc_attr( $connection['id'] ); ?>" />
			<?php wp_nonce_field( 'veloxa_solar_save_ha_mapping' ); ?>
			<table class="form-table">
				<?php foreach ( array_keys( PV_Reading::FIELD_UNITS ) as $field ) : ?>
					<?php
					$is_core = in_array( $field, PV_Reading::CORE_FIELDS, true );
					$options = $candidates[ $field ] ?? array();

					if ( ! $is_core && empty( $options ) ) {
						continue;
					}
					?>
					<tr>
						<th>
							<label for="veloxa-solar-ha-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( Abstract_Source::field_label( $field ) ); ?></label>
							<?php if ( ! $is_core ) : ?>
								<br><small><?php esc_html_e( '(optional)', 'veloxa-solar-widget' ); ?></small>
							<?php endif; ?>
						</th>
						<td>
							<?php if ( empty( $options ) ) : ?>
								<p class="description" style="color:#b32d2e;"><?php esc_html_e( 'No matching sensor found in Home Assistant. This value is required.', 'veloxa-solar-widget' ); ?></p>
							<?php else : ?>
								<select id="veloxa-solar-ha-<?php echo esc_attr( $field ); ?>" name="fields[<?php echo esc_attr( $field ); ?>][entity]">
									<option value=""><?php echo $is_core ? esc_html__( '— choose —', 'veloxa-solar-widget' ) : esc_html__( '— not used —', 'veloxa-solar-widget' ); ?></option>
									<?php foreach ( $options as $option ) : ?>
										<option value="<?php echo esc_attr( $option['id'] ); ?>" <?php selected( (string) ( $current[ $field ]['entity'] ?? '' ), $option['id'] ); ?>>
											<?php echo esc_html( sprintf( '%1$s (%2$s) — %3$s %4$s', $option['label'], $option['id'], $option['state'], $option['unit'] ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ( in_array( $field, Abstract_Source::SIGNED_FIELDS, true ) ) : ?>
									<label><input type="checkbox" name="fields[<?php echo esc_attr( $field ); ?>][invert]" value="1" <?php checked( ! empty( $current[ $field ]['invert'] ) ); ?> /> <?php esc_html_e( 'Invert sign', 'veloxa-solar-widget' ); ?></label>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php
				$option_values = 'veloxa-solar-ha-mapping' === $form_state['form'] ? (array) $form_state['values'] : (array) $connection;
				$option_prefix = 'veloxa-solar-ha';
				require __DIR__ . '/partials/source-options.php';
				?>
			</table>
			<?php submit_button( __( 'Save sensors', 'veloxa-solar-widget' ) ); ?>
		</form>
	<?php endif; ?>
</div>
