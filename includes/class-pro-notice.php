<?php
/**
 * Adres ekranu pełnoekranowego (/solar-display/…), gdy funkcje Pro nie
 * działają (paczka bez kodu Pro albo Pro bez licencji): administrator widzi
 * informację zamiast gołego 404. Pozostali dostają zwykłą odpowiedź
 * WordPressa. Bez warunku licencyjnego — Plugin::pro_features_available().
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pro_Notice
 */
class Pro_Notice {

	/**
	 * Prefiks adresu ekranu pełnoekranowego (ten sam co w kodzie Pro).
	 */
	const DISPLAY_PATH = 'solar-display';

	/**
	 * Parametr adresu ekranu przy bezpośrednich permalinkach.
	 */
	const DISPLAY_QUERY_VAR = 'veloxa_solar_display';

	/**
	 * Konstruktor.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_explain_display' ), 1 );
		add_action( 'init', array( __CLASS__, 'drop_stale_display_rules' ), 99 );
	}

	/**
	 * Gdy funkcje Pro nie działają (paczka darmowa, Pro bez licencji), a w
	 * bazie zostały reguły /solar-display/ z czasu, gdy działały — przepisuje
	 * reguły raz, żeby gość dostał zwykłe 404 zamiast strony głównej. Bez
	 * kosztu w zwykłym przypadku: flush tylko, gdy stara reguła istnieje.
	 */
	public static function drop_stale_display_rules(): void {
		if ( Plugin::pro_features_available() ) {
			return;
		}

		$rules = get_option( 'rewrite_rules' );

		if ( ! is_array( $rules ) ) {
			return;
		}

		foreach ( array_keys( $rules ) as $pattern ) {
			if ( false !== strpos( (string) $pattern, self::DISPLAY_PATH ) ) {
				flush_rewrite_rules( false );
				// Po powrocie licencji tryb pełnoekranowy doda regułę ponownie.
				delete_option( 'veloxa_solar_rewrite_version' );
				return;
			}
		}
	}

	/**
	 * Czy bieżące żądanie to adres ekranu pełnoekranowego.
	 *
	 * @return bool
	 */
	public static function is_display_request(): bool {
		if ( isset( $_GET[ self::DISPLAY_QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko rozpoznanie adresu.
			return true;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) + 1 );
		}

		return 0 === strpos( $path . '/', self::DISPLAY_PATH . '/' );
	}

	/**
	 * Informacja dla administratora na adresie ekranu w paczce bez kodu Pro.
	 */
	public function maybe_explain_display(): void {
		if ( Plugin::pro_features_available() || ! self::is_display_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		nocache_headers();

		wp_die(
			/**
			 * Komunikat dla administratora na adresie ekranu pełnoekranowego,
			 * gdy funkcje Pro nie działają.
			 *
			 * @param string $message Komunikat.
			 */
			esc_html( (string) apply_filters( 'veloxa_solar_pro_display_notice', __( 'The full-screen display is available in the Pro version.', 'veloxa-solar-widget' ) ) ),
			esc_html__( 'Veloxa Solar Widget', 'veloxa-solar-widget' ),
			array(
				'response'  => 404,
				'back_link' => true,
			)
		);
	}
}
