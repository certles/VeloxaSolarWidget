<?php
/**
 * Widok: ekran kanału push — adres, token, gotowe przykłady i "Sprawdź,
 * czy dane docierają" (kryterium akceptacji Fazy 3). Wzorowane na
 * step-push-3-snippet.php + push-check-receiving.js z IoT Data Widget.
 *
 * @package Veloxa\Solar
 *
 * @var array<string, mixed>             $connection Połączenie (kanał push).
 * @var \Veloxa\Solar\Sources\Push_Source $source  Źródło.
 * @var array<int, array<string, mixed>> $entities   Encje kanału z ostatnimi wartościami.
 */

use Veloxa\Solar\Admin\Connections_Page;
use Veloxa\Solar\Model\PV_Reading;
use Veloxa\Solar\Model\Reading_Store;
use Veloxa\Solar\Sources\Abstract_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- szablon dołączany (require) wewnątrz metody Connections_Page; zmienne są lokalne dla tej metody, nie globalne.

$new_token = get_transient( 'veloxa_solar_new_token_' . get_current_user_id() );

if ( false !== $new_token ) {
	delete_transient( 'veloxa_solar_new_token_' . get_current_user_id() );
}

$token_text = false !== $new_token ? (string) $new_token : 'YOUR_TOKEN';
$ingest_url = rest_url( 'veloxa-solar-widget/v1/ingest' );
$payload    = $source->example_payload();
$reading    = Reading_Store::get( $connection['id'] );
$waiting    = Reading_Store::get_last_error( $connection['id'] );

$curl = sprintf(
	"curl -X POST '%s' \\\n  -H 'X-Solar-Token: %s' \\\n  -H 'Content-Type: application/json' \\\n  -d '%s'",
	$ingest_url,
	$token_text,
	wp_json_encode( $payload )
);

$ha_readings = array();

foreach ( $payload['readings'] as $example ) {
	$ha_readings[] = sprintf( '{"entity": "%1$s", "value": {{ states(\'sensor.YOUR_%2$s_SENSOR\') }}, "unit": "%3$s"}', $example['entity'], strtoupper( $example['entity'] ), $example['unit'] );
}

$ha_yaml = sprintf(
	"rest_command:\n  solar_push:\n    url: \"%s\"\n    method: POST\n    headers:\n      X-Solar-Token: \"%s\"\n    content_type: \"application/json\"\n    payload: >\n      {\"readings\": [%s]}",
	$ingest_url,
	$token_text,
	implode( ', ', $ha_readings )
);
?>
<div class="wrap">
	<p>
		<a href="<?php echo esc_url( Connections_Page::page_url() ); ?>">&larr; <?php esc_html_e( 'All connections', 'veloxa-solar-widget' ); ?></a> |
		<a href="<?php echo esc_url( Connections_Page::page_url( array( 'veloxa_solar_edit' => $connection['id'] ) ) ); ?>"><?php esc_html_e( 'Edit channel', 'veloxa-solar-widget' ); ?></a>
	</p>
	<h1>
		<?php
		/* translators: %s: channel name */
		echo esc_html( sprintf( __( 'Push channel: %s', 'veloxa-solar-widget' ), $connection['name'] ) );
		?>
	</h1>

	<?php if ( false !== $new_token ) : ?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Copy this token now — it will not be shown again:', 'veloxa-solar-widget' ); ?></strong><br>
				<code><?php echo esc_html( $new_token ); ?></code>
			</p>
			<p><?php esc_html_e( 'It is already filled in the examples below.', 'veloxa-solar-widget' ); ?></p>
		</div>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'The token is shown only once, when it is created. The examples use YOUR_TOKEN as a placeholder — replace it with your token. Lost it? Generate a new one at the bottom of this page.', 'veloxa-solar-widget' ); ?></p>
	<?php endif; ?>

	<h2><?php esc_html_e( '1. Where to send', 'veloxa-solar-widget' ); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e( 'Address (POST)', 'veloxa-solar-widget' ); ?></th>
			<td><code id="veloxa-solar-ingest-url"><?php echo esc_html( $ingest_url ); ?></code></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Token header', 'veloxa-solar-widget' ); ?></th>
			<td><code>X-Solar-Token</code> <span class="description"><?php esc_html_e( '(or Authorization: Bearer …)', 'veloxa-solar-widget' ); ?></span></td>
		</tr>
	</table>

	<h2><?php esc_html_e( '2. What to send', 'veloxa-solar-widget' ); ?></h2>
	<p><?php esc_html_e( 'Entity names must be exactly as below. You can send all values in one request or each separately. A "unit" in the request overrides the unit chosen for the channel. Accepted units: power W, kW, MW (also w, kw, KW); energy Wh, kWh, MWh (also wh, kwh, KWH); battery %. A request with any other unit is rejected with an error and nothing from it is stored.', 'veloxa-solar-widget' ); ?></p>
	<p><strong><?php esc_html_e( 'Test from a terminal:', 'veloxa-solar-widget' ); ?></strong></p>
	<textarea class="large-text code" rows="5" readonly id="veloxa-solar-curl"><?php echo esc_textarea( $curl ); ?></textarea>
	<p><strong><?php esc_html_e( 'Home Assistant (configuration.yaml) — then call the "rest_command.solar_push" action from an automation, e.g. every 5 minutes:', 'veloxa-solar-widget' ); ?></strong></p>
	<textarea class="large-text code" rows="10" readonly><?php echo esc_textarea( $ha_yaml ); ?></textarea>

	<h2><?php esc_html_e( '3. Check that the data arrives', 'veloxa-solar-widget' ); ?></h2>
	<p>
		<button type="button" class="button button-primary" id="veloxa-solar-push-check"><?php esc_html_e( 'Check if data arrives', 'veloxa-solar-widget' ); ?></button>
		<span id="veloxa-solar-push-check-result" style="margin-left:8px;" aria-live="polite"></span>
	</p>
	<table class="wp-list-table widefat striped" style="max-width:720px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Entity', 'veloxa-solar-widget' ); ?></th>
				<th><?php esc_html_e( 'Last value', 'veloxa-solar-widget' ); ?></th>
				<th><?php esc_html_e( 'Received (UTC)', 'veloxa-solar-widget' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $entities as $entity ) : ?>
				<tr data-entity-row="<?php echo esc_attr( $entity['entity_key'] ); ?>">
					<td><code><?php echo esc_html( $entity['entity_key'] ); ?></code></td>
					<td data-entity-value>
						<?php
						echo null === $entity['last_recorded_at']
							? esc_html__( '— no data yet —', 'veloxa-solar-widget' )
							: esc_html( ( $entity['last_value_txt'] ?? $entity['last_value_num'] ) . ( $entity['unit'] ? ' ' . $entity['unit'] : '' ) );
						?>
					</td>
					<td data-entity-time><?php echo esc_html( $entity['last_recorded_at'] ?? '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'What widgets will show', 'veloxa-solar-widget' ); ?></h3>
	<div id="veloxa-solar-push-reading">
		<?php if ( null !== $reading ) : ?>
			<?php foreach ( $reading->fields() as $field ) : ?>
				<?php echo esc_html( Abstract_Source::field_label( $field ) ); ?>: <strong><?php echo esc_html( \Veloxa\Solar\Widgets\Formatter::field( $field, (float) $reading->get( $field ) ) ); ?></strong><br>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php if ( null !== $waiting ) : ?>
			<p class="description"><?php echo esc_html( $waiting ); ?></p>
		<?php elseif ( null === $reading ) : ?>
			<p class="description"><?php esc_html_e( 'Nothing yet — widgets start showing data once power, energy today and total energy have all arrived.', 'veloxa-solar-widget' ); ?></p>
		<?php endif; ?>
	</div>

	<h2><?php esc_html_e( '4. New token', 'veloxa-solar-widget' ); ?></h2>
	<p><?php esc_html_e( 'Generate a new token if the old one was lost or may have leaked. The old token stops working immediately — update your device or automation right after. Values, history and widgets are kept.', 'veloxa-solar-widget' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="veloxa_solar_rotate_push_token" />
		<input type="hidden" name="id" value="<?php echo esc_attr( $connection['id'] ); ?>" />
		<?php wp_nonce_field( 'veloxa_solar_rotate_push_token' ); ?>
		<?php submit_button( __( 'Generate a new token', 'veloxa-solar-widget' ), 'secondary', 'submit', false ); ?>
	</form>
</div>
