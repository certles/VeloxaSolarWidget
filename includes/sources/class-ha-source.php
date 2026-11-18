<?php
/**
 * Źródło Home Assistant (REST API, Long-Lived Access Token). Wzorowane na
 * IOT_DW_HA_Connector (IoT Data Widget, commit b10c54c) — te same
 * endpointy (/api/states, /api/states/{id}) i nagłówek Bearer — ale nie
 * kopia: IoT czyta jedną encję na widget przy renderowaniu, tu jedno
 * odświeżenie w tle składa cały odczyt PV, a jednostkę bierze z
 * unit_of_measurement encji (bez ręcznego wyboru).
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Sources;

use Veloxa\Solar\Core\Http_Client;
use Veloxa\Solar\Model\PV_Reading;
use Veloxa\Solar\Model\Unit_Converter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ha_Source
 *
 * Konfiguracja połączenia:
 * - url — adres instancji HA osiągalny z serwera WWW (zwykle zdalny —
 *   Nabu Casa / reverse proxy; adresy lokalne blokuje Http_Client),
 * - token — Long-Lived Access Token (sekret),
 * - fields — pole PV => {entity, invert?},
 * - refresh_minutes — interwał odświeżania.
 */
class Ha_Source extends Abstract_Source implements Polled_Source {

	/**
	 * Typ konektora w Connector_Manager.
	 */
	const CONNECTOR_TYPE = 'ha';

	/**
	 * Klasa urządzenia HA (device_class) dopasowywana do pola PV. Dobór czujników po
	 * device_class + unit_of_measurement, NIE po nazwach encji (brief
	 * sekcja 3.3) — nazwy są dowolne i w każdym języku inne.
	 */
	const DEVICE_CLASS_FOR_FIELD = array(
		'current_power' => 'power',
		'energy_today'  => 'energy',
		'energy_total'  => 'energy',
		'battery_soc'   => 'battery',
		'battery_power' => 'power',
		'grid_power'    => 'power',
		'home_power'    => 'power',
	);

	/**
	 * Presety czujników dla znanych integracji HA — końcówki entity_id per
	 * pole (decyzja właściciela 2026-09-30, opcja A). To tylko PODPOWIEDŹ
	 * wstępnie zaznaczona w kreatorze: kandydaci nadal pochodzą wyłącznie z
	 * doboru po device_class + jednostce (brief 3.3), a nazwy służą jedynie
	 * do wskazania, który z pasujących czujników jest którym polem — samo
	 * device_class tego nie rozstrzyga (na prawdziwej instalacji każde pole
	 * mocy ma tę samą listę; tests/fixtures/ha-states-sofar-2026-09-30.json).
	 *
	 * 'anchor' — pola, które MUSZĄ pasować, żeby rozpoznać integrację; z
	 * nich brany jest wspólny prefiks encji (np. "sensor.inverter"), a
	 * pozostałe pola dobierane są wyłącznie jako prefiks + końcówka.
	 */
	const PRESETS = array(
		'solarman' => array(
			'label'    => 'Solarman / Sofar',
			'anchor'   => array( 'current_power', 'energy_today' ),
			'suffixes' => array(
				'current_power' => '_pv_power',
				'energy_today'  => '_today_production',
				'energy_total'  => '_total_production',
				'battery_soc'   => '_battery',
				'battery_power' => '_battery_power',
				'grid_power'    => '_activepower_pcc_total',
				'home_power'    => '_activepower_load_sys',
			),
		),
	);

	/**
	 * Podpowiedź mapowania z presetów dla listy kandydatów. Null, gdy żaden
	 * preset nie pasuje albo pasuje niejednoznacznie (np. dwa falowniki —
	 * wtedy lepiej nie zgadywać).
	 *
	 * @param array<string, array<int, array{id: string}>> $candidates Wynik list_candidates().
	 * @return array{preset: string, fields: array<string, string>}|null
	 */
	public static function suggest_mapping( array $candidates ): ?array {
		foreach ( self::PRESETS as $preset ) {
			$prefixes = null;

			foreach ( $preset['anchor'] as $field ) {
				$suffix = $preset['suffixes'][ $field ];
				$found  = array();

				foreach ( $candidates[ $field ] ?? array() as $candidate ) {
					if ( substr( $candidate['id'], -strlen( $suffix ) ) === $suffix ) {
						$found[] = substr( $candidate['id'], 0, -strlen( $suffix ) );
					}
				}

				$prefixes = null === $prefixes ? $found : array_values( array_intersect( $prefixes, $found ) );
			}

			if ( 1 !== count( (array) $prefixes ) ) {
				continue;
			}

			$fields = array();

			foreach ( $preset['suffixes'] as $field => $suffix ) {
				$entity = $prefixes[0] . $suffix;

				if ( in_array( $entity, wp_list_pluck( $candidates[ $field ] ?? array(), 'id' ), true ) ) {
					$fields[ $field ] = $entity;
				}
			}

			return array(
				'preset' => $preset['label'],
				'fields' => $fields,
			);
		}

		return null;
	}

	/**
	 * Podpowiedź „beta” po nazwach czujników (decyzja właściciela 2026-10-11:
	 * integracje z dokumentacji/kodu źródłowego jako beta). Słowa kluczowe
	 * z nazw czujników integracji HA — Huawei Solar (input_power,
	 * daily_yield, total_yield, batteries_state_of_capacity,
	 * batteries_charge_discharge_power, power_meter_active_power), Fronius
	 * (power_photovoltaics, power_load, power_grid, power_battery,
	 * state_of_charge, energy_day), Enphase (current_power_production,
	 * daily_production, lifetime_production, current_power_consumption),
	 * GoodWe (ppv, e_day, e_total, house_consumption, battery_soc), SMA,
	 * SolarEdge — oraz polskie i niemieckie odpowiedniki (HA tworzy entity_id
	 * z nazwy w języku instalacji, np. „…_moc”). Słowo ujemne wyklucza pole.
	 * Kandydaci nadal tylko z doboru po device_class + jednostce; znak sieci
	 * i baterii pilnuje kontrola bilansu (przepływ energii).
	 */
	const NAME_KEYWORDS = array(
		'current_power' => array(
			'input_power'              => 6,
			'power_photovoltaics'      => 6,
			'current_power_production' => 6,
			'pv_power'                 => 6,
			'ppv'                      => 5,
			'solar_power'              => 5,
			'photovolta'               => 3,
			'fotowolt'                 => 3,
			'solar'                    => 2,
			'pv'                       => 2,
			'production'               => 1,
			'produkcja'                => 1,
			'erzeugung'                => 2,
			'battery'                  => -9,
			'bateri'                   => -9,
			'grid'                     => -9,
			'meter'                    => -9,
			'load'                     => -9,
			'consumption'              => -9,
			'house'                    => -9,
			'siec'                     => -9,
			'sieć'                     => -9,
			'zuzycie'                  => -9,
			'zużycie'                  => -9,
		),
		'energy_today'  => array(
			'daily_yield'             => 6,
			'energy_day'              => 6,
			'daily_production'        => 6,
			'energy_production_today' => 6,
			'today_production'        => 6,
			'e_day'                   => 5,
			'today'                   => 3,
			'daily'                   => 3,
			'dzis'                    => 3,
			'dziś'                    => 3,
			'dzienn'                  => 3,
			'heute'                   => 3,
			'total'                   => -9,
			'lifetime'                => -9,
			'consum'                  => -9,
			'import'                  => -9,
			'export'                  => -9,
			'battery'                 => -9,
			'bateri'                  => -9,
			'load'                    => -9,
			'grid'                    => -9,
		),
		'energy_total'  => array(
			'total_yield'         => 6,
			'lifetime_production' => 6,
			'lifetime_energy'     => 6,
			'total_production'    => 6,
			'e_total'             => 5,
			'energy_total'        => 4,
			'total'               => 3,
			'lifetime'            => 3,
			'calk'                => 3,
			'całk'                => 3,
			'gesamt'              => 3,
			'today'               => -9,
			'daily'               => -9,
			'consum'              => -9,
			'import'              => -9,
			'export'              => -9,
			'battery'             => -9,
			'bateri'              => -9,
			'load'                => -9,
			'grid'                => -9,
		),
		'battery_soc'   => array(
			'state_of_capacity' => 6,
			'state_of_charge'   => 6,
			'battery_soc'       => 6,
			'soc'               => 4,
			'battery'           => 2,
			'bateri'            => 2,
			'akumul'            => 2,
			'speicher'          => 2,
			'phone'             => -9,
			'ipad'              => -9,
			'tablet'            => -9,
			'watch'             => -9,
		),
		'battery_power' => array(
			'charge_discharge_power' => 6,
			'battery_power'          => 6,
			'power_battery'          => 6,
			'pbattery'               => 5,
			'battery'                => 3,
			'bateri'                 => 3,
			'akumul'                 => 3,
			'speicher'               => 3,
			'grid'                   => -9,
			'load'                   => -9,
		),
		'grid_power'    => array(
			'power_meter_active_power' => 6,
			'power_grid'               => 6,
			'grid_power'               => 6,
			'net_consumption'          => 5,
			'pcc'                      => 4,
			'grid'                     => 3,
			'meter'                    => 2,
			'siec'                     => 3,
			'sieć'                     => 3,
			'netz'                     => 3,
			'battery'                  => -9,
			'bateri'                   => -9,
			'photovolta'               => -9,
			'pv_power'                 => -9,
		),
		'home_power'    => array(
			'house_consumption'         => 6,
			'power_load'                => 6,
			'current_power_consumption' => 6,
			'load_power'                => 5,
			'load_sys'                  => 5,
			'consumption'               => 3,
			'load'                      => 3,
			'house'                     => 3,
			'home'                      => 3,
			'dom'                       => 2,
			'zuzycie'                   => 3,
			'zużycie'                   => 3,
			'verbrauch'                 => 3,
			'net_consumption'           => -9,
			'battery'                   => -9,
			'bateri'                    => -9,
			'grid'                      => -9,
			'pv'                        => -9,
		),
	);

	/**
	 * Najmniejszy wynik podpowiedzi po nazwie.
	 */
	const NAME_MIN_SCORE = 3;

	/**
	 * Podpowiedź po nazwach czujników (beta) — dla integracji bez presetu.
	 * Pole dostaje czujnik o najwyższym wyniku; remis albo wynik poniżej
	 * NAME_MIN_SCORE = brak podpowiedzi dla pola (np. dwa falowniki). Null,
	 * gdy nie ma podpowiedzi mocy produkcji.
	 *
	 * @param array<string, array<int, array{id: string, label: string}>> $candidates Wynik list_candidates().
	 * @return array{preset: string, fields: array<string, string>, beta: bool}|null
	 */
	public static function suggest_by_name( array $candidates ): ?array {
		$fields = array();
		$used   = array();

		foreach ( self::NAME_KEYWORDS as $field => $keywords ) {
			$scores = array();

			foreach ( $candidates[ $field ] ?? array() as $candidate ) {
				if ( isset( $used[ $candidate['id'] ] ) ) {
					continue;
				}

				$text  = mb_strtolower( $candidate['id'] . ' ' . ( $candidate['label'] ?? '' ) );
				$score = 0;

				foreach ( $keywords as $keyword => $points ) {
					if ( false !== mb_strpos( $text, $keyword ) ) {
						if ( $points < 0 ) {
							$score = PHP_INT_MIN;
							break;
						}
						$score += $points;
					}
				}

				$scores[ $candidate['id'] ] = $score;
			}

			if ( empty( $scores ) ) {
				continue;
			}

			arsort( $scores );
			$top   = array_slice( $scores, 0, 2, true );
			$ids   = array_keys( $top );
			$best  = $top[ $ids[0] ];
			$runup = isset( $ids[1] ) ? $top[ $ids[1] ] : PHP_INT_MIN;

			if ( $best >= self::NAME_MIN_SCORE && $best > $runup ) {
				$fields[ $field ] = $ids[0];
				$used[ $ids[0] ]  = true;
			}
		}

		return isset( $fields['current_power'] ) ? array(
			'preset' => __( 'suggested by sensor names (beta)', 'veloxa-solar-widget' ),
			'fields' => $fields,
			'beta'   => true,
		) : null;
	}

	/**
	 * Wzorzec entity_id HA ("domena.nazwa").
	 */
	const ENTITY_ID_PATTERN = '/^[a-z0-9_]+\.[a-z0-9_]+$/';

	/**
	 * Sprawdza konfigurację przed zapisem.
	 *
	 * @param array<string, mixed> $config        Konfiguracja.
	 * @param bool                 $require_fields Czy wymagać mapowania rdzenia (krok 2 kreatora); w kroku 1 jeszcze go nie ma.
	 * @return string[] Czytelne błędy; pusta lista = poprawna.
	 */
	public static function validate_config( array $config, bool $require_fields = true ): array {
		$errors = array();
		$url    = (string) ( $config['url'] ?? '' );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( '' === (string) wp_parse_url( $url, PHP_URL_HOST ) || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			$errors[] = __( 'Enter the full Home Assistant address, e.g. https://example.ui.nabu.casa', 'veloxa-solar-widget' );
		}

		if ( '' === trim( (string) ( $config['token'] ?? '' ) ) ) {
			$errors[] = __( 'A Home Assistant access token is required.', 'veloxa-solar-widget' );
		}

		if ( ! $require_fields ) {
			return $errors;
		}

		$fields = is_array( $config['fields'] ?? null ) ? $config['fields'] : array();

		foreach ( PV_Reading::CORE_FIELDS as $field ) {
			if ( '' === (string) ( $fields[ $field ]['entity'] ?? '' ) ) {
				/* translators: %s: field label */
				$errors[] = sprintf( __( '%s: choose a sensor.', 'veloxa-solar-widget' ), self::field_label( $field ) );
			}
		}

		foreach ( $fields as $field => $mapping ) {
			$entity = (string) ( $mapping['entity'] ?? '' );

			if ( ! isset( PV_Reading::FIELD_UNITS[ $field ] ) || ( '' !== $entity && ! preg_match( self::ENTITY_ID_PATTERN, $entity ) ) ) {
				/* translators: %s: field key */
				$errors[] = sprintf( __( 'Invalid sensor for "%s".', 'veloxa-solar-widget' ), (string) $field );
			}
		}

		return $errors;
	}

	/**
	 * Wykonuje GET do API HA i zwraca zdekodowany JSON.
	 *
	 * @param string $path Ścieżka API, np. "/api/states".
	 * @return array<mixed>|\WP_Error
	 */
	private function request( string $path ) {
		$response = Http_Client::get(
			untrailingslashit( (string) ( $this->config['url'] ?? '' ) ) . $path,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . (string) ( $this->config['token'] ?? '' ),
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $status || 403 === $status ) {
			return new \WP_Error( 'veloxa_solar_auth_failed', __( 'Invalid or expired Home Assistant access token.', 'veloxa-solar-widget' ) );
		}

		if ( 404 === $status ) {
			return new \WP_Error( 'veloxa_solar_not_found', __( 'Not found in Home Assistant — the sensor may have been renamed or removed.', 'veloxa-solar-widget' ) );
		}

		if ( 200 !== $status ) {
			/* translators: %d: HTTP status code */
			return new \WP_Error( 'veloxa_solar_http_status', sprintf( __( 'Home Assistant returned an unexpected response code: %d.', 'veloxa-solar-widget' ), $status ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'veloxa_solar_invalid_json', __( 'Invalid response from Home Assistant.', 'veloxa-solar-widget' ) );
		}

		return $body;
	}

	/**
	 * Historia zmapowanych pól z API historii HA (/api/history/period —
	 * stany z recordera, domyślnie 10 dni) w jednostkach wewnętrznych,
	 * z odwróceniem znaku jak w fetch_reading(). Punkty rosnąco; stany
	 * nieliczbowe (unavailable/unknown) pominięte. Pierwszy punkt każdej
	 * encji to stan obowiązujący na początku okresu.
	 *
	 * @param string[] $fields Pola PV (muszą być zmapowane).
	 * @param int      $from   Początek (unix UTC).
	 * @param int      $to     Koniec (unix UTC).
	 * @return array<string, array<int, array{0: int, 1: float}>>|\WP_Error Pole => [[czas, wartość], …].
	 */
	public function history( array $fields, int $from, int $to ) {
		$mapping = $this->mapped_fields();
		$by_id   = array();

		foreach ( $fields as $field ) {
			$entity = (string) ( $mapping[ $field ]['entity'] ?? '' );

			if ( preg_match( self::ENTITY_ID_PATTERN, $entity ) ) {
				$by_id[ $entity ][] = $field;
			}
		}

		if ( empty( $by_id ) ) {
			return array();
		}

		$data = $this->request(
			'/api/history/period/' . rawurlencode( gmdate( 'Y-m-d\TH:i:s\Z', $from ) )
			. '?end_time=' . rawurlencode( gmdate( 'Y-m-d\TH:i:s\Z', $to ) )
			. '&filter_entity_id=' . rawurlencode( implode( ',', array_keys( $by_id ) ) )
			. '&minimal_response'
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$result = array_fill_keys( $fields, array() );

		foreach ( $data as $series ) {
			if ( ! is_array( $series ) || ! isset( $series[0]['entity_id'] ) || ! isset( $by_id[ $series[0]['entity_id'] ] ) ) {
				continue;
			}

			$unit = (string) ( $series[0]['attributes']['unit_of_measurement'] ?? '' );

			foreach ( $by_id[ $series[0]['entity_id'] ] as $field ) {
				foreach ( $series as $point ) {
					$at = strtotime( (string) ( $point['last_changed'] ?? $point['last_updated'] ?? '' ) );

					try {
						$value = false === $at ? null : Unit_Converter::to_internal( $field, $point['state'] ?? null, $unit );
					} catch ( \InvalidArgumentException $e ) {
						$value = null;
					}

					if ( null === $value ) {
						continue;
					}

					if ( ! empty( $mapping[ $field ]['invert'] ) && in_array( $field, self::SIGNED_FIELDS, true ) ) {
						$value = -$value;
					}

					$result[ $field ][] = array( (int) $at, $value );
				}

				usort( $result[ $field ], static fn( array $a, array $b ): int => $a[0] <=> $b[0] );
			}
		}

		return $result;
	}

	/**
	 * Eksport czujników do zgłoszenia wsparcia (0.6): stany wszystkich
	 * czujników mocy, energii i baterii — z nich dopisujemy podpowiedzi
	 * czujników dla kolejnych falowników (PRESETS). Tylko sensor.* z
	 * device_class power/energy/battery i tylko atrybuty jednostki, klasy
	 * i nazwy; bez adresu HA i tokenu.
	 *
	 * @return array<int, array{entity_id: string, state: string, attributes: array<string, string>}>|\WP_Error
	 */
	public function export_states() {
		$states = $this->request( '/api/states' );

		return is_wp_error( $states ) ? $states : self::filter_export( $states );
	}

	/**
	 * Filtr eksportu (testowalny bez HTTP).
	 *
	 * @param array<int, mixed> $states Odpowiedź /api/states.
	 * @return array<int, array{entity_id: string, state: string, attributes: array<string, string>}>
	 */
	public static function filter_export( array $states ): array {
		$result = array();
		$device = static fn( string $entity_id ): string => (string) strtok( substr( $entity_id, strlen( 'sensor.' ) ), '_' );

		// Baterie tylko urządzeń, które mają też czujnik mocy/energii (np.
		// sensor.inverter_battery obok sensor.inverter_pv_power) — baterie
		// telefonów i czujników Zigbee mają w nazwach imiona domowników
		// (sprawdzone na prawdziwym HA 2026-10-11) i nie są potrzebne.
		$energy_devices = array();

		foreach ( $states as $state ) {
			$entity_id = (string) ( $state['entity_id'] ?? '' );

			if ( 0 === strpos( $entity_id, 'sensor.' ) && in_array( $state['attributes']['device_class'] ?? '', array( 'power', 'energy' ), true ) ) {
				$energy_devices[ $device( $entity_id ) ] = true;
			}
		}

		foreach ( $states as $state ) {
			$entity_id  = (string) ( $state['entity_id'] ?? '' );
			$attributes = is_array( $state['attributes'] ?? null ) ? $state['attributes'] : array();
			$class      = $attributes['device_class'] ?? '';

			if ( 0 !== strpos( $entity_id, 'sensor.' ) || ! in_array( $class, array( 'power', 'energy', 'battery' ), true ) ) {
				continue;
			}

			if ( 'battery' === $class && ! isset( $energy_devices[ $device( $entity_id ) ] ) ) {
				continue;
			}

			$result[] = array(
				'entity_id'  => $entity_id,
				'state'      => (string) ( $state['state'] ?? '' ),
				'attributes' => array_map( 'strval', array_intersect_key( $attributes, array_flip( array( 'unit_of_measurement', 'device_class', 'state_class', 'friendly_name' ) ) ) ),
			);
		}

		return $result;
	}

	/**
	 * Czujniki HA pasujące do każdego pola PV — po device_class i jednostce
	 * akceptowanej dla pola. Pole bez kandydatów = źródło go nie dostarcza,
	 * kreator go nie zaoferuje.
	 *
	 * @return array<string, array<int, array{id: string, label: string, unit: string, state: string}>>|\WP_Error
	 */
	public function list_candidates() {
		$states = $this->request( '/api/states' );

		if ( is_wp_error( $states ) ) {
			return $states;
		}

		$candidates = array_fill_keys( array_keys( PV_Reading::FIELD_UNITS ), array() );

		foreach ( $states as $state ) {
			$entity_id  = (string) ( $state['entity_id'] ?? '' );
			$attributes = is_array( $state['attributes'] ?? null ) ? $state['attributes'] : array();

			// Tylko domena sensor — number.* / input_number.* mają ten sam
			// device_class i jednostkę, ale to nastawy sterujące (np.
			// "moc ładowania baterii" falownika), nie pomiary. Znalezione na
			// prawdziwej instalacji właściciela (2026-09-24).
			if ( ! preg_match( self::ENTITY_ID_PATTERN, $entity_id ) || 0 !== strpos( $entity_id, 'sensor.' ) ) {
				continue;
			}

			foreach ( self::DEVICE_CLASS_FOR_FIELD as $field => $device_class ) {
				$unit = (string) ( $attributes['unit_of_measurement'] ?? '' );

				if ( ( $attributes['device_class'] ?? '' ) === $device_class && null !== Unit_Converter::canonical_unit( $field, $unit ) ) {
					$candidates[ $field ][] = array(
						'id'    => $entity_id,
						'label' => (string) ( $attributes['friendly_name'] ?? $entity_id ),
						'unit'  => $unit,
						'state' => (string) ( $state['state'] ?? '' ),
					);
				}
			}
		}

		return $candidates;
	}

	/**
	 * Pobiera odczyt — jedno żądanie na zmapowany czujnik.
	 *
	 * Czas pomiaru: chwila pobrania, z flagą "przybliżony" — decyzja
	 * właściciela 2026-09-24. last_reported/last_updated z HA NIE są czasem
	 * pomiaru: wiele integracji nie zapisuje ponownie niezmienionej
	 * wartości, więc na prawdziwej instalacji last_reported mocy PV stał
	 * 3 h w miejscu (0 W wieczorem) i widget pokazywałby "dane
	 * nieaktualne" przy w pełni działającym systemie. Awarię HA sygnalizuje
	 * sam — stanem "unavailable" (wtedy odczyt się nie udaje, zostaje
	 * ostatnia wartość i z czasem robi się nieaktualna) albo brakiem
	 * odpowiedzi.
	 *
	 * @param int $now Bieżący czas (unix, UTC).
	 * @return PV_Reading|\WP_Error
	 */
	public function fetch_reading( int $now ) {
		$values   = array();
		$problems = array();
		$previous = \Veloxa\Solar\Model\History_Store::latest_recorded_at( $this->connection_id() );

		foreach ( $this->mapped_fields() as $field => $mapping ) {
			$entity  = (string) ( $mapping['entity'] ?? '' );
			$is_core = in_array( $field, PV_Reading::CORE_FIELDS, true );

			if ( '' === $entity ) {
				continue;
			}

			$state = $this->request( '/api/states/' . rawurlencode( $entity ) );

			if ( is_wp_error( $state ) ) {
				if ( in_array( $state->get_error_code(), array( 'veloxa_solar_auth_failed', 'veloxa_solar_ssrf_blocked', 'veloxa_solar_invalid_url' ), true ) ) {
					return $state;
				}

				if ( $is_core ) {
					$problems[] = $entity . ': ' . $state->get_error_message();
				}
				continue;
			}

			$unit = (string) ( $state['attributes']['unit_of_measurement'] ?? '' );

			try {
				$value = Unit_Converter::to_internal( $field, $state['state'] ?? null, $unit );
			} catch ( \InvalidArgumentException $e ) {
				/* translators: 1: entity id, 2: unit */
				$problems[] = sprintf( __( '%1$s: unit "%2$s" does not fit this field.', 'veloxa-solar-widget' ), $entity, $unit );
				continue;
			}

			if ( null === $value ) {
				if ( $is_core ) {
					/* translators: 1: entity id, 2: state, e.g. "unavailable" */
					$problems[] = sprintf( __( '%1$s is "%2$s" in Home Assistant.', 'veloxa-solar-widget' ), $entity, (string) ( $state['state'] ?? '' ) );
				}
				continue;
			}

			if ( ! empty( $mapping['invert'] ) && in_array( $field, self::SIGNED_FIELDS, true ) ) {
				$value = -$value;
			}

			$values[ $field ] = $value;
		}

		if ( ! empty( $problems ) ) {
			return new \WP_Error( 'veloxa_solar_mapping_failed', implode( ' ', $problems ) );
		}

		$reading = new PV_Reading( $values, $now, true, $now );

		try {
			Ha_History_Backfill::after_reading( $this->connection_id(), $this, $this->refresh_interval(), $previous, $now );
		} catch ( \Throwable $e ) {
			// Uzupełnianie historii nigdy nie psuje odczytu bieżącego.
			\Veloxa\Solar\Core\Diagnostics_Logger::log_error( 'connection:' . $this->connection_id(), 'Home Assistant history: ' . $e->getMessage() );
		}

		return $reading;
	}
}
