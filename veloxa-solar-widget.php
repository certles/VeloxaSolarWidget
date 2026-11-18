<?php

/**
 * Plugin Name:       Veloxa Solar Widget – Solar Production Display
 * Description:       Displays current solar production data (power, energy today/total) on your WordPress site via shortcode and Gutenberg block — a presentation layer for PV data you already monitor elsewhere.
 * Version:           0.7.12
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            WPVeloxa
 * Author URI:        https://wpveloxa.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       veloxa-solar-widget
 * Domain Path:       /languages
 *
 *
 * @package Veloxa\Solar
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Faza 1 planu (docs/wpveloxa-solar-brief.md, sekcja 2.1) — WSZYSTKIE
 * globalne nazwy (opcje, tabele, trasy REST, hooki, handle skryptów,
 * shortcode, blok) noszą prefiks veloxa_solar_/veloxa-solar-widget, ŻADNA nazwa
 * klasy/funkcji/stałej/pliku ładowanego globalnie nie pokrywa się z IoT
 * Data Widget (namespace Veloxa\Solar zamiast prefiksu IOT_DW_ —
 * wystarcza samo w sobie, bez dodatkowego prefiksowania nazw klas).
 */
/*
 * Freemius SDK (Faza 7, brief sekcja 10) — konfiguracja z panelu Freemiusa
 * (produkt 40053), dostosowana:
 *
 * - veloxa_solar_fs() / $veloxa_solar_fs / veloxa_solar_fs_loaded zamiast spw_fs()
 *   z wygenerowanego fragmentu — prefiks veloxa_solar_ (brief sekcja 2.1);
 * - premium_slug nadpisany na veloxa-solar-widget-premium: panel podaje
 *   iot-data-widget-premium (wartość z produktu IoT), a SDK bez tego pola
 *   i tak użyłby "{slug}-premium" — nigdy slug Pro innej wtyczki;
 * - is_premium => true na stałe — to kod pełny; preprocesor Freemiusa
 *   przełącza je na false i usuwa wp_org_gatekeeper w wersji darmowej
 *   (README.md, "Potok wydania").
 *
 * public_key i wp_org_gatekeeper są z definicji publiczne. Klucz tajny i
 * klucz API NIGDY nie trafiają do repo — SDK ich nie potrzebuje.
 *
 * Struktura if ( function_exists( 'veloxa_solar_fs' ) ) { set_basename } else
 * { … } jest wymagana przez SDK: gdy wersja darmowa i Pro są aktywne
 * jednocześnie, druga załadowana zgłasza się SDK i nie wykonuje reszty
 * pliku (inaczej te same klasy zostałyby zadeklarowane dwa razy).
 */
if ( function_exists( 'veloxa_solar_fs' ) ) {
    veloxa_solar_fs()->set_basename( false, __FILE__ );
} else {
    /**
     * DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE
     * `function_exists` CALL ABOVE TO PROPERLY WORK.
     */
    if ( !function_exists( 'veloxa_solar_fs' ) ) {
        /**
         * Zwraca (i przy pierwszym wywołaniu inicjalizuje) instancję SDK
         * Freemiusa dla tej wtyczki.
         *
         * @return Freemius
         */
        function veloxa_solar_fs() {
            global $veloxa_solar_fs;
            if ( !isset( $veloxa_solar_fs ) ) {
                // `require`, nie `require_once` — ten sam powód co w IoT Data
                // Widget: autoload Composera (np. w PHPUnit) potrafi wciągnąć
                // start.php, zanim WordPress zdefiniuje ABSPATH; plik wtedy
                // wraca bez niczego, a require_once byłby później cichym
                // no-opem i fs_dynamic_init() nigdy by nie powstało.
                if ( !function_exists( 'fs_dynamic_init' ) ) {
                    require __DIR__ . '/vendor/freemius/wordpress-sdk/start.php';
                }
                $veloxa_solar_fs = fs_dynamic_init( array(
                    'id'               => '40053',
                    'slug'             => 'veloxa-solar-widget',
                    'premium_slug'     => 'veloxa-solar-widget-premium',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_b666825918f47c6a9b95ad62fbe74',
                    'is_premium'       => false,
                    'premium_suffix'   => 'Pro',
                    'has_addons'       => false,
                    'has_paid_plans'   => true,
                    'is_org_compliant' => true,
                    'menu'             => array(
                        'slug'    => 'veloxa-solar-widget',
                        'support' => false,
                        'pricing' => true,
                    ),
                    'is_live'          => true,
                ) );
            }
            return $veloxa_solar_fs;
        }

        veloxa_solar_fs();
        // Ikona na stronie cennika SDK. Bez tego SDK szuka pliku {slug}.* we własnym katalogu
        // assets/img i na zwykłych witrynach (nie localhost) wstawia ogólną ikonę.
        veloxa_solar_fs()->add_filter( 'plugin_icon', static function () {
            return __DIR__ . '/public/img/plugin-icon.png';
        } );
        /**
         * SDK Freemiusa zainicjowane.
         */
        do_action( 'veloxa_solar_fs_loaded' );
    }
    define( 'VELOXA_SOLAR_VERSION', '0.7.12' );
    define( 'VELOXA_SOLAR_PLUGIN_FILE', __FILE__ );
    define( 'VELOXA_SOLAR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
    define( 'VELOXA_SOLAR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
    require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/class-plugin.php';
    require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/class-i18n.php';
    // Tłumaczenie interfejsu SDK Freemiusa — przed komunikatami SDK
    // (includes/class-i18n.php). Teksty wtyczki ładuje Plugin na init.
    add_action( 'plugins_loaded', array('\\Veloxa\\Solar\\I18n', 'load_freemius'), 1 );
    /**
     * Zwraca główną instancję wtyczki.
     *
     * @return \Veloxa\Solar\Plugin
     */
    function veloxa_solar_plugin() {
        return \Veloxa\Solar\Plugin::instance();
    }

    // Init, nie plugins_loaded — moduły wtyczki tłumaczą stringi w
    // konstruktorach, a WP 6.7+ zgłasza to jako błąd, jeśli dzieje się
    // wcześniej niż init (ten sam powód co w IoT Data Widget).
    add_action( 'init', 'veloxa_solar_plugin' );
    register_activation_hook( __FILE__, array('\\Veloxa\\Solar\\Plugin', 'activate') );
    register_deactivation_hook( __FILE__, array('\\Veloxa\\Solar\\Plugin', 'deactivate') );
    // Zamiast uninstall.php i register_uninstall_hook() — SDK Freemiusa sam
    // rejestruje hook dezinstalacji i po swojej logice (opcjonalna ankieta)
    // woła 'after_uninstall'. Fizyczny uninstall.php miałby w rdzeniu WP
    // pierwszeństwo i to zdarzenie nigdy by się nie odpaliło (jak w IoT).
    veloxa_solar_fs()->add_action( 'after_uninstall', array('\\Veloxa\\Solar\\Plugin', 'uninstall') );
}