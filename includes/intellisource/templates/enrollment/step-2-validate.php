<?php
/**
 * Enrollment Step 2: Account Validation
 *
 * User enters utility account number, ZIP code, and participation level for validation.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Import content helper function
use function ISF\Frontend\isf_get_content;
use function ISF\Frontend\isf_get_support_phone;

$utility_no = $form_data['utility_no'] ?? '';
$zip = $form_data['zip'] ?? '';
$cycling_level = $form_data['cycling_level'] ?? '100';
$validation_error = $form_data['validation_error'] ?? '';

// Get utility name from instance settings
$utility_name = $instance['settings']['content']['utility_name'] ?? 'Delmarva Power';

// Get customizable content
$step_title = isf_get_content($instance, 'step2_title', __('Verify Your Account', 'formflow'));
$help_account = isf_get_content($instance, 'help_account', __('Please enter your account number without dashes or spaces.', 'formflow'));
$help_zip = isf_get_content($instance, 'help_zip', __('The ZIP code where your utility service is located.', 'formflow'));
$btn_back = isf_get_content($instance, 'btn_back', __('Back', 'formflow'));
$btn_verify = isf_get_content($instance, 'btn_verify', __('Verify Account', 'formflow'));

// Get account number help image if configured
$account_help_image = $instance['settings']['account_help_image'] ?? '';
?>

<div class="isf-step" data-step="2">
    <h2 class="isf-step-title"><?php echo esc_html($step_title); ?></h2>
    <p class="isf-step-description">
        <?php esc_html_e('Please enter your utility account information to verify your eligibility for the program.', 'formflow'); ?>
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

    <form class="isf-step-form" id="isf-step-2-form">
        <!-- Account Validation -->
        <fieldset class="isf-fieldset">
            <legend class="isf-legend"><?php esc_html_e('Account Validation', 'formflow'); ?></legend>

            <div class="isf-form-grid isf-form-grid-2">
                <div class="isf-field isf-field-required">
                    <label for="utility_no" class="isf-label">
                        <?php printf(esc_html__('%s Account Number', 'formflow'), esc_html($utility_name)); ?>
                        <span class="isf-required">*</span>
                        <button type="button" class="isf-help-link" data-popup="account-help">
                            <?php esc_html_e('Where is this?', 'formflow'); ?>
                        </button>
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
                        <?php echo esc_html($help_account); ?>
                    </p>
                </div>

                <div class="isf-field isf-field-required">
                    <label for="zip" class="isf-label">
                        <?php esc_html_e('Service ZIP Code', 'formflow'); ?>
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
                    <p class="isf-field-hint">
                        <?php echo esc_html($help_zip); ?>
                    </p>
                </div>
            </div>
        </fieldset>

        <!-- Participation Level -->
        <fieldset class="isf-fieldset">
            <legend class="isf-legend">
                <?php esc_html_e('Participation Level', 'formflow'); ?>
                <button type="button" class="isf-help-link" data-popup="cycling-help">
                    <?php esc_html_e('What is cycling?', 'formflow'); ?>
                </button>
            </legend>

            <div class="isf-cycling-options">
                <label class="isf-radio-option <?php echo $cycling_level === '50' ? 'selected' : ''; ?>">
                    <input type="radio"
                           name="cycling_level"
                           value="50"
                           <?php checked($cycling_level, '50'); ?>>
                    <span class="isf-radio-label">
                        <strong>50% Cycling</strong>
                        <span class="isf-radio-desc"><?php esc_html_e('Your AC cycles off for up to 7.5 minutes each half hour', 'formflow'); ?></span>
                    </span>
                </label>

                <label class="isf-radio-option <?php echo $cycling_level === '75' ? 'selected' : ''; ?>">
                    <input type="radio"
                           name="cycling_level"
                           value="75"
                           <?php checked($cycling_level, '75'); ?>>
                    <span class="isf-radio-label">
                        <strong>75% Cycling</strong>
                        <span class="isf-radio-desc"><?php esc_html_e('Your AC cycles off for up to 11.25 minutes each half hour', 'formflow'); ?></span>
                    </span>
                </label>

                <label class="isf-radio-option <?php echo ($cycling_level === '100' || empty($cycling_level)) ? 'selected' : ''; ?>">
                    <input type="radio"
                           name="cycling_level"
                           value="100"
                           <?php checked($cycling_level, '100'); ?>
                           <?php if (empty($cycling_level)) echo 'checked'; ?>>
                    <span class="isf-radio-label">
                        <strong>100% Cycling</strong>
                        <span class="isf-radio-desc"><?php esc_html_e('Your AC cycles off for up to 15 minutes each half hour (Maximum savings)', 'formflow'); ?></span>
                    </span>
                </label>
            </div>
        </fieldset>

        <div class="isf-step-actions">
            <button type="button" class="isf-btn isf-btn-secondary isf-btn-prev">
                <span class="isf-btn-arrow">&larr;</span>
                <?php echo esc_html($btn_back); ?>
            </button>
            <button type="submit" class="isf-btn isf-btn-primary isf-btn-next">
                <span class="isf-btn-text"><?php echo esc_html($btn_verify); ?></span>
                <span class="isf-btn-loading" style="display:none;">
                    <svg class="isf-spinner" viewBox="0 0 24 24" width="20" height="20">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.4 31.4" />
                    </svg>
                    <?php esc_html_e('Verifying...', 'formflow'); ?>
                </span>
            </button>
        </div>
    </form>
</div>

<!-- Account Number Help Popup -->
<div id="isf-popup-account-help" class="isf-popup" style="display:none;">
    <div class="isf-popup-content isf-popup-lg">
        <button type="button" class="isf-popup-close" aria-label="<?php esc_attr_e('Close', 'formflow'); ?>">&times;</button>
        <h3><?php esc_html_e('Where to Find Your Account Number', 'formflow'); ?></h3>
        <div class="isf-popup-body">
            <p><?php printf(esc_html__('Your %s account number can be found at the top of your monthly utility bill.', 'formflow'), esc_html($utility_name)); ?></p>
            <?php if (!empty($account_help_image)) : ?>
                <img src="<?php echo esc_url($account_help_image); ?>" alt="<?php esc_attr_e('Account number location on bill', 'formflow'); ?>" class="isf-help-image">
            <?php else : ?>
                <div class="isf-help-bill-diagram">
                    <div class="isf-bill-mock">
                        <div class="isf-bill-header"><?php echo esc_html($utility_name); ?></div>
                        <div class="isf-bill-row isf-bill-highlight">
                            <span><?php esc_html_e('Account Number:', 'formflow'); ?></span>
                            <span class="isf-bill-value">1234567890</span>
                            <span class="isf-bill-arrow">&larr; <?php esc_html_e('Here', 'formflow'); ?></span>
                        </div>
                        <div class="isf-bill-row">
                            <span><?php esc_html_e('Service Address:', 'formflow'); ?></span>
                            <span>123 Main St</span>
                        </div>
                        <div class="isf-bill-row">
                            <span><?php esc_html_e('Amount Due:', 'formflow'); ?></span>
                            <span>$XXX.XX</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <p class="isf-help-note">
                <?php esc_html_e('Enter your account number without dashes or spaces.', 'formflow'); ?>
            </p>
            <p class="isf-help-note">
                <?php esc_html_e('If you have multiple accounts, use the account number for the service address where you want the device installed.', 'formflow'); ?>
            </p>
        </div>
    </div>
</div>

<!-- Cycling Help Popup -->
<div id="isf-popup-cycling-help" class="isf-popup" style="display:none;">
    <div class="isf-popup-content">
        <button type="button" class="isf-popup-close" aria-label="<?php esc_attr_e('Close', 'formflow'); ?>">&times;</button>
        <h3><?php esc_html_e('What is Cycling?', 'formflow'); ?></h3>
        <div class="isf-popup-body">
            <p><?php esc_html_e('Cycling refers to how your air conditioning compressor is managed during peak energy demand periods (typically hot summer afternoons).', 'formflow'); ?></p>
            <p><?php esc_html_e('During a cycling event:', 'formflow'); ?></p>
            <ul>
                <li><strong>50% Cycling:</strong> <?php esc_html_e('Your compressor cycles off for up to 7.5 minutes every half hour', 'formflow'); ?></li>
                <li><strong>75% Cycling:</strong> <?php esc_html_e('Your compressor cycles off for up to 11.25 minutes every half hour', 'formflow'); ?></li>
                <li><strong>100% Cycling:</strong> <?php esc_html_e('Your compressor cycles off for up to 15 minutes every half hour', 'formflow'); ?></li>
            </ul>
            <p><?php esc_html_e('Your fan continues to run during cycling, keeping air circulating. Most participants notice little to no change in comfort level.', 'formflow'); ?></p>
            <p><strong><?php esc_html_e('Higher cycling levels = greater energy savings for everyone!', 'formflow'); ?></strong></p>
        </div>
    </div>
</div>
