<?php
/**
 * Główna klasa wtyczki.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar;

use Veloxa\Solar\Core\Connector_Manager;
use Veloxa\Solar\Core\Diagnostics_Logger;
use Veloxa\Solar\Core\Push_Channel_Manager;
use Veloxa\Solar\Core\Push_Ingest;
use Veloxa\Solar\Admin\Connections_Page;
use Veloxa\Solar\Admin\Diagnostics_Page;
use Veloxa\Solar\Model\History_Store;
use Veloxa\Solar\Sources\Ha_Source;
use Veloxa\Solar\Sources\Push_Source;
use Veloxa\Solar\Sources\Rest_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 */
final class Plugin {

	/**
	 * Opcja z ustawieniami ogólnymi wtyczki (Faza 1: tylko
	 * 'remove_data_on_uninstall').
	 */
	const SETTINGS_OPTION = 'veloxa_solar_settings';

	/**
	 * Domyślny współczynnik unikniętej emisji (kg CO₂ na kWh) — około
	 * emisyjności polskiej sieci wg KOBiZE (decyzja właściciela 2026-10-10).
	 * Ustawiany w Solar Widget → Connections → Settings.
	 */
	const DEFAULT_CO2_KG_PER_KWH = 0.7;

	/**
	 * Największy przyjmowany współczynnik (kg CO₂/kWh) — sieci najbardziej
	 * węglowe są poniżej 1,1; 2 chroni przed pomyłką o rząd wielkości.
	 */
	const MAX_CO2_KG_PER_KWH = 2.0;

	/**
	 * Wersja schematu bazy — podbijana przy każdej zmianie tabel.
	 * maybe_upgrade_db() tworzy/aktualizuje tabele, gdy zapisana wersja
	 * jest inna (aktualizacja wtyczki nie odpala activate()).
	 * 2 = tabela veloxa_solar_history (2026-09-24, za zgodą właściciela).
	 * 3 = kolumna home_power w historii (2026-09-30, za zgodą właściciela).
	 * 4 = tabela veloxa_solar_daily (docs/etap-1.md, 1.6).
	 */
	const DB_VERSION = '4';

	/**
	 * Jedyna instancja klasy.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Menedżer konektorów.
	 *
	 * @var Connector_Manager
	 */
	public $connector_manager;

	/**
	 * Whitelist encji i ostatnia wartość per kanał push.
	 *
	 * @var Push_Channel_Manager
	 */
	public $push_channel_manager;

	/**
	 * Endpoint REST przyjmujący odczyty push.
	 *
	 * @var Push_Ingest
	 */
	public $push_ingest;

	/**
	 * Ekran admina z diagnostyką.
	 *
	 * @var Diagnostics_Page
	 */
	public $diagnostics_page;

	/**
	 * Ekran admina z listą połączeń.
	 *
	 * @var Connections_Page
	 */
	public $connections_page;

	/**
	 * Odświeżanie źródeł w tle (cron).
	 *
	 * @var Source_Refresher
	 */
	public $source_refresher;

	/**
	 * Prośba o opinię na WordPress.org.
	 *
	 * @var Review_Notice
	 */
	public $review_notice;

	/**
	 * Renderer widgetów (shortcode i blok).
	 *
	 * @var Widgets\Widget_Renderer
	 */
	public $widget_renderer;

	/**
	 * Zwraca jedyną instancję klasy (singleton).
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Konstruktor prywatny — wymusza użycie instance().
	 */
	private function __construct() {
		// Przed modułami — ich konstruktory tłumaczą teksty (class-i18n.php,
		// dołączany w pliku głównym).
		I18n::init();
		$this->includes();
		self::maybe_upgrade_db();
		$this->init_modules();
	}

	/**
	 * Tworzy/aktualizuje tabele, gdy wersja schematu w bazie jest inna niż
	 * DB_VERSION. Opcja autoload — koszt na żądanie to jedno porównanie.
	 */
	public static function maybe_upgrade_db(): void {
		if ( self::DB_VERSION === get_option( 'veloxa_solar_db_version' ) ) {
			return;
		}

		self::create_tables();
	}

	/**
	 * Tworzy wszystkie tabele wtyczki (dbDelta — bezpieczne przy ponownym
	 * uruchomieniu) i zapisuje wersję schematu.
	 */
	private static function create_tables(): void {
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/core/class-diagnostics-logger.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/core/class-push-channel-manager.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/model/class-pv-reading.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/model/class-history-store.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/model/class-daily-store.php';

		Diagnostics_Logger::create_tables();
		Push_Channel_Manager::create_table();
		History_Store::create_table();
		\Veloxa\Solar\Model\Daily_Store::create_table();

		update_option( 'veloxa_solar_db_version', self::DB_VERSION );
	}

	/**
	 * Ładuje wymagane pliki klas.
	 */
	private function includes(): void {
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/core/class-value-normalizer.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/core/class-credential-cipher.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/core/class-messages.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/core/class-diagnostics-logger.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/core/class-cache.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/core/class-json-path.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/core/class-http-client.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/core/connectors/abstract-connector.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/core/class-connector-manager.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/core/class-push-channel-manager.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/core/class-push-ingest.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/model/class-pv-reading.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/model/class-unit-converter.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/model/class-reading-store.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/model/class-history-store.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/model/class-daily-store.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/model/class-history-gaps.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/model/class-sanity-checks.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/sources/abstract-source.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/sources/interface-polled-source.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/sources/class-rest-source.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/sources/class-push-source.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/sources/class-ha-source.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/sources/class-ha-history-backfill.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/class-source-refresher.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/widgets/class-formatter.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/widgets/class-widget-renderer.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/widgets/class-shortcode.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/widgets/class-block.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'admin/class-form-input.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'admin/class-connections-page.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'admin/class-diagnostics-page.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'admin/class-site-health.php';

		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/class-pro-notice.php';
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/class-review-notice.php';

		// Kod Pro (brief sekcja 10): fizycznie w includes/premium/, wycinany
		// z paczki darmowej przez preprocesor Freemiusa. Tu wyłącznie
		// sprawdzenie, czy plik istnieje — żadnego warunku licencyjnego.
		if ( file_exists( VELOXA_SOLAR_PLUGIN_DIR . 'includes/premium/bootstrap.php' ) ) {
			require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/premium/bootstrap.php';
		}
	}

	/**
	 * Inicjalizuje moduły wtyczki.
	 */
	private function init_modules(): void {
		$this->connector_manager = new Connector_Manager();
		$this->connector_manager->register(
			Push_Source::CONNECTOR_TYPE,
			Push_Source::class,
			__( 'Push (device sends data to your site)', 'veloxa-solar-widget' )
		);
		$this->connector_manager->register(
			Rest_Source::CONNECTOR_TYPE,
			Rest_Source::class,
			__( 'Generic REST / JSON API', 'veloxa-solar-widget' )
		);
		$this->connector_manager->register(
			Ha_Source::CONNECTOR_TYPE,
			Ha_Source::class,
			__( 'Home Assistant', 'veloxa-solar-widget' )
		);

		$this->push_channel_manager = new Push_Channel_Manager();
		$this->push_ingest          = new Push_Ingest( $this->connector_manager, $this->push_channel_manager );
		$this->source_refresher     = new Source_Refresher( $this->connector_manager );

		add_action( 'veloxa_solar_push_received', array( $this, 'sync_push_channel' ) );

		$this->widget_renderer = new Widgets\Widget_Renderer( $this->connector_manager );
		new Widgets\Shortcode( $this->widget_renderer );
		new Widgets\Block( $this->widget_renderer );
		$this->connections_page = new Connections_Page( $this->connector_manager, $this->push_channel_manager, $this->source_refresher );
		$this->diagnostics_page = new Diagnostics_Page( $this->connector_manager, $this->push_channel_manager );
		new Admin\Site_Health( $this->diagnostics_page );
		new Pro_Notice();
		$this->review_notice = new Review_Notice( $this->connector_manager );

		/**
		 * Moduły wtyczki gotowe — punkt wpięcia dla kodu z includes/premium/.
		 *
		 * @param Plugin $plugin Instancja wtyczki.
		 */
		do_action( 'veloxa_solar_modules_loaded', $this );
	}

	/**
	 * Czy kod Pro jest fizycznie w paczce. WYŁĄCZNIE sprawdzenie istnienia
	 * pliku — nie licencja (paczka darmowa po preprocesorze Freemiusa po
	 * prostu go nie ma). Służy tylko do wyboru komunikatu "dostępne w wersji
	 * Pro" zamiast "nieznany widget".
	 *
	 * @return bool
	 */
	public static function premium_code_installed(): bool {
		/**
		 * Nadpisanie wyniku — tylko do testów (symulacja paczki darmowej).
		 *
		 * @param bool $installed Czy includes/premium/bootstrap.php istnieje.
		 */
		return (bool) apply_filters( 'veloxa_solar_premium_code_installed', file_exists( VELOXA_SOLAR_PLUGIN_DIR . 'includes/premium/bootstrap.php' ) );
	}

	/**
	 * Współczynnik unikniętej emisji CO₂ (kg na kWh) z ustawień.
	 *
	 * @return float
	 */
	public static function co2_kg_per_kwh(): float {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$value    = is_array( $settings ) && isset( $settings['co2_kg_per_kwh'] ) && is_numeric( $settings['co2_kg_per_kwh'] ) ? (float) $settings['co2_kg_per_kwh'] : self::DEFAULT_CO2_KG_PER_KWH;

		return max( 0.0, min( self::MAX_CO2_KG_PER_KWH, $value ) );
	}

	/**
	 * Czy funkcje Pro faktycznie działają. Kod darmowy NIE sprawdza licencji
	 * (README.md, Guideline 5): domyślnie to samo co premium_code_installed(),
	 * a jedyny kod, który może to wyłączyć, to includes/premium/bootstrap.php
	 * (brak aktywnej licencji — brief, BLOKER 2). Dzięki temu paczka Pro bez
	 * licencji zachowuje się dokładnie jak paczka darmowa.
	 *
	 * @return bool
	 */
	public static function pro_features_available(): bool {
		/**
		 * Czy funkcje Pro są dostępne.
		 *
		 * @param bool $available Domyślnie: czy kod Pro jest w paczce.
		 */
		return (bool) apply_filters( 'veloxa_solar_pro_features_available', self::premium_code_installed() );
	}

	/**
	 * Menedżer konektorów.
	 *
	 * @return Connector_Manager
	 */
	public function connector_manager(): Connector_Manager {
		return $this->connector_manager;
	}

	/**
	 * Renderer widgetów.
	 *
	 * @return Widgets\Widget_Renderer
	 */
	public function widget_renderer(): Widgets\Widget_Renderer {
		return $this->widget_renderer;
	}

	/**
	 * Składa model PV z właśnie odebranych danych push.
	 *
	 * @param string $connection_id ID kanału.
	 */
	public function sync_push_channel( $connection_id ): void {
		$source = $this->connector_manager->get_connector_for( (string) $connection_id );

		if ( ! $source instanceof Push_Source ) {
			return;
		}

		// Dane są już zapisane — błąd przy składaniu modelu nie może
		// zamienić odpowiedzi dla urządzenia w 500 (urządzenie ponawiałoby
		// wysyłkę w nieskończoność). Trafia do diagnostyki.
		try {
			$source->sync( $this->push_channel_manager, time() );
		} catch ( \Throwable $e ) {
			Diagnostics_Logger::log_error( 'connection:' . (string) $connection_id, $e->getMessage() );
		}
	}

	/**
	 * Wołane przy aktywacji wtyczki — tworzy tabele.
	 */
	public static function activate(): void {
		self::create_tables();

		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/class-source-refresher.php';
		Source_Refresher::schedule();

		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/class-review-notice.php';
		Review_Notice::ensure_activation_time();

		// Dezinstalacja: zdarzenie Freemiusa 'after_uninstall' (plik główny) —
		// NIE register_uninstall_hook() tutaj: WordPress trzyma jeden callback
		// na plik wtyczki i nadpisałby rejestrację SDK.
	}

	/**
	 * Wołane przy deaktywacji wtyczki. Nie usuwa tabel/danych — to rola
	 * uninstall().
	 */
	public static function deactivate(): void {
		require_once VELOXA_SOLAR_PLUGIN_DIR . 'includes/class-source-refresher.php';
		Source_Refresher::unschedule();
	}

	/**
	 * Wołane przy odinstalowaniu (usunięciu) wtyczki. Opt-in, jak w IoT
	 * Data Widget (IOT_DW_Plugin::uninstall()): bez zaznaczenia
	 * 'remove_data_on_uninstall' w veloxa_solar_settings nie usuwa NICZEGO —
	 * przypadkowe usunięcie wtyczki nie może skasować kanałów push, których
	 * tokenów użytkownik już nigdzie nie odzyska.
	 *
	 * Dotyka WYŁĄCZNIE nazw z prefiksem veloxa_solar_ (brief sekcja 2.1) —
	 * kryterium akceptacji Fazy 1: dezinstalacja Solara nie rusza danych
	 * IoT Data Widget (iot_dw_*).
	 *
	 * Przy dodawaniu nowej opcji/tabeli — dopisać ją TUTAJ w tym samym
	 * commicie (lekcja z IoT: iot_dw_push_entities, iot_dw_alert_rules i
	 * iot_dw_style_settings brakowało w czyszczeniu, znalezione dopiero przy
	 * audytach).
	 */
	public static function uninstall(): void {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		if ( empty( $settings['remove_data_on_uninstall'] ) ) {
			return;
		}

		delete_option( self::SETTINGS_OPTION );
		delete_option( 'veloxa_solar_connections' );
		delete_option( 'veloxa_solar_db_version' );
		delete_option( 'veloxa_solar_rewrite_version' );
		delete_option( 'veloxa_solar_activated_at' );
		delete_metadata( 'user', 0, 'veloxa_solar_review_notice', '', true );
		wp_clear_scheduled_hook( 'veloxa_solar_refresh_sources' );
		wp_clear_scheduled_hook( 'veloxa_solar_prune_history' );
		wp_clear_scheduled_hook( 'veloxa_solar_rce_sync' );
		wp_clear_scheduled_hook( 'veloxa_solar_rce_cleanup' );

		global $wpdb;

		// Brak natywnego WP API do masowego usuwania transientów/opcji po
		// prefiksie — bezpośrednie zapytanie jest jedyną opcją. Obejmuje
		// cache (veloxa_solar_<md5>), rate limit push (veloxa_solar_push_rate_*),
		// jednorazowy token (veloxa_solar_new_token_*), status cronów
		// (veloxa_solar_cron_status_*, Diagnostics_Logger::CRON_STATUS_OPTION_PREFIX)
		// i ostatnie odczyty źródeł (veloxa_solar_reading_*, Reading_Store::OPTION_PREFIX).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		foreach ( array( '_transient_veloxa_solar_', '_transient_timeout_veloxa_solar_', 'veloxa_solar_cron_status_', 'veloxa_solar_reading_', 'veloxa_solar_rce_' ) as $prefix ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
		}

		// Nazwy tabel wpisane literalnie (nie przez stałe klas z
		// includes/core/) — przy odinstalowaniu ładowany jest tylko główny
		// plik wtyczki, klasy rdzenia nie są wczytane.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}veloxa_solar_push_entities" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}veloxa_solar_connection_status" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}veloxa_solar_error_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}veloxa_solar_history" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}veloxa_solar_daily" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}veloxa_solar_rce_prices" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}veloxa_solar_rce_export" );
		// phpcs:enable
	}

	/**
	 * Blokuje klonowanie instancji.
	 */
	private function __clone() {}

	/**
	 * Blokuje deserializację instancji.
	 *
	 * @throws \Exception Zawsze — deserializacja singletona jest zabroniona.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize the Plugin singleton.' );
	}
}
