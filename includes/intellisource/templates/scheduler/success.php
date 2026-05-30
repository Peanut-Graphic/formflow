<?php
/**
 * Scheduler Success Page
 *
 * Displayed after successful appointment scheduling.
 */

if (!defined('ABSPATH')) {
    exit;
}

$confirmation_number = $form_data['confirmation_number'] ?? '';
$customer_name = ($form_data['first_name'] ?? '') . ' ' . ($form_data['last_name'] ?? '');
$address = $form_data['address'] ?? [];
?>

<div class="isf-step isf-step-success" data-step="success">
    <div class="isf-success-icon">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="64" height="64">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
        </svg>
    </div>

    <h2 class="isf-success-title"><?php esc_html_e('Appointment Scheduled!', 'formflow'); ?></h2>
    <p class="isf-success-message">
        <?php esc_html_e('Your installation appointment has been confirmed.', 'formflow'); ?>
    </p>

    <?php if (!empty($confirmation_number)) : ?>
    <div class="isf-confirmation-box">
        <span class="isf-confirmation-label"><?php esc_html_e('Confirmation Number', 'formflow'); ?></span>
        <span class="isf-confirmation-number"><?php echo esc_html($confirmation_number); ?></span>
        <p class="isf-confirmation-hint"><?php esc_html_e('Please save this number for your records.', 'formflow'); ?></p>
    </div>
    <?php endif; ?>

    <div class="isf-success-details">
        <h3><?php esc_html_e('Appointment Details', 'formflow'); ?></h3>
        <div class="isf-success-grid">
            <div class="isf-success-item">
                <span class="isf-success-label"><?php esc_html_e('Date', 'formflow'); ?></span>
                <span class="isf-success-value" id="success-date"><?php echo esc_html($form_data['schedule_date'] ?? ''); ?></span>
            </div>
            <div class="isf-success-item">
                <span class="isf-success-label"><?php esc_html_e('Time', 'formflow'); ?></span>
                <span class="isf-success-value" id="success-time"><?php echo esc_html($form_data['schedule_time'] ?? ''); ?></span>
            </div>
            <?php if (!empty(trim($customer_name))) : ?>
            <div class="isf-success-item">
                <span class="isf-success-label"><?php esc_html_e('Name', 'formflow'); ?></span>
                <span class="isf-success-value"><?php echo esc_html(trim($customer_name)); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($address['street'])) : ?>
            <div class="isf-success-item">
                <span class="isf-success-label"><?php esc_html_e('Address', 'formflow'); ?></span>
                <span class="isf-success-value">
                    <?php echo esc_html($address['street']); ?><br>
                    <?php echo esc_html(($address['city'] ?? '') . ', ' . ($address['state'] ?? '') . ' ' . ($address['zip'] ?? '')); ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="isf-success-next">
        <h3><?php esc_html_e('What Happens Next?', 'formflow'); ?></h3>
        <ul class="isf-next-steps-list">
            <li><?php esc_html_e('You may receive a confirmation call 1-2 days before your appointment.', 'formflow'); ?></li>
            <li><?php esc_html_e('A certified technician will arrive during your scheduled time window.', 'formflow'); ?></li>
            <li><?php esc_html_e('Please ensure an adult (18+) is present for the installation.', 'formflow'); ?></li>
        </ul>
    </div>

    <div class="isf-success-contact">
        <p><?php esc_html_e('Need to reschedule or have questions?', 'formflow'); ?></p>
        <p>
            <?php
            printf(
                esc_html__('Contact us at %s', 'formflow'),
                '<a href="tel:1-888-818-0075">1-888-818-0075</a>'
            );
            ?>
        </p>
    </div>

    <div class="isf-success-actions">
        <button type="button" class="isf-btn isf-btn-secondary" onclick="window.print();">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
                <path fill-rule="evenodd" d="M5 4v3H4a2 2 0 00-2 2v3a2 2 0 002 2h1v2a2 2 0 002 2h6a2 2 0 002-2v-2h1a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm8 0H7v3h6V4zm0 8H7v4h6v-4z" clip-rule="evenodd" />
            </svg>
            <?php esc_html_e('Print Confirmation', 'formflow'); ?>
        </button>
    </div>
</div>
