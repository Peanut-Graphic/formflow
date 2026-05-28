<?php
/**
 * Reusable sub-rail (left list in two-pane layouts).
 * Expects $rail_items array of [['label'=>'…','href'=>'…','status'=>'ok|attention|defaults','active'=>true|false,'sublabel'=>'…']],
 * plus optional $rail_footer string of HTML.
 */
if (!defined('ABSPATH')) { exit; }
?>
<aside class="isf-fe-sub-rail">
    <?php foreach (($rail_items ?? []) as $item) :
        $cls = 'isf-fe-rail-item';
        if (!empty($item['active'])) $cls .= ' is-active';
        if (!empty($item['status'])) $cls .= ' isf-fe-status-' . sanitize_html_class($item['status']);
    ?>
        <a class="<?php echo esc_attr($cls); ?>" href="<?php echo esc_url($item['href'] ?? '#'); ?>">
            <span class="isf-fe-rail-label"><?php echo esc_html($item['label'] ?? ''); ?></span>
            <?php if (!empty($item['sublabel'])) : ?>
                <span class="isf-fe-rail-sublabel" style="display:block;font-size:11px;color:#646970"><?php echo esc_html($item['sublabel']); ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
    <?php if (!empty($rail_footer)) : ?>
        <div class="isf-fe-rail-footer" style="padding:12px 14px;border-top:1px solid #f0f0f1;font-size:11px;color:#646970">
            <?php echo $rail_footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — caller-built ?>
        </div>
    <?php endif; ?>
</aside>
