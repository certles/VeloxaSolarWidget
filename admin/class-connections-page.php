<?php
/**
 * Panel administracyjny — lista połączeń (Faza 1: szkielet minimalny, tylko
 * tyle, żeby dało się utworzyć kanał push i zweryfikować kryterium
 * akceptacji koegzystencji z IoT Data Widget — pełny kreator wieloetapowy
 * to osobne zadanie, nie blokujące Fazy 1).
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Admin;

use Veloxa\Solar\Core\Connector_Manager;
use Veloxa\Solar\Core\Push_Channel_Manager;
use Veloxa\Solar\Model\History_Store;
use Veloxa\Solar\Model\PV_Reading;
use Veloxa\Solar\Model\Reading_Store;
use Veloxa\Solar\Plugin;
use Veloxa\Solar\Source_Refresher;
use Veloxa\Solar\Model\Unit_Converter;
use Veloxa\Solar\Sources\Abstract_Source;
use Veloxa\Solar\Sources\Ha_Source;
use Veloxa\Solar\Sources\Push_Source;
use Veloxa\Solar\Sources\Rest_Source;
use Veloxa\Solar\Widgets\Formatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Connections_Page
 */
class Connections_Page {

	/**
	 * Menedżer konektorów.
	 *
	 * @var Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Whitelist encji i ostatnia wartość per kanał push.
	 *
	 * @var Push_Channel_Manager
	 */
	private $push_channel_manager;

	/**
	 * Odświeżanie źródeł (przycisk "Refresh now").
	 *
	 * @var Source_Refresher
	 */
	private $source_refresher;

	/**
	 * Konstruktor.
	 *
	 * @param Connector_Manager    $connector_manager    Menedżer konektorów.
	 * @param Push_Channel_Manager $push_channel_manager Whitelist encji + ostatnia wartość per kanał push.
	 * @param Source_Refresher     $source_refresher     Odświeżanie źródeł.
	 */
	public function __construct( Connector_Manager $connector_manager, Push_Channel_Manager $push_channel_manager, Source_Refresher $source_refresher ) {
		$this->connector_manager    = $connector_manager;
		$this->push_channel_manager = $push_channel_manager;
		$this->source_refresher     = $source_refresher;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_veloxa_solar_save_connection', array( $this, 'handle_save' ) );
		add_action( 'admin_post_veloxa_solar_delete_connection', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_veloxa_solar_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_veloxa_solar_save_rest_connection', array( $this, 'handle_save_rest' ) );
		add_action( 'admin_post_veloxa_solar_refresh_connection', array( $this, 'handle_refresh' ) );
		add_action( 'admin_post_veloxa_solar_save_ha_connection', array( $this, 'handle_save_ha' ) );
		add_action( 'admin_post_veloxa_solar_save_ha_mapping', array( $this, 'handle_save_ha_mapping' ) );
		add_action( 'admin_post_veloxa_solar_toggle_public', array( $this, 'handle_toggle_public' ) );
		add_action( 'admin_post_veloxa_solar_rotate_push_token', array( $this, 'handle_rotate_push_token' ) );
		add_action( 'admin_post_veloxa_solar_dismiss_getting_started', array( $this, 'handle_dismiss_getting_started' ) );
		add_action( 'admin_post_veloxa_solar_save_solax_connection', array( $this, 'handle_save_solax' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Typy formularzy dodawania źródeł dostępne na tej instalacji.
	 * Moduły Pro dopisują własne wpisy filtrem; kod Free nie zna ich formularzy.
	 *
	 * @return array<string, array{form_id: string, description: string}>
	 */
	public static function connection_form_types(): array {
		$types = array(
			Push_Source::CONNECTOR_TYPE => array(
				'form_id'     => 'veloxa-solar-push-form',
				'description' => __( 'Your device, Home Assistant automation or script sends readings to this site. Recommended for most externally hosted WordPress sites.', 'veloxa-solar-widget' ),
			),
			Ha_Source::CONNECTOR_TYPE   => array(
				'form_id'     => 'veloxa-solar-ha-form',
				'description' => __( 'Read selected solar sensors from a Home Assistant instance that your WordPress server can reach.', 'veloxa-solar-widget' ),
			),
			Rest_Source::CONNECTOR_TYPE => array(
				'form_id'     => 'veloxa-solar-rest-form',
				'description' => __( 'Map values from any JSON API and choose the units it uses.', 'veloxa-solar-widget' ),
			),
			'solax'                     => array(
				'form_id'     => 'veloxa-solar-solax-form',
				'description' => __( 'Connect directly to SolaxCloud. This integration is in beta.', 'veloxa-solar-widget' ),
			),
		);

		/**
		 * Formularze dodawania źródeł dostępne w bieżącym buildzie/licencji.
		 *
		 * @param array<string, array{form_id: string, description: string}> $types Typy konektorów.
		 */
		$types = apply_filters( 'veloxa_solar_connection_form_types', $types );

		return is_array( $types ) ? $types : array();
	}

	/**
	 * Źródła z wersji Pro pokazywane w wyborze źródła jako nieaktywne karty
	 * z odnośnikiem do planów (0.7.10). Bez sprawdzania licencji: karta
	 * znika, gdy moduł Pro sam zarejestrował formularz tego typu.
	 *
	 * @param array<string, mixed> $available_types Typy z formularzem na tej instalacji.
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function pro_source_cards( array $available_types ): array {
		$cards = array(
			'solarman'  => array(
				'label'       => 'Solarman',
				'description' => __( 'Connect to an inverter monitored in the Solarman cloud using your OpenAPI account.', 'veloxa-solar-widget' ),
			),
			'solaredge' => array(
				'label'       => 'SolarEdge',
				'description' => __( 'Connect a SolarEdge installation using an API key from the monitoring portal.', 'veloxa-solar-widget' ),
			),
		);

		return array_diff_key( $cards, $available_types );
	}

	/**
	 * Strona planów (Freemius) — cel odnośnika z kart Pro.
	 *
	 * @return string
	 */
	public static function plans_url(): string {
		if ( function_exists( 'veloxa_solar_fs' ) ) {
			return (string) veloxa_solar_fs()->pricing_url();
		}

		return admin_url( 'admin.php?page=veloxa-solar-widget-pricing' );
	}

	/**
	 * URL tej strony admina z dodatkowymi parametrami.
	 *
	 * @param array<string, string> $args Parametry query.
	 * @return string
	 */
	public static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => 'veloxa-solar-widget' ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Zapamiętuje błędy formularza (i bezpieczne do ponownego wypełnienia
	 * wartości) na minutę i przekierowuje z powrotem.
	 *
	 * @param string                $form     Identyfikator formularza (kotwica HTML).
	 * @param string[]              $errors   Błędy.
	 * @param array<string, mixed>  $values   Wartości BEZ sekretów.
	 * @param array<string, string> $url_args Parametry URL powrotu.
	 */
	private function fail_form( string $form, array $errors, array $values, array $url_args = array() ): void {
		if ( empty( $url_args ) ) {
			foreach ( self::connection_form_types() as $connector => $form_type ) {
				if ( ( $form_type['form_id'] ?? '' ) === $form ) {
					$url_args['veloxa_solar_add'] = (string) $connector;
					break;
				}
			}
		}

		set_transient(
			'veloxa_solar_form_' . get_current_user_id(),
			array(
				'form'   => $form,
				'errors' => $errors,
				'values' => $values,
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( self::page_url( $url_args ) . '#' . $form );
		exit;
	}

	/**
	 * Połączenie edytowane przez wysłany formularz: null przy dodawaniu (brak
	 * pola `id`), wp_die() gdy ID nie istnieje albo jest innego typu.
	 *
	 * @param string $type Oczekiwany typ konektora.
	 * @return array<string, mixed>|null
	 */
	private function connection_for_edit( string $type ): ?array {
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdza handler przed wywołaniem.

		if ( '' === $id ) {
			return null;
		}

		$connection = $this->connector_manager->find_by_id( $id );

		if ( null === $connection || $type !== $connection['connector'] ) {
			wp_die( esc_html__( 'Connection not found.', 'veloxa-solar-widget' ) );
		}

		return $connection;
	}

	/**
	 * Nakłada dane formularza edycji na zapisane połączenie. Pola spoza
	 * formularza (id, public, token_hash, mapowanie HA) zostają. Pola-sekrety
	 * z $keep_if_empty zostawione puste zachowują obecną wartość — formularz
	 * edycji nigdy nie pokazuje sekretów, więc puste pole znaczy "bez zmian".
	 *
	 * @param array<string, mixed> $existing      Zapisane połączenie (sekrety jawne).
	 * @param array<string, mixed> $posted        Konfiguracja z formularza.
	 * @param string[]             $keep_if_empty Pola zachowywane, gdy puste.
	 * @return array<string, mixed>
	 */
	public static function merge_edit( array $existing, array $posted, array $keep_if_empty ): array {
		foreach ( $keep_if_empty as $key ) {
			if ( '' === trim( (string) ( $posted[ $key ] ?? '' ) ) ) {
				unset( $posted[ $key ] );
			}
		}

		return array_merge( $existing, $posted );
	}

	/**
	 * Parametry URL powrotu po nieudanym zapisie: ekran edycji albo dodawania.
	 *
	 * @param array<string, mixed>|null $existing Edytowane połączenie.
	 * @return array<string, string>
	 */
	private static function return_args( ?array $existing ): array {
		return null === $existing ? array() : array( 'veloxa_solar_edit' => (string) $existing['id'] );
	}

	/**
	 * Rejestruje endpoint statusu kanału push — źródło danych ekranu
	 * "Sprawdź, czy dane docierają" (kryterium akceptacji Fazy 3).
	 */
	public function register_routes(): void {
		register_rest_route(
			'veloxa-solar-widget/v1',
			'/push-channels/(?P<id>[A-Za-z0-9-]+)/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_push_status' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Stan kanału push: surowe encje + złożony odczyt PV.
	 *
	 * @param \WP_REST_Request $request Żądanie.
	 * @return \WP_REST_Response
	 */
	public function get_push_status( \WP_REST_Request $request ): \WP_REST_Response {
		$id         = (string) $request['id'];
		$connection = $this->connector_manager->find_by_id_or_name( $id );

		if ( null === $connection || Push_Source::CONNECTOR_TYPE !== ( $connection['connector'] ?? '' ) ) {
			return new \WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$reading = Reading_Store::get( $connection['id'] );

		return rest_ensure_response(
			array(
				'entities' => $this->push_channel_manager->get_entities_for_channel( $connection['id'] ),
				'reading'  => null === $reading ? null : array(
					'values'     => $reading->to_array()['values'],
					'formatted'  => array_combine(
						$reading->fields(),
						array_map(
							static function ( string $field ) use ( $reading ): string {
								return Formatter::field( $field, (float) $reading->get( $field ) );
							},
							$reading->fields()
						)
					),
					'updated_at' => gmdate( 'c', $reading->updated_at() ),
				),
				'waiting'  => Reading_Store::get_last_error( $connection['id'] ),
			)
		);
	}

	/**
	 * Skrypt "Sprawdź, czy dane docierają" — tylko na ekranie kanału push.
	 *
	 * @param string $hook_suffix Ekran admina.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( 'toplevel_page_veloxa-solar-widget' !== $hook_suffix ) {
			return;
		}

		// Formularz kanału push (dodawanie i edycja): zmiana jednostki zaznacza pole.
		wp_enqueue_script( 'veloxa-solar-push-form', VELOXA_SOLAR_PLUGIN_URL . 'admin/js/push-form.js', array(), VELOXA_SOLAR_VERSION, true );
		wp_enqueue_style( 'veloxa-solar-connections', VELOXA_SOLAR_PLUGIN_URL . 'admin/css/connections.css', array(), VELOXA_SOLAR_VERSION );
		wp_enqueue_script( 'veloxa-solar-connections', VELOXA_SOLAR_PLUGIN_URL . 'admin/js/connections.js', array(), VELOXA_SOLAR_VERSION, true );

		$channel = isset( $_GET['veloxa_solar_channel'] ) ? sanitize_text_field( wp_unslash( $_GET['veloxa_solar_channel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko wybór ekranu.

		if ( '' === $channel ) {
			return;
		}

		wp_enqueue_script( 'veloxa-solar-push-check', VELOXA_SOLAR_PLUGIN_URL . 'admin/js/push-check.js', array(), VELOXA_SOLAR_VERSION, true );
		wp_localize_script(
			'veloxa-solar-push-check',
			'veloxaSolarPushCheck',
			array(
				// rest_url(), nie sklejane "/wp-json/…" — działa też przy
				// bezpośrednich permalinkach (?rest_route=). Lekcja z błędu
				// restUrl w IoT Data Widget (brief, Faza 3).
				'statusUrl'    => esc_url_raw( rest_url( 'veloxa-solar-widget/v1/push-channels/' . rawurlencode( $channel ) . '/status' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'listening'    => __( 'Listening… send data from your device now.', 'veloxa-solar-widget' ),
				'received'     => __( 'Received! The data reaches this site.', 'veloxa-solar-widget' ),
				'timeout'      => __( 'Nothing arrived within 2 minutes. Check the URL, the token and the entity names in your device.', 'veloxa-solar-widget' ),
				'networkError' => __( 'Could not check the status — reload the page and try again.', 'veloxa-solar-widget' ),
				'noData'       => __( '— no data yet —', 'veloxa-solar-widget' ),
				'labels'       => array_combine(
					array_keys( PV_Reading::FIELD_UNITS ),
					array_map( array( Abstract_Source::class, 'field_label' ), array_keys( PV_Reading::FIELD_UNITS ) )
				),
			)
		);
	}

	/**
	 * Rejestruje stronę w menu administracyjnym.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Veloxa Solar Widget', 'veloxa-solar-widget' ),
			__( 'Solar Widget', 'veloxa-solar-widget' ),
			'manage_options',
			'veloxa-solar-widget',
			array( $this, 'render_page' ),
			'dashicons-sun'
		);

		// Pierwsza pozycja podmenu (ta sama strona) — inaczej WP powiela
		// w niej nazwę menu "Solar Widget" obok "Diagnostics".
		add_submenu_page(
			'veloxa-solar-widget',
			__( 'Veloxa Solar Widget', 'veloxa-solar-widget' ),
			__( 'Connections', 'veloxa-solar-widget' ),
			'manage_options',
			'veloxa-solar-widget',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renderuje stronę połączeń.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- tylko wybór ekranu.
		$channel_id = isset( $_GET['veloxa_solar_channel'] ) ? sanitize_text_field( wp_unslash( $_GET['veloxa_solar_channel'] ) ) : '';
		$ha_id      = isset( $_GET['veloxa_solar_ha'] ) ? sanitize_text_field( wp_unslash( $_GET['veloxa_solar_ha'] ) ) : '';
		$edit_id    = isset( $_GET['veloxa_solar_edit'] ) ? sanitize_text_field( wp_unslash( $_GET['veloxa_solar_edit'] ) ) : '';
		// phpcs:enable

		$form_state = get_transient( 'veloxa_solar_form_' . get_current_user_id() );

		if ( false !== $form_state ) {
			delete_transient( 'veloxa_solar_form_' . get_current_user_id() );
		}

		$form_state   = is_array( $form_state ) ? $form_state : array(
			'form'   => '',
			'errors' => array(),
			'values' => array(),
		);
		$form_types   = self::connection_form_types();
		$add_type     = isset( $_GET['veloxa_solar_add'] ) ? sanitize_key( wp_unslash( $_GET['veloxa_solar_add'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko wybór formularza.
		$show_chooser = isset( $_GET['veloxa_solar_choose'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['veloxa_solar_choose'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko wybór kroku interfejsu.

		if ( ! isset( $form_types[ $add_type ] ) ) {
			$add_type = '';
		}

		// Po błędzie zapisu formularza odtwarzamy dokładnie ten sam typ,
		// również dla formularzy rejestrowanych przez moduły Pro.
		if ( '' === $add_type && '' !== (string) ( $form_state['form'] ?? '' ) ) {
			foreach ( $form_types as $connector => $form_type ) {
				if ( (string) ( $form_type['form_id'] ?? '' ) === (string) $form_state['form'] ) {
					$add_type = (string) $connector;
					break;
				}
			}
		}

		$form_state['selected_connector'] = $add_type;
		$show_chooser                     = $show_chooser || '' !== $add_type;

		$editing = '' !== $edit_id ? $this->connector_manager->find_by_id( $edit_id ) : null;

		if ( null !== $editing ) {
			require __DIR__ . '/views/edit-connection.php';
			return;
		}

		$connection = '' !== $channel_id || '' !== $ha_id ? $this->connector_manager->find_by_id_or_name( '' !== $channel_id ? $channel_id : $ha_id ) : null;

		if ( null !== $connection && Push_Source::CONNECTOR_TYPE === $connection['connector'] ) {
			$source   = $this->connector_manager->make( $connection );
			$entities = $this->push_channel_manager->get_entities_for_channel( $connection['id'] );

			require __DIR__ . '/views/push-channel.php';
			return;
		}

		if ( null !== $connection && Ha_Source::CONNECTOR_TYPE === $connection['connector'] ) {
			$source     = $this->connector_manager->make( $connection );
			$candidates = $source instanceof Ha_Source ? $source->list_candidates() : new \WP_Error( 'x', '' );

			require __DIR__ . '/views/ha-mapping.php';
			return;
		}

		$connections       = $this->connector_manager->get_connections();
		$getting_started   = $this->getting_started( $connections );
		$refresh_intervals = array();
		foreach ( $connections as $saved_connection ) {
			$source = $this->connector_manager->make( $saved_connection );
			if ( $source instanceof Abstract_Source ) {
				$refresh_intervals[ $saved_connection['id'] ] = $source->refresh_interval();
			}
		}
		$available_types = (array) apply_filters(
			'veloxa_solar_connector_type_labels',
			array(
				Push_Source::CONNECTOR_TYPE => __( 'Push', 'veloxa-solar-widget' ),
				Ha_Source::CONNECTOR_TYPE   => __( 'Home Assistant', 'veloxa-solar-widget' ),
				Rest_Source::CONNECTOR_TYPE => __( 'Generic REST', 'veloxa-solar-widget' ),
				'solax'                     => __( 'SolaX Cloud', 'veloxa-solar-widget' ),
			)
		);
		$available_types = array_intersect_key( $available_types, $form_types );
		$pro_sources     = self::pro_source_cards( $available_types );

		require __DIR__ . '/views/connections-page.php';
	}

	/**
	 * Klucz w ustawieniach wtyczki: ukryte „Pierwsze kroki” (opcja ustawień
	 * jest już sprzątana przy odinstalowaniu — bez nowych danych do usuwania).
	 */
	const GETTING_STARTED_KEY = 'getting_started_dismissed';

	/**
	 * „Pierwsze kroki” (0.6): źródło danych → widoczne publicznie → widget na
	 * stronie. Null, gdy wszystko zrobione albo użytkownik ukrył listę.
	 *
	 * @param array<int, array<string, mixed>> $connections Połączenia.
	 * @return array<string, bool>|null Krok => czy zrobiony.
	 */
	public function getting_started( array $connections ): ?array {
		$settings = get_option( Plugin::SETTINGS_OPTION, array() );

		if ( is_array( $settings ) && ! empty( $settings[ self::GETTING_STARTED_KEY ] ) ) {
			return null;
		}

		$steps = array(
			'source' => ! empty( $connections ),
			'public' => ! empty( array_filter( $connections, static fn( array $connection ): bool => ! empty( $connection['public'] ) ) ),
			'widget' => self::widget_on_a_page(),
		);

		return in_array( false, $steps, true ) ? $steps : null;
	}

	/**
	 * Czy jakaś opublikowana treść ma blok albo shortcode widgetu.
	 *
	 * @return bool
	 */
	private static function widget_on_a_page(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- jedno zapytanie na ekranie admina, tylko gdy lista jest widoczna.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND ( post_content LIKE %s OR post_content LIKE %s ) LIMIT 1",
				'%' . $wpdb->esc_like( '<!-- wp:veloxa-solar-widget/widget' ) . '%',
				'%' . $wpdb->esc_like( '[veloxa_solar' ) . '%'
			)
		);

		return null !== $found;
	}

	/**
	 * Ukrywa „Pierwsze kroki”.
	 */
	public function handle_dismiss_getting_started(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'veloxa-solar-widget' ) );
		}

		check_admin_referer( 'veloxa_solar_dismiss_getting_started' );
		$settings                              = get_option( Plugin::SETTINGS_OPTION, array() );
		$settings                              = is_array( $settings ) ? $settings : array();
		$settings[ self::GETTING_STARTED_KEY ] = true;
		update_option( Plugin::SETTINGS_OPTION, $settings );

		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Opcje wspólne źródeł z formularza (docs/etap-1.md, 1.5 i 1.7):
	 * szacunek zużycia domu (domyślnie wyłączony) i próg bezczynności baterii.
	 *
	 * @param array<string, mixed> $post Dane formularza już sanityzowane (Form_Input::source_post()).
	 * @return array<string, mixed>
	 */
	public static function source_options_from_post( array $post ): array {
		$options = array(
			'estimate_home_power' => ! empty( $post['estimate_home_power'] ),
			'home_from_balance'   => ! empty( $post['home_from_balance'] ),
			'battery_idle_watts'  => isset( $post['battery_idle_watts'] ) && '' !== (string) $post['battery_idle_watts'] ? absint( $post['battery_idle_watts'] ) : Abstract_Source::DEFAULT_BATTERY_IDLE_WATTS,
		);

		/**
		 * Dodatkowe opcje z pól dodanych akcją
		 * veloxa_solar_source_options_fields. Klucze tych pól moduł zgłasza
		 * filtrem veloxa_solar_source_option_keys — filtr dostaje je już po
		 * sanitize_text_field() (nigdy surowego $_POST).
		 *
		 * @param array<string, mixed> $options Opcje.
		 * @param array<string, mixed> $post    Dane formularza, sanityzowane.
		 */
		$filtered = apply_filters( 'veloxa_solar_source_options_from_post', $options, $post );

		return is_array( $filtered ) ? $filtered : $options;
	}

	/**
	 * Buduje konfigurację kanału push z danych formularza: rdzeń zawsze,
	 * pola opcjonalne tylko zaznaczone.
	 *
	 * @param array<string, mixed> $post Dane formularza (sanityzowane — Form_Input::source_post()).
	 * @return array<string, mixed>
	 */
	public static function push_config_from_post( array $post ): array {
		$fields = array();

		foreach ( array_keys( PV_Reading::FIELD_UNITS ) as $field ) {
			$mapping = isset( $post['fields'][ $field ] ) && is_array( $post['fields'][ $field ] ) ? $post['fields'][ $field ] : array();

			if ( ! in_array( $field, PV_Reading::CORE_FIELDS, true ) && empty( $mapping['enabled'] ) ) {
				continue;
			}

			$fields[ $field ] = array(
				'unit'   => sanitize_text_field( (string) ( $mapping['unit'] ?? '' ) ),
				'invert' => in_array( $field, Abstract_Source::SIGNED_FIELDS, true ) && ! empty( $mapping['invert'] ),
			);
		}

		return array_merge(
			array(
				'name'            => sanitize_text_field( (string) ( $post['name'] ?? '' ) ),
				'connector'       => Push_Source::CONNECTOR_TYPE,
				'fields'          => $fields,
				'refresh_minutes' => max( Abstract_Source::MIN_REFRESH_MINUTES, absint( $post['refresh_minutes'] ?? Abstract_Source::MIN_REFRESH_MINUTES ) ),
			),
			self::source_options_from_post( $post )
		);
	}

	/**
	 * Obsługuje dodanie i edycję kanału push. Whitelist encji kanału =
	 * wybrane pola modelu — bez niej Push_Ingest odrzuca każdy odczyt
	 * ("Unknown entity"). Po dodaniu przekierowuje na ekran kanału (adres,
	 * token, przykład, "Sprawdź, czy dane docierają"). Edycja nie zmienia
	 * tokenu — od tego jest handle_rotate_push_token() — i od razu składa
	 * odczyt z ostatnich wartości encji według nowej konfiguracji.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'veloxa-solar-widget' ) );
		}

		check_admin_referer( 'veloxa_solar_save_connection' );

		$existing = $this->connection_for_edit( Push_Source::CONNECTOR_TYPE );
		$config   = self::push_config_from_post( Form_Input::source_post() );
		$errors   = Push_Source::validate_config( $config );

		if ( '' === $config['name'] ) {
			array_unshift( $errors, __( 'Name is required.', 'veloxa-solar-widget' ) );
		}

		if ( ! empty( $errors ) ) {
			$this->fail_form( 'veloxa-solar-push-form', $errors, $config, self::return_args( $existing ) );
		}

		if ( null !== $existing ) {
			$config = self::merge_edit( $existing, $config, array() );

			$this->connector_manager->update_connection( $config );
			// Encje, które zostają, zachowują ostatnie wartości.
			$this->push_channel_manager->set_allowed_entities( $config['id'], array_keys( $config['fields'] ) );
			// Złożony odczyt od razu według nowej konfiguracji (jednostki,
			// odwrócenie znaku, usunięte pola) — bez czekania na następne
			// żądanie urządzenia.
			( new Push_Source( $config ) )->sync( $this->push_channel_manager, time() );

			wp_safe_redirect( self::page_url( array( 'veloxa_solar_notice' => 'saved' ) ) );
			exit;
		}

		$raw_token            = Push_Channel_Manager::generate_token();
		$config['id']         = wp_generate_uuid4();
		$config['token_hash'] = Push_Channel_Manager::hash_token( $raw_token );

		$connections   = $this->connector_manager->get_connections();
		$connections[] = $config;

		$this->connector_manager->save_connections( $connections );
		$this->push_channel_manager->set_allowed_entities( $config['id'], array_keys( $config['fields'] ) );

		set_transient( 'veloxa_solar_new_token_' . get_current_user_id(), $raw_token, MINUTE_IN_SECONDS );

		wp_safe_redirect( self::page_url( array( 'veloxa_solar_channel' => $config['id'] ) ) );
		exit;
	}

	/**
	 * Krok 1 kreatora Home Assistant: adres + token. Połączenie zapisywane
	 * od razu (token szyfrowany), bez mapowania — krok 2 (wybór czujników)
	 * edytuje to samo połączenie, więc token nigdy nie leży jawnym tekstem
	 * w transiencie między krokami.
	 *
	 * Edycja: pusty token = bez zmian (rotacja tokenu bez utraty mapowania),
	 * mapowanie czujników zostaje. Połączenie z HA sprawdzane ponownie tylko,
	 * gdy zmienił się adres albo token.
	 */
	public function handle_save_ha(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'veloxa-solar-widget' ) );
		}

		check_admin_referer( 'veloxa_solar_save_ha_connection' );

		$existing = $this->connection_for_edit( Ha_Source::CONNECTOR_TYPE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- zweryfikowane wyżej.
		$config = array(
			'id'              => null === $existing ? wp_generate_uuid4() : $existing['id'],
			'name'            => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'connector'       => Ha_Source::CONNECTOR_TYPE,
			'url'             => isset( $_POST['url'] ) ? esc_url_raw( sanitize_text_field( wp_unslash( $_POST['url'] ) ), array( 'http', 'https' ) ) : '',
			'token'           => isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '',
			'fields'          => array(),
			'refresh_minutes' => isset( $_POST['refresh_minutes'] ) ? max( Abstract_Source::MIN_REFRESH_MINUTES, absint( wp_unslash( $_POST['refresh_minutes'] ) ) ) : Abstract_Source::MIN_REFRESH_MINUTES,
		);
		// phpcs:enable

		if ( null !== $existing ) {
			unset( $config['fields'] );
			$config = self::merge_edit( $existing, $config, array( 'token' ) );
		}

		$errors = Ha_Source::validate_config( $config, false );

		if ( '' === $config['name'] ) {
			array_unshift( $errors, __( 'Name is required.', 'veloxa-solar-widget' ) );
		}

		$reconnect = null === $existing || $existing['url'] !== $config['url'] || $existing['token'] !== $config['token'];

		if ( empty( $errors ) && $reconnect ) {
			$candidates = ( new Ha_Source( $config ) )->list_candidates();

			if ( is_wp_error( $candidates ) ) {
				$errors[] = $candidates->get_error_message();
			}
		}

		if ( ! empty( $errors ) ) {
			$this->fail_form(
				'veloxa-solar-ha-form',
				$errors,
				array(
					'name'            => $config['name'],
					'url'             => $config['url'],
					'refresh_minutes' => $config['refresh_minutes'],
				),
				self::return_args( $existing )
			);
		}

		if ( null !== $existing ) {
			$this->connector_manager->update_connection( $config );

			if ( ! empty( $config['fields'] ) ) {
				$this->source_refresher->refresh_now( $config['id'], time() );
			}

			wp_safe_redirect( self::page_url( array( 'veloxa_solar_notice' => 'saved' ) ) );
			exit;
		}

		$connections   = $this->connector_manager->get_connections();
		$connections[] = $config;

		$this->connector_manager->save_connections( $connections );

		wp_safe_redirect( self::page_url( array( 'veloxa_solar_ha' => $config['id'] ) ) );
		exit;
	}

	/**
	 * Krok 2 kreatora Home Assistant: wybór czujników dla pól PV.
	 */
	public function handle_save_ha_mapping(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'veloxa-solar-widget' ) );
		}

		check_admin_referer( 'veloxa_solar_save_ha_mapping' );

		$input  = Form_Input::source_post();
		$id     = (string) ( $input['id'] ?? '' );
		$posted = $input['fields'];
		$fields = array();

		foreach ( array_keys( PV_Reading::FIELD_UNITS ) as $field ) {
			$entity = sanitize_text_field( (string) ( $posted[ $field ]['entity'] ?? '' ) );

			if ( '' !== $entity ) {
				$fields[ $field ] = array(
					'entity' => $entity,
					'invert' => in_array( $field, Abstract_Source::SIGNED_FIELDS, true ) && ! empty( $posted[ $field ]['invert'] ),
				);
			}
		}

		$connections = $this->connector_manager->get_connections();
		$index       = null;

		foreach ( $connections as $i => $connection ) {
			if ( $connection['id'] === $id && Ha_Source::CONNECTOR_TYPE === $connection['connector'] ) {
				$index = $i;
			}
		}

		if ( null === $index ) {
			wp_die( esc_html__( 'Connection not found.', 'veloxa-solar-widget' ) );
		}

		$config           = array_merge( $connections[ $index ], self::source_options_from_post( $input ) );
		$config['fields'] = $fields;
		$errors           = Ha_Source::validate_config( $config );

		if ( ! empty( $errors ) ) {
			$this->fail_form( 'veloxa-solar-ha-mapping', $errors, array( 'fields' => $fields ), array( 'veloxa_solar_ha' => $id ) );
		}

		$connections[ $index ] = $config;

		$this->connector_manager->save_connections( $connections );
		$this->source_refresher->refresh_now( $id, time() );

		wp_safe_redirect( self::page_url( array( 'veloxa_solar_notice' => 'saved' ) ) );
		exit;
	}

	/**
	 * Obsługuje usunięcie połączenia.
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'veloxa-solar-widget' ) );
		}

		check_admin_referer( 'veloxa_solar_delete_connection' );

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		$connections = array_values(
			array_filter(
				$this->connector_manager->get_connections(),
				static function ( array $connection ) use ( $id ): bool {
					return $connection['id'] !== $id;
				}
			)
		);

		$this->connector_manager->save_connections( $connections );
		$this->push_channel_manager->set_allowed_entities( $id, array() );
		Reading_Store::delete( $id );
		History_Store::delete_for_connection( $id );
		\Veloxa\Solar\Model\Daily_Store::delete_for_connection( $id );

		wp_safe_redirect( admin_url( 'admin.php?page=veloxa-solar-widget&veloxa_solar_notice=deleted' ) );
		exit;
	}

	/**
	 * Zapisuje ustawienia ogólne (Faza 1: tylko opt-in usuwania danych przy
	 * odinstalowaniu — patrz Plugin::uninstall()). Tu, nie na osobnej
	 * stronie Ustawień, bo Solar w Fazie 1 ma jeden ekran admina.
	 */
	public function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'veloxa-solar-widget' ) );
		}

		check_admin_referer( 'veloxa_solar_save_settings' );

		$settings                             = get_option( Plugin::SETTINGS_OPTION, array() );
		$settings                             = is_array( $settings ) ? $settings : array();
		$settings['remove_data_on_uninstall'] = ! empty( $_POST['remove_data_on_uninstall'] );
		$co2                                  = isset( $_POST['co2_kg_per_kwh'] ) ? str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['co2_kg_per_kwh'] ) ) ) : '';
		// Zero i ujemne jak tekst — domyślny współczynnik (widget z „0 kg” nic nie mówi).
		$settings['co2_kg_per_kwh'] = is_numeric( $co2 ) && (float) $co2 > 0 ? min( Plugin::MAX_CO2_KG_PER_KWH, (float) $co2 ) : Plugin::DEFAULT_CO2_KG_PER_KWH;

		// Brief 7.1 pkt 4: na multisite tylko administrator sieci. Bez
		// uprawnienia wartość zostaje, jaka była — pole nie jest ignorowane
		// "na zero", bo formularz pokazuje je wtedy tylko do odczytu.
		if ( self::can_manage_local_network() ) {
			$settings['allow_local_network'] = ! empty( $_POST['allow_local_network'] );
		}

		/**
		 * Ustawienia przed zapisem — pola dokładane przez inne moduły
		 * (veloxa_solar_settings_rows). Nonce i uprawnienia sprawdzone wyżej.
		 *
		 * @param array<string, mixed> $settings Ustawienia.
		 */
		$filtered = apply_filters( 'veloxa_solar_save_settings', $settings );

		update_option( Plugin::SETTINGS_OPTION, is_array( $filtered ) ? $filtered : $settings );

		wp_safe_redirect( admin_url( 'admin.php?page=veloxa-solar-widget&veloxa_solar_notice=settings_saved' ) );
		exit;
	}

	/**
	 * Czy bieżący użytkownik może włączyć "Allow local network addresses"
	 * (brief 7.1 pkt 4): manage_options, na multisite manage_network_options.
	 *
	 * @return bool
	 */
	public static function can_manage_local_network(): bool {
		return current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
	}

	/**
	 * Buduje konfigurację Generic REST z danych formularza.
	 *
	 * @param array<string, mixed> $post Dane formularza (sanityzowane — Form_Input::source_post()).
	 * @return array<string, mixed>
	 */
	public static function rest_config_from_post( array $post ): array {
		$fields = array();

		foreach ( array_keys( PV_Reading::FIELD_UNITS ) as $field ) {
			$mapping = isset( $post['fields'][ $field ] ) && is_array( $post['fields'][ $field ] ) ? $post['fields'][ $field ] : array();
			$path    = sanitize_text_field( (string) ( $mapping['path'] ?? '' ) );

			if ( '' === $path ) {
				continue;
			}

			$fields[ $field ] = array(
				'path'   => $path,
				'unit'   => sanitize_text_field( (string) ( $mapping['unit'] ?? '' ) ),
				'invert' => in_array( $field, Abstract_Source::SIGNED_FIELDS, true ) && ! empty( $mapping['invert'] ),
			);
		}

		return array_merge(
			array(
				'name'            => sanitize_text_field( (string) ( $post['name'] ?? '' ) ),
				'connector'       => Rest_Source::CONNECTOR_TYPE,
				'url'             => esc_url_raw( trim( (string) ( $post['url'] ?? '' ) ), array( 'http', 'https' ) ),
				'token'           => sanitize_text_field( (string) ( $post['token'] ?? '' ) ),
				'fields'          => $fields,
				'updated_at_path' => sanitize_text_field( (string) ( $post['updated_at_path'] ?? '' ) ),
				'timezone'        => sanitize_text_field( (string) ( $post['timezone'] ?? '' ) ),
				'refresh_minutes' => max( Abstract_Source::MIN_REFRESH_MINUTES, absint( $post['refresh_minutes'] ?? Abstract_Source::MIN_REFRESH_MINUTES ) ),
			),
			self::source_options_from_post( $post )
		);
	}

	/**
	 * Obsługuje dodanie i edycję połączenia Generic REST. Po zapisie od razu
	 * pobiera odczyt — admin widzi wynik (albo błąd mapowania) natychmiast,
	 * nie po 5 minutach.
	 *
	 * Edycja: puste URL i nagłówek = bez zmian (podmiana klucza API bez
	 * utraty mapowania). Nagłówek usuwa tylko pole "clear_token".
	 */
	public function handle_save_rest(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'veloxa-solar-widget' ) );
		}

		check_admin_referer( 'veloxa_solar_save_rest_connection' );

		$existing = $this->connection_for_edit( Rest_Source::CONNECTOR_TYPE );
		$input    = Form_Input::source_post();
		$config   = self::rest_config_from_post( $input );

		if ( null !== $existing ) {
			$keep   = empty( $input['clear_token'] ) ? array( 'url', 'token' ) : array( 'url' );
			$config = self::merge_edit( $existing, $config, $keep );
		}

		$errors = Rest_Source::validate_config( $config );

		if ( '' === $config['name'] ) {
			array_unshift( $errors, __( 'Name is required.', 'veloxa-solar-widget' ) );
		}

		if ( ! empty( $errors ) ) {
			// Formularz do ponownego wypełnienia BEZ url/token — to sekrety,
			// nie mogą leżeć jawnym tekstem w transiencie.
			$refill = $config;
			unset( $refill['url'], $refill['token'] );

			$this->fail_form( 'veloxa-solar-rest-form', $errors, $refill, self::return_args( $existing ) );
		}

		if ( null !== $existing ) {
			$this->connector_manager->update_connection( $config );
		} else {
			$config['id']  = wp_generate_uuid4();
			$connections   = $this->connector_manager->get_connections();
			$connections[] = $config;

			$this->connector_manager->save_connections( $connections );
		}

		$this->source_refresher->refresh_now( $config['id'], time() );

		wp_safe_redirect( admin_url( 'admin.php?page=veloxa-solar-widget&veloxa_solar_notice=saved' ) );
		exit;
	}

	/**
	 * SolaX Cloud (beta): źródło Generic REST z gotowym mapowaniem.
	 */
	public function handle_save_solax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'veloxa-solar-widget' ) );
		}

		check_admin_referer( 'veloxa_solar_save_solax_connection' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- sprawdzone wyżej.
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$token  = isset( $_POST['solax_token'] ) ? (string) preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['solax_token'] ) ) ) : '';
		$serial = isset( $_POST['solax_sn'] ) ? (string) preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['solax_sn'] ) ) ) : '';
		// phpcs:enable

		$errors = array();

		if ( '' === $name ) {
			$errors[] = __( 'Name is required.', 'veloxa-solar-widget' );
		}

		if ( strlen( (string) $token ) < 10 ) {
			$errors[] = __( 'Enter the token ID from SolaxCloud (Service → API).', 'veloxa-solar-widget' );
		}

		if ( strlen( (string) $serial ) < 6 ) {
			$errors[] = __( 'Enter the registration number (SN) of the Wi-Fi/LAN dongle.', 'veloxa-solar-widget' );
		}

		if ( ! empty( $errors ) ) {
			$this->fail_form(
				'veloxa-solar-solax-form',
				$errors,
				array(
					'name'     => $name,
					'solax_sn' => $serial,
				)
			);
		}

		$config        = Rest_Source::solax_config( $name, (string) $token, (string) $serial );
		$config['id']  = wp_generate_uuid4();
		$connections   = $this->connector_manager->get_connections();
		$connections[] = $config;

		$this->connector_manager->save_connections( $connections );
		$this->source_refresher->refresh_now( $config['id'], time() );

		wp_safe_redirect( admin_url( 'admin.php?page=veloxa-solar-widget&veloxa_solar_notice=saved' ) );
		exit;
	}

	/**
	 * Włącza/wyłącza pokazywanie widgetów połączenia odwiedzającym (brief
	 * sekcja 7: dane PV mogą ujawniać wzorce obecności — tryb publiczny
	 * włączany świadomie, domyślnie wyłączony).
	 */
	public function handle_toggle_public(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'veloxa-solar-widget' ) );
		}

		check_admin_referer( 'veloxa_solar_toggle_public' );

		$id          = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$connections = $this->connector_manager->get_connections();

		foreach ( $connections as $i => $connection ) {
			if ( $connection['id'] === $id ) {
				$connections[ $i ]['public'] = empty( $connection['public'] );
			}
		}

		$this->connector_manager->save_connections( $connections );

		wp_safe_redirect( self::page_url( array( 'veloxa_solar_notice' => 'saved' ) ) );
		exit;
	}

	/**
	 * Generuje nowy token kanału push. Stary przestaje działać od razu;
	 * encje, ostatnie wartości i historia zostają. Nowy token pokazywany raz
	 * na ekranie kanału — jak przy tworzeniu.
	 */
	public function handle_rotate_push_token(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'veloxa-solar-widget' ) );
		}

		check_admin_referer( 'veloxa_solar_rotate_push_token' );

		$connection = $this->connection_for_edit( Push_Source::CONNECTOR_TYPE );

		if ( null === $connection ) {
			wp_die( esc_html__( 'Connection not found.', 'veloxa-solar-widget' ) );
		}

		$raw_token                = Push_Channel_Manager::generate_token();
		$connection['token_hash'] = Push_Channel_Manager::hash_token( $raw_token );

		$this->connector_manager->update_connection( $connection );

		set_transient( 'veloxa_solar_new_token_' . get_current_user_id(), $raw_token, MINUTE_IN_SECONDS );

		wp_safe_redirect( self::page_url( array( 'veloxa_solar_channel' => $connection['id'] ) ) );
		exit;
	}

	/**
	 * Obsługuje przycisk "Refresh now".
	 */
	public function handle_refresh(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'veloxa-solar-widget' ) );
		}

		check_admin_referer( 'veloxa_solar_refresh_connection' );

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		$this->source_refresher->refresh_now( $id, time() );

		wp_safe_redirect( admin_url( 'admin.php?page=veloxa-solar-widget&veloxa_solar_notice=refreshed' ) );
		exit;
	}
}
