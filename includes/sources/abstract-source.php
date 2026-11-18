<?php
/**
 * Wspólna baza źródeł danych PV (Generic REST, push, Home Assistant).
 * Każde źródło zapisuje znormalizowany odczyt w Reading_Store, a ścieżka
 * renderowania czyta WYŁĄCZNIE stamtąd (brief sekcja 7) — ta klasa
 * dostarcza tę wspólną część raz, zamiast w każdym źródle osobno.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Sources;

use Veloxa\Solar\Core\Connectors\Abstract_Connector;
use Veloxa\Solar\Core\Diagnostics_Logger;
use Veloxa\Solar\Model\PV_Reading;
use Veloxa\Solar\Model\Reading_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Abstract_Source
 */
abstract class Abstract_Source extends Abstract_Connector {

	/**
	 * Pola ze znakiem — źródła różnią się konwencją, więc mapowanie pozwala
	 * odwrócić znak do konwencji modelu (PV_Reading::FIELD_UNITS).
	 */
	const SIGNED_FIELDS = array( 'battery_power', 'grid_power', 'home_power' );

	/**
	 * Domyślny próg bezczynności baterii (W) — docs/etap-1.md, 1.7.
	 */
	const DEFAULT_BATTERY_IDLE_WATTS = 50;

	/**
	 * Minimalny interwał odświeżania — chmury producentów aktualizują dane
	 * co ~5 min (SolaX) do ~15 min (SolarEdge), brief sekcja 1; częściej
	 * nie ma sensu i zużywa limity API.
	 */
	const MIN_REFRESH_MINUTES = 5;

	/**
	 * Ile sekund w przyszłość może wskazywać czas pomiaru ze źródła
	 * (rozjazd zegarów). Więcej = najpewniej zła strefa czasowa — taki
	 * odczyt wyglądałby na świeży przez godziny i psuł stan "dane
	 * nieaktualne" (brief sekcja 5).
	 */
	const MAX_FUTURE_SKEW_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * Konfiguracja połączenia (sekrety już odszyfrowane przez Connector_Manager).
	 *
	 * @var array<string, mixed>
	 */
	protected $config;

	/**
	 * Konstruktor.
	 *
	 * @param array<string, mixed> $config Konfiguracja połączenia.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Interwał, z jakim źródło powinno dostarczać dane (sekundy) — podstawa
	 * stanu "dane nieaktualne" (3× ten interwał, brief sekcja 5).
	 *
	 * @return int
	 */
	public function refresh_interval(): int {
		return max( self::MIN_REFRESH_MINUTES, (int) ( $this->config['refresh_minutes'] ?? self::MIN_REFRESH_MINUTES ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Mapowanie pól PV tego połączenia: pole => ustawienia mapowania.
	 * Tylko pola obecne w PV_Reading::FIELD_UNITS.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function mapped_fields(): array {
		$fields = is_array( $this->config['fields'] ?? null ) ? $this->config['fields'] : array();

		return array_intersect_key( $fields, PV_Reading::FIELD_UNITS );
	}

	/**
	 * Czy źródło jest „beta” — zbudowane z dokumentacji producenta, jeszcze
	 * niepotwierdzone na prawdziwej instalacji (kontrole Sanity_Checks).
	 *
	 * @return bool
	 */
	public function is_beta(): bool {
		return ! empty( $this->config['beta'] );
	}

	/**
	 * Czy połączenie ma włączony szacunek zużycia domu (tryb awaryjny,
	 * domyślnie wyłączony — docs/etap-1.md, 1.5).
	 *
	 * @return bool
	 */
	public function estimates_home_power(): bool {
		return ! empty( $this->config['estimate_home_power'] );
	}

	/**
	 * Odczyt gotowy do zapisu: z szacunkiem zużycia domu tylko wtedy, gdy
	 * połączenie go włącza.
	 *
	 * @param PV_Reading $reading Odczyt ze źródła.
	 * @return PV_Reading
	 */
	public function finalize_reading( PV_Reading $reading ): PV_Reading {
		return $this->estimates_home_power() ? $reading->with_estimated_home_power() : $reading;
	}

	/**
	 * Próg bezczynności baterii (W) ustawiony w połączeniu.
	 *
	 * @return int
	 */
	public function battery_idle_watts(): int {
		return isset( $this->config['battery_idle_watts'] ) ? max( 0, (int) $this->config['battery_idle_watts'] ) : self::DEFAULT_BATTERY_IDLE_WATTS;
	}

	/**
	 * ID połączenia.
	 *
	 * @return string
	 */
	protected function connection_id(): string {
		return (string) ( $this->config['id'] ?? '' );
	}

	/**
	 * Czytelna etykieta pola PV.
	 *
	 * @param string $field Pole PV_Reading.
	 * @return string
	 */
	public static function field_label( string $field ): string {
		$labels = array(
			'current_power' => __( 'Current power', 'veloxa-solar-widget' ),
			'energy_today'  => __( 'Energy today', 'veloxa-solar-widget' ),
			'energy_total'  => __( 'Total energy', 'veloxa-solar-widget' ),
			'battery_soc'   => __( 'Battery charge', 'veloxa-solar-widget' ),
			'battery_power' => __( 'Battery power', 'veloxa-solar-widget' ),
			'grid_power'    => __( 'Grid power', 'veloxa-solar-widget' ),
			'home_power'    => __( 'Home consumption', 'veloxa-solar-widget' ),
		);

		return $labels[ $field ] ?? $field;
	}

	/**
	 * Odrzuca czas pomiaru leżący za daleko w przyszłości (patrz
	 * MAX_FUTURE_SKEW_SECONDS) — z wpisem w diagnostyce, żeby admin wiedział,
	 * czemu czas jest oznaczony jako przybliżony.
	 *
	 * @param int|null $updated_at Czas pomiaru ze źródła (unix, UTC) albo null.
	 * @param int      $now        Bieżący czas (unix, UTC).
	 * @return int|null Null = użyj czasu pobrania/odbioru z flagą "przybliżony".
	 */
	protected function accept_source_time( ?int $updated_at, int $now ): ?int {
		if ( null === $updated_at || $updated_at <= $now + self::MAX_FUTURE_SKEW_SECONDS ) {
			return $updated_at;
		}

		Diagnostics_Logger::log_error(
			'connection:' . $this->connection_id(),
			sprintf(
				/* translators: %d: minutes */
				__( 'The source measurement time is %d minutes in the future — check the timezone setting of the source. Using the fetch time instead.', 'veloxa-solar-widget' ),
				(int) round( ( $updated_at - $now ) / MINUTE_IN_SECONDS )
			)
		);

		return null;
	}

	/**
	 * Wartość pola z ostatniego zapisanego odczytu — ścieżka renderowania,
	 * bez żadnego żądania sieciowego.
	 *
	 * @param string $entity_id Pole PV_Reading (np. "current_power").
	 * @return array{value: mixed, unit: string|null, label: string|null, last_updated: string|null, error: string|null, display_value: string|null, updated_at_approximate?: bool, stale?: bool}
	 */
	public function fetch_data( string $entity_id ): array {
		$reading = Reading_Store::get( $this->connection_id() );

		if ( null === $reading ) {
			$error = Reading_Store::get_last_error( $this->connection_id() );

			return $this->error_response( $error ?? __( 'No data yet — the first reading has not arrived.', 'veloxa-solar-widget' ) );
		}

		if ( ! $reading->has( $entity_id ) ) {
			/* translators: %s: field label */
			return $this->error_response( sprintf( __( 'This data source does not provide "%s".', 'veloxa-solar-widget' ), self::field_label( $entity_id ) ) );
		}

		return array(
			'value'                  => $reading->get( $entity_id ),
			'unit'                   => PV_Reading::FIELD_UNITS[ $entity_id ],
			'label'                  => self::field_label( $entity_id ),
			'last_updated'           => gmdate( 'c', $reading->updated_at() ),
			'error'                  => null,
			'display_value'          => null,
			'updated_at_approximate' => $reading->is_updated_at_approximate(),
			'stale'                  => $reading->is_stale( $this->refresh_interval(), time() ),
		);
	}

	/**
	 * Pola zmapowane w konfiguracji — tylko te widget może zaoferować
	 * (brief: UI nie sugeruje danych, których źródło nie dostarcza).
	 *
	 * @return array<int, array{id: string, label: string, value_type: string}>
	 */
	public function list_entities(): array {
		$mapped   = $this->mapped_fields();
		$entities = array();

		// Szacunek zużycia domu (jeśli włączony i możliwy) — widget przepływu
		// energii może go wtedy użyć, z oznaczeniem szacunku.
		if ( ! isset( $mapped['home_power'] ) && $this->estimates_home_power() && PV_Reading::can_estimate_home_power( array_keys( $mapped ) ) ) {
			$mapped['home_power'] = array();
		}

		foreach ( array_keys( PV_Reading::FIELD_UNITS ) as $field ) {
			if ( isset( $mapped[ $field ] ) ) {
				$entities[] = array(
					'id'         => $field,
					'label'      => self::field_label( $field ),
					'value_type' => 'numeric',
				);
			}
		}

		return $entities;
	}
}
