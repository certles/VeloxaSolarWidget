<?php
/**
 * Dane formularzy źródeł z $_POST — tylko oczekiwane klucze, każde pole
 * sanityzowane przy odczycie (recenzja wp.org 0.7.1). Dalej (funkcje
 * *_from_post() i filtry) trafiają wyłącznie dane już oczyszczone.
 *
 * Nonce i uprawnienia sprawdza wywołujący handler — ta klasa tylko czyta.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Admin;

use Veloxa\Solar\Model\PV_Reading;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Input
 */
class Form_Input {

	/**
	 * Pola tekstowe formularzy źródeł (sanitize_text_field).
	 */
	const TEXT_KEYS = array( 'id', 'name', 'token', 'updated_at_path', 'timezone', 'battery_idle_watts', 'refresh_minutes' );

	/**
	 * Pola wyboru (true/false).
	 */
	const FLAG_KEYS = array( 'estimate_home_power', 'home_from_balance', 'clear_token' );

	/**
	 * Dozwolone podklucze fields[pole][…].
	 */
	const FIELD_KEYS = array( 'enabled', 'unit', 'invert', 'path', 'entity' );

	/**
	 * Formularz źródła z $_POST: oczekiwane klucze, sanityzowane.
	 *
	 * @return array<string, mixed>
	 */
	public static function source_post(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce sprawdza handler przed wywołaniem.
		$data = array();

		foreach ( self::text_keys() as $key ) {
			if ( isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) {
				$data[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		foreach ( self::FLAG_KEYS as $key ) {
			$data[ $key ] = isset( $_POST[ $key ] ) && rest_sanitize_boolean( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
		}

		if ( isset( $_POST['url'] ) && is_scalar( $_POST['url'] ) ) {
			$data['url'] = esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['url'] ) ) ), array( 'http', 'https' ) );
		}

		$fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? map_deep( wp_unslash( $_POST['fields'] ), 'sanitize_text_field' ) : array();
		// phpcs:enable

		$data['fields'] = self::fields( $fields );

		return $data;
	}

	/**
	 * Klucze tekstowe: wspólne i zgłoszone przez moduły (np. układ ekranu Pro).
	 *
	 * @return string[]
	 */
	private static function text_keys(): array {
		/**
		 * Dodatkowe klucze opcji źródła z pól veloxa_solar_source_options_fields.
		 * Wartości trafiają do filtra veloxa_solar_source_options_from_post już
		 * po sanitize_text_field().
		 *
		 * @param string[] $keys Klucze.
		 */
		$extra = apply_filters( 'veloxa_solar_source_option_keys', array() );
		$extra = is_array( $extra ) ? array_map( 'sanitize_key', array_filter( $extra, 'is_string' ) ) : array();

		return array_values( array_unique( array_merge( self::TEXT_KEYS, $extra ) ) );
	}

	/**
	 * Pola fields[pole][podklucz] — tylko pola modelu PV i znane podklucze.
	 *
	 * @param mixed $posted Dane po sanitize_text_field().
	 * @return array<string, array<string, string>>
	 */
	public static function fields( $posted ): array {
		$fields = array();

		if ( ! is_array( $posted ) ) {
			return $fields;
		}

		foreach ( array_keys( PV_Reading::FIELD_UNITS ) as $field ) {
			if ( ! isset( $posted[ $field ] ) || ! is_array( $posted[ $field ] ) ) {
				continue;
			}

			foreach ( self::FIELD_KEYS as $key ) {
				if ( isset( $posted[ $field ][ $key ] ) && is_scalar( $posted[ $field ][ $key ] ) ) {
					$fields[ $field ][ $key ] = (string) $posted[ $field ][ $key ];
				}
			}
		}

		return $fields;
	}
}
