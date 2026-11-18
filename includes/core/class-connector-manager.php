<?php
/**
 * Menedżer konektorów danych. Adaptowane z IoT Data Widget
 * (includes/class-connector-manager.php, commit b10c54c — patrz
 * SHARED.md), OKROJONE do zakresu Fazy 1 (szkielet):
 *
 * - build_config()/enforce_min_ttl()/GLOBAL_MIN_POLL_TTL pominięte — zależą
 *   od konkretnych typów konektorów (REST/presety/MQTT), których jeszcze
 *   nie ma; wracają w Fazie 2+ razem z tymi konektorami.
 * - ŻADNEGO gatingu Free/Pro (FREE_TIER_CONNECTION_LIMIT/count_billable() w
 *   IoT) — brief sekcja 6 wprost zakazuje implementowania podziału bez
 *   decyzji właściciela (Faza 7).
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Connector_Manager
 */
class Connector_Manager {

	/**
	 * Nazwa opcji przechowującej połączenia skonfigurowane przez użytkownika.
	 */
	const CONNECTIONS_OPTION = 'veloxa_solar_connections';

	/**
	 * Zarejestrowane typy konektorów: typ => ['class' => ..., 'label' => ...].
	 *
	 * @var array<string, array{class: string, label: string}>
	 */
	private $connectors = array();

	/**
	 * Rejestruje typ konektora.
	 *
	 * @param string $type       Identyfikator typu, np. "push".
	 * @param string $class_name Pełna nazwa klasy implementującej Connectors\Abstract_Connector.
	 * @param string $label      Czytelna nazwa do wyświetlenia w adminie.
	 */
	public function register( string $type, string $class_name, string $label ): void {
		$this->connectors[ $type ] = array(
			'class' => $class_name,
			'label' => $label,
		);
	}

	/**
	 * Zwraca zarejestrowane typy konektorów jako [typ => etykieta].
	 *
	 * @return array<string, string>
	 */
	public function get_types(): array {
		return wp_list_pluck( $this->connectors, 'label' );
	}

	/**
	 * Tworzy instancję konektora dla danego połączenia.
	 *
	 * @param array<string, mixed> $connection Połączenie (musi zawierać 'connector').
	 * @return Connectors\Abstract_Connector|null
	 */
	public function make( array $connection ): ?Connectors\Abstract_Connector {
		$type = $connection['connector'] ?? '';

		if ( ! isset( $this->connectors[ $type ] ) ) {
			return null;
		}

		$class = $this->connectors[ $type ]['class'];

		return new $class( $connection );
	}

	/**
	 * Zwraca połączenia skonfigurowane przez użytkownika — pola-sekrety
	 * deszyfrowane tutaj, więc każdy inny kod zawsze dostaje wartość jawną.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_connections(): array {
		$connections = get_option( self::CONNECTIONS_OPTION, array() );

		if ( ! is_array( $connections ) ) {
			return array();
		}

		return array_map( array( $this, 'decrypt_secret_fields' ), $connections );
	}

	/**
	 * Zapisuje listę połączeń — pola-sekrety szyfrowane tutaj.
	 *
	 * @param array<int, array<string, string>> $connections Lista połączeń, wartości sekretów JAWNE.
	 */
	public function save_connections( array $connections ): void {
		update_option( self::CONNECTIONS_OPTION, array_map( array( $this, 'encrypt_secret_fields' ), $connections ) );
	}

	/**
	 * Pola, których wartość jest sekretem dla danego połączenia. Lista
	 * przewidująca konektory Fazy 2/4 (api_key — SolarEdge, token — SolaX/
	 * REST), nie tylko to, co istnieje dziś w Fazie 1.
	 *
	 * Dla Generic REST sekretem jest też 'url' — klucze API często siedzą w
	 * query stringu (SolaX: ?tokenId=…), więc URL w bazie nie może być jawny.
	 *
	 * @param array<string, mixed> $connection Połączenie.
	 * @return string[]
	 */
	private function secret_fields_for( array $connection ): array {
		// app_secret / password_hash — źródła chmurowe (np. Solarman, Pro);
		// skrót hasła działa jak hasło, więc też jest sekretem.
		$fields = array( 'token', 'api_key', 'auth_key', 'app_secret', 'password_hash' );

		if ( 'rest' === ( $connection['connector'] ?? '' ) ) {
			$fields[] = 'url';
		}

		return $fields;
	}

	/**
	 * Szyfruje pola-sekrety połączenia.
	 *
	 * @param array<string, mixed> $connection Połączenie z polami-sekretami JAWNYMI.
	 * @return array<string, mixed>
	 */
	private function encrypt_secret_fields( array $connection ): array {
		foreach ( $this->secret_fields_for( $connection ) as $field ) {
			if ( isset( $connection[ $field ] ) && is_string( $connection[ $field ] ) ) {
				$connection[ $field ] = Credential_Cipher::encrypt( $connection[ $field ] );
			}
		}

		return $connection;
	}

	/**
	 * Deszyfruje pola-sekrety połączenia.
	 *
	 * @param array<string, mixed> $connection Połączenie z polami-sekretami ZASZYFROWANYMI (albo jawnym tekstem).
	 * @return array<string, mixed>
	 */
	private function decrypt_secret_fields( array $connection ): array {
		foreach ( $this->secret_fields_for( $connection ) as $field ) {
			if ( isset( $connection[ $field ] ) && is_string( $connection[ $field ] ) ) {
				$connection[ $field ] = Credential_Cipher::decrypt( $connection[ $field ] );
			}
		}

		return $connection;
	}

	/**
	 * Znajduje połączenie po ID albo (pomocniczo) po nazwie.
	 *
	 * @param string $id_or_name ID lub nazwa połączenia.
	 * @return array<string, string>|null
	 */
	public function find_by_id_or_name( string $id_or_name ): ?array {
		foreach ( $this->get_connections() as $connection ) {
			if ( $connection['id'] === $id_or_name || $connection['name'] === $id_or_name ) {
				return $connection;
			}
		}

		return null;
	}

	/**
	 * Znajduje połączenie wyłącznie po ID — do edycji, gdzie dopasowanie po
	 * nazwie mogłoby trafić w inne połączenie.
	 *
	 * @param string $id ID połączenia.
	 * @return array<string, mixed>|null
	 */
	public function find_by_id( string $id ): ?array {
		foreach ( $this->get_connections() as $connection ) {
			if ( $connection['id'] === $id ) {
				return $connection;
			}
		}

		return null;
	}

	/**
	 * Podmienia zapisane połączenie o tym samym ID (edycja). Pozycja na
	 * liście zostaje ta sama.
	 *
	 * @param array<string, mixed> $connection Pełna konfiguracja, sekrety JAWNE.
	 * @return bool False, gdy połączenia o tym ID nie ma.
	 */
	public function update_connection( array $connection ): bool {
		$connections = $this->get_connections();

		foreach ( $connections as $i => $existing ) {
			if ( ( $connection['id'] ?? null ) === $existing['id'] ) {
				$connections[ $i ] = $connection;
				$this->save_connections( $connections );

				return true;
			}
		}

		return false;
	}

	/**
	 * Zwraca gotowy konektor dla danego ID/nazwy połączenia.
	 *
	 * @param string $id_or_name ID lub nazwa połączenia.
	 * @return Connectors\Abstract_Connector|null
	 */
	public function get_connector_for( string $id_or_name ): ?Connectors\Abstract_Connector {
		$connection = $this->find_by_id_or_name( $id_or_name );

		if ( null === $connection ) {
			return null;
		}

		return $this->make( $connection );
	}
}
