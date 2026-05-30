<?php
/**
 * Enrollment Step 5: Review and Confirm
 *
 * Final review of all information before submission.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Import content helper function
use function ISF\Frontend\isf_get_content;
use function ISF\Frontend\isf_get_support_phone;

$device_type = $form_data['device_type'] ?? 'thermostat';
$device_name = $device_type === 'thermostat'
    ? __('Web-Programmable Thermostat', 'formflow')
    : __('Outdoor Switch', 'formflow');

// Get customizable content
$step_title = isf_get_content($instance, 'step5_title', __('Review & Confirm', 'formflow'));
$program_name = isf_get_content($instance, 'program_name', __('Energy Wise Rewards', 'formflow'));
$btn_back = isf_get_content($instance, 'btn_back', __('Back', 'formflow'));
$btn_submit = isf_get_content($instance, 'btn_submit', __('Complete Enrollment', 'formflow'));

// Get customizable Terms & Conditions
$terms_title = isf_get_content($instance, 'terms_title', __('Terms and Conditions', 'formflow'));
$terms_intro = isf_get_content($instance, 'terms_intro', sprintf(
    __('By enrolling in the %s program, you agree to the following terms:', 'formflow'),
    $program_name
));
$terms_content = $instance['settings']['content']['terms_content'] ?? '';
$terms_footer = isf_get_content($instance, 'terms_footer', __('For complete program details, please visit our website or contact customer service.', 'formflow'));
$terms_checkbox = isf_get_content($instance, 'terms_checkbox', __('I have read and agree to the Terms and Conditions', 'formflow'));

// Default terms content if not customized
if (empty($terms_content)) {
    $terms_content = '<ol>
        <li>' . esc_html__('You authorize the installation of energy management equipment at your service address.', 'formflow') . '</li>
        <li>' . esc_html__('During peak demand periods, your equipment may be cycled to help reduce strain on the power grid.', 'formflow') . '</li>
        <li>' . esc_html__('The cycling events are designed to have minimal impact on your comfort.', 'formflow') . '</li>
        <li>' . esc_html__('You may withdraw from the program at any time by contacting customer service.', 'formflow') . '</li>
        <li>' . esc_html__('The equipment remains the property of the utility company.', 'formflow') . '</li>
        <li>' . esc_html__('Installation and equipment are provided at no cost to you.', 'formflow') . '</li>
        <li>' . esc_html__('You must have a functioning central air conditioner or heat pump to participate.', 'formflow') . '</li>
    </ol>';
}
?>

<div class="isf-step" data-step="5">
    <h2 class="isf-step-title"><?php echo esc_html($step_title); ?></h2>
    <p class="isf-step-description">
        <?php esc_html_e('Please review your information below and confirm your enrollment.', 'formflow'); ?>
    </p>

    <form class="isf-step-form" id="isf-step-5-form">
        <!-- Device Selection Summary -->
        <div class="isf-review-section">
            <div class="isf-review-header">
                <h3><?php esc_html_e('Selected Device', 'formflow'); ?></h3>
                <button type="button" class="isf-edit-link" data-goto-step="1">
                    <?php esc_html_e('Edit', 'formflow'); ?>
                </button>
            </div>
            <div class="isf-review-content">
                <div class="isf-device-summary">
                    <div class="isf-device-icon-small">
                        <?php if ($device_type === 'thermostat') : ?>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32">
                                <rect x="4" y="2" width="16" height="20" rx="2"/>
                                <circle cx="12" cy="11" r="4"/>
                                <path d="M12 7v1M12 15v1M8 11h1M15 11h1"/>
                            </svg>
                        <?php else : ?>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32">
                                <rect x="3" y="3" width="18" height="18" rx="2"/>
                                <circle cx="12" cy="12" r="3"/>
                                <path d="M12 3v3M12 18v3M3 12h3M18 12h3"/>
                            </svg>
                        <?php endif; ?>
                    </div>
                    <span class="isf-device-name"><?php echo esc_html($device_name); ?></span>
                </div>
            </div>
        </div>

        <!-- Account Information Summary -->
        <div class="isf-review-section">
            <div class="isf-review-header">
                <h3><?php esc_html_e('Account Information', 'formflow'); ?></h3>
                <button type="button" class="isf-edit-link" data-goto-step="2">
                    <?php esc_html_e('Edit', 'formflow'); ?>
                </button>
            </div>
            <div class="isf-review-content">
                <div class="isf-review-grid">
                    <div class="isf-review-item">
                        <span class="isf-review-label"><?php esc_html_e('Account Number', 'formflow'); ?></span>
                        <span class="isf-review-value" id="review-utility-no"><?php echo esc_html($form_data['utility_no'] ?? ''); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contact Information Summary -->
        <div class="isf-review-section">
            <div class="isf-review-header">
                <h3><?php esc_html_e('Contact Information', 'formflow'); ?></h3>
                <button type="button" class="isf-edit-link" data-goto-step="3">
                    <?php esc_html_e('Edit', 'formflow'); ?>
                </button>
            </div>
            <div class="isf-review-content">
                <div class="isf-review-grid isf-review-grid-2">
                    <div class="isf-review-item">
                        <span class="isf-review-label"><?php esc_html_e('Name', 'formflow'); ?></span>
                        <span class="isf-review-value" id="review-name">
                            <?php echo esc_html(($form_data['first_name'] ?? '') . ' ' . ($form_data['last_name'] ?? '')); ?>
                        </span>
                    </div>
                    <div class="isf-review-item">
                        <span class="isf-review-label"><?php esc_html_e('Email', 'formflow'); ?></span>
                        <span class="isf-review-value" id="review-email"><?php echo esc_html($form_data['email'] ?? ''); ?></span>
                    </div>
                    <div class="isf-review-item">
                        <span class="isf-review-label"><?php esc_html_e('Primary Phone', 'formflow'); ?></span>
                        <span class="isf-review-value" id="review-phone"><?php echo esc_html($form_data['phone'] ?? ''); ?></span>
                    </div>
                    <?php if (!empty($form_data['alt_phone'])) : ?>
                    <div class="isf-review-item">
                        <span class="isf-review-label"><?php esc_html_e('Alternate Phone', 'formflow'); ?></span>
                        <span class="isf-review-value" id="review-alt-phone"><?php echo esc_html($form_data['alt_phone']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Service Address Summary -->
        <div class="isf-review-section">
            <div class="isf-review-header">
                <h3><?php esc_html_e('Service Address', 'formflow'); ?></h3>
                <button type="button" class="isf-edit-link" data-goto-step="3">
                    <?php esc_html_e('Edit', 'formflow'); ?>
                </button>
            </div>
            <div class="isf-review-content">
                <div class="isf-review-address" id="review-address">
                    <?php
                    $street = esc_html($form_data['street'] ?? '');
                    $city = esc_html($form_data['city'] ?? '');
                    $state = esc_html($form_data['state'] ?? '');
                    $zip = esc_html($form_data['zip'] ?? $form_data['zip_confirm'] ?? '');

                    if ($street) {
                        echo $street . '<br>';
                    }
                    echo $city . ', ' . $state . ' ' . $zip;
                    ?>
                </div>
            </div>
        </div>

        <!-- Appointment Summary -->
        <div class="isf-review-section">
            <div class="isf-review-header">
                <h3><?php esc_html_e('Installation Appointment', 'formflow'); ?></h3>
                <button type="button" class="isf-edit-link" data-goto-step="4">
                    <?php esc_html_e('Edit', 'formflow'); ?>
                </button>
            </div>
            <div class="isf-review-content">
                <div class="isf-review-grid isf-review-grid-2">
                    <div class="isf-review-item">
                        <span class="isf-review-label"><?php esc_html_e('Date', 'formflow'); ?></span>
                        <span class="isf-review-value" id="review-date"><?php echo esc_html($form_data['schedule_date'] ?? ''); ?></span>
                    </div>
                    <div class="isf-review-item">
                        <span class="isf-review-label"><?php esc_html_e('Time', 'formflow'); ?></span>
                        <span class="isf-review-value" id="review-time"><?php echo esc_html($form_data['schedule_time'] ?? ''); ?></span>
                    </div>
                </div>
                <?php if (!empty($form_data['special_instructions'])) : ?>
                <div class="isf-review-item isf-review-item-full">
                    <span class="isf-review-label"><?php esc_html_e('Special Instructions', 'formflow'); ?></span>
                    <span class="isf-review-value" id="review-instructions"><?php echo esc_html($form_data['special_instructions']); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Terms and Conditions -->
        <div class="isf-terms-section">
            <div class="isf-terms-links">
                <a href="#" class="isf-link" data-popup="rules">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
                        <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/>
                    </svg>
                    <?php esc_html_e('View Program Rules', 'formflow'); ?>
                </a>
            </div>

            <div class="isf-field isf-field-required">
                <label class="isf-checkbox-label">
                    <input type="checkbox" name="agree_terms" id="agree_terms" value="yes" required>
                    <span class="isf-checkbox-text">
                        <a href="#" class="isf-terms-link" data-popup="terms"><?php echo esc_html($terms_checkbox); ?></a>
                        <?php
                        /* translators: %s is the program name */
                        printf(esc_html__(' of the %s program.', 'formflow'), esc_html($program_name));
                        ?>
                        <span class="isf-required">*</span>
                    </span>
                </label>
            </div>

            <div class="isf-field isf-field-required">
                <label class="isf-checkbox-label">
                    <input type="checkbox" name="agree_adult" id="agree_adult" value="yes" required>
                    <span class="isf-checkbox-text">
                        <?php esc_html_e('I confirm that I am at least 18 years old and am authorized to make decisions for this account.', 'formflow'); ?>
                        <span class="isf-required">*</span>
                    </span>
                </label>
            </div>

            <div class="isf-field">
                <label class="isf-checkbox-label">
                    <input type="checkbox" name="agree_contact" id="agree_contact" value="yes" checked>
                    <span class="isf-checkbox-text">
                        <?php esc_html_e('I agree to receive program updates and energy-saving tips via email.', 'formflow'); ?>
                    </span>
                </label>
            </div>
        </div>

        <div class="isf-step-actions">
            <button type="button" class="isf-btn isf-btn-secondary isf-btn-prev">
                <span class="isf-btn-arrow">&larr;</span>
                <?php echo esc_html($btn_back); ?>
            </button>
            <button type="submit" class="isf-btn isf-btn-primary isf-btn-submit">
                <span class="isf-btn-text"><?php echo esc_html($btn_submit); ?></span>
                <span class="isf-btn-loading" style="display:none;">
                    <svg class="isf-spinner" viewBox="0 0 24 24" width="20" height="20">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.4 31.4" />
                    </svg>
                    <?php esc_html_e('Processing...', 'formflow'); ?>
                </span>
            </button>
        </div>
    </form>
</div>

<!-- Terms Popup -->
<div class="isf-popup" id="isf-popup-terms" style="display:none;">
    <div class="isf-popup-content isf-popup-large">
        <button type="button" class="isf-popup-close">&times;</button>
        <h3><?php echo esc_html($terms_title); ?></h3>
        <div class="isf-terms-content">
            <p><strong><?php echo esc_html($program_name); ?> <?php esc_html_e('Program Terms', 'formflow'); ?></strong></p>
            <p><?php echo esc_html($terms_intro); ?></p>
            <?php echo wp_kses_post($terms_content); ?>
            <p><?php echo esc_html($terms_footer); ?></p>
        </div>
    </div>
</div>

<!-- Program Rules Popup -->
<?php
// Get customizable program rules content
$rules_title = isf_get_content($instance, 'rules_title', sprintf(__('%s Program Rules', 'formflow'), $program_name));
$rules_content = $instance['settings']['content']['rules_content'] ?? '';

// Default rules content if not customized
if (empty($rules_content)) {
    $rules_content = '
<h4>' . esc_html__('Eligibility', 'formflow') . '</h4>
<ul>
    <li>' . esc_html__('Open to residential rate classes only', 'formflow') . '</li>
    <li>' . esc_html__('Customer must have central air conditioner/heat pump', 'formflow') . '</li>
    <li>' . esc_html__('Customer must have control of thermostat. If customer is a renter, they must certify they have received approval from the landlord.', 'formflow') . '</li>
</ul>

<h4>' . esc_html__('Credits', 'formflow') . '</h4>
<ul>
    <li>' . esc_html__('Start-up Bonus will be applied to your bill after the control device is installed.', 'formflow') . '</li>
    <li>' . esc_html__('Monthly credits are applied on June, July, August, September, and October bills.', 'formflow') . '</li>
    <li>' . esc_html__('Credit will only be applied if account is marked as participating on the last day of the bill period.', 'formflow') . '</li>
</ul>

<h4>' . esc_html__('Participation Levels', 'formflow') . '</h4>
<table class="isf-rules-table">
    <thead>
        <tr>
            <th>' . esc_html__('Level', 'formflow') . '</th>
            <th>' . esc_html__('Installation Credit*', 'formflow') . '</th>
        </tr>
    </thead>
    <tbody>
        <tr><td>50%</td><td>$40</td></tr>
        <tr><td>75%</td><td>$60</td></tr>
        <tr><td>100%</td><td>$80</td></tr>
    </tbody>
</table>
<p class="isf-small">*' . esc_html__('Credits subject to change in future years', 'formflow') . '</p>

<h4>' . esc_html__('Change Participation Level', 'formflow') . '</h4>
<ul>
    <li>' . esc_html__('Customer may change participation level twice in the first year, and once each subsequent year.', 'formflow') . '</li>
    <li>' . esc_html__('If customer elects to be removed from program, the device will be disabled immediately. Note: Device will not be physically removed.', 'formflow') . '</li>
</ul>

<h4>' . esc_html__('Cycling Events', 'formflow') . '</h4>
<ul>
    <li>' . esc_html__('Events will generally occur during the summer months of June through September.', 'formflow') . '</li>
    <li>' . esc_html__('Events will most likely last 4-6 hours.', 'formflow') . '</li>
    <li>' . esc_html__('If an emergency event is called by PJM for reliability reasons, it will last until the situation is averted.', 'formflow') . '</li>
    <li>' . esc_html__('There is no limit on the number of events to be called each year, but expectations are most years will result in 5 or less events.', 'formflow') . '</li>
    <li>' . esc_html__('A customer may override two events a year; however, a customer may not override a PJM required event.', 'formflow') . '</li>
</ul>
';
}
?>
<div class="isf-popup" id="isf-popup-rules" style="display:none;">
    <div class="isf-popup-content isf-popup-large">
        <button type="button" class="isf-popup-close">&times;</button>
        <h3><?php echo esc_html($rules_title); ?></h3>
        <div class="isf-rules-content">
            <?php echo wp_kses_post($rules_content); ?>
        </div>
    </div>
</div>
