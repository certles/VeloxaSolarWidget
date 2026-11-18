<?php
/**
 * Wspólne narzędzia uzupełniania danych z historii źródła (Solarman w Pro,
 * Home Assistant we wszystkich wersjach): które dni nie mają sumy z historii,
 * daty lokalne, odstęp od istniejących odczytów i blokady ponownych prób.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class History_Gaps
 */
final class History_Gaps {

	/**
	 * Odczyt z historii bliżej istniejącego niż tyle sekund jest pomijany.
	 */
	const NEAR_SECONDS = 150;

	/**
	 * Po nieudanej próbie — kolejna najwcześniej po tylu sekundach.
	 */
	const RETRY_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Dni od pierwszego agregatu połączenia (maks. $window dni wstecz) do
	 * wczoraj bez sumy z historii — rosnąco.
	 *
	 * @param string $connection_id ID połączenia.
	 * @param int    $now           Bieżący czas.
	 * @param int    $window        Najstarszy dzień (dni wstecz).
	 * @return string[] Daty Y-m-d.
	 */
	public static function days_without_history( string $connection_id, int $now, int $window ): array {
		$first = Daily_Store::first_day( $connection_id );

		if ( null === $first ) {
			return array();
		}

		$from = max( $first, self::day_offset( $now, -$window ) );
		$to   = self::day_offset( $now, -1 );

		if ( $from > $to ) {
			return array();
		}

		$sources = array();

		foreach ( Daily_Store::get_range( $connection_id, $from, $to ) as $row ) {
			$sources[ (string) $row['day'] ] = (string) $row['energy_source'];
		}

		$missing = array();

		for ( $day = $from; $day <= $to; $day = self::next_day( $day ) ) {
			if ( Daily_Store::SOURCE_HISTORY !== ( $sources[ $day ] ?? '' ) ) {
				$missing[] = $day;
			}
		}

		return $missing;
	}

	/**
	 * Minione dni (od wczoraj wstecz, maks. $window, nie starsze niż
	 * pierwszy agregat) z rzadkimi odczytami albo bez wiersza dnia.
	 *
	 * @param string $connection_id    ID połączenia.
	 * @param int    $refresh_interval Interwał odświeżania (s).
	 * @param int    $now              Bieżący czas.
	 * @param int    $window           Dni wstecz.
	 * @param float  $ratio            Ułamek oczekiwanych odczytów, poniżej którego dzień jest „rzadki”.
	 * @return string[] Daty Y-m-d, od najnowszej.
	 */
	public static function sparse_days( string $connection_id, int $refresh_interval, int $now, int $window, float $ratio ): array {
		$expected = DAY_IN_SECONDS / max( MINUTE_IN_SECONDS, $refresh_interval );
		$first    = Daily_Store::first_day( $connection_id );
		$days     = array();

		for ( $back = 1; $back <= $window; $back++ ) {
			$day = self::day_offset( $now, -$back );

			if ( null === $first || $day < $first ) {
				break;
			}

			$row = Daily_Store::get_day( $connection_id, $day );

			if ( null === $row || (int) $row['samples'] < $ratio * $expected ) {
				$days[] = $day;
			}
		}

		return $days;
	}

	/**
	 * Początek dzisiejszej przerwy, jeśli jest dłuższa niż 3 interwały —
	 * inaczej null.
	 *
	 * @param int|null $previous_at      Czas poprzedniego odczytu.
	 * @param int      $refresh_interval Interwał (s).
	 * @param int      $now              Bieżący czas.
	 * @return int|null
	 */
	public static function today_gap_start( ?int $previous_at, int $refresh_interval, int $now ): ?int {
		list( $day_start ) = Daily_Store::day_bounds( wp_date( 'Y-m-d', $now ) );

		$from = null === $previous_at ? $day_start : max( $day_start, $previous_at );

		return $now - $from > 3 * $refresh_interval ? $from : null;
	}

	/**
	 * Czy czas leży bliżej niż NEAR_SECONDS od któregoś z istniejących.
	 *
	 * @param int   $at       Czas.
	 * @param int[] $existing Istniejące czasy.
	 * @return bool
	 */
	public static function near( int $at, array $existing ): bool {
		foreach ( $existing as $time ) {
			if ( abs( (int) $time - $at ) < self::NEAR_SECONDS ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Czasy odczytów zapisanych w danym dniu.
	 *
	 * @param string $connection_id ID połączenia.
	 * @param string $day           Y-m-d.
	 * @return int[]
	 */
	public static function recorded_times( string $connection_id, string $day ): array {
		list( $start, $end ) = Daily_Store::day_bounds( $day );

		return array_column( History_Store::get_range( $connection_id, $start, $end - 1 ), 'recorded_at' );
	}

	/**
	 * Klucz transientu (krótki — limit długości nazwy opcji).
	 *
	 * @param string $prefix        Prefiks źródła.
	 * @param string $kind          Rodzaj.
	 * @param string $connection_id ID połączenia.
	 * @param string $day           Dzień Y-m-d.
	 * @return string
	 */
	public static function key( string $prefix, string $kind, string $connection_id, string $day ): string {
		return $prefix . 'bf_' . $kind . '_' . substr( md5( $connection_id ), 0, 12 ) . '_' . str_replace( '-', '', $day );
	}

	/**
	 * Czy dla klucza ustawiono znacznik (wykonane albo zablokowane).
	 *
	 * @param string $key Klucz.
	 * @return bool
	 */
	public static function is_set( string $key ): bool {
		return false !== get_transient( $key );
	}

	/**
	 * Ustawia znacznik na podany czas.
	 *
	 * @param string $key     Klucz.
	 * @param int    $seconds Czas życia.
	 */
	public static function set( string $key, int $seconds ): void {
		set_transient( $key, 1, $seconds );
	}

	/**
	 * Dzień lokalny przesunięty o liczbę dni.
	 *
	 * @param int $now    Czas.
	 * @param int $offset Dni (ujemne = wstecz).
	 * @return string Y-m-d.
	 */
	public static function day_offset( int $now, int $offset ): string {
		return ( new \DateTimeImmutable( wp_date( 'Y-m-d', $now ), wp_timezone() ) )->modify( sprintf( '%+d day', $offset ) )->format( 'Y-m-d' );
	}

	/**
	 * Następny dzień.
	 *
	 * @param string $day Y-m-d.
	 * @return string
	 */
	public static function next_day( string $day ): string {
		return ( new \DateTimeImmutable( $day, wp_timezone() ) )->modify( '+1 day' )->format( 'Y-m-d' );
	}
}
