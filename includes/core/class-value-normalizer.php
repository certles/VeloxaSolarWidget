<?php
/**
 * Normalizuje surowe wartości źródeł do kontraktu 'value' (zawsze liczba) +
 * opcjonalnego 'display_value'. Skopiowane z IoT Data Widget
 * (includes/class-value-normalizer.php, commit b10c54c — patrz SHARED.md),
 * bez zmian poza namespace/nazwą klasy.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Value_Normalizer
 */
class Value_Normalizer {

	/**
	 * Stany mapowane na 1 ("włączone"/"aktywne"/prawda).
	 *
	 * @var string[]
	 */
	const TRUE_STATES = array( 'on', 'true', 'home', 'open', 'opening', 'locked', 'detected', 'active', 'yes', 'wet' );

	/**
	 * Stany mapowane na 0 ("wyłączone"/"nieaktywne"/fałsz).
	 *
	 * @var string[]
	 */
	const FALSE_STATES = array( 'off', 'false', 'not_home', 'away', 'closed', 'closing', 'unlocked', 'clear', 'inactive', 'no', 'dry' );

	/**
	 * Stany "brak odczytu" (HA unavailable/unknown) — nie są pomiarem
	 * (IoT Data Widget 1.0.6, przegląd demo 2026-10-22).
	 *
	 * @var string[]
	 */
	const UNAVAILABLE_STATES = array( 'unavailable', 'unknown', 'none', 'null', 'nan', 'n/a' );

	/**
	 * Czy surowa wartość to stan "brak odczytu" (patrz UNAVAILABLE_STATES).
	 *
	 * @param mixed $raw_value Surowa wartość/tekst stanu.
	 * @return bool
	 */
	public static function is_unavailable( $raw_value ): bool {
		if ( ! is_string( $raw_value ) ) {
			return false;
		}

		return in_array( strtolower( trim( $raw_value ) ), self::UNAVAILABLE_STATES, true );
	}

	/**
	 * Normalizuje surową wartość ze źródła danych.
	 *
	 * @param mixed $raw_value Surowa wartość ze źródła.
	 * @return array{value: mixed, display_value: string|null}
	 */
	public static function normalize( $raw_value ): array {
		if ( is_numeric( $raw_value ) ) {
			return array(
				'value'         => (float) $raw_value,
				'display_value' => null,
			);
		}

		$text  = is_bool( $raw_value ) ? ( $raw_value ? 'true' : 'false' ) : (string) $raw_value;
		$state = strtolower( trim( $text ) );

		if ( in_array( $state, self::TRUE_STATES, true ) ) {
			return array(
				'value'         => 1.0,
				'display_value' => $text,
			);
		}

		if ( in_array( $state, self::FALSE_STATES, true ) ) {
			return array(
				'value'         => 0.0,
				'display_value' => $text,
			);
		}

		return array(
			'value'         => $raw_value,
			'display_value' => null,
		);
	}
}
