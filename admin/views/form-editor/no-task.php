<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="isf-fe-no-task">
    <h2><?php esc_html_e('No form selected', 'formflow'); ?></h2>
    <p>
        <?php esc_html_e('Choose a form from the dashboard, or check the URL.', 'formflow'); ?>
    </p>
    <p>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=isf-dashboard')); ?>">
            <?php esc_html_e('Back to dashboard', 'formflow'); ?>
        </a>
    </p>
</div>
