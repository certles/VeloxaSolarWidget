<?php
/**
 * Źródło push — urządzenie/bramka (np. automatyzacja Home Assistant,
 * Node-RED, skrypt na routerze) samo wysyła odczyty do
 * veloxa-solar-widget/v1/ingest. Push_Ingest (skopiowany z IoT) zapisuje surowe
 * wartości encji kanału; ta klasa składa z nich znormalizowany PV_Reading.
 *
 * Nazwy encji kanału = nazwy pól modelu (current_power, energy_today, …) —
 * jeden stały, udokumentowany kontrakt zamiast dowolnych nazw do
 * mapowania; przykład gotowy do wklejenia pokazuje ekran kanału.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Sources;

use Veloxa\Solar\Core\Push_Channel_Manager;
use Veloxa\Solar\Model\History_Store;
use Veloxa\Solar\Model\PV_Reading;
use Veloxa\Solar\Model\Reading_Store;
use Veloxa\Solar\Model\Unit_Converter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Push_Source
 *
 * Konfiguracja połączenia:
 * - fields — pole PV => {unit, invert?}; rdzeń zawsze, opcjonalne wybrane
 *   przez admina (tylko te są na whitelist kanału),
 * - refresh_minutes — jak często urządzenie wysyła dane (podstawa stanu
 *   "dane nieaktualne"),
 * - token_hash — hash tokenu kanału (Push_Channel_Manager).
 */
class Push_Source extends Abstract_Source {

	/**
	 * Typ konektora w Connector_Manager — ten sam, którego szuka Push_Ingest.
	 */
	const CONNECTOR_TYPE = Push_Channel_Manager::CONNECTOR_TYPE;

	/**
	 * Co najwyżej jeden wiersz historii na tyle sekund — urządzenie może
	 * wysyłać nawet co sekundę (limit Push_Ingest: 60/min), a wykres
	 * dzienny nie potrzebuje takiej rozdzielczości.
	 */
	const HISTORY_MIN_INTERVAL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Sprawdza konfigurację przed zapisem.
	 *
	 * @param array<string, mixed> $config Konfiguracja.
	 * @return string[] Czytelne błędy; pusta lista = poprawna.
	 */
	public static function validate_config( array $config ): array {
		$errors = array();
		$fields = is_array( $config['fields'] ?? null ) ? $config['fields'] : array();

		foreach ( PV_Reading::CORE_FIELDS as $field ) {
			if ( ! isset( $fields[ $field ] ) ) {
				/* translators: %s: field label */
				$errors[] = sprintf( __( '%s is required.', 'veloxa-solar-widget' ), self::field_label( $field ) );
			}
		}

		foreach ( $fields as $field => $mapping ) {
			if ( ! in_array( (string) ( $mapping['unit'] ?? '' ), Unit_Converter::units_for( (string) $field ), true ) ) {
				/* translators: %s: field label or key */
				$errors[] = sprintf( __( '%s: choose a unit.', 'veloxa-solar-widget' ), self::field_label( (string) $field ) );
			}
		}

		return $errors;
	}

	/**
	 * Składa odczyt z ostatnich wartości encji kanału i zapisuje go
	 * (Reading_Store + historia). Wołane po każdym przyjętym żądaniu ingest.
	 *
	 * Jednostka: podana przez urządzenie w żądaniu ("unit"), jeśli jest
	 * poprawna dla pola — inaczej jednostka z konfiguracji kanału. Jawna
	 * jednostka w danych jest pewniejsza niż założenie z konfiguracji.
	 *
	 * Czas pomiaru: czas odbioru przez stronę z flagą "przybliżony" (brief
	 * sekcja 4 — push nie podaje czasu pomiaru). Przy kilku encjach
	 * wysyłanych osobno — najstarszy z czasów pól rdzenia, żeby odczyt nie
	 * wyglądał na świeższy niż jego najstarsza część.
	 *
	 * @param Push_Channel_Manager $channels Stan encji kanałów.
	 * @param int                  $now      Bieżący czas (unix, UTC).
	 * @return PV_Reading|null Null, gdy brakuje jeszcze któregoś pola rdzenia.
	 */
	public function sync( Push_Channel_Manager $channels, int $now ): ?PV_Reading {
		$values  = array();
		$missing = array();
		$times   = array();

		foreach ( $this->mapped_fields() as $field => $mapping ) {
			$state   = $channels->get_entity_state( $this->connection_id(), $field );
			$is_core = in_array( $field, PV_Reading::CORE_FIELDS, true );
			$value   = null;

			if ( null !== $state && null !== $state['last_recorded_at'] && null === $state['last_value_txt'] && null !== $state['last_value_num'] ) {
				$unit  = Unit_Converter::canonical_unit( $field, (string) $state['unit'] ) ?? (string) ( $mapping['unit'] ?? '' );
				$value = Unit_Converter::to_internal( $field, $state['last_value_num'], $unit );
			}

			if ( null === $value ) {
				if ( $is_core ) {
					$missing[] = $field;
				}
				continue;
			}

			if ( ! empty( $mapping['invert'] ) && in_array( $field, self::SIGNED_FIELDS, true ) ) {
				$value = -$value;
			}

			$values[ $field ] = $value;

			if ( $is_core ) {
				$times[] = (int) strtotime( $state['last_recorded_at'] . ' UTC' );
			}
		}

		// Pole rdzenia nieobecne w mapowaniu (np. kanał sprzed Fazy 3, bez
		// konfiguracji pól) też jest brakiem — bez tego min() niżej dostałby
		// pustą tablicę.
		$missing = array_values( array_unique( array_merge( $missing, array_diff( PV_Reading::CORE_FIELDS, array_keys( $this->mapped_fields() ) ) ) ) );

		if ( ! empty( $missing ) ) {
			Reading_Store::save_error(
				$this->connection_id(),
				(string) \Veloxa\Solar\Core\Messages::render( \Veloxa\Solar\Core\Messages::WAITING_FOR, $missing ),
				$now,
				\Veloxa\Solar\Core\Messages::WAITING_FOR,
				$missing
			);

			return null;
		}

		$reading = $this->finalize_reading( new PV_Reading( $values, min( $times ), true, $now ) );

		Reading_Store::save( $this->connection_id(), $reading );
		History_Store::record( $this->connection_id(), $reading, self::HISTORY_MIN_INTERVAL );
		\Veloxa\Solar\Model\Daily_Store::refresh_for( $this->connection_id(), $reading->updated_at(), 3 * $this->refresh_interval(), $now );
		// Szczyt dnia z każdego odczytu, nie tylko z tych, które trafiły do
		// historii (ta jest przerzedzana do jednego wiersza na 5 minut).
		\Veloxa\Solar\Model\Daily_Store::raise_peak( $this->connection_id(), wp_date( 'Y-m-d', $reading->updated_at() ), (int) round( max( 0.0, (float) $reading->get( 'current_power' ) ) ), $now );

		return $reading;
	}

	/**
	 * Przykładowe ciało żądania dla tego kanału — do pokazania w adminie
	 * (wartości przykładowe, jednostki z konfiguracji).
	 *
	 * @return array{readings: array<int, array{entity: string, value: float, unit: string}>}
	 */
	public function example_payload(): array {
		$examples = array(
			'current_power' => array(
				'W'  => 4820,
				'kW' => 4.82,
				'MW' => 0.00482,
			),
			'energy_today'  => array(
				'Wh'  => 24800,
				'kWh' => 24.8,
				'MWh' => 0.0248,
			),
			'energy_total'  => array(
				'Wh'  => 38400000,
				'kWh' => 38400,
				'MWh' => 38.4,
			),
			'battery_soc'   => array( '%' => 87 ),
			'battery_power' => array(
				'W'  => 1500,
				'kW' => 1.5,
				'MW' => 0.0015,
			),
			'grid_power'    => array(
				'W'  => -2100,
				'kW' => -2.1,
				'MW' => -0.0021,
			),
			'home_power'    => array(
				'W'  => 1220,
				'kW' => 1.22,
				'MW' => 0.00122,
			),
		);
		$readings = array();

		foreach ( $this->mapped_fields() as $field => $mapping ) {
			$unit       = (string) ( $mapping['unit'] ?? '' );
			$readings[] = array(
				'entity' => $field,
				'value'  => $examples[ $field ][ $unit ] ?? 0,
				'unit'   => $unit,
			);
		}

		return array( 'readings' => $readings );
	}
}
