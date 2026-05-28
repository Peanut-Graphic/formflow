<?php
/**
 * Setup task — simplest task. Single-pane (no sub-rail).
 *
 * Expects $instance, $mode in scope.
 */
if (!defined('ABSPATH')) { exit; }

use ISF\FormEditor\ModeResolver;

$is_dev = $mode === ModeResolver::MODE_DEV;
?>
<div class="isf-fe-task-panel" data-task="setup">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Form setup', 'formflow'); ?></h2>
        <p class="description">
            <?php esc_html_e('Identity, type, and utility. These rarely change after initial setup.', 'formflow'); ?>
        </p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="name"><?php esc_html_e('Form name', 'formflow'); ?> <span class="required">*</span></label></th>
                <td>
                    <input type="text" id="name" name="name" class="regular-text"
                        data-fe-autosave
                        value="<?php echo esc_attr($instance['name'] ?? ''); ?>"
                        <?php disabled(!$is_dev); ?>>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="slug"><?php esc_html_e('Slug', 'formflow'); ?> <span class="required">*</span></label></th>
                <td>
                    <code>[isf_form instance="</code><input type="text" id="slug" name="slug"
                        data-fe-autosave
                        value="<?php echo esc_attr($instance['slug'] ?? ''); ?>"
                        <?php disabled(!$is_dev); ?>><code>"]</code>
                    <p class="description"><?php esc_html_e('URL-friendly identifier used in the shortcode.', 'formflow'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="form_type"><?php esc_html_e('Form type', 'formflow'); ?></label></th>
                <td>
                    <select id="form_type" name="form_type" data-fe-autosave <?php disabled(!$is_dev); ?>>
                        <option value="custom"      <?php selected($instance['form_type'] ?? '', 'custom'); ?>><?php esc_html_e('Custom (call-center handoff)', 'formflow'); ?></option>
                        <option value="enrollment"  <?php selected($instance['form_type'] ?? '', 'enrollment'); ?>><?php esc_html_e('Enrollment (IntelliSOURCE)', 'formflow'); ?></option>
                        <option value="scheduler"   <?php selected($instance['form_type'] ?? '', 'scheduler'); ?>><?php esc_html_e('Scheduler only', 'formflow'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="utility"><?php esc_html_e('Utility', 'formflow'); ?></label></th>
                <td>
                    <input type="text" id="utility" name="utility" class="regular-text"
                        data-fe-autosave
                        value="<?php echo esc_attr($instance['utility'] ?? ''); ?>"
                        <?php disabled(!$is_dev); ?>>
                </td>
            </tr>
        </table>

        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php'; ?>
    </div>
</div>
