<?php
/**
 * Komunikaty zapisywane w bazie jako klucz + liczby i składane dopiero przy
 * wyświetleniu — w języku oglądającego (profil admina), nie w języku
 * żądania, które je zapisało (cron, push urządzenia). Raport retestu 0.7.1.
 *
 * Stare wpisy (sam tekst, bez klucza) wyświetlają się bez zmian.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Messages
 */
class Messages {

	/**
	 * Cron odświeżania: [odświeżone, nieudane].
	 */
	const REFRESHED = 'refreshed';

	/**
	 * Cron czyszczenia: [wiersze historii, wpisy logu błędów].
	 */
	const PRUNED = 'pruned';

	/**
	 * Kanał push bez kompletu danych: [pole, pole, …].
	 */
	const WAITING_FOR = 'waiting_for';

	/**
	 * Tekst komunikatu albo null dla nieznanego klucza.
	 *
	 * @param string            $key  Klucz (stałe klasy).
	 * @param array<int, mixed> $args Liczby / nazwy pól.
	 * @return string|null
	 */
	public static function render( string $key, array $args ): ?string {
		$args = array_values( $args );

		switch ( $key ) {
			case self::REFRESHED:
				$refreshed = (int) ( $args[0] ?? 0 );
				$failed    = (int) ( $args[1] ?? 0 );

				return sprintf(
					/* translators: 1: number of refreshed data sources, 2: number of failed ones */
					_n( 'Refreshed %1$d source, %2$d failed.', 'Refreshed %1$d sources, %2$d failed.', $refreshed, 'veloxa-solar-widget' ),
					$refreshed,
					$failed
				);
			case self::PRUNED:
				$rows    = (int) ( $args[0] ?? 0 );
				$entries = (int) ( $args[1] ?? 0 );

				return sprintf(
					/* translators: 1: count of old history rows with its noun, 2: count of old error log entries with its noun */
					__( 'Removed %1$s and %2$s.', 'veloxa-solar-widget' ),
					/* translators: %d: number of rows */
					sprintf( _n( '%d old history row', '%d old history rows', $rows, 'veloxa-solar-widget' ), $rows ),
					/* translators: %d: number of entries */
					sprintf( _n( '%d old error log entry', '%d old error log entries', $entries, 'veloxa-solar-widget' ), $entries )
				);
			case self::WAITING_FOR:
				return sprintf(
					/* translators: %s: comma-separated entity names */
					__( 'Waiting for the device to send: %s.', 'veloxa-solar-widget' ),
					implode( ', ', array_map( 'strval', $args ) )
				);
		}

		return null;
	}

	/**
	 * Komunikat z zapisu: wg klucza, gdy jest i znany, inaczej zapisany tekst.
	 *
	 * @param array<string, mixed> $stored Zapis z polami message, message_key, message_args.
	 * @param string               $field  Pole z gotowym tekstem.
	 * @return string
	 */
	public static function from_stored( array $stored, string $field = 'message' ): string {
		$key  = (string) ( $stored['message_key'] ?? '' );
		$text = '' !== $key ? self::render( $key, (array) ( $stored['message_args'] ?? array() ) ) : null;

		return $text ?? (string) ( $stored[ $field ] ?? '' );
	}
}
