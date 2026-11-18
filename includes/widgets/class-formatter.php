<?php
/**
 * Formatowanie wartości modelu PV do wyświetlenia (brief sekcja 5:
 * "4,82 kW", "24,8 kWh", "38,4 MWh" — automatyczne skalowanie). Separator
 * dziesiętny z locale WordPressa (number_format_i18n).
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Formatter
 */
final class Formatter {

	/**
	 * Moc (wejście w W): poniżej 1 kW w W, potem kW / MW z dwoma miejscami.
	 * Małe niezerowe wartości dostają tylko tyle dodatkowej precyzji, by nie
	 * zostały pokazane jako zero.
	 *
	 * @param float $watts Moc w W.
	 * @return array{value: string, unit: string}
	 */
	public static function power( float $watts ): array {
		$abs = abs( $watts );

		if ( $abs < 1000 ) {
			return self::pair_preserving_nonzero( $watts, 0, 'W' );
		}

		if ( $abs < 1000000 ) {
			return self::pair_preserving_nonzero( $watts / 1000, 2, 'kW' );
		}

		return self::pair_preserving_nonzero( $watts / 1000000, 2, 'MW' );
	}

	/**
	 * Energia (wejście w Wh) — zawsze co najmniej kWh (0,8 kWh czytelniejsze
	 * niż 800 Wh w kontekście produkcji PV), od 1000 kWh MWh, od 1000 MWh GWh.
	 *
	 * @param float $watt_hours Energia w Wh.
	 * @return array{value: string, unit: string}
	 */
	public static function energy( float $watt_hours ): array {
		$abs = abs( $watt_hours );

		if ( $abs < 1000000 ) {
			return self::pair_preserving_nonzero( $watt_hours / 1000, 1, 'kWh' );
		}

		if ( $abs < 1000000000 ) {
			return self::pair_preserving_nonzero( $watt_hours / 1000000, 1, 'MWh' );
		}

		return self::pair_preserving_nonzero( $watt_hours / 1000000000, 2, 'GWh' );
	}

	/**
	 * Masa CO₂ (wejście w kg): poniżej 10 kg z jednym miejscem (z dodatkową
	 * precyzją dla małych wartości), do 1000 kg w kg, potem t (jedno miejsce)
	 * i od 1000 t — kt.
	 *
	 * @param float $kilograms Masa w kg.
	 * @return array{value: string, unit: string}
	 */
	public static function mass( float $kilograms ): array {
		$abs = abs( $kilograms );

		if ( $abs < 10 ) {
			return self::pair_preserving_nonzero( $kilograms, 1, 'kg' );
		}

		if ( $abs < 1000 ) {
			return self::pair( $kilograms, 0, 'kg' );
		}

		if ( $abs < 1000000 ) {
			return self::pair( $kilograms / 1000, 1, 't' );
		}

		return self::pair( $kilograms / 1000000, 2, 'kt' );
	}

	/**
	 * Poziom procentowy, np. SOC baterii. Zwykłe wartości pozostają
	 * całkowite; małe niezerowe wartości zachowują czytelną precyzję.
	 *
	 * @param float $value Procent.
	 * @return array{value: string, unit: string}
	 */
	public static function percentage( float $value ): array {
		return self::pair_preserving_nonzero( $value, 0, '%' );
	}

	/**
	 * Liczba z tyloma miejscami po przecinku, ile potrzeba (0,7 → „0,7”;
	 * 0,597 → „0,597”), najwyżej trzema.
	 *
	 * @param float $value Wartość.
	 * @return string
	 */
	public static function decimal( float $value ): string {
		for ( $decimals = 1; $decimals < 3; $decimals++ ) {
			if ( abs( round( $value, $decimals ) - $value ) < 1e-9 ) {
				break;
			}
		}

		return number_format_i18n( $value, $decimals );
	}

	/**
	 * Dowolne pole modelu PV jako tekst "wartość jednostka" — moc/energia
	 * skalowane jak w widgetach, bateria w %.
	 *
	 * @param string $field Pole PV_Reading.
	 * @param float  $value Wartość w jednostce wewnętrznej.
	 * @return string
	 */
	public static function field( string $field, float $value ): string {
		if ( 'battery_soc' === $field ) {
			$pair = self::percentage( $value );
		} elseif ( in_array( $field, array( 'energy_today', 'energy_total' ), true ) ) {
			$pair = self::energy( $value );
		} else {
			$pair = self::power( $value );
		}

		return $pair['value'] . ' ' . $pair['unit'];
	}

	/**
	 * Czas aktualizacji w strefie WordPressa: sama godzina, gdy dziś; inaczej
	 * data i godzina.
	 *
	 * @param int $timestamp Unix UTC.
	 * @param int $now       Bieżący czas (unix, UTC).
	 * @return string
	 */
	public static function time( int $timestamp, int $now ): string {
		$same_day = wp_date( 'Y-m-d', $timestamp ) === wp_date( 'Y-m-d', $now );
		$format   = $same_day ? get_option( 'time_format' ) : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return (string) wp_date( $format, $timestamp );
	}

	/**
	 * Para wartość + jednostka.
	 *
	 * @param float  $value    Wartość w docelowej jednostce.
	 * @param int    $decimals Miejsca po przecinku.
	 * @param string $unit     Jednostka.
	 * @return array{value: string, unit: string}
	 */
	private static function pair( float $value, int $decimals, string $unit ): array {
		return array(
			'value' => number_format_i18n( $value, $decimals ),
			'unit'  => $unit,
		);
	}

	/**
	 * Zachowuje dokładne zero oraz zwiększa precyzję, gdy zwykłe
	 * zaokrąglenie zamieniłoby niezerowy pomiar w zero. Dla wartości mniejszej
	 * niż rozdzielczość sześciu miejsc pokazuje jawne ograniczenie zamiast zera.
	 *
	 * @param float  $value    Wartość w docelowej jednostce.
	 * @param int    $decimals Domyślna precyzja dla danej wielkości.
	 * @param string $unit     Jednostka.
	 * @return array{value: string, unit: string}
	 */
	private static function pair_preserving_nonzero( float $value, int $decimals, string $unit ): array {
		if ( 0.0 === $value ) {
			return self::pair( 0.0, 0, $unit );
		}

		for ( $precision = $decimals; $precision <= 6; $precision++ ) {
			if ( 0.0 !== round( $value, $precision ) ) {
				return self::pair( $value, $precision, $unit );
			}
		}

		$threshold = number_format_i18n( 0.000001, 6 );

		return array(
			'value' => ( $value < 0 ? '-<' : '<' ) . $threshold,
			'unit'  => $unit,
		);
	}
}
