<?php
/**
 * Renderowanie widgetów (brief sekcja 5) — wspólne dla shortcode'u
 * [veloxa_solar] i bloku veloxa-solar-widget/widget. Czyta WYŁĄCZNIE zapisany
 * odczyt (Reading_Store) i historię — nigdy nie odpytuje źródła (brief
 * sekcja 7).
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Widgets;

use Veloxa\Solar\Core\Connector_Manager;
use Veloxa\Solar\Model\PV_Reading;
use Veloxa\Solar\Plugin;
use Veloxa\Solar\Model\Reading_Store;
use Veloxa\Solar\Sources\Abstract_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Widget_Renderer
 *
 * Tryb publiczny (brief sekcja 7 — dane PV mogą ujawniać wzorce obecności):
 * widget połączenia, którego admin świadomie nie oznaczył jako publiczne,
 * nie pokazuje odwiedzającym nic; redaktor widzi podgląd z informacją, jak
 * go włączyć.
 */
class Widget_Renderer {

	/**
	 * Widgety z wersji Pro — w paczce bez kodu Pro dają komunikat "dostępne
	 * w wersji Pro" (tylko dla administratora) zamiast "nieznany widget".
	 */
	const PRO_WIDGETS = array( 'chart', 'flow', 'history', 'stats', 'rce', 'rce_export' );

	/**
	 * Widgety bez wyboru źródła w bloku (suma instalacji, ceny energii).
	 */
	const NO_SOURCE_WIDGETS = array( 'portfolio', 'rce' );


	/**
	 * Nazwy widgetów Pro — lista w bloku, gdy kod Pro nie działa.
	 *
	 * @return array<string, string>
	 */
	public static function pro_widget_labels(): array {
		return array(
			'chart'      => __( 'Production today (chart)', 'veloxa-solar-widget' ),
			'flow'       => __( 'Energy flow', 'veloxa-solar-widget' ),
			'history'    => __( 'Production history', 'veloxa-solar-widget' ),
			'stats'      => __( 'Statistics', 'veloxa-solar-widget' ),
			'rce'        => __( 'Electricity prices (RCE)', 'veloxa-solar-widget' ),
			'rce_export' => __( 'Exported energy value (RCE)', 'veloxa-solar-widget' ),
		);
	}

	/**
	 * Widgety planu Agency (decyzja właściciela 2026-10-10) — kod w
	 * includes/premium/; tutaj tylko nazwa do oznaczenia na liście.
	 */
	const AGENCY_WIDGETS = array( 'portfolio' );

	/**
	 * Nazwy widgetów Agency — lista w bloku, gdy plan Agency nie działa.
	 *
	 * @return array<string, string>
	 */
	public static function agency_widget_labels(): array {
		return array(
			'portfolio' => __( 'Our installations', 'veloxa-solar-widget' ),
		);
	}

	/**
	 * Dostępne style.
	 */
	const STYLES = array( 'card', 'minimal' );

	/**
	 * Uchwyt arkusza stylów widgetów.
	 */
	const STYLE_HANDLE = 'veloxa-solar-widgets';

	/**
	 * Menedżer konektorów.
	 *
	 * @var Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Konstruktor.
	 *
	 * @param Connector_Manager $connector_manager Menedżer konektorów.
	 */
	public function __construct( Connector_Manager $connector_manager ) {
		$this->connector_manager = $connector_manager;

		// Wprost, nie przez add_action( 'init' ) — Plugin tworzy tę klasę
		// już W TRAKCIE 'init' (priorytet 10), a callback dopięty do
		// aktualnie wykonywanego priorytetu się nie uruchamia (znalezione
		// na żywo: CSS widgetów nie był rejestrowany).
		self::register_assets();
	}

	/**
	 * Tagi i atrybuty HTML/SVG widgetów (Free i Pro) dla wp_kses() — HTML
	 * z filtrów veloxa_solar_render_standalone i veloxa_solar_widget_body
	 * przechodzi przez tę listę (recenzja wp.org 0.7.1). wp_kses_post()
	 * nie wystarcza: wycina SVG. Nazwy atrybutów małymi literami (tak
	 * porównuje wp_kses); przeglądarka przywraca wielkość liter atrybutów
	 * SVG (viewBox, markerWidth, refX) przy parsowaniu HTML.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		static $allowed = null;

		if ( null !== $allowed ) {
			return $allowed;
		}

		$common = array_fill_keys(
			array( 'class', 'id', 'hidden', 'role', 'title', 'style', 'data-*', 'aria-hidden', 'aria-label', 'aria-labelledby', 'aria-describedby', 'aria-live', 'aria-valuemin', 'aria-valuemax', 'aria-valuenow', 'aria-valuetext' ),
			true
		);
		$svg    = array_fill_keys( array( 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'opacity', 'transform', 'marker-end', 'marker-start' ), true );
		$tags   = array(
			'div'        => array(),
			'span'       => array(),
			'p'          => array(),
			'strong'     => array(),
			'em'         => array(),
			'small'      => array(),
			'br'         => array(),
			'code'       => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'dl'         => array(),
			'dt'         => array(),
			'dd'         => array(),
			'figure'     => array(),
			'figcaption' => array(),
			'details'    => array( 'open' => true ),
			'summary'    => array(),
			'table'      => array(),
			'caption'    => array(),
			'thead'      => array(),
			'tbody'      => array(),
			'tr'         => array(),
			'th'         => array(
				'scope'   => true,
				'colspan' => true,
			),
			'td'         => array( 'colspan' => true ),
			'time'       => array( 'datetime' => true ),
			'a'          => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'img'        => array(
				'src'      => true,
				'alt'      => true,
				'width'    => true,
				'height'   => true,
				'loading'  => true,
				'decoding' => true,
			),
			'svg'        => array(
				'viewbox'             => true,
				'width'               => true,
				'height'              => true,
				'xmlns'               => true,
				'focusable'           => true,
				'preserveaspectratio' => true,
			),
			'g'          => $svg,
			'defs'       => array(),
			'title'      => array(),
			'desc'       => array(),
			'marker'     => array(
				'viewbox'      => true,
				'markerwidth'  => true,
				'markerheight' => true,
				'refx'         => true,
				'refy'         => true,
				'orient'       => true,
				'markerunits'  => true,
			),
			'path'       => array_merge( $svg, array( 'd' => true ) ),
			'rect'       => array_merge(
				$svg,
				array_fill_keys( array( 'x', 'y', 'width', 'height', 'rx', 'ry' ), true )
			),
			'circle'     => array_merge( $svg, array_fill_keys( array( 'cx', 'cy', 'r' ), true ) ),
			'line'       => array_merge( $svg, array_fill_keys( array( 'x1', 'x2', 'y1', 'y2' ), true ) ),
			'polyline'   => array_merge( $svg, array( 'points' => true ) ),
			'polygon'    => array_merge( $svg, array( 'points' => true ) ),
			'text'       => array_merge( $svg, array_fill_keys( array( 'x', 'y', 'dx', 'dy', 'text-anchor', 'dominant-baseline', 'font-size' ), true ) ),
			'tspan'      => array_merge( $svg, array_fill_keys( array( 'x', 'y', 'dx', 'dy' ), true ) ),
		);

		foreach ( $tags as $tag => $attributes ) {
			$tags[ $tag ] = array_merge( $common, $attributes );
		}

		$allowed = $tags;

		return $allowed;
	}

	/**
	 * Rejestruje CSS widgetów (dołączany dopiero przy renderze).
	 */
	public static function register_assets(): void {
		wp_register_style( self::STYLE_HANDLE, VELOXA_SOLAR_PLUGIN_URL . 'public/css/widgets.css', array(), VELOXA_SOLAR_VERSION );
	}

	/**
	 * Dostępne widgety: klucz => czytelna nazwa.
	 *
	 * @return array<string, string>
	 */
	public static function widget_labels(): array {
		$labels = array(
			'power'   => __( 'Current power', 'veloxa-solar-widget' ),
			'today'   => __( 'Energy today', 'veloxa-solar-widget' ),
			'total'   => __( 'Total energy', 'veloxa-solar-widget' ),
			'summary' => __( 'Summary', 'veloxa-solar-widget' ),
			'battery' => __( 'Battery', 'veloxa-solar-widget' ),
			'co2'     => __( 'Avoided CO₂', 'veloxa-solar-widget' ),
		);

		/**
		 * Dodatkowe widgety (np. wykres z includes/premium/). Każdy dodany
		 * klucz musi być obsłużony w filtrze veloxa_solar_widget_body.
		 *
		 * @param array<string, string> $labels Klucz => czytelna nazwa.
		 */
		$filtered = apply_filters( 'veloxa_solar_widgets', $labels );

		return is_array( $filtered ) ? $filtered : $labels;
	}

	/**
	 * Czy źródło dostarczające podane pola ma dane dla widgetu (brief: UI
	 * nigdy nie sugeruje danych, których źródło nie dostarcza — np. brak
	 * baterii w źródle = brak widgetu baterii).
	 *
	 * @param string   $widget Klucz widgetu.
	 * @param string[] $fields Pola dostarczane przez źródło.
	 * @return bool
	 */
	public static function widget_supported( string $widget, array $fields ): bool {
		switch ( $widget ) {
			case 'power':
				return in_array( 'current_power', $fields, true );
			case 'today':
				return in_array( 'energy_today', $fields, true );
			case 'total':
				return in_array( 'energy_total', $fields, true );
			case 'summary':
				return array() === array_diff( PV_Reading::CORE_FIELDS, $fields );
			case 'battery':
				// docs/etap-1.md, 1.7: widget baterii tylko przy poziomie naładowania.
				return in_array( 'battery_soc', $fields, true );
			case 'co2':
				return in_array( 'energy_total', $fields, true );
		}

		/**
		 * Wymagania widgetów dodanych filtrem veloxa_solar_widgets.
		 *
		 * @param bool     $supported Domyślnie true.
		 * @param string   $widget    Klucz widgetu.
		 * @param string[] $fields    Pola dostarczane przez źródło.
		 */
		return (bool) apply_filters( 'veloxa_solar_widget_supported', true, $widget, $fields );
	}

	/**
	 * Źródło PV dla połączenia, albo null.
	 *
	 * @param array<string, mixed> $connection Połączenie.
	 * @return Abstract_Source|null
	 */
	public function source_for( array $connection ): ?Abstract_Source {
		$source = $this->connector_manager->make( $connection );

		return $source instanceof Abstract_Source ? $source : null;
	}

	/**
	 * Połączenia, które mogą zasilać widgety (źródła PV).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function source_connections(): array {
		return array_values(
			array_filter(
				$this->connector_manager->get_connections(),
				function ( array $connection ): bool {
					return $this->connector_manager->make( $connection ) instanceof Abstract_Source;
				}
			)
		);
	}

	/**
	 * Renderuje widget.
	 *
	 * @param array<string, mixed> $atts widget, connection, style, label.
	 * @return string
	 */
	public function render( array $atts ): string {
		$widget = strtolower( trim( (string) ( $atts['widget'] ?? 'summary' ) ) );
		$style  = strtolower( trim( (string) ( $atts['style'] ?? 'card' ) ) );

		$widgets = array_keys( self::widget_labels() );

		if ( ! in_array( $widget, $widgets, true ) && in_array( $widget, self::PRO_WIDGETS, true ) && ! Plugin::pro_features_available() ) {
			/**
			 * Komunikat dla administratora przy widgecie Pro, gdy funkcje Pro
			 * nie działają.
			 *
			 * @param string $message Komunikat.
			 */
			return self::pro_notice( (string) apply_filters( 'veloxa_solar_pro_widget_notice', __( 'This widget is available in the Pro version.', 'veloxa-solar-widget' ) ) );
		}

		if ( ! in_array( $widget, $widgets, true ) && in_array( $widget, self::AGENCY_WIDGETS, true ) ) {
			/**
			 * Komunikat dla administratora przy widgecie planu Agency, gdy
			 * plan Agency nie działa.
			 *
			 * @param string $message Komunikat.
			 */
			return self::pro_notice( (string) apply_filters( 'veloxa_solar_agency_widget_notice', __( 'This widget is available in the Agency plan.', 'veloxa-solar-widget' ) ) );
		}

		if ( ! in_array( $widget, $widgets, true ) ) {
			/* translators: 1: the value given, 2: comma-separated allowed values */
			return $this->notice( sprintf( __( 'Unknown widget "%1$s". Allowed values: %2$s.', 'veloxa-solar-widget' ), $widget, implode( ', ', $widgets ) ) );
		}

		if ( ! in_array( $style, self::STYLES, true ) ) {
			/* translators: 1: the value given, 2: comma-separated allowed values */
			return $this->notice( sprintf( __( 'Unknown style "%1$s". Allowed values: %2$s.', 'veloxa-solar-widget' ), $style, implode( ', ', self::STYLES ) ) );
		}

		/**
		 * Walidacja atrybutów widgetów dodanych filtrem (np. period, show):
		 * niepusty wynik = komunikat z listą dopuszczalnych wartości, tylko
		 * dla administratora.
		 *
		 * @param string               $error  Komunikat albo ''.
		 * @param string               $widget Klucz widgetu.
		 * @param array<string, mixed> $atts   Atrybuty.
		 */
		$attribute_error = (string) apply_filters( 'veloxa_solar_widget_attribute_error', '', $widget, $atts );

		if ( '' !== $attribute_error ) {
			return self::pro_notice( $attribute_error );
		}

		/**
		 * Widgety bez jednego źródła (np. suma wielu instalacji) — cały HTML
		 * z filtra; null = zwykły widget jednego połączenia.
		 *
		 * @param string|null          $html   Null albo HTML widgetu.
		 * @param string               $widget Klucz widgetu.
		 * @param array<string, mixed> $atts   Atrybuty.
		 * @param string               $style  Styl.
		 */
		$standalone = apply_filters( 'veloxa_solar_render_standalone', null, $widget, $atts, $style );

		if ( is_string( $standalone ) ) {
			return wp_kses( $standalone, self::allowed_html() );
		}

		$connection = $this->resolve_connection( (string) ( $atts['connection'] ?? '' ) );

		if ( is_string( $connection ) ) {
			return $this->notice( $connection );
		}

		$is_public = ! empty( $connection['public'] );

		if ( ! $is_public && ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		/**
		 * Źródło.
		 *
		 * @var Abstract_Source $source
		 */
		$source  = $this->connector_manager->make( $connection );
		$reading = Reading_Store::get( $connection['id'] );

		if ( null !== $reading && ! self::widget_supported( $widget, $reading->fields() ) ) {
			/* translators: %s: widget name */
			return $this->notice( sprintf( __( 'This data source does not provide the data for the "%s" widget.', 'veloxa-solar-widget' ), self::widget_labels()[ $widget ] ) );
		}

		$error = Reading_Store::get_last_error( $connection['id'] );
		$now   = time();
		$stale = null !== $reading && $reading->is_stale( $source->refresh_interval(), $now );
		$label = trim( (string) ( $atts['label'] ?? '' ) );
		$label = '' !== $label ? $label : self::widget_labels()[ $widget ];

		wp_enqueue_style( self::STYLE_HANDLE );

		$classes = array( 'veloxa-solar', 'veloxa-solar--' . $style, 'veloxa-solar--' . $widget );

		if ( $stale ) {
			$classes[] = 'veloxa-solar--stale';
		}

		if ( null === $reading ) {
			$classes[] = null !== $error ? 'veloxa-solar--error' : 'veloxa-solar--empty';
		}

		$body = null === $reading ? $this->empty_state( $error ) : $this->body( $widget, $reading, $source, $label, $now, (string) $connection['id'], $atts );
		$html = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		if ( ! $is_public ) {
			$html .= '<details class="veloxa-solar__privacy"><summary>' . esc_html__( 'Private data', 'veloxa-solar-widget' ) . '</summary><p class="veloxa-solar__private">' . esc_html__( 'Only logged-in editors see this widget. To show it to visitors, enable "Show on public pages" for this connection in Solar Widget → Connections.', 'veloxa-solar-widget' ) . '</p></details>';
		}

		if ( 'summary' !== $widget ) {
			$html .= '<div class="veloxa-solar__label">' . esc_html( $label ) . '</div>';
		}

		$html .= $body;

		if ( null !== $reading ) {
			$html .= $this->meta( $reading, $stale, $now );
		}

		return $html . '</div>';
	}

	/**
	 * Treść widgetu dla istniejącego odczytu.
	 *
	 * @param string          $widget  Widget.
	 * @param PV_Reading      $reading Odczyt.
	 * @param Abstract_Source $source  Źródło.
	 * @param string          $label   Etykieta.
	 * @param int             $now     Bieżący czas.
	 * @param string          $connection_id ID połączenia.
	 * @param array           $atts          Atrybuty widgetu.
	 * @return string
	 */
	private function body( string $widget, PV_Reading $reading, Abstract_Source $source, string $label, int $now, string $connection_id, array $atts = array() ): string {
		switch ( $widget ) {
			case 'power':
				return $this->value( Formatter::power( (float) $reading->get( 'current_power' ) ) );
			case 'today':
				return $this->value( Formatter::energy( (float) $reading->get( 'energy_today' ) ) );
			case 'total':
				return $this->value( Formatter::energy( (float) $reading->get( 'energy_total' ) ) );
			case 'summary':
				$items = array(
					'current_power' => Formatter::power( (float) $reading->get( 'current_power' ) ),
					'energy_today'  => Formatter::energy( (float) $reading->get( 'energy_today' ) ),
					'energy_total'  => Formatter::energy( (float) $reading->get( 'energy_total' ) ),
				);
				$html  = '<dl class="veloxa-solar__summary">';

				foreach ( $items as $field => $formatted ) {
					$html .= '<div class="veloxa-solar__item"><dt class="veloxa-solar__label">' . esc_html( Abstract_Source::field_label( $field ) ) . '</dt> <dd>' . $this->value( $formatted ) . '</dd></div> ';
				}

				return $html . '</dl>';
			case 'battery':
				return $this->battery( $reading, $source->battery_idle_watts() );
			case 'co2':
				return $this->co2( $reading );
		}

		/**
		 * Treść widgetu dodanego filtrem veloxa_solar_widgets.
		 *
		 * @param string          $html          Pusty tekst.
		 * @param string          $widget        Klucz widgetu.
		 * @param PV_Reading      $reading       Odczyt.
		 * @param Abstract_Source $source        Źródło.
		 * @param string          $label         Etykieta.
		 * @param int             $now           Bieżący czas.
		 * @param string          $connection_id ID połączenia.
		 * @param array           $atts          Atrybuty widgetu (np. period, show).
		 */
		return wp_kses( (string) apply_filters( 'veloxa_solar_widget_body', '', $widget, $reading, $source, $label, $now, $connection_id, $atts ), self::allowed_html() );
	}

	/**
	 * Widget baterii: poziom naładowania (jeśli źródło go podaje) i stan
	 * „ładuje / rozładowuje / bezczynna” z mocą (jeśli podaje moc). Stan
	 * zawsze tekstem i ikoną, nigdy samym kolorem.
	 *
	 * @param PV_Reading $reading Odczyt.
	 * @param int        $idle    Próg bezczynności (W) z połączenia.
	 * @return string
	 */
	private function battery( PV_Reading $reading, int $idle ): string {
		$soc   = $reading->get( 'battery_soc' );
		$power = $reading->get( 'battery_power' );
		$html  = '';

		if ( null !== $soc ) {
			$percent = max( 0.0, min( 100.0, $soc ) );
			$html   .= $this->value( Formatter::percentage( $soc ) );
			$html   .= '<div class="veloxa-solar__meter" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $percent ) . '" aria-label="' . esc_attr__( 'Battery charge', 'veloxa-solar-widget' ) . '"><span style="width:' . esc_attr( (string) round( $percent, 1 ) ) . '%"></span></div>';
		}

		if ( null === $power ) {
			return $html;
		}

		$state = self::battery_state( $power, $idle );
		$texts = array(
			'charging'    => __( 'Charging', 'veloxa-solar-widget' ),
			'discharging' => __( 'Discharging', 'veloxa-solar-widget' ),
			'idle'        => __( 'Idle', 'veloxa-solar-widget' ),
		);
		$pair  = Formatter::power( abs( $power ) );

		if ( null === $soc ) {
			$html .= $this->value( $pair );
		}

		$html .= '<p class="veloxa-solar__battery-state veloxa-solar__battery-state--' . esc_attr( $state ) . '"><span class="veloxa-solar__battery-icon" aria-hidden="true"></span>' . esc_html( $texts[ $state ] );

		if ( 'idle' !== $state && null !== $soc ) {
			$html .= ' · ' . esc_html( $pair['value'] . ' ' . $pair['unit'] );
		}

		return $html . '</p>';
	}

	/**
	 * Uniknięta emisja CO₂ (decyzja właściciela 2026-10-10, wersja darmowa):
	 * energia całkowita × współczynnik z ustawień; dzisiejsza z energii dziś.
	 * Zawsze z podanym współczynnikiem — to szacunek, nie pomiar.
	 *
	 * @param PV_Reading $reading Odczyt.
	 * @return string
	 */
	private function co2( PV_Reading $reading ): string {
		$factor = Plugin::co2_kg_per_kwh();
		$html   = $this->value( Formatter::mass( (float) $reading->get( 'energy_total' ) / 1000 * $factor ) );
		$today  = $reading->get( 'energy_today' );

		if ( null !== $today ) {
			$pair = Formatter::mass( $today / 1000 * $factor );
			/* translators: %s: mass of CO2 with unit, e.g. "12 kg" */
			$html .= '<p class="veloxa-solar__co2-today">' . esc_html( sprintf( __( 'Today: %s', 'veloxa-solar-widget' ), $pair['value'] . ' ' . $pair['unit'] ) ) . '</p>';
		}

		/* translators: %s: emission factor, e.g. "0.7" */
		return $html . '<p class="veloxa-solar__co2-note">' . esc_html( sprintf( __( 'Estimate: %s kg CO₂ per kWh produced', 'veloxa-solar-widget' ), Formatter::decimal( $factor ) ) ) . '</p>';
	}

	/**
	 * Stan baterii z mocy (W; + ładowanie, − rozładowanie): |P| poniżej
	 * progu = bezczynna (docs/etap-1.md, 1.7 — domyślnie 50 W, ustawiany w
	 * połączeniu; falowniki raportują w spoczynku kilkadziesiąt W szumu).
	 *
	 * @param float $watts Moc baterii.
	 * @param int   $idle  Próg bezczynności (W).
	 * @return string charging|discharging|idle
	 */
	public static function battery_state( float $watts, int $idle = Abstract_Source::DEFAULT_BATTERY_IDLE_WATTS ): string {
		if ( abs( $watts ) < $idle ) {
			return 'idle';
		}

		return $watts > 0 ? 'charging' : 'discharging';
	}

	/**
	 * Duża liczba z jednostką.
	 *
	 * @param array{value: string, unit: string} $formatted Wartość.
	 * @return string
	 */
	private function value( array $formatted ): string {
		return '<div class="veloxa-solar__value"><span class="veloxa-solar__number">' . esc_html( $formatted['value'] ) . '</span> <span class="veloxa-solar__unit">' . esc_html( $formatted['unit'] ) . '</span></div>';
	}

	/**
	 * Stopka: czas aktualizacji + ewentualnie "dane nieaktualne".
	 *
	 * @param PV_Reading $reading Odczyt.
	 * @param bool       $stale   Czy nieaktualne.
	 * @param int        $now     Bieżący czas.
	 * @return string
	 */
	private function meta( PV_Reading $reading, bool $stale, int $now ): string {
		/* translators: %s: time (or date and time) of the last update */
		$text = sprintf( __( 'Updated %s', 'veloxa-solar-widget' ), Formatter::time( $reading->updated_at(), $now ) );
		$html = '<div class="veloxa-solar__meta"><time datetime="' . esc_attr( gmdate( 'c', $reading->updated_at() ) ) . '">' . esc_html( $text ) . '</time>';

		if ( $stale ) {
			$html .= ' <span class="veloxa-solar__badge">' . esc_html__( 'Data may be out of date', 'veloxa-solar-widget' ) . '</span>';
		}

		return $html . '</div>';
	}

	/**
	 * Stan bez żadnego odczytu: "brak danych" albo "błąd źródła".
	 *
	 * @param string|null $error Ostatni błąd źródła.
	 * @return string
	 */
	private function empty_state( ?string $error ): string {
		if ( null === $error ) {
			return '<p class="veloxa-solar__empty">' . esc_html__( 'Waiting for the first reading.', 'veloxa-solar-widget' ) . '</p>';
		}

		$html = '<p class="veloxa-solar__empty">' . esc_html__( 'The data source is currently unavailable.', 'veloxa-solar-widget' ) . '</p>';

		// Szczegóły błędu tylko dla redaktorów — mogą zawierać nazwy czujników itp.
		if ( current_user_can( 'edit_posts' ) ) {
			$html .= '<p class="veloxa-solar__private">' . esc_html( $error ) . '</p>';
		}

		return $html;
	}

	/**
	 * Znajduje połączenie WYŁĄCZNIE po ID; pusty atrybut = jedyne źródło,
	 * jeśli jest dokładnie jedno. Bez dopasowania po nazwie — zmiana nazwy
	 * połączenia psułaby shortcode'y (ta sama poprawka co w IoT Data Widget).
	 *
	 * @param string $id ID połączenia (może być puste).
	 * @return array<string, mixed>|string Połączenie albo komunikat błędu.
	 */
	private function resolve_connection( string $id ) {
		$sources = $this->source_connections();
		$names   = implode(
			', ',
			array_map(
				static function ( array $connection ): string {
					return $connection['name'] . ' (' . $connection['id'] . ')';
				},
				$sources
			)
		);

		if ( empty( $sources ) ) {
			return __( 'No data source configured yet — add one in Solar Widget → Connections.', 'veloxa-solar-widget' );
		}

		if ( '' === trim( $id ) ) {
			if ( 1 === count( $sources ) ) {
				return $sources[0];
			}

			/* translators: %s: comma-separated connection names with their IDs in brackets */
			return sprintf( __( 'Choose a connection by its ID with the "connection" attribute. Available: %s.', 'veloxa-solar-widget' ), $names );
		}

		foreach ( $sources as $connection ) {
			if ( $connection['id'] === $id ) {
				return $connection;
			}
		}

		/* translators: 1: the value given, 2: comma-separated connection names with their IDs in brackets */
		return sprintf( __( 'Unknown connection ID "%1$s". Available: %2$s.', 'veloxa-solar-widget' ), $id, $names );
	}

	/**
	 * Informacja o funkcji z wersji Pro — tylko dla administratora;
	 * odwiedzający i redaktorzy nie widzą nic.
	 *
	 * @param string $message Komunikat.
	 * @return string
	 */
	public static function pro_notice( string $message ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		wp_enqueue_style( self::STYLE_HANDLE );

		return '<div class="veloxa-solar-notice" role="note"><strong>Veloxa Solar Widget:</strong> ' . esc_html( $message ) . '</div>';
	}

	/**
	 * Komunikat konfiguracji — tylko dla redaktorów; odwiedzający nie widzą
	 * nic (nie ujawniamy konfiguracji).
	 *
	 * @param string $message Komunikat.
	 * @return string
	 */
	private function notice( string $message ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		wp_enqueue_style( self::STYLE_HANDLE );

		return '<div class="veloxa-solar-notice" role="alert"><strong>Veloxa Solar Widget:</strong> ' . esc_html( $message ) . '</div>';
	}
}
