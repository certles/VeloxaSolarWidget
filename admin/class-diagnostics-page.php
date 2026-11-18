<?php
/**
 * Panel administracyjny — strona diagnostyki (brief sekcja 2.2: "strona
 * diagnostyki"). Wzorowane na admin/class-diagnostics-page.php z IoT Data
 * Widget (commit b10c54c, patrz SHARED.md): jeden ekran i przycisk "Copy
 * report" zamiast rundy e-maili z prośbą o zrzuty ekranu.
 *
 * Różnice względem IoT: bez reguł alertów, MQTT i raportów e-mail (Solar
 * ich nie ma); status połączenia liczony z Reading_Store — ostatni odczyt,
 * stan "dane nieaktualne" (brief sekcja 5) i ostatni błąd — wspólnie dla
 * źródeł odpytywanych i push, zamiast osobnej ścieżki dla push.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Admin;

use Veloxa\Solar\Core\Connector_Manager;
use Veloxa\Solar\Core\Diagnostics_Logger;
use Veloxa\Solar\Core\Http_Client;
use Veloxa\Solar\Core\Push_Channel_Manager;
use Veloxa\Solar\Model\History_Store;
use Veloxa\Solar\Model\Reading_Store;
use Veloxa\Solar\Plugin;
use Veloxa\Solar\Source_Refresher;
use Veloxa\Solar\Sources\Abstract_Source;
use Veloxa\Solar\Sources\Ha_Source;
use Veloxa\Solar\Sources\Polled_Source;
use Veloxa\Solar\Sources\Push_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Diagnostics_Page
 */
class Diagnostics_Page {

	/**
	 * Slug strony.
	 */
	const PAGE_SLUG = 'veloxa-solar-widget-diagnostics';

	/**
	 * Stany połączenia na ekranie.
	 */
	const STATUS_OK       = 'ok';
	const STATUS_STALE    = 'stale';
	const STATUS_ERROR    = 'error';
	const STATUS_NO_DATA  = 'no_data';
	const STATUS_UNMAPPED = 'unmapped';
	const STATUS_UNKNOWN  = 'unknown_type';

	/**
	 * Ile ostatnich wpisów logu błędów pokazać.
	 */
	const RECENT_ERRORS = 50;

	/**
	 * Ostrzeżenie „rzadkie odświeżanie”: mniej niż ten ułamek oczekiwanych
	 * prób w ostatniej dobie (WP-Cron działa tylko przy ruchu na stronie).
	 */
	const RARE_REFRESH_RATIO = 0.25;

	/**
	 * Menedżer konektorów.
	 *
	 * @var Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Stan encji kanałów push (czas ostatniego odbioru).
	 *
	 * @var Push_Channel_Manager
	 */
	private $push_channel_manager;

	/**
	 * Hook suffix strony — do ładowania JS tylko tutaj.
	 *
	 * @var string
	 */
	private $page_hook_suffix = '';

	/**
	 * Konstruktor.
	 *
	 * @param Connector_Manager    $connector_manager    Menedżer konektorów.
	 * @param Push_Channel_Manager $push_channel_manager Stan encji kanałów push.
	 */
	public function __construct( Connector_Manager $connector_manager, Push_Channel_Manager $push_channel_manager ) {
		$this->connector_manager    = $connector_manager;
		$this->push_channel_manager = $push_channel_manager;

		// Po Connections_Page (priorytet 10) — menu nadrzędne musi już istnieć.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_veloxa_solar_export_ha_sensors', array( $this, 'handle_export_ha_sensors' ) );
	}

	/**
	 * Pobranie listy czujników HA (0.6) — plik JSON w formacie fixture'ów
	 * testowych (tests/fixtures/ha-states-*.json): z niego dopisujemy
	 * podpowiedzi czujników dla falownika użytkownika.
	 */
	public function handle_export_ha_sensors(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'veloxa-solar-widget' ) );
		}

		check_admin_referer( 'veloxa_solar_export_ha_sensors' );

		$id         = isset( $_GET['connection'] ) ? sanitize_text_field( wp_unslash( $_GET['connection'] ) ) : '';
		$connection = $this->connector_manager->find_by_id( $id );
		$source     = null === $connection ? null : $this->connector_manager->make( $connection );

		if ( ! $source instanceof Ha_Source ) {
			wp_die( esc_html__( 'Connection not found.', 'veloxa-solar-widget' ) );
		}

		$states = $source->export_states();

		if ( is_wp_error( $states ) ) {
			wp_die( esc_html( Diagnostics_Logger::redact_secrets( $states->get_error_message() ) ) );
		}

		$export = array(
			'_source' => sprintf( 'Home Assistant GET /api/states, exported by Veloxa Solar Widget %s on %s; only sensor.* with device_class power/energy/battery, without the Home Assistant address and token.', VELOXA_SOLAR_VERSION, gmdate( 'Y-m-d' ) ),
			'states'  => $states,
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="solar-widget-ha-sensors-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Rejestruje podstronę "Diagnostics".
	 */
	public function register_menu(): void {
		$this->page_hook_suffix = (string) add_submenu_page(
			'veloxa-solar-widget',
			__( 'Veloxa Solar Widget — Diagnostics', 'veloxa-solar-widget' ),
			__( 'Diagnostics', 'veloxa-solar-widget' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * JS przycisku "Copy report" — tylko na tej stronie.
	 *
	 * @param string $hook_suffix Ekran admina.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( '' === $this->page_hook_suffix || $hook_suffix !== $this->page_hook_suffix ) {
			return;
		}

		wp_enqueue_script( 'veloxa-solar-diagnostics-report', VELOXA_SOLAR_PLUGIN_URL . 'admin/js/diagnostics-report.js', array(), VELOXA_SOLAR_VERSION, true );
		wp_localize_script(
			'veloxa-solar-diagnostics-report',
			'veloxaSolarDiagnosticsReport',
			array(
				'copied' => __( 'Copied!', 'veloxa-solar-widget' ),
				'failed' => __( 'Could not copy — select the text below and copy it manually.', 'veloxa-solar-widget' ),
			)
		);
	}

	/**
	 * Renderuje stronę.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$data        = $this->collect( time() );
		$report_text = self::build_report_text( $data );

		require __DIR__ . '/views/diagnostics.php';
	}

	/**
	 * Zbiera wszystkie dane ekranu. Każdy komunikat błędu przechodzi przez
	 * Diagnostics_Logger::redact_secrets() — raport trafia do e-maila.
	 *
	 * @param int $now Bieżący czas (unix, UTC).
	 * @return array{generated_at: int, environment: array<string, mixed>, cron: array<int, array<string, mixed>>, connections: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>, data_sizes: array<int, array{label: string, count: int}>}
	 */
	public function collect( int $now ): array {
		return array(
			'generated_at' => $now,
			'environment'  => $this->environment_info(),
			'cron'         => $this->cron_info(),
			'connections'  => $this->connections_info( $now ),
			'errors'       => Diagnostics_Logger::get_recent_errors( self::RECENT_ERRORS ),
			'data_sizes'   => $this->data_size_info(),
		);
	}

	/**
	 * Środowisko. Stan Pro: wyłącznie istnienie kodu (Plugin::premium_code_installed())
	 * — żadnego wywołania licencyjnego w kodzie darmowym (README.md).
	 *
	 * @return array<string, mixed>
	 */
	private function environment_info(): array {
		$theme = wp_get_theme();

		return array(
			'plugin_version'      => VELOXA_SOLAR_VERSION,
			'pro_code_installed'  => Plugin::premium_code_installed(),
			'wp_version'          => get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'multisite'           => is_multisite(),
			'timezone'            => wp_timezone_string(),
			'active_theme'        => trim( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ),
			'memory_limit'        => (string) ini_get( 'memory_limit' ),
			'max_execution_time'  => (string) ini_get( 'max_execution_time' ),
			'wp_cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'allow_local_network' => Http_Client::allow_local_network(),
			'other_plugins'       => $this->other_active_plugins(),
		);
	}

	/**
	 * Inne aktywne wtyczki (nazwa + wersja) — pod kątem konfliktów.
	 *
	 * @return string[]
	 */
	private function other_active_plugins(): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$self  = plugin_basename( VELOXA_SOLAR_PLUGIN_FILE );
		$names = array();

		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin_file ) {
			$full_path = WP_PLUGIN_DIR . '/' . $plugin_file;

			if ( $plugin_file === $self || ! file_exists( $full_path ) ) {
				continue;
			}

			$data    = get_plugin_data( $full_path, false, false );
			$name    = '' !== ( $data['Name'] ?? '' ) ? $data['Name'] : $plugin_file;
			$names[] = trim( $name . ' ' . ( $data['Version'] ?? '' ) );
		}

		return $names;
	}

	/**
	 * Zadania WP-Cron wtyczki: zaplanowane / nigdy nie uruchomione /
	 * niezaplanowane (realny problem — dane przestają się odświeżać).
	 *
	 * @return array<int, array{hook: string, label: string, next_run: int|null, last_run: array{ran_at: string, success: bool, message: string}|null}>
	 */
	private function cron_info(): array {
		$hooks = array(
			Source_Refresher::CRON_HOOK => __( 'Refresh data sources', 'veloxa-solar-widget' ),
			History_Store::PRUNE_HOOK   => __( 'Remove old history and error log entries', 'veloxa-solar-widget' ),
		);
		$jobs  = array();

		foreach ( $hooks as $hook => $label ) {
			$next     = wp_next_scheduled( $hook );
			$last_run = Diagnostics_Logger::get_cron_status( $hook );

			if ( null !== $last_run ) {
				$last_run['message'] = Diagnostics_Logger::redact_secrets( (string) $last_run['message'] );
			}

			$jobs[] = array(
				'hook'     => $hook,
				'label'    => $label,
				'next_run' => false === $next ? null : (int) $next,
				'last_run' => $last_run,
			);
		}

		return $jobs;
	}

	/**
	 * Połączenia z ich stanem. Bez URL-i, tokenów i nazw encji — raport
	 * trafia do wsparcia, a URL Generic REST bywa sekretem (klucz w query).
	 *
	 * @param int $now Bieżący czas (unix, UTC).
	 * @return array<int, array<string, mixed>>
	 */
	public function connections_info( int $now ): array {
		$types    = $this->connector_manager->get_types();
		$statuses = Diagnostics_Logger::get_all_connection_statuses();
		$rows     = array();

		foreach ( $this->connector_manager->get_connections() as $connection ) {
			$id         = (string) $connection['id'];
			$type       = (string) ( $connection['connector'] ?? '' );
			$source     = $this->connector_manager->make( $connection );
			$reading    = Reading_Store::get( $id );
			$last_error = Reading_Store::get_last_error( $id );
			$attempt_at = Reading_Store::get_last_attempt_at( $id );
			$interval   = $source instanceof Abstract_Source ? $source->refresh_interval() : Abstract_Source::MIN_REFRESH_MINUTES * MINUTE_IN_SECONDS;
			$received   = Push_Source::CONNECTOR_TYPE === $type ? $this->push_channel_manager->get_last_received_at( $id ) : null;
			$polled     = $source instanceof Polled_Source;
			$attempts   = $polled ? Reading_Store::attempts_since( $id, $now - DAY_IN_SECONDS ) : null;
			$expected   = (int) floor( DAY_IN_SECONDS / max( MINUTE_IN_SECONDS, $interval ) );
			$tracked    = Reading_Store::attempts_tracked_since( $id );

			if ( ! $source instanceof Abstract_Source ) {
				$status = self::STATUS_UNKNOWN;
			} elseif ( Ha_Source::CONNECTOR_TYPE === $type && empty( $connection['fields'] ) ) {
				$status = self::STATUS_UNMAPPED;
			} elseif ( null !== $last_error ) {
				$status = self::STATUS_ERROR;
			} elseif ( null === $reading ) {
				$status = self::STATUS_NO_DATA;
			} elseif ( $reading->is_stale( $interval, $now ) ) {
				$status = self::STATUS_STALE;
			} else {
				$status = self::STATUS_OK;
			}

			$rows[] = array(
				'id'                 => $id,
				'name'               => (string) ( $connection['name'] ?? $id ),
				'type'               => $type,
				'type_label'         => $types[ $type ] ?? $type,
				'public'             => ! empty( $connection['public'] ),
				'status'             => $status,
				'fields'             => null === $reading ? array() : $reading->fields(),
				'updated_at'         => null === $reading ? null : $reading->updated_at(),
				'approximate'        => null !== $reading && $reading->is_updated_at_approximate(),
				'last_attempt_at'    => $attempt_at,
				'last_received_at'   => null === $received ? null : (int) strtotime( $received . ' UTC' ),
				'refresh_minutes'    => (int) ( $interval / MINUTE_IN_SECONDS ),
				'last_error'         => null === $last_error ? null : Diagnostics_Logger::redact_secrets( $last_error ),
				'consecutive_errors' => (int) ( $statuses[ $id ]['consecutive_errors'] ?? 0 ),
				'attempts_24h'       => $attempts,
				'expected_24h'       => $polled ? $expected : null,
				'rare_refresh'       => $polled && null !== $tracked && $tracked <= $now - DAY_IN_SECONDS && $attempts < self::RARE_REFRESH_RATIO * $expected,
			);
		}

		return $rows;
	}

	/**
	 * Liczba wierszy w tabelach wtyczki — czy sprzątanie działa.
	 *
	 * @return array<int, array{label: string, count: int}>
	 */
	private function data_size_info(): array {
		global $wpdb;

		// Jawne nazwy tabel zamiast zmiennej — Plugin Check
		// (UnescapedDBParameter) zgłasza nazwę tabeli przekazaną zmienną,
		// a %i w prepare() wymaga WP 6.2 (wtyczka wspiera 6.0).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array(
			array(
				'label' => __( 'Production history', 'veloxa-solar-widget' ),
				'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}veloxa_solar_history" ),
			),
			array(
				'label' => __( 'Push channel entities', 'veloxa-solar-widget' ),
				'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}veloxa_solar_push_entities" ),
			),
			array(
				'label' => __( 'Daily production totals', 'veloxa-solar-widget' ),
				'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}veloxa_solar_daily" ),
			),
			array(
				'label' => __( 'Error log', 'veloxa-solar-widget' ),
				'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}veloxa_solar_error_log" ),
			),
		);
		// phpcs:enable
	}

	/**
	 * Czytelna etykieta stanu połączenia.
	 *
	 * @param string $status Stała STATUS_*.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			self::STATUS_OK       => __( 'OK', 'veloxa-solar-widget' ),
			self::STATUS_STALE    => __( 'Data out of date', 'veloxa-solar-widget' ),
			self::STATUS_ERROR    => __( 'Error', 'veloxa-solar-widget' ),
			self::STATUS_NO_DATA  => __( 'No data yet', 'veloxa-solar-widget' ),
			self::STATUS_UNMAPPED => __( 'Sensors not chosen', 'veloxa-solar-widget' ),
			self::STATUS_UNKNOWN  => __( 'Unknown connection type', 'veloxa-solar-widget' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Czas UTC do raportu, albo "—".
	 *
	 * @param int|null $timestamp Unix, UTC.
	 * @return string
	 */
	public static function utc( ?int $timestamp ): string {
		return null === $timestamp ? '—' : gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC';
	}

	/**
	 * Raport tekstowy do schowka — ta sama treść co ekran. Dane wejściowe
	 * już zredagowane w collect().
	 *
	 * @param array<string, mixed> $data Wynik collect().
	 * @return string
	 */
	public static function build_report_text( array $data ): string {
		$env   = $data['environment'];
		$yes   = __( 'yes', 'veloxa-solar-widget' );
		$no    = __( 'no', 'veloxa-solar-widget' );
		$lines = array(
			__( 'Veloxa Solar Widget — Diagnostic report', 'veloxa-solar-widget' ),
			/* translators: %s: date and time in UTC */
			sprintf( __( 'Generated: %s', 'veloxa-solar-widget' ), self::utc( $data['generated_at'] ) ),
			'',
			__( '== Environment ==', 'veloxa-solar-widget' ),
			/* translators: %s: version number */
			sprintf( __( 'Plugin version: %s', 'veloxa-solar-widget' ), $env['plugin_version'] ),
			/* translators: %s: yes or no */
			sprintf( __( 'Pro features installed: %s', 'veloxa-solar-widget' ), $env['pro_code_installed'] ? $yes : $no ),
			/* translators: %s: version number */
			sprintf( __( 'WordPress version: %s', 'veloxa-solar-widget' ), $env['wp_version'] ),
			/* translators: %s: version number */
			sprintf( __( 'PHP version: %s', 'veloxa-solar-widget' ), $env['php_version'] ),
			/* translators: %s: yes or no */
			sprintf( __( 'Multisite: %s', 'veloxa-solar-widget' ), $env['multisite'] ? $yes : $no ),
			/* translators: %s: timezone name or UTC offset */
			sprintf( __( 'Site timezone: %s', 'veloxa-solar-widget' ), $env['timezone'] ),
			/* translators: %s: theme name and version */
			sprintf( __( 'Active theme: %s', 'veloxa-solar-widget' ), $env['active_theme'] ),
			/* translators: %s: PHP memory limit, e.g. 256M */
			sprintf( __( 'Memory limit: %s', 'veloxa-solar-widget' ), $env['memory_limit'] ),
			/* translators: %s: seconds */
			sprintf( __( 'Max execution time: %ss', 'veloxa-solar-widget' ), $env['max_execution_time'] ),
			/* translators: %s: yes or no */
			sprintf( __( 'WP-Cron disabled (DISABLE_WP_CRON): %s', 'veloxa-solar-widget' ), $env['wp_cron_disabled'] ? $yes : $no ),
			/* translators: %s: yes or no */
			sprintf( __( 'Local network addresses allowed: %s', 'veloxa-solar-widget' ), $env['allow_local_network'] ? $yes : $no ),
			/* translators: %s: comma-separated plugin names */
			sprintf( __( 'Other active plugins: %s', 'veloxa-solar-widget' ), empty( $env['other_plugins'] ) ? __( 'none', 'veloxa-solar-widget' ) : implode( ', ', $env['other_plugins'] ) ),
			'',
			__( '== Scheduled tasks ==', 'veloxa-solar-widget' ),
		);

		foreach ( $data['cron'] as $job ) {
			$lines[] = sprintf(
				/* translators: 1: task name, 2: status, 3: next run, 4: last run */
				__( '%1$s: %2$s (next: %3$s, last: %4$s)', 'veloxa-solar-widget' ),
				$job['label'],
				self::cron_status_text( $job ),
				self::utc( $job['next_run'] ),
				null === $job['last_run'] ? '—' : $job['last_run']['ran_at'] . ' UTC'
			);

			if ( null !== $job['last_run'] && '' !== $job['last_run']['message'] ) {
				$lines[] = '  ' . $job['last_run']['message'];
			}
		}

		$lines[] = '';
		$lines[] = __( '== Connections ==', 'veloxa-solar-widget' );

		if ( empty( $data['connections'] ) ) {
			$lines[] = __( 'None configured.', 'veloxa-solar-widget' );
		}

		foreach ( $data['connections'] as $row ) {
			$lines[] = sprintf(
				/* translators: 1: connection name, 2: connection ID, 3: connection type, 4: status */
				__( '%1$s [%2$s] (%3$s): %4$s', 'veloxa-solar-widget' ),
				$row['name'],
				$row['id'],
				$row['type_label'],
				self::status_label( $row['status'] )
			);
			$lines[] = sprintf(
				/* translators: 1: measured at, 2: last attempt, 3: refresh interval in minutes, 4: consecutive errors, 5: yes or no */
				__( '  Measured: %1$s, last attempt: %2$s, every %3$d min, consecutive errors: %4$d, public: %5$s', 'veloxa-solar-widget' ),
				self::utc( $row['updated_at'] ) . ( $row['approximate'] ? ' ' . __( '(approximate)', 'veloxa-solar-widget' ) : '' ),
				self::utc( $row['last_attempt_at'] ),
				$row['refresh_minutes'],
				$row['consecutive_errors'],
				$row['public'] ? $yes : $no
			);

			if ( null !== $row['attempts_24h'] ) {
				/* translators: 1: number of refresh attempts, 2: expected number */
				$lines[] = sprintf( __( '  Refreshes in the last 24 h: %1$d (expected about %2$d)', 'veloxa-solar-widget' ), $row['attempts_24h'], $row['expected_24h'] );
			}

			if ( ! empty( $row['fields'] ) ) {
				/* translators: %s: comma-separated field names */
				$lines[] = sprintf( __( '  Fields: %s', 'veloxa-solar-widget' ), implode( ', ', $row['fields'] ) );
			}

			if ( null !== $row['last_received_at'] ) {
				/* translators: %s: date and time in UTC */
				$lines[] = sprintf( __( '  Last push received: %s', 'veloxa-solar-widget' ), self::utc( $row['last_received_at'] ) );
			}

			if ( null !== $row['last_error'] ) {
				/* translators: %s: error message */
				$lines[] = sprintf( __( '  Last error: %s', 'veloxa-solar-widget' ), $row['last_error'] );
			}
		}

		$lines[] = '';
		$lines[] = __( '== Recent errors ==', 'veloxa-solar-widget' );

		if ( empty( $data['errors'] ) ) {
			$lines[] = __( 'None recorded.', 'veloxa-solar-widget' );
		}

		foreach ( $data['errors'] as $entry ) {
			$lines[] = sprintf( '[%1$s UTC] %2$s: %3$s', $entry['occurred_at'], $entry['source'], $entry['message'] );
		}

		$lines[] = '';
		$lines[] = __( '== Data size ==', 'veloxa-solar-widget' );

		foreach ( $data['data_sizes'] as $table ) {
			$lines[] = sprintf( '%1$s: %2$d', $table['label'], $table['count'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Stan zadania crona.
	 *
	 * @param array<string, mixed> $job Wiersz z cron_info().
	 * @return string
	 */
	public static function cron_status_text( array $job ): string {
		if ( null === $job['next_run'] ) {
			return __( 'Not scheduled', 'veloxa-solar-widget' );
		}

		if ( null === $job['last_run'] ) {
			return __( 'Scheduled, not run yet', 'veloxa-solar-widget' );
		}

		return $job['last_run']['success'] ? __( 'Running', 'veloxa-solar-widget' ) : __( 'Last run failed', 'veloxa-solar-widget' );
	}
}
