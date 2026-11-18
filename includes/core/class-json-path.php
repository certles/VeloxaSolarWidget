<?php
/**
 * Uproszczony resolver ścieżek JSON (notacja kropkowa). Skopiowane z IoT
 * Data Widget (includes/class-json-path.php, commit b10c54c — patrz
 * SHARED.md), bez zmian poza namespace/nazwą klasy.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Json_Path
 *
 * To NIE jest pełna specyfikacja JSONPath (bez filtrów, wildcardów,
 * zakresów) — celowe uproszczenie.
 */
class Json_Path {

	/**
	 * Rozwiązuje uproszczoną ścieżkę kropkową w zdekodowanej strukturze JSON.
	 *
	 * @param mixed  $data Zdekodowana odpowiedź JSON.
	 * @param string $path Ścieżka, np. "data.temperature" albo "$.readings.0.value".
	 * @return mixed|null Null, gdy ścieżka nie istnieje.
	 */
	public static function resolve( $data, string $path ) {
		$path = trim( $path );

		if ( str_starts_with( $path, '$.' ) ) {
			$path = substr( $path, 2 );
		} elseif ( '$' === $path ) {
			$path = '';
		}

		if ( '' === $path ) {
			return $data;
		}

		$current = $data;

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}

			$current = $current[ $segment ];
		}

		return $current;
	}
}
