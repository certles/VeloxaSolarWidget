<?php
/**
 * Kontrole wiarygodności odczytów źródeł „beta” (2026-10-11, decyzja
 * właściciela: integracje z dokumentacji jako beta, poprawiane na żywych
 * instalacjach). Wynik trafia do logu diagnostyki (lokalnie — nic nie jest
 * wysyłane), każdy problem raz na dobę na połączenie; z raportu
 * diagnostycznego przysłanego przez użytkownika poprawiamy mapowanie.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Model;

use Veloxa\Solar\Core\Diagnostics_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sanity_Checks
 */
final class Sanity_Checks {

	/**
	 * Spadek energii dziś w ciągu dnia uznajemy za błąd, gdy większy niż tyle
	 * Wh (zaokrąglenia liczników bywają rzędu 0,1 kWh).
	 */
	const DROP_TOLERANCE_WH = 150;

	/**
	 * Problemy odczytu (puste = w porządku).
	 *
	 * @param PV_Reading      $reading  Nowy odczyt.
	 * @param PV_Reading|null $previous Poprzedni odczyt tego połączenia.
	 * @return array<string, string> Kod => opis.
	 */
	public static function problems( PV_Reading $reading, ?PV_Reading $previous ): array {
		$problems = array();
		$power    = $reading->get( 'current_power' );
		$today    = $reading->get( 'energy_today' );
		$total    = $reading->get( 'energy_total' );
		$soc      = $reading->get( 'battery_soc' );

		if ( null !== $today && null !== $total && $today > $total + 1 ) {
			$problems['today_above_total'] = __( 'Energy today is larger than the total energy — the unit of one of them is probably wrong.', 'veloxa-solar-widget' );
		}

		if ( null !== $power && $power < -1 ) {
			$problems['negative_power'] = __( 'Solar power is negative — the value probably has the opposite sign.', 'veloxa-solar-widget' );
		}

		if ( null !== $soc && ( $soc < 0 || $soc > 100 ) ) {
			$problems['soc_range'] = __( 'Battery charge is outside 0–100 % — the unit or the field is probably wrong.', 'veloxa-solar-widget' );
		}

		if ( null !== $previous && null !== $today ) {
			$before = $previous->get( 'energy_today' );

			if ( null !== $before
				&& wp_date( 'Y-m-d', $previous->updated_at() ) === wp_date( 'Y-m-d', $reading->updated_at() )
				&& $before - $today > self::DROP_TOLERANCE_WH ) {
				$problems['today_dropped'] = __( 'Energy today went down during the day — the field may not be a daily counter.', 'veloxa-solar-widget' );
			}
		}

		return $problems;
	}

	/**
	 * Sprawdza odczyt źródła beta i zapisuje nowe problemy w logu
	 * diagnostyki (każdy kod raz na dobę).
	 *
	 * @param string          $connection_id ID połączenia.
	 * @param PV_Reading      $reading       Nowy odczyt.
	 * @param PV_Reading|null $previous      Poprzedni odczyt.
	 * @return array<string, string> Zapisane problemy.
	 */
	public static function check_and_log( string $connection_id, PV_Reading $reading, ?PV_Reading $previous ): array {
		$logged = array();

		foreach ( self::problems( $reading, $previous ) as $code => $message ) {
			$key = 'veloxa_solar_beta_' . substr( md5( $connection_id . '|' . $code ), 0, 16 );

			if ( false !== get_transient( $key ) ) {
				continue;
			}

			set_transient( $key, 1, DAY_IN_SECONDS );
			Diagnostics_Logger::log_error( 'beta-check:' . $connection_id, $message );
			$logged[ $code ] = $message;
		}

		return $logged;
	}
}
