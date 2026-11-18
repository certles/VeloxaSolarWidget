<?php
/**
 * Blok Gutenberga veloxa-solar-widget/widget — renderowany po stronie serwera
 * tym samym Widget_Renderer co shortcode (jedno źródło HTML-a). Skrypt
 * edytora bez kroku budowania (wp.element.createElement), podgląd przez
 * ServerSideRender.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Block
 */
class Block {

	/**
	 * Nazwa bloku (brief sekcja 2.1).
	 */
	const NAME = 'veloxa-solar-widget/widget';

	/**
	 * Uchwyt skryptu edytora.
	 */
	const EDITOR_SCRIPT_HANDLE = 'veloxa-solar-block-editor';

	/**
	 * Renderer.
	 *
	 * @var Widget_Renderer
	 */
	private $renderer;

	/**
	 * Konstruktor.
	 *
	 * @param Widget_Renderer $renderer Renderer.
	 */
	public function __construct( Widget_Renderer $renderer ) {
		$this->renderer = $renderer;

		add_action( 'init', array( $this, 'register' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ) );
	}

	/**
	 * Rejestruje blok.
	 */
	public function register(): void {
		wp_register_script(
			self::EDITOR_SCRIPT_HANDLE,
			VELOXA_SOLAR_PLUGIN_URL . 'blocks/widget/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			VELOXA_SOLAR_VERSION,
			true
		);
		// Tłumaczenia edytora (wp.i18n) — w wersji darmowej i Pro.
		\Veloxa\Solar\I18n::register_script( self::EDITOR_SCRIPT_HANDLE );

		register_block_type(
			self::NAME,
			array(
				'api_version'     => 3,
				'title'           => __( 'Solar production', 'veloxa-solar-widget' ),
				'category'        => 'widgets',
				'icon'            => 'chart-area',
				'editor_script'   => self::EDITOR_SCRIPT_HANDLE,
				'style'           => Widget_Renderer::STYLE_HANDLE,
				'render_callback' => array( $this, 'render' ),
				'attributes'      => array(
					'widget'     => array(
						'type'    => 'string',
						'default' => 'summary',
					),
					'connection' => array(
						'type'    => 'string',
						'default' => '',
					),
					'style'      => array(
						'type'    => 'string',
						'default' => 'card',
					),
					'label'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'period'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'show'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'day'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'view'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'unit'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'window'     => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Dane dla edytora: widgety, style, połączenia (tylko ID i nazwa — bez
	 * żadnych sekretów).
	 */
	public function editor_data(): void {
		$labels      = Widget_Renderer::widget_labels();
		$connections = array_map(
			function ( array $connection ) use ( $labels ): array {
				$source = $this->renderer->source_for( $connection );
				$fields = null === $source ? array() : wp_list_pluck( $source->list_entities(), 'id' );

				return array(
					'id'      => (string) $connection['id'],
					'name'    => (string) $connection['name'],
					'public'  => ! empty( $connection['public'] ),
					// Widgety, dla których źródło ma dane (brief: UI nie
					// sugeruje danych, których źródło nie dostarcza).
					'widgets' => array_values(
						array_filter(
							array_keys( $labels ),
							static function ( string $widget ) use ( $fields ): bool {
								return Widget_Renderer::widget_supported( $widget, $fields );
							}
						)
					),
				);
			},
			$this->renderer->source_connections()
		);

		// Widgety Pro, gdy funkcje Pro nie działają: na liście z oznaczeniem
		// "(Pro)", po wybraniu — komunikat w edytorze zamiast podglądu.
		$pro    = array_diff_key( Widget_Renderer::pro_widget_labels(), $labels );
		$agency = array_diff_key( Widget_Renderer::agency_widget_labels(), $labels );

		/**
		 * Opcje atrybutów widgetów dla panelu bloku:
		 * widget => atrybut => array{label, multiple, options}.
		 *
		 * @param array<string, array<string, array{label: string, multiple: bool, options: array<string, string>}>> $options Opcje.
		 */
		$attribute_options = apply_filters( 'veloxa_solar_block_attribute_options', array() );

		wp_localize_script(
			self::EDITOR_SCRIPT_HANDLE,
			'veloxaSolarBlock',
			array(
				'widgets'       => $labels,
				'proWidgets'    => $pro,
				'proNotice'     => __( 'This widget is available in the Pro version.', 'veloxa-solar-widget' ),
				'agencyWidgets' => $agency,
				'agencyNotice'  => __( 'This widget is available in the Agency plan.', 'veloxa-solar-widget' ),
				// Widgety bez wyboru źródła (suma wszystkich instalacji).
				'noSource'      => Widget_Renderer::NO_SOURCE_WIDGETS,
				'attributes'    => is_array( $attribute_options ) ? $attribute_options : array(),
				'styles'        => array(
					'card'    => __( 'Card', 'veloxa-solar-widget' ),
					'minimal' => __( 'Minimal', 'veloxa-solar-widget' ),
				),
				'connections'   => $connections,
			)
		);
	}

	/**
	 * Callback renderowania bloku.
	 *
	 * @param array<string, mixed> $attributes Atrybuty bloku.
	 * @return string
	 */
	public function render( $attributes ): string {
		return wp_kses( $this->renderer->render( is_array( $attributes ) ? $attributes : array() ), Widget_Renderer::allowed_html() );
	}
}
