<?php
/**
 * Konwersja jednostek i znaczników czasu źródeł do modelu PV_Reading —
 * brief sekcja 4: "konwersja jednostek w jednym miejscu". Każde źródło
 * (Generic REST, presety, push, HA, SolarEdge) przechodzi przez tę klasę;
 * żadne nie przelicza jednostek samo.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Unit_Converter
 */
final class Unit_Converter {

	/**
	 * Mnożniki do jednostki wewnętrznej, per jednostka wewnętrzna
	 * (PV_Reading::FIELD_UNITS). Wielkość liter ma znaczenie — "mW"
	 * (miliwat) to nie "MW" (megawat), więc nie normalizujemy wielkości.
	 */
	const FACTORS = array(
		'W'  => array(
			'W'  => 1,
			'kW' => 1000,
			'MW' => 1000000,
		),
		'Wh' => array(
			'Wh'  => 1,
			'kWh' => 1000,
			'MWh' => 1000000,
		),
		'%'  => array(
			'%' => 1,
		),
	);

	/**
	 * Akceptowane zapisy jednostek źródła → jednostka kanoniczna (klucz
	 * FACTORS), per jednostka wewnętrzna. Jawna lista zamiast ogólnego
	 * ignorowania wielkości liter (brief, BLOKER 1): "mW" (miliwat) i "MW"
	 * (megawat) różnią się tylko wielkością litery, więc "mW" celowo nie ma
	 * na liście i jest odrzucane. Urządzenia wysyłające "w" / "kw" dostają
	 * poprawną konwersję zamiast cichego przyjęcia jednostki kanału.
	 */
	const SPELLINGS = array(
		'W'  => array(
			'W'  => 'W',
			'w'  => 'W',
			'kW' => 'kW',
			'kw' => 'kW',
			'KW' => 'kW',
			'MW' => 'MW',
		),
		'Wh' => array(
			'Wh'  => 'Wh',
			'wh'  => 'Wh',
			'kWh' => 'kWh',
			'kwh' => 'kWh',
			'KWH' => 'kWh',
			'MWh' => 'MWh',
		),
		'%'  => array(
			'%' => '%',
		),
	);

	/**
	 * Jednostki kanoniczne dopuszczalne dla pola (do wyboru w formularzach).
	 *
	 * @param string $field Pole PV_Reading.
	 * @return string[] Pusta lista dla nieznanego pola.
	 */
	public static function units_for( string $field ): array {
		$internal = PV_Reading::FIELD_UNITS[ $field ] ?? null;

		return null === $internal ? array() : array_keys( self::FACTORS[ $internal ] );
	}

	/**
	 * Wszystkie akceptowane zapisy jednostek dla pola (do komunikatów błędu).
	 *
	 * @param string $field Pole PV_Reading.
	 * @return string[] Pusta lista dla nieznanego pola.
	 */
	public static function accepted_spellings_for( string $field ): array {
		$internal = PV_Reading::FIELD_UNITS[ $field ] ?? null;

		return null === $internal ? array() : array_keys( self::SPELLINGS[ $internal ] );
	}

	/**
	 * Jednostka kanoniczna dla zapisu podanego przez źródło, albo null, gdy
	 * zapis nie jest akceptowany dla pola (albo pole jest nieznane).
	 *
	 * @param string $field Pole PV_Reading.
	 * @param string $unit  Jednostka w zapisie źródła.
	 * @return string|null
	 */
	public static function canonical_unit( string $field, string $unit ): ?string {
		$internal = PV_Reading::FIELD_UNITS[ $field ] ?? null;

		return null === $internal ? null : ( self::SPELLINGS[ $internal ][ trim( $unit ) ] ?? null );
	}

	/**
	 * Przelicza surową wartość źródła na jednostkę wewnętrzną pola.
	 *
	 * @param string $field Pole PV_Reading.
	 * @param mixed  $raw   Surowa wartość (liczba albo liczba jako string).
	 * @param string $unit  Jednostka, w której źródło podaje wartość.
	 * @return float|null Null, gdy $raw nie jest liczbą.
	 * @throws \InvalidArgumentException Przy nieznanym polu albo jednostce niedopuszczalnej dla pola — błąd konfiguracji, nie danych.
	 */
	public static function to_internal( string $field, $raw, string $unit ): ?float {
		$internal = PV_Reading::FIELD_UNITS[ $field ] ?? null;

		if ( null === $internal ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown PV field "%s".', esc_html( $field ) ) );
		}

		$canonical = self::canonical_unit( $field, $unit );

		if ( null === $canonical ) {
			throw new \InvalidArgumentException( sprintf( 'Unit "%1$s" is not valid for field "%2$s".', esc_html( $unit ), esc_html( $field ) ) );
		}

		$unit = $canonical;

		// is_numeric(bool) === false — true/false z JSON-a nie przejdzie jako 1/0.
		if ( ! is_numeric( $raw ) ) {
			return null;
		}

		$value = (float) $raw;

		if ( ! is_finite( $value ) ) {
			return null;
		}

		return $value * self::FACTORS[ $internal ][ $unit ];
	}

	/**
	 * Parsuje znacznik czasu źródła do unix UTC.
	 *
	 * - liczba (albo liczba jako string): sekundy unix; wartości > 1e11
	 *   traktowane jako milisekundy (1e11 s to rok ~5138, a 1e11 ms to 1973
	 *   — przedziały się nie nakładają dla realnych dat),
	 * - string z datą: z własnym przesunięciem strefy (ISO 8601 "…Z"/"+02:00")
	 *   — użyte wprost; bez przesunięcia — interpretowany w $source_timezone
	 *   (np. "2026-09-24 12:30:00" z chmury producenta w czasie lokalnym).
	 *
	 * @param mixed         $raw             Surowa wartość ze źródła.
	 * @param \DateTimeZone $source_timezone Strefa dla dat bez przesunięcia.
	 * @return int|null Null, gdy nie da się sparsować.
	 */
	public static function parse_timestamp( $raw, \DateTimeZone $source_timezone ): ?int {
		if ( is_int( $raw ) || is_float( $raw ) || ( is_string( $raw ) && is_numeric( trim( $raw ) ) ) ) {
			$seconds = (float) $raw;

			if ( $seconds > 1e11 ) {
				$seconds /= 1000;
			}

			return $seconds > 0 ? (int) floor( $seconds ) : null;
		}

		if ( ! is_string( $raw ) ) {
			return null;
		}

		$raw = trim( $raw );

		// Wymóg cyfry odrzuca względne wyrażenia strtotime() ("now",
		// "tomorrow") — źródło, które je zwraca, nie podaje realnego czasu.
		if ( '' === $raw || ! preg_match( '/\d/', $raw ) ) {
			return null;
		}

		try {
			$date = new \DateTimeImmutable( $raw, $source_timezone );
		} catch ( \Exception $e ) {
			return null;
		}

		return $date->getTimestamp();
	}
}
