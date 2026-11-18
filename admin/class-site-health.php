<?php
/**
 * Testy w „Narzędzia → Stan witryny” (0.6, plan kolejnych etapów, etap 1):
 * czy źródła danych są aktualne i czy zadanie odświeżania działa. Ten sam
 * stan co ekran diagnostyki — administrator widzi problem tam, gdzie WordPress
 * i tak go o kłopoty pyta, zanim napisze do wsparcia.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Admin;

use Veloxa\Solar\Core\Diagnostics_Logger;
use Veloxa\Solar\Source_Refresher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Site_Health
 */
class Site_Health {

	/**
	 * Zadanie odświeżania uznajemy za zatrzymane, gdy nie uruchomiło się
	 * dłużej niż tyle sekund (cron co 5 minut).
	 */
	const CRON_LATE_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Diagnostyka (stan połączeń).
	 *
	 * @var Diagnostics_Page
	 */
	private $diagnostics;

	/**
	 * Konstruktor.
	 *
	 * @param Diagnostics_Page $diagnostics Diagnostyka.
	 */
	public function __construct( Diagnostics_Page $diagnostics ) {
		$this->diagnostics = $diagnostics;

		add_filter( 'site_status_tests', array( $this, 'register' ) );
	}

	/**
	 * Rejestruje testy (bezpośrednie — bez zapytań sieciowych).
	 *
	 * @param array<string, mixed> $tests Testy.
	 * @return array<string, mixed>
	 */
	public function register( $tests ): array {
		$tests = is_array( $tests ) ? $tests : array();

		$tests['direct']['veloxa_solar_sources'] = array(
			'label' => __( 'Solar data sources are up to date', 'veloxa-solar-widget' ),
			'test'  => array( $this, 'test_sources' ),
		);
		$tests['direct']['veloxa_solar_cron']    = array(
			'label' => __( 'Solar data is refreshed in the background', 'veloxa-solar-widget' ),
			'test'  => array( $this, 'test_cron' ),
		);

		return $tests;
	}

	/**
	 * Test: źródła danych.
	 *
	 * @return array<string, mixed>
	 */
	public function test_sources(): array {
		$rows     = $this->diagnostics->connections_info( time() );
		$problems = array();

		foreach ( $rows as $row ) {
			if ( Diagnostics_Page::STATUS_OK !== $row['status'] ) {
				$problems[] = sprintf(
					/* translators: 1: connection name, 2: status, e.g. "Data out of date" */
					__( '%1$s: %2$s', 'veloxa-solar-widget' ),
					$row['name'],
					Diagnostics_Page::status_label( $row['status'] ) . ( null !== $row['last_error'] ? ' — ' . $row['last_error'] : '' )
				);
			} elseif ( ! empty( $row['rare_refresh'] ) ) {
				$problems[] = sprintf(
					/* translators: 1: connection name, 2: refreshes in the last 24 hours, 3: expected refreshes */
					__( '%1$s: refreshed %2$d times in the last 24 hours (expected about %3$d)', 'veloxa-solar-widget' ),
					$row['name'],
					$row['attempts_24h'],
					$row['expected_24h']
				);
			}
		}

		$result = array(
			'label'       => __( 'Solar data sources are up to date', 'veloxa-solar-widget' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Solar Widget', 'veloxa-solar-widget' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Every data source of Veloxa Solar Widget delivers current readings.', 'veloxa-solar-widget' ) . '</p>',
			'actions'     => '',
			'test'        => 'veloxa_solar_sources',
		);

		if ( empty( $rows ) ) {
			$result['description'] = '<p>' . esc_html__( 'No data source is set up yet.', 'veloxa-solar-widget' ) . '</p>';
			$result['actions']     = '<p><a href="' . esc_url( Connections_Page::page_url() ) . '">' . esc_html__( 'Add a data source', 'veloxa-solar-widget' ) . '</a></p>';

			return $result;
		}

		if ( empty( $problems ) ) {
			return $result;
		}

		$result['status']      = 'recommended';
		$result['label']       = __( 'Some solar data sources need attention', 'veloxa-solar-widget' );
		$result['description'] = '<p>' . esc_html__( 'Widgets of these data sources show old data or none:', 'veloxa-solar-widget' ) . '</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $problems ) ) . '</li></ul>';
		$result['actions']     = '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . Diagnostics_Page::PAGE_SLUG ) ) . '">' . esc_html__( 'Open the Solar Widget diagnostics', 'veloxa-solar-widget' ) . '</a></p>';

		return $result;
	}

	/**
	 * Test: zadanie odświeżania w tle.
	 *
	 * @return array<string, mixed>
	 */
	public function test_cron(): array {
		$next = wp_next_scheduled( Source_Refresher::CRON_HOOK );
		$last = Diagnostics_Logger::get_cron_status( Source_Refresher::CRON_HOOK );
		$ran  = null === $last ? null : (int) strtotime( $last['ran_at'] . ' UTC' );

		$result = array(
			'label'       => __( 'Solar data is refreshed in the background', 'veloxa-solar-widget' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Solar Widget', 'veloxa-solar-widget' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'The background task that refreshes solar data is scheduled and runs regularly.', 'veloxa-solar-widget' ) . '</p>',
			'actions'     => '',
			'test'        => 'veloxa_solar_cron',
		);

		if ( false === $next ) {
			$result['status']      = 'critical';
			$result['label']       = __( 'Solar data is not refreshed — the background task is missing', 'veloxa-solar-widget' );
			$result['description'] = '<p>' . esc_html__( 'The background task of Veloxa Solar Widget is not scheduled, so widgets stop updating. Deactivate and activate the plugin to schedule it again.', 'veloxa-solar-widget' ) . '</p>';

			return $result;
		}

		if ( null !== $ran && time() - $ran > self::CRON_LATE_SECONDS ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Solar data is refreshed late', 'veloxa-solar-widget' );
			$result['description'] = '<p>' . esc_html(
				sprintf(
					/* translators: %s: how long ago, e.g. "3 hours" */
					__( 'The background task last ran %s ago. WP-Cron runs only when someone visits the site; on a site with few visitors set up a real cron job that calls wp-cron.php every 5 minutes.', 'veloxa-solar-widget' ),
					human_time_diff( $ran, time() )
				)
			) . '</p>';
			$result['actions'] = '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . Diagnostics_Page::PAGE_SLUG ) ) . '">' . esc_html__( 'Open the Solar Widget diagnostics', 'veloxa-solar-widget' ) . '</a></p>';
		}

		return $result;
	}
}
