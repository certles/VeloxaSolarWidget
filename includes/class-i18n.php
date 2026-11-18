<?php
/**
 * Ładowanie tłumaczeń: PHP, skrypt bloku i interfejs SDK Freemiusa.
 *
 * Kolejność (raport lokalizacji 2026-11, docs/raport-lokalizacja-2026-11.md):
 * 1. pakiet językowy z translate.wordpress.org (wp-content/languages/plugins),
 *    jeśli jest — ma pierwszeństwo dla wszystkich tekstów, które zawiera,
 * 2. plik z katalogu languages/ wtyczki — uzupełnia teksty, których pakiet
 *    nie ma (pakiet powstaje z wersji darmowej i nie zawiera tekstów Pro; nowe
 *    teksty pojawiają się w nim dopiero po zatwierdzeniu na translate.w.org).
 *
 * WordPress szuka tekstu we wszystkich plikach domeny w kolejności ich
 * załadowania (WP_Translation_Controller), więc oba pliki działają razem.
 * Ten sam kod w wersji darmowej i Pro — bez żadnego warunku licencyjnego.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class I18n
 */
final class I18n {

	/**
	 * Text domain wtyczki.
	 */
	const DOMAIN = 'veloxa-solar-widget';

	/**
	 * Podpina ładowanie tłumaczeń. Wołane z konstruktora Plugin (akcja init),
	 * zanim moduły zaczną tłumaczyć teksty.
	 */
	public static function init(): void {
		self::load();

		// switch_to_locale() (np. inny język profilu użytkownika) zwalnia
		// domeny — ładujemy oba pliki ponownie dla nowego języka.
		add_action( 'change_locale', array( self::class, 'load' ) );
		add_filter( 'load_script_translation_file', array( self::class, 'script_translation_file' ), 10, 3 );
	}

	/**
	 * Ładuje tłumaczenia PHP: najpierw pakiet wp.org, potem plik wtyczki.
	 *
	 * @param string $locale Język (z akcji change_locale); pusty = bieżący.
	 */
	public static function load( $locale = '' ): void {
		$locale = is_string( $locale ) && '' !== $locale ? $locale : determine_locale();

		if ( 'en_US' === $locale ) {
			return;
		}

		foreach ( array( self::language_pack_dir(), self::bundled_dir() ) as $dir ) {
			$mofile = $dir . self::DOMAIN . '-' . $locale . '.mo';

			if ( self::translation_file_exists( $mofile ) ) {
				load_textdomain( self::DOMAIN, $mofile, $locale );
			}
		}
	}

	/**
	 * Tłumaczenie interfejsu SDK Freemiusa (Konto, Cennik, Kontakt, ekran
	 * zgody). SDK 2.13.5 nie ma pl_PL — dokładamy pełny katalog domeny
	 * "freemius" z languages/ wtyczki, ten sam co w IoT Data Widget (SHARED.md).
	 * Pliki oficjalne (z SDK albo z wp-content/languages/plugins) ładujemy
	 * pierwsze, więc gdy się pojawią, mają pierwszeństwo. Tylko w panelu —
	 * SDK tłumaczy wyłącznie ekrany admina. Podpięte pod plugins_loaded
	 * (priorytet 1, jak Freemius::_load_textdomain) w pliku głównym — SDK
	 * składa część komunikatów przed init. Domena "freemius" jest wspólna
	 * dla wszystkich wtyczek z tym SDK, więc katalog działa niezależnie od
	 * tego, która wtyczka dostarczyła SDK.
	 */
	public static function load_freemius(): void {
		if ( ! is_admin() ) {
			return;
		}

		$locale = determine_locale();
		$own    = self::bundled_dir() . 'freemius-' . $locale . '.mo';

		if ( 'en_US' === $locale || ! is_readable( $own ) ) {
			return;
		}

		$official = array( self::language_pack_dir() . 'freemius-' . $locale . '.mo' );

		if ( defined( 'WP_FS__DIR' ) ) {
			$official[] = trailingslashit( WP_FS__DIR ) . 'languages/freemius-' . $locale . '.mo';
		}

		foreach ( $official as $mofile ) {
			if ( self::translation_file_exists( $mofile ) ) {
				load_textdomain( 'freemius', $mofile, $locale );
			}
		}

		load_textdomain( 'freemius', $own, $locale );
	}

	/**
	 * Rejestruje tłumaczenia skryptu (wp.i18n). Ścieżka to katalog pakietów
	 * wp.org — tam WordPress szuka najpierw; brakujący plik zastępuje
	 * script_translation_file() plikiem z languages/ wtyczki.
	 *
	 * @param string $handle Uchwyt zarejestrowanego skryptu.
	 */
	public static function register_script( string $handle ): void {
		wp_set_script_translations( $handle, self::DOMAIN, untrailingslashit( self::language_pack_dir() ) );
	}

	/**
	 * Filtr load_script_translation_file: gdy pakietu wp.org nie ma (albo nie
	 * ma w nim tłumaczeń skryptu), bierze plik JSON o tej samej nazwie z
	 * languages/ wtyczki.
	 *
	 * @param string|false $file   Ścieżka pliku JSON.
	 * @param string       $handle Uchwyt skryptu.
	 * @param string       $domain Text domain.
	 * @return string|false
	 */
	public static function script_translation_file( $file, $handle, $domain ) {
		unset( $handle );

		if ( self::DOMAIN !== $domain || ! is_string( $file ) || '' === $file || is_readable( $file ) ) {
			return $file;
		}

		if ( 0 !== strpos( wp_normalize_path( $file ), wp_normalize_path( self::language_pack_dir() ) ) ) {
			return $file;
		}

		$bundled = self::bundled_dir() . basename( $file );

		return is_readable( $bundled ) ? $bundled : $file;
	}

	/**
	 * Katalog pakietów językowych wtyczek z translate.wordpress.org.
	 *
	 * @return string Ze slashem na końcu.
	 */
	public static function language_pack_dir(): string {
		return trailingslashit( WP_LANG_DIR ) . 'plugins/';
	}

	/**
	 * Katalog tłumaczeń dołączonych do wtyczki.
	 *
	 * @return string Ze slashem na końcu.
	 */
	public static function bundled_dir(): string {
		return VELOXA_SOLAR_PLUGIN_DIR . 'languages/';
	}

	/**
	 * Czy jest plik tłumaczeń: .mo albo .l10n.php (format pakietów wp.org
	 * od WordPress 6.5 — load_textdomain() wybiera go sam).
	 *
	 * @param string $mofile Ścieżka pliku .mo.
	 * @return bool
	 */
	private static function translation_file_exists( string $mofile ): bool {
		return is_readable( $mofile ) || is_readable( substr( $mofile, 0, -3 ) . '.l10n.php' );
	}
}
