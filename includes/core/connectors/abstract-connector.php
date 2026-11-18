<?php
/**
 * Bazowa klasa dla konektorów danych. Skopiowane z IoT Data Widget
 * (includes/connectors/abstract-connector.php, commit b10c54c — patrz
 * SHARED.md), z jedną świadomą różnicą: is_local_network_host()/
 * maybe_explain_local_network_error() (tam: tylko wzbogacały komunikat
 * błędu PO nieudanym połączeniu, nic nie blokowały) zostały usunięte na
 * rzecz Http_Client::get()/post() (patrz ../class-http-client.php), który
 * faktycznie ODMAWIA połączenia z adresem lokalnym/prywatnym/zarezerwowanym
 * PRZED wysłaniem, z podobnie wyjaśniającym komunikatem błędu — zgłoszone i
 * zdecydowane z właścicielem 2026-09-23 (patrz SHARED.md, SYNC-REQUIRED).
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Core\Connectors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Abstract_Connector
 *
 * Każdy konektor zwraca wyłącznie znormalizowaną strukturę — renderer i
 * cache layer nigdy nie widzą surowej odpowiedzi API źródła danych.
 */
abstract class Abstract_Connector {

	/**
	 * Timeout (sekundy) dla KAŻDEGO żądania HTTP synchronicznego podczas
	 * renderowania strony — patrz Http_Client::TIMEOUT_SECONDS (jedno
	 * miejsce prawdy, ta stała zostaje tu tylko dla zgodności z konektorami,
	 * które jeszcze jej nie przełączyły).
	 */
	const HTTP_TIMEOUT_SECONDS = 3;

	/**
	 * Pobiera i normalizuje dane encji ze źródła. 'value' musi zawsze być
	 * liczbą — patrz sekcja 4 briefu (model znormalizowany).
	 *
	 * @param string $entity_id Identyfikator wartości u źródła danych.
	 * @return array{value: mixed, unit: string|null, label: string|null, last_updated: string|null, error: string|null, display_value?: string|null}
	 */
	abstract public function fetch_data( string $entity_id ): array;

	/**
	 * Zwraca listę dostępnych wartości — opcjonalna zdolność, do
	 * autouzupełniania w edytorze bloku. Domyślnie pusta.
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	public function list_entities(): array {
		return array();
	}

	/**
	 * Buduje znormalizowaną odpowiedź błędu — ten sam kształt co odpowiedź
	 * sukcesu.
	 *
	 * @param string $message Czytelny komunikat błędu.
	 * @return array{value: null, unit: null, label: null, last_updated: null, error: string, display_value: null}
	 */
	protected function error_response( string $message ): array {
		return array(
			'value'         => null,
			'unit'          => null,
			'label'         => null,
			'last_updated'  => null,
			'error'         => $message,
			'display_value' => null,
		);
	}
}
