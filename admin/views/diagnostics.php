<?php
/**
 * Widok: strona diagnostyki — środowisko, zadania WP-Cron, stan połączeń,
 * ostatnie błędy, rozmiar danych i przycisk "Copy report".
 *
 * @package Veloxa\Solar
 *
 * @var array<string, mixed> $data        Wynik Diagnostics_Page::collect().
 * @var string               $report_text Raport tekstowy (już zredagowany).
 */

use Veloxa\Solar\Admin\Connections_Page;
use Veloxa\Solar\Admin\Diagnostics_Page;
use Veloxa\Solar\Core\Diagnostics_Logger;
use Veloxa\Solar\Sources\Abstract_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- szablon dołączany (require) wewnątrz metody Diagnostics_Page; zmienne są lokalne dla tej metody, nie globalne.

$env           = $data['environment'];
$status_colors = array(
	Diagnostics_Page::STATUS_OK       => '#00a32a',
	Diagnostics_Page::STATUS_STALE    => '#dba617',
	Diagnostics_Page::STATUS_ERROR    => '#b32d2e',
	Diagnostics_Page::STATUS_UNMAPPED => '#dba617',
	Diagnostics_Page::STATUS_UNKNOWN  => '#b32d2e',
);
$local_time    = static function ( ?int $timestamp ): string {
	return null === $timestamp ? '—' : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
};
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Veloxa Solar Widget — Diagnostics', 'veloxa-solar-widget' ); ?></h1>
	<p><?php esc_html_e( 'The state of your data sources and of the background tasks that refresh them — useful when a widget shows old data or none at all.', 'veloxa-solar-widget' ); ?></p>

	<p>
		<button type="button" id="veloxa-solar-copy-report" class="button button-primary"><?php esc_html_e( 'Copy diagnostic report', 'veloxa-solar-widget' ); ?></button>
		<span id="veloxa-solar-copy-report-status" role="status" style="margin-left:8px;"></span><br>
		<span class="description"><?php esc_html_e( 'Copies this page as plain text, ready to paste into a support request. Addresses, tokens and API keys are never included.', 'veloxa-solar-widget' ); ?></span>
	</p>
	<textarea id="veloxa-solar-report-text" class="large-text code" rows="12" readonly hidden><?php echo esc_textarea( $report_text ); ?></textarea>

	<?php if ( $env['wp_cron_disabled'] ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). Data sources are refreshed only if a real cron job calls wp-cron.php — ask your host to set one up, every 5 minutes.', 'veloxa-solar-widget' ); ?></p></div>
	<?php endif; ?>

	<?php $rare = array_values( array_filter( $data['connections'], static fn( array $row ): bool => ! empty( $row['rare_refresh'] ) ) ); ?>
	<?php if ( ! empty( $rare ) ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: comma-separated connection names */
						__( 'Data was refreshed much less often than set in the last 24 hours: %s. WP-Cron runs only when someone visits your site, so on a site with few visitors data arrives late or with gaps. Set up a real cron job that runs wp-cron.php every 5 minutes — see "My site gets few visitors" in the plugin FAQ, or ask your host.', 'veloxa-solar-widget' ),
						implode(
							', ',
							array_map(
								static fn( array $row ): string => sprintf(
									/* translators: 1: connection name, 2: refreshes in the last 24 hours, 3: expected refreshes */
									__( '%1$s (%2$d of about %3$d)', 'veloxa-solar-widget' ),
									$row['name'],
									$row['attempts_24h'],
									$row['expected_24h']
								),
								$rare
							)
						)
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Connections', 'veloxa-solar-widget' ); ?></h2>
	<table class="wp-list-table widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Connection', 'veloxa-solar-widget' ); ?></th>
				<th><?php esc_html_e( 'Status', 'veloxa-solar-widget' ); ?></th>
				<th><?php esc_html_e( 'Measured at', 'veloxa-solar-widget' ); ?></th>
				<th><?php esc_html_e( 'Last attempt', 'veloxa-solar-widget' ); ?></th>
				<th><?php esc_html_e( 'Consecutive errors', 'veloxa-solar-widget' ); ?></th>
				<th><?php esc_html_e( 'Last error', 'veloxa-solar-widget' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $data['connections'] ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No connections yet.', 'veloxa-solar-widget' ); ?> <a href="<?php echo esc_url( Connections_Page::page_url() ); ?>"><?php esc_html_e( 'Add one', 'veloxa-solar-widget' ); ?></a></td></tr>
			<?php endif; ?>
			<?php foreach ( $data['connections'] as $row ) : ?>
				<tr>
					<td>
						<a href="<?php echo esc_url( Connections_Page::page_url( array( 'veloxa_solar_edit' => $row['id'] ) ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a><br>
						<small><?php echo esc_html( $row['type_label'] ); ?> · <code><?php echo esc_html( $row['id'] ); ?></code></small>
					</td>
					<td>
						<strong style="color:<?php echo esc_attr( $status_colors[ $row['status'] ] ?? 'inherit' ); ?>;"><?php echo esc_html( Diagnostics_Page::status_label( $row['status'] ) ); ?></strong>
						<?php if ( ! empty( $row['fields'] ) ) : ?>
							<br><small><?php echo esc_html( implode( ', ', array_map( array( Abstract_Source::class, 'field_label' ), $row['fields'] ) ) ); ?></small>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( $local_time( $row['updated_at'] ) ); ?>
						<?php if ( $row['approximate'] ) : ?>
							<br><small><?php esc_html_e( 'approximate — time received', 'veloxa-solar-widget' ); ?></small>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( $local_time( $row['last_attempt_at'] ) ); ?><br>
						<small>
							<?php
							/* translators: %d: minutes */
							echo esc_html( sprintf( __( 'every %d min', 'veloxa-solar-widget' ), $row['refresh_minutes'] ) );
							?>
						</small>
						<?php if ( null !== $row['last_received_at'] ) : ?>
							<br><small>
								<?php
								/* translators: %s: date and time */
								echo esc_html( sprintf( __( 'last push: %s', 'veloxa-solar-widget' ), $local_time( $row['last_received_at'] ) ) );
								?>
							</small>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $row['consecutive_errors'] > Diagnostics_Logger::PERSISTENT_ERROR_THRESHOLD ) : ?>
							<strong style="color:#b32d2e;"><?php echo esc_html( number_format_i18n( $row['consecutive_errors'] ) ); ?></strong>
						<?php else : ?>
							<?php echo esc_html( number_format_i18n( $row['consecutive_errors'] ) ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $row['last_error'] ?? '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php $veloxa_solar_ha = array_values( array_filter( $data['connections'], static fn( array $row ): bool => 'ha' === $row['type'] ) ); ?>
	<?php if ( ! empty( $veloxa_solar_ha ) ) : ?>
		<h2><?php esc_html_e( 'Home Assistant sensors', 'veloxa-solar-widget' ); ?></h2>
		<p><?php esc_html_e( 'Is your inverter not recognised when choosing sensors? Download the list of your power, energy and battery sensors and send it to support — we use it to add your inverter. The file contains sensor names and current values only, not your Home Assistant address or token. Look through it before sending and remove anything you consider private.', 'veloxa-solar-widget' ); ?></p>
		<ul>
			<?php foreach ( $veloxa_solar_ha as $row ) : ?>
				<li><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=veloxa_solar_export_ha_sensors&connection=' . rawurlencode( $row['id'] ) ), 'veloxa_solar_export_ha_sensors' ) ); ?>">
					<?php
					/* translators: %s: connection name */
					echo esc_html( sprintf( __( 'Download sensor list: %s', 'veloxa-solar-widget' ), $row['name'] ) );
					?>
				</a></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Scheduled tasks', 'veloxa-solar-widget' ); ?></h2>
	<table class="wp-list-table widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Task', 'veloxa-solar-widget' ); ?></th>
				<th><?php esc_html_e( 'Status', 'veloxa-solar-widget' ); ?></th>
				<th><?php esc_html_e( 'Next run', 'veloxa-solar-widget' ); ?></th>
				<th><?php esc_html_e( 'Last run', 'veloxa-solar-widget' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $data['cron'] as $job ) : ?>
				<?php $job_ok = null !== $job['next_run'] && ( null === $job['last_run'] || $job['last_run']['success'] ); ?>
				<tr>
					<td><?php echo esc_html( $job['label'] ); ?><br><small><code><?php echo esc_html( $job['hook'] ); ?></code></small></td>
					<td><strong style="color:<?php echo esc_attr( $job_ok ? '#00a32a' : '#b32d2e' ); ?>;"><?php echo esc_html( Diagnostics_Page::cron_status_text( $job ) ); ?></strong></td>
					<td><?php echo esc_html( $local_time( $job['next_run'] ) ); ?></td>
					<td>
						<?php echo esc_html( null === $job['last_run'] ? '—' : $local_time( (int) strtotime( $job['last_run']['ran_at'] . ' UTC' ) ) ); ?>
						<?php if ( null !== $job['last_run'] && '' !== $job['last_run']['message'] ) : ?>
							<br><small><?php echo esc_html( $job['last_run']['message'] ); ?></small>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Recent errors', 'veloxa-solar-widget' ); ?></h2>
	<table class="wp-list-table widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'When', 'veloxa-solar-widget' ); ?></th>
				<th><?php esc_html_e( 'Source', 'veloxa-solar-widget' ); ?></th>
				<th><?php esc_html_e( 'Message', 'veloxa-solar-widget' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $data['errors'] ) ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'No errors recorded — everything is working.', 'veloxa-solar-widget' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $data['errors'] as $entry ) : ?>
				<tr>
					<td><?php echo esc_html( $local_time( (int) strtotime( $entry['occurred_at'] . ' UTC' ) ) ); ?></td>
					<td><code><?php echo esc_html( $entry['source'] ); ?></code></td>
					<td><?php echo esc_html( $entry['message'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Environment', 'veloxa-solar-widget' ); ?></h2>
	<table class="widefat striped">
		<tbody>
			<?php
			$yes      = __( 'Yes', 'veloxa-solar-widget' );
			$no       = __( 'No', 'veloxa-solar-widget' );
			$env_rows = array(
				__( 'Plugin version', 'veloxa-solar-widget' ) => $env['plugin_version'],
				__( 'Pro features installed', 'veloxa-solar-widget' ) => $env['pro_code_installed'] ? $yes : $no,
				__( 'WordPress version', 'veloxa-solar-widget' ) => $env['wp_version'],
				__( 'PHP version', 'veloxa-solar-widget' ) => $env['php_version'],
				__( 'Multisite', 'veloxa-solar-widget' )   => $env['multisite'] ? $yes : $no,
				__( 'Site timezone', 'veloxa-solar-widget' ) => $env['timezone'],
				__( 'Active theme', 'veloxa-solar-widget' ) => $env['active_theme'],
				__( 'Memory limit', 'veloxa-solar-widget' ) => $env['memory_limit'],
				/* translators: %s: seconds */
				__( 'Max execution time', 'veloxa-solar-widget' ) => sprintf( __( '%ss', 'veloxa-solar-widget' ), $env['max_execution_time'] ),
				__( 'WP-Cron disabled', 'veloxa-solar-widget' ) => $env['wp_cron_disabled'] ? $yes : $no,
				__( 'Local network addresses allowed', 'veloxa-solar-widget' ) => $env['allow_local_network'] ? $yes : $no,
				__( 'Other active plugins', 'veloxa-solar-widget' ) => empty( $env['other_plugins'] ) ? __( 'None', 'veloxa-solar-widget' ) : implode( ', ', $env['other_plugins'] ),
			);
			?>
			<?php foreach ( $env_rows as $label => $value ) : ?>
				<tr>
					<th style="width:260px;"><?php echo esc_html( $label ); ?></th>
					<td><?php echo esc_html( $value ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Data size', 'veloxa-solar-widget' ); ?></h2>
	<table class="widefat striped">
		<tbody>
			<?php foreach ( $data['data_sizes'] as $table ) : ?>
				<tr>
					<th style="width:260px;"><?php echo esc_html( $table['label'] ); ?></th>
					<td>
						<?php
						/* translators: %s: number of rows */
						echo esc_html( sprintf( _n( '%s row', '%s rows', $table['count'], 'veloxa-solar-widget' ), number_format_i18n( $table['count'] ) ) );
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
