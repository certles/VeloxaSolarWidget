<?php
/**
 * Źródło odpytywane w tle przez Source_Refresher (Generic REST, Home
 * Assistant) — w odróżnieniu od push, gdzie dane przychodzą same.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Sources;

use Veloxa\Solar\Model\PV_Reading;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Polled_Source
 */
interface Polled_Source {

	/**
	 * Pobiera odczyt ze źródła — jedyne miejsce wysyłające żądanie; wołane
	 * z crona albo z przycisku "Refresh now", nigdy z renderowania.
	 *
	 * @param int $now Bieżący czas (unix, UTC).
	 * @return PV_Reading|\WP_Error
	 */
	public function fetch_reading( int $now );

	/**
	 * Interwał odświeżania (sekundy).
	 *
	 * @return int
	 */
	public function refresh_interval(): int;
}
