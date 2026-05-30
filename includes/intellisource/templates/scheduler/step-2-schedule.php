<?php
/**
 * Scheduler Step 2: Select Appointment
 *
 * User selects installation date and time slot.
 */

if (!defined('ABSPATH')) {
    exit;
}

$selected_date = $form_data['schedule_date'] ?? '';
$selected_time = $form_data['schedule_time'] ?? '';
$customer_name = ($form_data['first_name'] ?? '') . ' ' . ($form_data['last_name'] ?? '');
$address = $form_data['address'] ?? [];
?>

<div class="isf-step" data-step="2">
    <h2 class="isf-step-title"><?php esc_html_e('Select Your Appointment', 'formflow'); ?></h2>

    <?php if (!empty($customer_name) || !empty($address)) : ?>
    <div class="isf-customer-info-box">
        <?php if (!empty(trim($customer_name))) : ?>
            <p><strong><?php echo esc_html(trim($customer_name)); ?></strong></p>
        <?php endif; ?>
        <?php if (!empty($address['street'])) : ?>
            <p><?php echo esc_html($address['street']); ?></p>
            <p><?php echo esc_html(($address['city'] ?? '') . ', ' . ($address['state'] ?? '') . ' ' . ($address['zip'] ?? '')); ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <form class="isf-step-form" id="isf-scheduler-step-2-form">
        <div class="isf-schedule-container">
            <!-- Calendar Section -->
            <div class="isf-calendar-section">
                <div class="isf-calendar-header">
                    <button type="button" class="isf-calendar-nav isf-calendar-prev" aria-label="<?php esc_attr_e('Previous week', 'formflow'); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                            <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <span class="isf-calendar-month" id="isf-calendar-month"></span>
                    <button type="button" class="isf-calendar-nav isf-calendar-next" aria-label="<?php esc_attr_e('Next week', 'formflow'); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                            <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </div>

                <div class="isf-calendar-grid" id="isf-calendar-grid">
                    <div class="isf-calendar-loading">
                        <svg class="isf-spinner" viewBox="0 0 24 24" width="32" height="32">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.4 31.4" />
                        </svg>
                        <span><?php esc_html_e('Loading available dates...', 'formflow'); ?></span>
                    </div>
                </div>

                <div class="isf-calendar-legend">
                    <span class="isf-legend-item">
                        <span class="isf-legend-dot isf-legend-available"></span>
                        <?php esc_html_e('Available', 'formflow'); ?>
                    </span>
                    <span class="isf-legend-item">
                        <span class="isf-legend-dot isf-legend-selected"></span>
                        <?php esc_html_e('Selected', 'formflow'); ?>
                    </span>
                </div>
            </div>

            <!-- Time Slots Section -->
            <div class="isf-timeslots-section">
                <h3 class="isf-timeslots-title"><?php esc_html_e('Available Time Slots', 'formflow'); ?></h3>
                <p class="isf-timeslots-instruction" id="isf-timeslots-instruction">
                    <?php esc_html_e('Please select a date to see available time slots.', 'formflow'); ?>
                </p>

                <div class="isf-timeslots-grid" id="isf-timeslots-grid" style="display:none;"></div>

                <div class="isf-timeslots-loading" id="isf-timeslots-loading" style="display:none;">
                    <svg class="isf-spinner" viewBox="0 0 24 24" width="24" height="24">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.4 31.4" />
                    </svg>
                    <span><?php esc_html_e('Loading time slots...', 'formflow'); ?></span>
                </div>

                <div class="isf-timeslots-empty" id="isf-timeslots-empty" style="display:none;">
                    <?php esc_html_e('No time slots available for this date.', 'formflow'); ?>
                </div>
            </div>
        </div>

        <!-- Selected Appointment Summary -->
        <div class="isf-appointment-summary" id="isf-appointment-summary" style="display:none;">
            <h3 class="isf-summary-title"><?php esc_html_e('Your Selected Appointment', 'formflow'); ?></h3>
            <div class="isf-summary-details">
                <div class="isf-summary-item">
                    <span class="isf-summary-label"><?php esc_html_e('Date:', 'formflow'); ?></span>
                    <span class="isf-summary-value" id="isf-summary-date"></span>
                </div>
                <div class="isf-summary-item">
                    <span class="isf-summary-label"><?php esc_html_e('Time:', 'formflow'); ?></span>
                    <span class="isf-summary-value" id="isf-summary-time"></span>
                </div>
            </div>
        </div>

        <input type="hidden" name="schedule_date" id="schedule_date" value="<?php echo esc_attr($selected_date); ?>">
        <input type="hidden" name="schedule_time" id="schedule_time" value="<?php echo esc_attr($selected_time); ?>">
        <input type="hidden" name="schedule_fsr" id="schedule_fsr" value="">

        <div class="isf-step-actions">
            <button type="button" class="isf-btn isf-btn-secondary isf-btn-prev">
                <span class="isf-btn-arrow">&larr;</span>
                <?php esc_html_e('Back', 'formflow'); ?>
            </button>
            <button type="submit" class="isf-btn isf-btn-primary isf-btn-next" disabled>
                <span class="isf-btn-text"><?php esc_html_e('Confirm Appointment', 'formflow'); ?></span>
                <span class="isf-btn-loading" style="display:none;">
                    <svg class="isf-spinner" viewBox="0 0 24 24" width="20" height="20">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.4 31.4" />
                    </svg>
                    <?php esc_html_e('Scheduling...', 'formflow'); ?>
                </span>
            </button>
        </div>
    </form>
</div>
