<?php
/**
 * Widok: lista połączeń z ostatnim odczytem, formularze dodania źródeł
 * (push, Generic REST, Home Assistant), ustawienia.
 *
 * @package Veloxa\Solar
 *
 * @var array<int, array<string, mixed>>                                     $connections Lista połączeń.
 * @var array{form: string, errors: string[], values: array<string, mixed>} $form_state  Stan ostatniego nieudanego zapisu.
 * @var array<string, array{label: string, description: string}>         $pro_sources Karty źródeł Pro (nieaktywne), patrz Connections_Page::pro_source_cards().
 */

use Veloxa\Solar\Admin\Connections_Page;
use Veloxa\Solar\Model\Reading_Store;
use Veloxa\Solar\Sources\Abstract_Source;
use Veloxa\Solar\Sources\Ha_Source;
use Veloxa\Solar\Sources\Push_Source;
use Veloxa\Solar\Sources\Rest_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- szablon dołączany (require) wewnątrz metody Connections_Page; zmienne są lokalne dla tej metody, nie globalne.

$notice  = isset( $_GET['veloxa_solar_notice'] ) ? sanitize_key( wp_unslash( $_GET['veloxa_solar_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko wybór komunikatu do wyświetlenia.
$notices = array(
	'saved'          => __( 'Connection saved.', 'veloxa-solar-widget' ),
	'deleted'        => __( 'Connection deleted.', 'veloxa-solar-widget' ),
	'refreshed'      => __( 'Refresh finished — see the result in the list below.', 'veloxa-solar-widget' ),
	'settings_saved' => __( 'Settings saved.', 'veloxa-solar-widget' ),
);
$types   = array(
	Push_Source::CONNECTOR_TYPE => __( 'Push', 'veloxa-solar-widget' ),
	Rest_Source::CONNECTOR_TYPE => __( 'Generic REST', 'veloxa-solar-widget' ),
	Ha_Source::CONNECTOR_TYPE   => __( 'Home Assistant', 'veloxa-solar-widget' ),
);
$polled  = array( Rest_Source::CONNECTOR_TYPE, Ha_Source::CONNECTOR_TYPE );

/**
 * Typy połączeń odpytywanych w tle dodawane przez inne moduły (np. Pro) —
 * przy nich pokazywany jest przycisk "Refresh now".
 *
 * @param string[] $polled Typy konektorów.
 */
$polled = (array) apply_filters( 'veloxa_solar_polled_connector_types', $polled );

/**
 * Etykiety typów połączeń dodawanych przez inne moduły.
 *
 * @param array<string, string> $types Typ => etykieta.
 */
$types = (array) apply_filters( 'veloxa_solar_connector_type_labels', $types );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Veloxa Solar Widget — Connections', 'veloxa-solar-widget' ); ?></h1>

	<?php if ( isset( $notices[ $notice ] ) ) : ?>
		<div class="notice notice-success"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
	<?php endif; ?>

	<?php if ( null !== $getting_started ) : ?>
		<?php
		$steps = array(
			'source' => array( __( 'Add a data source', 'veloxa-solar-widget' ), __( 'Choose a data source below, then follow its setup form.', 'veloxa-solar-widget' ) ),
			'public' => array( __( 'Show it on public pages', 'veloxa-solar-widget' ), __( 'Click "Show on public pages" next to the data source — until then only logged-in editors see its widgets.', 'veloxa-solar-widget' ) ),
			'widget' => array( __( 'Add the widget to a page', 'veloxa-solar-widget' ), __( 'Insert the "Solar production" block in the editor, or the [veloxa_solar] shortcode.', 'veloxa-solar-widget' ) ),
		);
		?>
		<div class="notice notice-info inline veloxa-solar-getting-started">
			<h2><?php esc_html_e( 'Getting started', 'veloxa-solar-widget' ); ?></h2>
			<ol>
				<?php foreach ( $steps as $key => $step ) : ?>
					<li>
						<?php if ( $getting_started[ $key ] ) : ?>
							<span aria-hidden="true">✓</span> <s><?php echo esc_html( $step[0] ); ?></s> <span class="screen-reader-text"><?php esc_html_e( '(done)', 'veloxa-solar-widget' ); ?></span>
						<?php else : ?>
							<strong><?php echo esc_html( $step[0] ); ?></strong> — <?php echo esc_html( $step[1] ); ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
			<p><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=veloxa_solar_dismiss_getting_started' ), 'veloxa_solar_dismiss_getting_started' ) ); ?>"><?php esc_html_e( 'Hide this list', 'veloxa-solar-widget' ); ?></a></p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Saved connections', 'veloxa-solar-widget' ); ?></h2>
	<?php if ( empty( $connections ) ) : ?>
		<p><?php esc_html_e( 'No connections yet. Add a source to start displaying your solar data.', 'veloxa-solar-widget' ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $connections ) ) : ?>
	<table class="wp-list-table widefat striped veloxa-solar-connections-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'veloxa-solar-widget' ); ?></th>
				<th><?php esc_html_e( 'Type', 'veloxa-solar-widget' ); ?></th>
				<th><?php esc_html_e( 'Last reading', 'veloxa-solar-widget' ); ?></th>
				<th><?php esc_html_e( 'Public pages', 'veloxa-solar-widget' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $connections as $connection ) : ?>
				<?php
				$reading       = Reading_Store::get( $connection['id'] );
				$last_error    = Reading_Store::get_last_error( $connection['id'] );
				$needs_mapping = Ha_Source::CONNECTOR_TYPE === $connection['connector'] && empty( $connection['fields'] );
				$is_stale      = null !== $reading && isset( $refresh_intervals[ $connection['id'] ] ) && $reading->is_stale( $refresh_intervals[ $connection['id'] ], time() );
				?>
				<tr class="veloxa-solar-connection-row">
					<td data-label="<?php esc_attr_e( 'Name', 'veloxa-solar-widget' ); ?>">
						<?php echo esc_html( $connection['name'] ); ?><br>
						<small><?php esc_html_e( 'ID for shortcodes:', 'veloxa-solar-widget' ); ?> <code><?php echo esc_html( $connection['id'] ); ?></code></small>
					</td>
					<td data-label="<?php esc_attr_e( 'Type', 'veloxa-solar-widget' ); ?>">
						<?php echo esc_html( $types[ $connection['connector'] ] ?? $connection['connector'] ); ?>
						<?php if ( 'solax' === ( $connection['beta'] ?? '' ) ) : ?>
							<br><small>SolaX Cloud</small> <span class="veloxa-solar-beta" style="display:inline-block;padding:0 6px;border-radius:3px;background:#f0c33c;color:#1d2327;font-size:11px;font-weight:600;line-height:18px;vertical-align:middle;"><?php esc_html_e( 'beta', 'veloxa-solar-widget' ); ?></span>
						<?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Last reading', 'veloxa-solar-widget' ); ?>">
						<?php if ( $needs_mapping ) : ?>
							<span class="veloxa-solar-status is-warning"><?php esc_html_e( 'Setup required', 'veloxa-solar-widget' ); ?></span>
						<?php elseif ( null !== $last_error ) : ?>
							<span class="veloxa-solar-status is-error"><?php esc_html_e( 'Last refresh failed', 'veloxa-solar-widget' ); ?></span>
						<?php elseif ( null === $reading ) : ?>
							<span class="veloxa-solar-status is-muted"><?php esc_html_e( 'Waiting for first reading', 'veloxa-solar-widget' ); ?></span>
						<?php elseif ( $is_stale ) : ?>
							<span class="veloxa-solar-status is-warning"><?php esc_html_e( 'Data is out of date', 'veloxa-solar-widget' ); ?></span>
						<?php else : ?>
							<span class="veloxa-solar-status is-success"><?php esc_html_e( 'Data is up to date', 'veloxa-solar-widget' ); ?></span>
						<?php endif; ?>
						<br>
						<?php if ( null !== $reading ) : ?>
							<?php foreach ( $reading->fields() as $field ) : ?>
								<?php echo esc_html( Abstract_Source::field_label( $field ) ); ?>:
								<strong><?php echo esc_html( \Veloxa\Solar\Widgets\Formatter::field( $field, (float) $reading->get( $field ) ) ); ?></strong><br>
							<?php endforeach; ?>
							<small>
								<?php
								/* translators: %s: date and time in the site timezone */
								echo esc_html( sprintf( __( 'Updated: %s', 'veloxa-solar-widget' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $reading->updated_at() ) ) );
								if ( $reading->is_updated_at_approximate() ) {
									echo ' ' . esc_html__( '(approximate — time received, the source gave no usable timestamp)', 'veloxa-solar-widget' );
								}
								?>
							</small>
						<?php endif; ?>
						<?php if ( null !== $last_error ) : ?>
							<p style="color:#b32d2e;margin:4px 0 0;"><?php echo esc_html( $last_error ); ?></p>
						<?php endif; ?>
						<?php if ( $needs_mapping ) : ?>
							<em><?php esc_html_e( 'Choose sensors to finish the setup.', 'veloxa-solar-widget' ); ?></em>
						<?php elseif ( null === $reading && null === $last_error ) : ?>
							<em><?php esc_html_e( 'No reading yet.', 'veloxa-solar-widget' ); ?></em>
						<?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Public pages', 'veloxa-solar-widget' ); ?>">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="veloxa_solar_toggle_public" />
							<input type="hidden" name="id" value="<?php echo esc_attr( $connection['id'] ); ?>" />
							<?php wp_nonce_field( 'veloxa_solar_toggle_public' ); ?>
							<?php if ( ! empty( $connection['public'] ) ) : ?>
								<strong><?php esc_html_e( 'Shown to visitors', 'veloxa-solar-widget' ); ?></strong><br>
								<button type="submit" class="button-link"><?php esc_html_e( 'Hide from visitors', 'veloxa-solar-widget' ); ?></button>
							<?php else : ?>
								<?php esc_html_e( 'Hidden', 'veloxa-solar-widget' ); ?><br>
								<button type="submit" class="button-link"><?php esc_html_e( 'Show on public pages', 'veloxa-solar-widget' ); ?></button>
							<?php endif; ?>
						</form>
					</td>
					<td data-label="<?php esc_attr_e( 'Actions', 'veloxa-solar-widget' ); ?>">
						<a href="<?php echo esc_url( Connections_Page::page_url( array( 'veloxa_solar_edit' => $connection['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'veloxa-solar-widget' ); ?></a> |
						<?php if ( Push_Source::CONNECTOR_TYPE === $connection['connector'] ) : ?>
							<a href="<?php echo esc_url( Connections_Page::page_url( array( 'veloxa_solar_channel' => $connection['id'] ) ) ); ?>"><?php esc_html_e( 'Setup & check', 'veloxa-solar-widget' ); ?></a> |
						<?php endif; ?>
						<?php if ( Ha_Source::CONNECTOR_TYPE === $connection['connector'] ) : ?>
							<a href="<?php echo esc_url( Connections_Page::page_url( array( 'veloxa_solar_ha' => $connection['id'] ) ) ); ?>"><?php esc_html_e( 'Choose sensors', 'veloxa-solar-widget' ); ?></a> |
						<?php endif; ?>
						<?php
						/**
						 * Dodatkowe akcje w wierszu połączenia (np. link do ekranu
						 * pełnoekranowego z includes/premium/). Każda kończy się " | ".
						 *
						 * @param array<string, mixed> $connection Połączenie.
						 */
						do_action( 'veloxa_solar_connection_actions', $connection );
						?>
						<?php if ( in_array( $connection['connector'], $polled, true ) && ! $needs_mapping ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<input type="hidden" name="action" value="veloxa_solar_refresh_connection" />
								<input type="hidden" name="id" value="<?php echo esc_attr( $connection['id'] ); ?>" />
								<?php wp_nonce_field( 'veloxa_solar_refresh_connection' ); ?>
								<button type="submit" class="button-link"><?php esc_html_e( 'Refresh now', 'veloxa-solar-widget' ); ?></button>
							</form>
							|
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<input type="hidden" name="action" value="veloxa_solar_delete_connection" />
							<input type="hidden" name="id" value="<?php echo esc_attr( $connection['id'] ); ?>" />
							<?php wp_nonce_field( 'veloxa_solar_delete_connection' ); ?>
							<button type="submit" class="button-link"><?php esc_html_e( 'Delete', 'veloxa-solar-widget' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<?php $selected_connector = (string) ( $form_state['selected_connector'] ?? '' ); ?>
	<section class="veloxa-solar-add-source" aria-labelledby="veloxa-solar-add-source-title">
		<h2 id="veloxa-solar-add-source-title" tabindex="-1"><?php esc_html_e( 'Add a data source', 'veloxa-solar-widget' ); ?></h2>
		<p class="description"><?php esc_html_e( 'New sources are private by default. You can choose separately whether to show their data on public pages.', 'veloxa-solar-widget' ); ?></p>
		<a class="button button-primary" data-source-start href="<?php echo esc_url( Connections_Page::page_url( array( 'veloxa_solar_choose' => '1' ) ) ); ?>" <?php echo '' !== $selected_connector || $show_chooser ? 'hidden' : ''; ?>><?php esc_html_e( 'Add source', 'veloxa-solar-widget' ); ?></a>
		<div class="veloxa-solar-source-chooser" data-source-chooser <?php echo ! $show_chooser ? 'hidden' : ''; ?>>
			<?php foreach ( $form_types as $connector => $form_type ) : ?>
				<?php
				if ( ! isset( $available_types[ $connector ] ) ) {
					continue;
				}
				?>
				<a class="veloxa-solar-source-card" data-source-select="<?php echo esc_attr( $connector ); ?>" href="<?php echo esc_url( Connections_Page::page_url( array( 'veloxa_solar_add' => $connector ) ) ); ?>">
					<strong><?php echo esc_html( $available_types[ $connector ] ); ?>
						<?php if ( 'solax' === $connector || 'solaredge' === $connector ) : ?>
							<span class="veloxa-solar-beta-label"><?php esc_html_e( 'beta', 'veloxa-solar-widget' ); ?></span>
						<?php endif; ?>
					</strong>
					<span><?php echo esc_html( $form_type['description'] ?? '' ); ?></span>
				</a>
			<?php endforeach; ?>
			<?php foreach ( $pro_sources as $pro_source ) : ?>
				<div class="veloxa-solar-source-card veloxa-solar-source-card--pro">
					<strong><?php echo esc_html( $pro_source['label'] ); ?>
						<span class="veloxa-solar-pro-label"><?php esc_html_e( 'Pro', 'veloxa-solar-widget' ); ?></span>
					</strong>
					<span><?php echo esc_html( $pro_source['description'] ); ?></span>
					<a href="<?php echo esc_url( Connections_Page::plans_url() ); ?>"><?php esc_html_e( 'Available in Pro — see plans', 'veloxa-solar-widget' ); ?></a>
				</div>
			<?php endforeach; ?>
			<p><a data-source-cancel href="<?php echo esc_url( Connections_Page::page_url() ); ?>"><?php esc_html_e( 'Cancel adding source', 'veloxa-solar-widget' ); ?></a></p>
		</div>
		<?php if ( '' !== $selected_connector ) : ?>
			<div class="veloxa-solar-selected-source">
				<div>
					<h3 tabindex="-1" data-source-heading><?php echo esc_html( $available_types[ $selected_connector ] ?? $selected_connector ); ?></h3>
					<p class="description"><?php esc_html_e( 'Changing source type will discard unsaved entries.', 'veloxa-solar-widget' ); ?></p>
				</div>
				<a class="button" data-source-back href="<?php echo esc_url( Connections_Page::page_url( array( 'veloxa_solar_choose' => '1' ) ) ); ?>"><?php esc_html_e( 'Change source type', 'veloxa-solar-widget' ); ?></a>
			</div>
			<?php if ( '' !== (string) ( $form_state['form'] ?? '' ) && ! empty( $form_state['errors'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'Your non-secret entries were restored. For your security, enter passwords and tokens again.', 'veloxa-solar-widget' ); ?></p>
			<?php endif; ?>
			<?php
			$editing      = null;
			$push_values  = 'veloxa-solar-push-form' === $form_state['form'] ? $form_state['values'] : array();
			$rest_values  = 'veloxa-solar-rest-form' === $form_state['form'] ? $form_state['values'] : array();
			$solax_values = 'veloxa-solar-solax-form' === $form_state['form'] ? $form_state['values'] : array();
			$ha_values    = 'veloxa-solar-ha-form' === $form_state['form'] ? $form_state['values'] : array();
			foreach ( $form_types as $connector => $form_type ) :
				if ( ! isset( $available_types[ $connector ] ) ) {
					continue;
				}
				?>
				<div class="veloxa-solar-source-form" id="veloxa-solar-form-<?php echo esc_attr( sanitize_html_class( $connector ) ); ?>" data-source-label="<?php echo esc_attr( $available_types[ $connector ] ); ?>" <?php echo '' === $selected_connector || $connector !== $selected_connector ? 'hidden' : ''; ?>>
					<?php
					$form_state['selected_connector'] = $connector;
					switch ( $connector ) {
						case Push_Source::CONNECTOR_TYPE:
							require __DIR__ . '/partials/push-form.php';
							break;
						case Rest_Source::CONNECTOR_TYPE:
							require __DIR__ . '/partials/rest-form.php';
							break;
						case Ha_Source::CONNECTOR_TYPE:
							require __DIR__ . '/partials/ha-form.php';
							break;
						case 'solax':
							require __DIR__ . '/partials/solax-form.php';
							break;
						default:
							/** Formularze z modułów Pro (jeśli dostępne). */
							do_action( 'veloxa_solar_connection_forms', $form_state );
							break;
					}
					?>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</section>

	<?php $veloxa_solar_settings = get_option( \Veloxa\Solar\Plugin::SETTINGS_OPTION, array() ); ?>
	<h2><?php esc_html_e( 'Settings', 'veloxa-solar-widget' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="veloxa_solar_save_settings" />
		<?php wp_nonce_field( 'veloxa_solar_save_settings' ); ?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Local network', 'veloxa-solar-widget' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="allow_local_network" value="1" <?php checked( ! empty( $veloxa_solar_settings['allow_local_network'] ) ); ?> <?php disabled( ! Connections_Page::can_manage_local_network() ); ?> />
						<?php esc_html_e( 'Allow local network addresses', 'veloxa-solar-widget' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Turn this on only when this WordPress site runs in the same network as your Home Assistant or device — for example on a Raspberry Pi at home — and you want to use an address such as http://homeassistant.local:8123 or http://192.168.1.10:8123. On regular web hosting leave it off: your home network cannot be reached from there anyway.', 'veloxa-solar-widget' ); ?></p>
					<p class="description"><?php esc_html_e( 'Link-local addresses (169.254.x.x, used by cloud metadata services) stay blocked even with this option on.', 'veloxa-solar-widget' ); ?></p>
					<?php if ( ! Connections_Page::can_manage_local_network() ) : ?>
						<p class="description"><?php esc_html_e( 'Only a network administrator can change this setting.', 'veloxa-solar-widget' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="veloxa-solar-co2"><?php esc_html_e( 'Avoided CO₂ (kg per kWh)', 'veloxa-solar-widget' ); ?></label></th>
				<td>
					<input type="number" id="veloxa-solar-co2" name="co2_kg_per_kwh" class="small-text" min="0" max="<?php echo esc_attr( (string) \Veloxa\Solar\Plugin::MAX_CO2_KG_PER_KWH ); ?>" step="0.001" value="<?php echo esc_attr( (string) \Veloxa\Solar\Plugin::co2_kg_per_kwh() ); ?>" />
					<p class="description"><?php esc_html_e( 'How much CO₂ one kWh of grid electricity causes where the installation is. The "Avoided CO₂" widget multiplies the energy produced by this number. The default 0.7 is about the Polish grid (KOBiZE); use the factor for your country or your energy supplier.', 'veloxa-solar-widget' ); ?></p>
				</td>
			</tr>
			<?php
			/**
			 * Dodatkowe wiersze ustawień (np. marka w planie Agency).
			 *
			 * @param array<string, mixed> $settings Zapisane ustawienia.
			 */
			do_action( 'veloxa_solar_settings_rows', is_array( $veloxa_solar_settings ) ? $veloxa_solar_settings : array() );
			?>
			<tr>
				<th><?php esc_html_e( 'Uninstall', 'veloxa-solar-widget' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="remove_data_on_uninstall" value="1" <?php checked( ! empty( $veloxa_solar_settings['remove_data_on_uninstall'] ) ); ?> />
						<?php esc_html_e( 'Remove all plugin data when the plugin is deleted', 'veloxa-solar-widget' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Connections, push channel tokens, received values, history, diagnostics and cached data will be permanently removed. Leave unchecked to keep your data if you reinstall later.', 'veloxa-solar-widget' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save settings', 'veloxa-solar-widget' ) ); ?>
	</form>
</div>
