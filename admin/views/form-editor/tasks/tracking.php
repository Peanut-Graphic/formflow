<?php
if (!defined('ABSPATH')) { exit; }
$gtm = $instance['settings']['gtm'] ?? [];
$analytics = $instance['settings']['analytics'] ?? [];
?>
<div class="isf-fe-task-panel" data-task="tracking">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Tracking', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('UTM, GA4, attribution, fraud detection.', 'formflow'); ?></p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="gtm_enabled"><?php esc_html_e('Google Tag Manager', 'formflow'); ?></label></th>
                <td>
                    <label><input type="checkbox" id="gtm_enabled" name="settings[gtm][enabled]" value="1" data-fe-autosave <?php checked(!empty($gtm['enabled'])); ?>> <?php esc_html_e('Enable GTM/GA4 instrumentation', 'formflow'); ?></label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gtm_container_id"><?php esc_html_e('GTM container id', 'formflow'); ?></label></th>
                <td><input type="text" id="gtm_container_id" name="settings[gtm][container_id]" class="regular-text" data-fe-autosave value="<?php echo esc_attr($gtm['container_id'] ?? ''); ?>" placeholder="GTM-XXXXXXX"></td>
            </tr>
            <tr>
                <th scope="row"><label for="ga4_measurement_id"><?php esc_html_e('GA4 measurement id', 'formflow'); ?></label></th>
                <td><input type="text" id="ga4_measurement_id" name="settings[gtm][ga4_measurement_id]" class="regular-text" data-fe-autosave value="<?php echo esc_attr($gtm['ga4_measurement_id'] ?? ''); ?>" placeholder="G-XXXXXXXXXX"></td>
            </tr>
        </table>

        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php'; ?>
    </div>
</div>
