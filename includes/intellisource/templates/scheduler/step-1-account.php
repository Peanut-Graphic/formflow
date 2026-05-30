<?php
/**
 * Scheduler Step 1: Account Verification
 *
 * User enters utility account number and ZIP code.
 * If already scheduled, shows current appointment details.
 */

if (!defined('ABSPATH')) {
    exit;
}

$utility_no = $form_data['utility_no'] ?? '';
$zip = $form_data['zip'] ?? '';
$validation_error = $form_data['validation_error'] ?? '';

// Check if user already has an existing appointment
$existing_appointment = $form_data['existing_appointment'] ?? null;
$has_existing = !empty($existing_appointment) && !empty($existing_appointment['scheduled_date']);
?>

<div class="isf-step" data-step="1">
    <h2 class="isf-step-title"><?php esc_html_e('Schedule or Reschedule an Appointment', 'formflow'); ?></h2>

    <?php if ($has_existing) : ?>
    <!-- Existing Appointment Display -->
    <div class="isf-existing-appointment">
        <div class="isf-notice isf-notice-info">
            <div class="isf-notice-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="isf-notice-content">
                <?php esc_html_e('Our records indicate you already have a scheduled appointment.', 'formflow'); ?>
            </div>
        </div>

        <div class="isf-appointment-details">
            <h3 class="isf-details-title"><?php esc_html_e('Current Appointment Details', 'formflow'); ?></h3>

            <div class="isf-details-grid">
                <?php if (!empty($existing_appointment['customer_name'])) : ?>
                <div class="isf-detail-item">
                    <span class="isf-detail-label"><?php esc_html_e('Name', 'formflow'); ?></span>
                    <span class="isf-detail-value"><?php echo esc_html($existing_appointment['customer_name']); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($existing_appointment['address'])) : ?>
                <div class="isf-detail-item">
                    <span class="isf-detail-label"><?php esc_html_e('Address', 'formflow'); ?></span>
                    <span class="isf-detail-value"><?php echo esc_html($existing_appointment['address']); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($existing_appointment['equipment_count'])) : ?>
                <div class="isf-detail-item">
                    <span class="isf-detail-label">
                        <?php
                        $is_dcu = !empty($existing_appointment['is_dcu']);
                        echo $is_dcu
                            ? esc_html__('Units to Install', 'formflow')
                            : esc_html__('Thermostats to Install', 'formflow');
                        ?>
                    </span>
                    <span class="isf-detail-value"><?php echo esc_html($existing_appointment['equipment_count']); ?></span>
                </div>
                <?php endif; ?>

                <div class="isf-detail-item isf-detail-highlight">
                    <span class="isf-detail-label"><?php esc_html_e('Scheduled Date', 'formflow'); ?></span>
                    <span class="isf-detail-value"><?php echo esc_html($existing_appointment['scheduled_date']); ?></span>
                </div>

                <?php if (!empty($existing_appointment['scheduled_time'])) : ?>
                <div class="isf-detail-item isf-detail-highlight">
                    <span class="isf-detail-label"><?php esc_html_e('Scheduled Time', 'formflow'); ?></span>
                    <span class="isf-detail-value"><?php echo esc_html($existing_appointment['scheduled_time']); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="isf-reschedule-actions">
                <p><?php esc_html_e('If you would like to change your appointment, click the button below to reschedule.', 'formflow'); ?></p>
                <button type="button" class="isf-btn isf-btn-primary isf-btn-reschedule" id="isf-btn-reschedule">
                    <?php esc_html_e('Reschedule Appointment', 'formflow'); ?>
                </button>
            </div>
        </div>
    </div>

    <?php else : ?>
    <!-- Standard Account Verification Form -->
    <p class="isf-step-description">
        <?php esc_html_e('Scheduling an appointment is quick and easy. To begin, please enter your Account Number below.', 'formflow'); ?>
    </p>

    <?php if (!empty($validation_error)) : ?>
        <div class="isf-alert isf-alert-error">
            <span class="isf-alert-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>
            </span>
            <span class="isf-alert-message"><?php echo esc_html($validation_error); ?></span>
            <button type="button" class="isf-alert-close" aria-label="<?php esc_attr_e('Dismiss', 'formflow'); ?>">&times;</button>
        </div>
    <?php endif; ?>

    <form class="isf-step-form" id="isf-scheduler-step-1-form">
        <div class="isf-form-grid">
            <div class="isf-field isf-field-required">
                <label for="utility_no" class="isf-label">
                    <?php esc_html_e('Account Number', 'formflow'); ?>
                    <span class="isf-required">*</span>
                </label>
                <input type="text"
                       name="utility_no"
                       id="utility_no"
                       class="isf-input"
                       value="<?php echo esc_attr($utility_no); ?>"
                       placeholder="<?php esc_attr_e('Enter your account number', 'formflow'); ?>"
                       required
                       autocomplete="off">
                <p class="isf-field-hint">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16" style="vertical-align: middle;">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                    </svg>
                    <?php esc_html_e('Your account number can be found on your utility bill.', 'formflow'); ?>
                </p>
            </div>

            <div class="isf-field isf-field-required">
                <label for="zip" class="isf-label">
                    <?php esc_html_e('Zip Code', 'formflow'); ?>
                    <span class="isf-required">*</span>
                </label>
                <input type="text"
                       name="zip"
                       id="zip"
                       class="isf-input"
                       value="<?php echo esc_attr($zip); ?>"
                       placeholder="<?php esc_attr_e('Enter 5-digit ZIP code', 'formflow'); ?>"
                       pattern="[0-9]{5}"
                       maxlength="5"
                       required
                       autocomplete="postal-code">
            </div>
        </div>

        <div class="isf-step-actions">
            <button type="submit" class="isf-btn isf-btn-primary isf-btn-next">
                <span class="isf-btn-text"><?php esc_html_e('Submit', 'formflow'); ?></span>
                <span class="isf-btn-loading" style="display:none;">
                    <svg class="isf-spinner" viewBox="0 0 24 24" width="20" height="20">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.4 31.4" />
                    </svg>
                    <?php esc_html_e('Verifying...', 'formflow'); ?>
                </span>
            </button>
        </div>
    </form>
    <?php endif; // End has_existing check ?>
</div>
