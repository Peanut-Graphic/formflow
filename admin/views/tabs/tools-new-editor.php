<?php
/**
 * Tools → New Editor (Beta) — toggle UI for the ISF_NEW_EDITOR option.
 * If the constant is defined in wp-config.php, show that takes precedence
 * and the UI is informational only.
 *
 * @package FormFlow
 */
if (!defined('ABSPATH')) { exit; }

use ISF\FormEditor\FeatureFlag;

$constant_defined = defined(FeatureFlag::CONSTANT);
$current = FeatureFlag::is_enabled();
?>
<div class="isf-tools-section">
    <h2><?php esc_html_e('New Form Editor (Beta)', 'formflow'); ?></h2>
    <p class="description">
        <?php esc_html_e('The new task-oriented editor lives alongside the classic editor. Both read/write the same form data — toggle on to try it, toggle off for the old editor.', 'formflow'); ?>
    </p>

    <?php if ($constant_defined) : ?>
        <div class="notice notice-info inline">
            <p>
                <strong><?php esc_html_e('Constant override active.', 'formflow'); ?></strong>
                <?php
                printf(
                    /* translators: %1$s: constant name, %2$s: bool */
                    esc_html__('%1$s is set in wp-config.php to %2$s. Edit wp-config.php to change.', 'formflow'),
                    '<code>' . esc_html(FeatureFlag::CONSTANT) . '</code>',
                    '<code>' . ($current ? 'true' : 'false') . '</code>'
                );
                ?>
            </p>
        </div>
    <?php else : ?>
        <label style="display:inline-flex;align-items:center;gap:8px">
            <input type="checkbox" id="isf-new-editor-toggle" <?php checked($current); ?>>
            <span><?php esc_html_e('Use the new form editor for everyone on this site', 'formflow'); ?></span>
        </label>
        <p class="description"><?php esc_html_e('Affects all admins immediately. The old editor stays mounted as a fallback and can be reached via Advanced → Open legacy editor.', 'formflow'); ?></p>
        <script>
        jQuery(function ($) {
            $('#isf-new-editor-toggle').on('change', function () {
                var enabled = $(this).is(':checked') ? '1' : '0';
                $.post(ajaxurl, {
                    action: 'formflow_set_new_editor_flag',
                    nonce: '<?php echo esc_js(wp_create_nonce('isf_admin_nonce')); ?>',
                    value: enabled
                }, function () { window.location.reload(); });
            });
        });
        </script>
    <?php endif; ?>
</div>
