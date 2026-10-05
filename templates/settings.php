<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\API\SettingsRegistry;

$schema   = SettingsRegistry::get_schema();
$saved    = get_option( 'wc_erp_sync_settings', [] );
$sections = [
	'connection' => __( 'API Connection', 'wc-erp-sync' ),
	'sync'       => __( 'Sync Frequency & Batches', 'wc-erp-sync' ),
	'webhooks'   => __( 'Webhooks Integration', 'wc-erp-sync' ),
];
?>

<div class="settings-container">
	<form id="erp-sync-settings-form" method="post">
		<div class="tabs-subnav">
			<?php foreach ( $sections as $slug => $label ) : ?>
				<button type="button" class="tab-sub-btn <?php echo 'connection' === $slug ? 'active' : ''; ?>" data-target="sec-<?php echo esc_attr( $slug ); ?>">
					<?php echo esc_html( $label ); ?>
				</button>
			<?php endforeach; ?>
		</div>

		<div class="settings-panels">
			<?php foreach ( $sections as $sec_slug => $sec_label ) : ?>
				<div class="settings-panel-section <?php echo 'connection' === $sec_slug ? 'active' : ''; ?>" id="sec-<?php echo esc_attr( $sec_slug ); ?>">
					<div class="section-title-desc">
						<h3><?php echo esc_html( $sec_label ); ?></h3>
						<p><?php esc_html_e( 'Configure appropriate options and settings for this component.', 'wc-erp-sync' ); ?></p>
					</div>

					<div class="fields-grid">
						<?php
						foreach ( $schema as $key => $field ) {
							if ( $field['section'] !== $sec_slug ) {
								continue;
							}

							$value = isset( $saved[ $key ] ) ? $saved[ $key ] : $field['default'];
							$field_id = 'field_' . esc_attr( $key );
							?>
							<div class="field-row field-row-<?php echo esc_attr( $key ); ?>" id="row-<?php echo esc_attr( $key ); ?>">
								<div class="field-label-desc">
									<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
									<span class="field-description"><?php echo esc_html( $field['description'] ); ?></span>
								</div>

								<div class="field-input-wrapper">
									<?php if ( 'select' === $field['type'] ) : ?>
										<select id="<?php echo esc_attr( $field_id ); ?>" name="settings[<?php echo esc_attr( $key ); ?>]">
											<?php foreach ( $field['options'] as $opt_key => $opt_val ) : ?>
												<option value="<?php echo esc_attr( $opt_key ); ?>" <?php selected( $value, $opt_key ); ?>>
													<?php echo esc_html( $opt_val ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									<?php elseif ( 'number' === $field['type'] ) : ?>
										<input type="number" id="<?php echo esc_attr( $field_id ); ?>" name="settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="input-number">
									<?php elseif ( 'password' === $field['type'] ) : ?>
										<input type="password" id="<?php echo esc_attr( $field_id ); ?>" name="settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="input-password">
									<?php else : ?>
										<input type="text" id="<?php echo esc_attr( $field_id ); ?>" name="settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="input-text">
									<?php endif; ?>
								</div>
							</div>
							<?php
						}
						?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="settings-actions">
			<button type="submit" class="btn btn-primary">
				<span class="dashicons dashicons-saved"></span>
				<?php esc_html_e( 'Save Settings Options', 'wc-erp-sync' ); ?>
			</button>
			<span id="settings-save-spinner" class="spinner-saving" style="display:none;"><?php esc_html_e( 'Saving options...', 'wc-erp-sync' ); ?></span>
		</div>
	</form>

	<div id="settings-save-message" class="save-result-alert" style="display:none;"></div>
</div>
