<?php
/**
 * Overview — grid of task cards for an instance.
 * Expects $instance, $tasks, $mode in scope (from layout.php).
 */
if (!defined('ABSPATH')) { exit; }

use ISF\FormEditor\TaskValidator;

$form_type = $instance['form_type'] ?? '';
?>
<section class="isf-fe-overview">
    <p class="isf-fe-subtitle">
        <?php esc_html_e('Pick a task to edit. Status badges show what needs your attention.', 'formflow'); ?>
    </p>

    <div class="isf-fe-task-grid">
        <?php foreach ($tasks as $slug => $def):
            $status = TaskValidator::status_for($slug, $instance);
            include __DIR__ . '/partials/task-card.php';
        endforeach; ?>
    </div>
</section>
