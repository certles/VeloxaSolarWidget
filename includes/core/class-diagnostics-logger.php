<?php
/**
 * Centralny log statusu połączeń i błędów. Skopiowane z IoT Data Widget
 * (includes/class-diagnostics-logger.php, commit b10c54c — patrz SHARED.md),
 * namespace/nazwy/prefiksy dostosowane. Jedna różnica względem oryginału:
 * redact_secrets() nie zawiera wzorca dla linku bezpośredniego Supla —
 * Solar nie ma (i nie planuje w MVP) tego konektora, więc ten wzorzec byłby
 * martwym kodem.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Komunikaty crona składane przy odczycie — logger bywa ładowany sam
// (create_tables() przy aktywacji), więc zależność dołączana tutaj.
require_once __DIR__ . '/class-messages.php';

/**
 * Class Diagnostics_Logger
 */
class Diagnostics_Logger {

	const TABLE_CONNECTION_STATUS = 'veloxa_solar_connection_status';
	const TABLE_ERROR_LOG         = 'veloxa_solar_error_log';

	const ERROR_LOG_LIMIT = 200;

	const PERSISTENT_ERROR_THRESHOLD = 5;

	/**
	 * Tworzy tabele statusu połączeń i logu błędów.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$status_table    = $wpdb->prefix . self::TABLE_CONNECTION_STATUS;
		$error_table     = $wpdb->prefix . self::TABLE_ERROR_LOG;

		$sql = "CREATE TABLE {$status_table} (
			connection_id VARCHAR(36) NOT NULL,
			last_checked_at DATETIME NOT NULL,
			last_status ENUM('ok','error') NOT NULL,
			last_error_message TEXT NULL,
			consecutive_errors INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (connection_id)
		) {$charset_collate};
		CREATE TABLE {$error_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			occurred_at DATETIME NOT NULL,
			source VARCHAR(100) NOT NULL,
			message TEXT NOT NULL,
			PRIMARY KEY  (id),
			KEY occurred_at (occurred_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Zapisuje wynik realnego (niecachowanego) odczytu jednego połączenia —
	 * wołane wyłącznie z Cache::remember() na cache-miss.
	 *
	 * @param string $connection_id ID połączenia (UUID).
	 * @param array  $data          Znormalizowana odpowiedź — liczy się tylko klucz 'error'.
	 */
	public static function record_connection_result( string $connection_id, array $data ): void {
		if ( '' === $connection_id ) {
			return;
		}

		global $wpdb;

		$table    = $wpdb->prefix . self::TABLE_CONNECTION_STATUS;
		$is_error = ! empty( $data['error'] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (connection_id, last_checked_at, last_status, last_error_message, consecutive_errors)
				VALUES (%s, %s, %s, NULLIF(%s, ''), %d)
				ON DUPLICATE KEY UPDATE
					last_checked_at = VALUES(last_checked_at),
					last_status = VALUES(last_status),
					last_error_message = VALUES(last_error_message),
					consecutive_errors = IF(VALUES(last_status) = 'error', consecutive_errors + 1, 0)",
				$connection_id,
				current_time( 'mysql', true ),
				$is_error ? 'error' : 'ok',
				$is_error ? self::redact_secrets( (string) $data['error'] ) : '',
				$is_error ? 1 : 0
			)
		);
		// phpcs:enable

		if ( $is_error ) {
			self::log_error( 'connection:' . $connection_id, (string) $data['error'] );
		}
	}

	/**
	 * Zwraca AKTUALNĄ (sprzed tego odczytu) liczbę kolejnych błędów jednego
	 * połączenia — do wykładniczego backoffu w Cache.
	 *
	 * @param string $connection_id ID połączenia (UUID).
	 * @return int
	 */
	public static function get_consecutive_errors( string $connection_id ): int {
		if ( '' === $connection_id ) {
			return 0;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT consecutive_errors FROM ' . $wpdb->prefix . self::TABLE_CONNECTION_STATUS . ' WHERE connection_id = %s',
				$connection_id
			)
		);
		// phpcs:enable

		return null !== $count ? (int) $count : 0;
	}

	/**
	 * Zwraca status wszystkich połączeń, które kiedykolwiek miały realny
	 * (niecachowany) odczyt.
	 *
	 * @return array<string, array{connection_id: string, last_checked_at: string, last_status: string, last_error_message: string|null, consecutive_errors: int}>
	 */
	public static function get_all_connection_statuses(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE_CONNECTION_STATUS, ARRAY_A );

		foreach ( $rows as &$row ) {
			if ( null !== $row['last_error_message'] ) {
				$row['last_error_message'] = self::redact_secrets( $row['last_error_message'] );
			}
		}
		unset( $row );

		return array_column( $rows, null, 'connection_id' );
	}

	/**
	 * Zwraca najnowsze wpisy ogólnego logu błędów, najnowsze pierwsze.
	 *
	 * @param int $limit Maksymalna liczba wpisów do zwrócenia.
	 * @return array<int, array{id: int, occurred_at: string, source: string, message: string}>
	 */
	public static function get_recent_errors( int $limit = 50 ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE_ERROR_LOG . ' ORDER BY id DESC LIMIT %d', $limit ),
			ARRAY_A
		);
		// phpcs:enable

		foreach ( $rows as &$row ) {
			$row['message'] = self::redact_secrets( $row['message'] );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Prefiks nazwy opcji WP przechowującej wynik ostatniego uruchomienia
	 * danego hooka crona — jedna opcja per hook.
	 */
	const CRON_STATUS_OPTION_PREFIX = 'veloxa_solar_cron_status_';

	/**
	 * Wykonuje $callback i loguje wynik pod $hook.
	 *
	 * @param string   $hook     Hook crona.
	 * @param callable $callback Właściwy callback crona do wykonania — opcjonalnie zwraca array{success: bool, message?: string}.
	 */
	public static function run_cron( string $hook, callable $callback ): void {
		try {
			$result = $callback();

			if ( is_array( $result ) && isset( $result['success'] ) ) {
				self::record_cron_run( $hook, (bool) $result['success'], (string) ( $result['message'] ?? '' ), (string) ( $result['message_key'] ?? '' ), (array) ( $result['message_args'] ?? array() ) );
				return;
			}

			self::record_cron_run( $hook, true );
		} catch ( \Throwable $e ) {
			self::record_cron_run( $hook, false, $e->getMessage() );
		}
	}

	/**
	 * Zapisuje wynik jednego uruchomienia crona.
	 *
	 * @param string $hook    Hook crona.
	 * @param bool   $success Czy to uruchomienie zakończyło się powodzeniem.
	 * @param string $message Krótki komunikat — puste przy sukcesie bez uwag.
	 * @param string $key     Klucz Messages (Solar) — tekst składany przy wyświetleniu.
	 * @param array  $args    Liczby do komunikatu z kluczem.
	 */
	public static function record_cron_run( string $hook, bool $success, string $message = '', string $key = '', array $args = array() ): void {
		$message = self::redact_secrets( $message );

		update_option(
			self::CRON_STATUS_OPTION_PREFIX . $hook,
			array(
				'ran_at'       => current_time( 'mysql', true ),
				'success'      => $success,
				'message'      => $message,
				'message_key'  => $key,
				'message_args' => $args,
			),
			false
		);

		if ( ! $success ) {
			self::log_error( 'cron:' . $hook, $message );
		}
	}

	/**
	 * Zwraca wynik ostatniego uruchomienia danego hooka crona, albo null.
	 *
	 * @param string $hook Hook crona.
	 * @return array{ran_at: string, success: bool, message: string}|null
	 */
	public static function get_cron_status( string $hook ): ?array {
		$value = get_option( self::CRON_STATUS_OPTION_PREFIX . $hook, null );

		if ( ! is_array( $value ) ) {
			return null;
		}

		// Solar: komunikat w języku oglądającego (Messages), nie crona.
		$value['message'] = Messages::from_stored( $value );

		return $value;
	}

	/**
	 * Dopisuje wpis do ogólnego logu błędów.
	 *
	 * @param string $source  Źródło błędu, np. "connection:{id}" albo "cron:{hook}".
	 * @param string $message Surowy komunikat błędu (redagowany wewnątrz tej metody).
	 */
	public static function log_error( string $source, string $message ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . self::TABLE_ERROR_LOG,
			array(
				'occurred_at' => current_time( 'mysql', true ),
				'source'      => $source,
				'message'     => self::redact_secrets( $message ),
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Przycina log błędów do ERROR_LOG_LIMIT najnowszych wpisów. W IoT
	 * robi to cron sprzątający historię (IOT_DW_History_Recorder::trim_error_log());
	 * w Solarze tego kroku brakowało — źródło stale zwracające błąd dopisywało
	 * do 288 wierszy na dobę bez końca. Podzapytanie z aliasem, bo MySQL nie
	 * pozwala SELECT-ować z tabeli, z której się właśnie usuwa.
	 *
	 * @return int Liczba usuniętych wpisów.
	 */
	public static function trim_error_log(): int {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_ERROR_LOG;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $table . ' WHERE id NOT IN ( SELECT id FROM ( SELECT id FROM ' . $table . ' ORDER BY id DESC LIMIT %d ) AS keep_ids )',
				self::ERROR_LOG_LIMIT
			)
		);
		// phpcs:enable
	}

	/**
	 * Usuwa z komunikatu wszystko, co wygląda na token/klucz/hasło —
	 * obowiązkowe przed KAŻDYM zapisem do logu i przed KAŻDYM wyświetleniem
	 * w UI.
	 *
	 * @param string $message Surowy komunikat.
	 * @return string
	 */
	public static function redact_secrets( string $message ): string {
		// [\w-]* przed/po nazwie słowa kluczowego (nie tylko dokładne "token=")
		// — SolaX Cloud (Faza 2, brief sekcja 3.2) autoryzuje przez
		// "tokenId=", które dokładne dopasowanie "token=" by przepuściło.
		$redacted = preg_replace(
			'/([\w-]*(?:token|api[_-]?key|apikey|secret|password|passwd|pwd|auth)[\w-]*=)[^&\s"\']+/i',
			'$1[REDACTED]',
			$message
		);
		$message  = null !== $redacted ? $redacted : $message;

		$redacted = preg_replace(
			'/(Authorization:\s*(?:Bearer|Basic)\s+)\S+/i',
			'$1[REDACTED]',
			$message
		);
		$message  = null !== $redacted ? $redacted : $message;

		$redacted = preg_replace(
			'/(?!Authorization:)([\w-]*(?:key|token|secret|auth)[\w-]*:\s*)\S+/i',
			'$1[REDACTED]',
			$message
		);

		return null !== $redacted ? $redacted : $message;
	}
}
