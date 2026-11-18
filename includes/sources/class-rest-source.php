<?php
/**
 * Źródło Generic REST/JSON — dowolne API zwracające JSON, mapowane polami
 * JSONPath na model PV_Reading. Wzorowane na IOT_DW_REST_Connector (IoT
 * Data Widget, commit b10c54c), ale nie kopia: IoT mapuje JEDNĄ ścieżkę na
 * JEDNĄ wartość widgetu i pobiera przy renderowaniu (z cache); tu jedno
 * zapytanie daje cały odczyt PV z jednostkami, a pobieranie odbywa się
 * wyłącznie w tle (Source_Refresher).
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Sources;

use Veloxa\Solar\Core\Http_Client;
use Veloxa\Solar\Core\Json_Path;
use Veloxa\Solar\Model\PV_Reading;
use Veloxa\Solar\Model\Unit_Converter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rest_Source
 *
 * Konfiguracja połączenia (opcja veloxa_solar_connections):
 * - url — pełny adres endpointu (sekret: może zawierać klucz w query stringu),
 * - token — opcjonalny nagłówek autoryzacji "Nazwa: wartość" (sekret),
 * - fields — pole PV => {path, unit, invert?}; rdzeń (PV_Reading::CORE_FIELDS)
 *   wymagany, pola opcjonalne tylko, jeśli źródło je ma,
 * - updated_at_path — opcjonalna ścieżka do czasu pomiaru,
 * - timezone — strefa dat bez przesunięcia ('' = strefa WordPressa),
 * - refresh_minutes — interwał odświeżania (min. MIN_REFRESH_MINUTES).
 *
 * fetch_data() (ścieżka renderowania) NIGDY nie wysyła żądania — czyta
 * Reading_Store. Żądanie wysyła tylko fetch_reading(), wołane z crona.
 */
class Rest_Source extends Abstract_Source implements Polled_Source {

	/**
	 * Typ konektora w Connector_Manager.
	 */
	const CONNECTOR_TYPE = 'rest';

	/**
	 * Preset SolaX Cloud (beta, 2026-10-11): oficjalna dokumentacja SolaX
	 * (SolaxCloud User Monitoring API) + szablon evcc „solax-hybrid-cloud”:
	 * moc PV = suma stringów DC (acpower w falowniku hybrydowym zawiera
	 * baterię), feedinpower dodatni = oddawanie (nasz model: pobór +, więc
	 * odwrócony), batPower dodatni = ładowanie (jak model). Zużycia domu
	 * w API nie ma — szacunek z bilansu (opcja połączenia, oznaczany „≈”).
	 * Limit SolaX: 10 zapytań/min, 10 000/dobę na token.
	 */
	const SOLAX_URL = 'https://www.solaxcloud.com/proxyApp/proxy/api/getRealtimeInfo.do';

	/**
	 * Ile dni trzymamy próbkę surowej odpowiedzi źródła beta.
	 */
	const SAMPLE_DAYS = 30;

	/**
	 * Konfiguracja połączenia SolaX Cloud (beta).
	 *
	 * @param string $name   Nazwa.
	 * @param string $token  tokenID z SolaxCloud (Service → API).
	 * @param string $serial Numer seryjny modułu Wi-Fi (registration No.).
	 * @return array<string, mixed>
	 */
	public static function solax_config( string $name, string $token, string $serial ): array {
		return array(
			'name'                => $name,
			'connector'           => self::CONNECTOR_TYPE,
			'url'                 => add_query_arg(
				array(
					'tokenId' => rawurlencode( $token ),
					'sn'      => rawurlencode( $serial ),
				),
				self::SOLAX_URL
			),
			'token'               => '',
			'fields'              => array(
				'current_power' => array(
					'path' => 'result.powerdc1+result.powerdc2+result.powerdc3+result.powerdc4',
					'unit' => 'W',
				),
				'energy_today'  => array(
					'path' => 'result.yieldtoday',
					'unit' => 'kWh',
				),
				'energy_total'  => array(
					'path' => 'result.yieldtotal',
					'unit' => 'kWh',
				),
				'battery_soc'   => array(
					'path' => 'result.soc',
					'unit' => '%',
				),
				'battery_power' => array(
					'path'   => 'result.batPower',
					'unit'   => 'W',
					'invert' => false,
				),
				'grid_power'    => array(
					'path'   => 'result.feedinpower',
					'unit'   => 'W',
					'invert' => true,
				),
			),
			'updated_at_path'     => 'result.uploadTime',
			'timezone'            => '',
			'estimate_home_power' => true,
			'refresh_minutes'     => 5,
			'beta'                => 'solax',
		);
	}

	/**
	 * Klucz transientu z próbką odpowiedzi.
	 *
	 * @param string $connection_id ID połączenia.
	 * @return string
	 */
	public static function sample_key( string $connection_id ): string {
		return 'veloxa_solar_rest_sample_' . md5( $connection_id );
	}

	/**
	 * Ostatnia próbka odpowiedzi źródła beta (wartości proste, spłaszczone
	 * do ścieżek) albo null.
	 *
	 * @param string $connection_id ID połączenia.
	 * @return array{fetched_at: int, fields: array<string, scalar|null>}|null
	 */
	public static function sample( string $connection_id ): ?array {
		$sample = get_transient( self::sample_key( $connection_id ) );

		return is_array( $sample ) ? $sample : null;
	}

	/**
	 * Spłaszcza odpowiedź do ścieżek z wartościami prostymi (maks. 200).
	 *
	 * @param array<mixed> $data   Odpowiedź.
	 * @param string       $prefix Prefiks ścieżki.
	 * @return array<string, scalar|null>
	 */
	public static function flatten( array $data, string $prefix = '' ): array {
		$flat = array();

		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( is_array( $value ) ) {
				$flat = array_merge( $flat, self::flatten( $value, $path ) );
			} elseif ( null === $value || is_scalar( $value ) ) {
				$flat[ $path ] = $value;
			}

			if ( count( $flat ) >= 200 ) {
				break;
			}
		}

		return array_slice( $flat, 0, 200, true );
	}

	/**
	 * Wartość spod ścieżki; „a+b+c” = suma liczb spod ścieżek (brakujące
	 * pominięte, np. string DC, którego falownik nie ma). Null, gdy żadna
	 * część nie dała liczby.
	 *
	 * @param array<mixed> $body Odpowiedź.
	 * @param string       $path Ścieżka.
	 * @return mixed
	 */
	public static function resolve_path( array $body, string $path ) {
		if ( false === strpos( $path, '+' ) ) {
			return Json_Path::resolve( $body, $path );
		}

		$sum   = 0.0;
		$found = false;

		foreach ( array_map( 'trim', explode( '+', $path ) ) as $part ) {
			$value = '' === $part ? null : Json_Path::resolve( $body, $part );

			if ( is_numeric( $value ) ) {
				$sum  += (float) $value;
				$found = true;
			}
		}

		return $found ? $sum : null;
	}

	/**
	 * Sprawdza konfigurację przed zapisem.
	 *
	 * @param array<string, mixed> $config Konfiguracja.
	 * @return string[] Czytelne błędy; pusta lista = poprawna.
	 */
	public static function validate_config( array $config ): array {
		$errors = array();
		$url    = (string) ( $config['url'] ?? '' );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( '' === (string) wp_parse_url( $url, PHP_URL_HOST ) || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			$errors[] = __( 'Enter a full http:// or https:// URL.', 'veloxa-solar-widget' );
		}

		$token = (string) ( $config['token'] ?? '' );

		if ( '' !== trim( $token ) && false === strpos( $token, ':' ) ) {
			$errors[] = __( 'The auth header must look like "Header-Name: value".', 'veloxa-solar-widget' );
		}

		$fields = is_array( $config['fields'] ?? null ) ? $config['fields'] : array();

		foreach ( PV_Reading::CORE_FIELDS as $field ) {
			if ( '' === trim( (string) ( $fields[ $field ]['path'] ?? '' ) ) ) {
				/* translators: %s: field label, e.g. "Current power" */
				$errors[] = sprintf( __( '%s: a JSON path is required.', 'veloxa-solar-widget' ), self::field_label( $field ) );
			}
		}

		foreach ( $fields as $field => $mapping ) {
			if ( ! isset( PV_Reading::FIELD_UNITS[ $field ] ) ) {
				/* translators: %s: field key */
				$errors[] = sprintf( __( 'Unknown field "%s".', 'veloxa-solar-widget' ), $field );
				continue;
			}

			if ( '' !== trim( (string) ( $mapping['path'] ?? '' ) ) && ! in_array( (string) ( $mapping['unit'] ?? '' ), Unit_Converter::units_for( $field ), true ) ) {
				/* translators: 1: field label, 2: allowed units, comma separated */
				$errors[] = sprintf( __( '%1$s: choose a unit (%2$s).', 'veloxa-solar-widget' ), self::field_label( $field ), implode( ', ', Unit_Converter::units_for( $field ) ) );
			}
		}

		$timezone = (string) ( $config['timezone'] ?? '' );

		if ( '' !== $timezone && ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
			/* translators: %s: timezone the user entered */
			$errors[] = sprintf( __( 'Unknown timezone "%s" — use a name like Europe/Warsaw, or leave empty for the site timezone.', 'veloxa-solar-widget' ), $timezone );
		}

		return $errors;
	}

	/**
	 * Pobiera odczyt ze źródła — JEDYNE miejsce tej klasy wysyłające żądanie.
	 * Wołane z Source_Refresher (cron) albo z przycisku "Refresh now" w
	 * adminie, nigdy z renderowania.
	 *
	 * @param int $now Bieżący czas (unix, UTC) — czas pobrania.
	 * @return PV_Reading|\WP_Error
	 */
	public function fetch_reading( int $now ) {
		$headers = array( 'Accept' => 'application/json' );
		$token   = trim( (string) ( $this->config['token'] ?? '' ) );

		if ( '' !== $token && false !== strpos( $token, ':' ) ) {
			list( $name, $value ) = array_map( 'trim', explode( ':', $token, 2 ) );
			$headers[ $name ]     = $value;
		}

		$response = Http_Client::get( (string) ( $this->config['url'] ?? '' ), array( 'headers' => $headers ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $status || 403 === $status ) {
			return new \WP_Error( 'veloxa_solar_auth_failed', __( 'Authorization failed — check the URL key or auth header in the connection settings.', 'veloxa-solar-widget' ) );
		}

		if ( 429 === $status ) {
			return new \WP_Error( 'veloxa_solar_rate_limited', __( 'The data source rate limit was reached — the last reading is kept and the next attempt happens on schedule.', 'veloxa-solar-widget' ) );
		}

		if ( 200 !== $status ) {
			/* translators: %d: HTTP status code */
			return new \WP_Error( 'veloxa_solar_http_status', sprintf( __( 'The server returned an unexpected response code: %d.', 'veloxa-solar-widget' ), $status ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'veloxa_solar_invalid_json', __( 'The server response is not valid JSON.', 'veloxa-solar-widget' ) );
		}

		// Źródło beta: surowa odpowiedź do porównania z aplikacją producenta
		// (ekran edycji połączenia, raport dla wsparcia).
		if ( ! empty( $this->config['beta'] ) ) {
			set_transient(
				self::sample_key( $this->connection_id() ),
				array(
					'fetched_at' => $now,
					'fields'     => self::flatten( $body ),
				),
				self::SAMPLE_DAYS * DAY_IN_SECONDS
			);
		}

		return $this->map_body( $body, $now );
	}

	/**
	 * Mapuje zdekodowaną odpowiedź na PV_Reading.
	 *
	 * Brak pola rdzenia (albo wartość nieliczbowa) = błąd całego odczytu —
	 * lepiej zostawić ostatni dobry odczyt niż zapisać niepełny. Brak pola
	 * opcjonalnego = pole pomijane w tym odczycie (np. bateria chwilowo
	 * niedostępna), nie błąd.
	 *
	 * @param array<mixed> $body Zdekodowany JSON.
	 * @param int          $now  Czas pobrania (unix, UTC).
	 * @return PV_Reading|\WP_Error
	 */
	public function map_body( array $body, int $now ) {
		$fields   = is_array( $this->config['fields'] ?? null ) ? $this->config['fields'] : array();
		$values   = array();
		$problems = array();

		foreach ( $fields as $field => $mapping ) {
			$path = trim( (string) ( $mapping['path'] ?? '' ) );

			if ( '' === $path || ! isset( PV_Reading::FIELD_UNITS[ $field ] ) ) {
				continue;
			}

			$raw = self::resolve_path( $body, $path );

			try {
				$value = null === $raw ? null : Unit_Converter::to_internal( $field, $raw, (string) ( $mapping['unit'] ?? '' ) );
			} catch ( \InvalidArgumentException $e ) {
				/* translators: %s: field label */
				$problems[] = sprintf( __( '%s: invalid unit in the connection settings.', 'veloxa-solar-widget' ), self::field_label( $field ) );
				continue;
			}

			if ( null === $value ) {
				if ( in_array( $field, PV_Reading::CORE_FIELDS, true ) ) {
					$problems[] = null === $raw
						/* translators: 1: field label, 2: JSON path */
						? sprintf( __( '%1$s: path "%2$s" was not found in the response.', 'veloxa-solar-widget' ), self::field_label( $field ), $path )
						/* translators: 1: field label, 2: JSON path */
						: sprintf( __( '%1$s: the value at "%2$s" is not a number.', 'veloxa-solar-widget' ), self::field_label( $field ), $path );
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

		$updated_at      = null;
		$updated_at_path = trim( (string) ( $this->config['updated_at_path'] ?? '' ) );

		if ( '' !== $updated_at_path ) {
			$updated_at = Unit_Converter::parse_timestamp( Json_Path::resolve( $body, $updated_at_path ), $this->source_timezone() );
			$updated_at = $this->accept_source_time( $updated_at, $now );
		}

		return new PV_Reading( $values, $updated_at ?? $now, null === $updated_at, $now );
	}

	/**
	 * Strefa, w której źródło podaje daty bez przesunięcia.
	 *
	 * @return \DateTimeZone
	 */
	private function source_timezone(): \DateTimeZone {
		$timezone = (string) ( $this->config['timezone'] ?? '' );

		if ( '' !== $timezone && in_array( $timezone, timezone_identifiers_list(), true ) ) {
			return new \DateTimeZone( $timezone );
		}

		return wp_timezone();
	}
}
