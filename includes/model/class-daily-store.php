<?php
/**
 * Agregaty dzienne produkcji — historia od pierwszego dnia (docs/etap-1.md,
 * 1.6). Surowe odczyty (History_Store) żyją 7 dni; tu zostaje jeden wiersz
 * na połączenie i dzień, bez limitu. Wykresy 30 dni / 12 miesięcy (etap 2)
 * czytają stąd; miesiące i lata — zapytaniem, bez osobnej tabeli.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Daily_Store
 *
 * Dzień = data lokalna w strefie czasowej WordPressa (dzień zmiany czasu ma
 * 23 albo 25 godzin — wynika to z granic dnia, nie z liczenia godzin).
 *
 * energy_wh:
 * - 'counter' (preferowane): licznik energy_today ze źródła. Sumujemy
 *   przyrosty między kolejnymi odczytami; spadek licznika to wyzerowanie
 *   (północ, restart falownika, licznik zerowany w innej strefie — np. w
 *   UTC o 02:00 czasu strony) i początek nowego segmentu, liczonego od zera.
 *   Pierwszy odczyt dnia porównywany z ostatnim odczytem sprzed dnia; bez
 *   niego (pierwszy dzień po instalacji) przyjmujemy wyzerowanie o północy.
 *   Rozpoznanie po spadku wartości, nie po zegarze.
 * - 'integrated' (awaryjnie, źródło bez energy_today): całka current_power
 *   metodą trapezów; przez przerwę dłuższą niż 3× interwał odświeżania źródła
 *   nie całkujemy.
 * - 'history' (najdokładniejsze): suma dnia z historii samego źródła
 *   (np. Solarman, put_history_day()) — uzupełnia dni, w których WP-Cron
 *   chodził rzadko. Przeliczenie z surowych odczytów jej nie nadpisuje,
 *   podnosi tylko moc szczytową i liczbę próbek.
 */
final class Daily_Store {

	/**
	 * Nazwa tabeli bez prefiksu WP.
	 */
	const TABLE = 'veloxa_solar_daily';

	/**
	 * Spadek licznika mniejszy niż ten (Wh) albo 1 % wartości to szum
	 * zaokrągleń, nie wyzerowanie.
	 */
	const RESET_TOLERANCE_WH = 10;

	/**
	 * Wartość energy_source: suma dnia z historii źródła.
	 */
	const SOURCE_HISTORY = 'history';

	/**
	 * Tworzy (albo aktualizuje przez dbDelta) tabelę.
	 */
	public static function create_table(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$wpdb->prefix}veloxa_solar_daily (
			connection_id char(36) NOT NULL,
			day date NOT NULL,
			energy_wh bigint(20) unsigned NOT NULL DEFAULT 0,
			peak_power_w int(10) unsigned NOT NULL DEFAULT 0,
			energy_source varchar(16) NOT NULL,
			samples int(10) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (connection_id,day)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Granice dnia lokalnego (unix UTC): [początek, koniec).
	 *
	 * @param string $day Data Y-m-d w strefie WordPressa.
	 * @return array{0: int, 1: int}
	 */
	public static function day_bounds( string $day ): array {
		$start = new \DateTimeImmutable( $day . ' 00:00:00', wp_timezone() );

		return array( $start->getTimestamp(), $start->modify( '+1 day' )->getTimestamp() );
	}

	/**
	 * Przelicza dzień, w którym wypada podany czas pomiaru — wołane po
	 * każdym zapisie odczytu.
	 *
	 * @param string $connection_id ID połączenia.
	 * @param int    $recorded_at   Czas pomiaru (unix UTC).
	 * @param int    $max_gap       Najdłuższa przerwa (s), przez którą wolno całkować.
	 * @param int    $now           Bieżący czas (unix UTC).
	 * @return array<string, mixed>|null Zapisany wiersz albo null (brak danych).
	 */
	public static function refresh_for( string $connection_id, int $recorded_at, int $max_gap, int $now ): ?array {
		return self::refresh_day( $connection_id, wp_date( 'Y-m-d', $recorded_at ), $max_gap, $now );
	}

	/**
	 * Przelicza jeden dzień z surowej historii i zapisuje wynik.
	 *
	 * @param string $connection_id ID połączenia.
	 * @param string $day           Data Y-m-d (strefa WordPressa).
	 * @param int    $max_gap       Najdłuższa przerwa do całkowania (s).
	 * @param int    $now           Bieżący czas (unix UTC).
	 * @return array<string, mixed>|null
	 */
	public static function refresh_day( string $connection_id, string $day, int $max_gap, int $now ): ?array {
		list( $start, $end ) = self::day_bounds( $day );

		$rows = History_Store::get_range( $connection_id, $start, $end - 1 );

		if ( empty( $rows ) ) {
			return null;
		}

		$aggregate = self::compute( $rows, History_Store::last_before( $connection_id, $start ), $max_gap );
		$existing  = self::get_day( $connection_id, $day );

		if ( null !== $existing && self::SOURCE_HISTORY === $existing['energy_source'] ) {
			$aggregate['energy_wh']     = (int) $existing['energy_wh'];
			$aggregate['energy_source'] = self::SOURCE_HISTORY;
		}

		// Szczyt dnia nigdy nie spada: raise_peak() zapisuje go także z
		// odczytów, których nie ma w przerzedzonej historii.
		if ( null !== $existing ) {
			$aggregate['peak_power_w'] = max( $aggregate['peak_power_w'], (int) $existing['peak_power_w'] );
		}

		$row = array_merge(
			array(
				'connection_id' => $connection_id,
				'day'           => $day,
			),
			$aggregate,
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', $now ) )
		);

		self::write( $row );

		return $row;
	}

	/**
	 * Zapisuje sumę dnia z historii źródła. Moc szczytowa i liczba próbek
	 * zostają z istniejącego wiersza.
	 *
	 * @param string $connection_id ID połączenia.
	 * @param string $day           Data Y-m-d (strefa WordPressa).
	 * @param int    $energy_wh     Energia dnia (Wh).
	 * @param int    $now           Bieżący czas (unix UTC).
	 */
	public static function put_history_day( string $connection_id, string $day, int $energy_wh, int $now ): void {
		$existing = self::get_day( $connection_id, $day );

		self::write(
			array(
				'connection_id' => $connection_id,
				'day'           => $day,
				'energy_wh'     => max( 0, $energy_wh ),
				'peak_power_w'  => null === $existing ? 0 : (int) $existing['peak_power_w'],
				'energy_source' => self::SOURCE_HISTORY,
				'samples'       => null === $existing ? 0 : (int) $existing['samples'],
				'updated_at'    => gmdate( 'Y-m-d H:i:s', $now ),
			)
		);
	}

	/**
	 * Podnosi moc szczytową dnia (np. z odczytów 5-minutowych źródła), jeśli
	 * wiersz dnia istnieje i zapisana moc jest mniejsza.
	 *
	 * @param string $connection_id ID połączenia.
	 * @param string $day           Data Y-m-d.
	 * @param int    $peak_power_w  Moc (W).
	 * @param int    $now           Bieżący czas (unix UTC).
	 */
	public static function raise_peak( string $connection_id, string $day, int $peak_power_w, int $now ): void {
		$existing = self::get_day( $connection_id, $day );

		if ( null === $existing || $peak_power_w <= (int) $existing['peak_power_w'] ) {
			return;
		}

		$existing['peak_power_w'] = $peak_power_w;
		$existing['updated_at']   = gmdate( 'Y-m-d H:i:s', $now );

		self::write( $existing );
	}

	/**
	 * Wiersz jednego dnia albo null.
	 *
	 * @param string $connection_id ID połączenia.
	 * @param string $day           Data Y-m-d.
	 * @return array<string, mixed>|null
	 */
	public static function get_day( string $connection_id, string $day ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}veloxa_solar_daily WHERE connection_id = %s AND day = %s", $connection_id, $day ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Upsert wiersza (klucz: connection_id, day).
	 *
	 * @param array<string, mixed> $row Wiersz z wszystkimi kolumnami.
	 */
	private static function write( array $row ): void {
		global $wpdb;

		$row = array(
			'connection_id' => (string) $row['connection_id'],
			'day'           => (string) $row['day'],
			'energy_wh'     => (int) $row['energy_wh'],
			'peak_power_w'  => (int) $row['peak_power_w'],
			'energy_source' => (string) $row['energy_source'],
			'samples'       => (int) $row['samples'],
			'updated_at'    => (string) $row['updated_at'],
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- upsert po kluczu (connection_id, day), zapis — bez cache.
		$wpdb->replace( $wpdb->prefix . self::TABLE, $row, array( '%s', '%s', '%d', '%d', '%s', '%d', '%s' ) );
	}

	/**
	 * Wylicza agregat dnia z odczytów (bez zapisu — testowalne).
	 *
	 * @param array<int, array{recorded_at: int, values: array<string, float>}> $rows     Odczyty dnia, rosnąco.
	 * @param array{recorded_at: int, values: array<string, float>}|null        $previous Ostatni odczyt sprzed dnia.
	 * @param int                                                               $max_gap  Najdłuższa przerwa do całkowania (s).
	 * @return array{energy_wh: int, peak_power_w: int, energy_source: string, samples: int}
	 */
	public static function compute( array $rows, ?array $previous, int $max_gap ): array {
		$peak    = 0.0;
		$counter = array();

		foreach ( $rows as $row ) {
			$peak = max( $peak, (float) ( $row['values']['current_power'] ?? 0 ) );

			if ( isset( $row['values']['energy_today'] ) ) {
				$counter[] = (float) $row['values']['energy_today'];
			}
		}

		if ( ! empty( $counter ) ) {
			$energy = self::from_counter( $counter, $previous['values']['energy_today'] ?? null );
			$source = 'counter';
		} else {
			$energy = self::integrate( $rows, $max_gap );
			$source = 'integrated';
		}

		return array(
			'energy_wh'     => (int) round( max( 0.0, $energy ) ),
			'peak_power_w'  => (int) round( max( 0.0, $peak ) ),
			'energy_source' => $source,
			'samples'       => count( $rows ),
		);
	}

	/**
	 * Energia dnia z licznika: suma przyrostów, spadek = nowy segment.
	 *
	 * @param float[]    $values   Kolejne wartości licznika (Wh).
	 * @param float|null $previous Ostatnia wartość sprzed dnia albo null.
	 * @return float
	 */
	public static function from_counter( array $values, ?float $previous ): float {
		// Bez odczytu sprzed dnia: licznik wyzerowany o północy — pierwsza
		// wartość to już energia tego dnia.
		$last   = null === $previous ? 0.0 : $previous;
		$energy = 0.0;

		foreach ( $values as $value ) {
			$drop = $last - $value;

			if ( $drop > max( self::RESET_TOLERANCE_WH, 0.01 * $last ) ) {
				// Wyzerowanie: nowy segment liczony od zera.
				$energy += $value;
				$last    = $value;
				continue;
			}

			// Wzrost (albo szum zaokrągleń — wtedy przyrost 0, bez cofania).
			$energy += max( 0.0, $value - $last );
			$last    = max( $last, $value );
		}

		return $energy;
	}

	/**
	 * Energia z całki mocy (trapezy, Wh), bez przerw dłuższych niż $max_gap.
	 *
	 * @param array<int, array{recorded_at: int, values: array<string, float>}> $rows    Odczyty, rosnąco.
	 * @param int                                                               $max_gap Najdłuższa przerwa (s).
	 * @return float
	 */
	public static function integrate( array $rows, int $max_gap ): float {
		$energy = 0.0;
		$prev   = null;

		foreach ( $rows as $row ) {
			if ( ! isset( $row['values']['current_power'] ) ) {
				continue;
			}

			if ( null !== $prev ) {
				$dt = $row['recorded_at'] - $prev['recorded_at'];

				if ( $dt > 0 && $dt <= $max_gap ) {
					$energy += ( $prev['values']['current_power'] + $row['values']['current_power'] ) / 2 * $dt / HOUR_IN_SECONDS;
				}
			}

			$prev = $row;
		}

		return $energy;
	}

	/**
	 * Agregaty połączenia z przedziału dni (włącznie), rosnąco.
	 *
	 * @param string $connection_id ID połączenia.
	 * @param string $from          Y-m-d.
	 * @param string $to            Y-m-d.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_range( string $connection_id, string $from, string $to ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}veloxa_solar_daily WHERE connection_id = %s AND day BETWEEN %s AND %s ORDER BY day ASC", $connection_id, $from, $to ), ARRAY_A );
	}

	/**
	 * Pierwszy dzień z agregatem (początek zbierania danych) albo null.
	 *
	 * @param string $connection_id ID połączenia.
	 * @return string|null Y-m-d.
	 */
	public static function first_day( string $connection_id ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$day = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(day) FROM {$wpdb->prefix}veloxa_solar_daily WHERE connection_id = %s", $connection_id ) );

		return null === $day ? null : (string) $day;
	}

	/**
	 * Usuwa agregaty połączenia (przy usunięciu połączenia).
	 *
	 * @param string $connection_id ID połączenia.
	 */
	public static function delete_for_connection( string $connection_id ): void {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . self::TABLE, array( 'connection_id' => $connection_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
