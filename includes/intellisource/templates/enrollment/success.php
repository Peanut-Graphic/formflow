<?php
/**
 * Enrollment Success Page
 *
 * Displayed after successful enrollment completion.
 */

if (!defined('ABSPATH')) {
    exit;
}

$confirmation_number = $form_data['confirmation_number'] ?? '';
$device_type = $form_data['device_type'] ?? 'thermostat';
$device_name = $device_type === 'thermostat'
    ? __('Web-Programmable Thermostat', 'formflow')
    : __('Outdoor Switch', 'formflow');
?>

<div class="isf-step isf-step-success" data-step="success">
    <div class="isf-success-icon">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="64" height="64">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
        </svg>
    </div>

    <h2 class="isf-success-title"><?php esc_html_e('Enrollment Complete!', 'formflow'); ?></h2>
    <p class="isf-success-message">
        <?php esc_html_e('Thank you for enrolling in the Energy Wise Rewards program. Your installation has been scheduled.', 'formflow'); ?>
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
                <span class="isf-success-label"><?php esc_html_e('Device', 'formflow'); ?></span>
                <span class="isf-success-value"><?php echo esc_html($device_name); ?></span>
            </div>
            <div class="isf-success-item">
                <span class="isf-success-label"><?php esc_html_e('Date', 'formflow'); ?></span>
                <span class="isf-success-value" id="success-date"><?php echo esc_html($form_data['schedule_date'] ?? ''); ?></span>
            </div>
            <div class="isf-success-item">
                <span class="isf-success-label"><?php esc_html_e('Time', 'formflow'); ?></span>
                <span class="isf-success-value" id="success-time"><?php echo esc_html($form_data['schedule_time'] ?? ''); ?></span>
            </div>
            <div class="isf-success-item">
                <span class="isf-success-label"><?php esc_html_e('Address', 'formflow'); ?></span>
                <span class="isf-success-value">
                    <?php echo esc_html($form_data['street'] ?? ''); ?><br>
                    <?php echo esc_html(($form_data['city'] ?? '') . ', ' . ($form_data['state'] ?? '') . ' ' . ($form_data['zip'] ?? $form_data['zip_confirm'] ?? '')); ?>
                </span>
            </div>
        </div>
    </div>

    <div class="isf-success-email">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
            <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
            <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
        </svg>
        <p><?php esc_html_e('A confirmation email has been sent to:', 'formflow'); ?></p>
        <strong id="success-email"><?php echo esc_html($form_data['email'] ?? ''); ?></strong>
    </div>

    <div class="isf-success-next">
        <h3><?php esc_html_e('What Happens Next?', 'formflow'); ?></h3>
        <ol class="isf-next-steps">
            <li>
                <strong><?php esc_html_e('Confirmation Call', 'formflow'); ?></strong>
                <p><?php esc_html_e('You may receive a call to confirm your appointment 1-2 days before the scheduled date.', 'formflow'); ?></p>
            </li>
            <li>
                <strong><?php esc_html_e('Installation Day', 'formflow'); ?></strong>
                <p><?php esc_html_e('Our certified technician will arrive during your scheduled time window. Please ensure an adult (18+) is present.', 'formflow'); ?></p>
            </li>
            <?php if ($device_type === 'thermostat') : ?>
            <li>
                <strong><?php esc_html_e('Setup & Training', 'formflow'); ?></strong>
                <p><?php esc_html_e('The technician will install your thermostat and show you how to use it, including the web and mobile app features.', 'formflow'); ?></p>
            </li>
            <?php else : ?>
            <li>
                <strong><?php esc_html_e('Quick Installation', 'formflow'); ?></strong>
                <p><?php esc_html_e('The outdoor switch installation is quick and non-invasive. The technician will verify everything is working properly.', 'formflow'); ?></p>
            </li>
            <?php endif; ?>
            <li>
                <strong><?php esc_html_e('Start Saving', 'formflow'); ?></strong>
                <p><?php esc_html_e('Once installed, you\'ll automatically start participating in the Energy Wise Rewards program and earning rewards!', 'formflow'); ?></p>
            </li>
        </ol>
    </div>

    <div class="isf-success-contact">
        <p><?php esc_html_e('Questions about your enrollment or need to reschedule?', 'formflow'); ?></p>
        <p>
            <?php
            printf(
                esc_html__('Contact us at %s or call %s', 'formflow'),
                '<a href="mailto:support@energywiserewards.com">support@energywiserewards.com</a>',
                '<a href="tel:1-800-XXX-XXXX">1-800-XXX-XXXX</a>'
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
