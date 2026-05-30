<?php
/**
 * Progress Bar Partial
 *
 * Displays the multi-step form progress indicator.
 */

if (!defined('ABSPATH')) {
    exit;
}

$steps = [
    1 => __('Program', 'formflow'),
    2 => __('Verify', 'formflow'),
    3 => __('Info', 'formflow'),
    4 => __('Schedule', 'formflow'),
    5 => __('Confirm', 'formflow')
];
?>

<div class="isf-progress-container">
    <div class="isf-progress-bar">
        <div class="isf-progress-fill" style="width: 20%;"></div>
    </div>
    <div class="isf-progress-steps">
        <?php foreach ($steps as $num => $label) : ?>
            <div class="isf-progress-step <?php echo $num === 1 ? 'active' : ''; ?>" data-step="<?php echo esc_attr($num); ?>">
                <div class="isf-step-number"><?php echo esc_html($num); ?></div>
                <div class="isf-step-label"><?php echo esc_html($label); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="isf-progress-actions">
        <button type="button" class="isf-save-later-btn">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
                <path d="M7.707 10.293a1 1 0 10-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 11.586V6h5a2 2 0 012 2v7a2 2 0 01-2 2H4a2 2 0 01-2-2V8a2 2 0 012-2h5v5.586l-1.293-1.293zM9 4a1 1 0 012 0v2H9V4z"/>
            </svg>
            <?php esc_html_e('Save & Continue Later', 'formflow'); ?>
        </button>
    </div>
</div>
