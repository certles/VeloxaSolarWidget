<?php
/**
 * Publiczny endpoint odbioru danych push. Adaptowane z IoT Data Widget
 * (includes/class-push-ingest.php, commit b10c54c — patrz SHARED.md,
 * oznaczone SYNC-REQUIRED razem z Push_Channel_Manager), OKROJONE do
 * zakresu Fazy 1: zapis do History_Recorder (wykresy/raporty) pominięty —
 * Solar nie ma jeszcze zdefiniowanego modelu historii (to Faza 2, sekcja 4
 * briefu: current_power/energy_today/energy_total, nie ogólny "value" jak
 * w IoT). Odczyt trafia dziś WYŁĄCZNIE do Push_Channel_Manager (whitelist +
 * ostatnia wartość) — wystarczające dla kryterium akceptacji Fazy 1
 * ("Sprawdź, czy dane docierają" pokazuje odczyt), historia dochodzi w
 * Fazie 2/3.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Core;

use Veloxa\Solar\Model\PV_Reading;
use Veloxa\Solar\Model\Unit_Converter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Push_Ingest
 */
class Push_Ingest {

	const RATE_LIMIT_PER_MINUTE = 60;

	const MAX_PAYLOAD_BYTES = 8192;

	const ENTITY_KEY_PATTERN = '/^[a-z0-9_.:-]{1,120}$/';

	const MAX_TEXT_VALUE_LENGTH = 255;

	/**
	 * Menedżer połączeń — lista kanałów push do wyszukania po tokenie.
	 *
	 * @var Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Whitelist encji + ostatnia wartość per kanał.
	 *
	 * @var Push_Channel_Manager
	 */
	private $channel_manager;

	/**
	 * Konstruktor.
	 *
	 * @param Connector_Manager    $connector_manager Menedżer konektorów (lista kanałów push).
	 * @param Push_Channel_Manager $channel_manager   Whitelist encji + ostatnia wartość per kanał.
	 */
	public function __construct( Connector_Manager $connector_manager, Push_Channel_Manager $channel_manager ) {
		$this->connector_manager = $connector_manager;
		$this->channel_manager   = $channel_manager;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Rejestruje /ingest dla GET i POST (namespace veloxa-solar-widget/v1 —
	 * kompletnie odrębny od IoT Data Widget, patrz brief sekcja 2.1).
	 * permission_callback celowo '__return_true' — autoryzacja to "czy
	 * podany token pasuje do jakiegoś kanału", nie "kto jest zalogowany",
	 * co sprawdza sam handle_ingest().
	 */
	public function register_routes(): void {
		register_rest_route(
			'veloxa-solar-widget/v1',
			'/ingest',
			array(
				'methods'             => array( \WP_REST_Server::READABLE, \WP_REST_Server::CREATABLE ),
				'callback'            => array( $this, 'handle_ingest' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Obsługuje GET/POST /ingest.
	 *
	 * @param \WP_REST_Request $request Żądanie REST.
	 * @return \WP_REST_Response
	 */
	public function handle_ingest( \WP_REST_Request $request ): \WP_REST_Response {
		$raw_body = (string) $request->get_body();

		if ( strlen( $raw_body ) > self::MAX_PAYLOAD_BYTES ) {
			return $this->error_response( __( 'Request body is too large.', 'veloxa-solar-widget' ), 400 );
		}

		$channel = $this->find_channel_by_token( $this->extract_token( $request ) );

		if ( null === $channel ) {
			return $this->error_response( __( 'Invalid or missing token.', 'veloxa-solar-widget' ), 401 );
		}

		if ( $this->is_rate_limited( $channel['id'] ) ) {
			return $this->error_response(
				sprintf(
					/* translators: %d: requests per minute allowed */
					__( 'Rate limit exceeded — max %d requests per minute for this channel.', 'veloxa-solar-widget' ),
					self::RATE_LIMIT_PER_MINUTE
				),
				429
			);
		}

		$readings = $this->parse_readings( $request, $raw_body );

		if ( empty( $readings ) ) {
			return $this->error_response( __( 'No valid reading found in the request body.', 'veloxa-solar-widget' ), 400 );
		}

		// unavailable/unknown z HA to brak odczytu, nie błąd urządzenia:
		// pomijamy tylko to pole (zostaje ostatnia wartość ze swoim czasem),
		// reszta żądania przechodzi. Wcześniej tekst w polu PV odrzucał całe
		// żądanie i stawały wszystkie wartości.
		$available = array_filter(
			$readings,
			static function ( $reading ) {
				return ! ( is_array( $reading ) && Value_Normalizer::is_unavailable( $reading['value'] ?? null ) );
			}
		);

		if ( empty( $available ) ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$readings = $available;

		// Nierozpoznana jednostka odrzuca CAŁE żądanie, zanim cokolwiek
		// zostanie zapisane (brief, BLOKER 1) — urządzenie z błędną
		// konfiguracją nie może zapisać części wartości w złej skali.
		$unit_errors = array_filter( array_map( array( $this, 'unit_error' ), array_filter( $readings, 'is_array' ) ) );
		// Tak samo wartość pola PV, której model nie przyjmie (tekst, SOC
		// poza 0–100) — inaczej urządzenie dostaje 200, a widget stoi.
		$unit_errors = array_merge( $unit_errors, array_filter( array_map( array( $this, 'value_error' ), array_filter( $readings, 'is_array' ) ) ) );

		if ( ! empty( $unit_errors ) ) {
			return $this->error_response( implode( ' ', $unit_errors ), 400 );
		}

		$accepted = 0;
		$errors   = array();

		foreach ( $readings as $reading ) {
			if ( ! is_array( $reading ) ) {
				continue;
			}

			$result = $this->process_reading( $channel['id'], $reading );

			if ( true === $result ) {
				++$accepted;
			} else {
				$errors[] = $result;
			}
		}

		if ( 0 === $accepted ) {
			return $this->error_response( implode( ' ', $errors ), 400 );
		}

		/**
		 * Kanał przyjął co najmniej jeden odczyt — Solar składa z nich model
		 * PV (Sources\Push_Source::sync()). Dodane względem IoT (SHARED.md).
		 *
		 * @param string $connection_id ID kanału.
		 */
		do_action( 'veloxa_solar_push_received', $channel['id'] );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Buduje jednolitą odpowiedź błędu.
	 *
	 * @param string $message Czytelny komunikat.
	 * @param int    $status  Kod HTTP.
	 * @return \WP_REST_Response
	 */
	private function error_response( string $message, int $status ): \WP_REST_Response {
		$response = rest_ensure_response(
			array(
				'ok'    => false,
				'error' => $message,
			)
		);
		$response->set_status( $status );

		return $response;
	}

	/**
	 * Wyciąga token z żądania — nagłówek X-Solar-Token, potem Authorization:
	 * Bearer, na końcu query string ?token=.
	 *
	 * @param \WP_REST_Request $request Żądanie REST.
	 * @return string Pusty string, jeśli nigdzie nie znaleziono tokenu.
	 */
	private function extract_token( \WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'X-Solar-Token' );

		if ( '' !== $header ) {
			return $header;
		}

		$auth = (string) $request->get_header( 'Authorization' );

		if ( 0 === stripos( $auth, 'Bearer ' ) ) {
			return trim( substr( $auth, 7 ) );
		}

		$query = $request->get_query_params();

		return isset( $query['token'] ) ? (string) $query['token'] : '';
	}

	/**
	 * Znajduje kanał (połączenie typu 'push'), którego hash pasuje do
	 * podanego tokenu.
	 *
	 * @param string $token Surowy token z żądania.
	 * @return array<string, mixed>|null
	 */
	private function find_channel_by_token( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}

		foreach ( $this->connector_manager->get_connections() as $connection ) {
			if ( Push_Channel_Manager::CONNECTOR_TYPE !== ( $connection['connector'] ?? '' ) ) {
				continue;
			}

			if ( Push_Channel_Manager::verify_token( $token, (string) ( $connection['token_hash'] ?? '' ) ) ) {
				return $connection;
			}
		}

		return null;
	}

	/**
	 * Czy $connection_id przekroczył limit żądań w BIEŻĄCEJ minucie.
	 * Licznik atomowy (recenzja wp.org 0.7.1): równoległe żądania nie
	 * czytają tej samej wartości — przy trwałym object cache wp_cache_incr(),
	 * bez niego jedno zapytanie INSERT … ON DUPLICATE KEY UPDATE.
	 *
	 * @param string $connection_id ID kanału.
	 * @return bool
	 */
	private function is_rate_limited( string $connection_id ): bool {
		return self::hit( $connection_id, time() ) > self::RATE_LIMIT_PER_MINUTE;
	}

	/**
	 * Zwiększa licznik kanału w minucie $now i zwraca nową wartość.
	 *
	 * @param string $connection_id ID kanału.
	 * @param int    $now           Bieżący czas.
	 * @return int
	 */
	public static function hit( string $connection_id, int $now ): int {
		$minute = (int) floor( $now / MINUTE_IN_SECONDS );
		$key    = 'veloxa_solar_push_rate_' . md5( $connection_id ) . '_' . $minute;

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'veloxa_solar', 2 * MINUTE_IN_SECONDS );
			$count = wp_cache_incr( $key, 1, 'veloxa_solar' );

			if ( false !== $count ) {
				return (int) $count;
			}
		}

		global $wpdb;

		// Wiersz w wp_options z prefiksem transientów — sprząta go też
		// odinstalowanie (Plugin::uninstall, prefiks _transient_veloxa_solar_).
		$option = '_transient_' . $key;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- licznik atomowy; cache tu by go zepsuł.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off') ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID( option_value + 1 )",
				$option
			)
		);

		if ( 1 === (int) $wpdb->rows_affected ) {
			// Nowa minuta: liczba 1; poprzednie minuty tego kanału do usunięcia.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s",
					$wpdb->esc_like( '_transient_veloxa_solar_push_rate_' . md5( $connection_id ) . '_' ) . '%',
					$option
				)
			);

			return 1;
		}

		$count = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		// phpcs:enable

		return $count;
	}

	/**
	 * Rozkłada ciało żądania na listę odczytów — obsługuje pojedynczy JSON,
	 * zbiorczy JSON ({"readings":[...]}), form-urlencoded i text/plain.
	 *
	 * @param \WP_REST_Request $request  Żądanie REST.
	 * @param string           $raw_body Surowe (już zweryfikowane pod kątem rozmiaru) ciało żądania.
	 * @return array<int, array<string, mixed>>
	 */
	private function parse_readings( \WP_REST_Request $request, string $raw_body ): array {
		$trimmed_body = trim( $raw_body );

		if ( '' !== $trimmed_body && ( '{' === $trimmed_body[0] || '[' === $trimmed_body[0] ) ) {
			$json = json_decode( $trimmed_body, true );

			if ( is_array( $json ) ) {
				if ( isset( $json['readings'] ) && is_array( $json['readings'] ) ) {
					return array_values( $json['readings'] );
				}

				if ( array_key_exists( 'entity', $json ) ) {
					return array( $json );
				}
			}
		}

		$entity = (string) $request->get_param( 'entity' );

		if ( '' === $entity ) {
			return array();
		}

		$value = $request->get_param( 'value' );

		if ( null === $value || '' === $value ) {
			$value = $trimmed_body;
		}

		return array(
			array(
				'entity' => $entity,
				'value'  => $value,
				'unit'   => (string) $request->get_param( 'unit' ),
			),
		);
	}

	/**
	 * Błąd jednostki jednego odczytu, albo null. Dotyczy tylko encji będących
	 * polami modelu PV — brak jednostki jest poprawny (obowiązuje jednostka
	 * wybrana dla kanału).
	 *
	 * @param array<string, mixed> $reading Jeden odczyt.
	 * @return string|null
	 */
	private function unit_error( array $reading ): ?string {
		$entity_key = strtolower( trim( (string) ( $reading['entity'] ?? '' ) ) );
		$unit       = trim( (string) ( $reading['unit'] ?? '' ) );

		if ( '' === $unit || ! isset( PV_Reading::FIELD_UNITS[ $entity_key ] ) || null !== Unit_Converter::canonical_unit( $entity_key, $unit ) ) {
			return null;
		}

		return sprintf(
			/* translators: 1: unit as sent by the device, 2: entity name, 3: comma-separated accepted units */
			__( 'Unknown unit "%1$s" for entity "%2$s". Accepted: %3$s.', 'veloxa-solar-widget' ),
			sanitize_text_field( mb_substr( $unit, 0, 32 ) ),
			$entity_key,
			implode( ', ', Unit_Converter::accepted_spellings_for( $entity_key ) )
		);
	}

	/**
	 * Błąd wartości jednego odczytu, albo null. Tylko encje będące polami
	 * modelu PV: wartość musi być liczbą, poziom baterii 0–100 %.
	 *
	 * @param array<string, mixed> $reading Jeden odczyt.
	 * @return string|null
	 */
	private function value_error( array $reading ): ?string {
		$entity_key = strtolower( trim( (string) ( $reading['entity'] ?? '' ) ) );
		$value      = $reading['value'] ?? null;

		if ( ! isset( PV_Reading::FIELD_UNITS[ $entity_key ] ) || null === $value || '' === $value ) {
			return null;
		}

		if ( ! is_numeric( is_string( $value ) ? trim( $value ) : $value ) || is_bool( $value ) ) {
			return sprintf(
				/* translators: %s: entity name */
				__( 'The value for entity "%s" must be a number.', 'veloxa-solar-widget' ),
				$entity_key
			);
		}

		if ( 'battery_soc' === $entity_key && ( (float) $value < 0 || (float) $value > 100 ) ) {
			return __( 'The value for entity "battery_soc" must be between 0 and 100.', 'veloxa-solar-widget' );
		}

		return null;
	}

	/**
	 * Waliduje i zapisuje jeden odczyt. Zwraca `true` przy sukcesie, albo
	 * czytelny komunikat błędu (string).
	 *
	 * @param string               $connection_id ID kanału.
	 * @param array<string, mixed> $reading       Jeden odczyt: 'entity', 'value', opcjonalnie 'unit'.
	 * @return true|string
	 */
	private function process_reading( string $connection_id, array $reading ) {
		$entity_key = strtolower( trim( (string) ( $reading['entity'] ?? '' ) ) );

		if ( '' === $entity_key || ! preg_match( self::ENTITY_KEY_PATTERN, $entity_key ) ) {
			return sprintf(
				/* translators: %s: the rejected entity name as sent by the device */
				__( 'Invalid entity name: "%s".', 'veloxa-solar-widget' ),
				sanitize_text_field( (string) ( $reading['entity'] ?? '' ) )
			);
		}

		if ( ! $this->channel_manager->is_entity_allowed( $connection_id, $entity_key ) ) {
			return sprintf(
				/* translators: %s: entity name not on the channel's whitelist */
				__( 'Unknown entity for this channel: "%s". Add it to the channel\'s entity list first.', 'veloxa-solar-widget' ),
				$entity_key
			);
		}

		$raw_value = $reading['value'] ?? null;

		if ( null === $raw_value || '' === $raw_value ) {
			return sprintf(
				/* translators: %s: entity name */
				__( 'Missing value for entity "%s".', 'veloxa-solar-widget' ),
				$entity_key
			);
		}

		$is_string_value = is_string( $raw_value );
		$clean_text      = $is_string_value ? wp_check_invalid_utf8( $raw_value, true ) : (string) $raw_value;
		$normalized      = Value_Normalizer::normalize( $is_string_value ? $clean_text : $raw_value );

		if ( is_numeric( $normalized['value'] ) ) {
			$value_num     = (float) $normalized['value'];
			$display_value = null !== $normalized['display_value']
				? mb_substr( $normalized['display_value'], 0, self::MAX_TEXT_VALUE_LENGTH )
				: null;
		} else {
			$value_num     = 1.0;
			$display_value = mb_substr( $clean_text, 0, self::MAX_TEXT_VALUE_LENGTH );
		}

		$unit = isset( $reading['unit'] ) ? trim( (string) $reading['unit'] ) : '';
		$unit = '' !== $unit ? mb_substr( wp_check_invalid_utf8( $unit, true ), 0, 32 ) : null;

		// Pole PV: zapis jednostki kanoniczny ("kw" → "kW"); poprawność
		// sprawdził już unit_error() w handle_ingest().
		if ( null !== $unit && isset( PV_Reading::FIELD_UNITS[ $entity_key ] ) ) {
			$unit = Unit_Converter::canonical_unit( $entity_key, $unit );
		}

		$this->channel_manager->record_reading( $connection_id, $entity_key, $value_num, $display_value, $unit );

		// Model PV i historia — po przyjęciu całego żądania, akcja
		// 'veloxa_solar_push_received' w handle_ingest().

		return true;
	}
}
