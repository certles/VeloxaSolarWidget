<?php
/**
 * Uzupełnianie danych z historii Home Assistanta (2026-10-10, wersja
 * darmowa — decyzja właściciela: kompletność danych, nie dodatkowa funkcja).
 *
 * Ten sam problem co w Solarmanie (History_Backfill w Pro): przy małym
 * ruchu WP-Cron chodzi rzadko, więc dni bez odczytu są lukami w historii,
 * a wykres dnia i moc szczytowa mają dziury. HA przechowuje historię stanów
 * (recorder, domyślnie 10 dni) — braki uzupełniamy z niej:
 *
 * 1. Sumy dzienne: raz na dobę, jedno zapytanie o licznik energii (dziś,
 *    a bez niego — całkowity) za dni bez sumy z historii (okno
 *    DAILY_WINDOW_DAYS). Suma dnia z przebiegu licznika tak samo jak
 *    z odczytów (Daily_Store::from_counter — wyzerowania licznika rozpoznane).
 * 2. Moc co 5 minut: dla minionych dni tygodnia z rzadkimi odczytami i dla
 *    dzisiejszej przerwy dłuższej niż 3 interwały — wiersze historii
 *    (wykres dnia) i moc szczytowa (z każdej zmiany stanu, nie tylko
 *    z punktów co 5 minut).
 *
 * HA jest instancją użytkownika (bez limitów zapytań jak w chmurach) —
 * mimo to każdy dzień pobierany raz, a po błędzie ponowna próba najwcześniej
 * po godzinie. Błąd uzupełniania nigdy nie psuje odczytu bieżącego.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Sources;

use Veloxa\Solar\Model\Daily_Store;
use Veloxa\Solar\Model\History_Gaps;
use Veloxa\Solar\Model\History_Store;
use Veloxa\Solar\Model\PV_Reading;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ha_History_Backfill
 */
final class Ha_History_Backfill {

	/**
	 * Najstarszy uzupełniany dzień (recorder HA trzyma domyślnie 10 dni).
	 */
	const DAILY_WINDOW_DAYS = 9;

	/**
	 * Dni wstecz dla mocy co 5 minut (History_Store::RETENTION_DAYS).
	 */
	const FRAMES_WINDOW_DAYS = 7;

	/**
	 * Dzień ma „rzadkie odczyty”, gdy ma mniej niż ten ułamek oczekiwanych.
	 */
	const SPARSE_RATIO = 0.5;

	/**
	 * Odstęp punktów mocy wstawianych do historii (s).
	 */
	const STEP_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * Prefiks transientów.
	 */
	const PREFIX = 'veloxa_solar_ha_';

	/**
	 * Uzupełnianie po udanym odczycie bieżącym.
	 *
	 * @param string    $connection_id    ID połączenia.
	 * @param Ha_Source $source           Źródło.
	 * @param int       $refresh_interval Interwał (s).
	 * @param int|null  $previous_at      Czas poprzedniego odczytu (przed bieżącym).
	 * @param int       $now              Bieżący czas (unix UTC).
	 */
	public static function after_reading( string $connection_id, Ha_Source $source, int $refresh_interval, ?int $previous_at, int $now ): void {
		self::daily_totals( $connection_id, $source, $now );

		foreach ( History_Gaps::sparse_days( $connection_id, $refresh_interval, $now, self::FRAMES_WINDOW_DAYS, self::SPARSE_RATIO ) as $day ) {
			$done  = self::key( 'power', $connection_id, $day );
			$retry = self::key( 'power_retry', $connection_id, $day );

			if ( History_Gaps::is_set( $done ) || History_Gaps::is_set( $retry ) ) {
				continue;
			}

			list( $start, $end ) = Daily_Store::day_bounds( $day );

			if ( self::import_power( $connection_id, $source, $day, $start, $end, $now ) ) {
				History_Gaps::set( $done, ( self::FRAMES_WINDOW_DAYS + 1 ) * DAY_IN_SECONDS );
			} else {
				History_Gaps::set( $retry, History_Gaps::RETRY_SECONDS );
			}
		}

		$today     = wp_date( 'Y-m-d', $now );
		$gap_start = History_Gaps::today_gap_start( $previous_at, $refresh_interval, $now );
		$lock      = self::key( 'today', $connection_id, $today );

		if ( null !== $gap_start && ! History_Gaps::is_set( $lock ) ) {
			History_Gaps::set( $lock, History_Gaps::RETRY_SECONDS );
			self::import_power( $connection_id, $source, $today, $gap_start, $now, $now );
		}
	}

	/**
	 * Sumy dzienne z licznika w historii HA: raz na dobę, dni bez sumy.
	 *
	 * @param string    $connection_id ID połączenia.
	 * @param Ha_Source $source        Źródło.
	 * @param int       $now           Bieżący czas.
	 */
	private static function daily_totals( string $connection_id, Ha_Source $source, int $now ): void {
		$today = wp_date( 'Y-m-d', $now );
		$done  = self::key( 'daily', $connection_id, $today );
		$retry = self::key( 'daily_retry', $connection_id, $today );

		if ( History_Gaps::is_set( $done ) || History_Gaps::is_set( $retry ) ) {
			return;
		}

		$missing = History_Gaps::days_without_history( $connection_id, $now, self::DAILY_WINDOW_DAYS );
		$mapped  = $source->mapped_fields();
		$field   = ! empty( $mapped['energy_today']['entity'] ) ? 'energy_today' : ( ! empty( $mapped['energy_total']['entity'] ) ? 'energy_total' : null );

		if ( empty( $missing ) || null === $field ) {
			History_Gaps::set( $done, DAY_IN_SECONDS );
			return;
		}

		list( $from ) = Daily_Store::day_bounds( $missing[0] );
		list( $to )   = Daily_Store::day_bounds( $today );

		$history = $source->history( array( $field ), $from, $to );

		if ( is_wp_error( $history ) ) {
			History_Gaps::set( $retry, History_Gaps::RETRY_SECONDS );
			return;
		}

		foreach ( $missing as $day ) {
			$energy = self::day_energy( $history[ $field ] ?? array(), $field, $day );

			if ( null !== $energy ) {
				Daily_Store::put_history_day( $connection_id, $day, (int) round( $energy ), $now );
			}
		}

		History_Gaps::set( $done, DAY_IN_SECONDS );
	}

	/**
	 * Energia dnia z przebiegu licznika. Null, gdy w dniu nie ma punktów
	 * albo (licznik całkowity) brak stanu sprzed dnia.
	 *
	 * @param array<int, array{0: int, 1: float}> $points Punkty licznika, rosnąco.
	 * @param string                              $field  energy_today albo energy_total.
	 * @param string                              $day    Y-m-d.
	 * @return float|null Wh.
	 */
	public static function day_energy( array $points, string $field, string $day ): ?float {
		list( $start, $end ) = Daily_Store::day_bounds( $day );

		$previous = null;
		$values   = array();

		// Stan z chwilą równą początkowi dnia to stan obowiązujący o północy
		// (HA zwraca tak stan z początku okresu) — punkt odniesienia, nie
		// pierwsza wartość dnia.
		foreach ( $points as $point ) {
			if ( $point[0] <= $start ) {
				$previous = $point[1];
			} elseif ( $point[0] < $end ) {
				$values[] = $point[1];
			}
		}

		if ( 'energy_total' === $field ) {
			$last = empty( $values ) ? $previous : max( $values );

			return null === $previous || null === $last ? null : max( 0.0, $last - $previous );
		}

		return empty( $values ) ? null : Daily_Store::from_counter( $values, $previous );
	}

	/**
	 * Moc z historii HA: punkty co STEP_SECONDS (stan obowiązujący w tej
	 * chwili) tam, gdzie nie ma odczytu, i moc szczytowa dnia.
	 *
	 * @param string    $connection_id ID połączenia.
	 * @param Ha_Source $source        Źródło.
	 * @param string    $day           Y-m-d.
	 * @param int       $from          Początek (unix UTC).
	 * @param int       $to            Koniec (unix UTC, wyłącznie).
	 * @param int       $now           Bieżący czas.
	 * @return bool Czy zapytanie się udało.
	 */
	private static function import_power( string $connection_id, Ha_Source $source, string $day, int $from, int $to, int $now ): bool {
		$history = $source->history( array( 'current_power' ), $from, $to );

		if ( is_wp_error( $history ) ) {
			return false;
		}

		$points = $history['current_power'] ?? array();

		if ( empty( $points ) ) {
			return true;
		}

		$existing = History_Gaps::recorded_times( $connection_id, $day );
		$peak     = 0.0;

		foreach ( $points as $point ) {
			if ( $point[0] >= $from && $point[0] < $to ) {
				$peak = max( $peak, $point[1] );
			}
		}

		$index   = 0;
		$count   = count( $points );
		$current = null;

		for ( $at = (int) ( ceil( $from / self::STEP_SECONDS ) * self::STEP_SECONDS ); $at < $to; $at += self::STEP_SECONDS ) {
			while ( $index < $count && $points[ $index ][0] <= $at ) {
				$current = $points[ $index ][1];
				++$index;
			}

			if ( null === $current || History_Gaps::near( $at, $existing ) ) {
				continue;
			}

			History_Store::record( $connection_id, new PV_Reading( array( 'current_power' => $current ), $at, false, $now ) );
		}

		Daily_Store::raise_peak( $connection_id, $day, (int) round( $peak ), $now );

		return true;
	}

	/**
	 * Klucz transientu.
	 *
	 * @param string $kind          Rodzaj.
	 * @param string $connection_id ID połączenia.
	 * @param string $day           Dzień.
	 * @return string
	 */
	private static function key( string $kind, string $connection_id, string $day ): string {
		return History_Gaps::key( self::PREFIX, $kind, $connection_id, $day );
	}
}
