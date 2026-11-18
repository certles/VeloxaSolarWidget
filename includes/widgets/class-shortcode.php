<?php
/**
 * Shortcode [veloxa_solar widget="power|today|total|summary|battery"
 * connection="nazwa-lub-id" style="card|minimal" label="…"].
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Shortcode
 */
class Shortcode {

	/**
	 * Nazwa shortcode'u (brief sekcja 2.1).
	 */
	const TAG = 'veloxa_solar';

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

		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Callback shortcode'u.
	 *
	 * @param array<string, string>|string $atts Atrybuty (WP podaje '' przy braku).
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'widget'      => 'summary',
				'connection'  => '',
				'style'       => 'card',
				'label'       => '',
				'period'      => '',
				'show'        => '',
				'connections' => '',
				'day'         => '',
				'view'        => '',
				'unit'        => '',
				'window'      => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		return wp_kses( $this->renderer->render( $atts ), Widget_Renderer::allowed_html() );
	}
}
