<?php
/**
 * Ostatni znormalizowany odczyt każdego źródła — to, co czyta ścieżka
 * renderowania. Brief sekcja 7: żadnego zapytania do API producenta w
 * ścieżce renderowania; dane odświeża w tle Source_Refresher, widgety
 * czytają wyłącznie stąd.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Reading_Store
 *
 * Jedna opcja (autoload wyłączony) na połączenie, nie jedna zbiorcza —
 * równoległe odświeżanie dwóch źródeł nie nadpisze sobie nawzajem danych
 * (read-modify-write na wspólnej opcji). Opcje, nie tabela: nowa tabela to
 * zmiana schematu bazy (brief sekcja 0, wyjątek 1), a do przechowania
 * jednego odczytu na źródło nie jest potrzebna. Historia (wykres, Faza 5)
 * to osobna decyzja.
 */
final class Reading_Store {

	/**
	 * Prefiks nazwy opcji — dopisany do czyszczenia w Plugin::uninstall().
	 */
	const OPTION_PREFIX = 'veloxa_solar_reading_';

	/**
	 * Czasy prób z ostatniej doby — do ostrzeżenia w diagnostyce, gdy
	 * WP-Cron chodzi rzadko (mały ruch na stronie). Górny limit wpisów
	 * chroni rozmiar opcji przy źródłach push.
	 */
	const ATTEMPT_LOG_MAX = 600;

	/**
	 * Zapisuje udany odczyt; kasuje ostatni błąd.
	 *
	 * @param string     $connection_id ID połączenia.
	 * @param PV_Reading $reading       Odczyt.
	 */
	public static function save( string $connection_id, PV_Reading $reading ): void {
		$state = self::get_state( $connection_id );

		update_option(
			self::OPTION_PREFIX . $connection_id,
			array_merge(
				array(
					'reading'         => $reading->to_array(),
					'last_error'      => null,
					'last_attempt_at' => $reading->fetched_at(),
				),
				self::attempt_log( $state, $reading->fetched_at() )
			),
			false
		);
	}

	/**
	 * Zapisuje nieudaną próbę, ZACHOWUJĄC ostatni dobry odczyt — widget
	 * pokazuje ostatnią wartość z czasem aktualizacji zamiast błędu (brief
	 * 3.1, ten sam wzorzec dla każdego źródła).
	 *
	 * @param string $connection_id ID połączenia.
	 * @param string $message       Komunikat błędu (już zredagowany z sekretów).
	 * @param int    $attempted_at  Czas próby (unix, UTC).
	 * @param string $key           Klucz Messages — tekst składany przy wyświetleniu.
	 * @param array  $args          Argumenty komunikatu z kluczem.
	 */
	public static function save_error( string $connection_id, string $message, int $attempted_at, string $key = '', array $args = array() ): void {
		$state = self::get_state( $connection_id );

		update_option(
			self::OPTION_PREFIX . $connection_id,
			array_merge(
				array(
					'reading'         => $state['reading'] ?? null,
					'last_error'      => $message,
					'message_key'     => $key,
					'message_args'    => $args,
					'last_attempt_at' => $attempted_at,
				),
				self::attempt_log( $state, $attempted_at )
			),
			false
		);
	}

	/**
	 * Liczba prób od podanej chwili (z ostatniej doby).
	 *
	 * @param string $connection_id ID połączenia.
	 * @param int    $since         Unix UTC.
	 * @return int
	 */
	public static function attempts_since( string $connection_id, int $since ): int {
		$state = self::get_state( $connection_id );

		return count(
			array_filter(
				(array) ( $state['attempts'] ?? array() ),
				static fn( $at ): bool => (int) $at >= $since
			)
		);
	}

	/**
	 * Od kiedy liczone są próby (pierwsza zapisana), albo null.
	 *
	 * @param string $connection_id ID połączenia.
	 * @return int|null
	 */
	public static function attempts_tracked_since( string $connection_id ): ?int {
		$state = self::get_state( $connection_id );

		return isset( $state['attempts_since'] ) ? (int) $state['attempts_since'] : null;
	}

	/**
	 * Dziennik prób po dopisaniu nowej: tylko ostatnia doba.
	 *
	 * @param array<string, mixed>|null $state Dotychczasowy stan.
	 * @param int                       $at    Czas próby.
	 * @return array{attempts: int[], attempts_since: int}
	 */
	private static function attempt_log( ?array $state, int $at ): array {
		$attempts   = array_values(
			array_filter(
				(array) ( $state['attempts'] ?? array() ),
				static fn( $time ): bool => (int) $time > $at - DAY_IN_SECONDS
			)
		);
		$attempts[] = $at;

		return array(
			'attempts'       => array_slice( $attempts, -self::ATTEMPT_LOG_MAX ),
			'attempts_since' => (int) ( $state['attempts_since'] ?? $at ),
		);
	}

	/**
	 * Ostatni dobry odczyt, albo null.
	 *
	 * @param string $connection_id ID połączenia.
	 * @return PV_Reading|null
	 */
	public static function get( string $connection_id ): ?PV_Reading {
		$state = self::get_state( $connection_id );

		return null === $state ? null : PV_Reading::from_array( $state['reading'] ?? null );
	}

	/**
	 * Komunikat ostatniej nieudanej próby, albo null, jeśli ostatnia się udała.
	 *
	 * @param string $connection_id ID połączenia.
	 * @return string|null
	 */
	public static function get_last_error( string $connection_id ): ?string {
		$state = self::get_state( $connection_id );

		return isset( $state['last_error'] ) ? \Veloxa\Solar\Core\Messages::from_stored( $state, 'last_error' ) : null;
	}

	/**
	 * Czas ostatniej próby (udanej lub nie), albo null, gdy nie było żadnej.
	 *
	 * @param string $connection_id ID połączenia.
	 * @return int|null
	 */
	public static function get_last_attempt_at( string $connection_id ): ?int {
		$state = self::get_state( $connection_id );

		return isset( $state['last_attempt_at'] ) ? (int) $state['last_attempt_at'] : null;
	}

	/**
	 * Usuwa stan połączenia (przy usunięciu połączenia).
	 *
	 * @param string $connection_id ID połączenia.
	 */
	public static function delete( string $connection_id ): void {
		delete_option( self::OPTION_PREFIX . $connection_id );
	}

	/**
	 * Surowy zapisany stan.
	 *
	 * @param string $connection_id ID połączenia.
	 * @return array<string, mixed>|null
	 */
	private static function get_state( string $connection_id ): ?array {
		$state = get_option( self::OPTION_PREFIX . $connection_id, null );

		return is_array( $state ) ? $state : null;
	}
}
