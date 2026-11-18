<?php
/**
 * Opcja połączenia „zużycie domu zawsze z bilansu” (0.7.12, domyślnie
 * wyłączona). Wspólna dla source-options.php i formularzy połączeń chmur
 * producentów — liczniki domu zwykle pomijają część odbiorów i straty, więc
 * pomiar nie sumuje się w widgecie przepływu energii.
 *
 * @package Veloxa\Solar
 *
 * @var array<string, mixed> $option_values Bieżące wartości połączenia albo formularza.
 * @var string               $option_prefix Prefiks id pól (unikalny na stronie).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- szablon dołączany (require) wewnątrz metody; zmienne lokalne.
?>
<p>
	<label>
		<input type="checkbox" id="<?php echo esc_attr( $option_prefix ); ?>-home-from-balance" name="home_from_balance" value="1" <?php checked( ! empty( $option_values['home_from_balance'] ) ); ?> />
		<?php esc_html_e( 'Always calculate it from the energy balance', 'veloxa-solar-widget' ); ?>
	</label>
</p>
<p class="description">
	<?php esc_html_e( 'Uses solar + grid − battery even when the source measures home consumption, so the energy flow always adds up. Use it when the measured value leaves out some loads and the flow does not balance. Values are marked "≈".', 'veloxa-solar-widget' ); ?>
</p>
