<?php
/**
 * Opcje wspólne dla źródeł (push, Generic REST, Home Assistant):
 * szacunek zużycia domu (tryb awaryjny, domyślnie wyłączony) i próg
 * bezczynności baterii — docs/etap-1.md, 1.5 i 1.7.
 *
 * @package Veloxa\Solar
 *
 * @var array<string, mixed> $option_values Bieżące wartości połączenia albo formularza.
 * @var string               $option_prefix Prefiks id pól (unikalny na stronie).
 */

use Veloxa\Solar\Model\PV_Reading;
use Veloxa\Solar\Sources\Abstract_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- szablon dołączany (require) wewnątrz metody; zmienne lokalne.
?>
<tr>
	<th><?php esc_html_e( 'Home consumption', 'veloxa-solar-widget' ); ?></th>
	<td>
		<label>
			<input type="checkbox" id="<?php echo esc_attr( $option_prefix ); ?>-estimate-home" name="estimate_home_power" value="1" <?php checked( ! empty( $option_values['estimate_home_power'] ) ); ?> />
			<?php esc_html_e( 'Estimate it when the source does not report it', 'veloxa-solar-widget' ); ?>
		</label>
		<p class="description">
			<?php
			/* translators: %s: formula */
			echo esc_html( sprintf( __( 'Fallback only, off by default: %s (grid positive when taking power from the grid, battery positive when charging). It ignores inverter losses, so it reads higher than a measured value. Estimated values are marked "≈".', 'veloxa-solar-widget' ), 'solar + grid − battery' ) );
			?>
		</p>
		<?php require __DIR__ . '/home-from-balance-option.php'; ?>
	</td>
</tr>
<tr>
	<th><label for="<?php echo esc_attr( $option_prefix ); ?>-idle"><?php esc_html_e( 'Idle below (W)', 'veloxa-solar-widget' ); ?></label></th>
	<td>
		<input type="number" id="<?php echo esc_attr( $option_prefix ); ?>-idle" name="battery_idle_watts" min="0" step="1" class="small-text" value="<?php echo esc_attr( $option_values['battery_idle_watts'] ?? Abstract_Source::DEFAULT_BATTERY_IDLE_WATTS ); ?>" />
		<p class="description"><?php esc_html_e( 'Battery or grid power below this value (either direction) counts as no flow — the battery is shown as "Idle".', 'veloxa-solar-widget' ); ?></p>
	</td>
</tr>
<?php
/**
 * Dodatkowe opcje połączenia-źródła (np. układ ekranu pełnoekranowego
 * z includes/premium/). Zapis: filtr veloxa_solar_source_options_from_post.
 *
 * @param array<string, mixed> $option_values Bieżące wartości.
 * @param string               $option_prefix Prefiks id pól.
 */
do_action( 'veloxa_solar_source_options_fields', $option_values, $option_prefix );
