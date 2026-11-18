<?php
/**
 * Warstwa cache — wrapper na WP Transients API. Skopiowane z IoT Data
 * Widget (includes/class-cache.php, commit b10c54c — patrz SHARED.md), bez
 * zmian poza namespace/nazwą klasy/prefiksem kluczy transientów.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cache
 *
 * Cache jest zawsze aktywny — bez opcji wyłączenia, żeby użytkownicy nie
 * zabijali requestami swojego źródła danych.
 */
class Cache {

	const DEFAULT_TTL = 60;

	const ERROR_TTL = 10;

	const LOCK_TTL = 10;

	const LAST_KNOWN_TTL = HOUR_IN_SECONDS;

	const MAX_ERROR_BACKOFF_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Zwraca dane z cache, a gdy ich brak (lub wygasły) — pobiera je przez
	 * $callback i zapisuje w cache na $ttl sekund. Chroni przed cache
	 * stampede (transient-owy "zamek") i implementuje wykładniczy backoff
	 * błędów per połączenie.
	 *
	 * @param string   $key           Unikalny klucz (np. id połączenia . entity_id).
	 * @param callable $callback      Funkcja zwracająca znormalizowaną strukturę danych.
	 * @param int      $ttl           Czas życia w sekundach dla udanej odpowiedzi.
	 * @param int      $error_ttl     BAZOWY (pierwsza kolejna awaria) czas życia w sekundach dla odpowiedzi z błędem.
	 * @param string   $connection_id ID połączenia — gdy podane, wynik cache-miss loguje się do Diagnostics_Logger i steruje wykładniczym backoffem.
	 * @return array
	 */
	public function remember( string $key, callable $callback, int $ttl = self::DEFAULT_TTL, int $error_ttl = self::ERROR_TTL, string $connection_id = '' ): array {
		$cache_key = $this->build_key( $key );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$lock_key = $cache_key . '_lock';
		$last_key = $cache_key . '_last';

		if ( false !== get_transient( $lock_key ) ) {
			$last_known = get_transient( $last_key );

			if ( false !== $last_known ) {
				return $last_known;
			}
		}

		set_transient( $lock_key, 1, self::LOCK_TTL );

		try {
			$data = $callback();
		} finally {
			delete_transient( $lock_key );
		}

		$is_error   = is_array( $data ) && ! empty( $data['error'] );
		$last_known = get_transient( $last_key );

		if ( $is_error ) {
			$effective_ttl = self::error_backoff_ttl( $error_ttl, $connection_id );
			$return_data   = false !== $last_known ? $last_known : $data;
		} else {
			$effective_ttl = $ttl;
			$return_data   = $data;
		}

		set_transient( $cache_key, $return_data, max( 1, $effective_ttl ) );

		if ( ! $is_error ) {
			set_transient( $last_key, $data, self::LAST_KNOWN_TTL );
		}

		if ( '' !== $connection_id && is_array( $data ) ) {
			Diagnostics_Logger::record_connection_result( $connection_id, $data );
		}

		return $return_data;
	}

	/**
	 * Wykładniczy backoff czasu cache'owania błędu — 10s / 20s / 40s / ...
	 * aż do pułapu MAX_ERROR_BACKOFF_TTL.
	 *
	 * @param int    $base_error_ttl Bazowy czas życia błędu (pierwsza awaria).
	 * @param string $connection_id  ID połączenia.
	 * @return int
	 */
	private static function error_backoff_ttl( int $base_error_ttl, string $connection_id ): int {
		if ( '' === $connection_id ) {
			return $base_error_ttl;
		}

		$failure_number = Diagnostics_Logger::get_consecutive_errors( $connection_id ) + 1;
		$backoff_ttl    = $base_error_ttl * ( 2 ** min( $failure_number - 1, 10 ) );

		return min( $backoff_ttl, self::MAX_ERROR_BACKOFF_TTL );
	}

	/**
	 * Usuwa wpis z cache przed jego naturalnym wygaśnięciem.
	 *
	 * @param string $key Klucz bazowy (ten sam, co przekazany do remember()).
	 */
	public function forget( string $key ): void {
		delete_transient( $this->build_key( $key ) );
	}

	/**
	 * Buduje klucz transienta z prefiksem wtyczki.
	 *
	 * @param string $key Klucz bazowy.
	 * @return string
	 */
	private function build_key( string $key ): string {
		return 'veloxa_solar_' . md5( $key );
	}
}
