<?php
/**
 * Enrollment Step 4: Schedule Installation
 *
 * User selects installation date and time slot.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Import content helper function
use function ISF\Frontend\isf_get_content;

$selected_date = $form_data['schedule_date'] ?? '';
$selected_time = $form_data['schedule_time'] ?? '';
$device_type = $form_data['device_type'] ?? 'thermostat';

// Get customizable content
$step_title = isf_get_content($instance, 'step4_title', __('Schedule Your Installation', 'formflow'));
$help_scheduling = isf_get_content($instance, 'help_scheduling', __('Select a convenient date and time for your free installation appointment, or skip to schedule later.', 'formflow'));
$btn_back = isf_get_content($instance, 'btn_back', __('Back', 'formflow'));
?>

<div class="isf-step" data-step="4">
    <h2 class="isf-step-title"><?php echo esc_html($step_title); ?></h2>
    <p class="isf-step-description">
        <?php echo esc_html($help_scheduling); ?>
    </p>
    <p class="isf-step-description isf-schedule-optional">
        <em><?php esc_html_e('Scheduling is optional. You can skip this step and someone will contact you to schedule, or you can schedule online later.', 'formflow'); ?></em>
    </p>

    <form class="isf-step-form" id="isf-step-4-form">
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
                    <!-- Calendar days will be populated by JavaScript -->
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
                    <span class="isf-legend-item">
                        <span class="isf-legend-dot isf-legend-unavailable"></span>
                        <?php esc_html_e('Unavailable', 'formflow'); ?>
                    </span>
                </div>
            </div>

            <!-- Time Slots Section -->
            <div class="isf-timeslots-section">
                <h3 class="isf-timeslots-title"><?php esc_html_e('Available Time Slots', 'formflow'); ?></h3>
                <p class="isf-timeslots-instruction" id="isf-timeslots-instruction">
                    <?php esc_html_e('Please select a date to see available time slots.', 'formflow'); ?>
                </p>

                <div class="isf-timeslots-grid" id="isf-timeslots-grid" style="display:none;">
                    <!-- Time slots will be populated by JavaScript -->
                </div>

                <div class="isf-timeslots-loading" id="isf-timeslots-loading" style="display:none;">
                    <svg class="isf-spinner" viewBox="0 0 24 24" width="24" height="24">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.4 31.4" />
                    </svg>
                    <span><?php esc_html_e('Loading time slots...', 'formflow'); ?></span>
                </div>

                <div class="isf-timeslots-empty" id="isf-timeslots-empty" style="display:none;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="24" height="24">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd" />
                    </svg>
                    <span><?php esc_html_e('No time slots available for this date. Please select another date.', 'formflow'); ?></span>
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
                <div class="isf-summary-item">
                    <span class="isf-summary-label"><?php esc_html_e('Device:', 'formflow'); ?></span>
                    <span class="isf-summary-value" id="isf-summary-device">
                        <?php echo $device_type === 'thermostat'
                            ? esc_html__('Web-Programmable Thermostat', 'formflow')
                            : esc_html__('Outdoor Switch', 'formflow'); ?>
                    </span>
                </div>
            </div>
        </div>

        <input type="hidden" name="schedule_date" id="schedule_date" value="<?php echo esc_attr($selected_date); ?>">
        <input type="hidden" name="schedule_time" id="schedule_time" value="<?php echo esc_attr($selected_time); ?>">
        <input type="hidden" name="schedule_fsr" id="schedule_fsr" value="">

        <div class="isf-installation-info">
            <h4><?php esc_html_e('What to Expect', 'formflow'); ?></h4>
            <ul>
                <li><?php esc_html_e('Installation is completely FREE', 'formflow'); ?></li>
                <li><?php esc_html_e('A certified technician will arrive during your selected time window', 'formflow'); ?></li>
                <?php if ($device_type === 'thermostat') : ?>
                    <li><?php esc_html_e('Installation typically takes 30-45 minutes', 'formflow'); ?></li>
                    <li><?php esc_html_e('The technician will show you how to use your new thermostat', 'formflow'); ?></li>
                <?php else : ?>
                    <li><?php esc_html_e('Installation typically takes 15-30 minutes', 'formflow'); ?></li>
                    <li><?php esc_html_e('The switch is installed on your outdoor unit - no indoor access needed', 'formflow'); ?></li>
                <?php endif; ?>
                <li><?php esc_html_e('An adult (18+) must be present for the installation', 'formflow'); ?></li>
            </ul>
        </div>

        <div class="isf-step-actions">
            <button type="button" class="isf-btn isf-btn-secondary isf-btn-prev">
                <span class="isf-btn-arrow">&larr;</span>
                <?php echo esc_html($btn_back); ?>
            </button>
            <button type="submit" class="isf-btn isf-btn-primary isf-btn-next" id="isf-schedule-continue">
                <span class="isf-btn-text isf-btn-text-skip"><?php esc_html_e('Skip & Continue', 'formflow'); ?></span>
                <span class="isf-btn-text isf-btn-text-confirm" style="display:none;"><?php esc_html_e('Confirm Appointment', 'formflow'); ?></span>
                <span class="isf-btn-arrow">&rarr;</span>
            </button>
        </div>
    </form>
</div>
