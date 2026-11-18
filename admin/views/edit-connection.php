<?php
/**
 * Widok: edycja połączenia. Te same formularze co przy dodawaniu, w trybie
 * edycji; sekrety nie są pokazywane (puste pole = bez zmian).
 *
 * @package Veloxa\Solar
 *
 * @var array<string, mixed>                                                  $editing    Edytowane połączenie (sekrety jawne — NIE wypisywać).
 * @var array{form: string, errors: string[], values: array<string, mixed>} $form_state Stan ostatniego nieudanego zapisu.
 */

use Veloxa\Solar\Admin\Connections_Page;
use Veloxa\Solar\Sources\Ha_Source;
use Veloxa\Solar\Sources\Push_Source;
use Veloxa\Solar\Sources\Rest_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- szablon dołączany (require) wewnątrz metody Connections_Page; zmienne są lokalne dla tej metody, nie globalne.

// Wartości formularza: zapisane połączenie bez sekretów, nadpisane tym,
// co admin wpisał przy nieudanym zapisie.
$saved = $editing;
unset( $saved['token'], $saved['api_key'], $saved['auth_key'], $saved['token_hash'], $saved['app_secret'], $saved['password_hash'] );

if ( Rest_Source::CONNECTOR_TYPE === $editing['connector'] ) {
	unset( $saved['url'] );
}

$failed = '' !== $form_state['form'] ? $form_state['values'] : array();
$values = array_merge( $saved, $failed );
?>
<div class="wrap">
	<p><a href="<?php echo esc_url( Connections_Page::page_url() ); ?>">&larr; <?php esc_html_e( 'All connections', 'veloxa-solar-widget' ); ?></a></p>
	<h1>
		<?php
		/* translators: %s: connection name */
		echo esc_html( sprintf( __( 'Edit connection: %s', 'veloxa-solar-widget' ), $editing['name'] ) );
		?>
	</h1>

	<?php
	switch ( $editing['connector'] ) {
		case Push_Source::CONNECTOR_TYPE:
			$push_values = $values;
			require __DIR__ . '/partials/push-form.php';
			?>
			<p>
				<a href="<?php echo esc_url( Connections_Page::page_url( array( 'veloxa_solar_channel' => $editing['id'] ) ) ); ?>"><?php esc_html_e( 'Address, examples and a new token', 'veloxa-solar-widget' ); ?> &rarr;</a>
			</p>
			<?php
			break;

		case Rest_Source::CONNECTOR_TYPE:
			$rest_values = $values;
			require __DIR__ . '/partials/rest-form.php';

			$sample = empty( $editing['beta'] ) ? null : Rest_Source::sample( (string) $editing['id'] );

			if ( null !== $sample ) :
				?>
				<h2><?php esc_html_e( 'Last data received (beta source)', 'veloxa-solar-widget' ); ?></h2>
				<p class="description">
					<?php
					/* translators: %s: date and time */
					echo esc_html( sprintf( __( 'Received %s. Raw values exactly as the source sends them — compare them with the manufacturer\'s app. If a value is wrong (unit, sign, or a different field), copy this table into your support request.', 'veloxa-solar-widget' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $sample['fetched_at'] ) ) );
					?>
				</p>
				<table class="widefat striped" style="max-width:40em;">
					<tbody>
						<?php foreach ( (array) $sample['fields'] as $sample_path => $sample_value ) : ?>
							<tr><th><code><?php echo esc_html( (string) $sample_path ); ?></code></th><td><?php echo esc_html( null === $sample_value ? 'null' : var_export( $sample_value, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- czytelny zapis wartości prostej. ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
			endif;
			break;

		case Ha_Source::CONNECTOR_TYPE:
			$ha_values = $values;
			require __DIR__ . '/partials/ha-form.php';
			?>
			<p>
				<a href="<?php echo esc_url( Connections_Page::page_url( array( 'veloxa_solar_ha' => $editing['id'] ) ) ); ?>"><?php esc_html_e( 'Choose sensors', 'veloxa-solar-widget' ); ?> &rarr;</a>
			</p>
			<?php
			break;

		default:
			if ( has_action( 'veloxa_solar_edit_connection_form_' . $editing['connector'] ) ) {
				/**
				 * Formularz edycji połączenia typu dodanego przez inny moduł.
				 *
				 * @param array<string, mixed> $editing    Połączenie (sekrety jawne — NIE wypisywać).
				 * @param array<string, mixed> $values     Wartości formularza bez sekretów.
				 * @param array                $form_state Stan ostatniego nieudanego zapisu.
				 */
				do_action( 'veloxa_solar_edit_connection_form_' . $editing['connector'], $editing, $values, $form_state );
				break;
			}

			echo '<p>' . esc_html__( 'This connection type cannot be edited.', 'veloxa-solar-widget' ) . '</p>';
	}
	?>
</div>
