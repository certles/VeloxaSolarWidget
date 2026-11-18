<?php
/**
 * Historia odczytów PV — źródło danych dla wykresu produkcji (brief
 * sekcja 5, "przebieg dzienny z historii zbieranej przez wtyczkę").
 * Tabela zatwierdzona przez właściciela 2026-09-24 (brief sekcja 0,
 * wyjątek 1): jeden wiersz na odświeżenie źródła, retencja 7 dni (docs/etap-1.md,
 * 1.6 — długoterminowo zostają agregaty dzienne, Daily_Store).
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class History_Store
 *
 * Kolumny wartości odpowiadają polom PV_Reading (NULL = źródło nie
 * dostarczyło pola). recorded_at to czas pomiaru ze źródła (updated_at),
 * nie czas pobrania — unikalny klucz (connection_id, recorded_at) sprawia,
 * że ponowne pobranie tych samych danych (źródło jeszcze się nie
 * zaktualizowało) nie tworzy duplikatu.
 */
final class History_Store {

	/**
	 * Nazwa tabeli bez prefiksu WP.
	 */
	const TABLE = 'veloxa_solar_history';

	/**
	 * Pola modelu zapisywane w historii — każde ma własną kolumnę tabeli.
	 * Jawna lista, nie PV_Reading::FIELD_UNITS: nowe pole modelu nie może po
	 * cichu wymagać zmiany schematu (brief sekcja 0). home_power — kolumna
	 * dodana 2026-09-30 za zgodą właściciela (Plugin::DB_VERSION 3).
	 */
	const FIELDS = array( 'current_power', 'energy_today', 'energy_total', 'battery_soc', 'battery_power', 'grid_power', 'home_power' );

	/**
	 * Ile dni historii trzymamy.
	 */
	const RETENTION_DAYS = 7;

	/**
	 * Hook dziennego crona czyszczącego.
	 */
	const PRUNE_HOOK = 'veloxa_solar_prune_history';

	/**
	 * Pełna nazwa tabeli.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Tworzy (albo aktualizuje przez dbDelta) tabelę.
	 */
	public static function create_table(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$wpdb->prefix}veloxa_solar_history (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id varchar(64) NOT NULL,
			recorded_at datetime NOT NULL,
			fetched_at datetime NOT NULL,
			recorded_at_approximate tinyint(1) NOT NULL DEFAULT 0,
			current_power double DEFAULT NULL,
			energy_today double DEFAULT NULL,
			energy_total double DEFAULT NULL,
			battery_soc double DEFAULT NULL,
			battery_power double DEFAULT NULL,
			grid_power double DEFAULT NULL,
			home_power double DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY connection_recorded (connection_id, recorded_at),
			KEY recorded_at (recorded_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Zapisuje odczyt. Duplikat (to samo połączenie i czas pomiaru) jest
	 * pomijany.
	 *
	 * @param string     $connection_id ID połączenia.
	 * @param PV_Reading $reading       Odczyt.
	 * @param int        $min_interval  Minimalny odstęp (sekundy) od ostatniego wiersza tego połączenia — 0 = bez limitu. Dla źródeł push, które mogą wysyłać co sekundę.
	 *                                  Tolerancja 10% (maks. 30 s): urządzenie wysyłające „co 5 minut” spóźnia się
	 *                                  i przyspiesza o kilka sekund, a bez niej co drugi odczyt wypadał z historii
	 *                                  (2026-10-22: 08:50:03 → 08:55:01 = 298 s, odczyt 200 W zgubiony).
	 * @return bool Czy dodano nowy wiersz.
	 */
	public static function record( string $connection_id, PV_Reading $reading, int $min_interval = 0 ): bool {
		global $wpdb;

		if ( $min_interval > 0 ) {
			$latest = self::latest_recorded_at( $connection_id );
			$slack  = min( 30, intdiv( $min_interval, 10 ) );

			if ( null !== $latest && $reading->updated_at() - $latest < $min_interval - $slack ) {
				return false;
			}
		}

		$columns = array(
			'connection_id'           => $connection_id,
			'recorded_at'             => gmdate( 'Y-m-d H:i:s', $reading->updated_at() ),
			'fetched_at'              => gmdate( 'Y-m-d H:i:s', $reading->fetched_at() ),
			'recorded_at_approximate' => $reading->is_updated_at_approximate() ? 1 : 0,
		);

		foreach ( self::FIELDS as $field ) {
			$columns[ $field ] = $reading->get( $field );
		}

		// INSERT IGNORE — duplikat klucza unikalnego to oczekiwany przypadek
		// (źródło nie zaktualizowało jeszcze danych), nie błąd.
		$placeholders = array();

		foreach ( $columns as $value ) {
			$placeholders[] = null === $value ? 'NULL' : ( is_float( $value ) ? '%f' : ( is_int( $value ) ? '%d' : '%s' ) );
		}

		$values = array_values( array_filter( $columns, static fn( $value ) => null !== $value ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}veloxa_solar_history (" . implode( ', ', array_keys( $columns ) ) . ') VALUES (' . implode( ', ', $placeholders ) . ')',
				$values
			)
		);
		// phpcs:enable

		return 1 === $inserted;
	}

	/**
	 * Czas pomiaru najnowszego wiersza połączenia (unix, UTC), albo null.
	 *
	 * @param string $connection_id ID połączenia.
	 * @return int|null
	 */
	public static function latest_recorded_at( string $connection_id ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(recorded_at) FROM {$wpdb->prefix}veloxa_solar_history WHERE connection_id = %s", $connection_id ) );

		return null === $value ? null : (int) strtotime( $value . ' UTC' );
	}

	/**
	 * Odczyty połączenia z przedziału czasu, rosnąco.
	 *
	 * @param string $connection_id ID połączenia.
	 * @param int    $from          Początek (unix, UTC, włącznie).
	 * @param int    $to            Koniec (unix, UTC, włącznie).
	 * @return array<int, array{recorded_at: int, approximate: bool, values: array<string, float>}>
	 */
	public static function get_range( string $connection_id, int $from, int $to ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}veloxa_solar_history WHERE connection_id = %s AND recorded_at BETWEEN %s AND %s ORDER BY recorded_at ASC",
				$connection_id,
				gmdate( 'Y-m-d H:i:s', $from ),
				gmdate( 'Y-m-d H:i:s', $to )
			),
			ARRAY_A
		);
		// phpcs:enable

		$result = array();

		foreach ( (array) $rows as $row ) {
			$values = array();

			foreach ( self::FIELDS as $field ) {
				if ( null !== $row[ $field ] ) {
					$values[ $field ] = (float) $row[ $field ];
				}
			}

			$result[] = array(
				'recorded_at' => (int) strtotime( $row['recorded_at'] . ' UTC' ),
				'approximate' => (bool) $row['recorded_at_approximate'],
				'values'      => $values,
			);
		}

		return $result;
	}

	/**
	 * Najnowsze odczyty połączenia, od najnowszego.
	 *
	 * @param string $connection_id ID połączenia.
	 * @param int    $limit         Ile wierszy.
	 * @return array<int, array{recorded_at: int, values: array<string, float>}>
	 */
	public static function latest( string $connection_id, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}veloxa_solar_history WHERE connection_id = %s ORDER BY recorded_at DESC LIMIT %d", $connection_id, max( 1, $limit ) ), ARRAY_A );
		$result = array();

		foreach ( (array) $rows as $row ) {
			$values = array();

			foreach ( self::FIELDS as $field ) {
				if ( null !== $row[ $field ] ) {
					$values[ $field ] = (float) $row[ $field ];
				}
			}

			$result[] = array(
				'recorded_at' => (int) strtotime( $row['recorded_at'] . ' UTC' ),
				'values'      => $values,
			);
		}

		return $result;
	}

	/**
	 * Ostatni wiersz połączenia sprzed danej chwili — punkt odniesienia
	 * licznika energii na granicy dnia (Daily_Store).
	 *
	 * @param string $connection_id ID połączenia.
	 * @param int    $before        Unix UTC (wyłącznie).
	 * @return array{recorded_at: int, values: array<string, float>}|null
	 */
	public static function last_before( string $connection_id, int $before ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}veloxa_solar_history WHERE connection_id = %s AND recorded_at < %s ORDER BY recorded_at DESC LIMIT 1", $connection_id, gmdate( 'Y-m-d H:i:s', $before ) ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$values = array();

		foreach ( self::FIELDS as $field ) {
			if ( null !== $row[ $field ] ) {
				$values[ $field ] = (float) $row[ $field ];
			}
		}

		return array(
			'recorded_at' => (int) strtotime( $row['recorded_at'] . ' UTC' ),
			'values'      => $values,
		);
	}

	/**
	 * Usuwa odczyty starsze niż RETENTION_DAYS.
	 *
	 * @param int $now Bieżący czas (unix, UTC).
	 * @return int Liczba usuniętych wierszy.
	 */
	public static function prune( int $now ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}veloxa_solar_history WHERE recorded_at < %s", gmdate( 'Y-m-d H:i:s', $now - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Usuwa całą historię połączenia (przy usunięciu połączenia).
	 *
	 * @param string $connection_id ID połączenia.
	 */
	public static function delete_for_connection( string $connection_id ): void {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'connection_id' => $connection_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Planuje dzienne czyszczenie, jeśli nie jest zaplanowane.
	 */
	public static function schedule_prune(): void {
		if ( false === wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}
}
