<?php
/**
 * Sticky action bar — appears at the bottom of any task's detail pane.
 *
 * Expects optional $extra_buttons array of <button> HTML in scope.
 */
if (!defined('ABSPATH')) { exit; }
?>
<div class="isf-fe-action-bar">
    <span class="isf-fe-save-status"><?php esc_html_e('Unsaved changes', 'formflow'); ?></span>
    <div class="isf-fe-action-bar-buttons">
        <?php if (!empty($extra_buttons) && is_array($extra_buttons)) : ?>
            <?php foreach ($extra_buttons as $btn_html) : ?>
                <?php echo $btn_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — caller-built ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <button type="button" class="button button-primary isf-fe-save">
            <?php esc_html_e('Save', 'formflow'); ?>
        </button>
    </div>
</div>
