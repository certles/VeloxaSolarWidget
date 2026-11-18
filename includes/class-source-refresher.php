<?php
/**
 * Odświeżanie źródeł danych w tle (WP-Cron) — brief sekcja 7: "żadnego
 * zapytania do API producenta w ścieżce renderowania dla odwiedzającego;
 * dane odświeżane w tle i serwowane z cache". Wyniki trafiają do
 * Reading_Store, skąd czyta renderowanie.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar;

use Veloxa\Solar\Core\Connector_Manager;
use Veloxa\Solar\Core\Diagnostics_Logger;
use Veloxa\Solar\Core\Messages;
use Veloxa\Solar\Model\History_Store;
use Veloxa\Solar\Model\PV_Reading;
use Veloxa\Solar\Model\Reading_Store;
use Veloxa\Solar\Sources\Polled_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Source_Refresher
 *
 * Jeden hook co 5 minut dla wszystkich źródeł; każde źródło odpytywane
 * dopiero, gdy minął jego własny interwał (refresh_interval()) — cron
 * nigdy nie wymusza częstszego odpytywania niż źródło pozwala.
 */
class Source_Refresher {

	/**
	 * Hook crona.
	 */
	const CRON_HOOK = 'veloxa_solar_refresh_sources';

	/**
	 * Własny harmonogram WP-Cron (WP nie ma wbudowanego 5-minutowego).
	 */
	const SCHEDULE = 'veloxa_solar_every_five_minutes';

	/**
	 * Tolerancja (sekundy) przy sprawdzaniu, czy źródło jest do
	 * odświeżenia — WP-Cron odpala się nieregularnie (przy ruchu na
	 * stronie), bez niej źródło z interwałem 5 min co drugi raz czekałoby
	 * 10 min, bo cron przyszedł 2 sekundy "za wcześnie".
	 */
	const DUE_SLACK_SECONDS = 30;

	/**
	 * Menedżer konektorów.
	 *
	 * @var Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Konstruktor — podpina hooki i planuje crona, jeśli go brakuje
	 * (aktualizacja wtyczki bez ponownej aktywacji nie odpala activate()).
	 *
	 * @param Connector_Manager $connector_manager Menedżer konektorów.
	 */
	public function __construct( Connector_Manager $connector_manager ) {
		$this->connector_manager = $connector_manager;

		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5 minut: minimalny sensowny interwał dla chmur PV.
		add_action( self::CRON_HOOK, array( $this, 'run_cron' ) );
		add_action( History_Store::PRUNE_HOOK, array( __CLASS__, 'run_prune_cron' ) );

		self::schedule();
	}

	/**
	 * Dodaje 5-minutowy harmonogram.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Harmonogramy.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function add_schedule( $schedules ): array {
		$schedules                   = is_array( $schedules ) ? $schedules : array();
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Veloxa Solar Widget)', 'veloxa-solar-widget' ),
		);

		return $schedules;
	}

	/**
	 * Planuje crona, jeśli jeszcze nie jest zaplanowany.
	 */
	public static function schedule(): void {
		History_Store::schedule_prune();

		if ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		// Filtr musi być aktywny przed wp_schedule_event() — przy aktywacji
		// konstruktor jeszcze się nie wykonał.
		if ( ! has_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ) ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- jak wyżej.
		}

		wp_schedule_event( time(), self::SCHEDULE, self::CRON_HOOK );
	}

	/**
	 * Usuwa crona (deaktywacja).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( History_Store::PRUNE_HOOK );
	}

	/**
	 * Callback dziennego crona czyszczącego historię i log błędów.
	 */
	public static function run_prune_cron(): void {
		Diagnostics_Logger::run_cron(
			History_Store::PRUNE_HOOK,
			static function () {
				$args = array( History_Store::prune( time() ), Diagnostics_Logger::trim_error_log() );

				return array(
					'success'      => true,
					'message'      => (string) Messages::render( Messages::PRUNED, $args ),
					'message_key'  => Messages::PRUNED,
					'message_args' => $args,
				);
			}
		);
	}

	/**
	 * Callback crona — z zapisem wyniku w diagnostyce.
	 */
	public function run_cron(): void {
		Diagnostics_Logger::run_cron(
			self::CRON_HOOK,
			function () {
				return $this->refresh_due( time() );
			}
		);
	}

	/**
	 * Odświeża źródła, którym minął interwał.
	 *
	 * Błąd pojedynczego źródła nie jest błędem crona — trafia do
	 * diagnostyki tego połączenia (record_connection_result()), a cron
	 * zgłasza sukces, dopóki sam zadziałał.
	 *
	 * @param int $now Bieżący czas (unix, UTC).
	 * @return array{success: bool, message: string, message_key: string, message_args: int[]}
	 */
	public function refresh_due( int $now ): array {
		$refreshed = 0;
		$failed    = 0;

		foreach ( $this->connector_manager->get_connections() as $connection ) {
			$source = $this->connector_manager->make( $connection );

			if ( ! $source instanceof Polled_Source || ! $this->is_due( (string) $connection['id'], $source->refresh_interval(), $now ) ) {
				continue;
			}

			if ( $this->refresh_source( (string) $connection['id'], $source, $now ) instanceof PV_Reading ) {
				++$refreshed;
			} else {
				++$failed;
			}
		}

		return array(
			'success'      => true,
			'message'      => (string) Messages::render( Messages::REFRESHED, array( $refreshed, $failed ) ),
			'message_key'  => Messages::REFRESHED,
			'message_args' => array( $refreshed, $failed ),
		);
	}

	/**
	 * Odświeża jedno połączenie od razu, bez sprawdzania interwału —
	 * przycisk "Refresh now" w adminie.
	 *
	 * @param string $connection_id ID połączenia.
	 * @param int    $now           Bieżący czas (unix, UTC).
	 * @return PV_Reading|\WP_Error
	 */
	public function refresh_now( string $connection_id, int $now ) {
		$connection = $this->connector_manager->find_by_id_or_name( $connection_id );
		$source     = null === $connection ? null : $this->connector_manager->make( $connection );

		if ( ! $source instanceof Polled_Source ) {
			return new \WP_Error( 'veloxa_solar_not_refreshable', __( 'This connection is not a polled data source.', 'veloxa-solar-widget' ) );
		}

		return $this->refresh_source( (string) $connection['id'], $source, $now );
	}

	/**
	 * Pobiera, zapisuje i raportuje wynik jednego źródła.
	 *
	 * @param string        $connection_id ID połączenia.
	 * @param Polled_Source $source        Źródło.
	 * @param int           $now           Bieżący czas (unix, UTC).
	 * @return PV_Reading|\WP_Error
	 */
	private function refresh_source( string $connection_id, Polled_Source $source, int $now ) {
		$result = $source->fetch_reading( $now );

		if ( is_wp_error( $result ) ) {
			$message = Diagnostics_Logger::redact_secrets( $result->get_error_message() );

			Reading_Store::save_error( $connection_id, $message, $now );
			Diagnostics_Logger::record_connection_result( $connection_id, array( 'error' => $message ) );

			return $result;
		}

		$result = $source instanceof \Veloxa\Solar\Sources\Abstract_Source ? $source->finalize_reading( $result ) : $result;

		// Źródła beta (z dokumentacji producenta): kontrole wiarygodności
		// do logu diagnostyki — poprawiamy mapowanie na żywych instalacjach.
		if ( $source instanceof \Veloxa\Solar\Sources\Abstract_Source && $source->is_beta() ) {
			\Veloxa\Solar\Model\Sanity_Checks::check_and_log( $connection_id, $result, Reading_Store::get( $connection_id ) );
		}

		Reading_Store::save( $connection_id, $result );
		History_Store::record( $connection_id, $result );
		\Veloxa\Solar\Model\Daily_Store::refresh_for( $connection_id, $result->updated_at(), 3 * $source->refresh_interval(), $now );
		Diagnostics_Logger::record_connection_result( $connection_id, array( 'error' => null ) );

		return $result;
	}

	/**
	 * Czy źródłu minął interwał od ostatniej próby (udanej lub nie —
	 * nieudana próba też liczy się do limitu zapytań źródła).
	 *
	 * @param string $connection_id    ID połączenia.
	 * @param int    $refresh_interval Interwał (sekundy).
	 * @param int    $now              Bieżący czas (unix, UTC).
	 * @return bool
	 */
	private function is_due( string $connection_id, int $refresh_interval, int $now ): bool {
		$last = Reading_Store::get_last_attempt_at( $connection_id );

		return null === $last || ( $now - $last ) >= ( $refresh_interval - self::DUE_SLACK_SECONDS );
	}
}
