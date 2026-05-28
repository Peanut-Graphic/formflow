<?php
/**
 * Submissions task — client-mode read-only summary.
 * Expects $instance, $instance_id in scope.
 */
if (!defined('ABSPATH')) { exit; }

global $wpdb;
$submissions_table = $wpdb->prefix . 'isf_submissions';
$deliveries_table  = $wpdb->prefix . 'isf_deliveries';

$total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$submissions_table} WHERE instance_id = %d", $instance_id));
$failed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$deliveries_table} WHERE instance_id = %d AND status = 'failed'", $instance_id));
$recent = $wpdb->get_results($wpdb->prepare(
    "SELECT id, created_at, status FROM {$submissions_table} WHERE instance_id = %d ORDER BY id DESC LIMIT 10",
    $instance_id
));
?>
<div class="isf-fe-task-panel" data-task="submissions">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Submissions', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('Recent submissions and delivery status. Read-only — to manage submissions, ask your administrator.', 'formflow'); ?></p>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:20px 0">
            <div class="isf-fe-task-card">
                <div class="isf-fe-task-card-head"><h3><?php echo esc_html(number_format($total)); ?></h3></div>
                <p class="isf-fe-task-card-desc"><?php esc_html_e('Total submissions', 'formflow'); ?></p>
            </div>
            <div class="isf-fe-task-card <?php echo $failed > 0 ? 'isf-fe-status-attention' : ''; ?>">
                <div class="isf-fe-task-card-head"><h3><?php echo esc_html(number_format($failed)); ?></h3></div>
                <p class="isf-fe-task-card-desc"><?php esc_html_e('Failed deliveries', 'formflow'); ?></p>
            </div>
        </div>

        <h3><?php esc_html_e('Recent submissions', 'formflow'); ?></h3>
        <?php if (!$recent) : ?>
            <p><em><?php esc_html_e('No submissions yet.', 'formflow'); ?></em></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th><?php esc_html_e('Submitted', 'formflow'); ?></th><th><?php esc_html_e('Status', 'formflow'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($recent as $row) : ?>
                        <tr>
                            <td><code><?php echo esc_html($row->id); ?></code></td>
                            <td><?php echo esc_html($row->created_at); ?></td>
                            <td><?php echo esc_html($row->status ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
