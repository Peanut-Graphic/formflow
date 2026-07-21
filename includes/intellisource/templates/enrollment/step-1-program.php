<?php
/**
 * Enrollment Step 1: Program Selection
 *
 * User selects their device type (thermostat or outdoor switch).
 */

if (!defined('ABSPATH')) {
    exit;
}

// Import content helper functions
use function ISF\Frontend\isf_get_content;
use function ISF\Frontend\isf_requires_wifi;

$device_type = $form_data['device_type'] ?? '';

// A Web-Programmable Thermostat cannot be installed without home WiFi. Only
// instances that opted in ask about it; everywhere else this whole block is
// absent and the step renders exactly as it always has.
$requires_wifi = isf_requires_wifi($instance);
$has_wifi      = $form_data['has_wifi'] ?? '';

// Get customizable content
$step_title = isf_get_content($instance, 'step1_title', __('Choose Your Energy-Saving Device', 'formflow'));
$form_description = isf_get_content($instance, 'form_description', __('Select the device you would like installed to participate in the Energy Wise Rewards program.', 'formflow'));
$program_name = isf_get_content($instance, 'program_name', __('Energy Wise Rewards', 'formflow'));
$btn_next = isf_get_content($instance, 'btn_next', __('Continue', 'formflow'));
?>

<div class="isf-step" data-step="1">
    <h2 class="isf-step-title"><?php echo esc_html($step_title); ?></h2>
    <p class="isf-step-description">
        <?php echo esc_html($form_description); ?>
    </p>

    <form class="isf-step-form" id="isf-step-1-form">
        <div class="isf-field isf-field-required">
            <label class="isf-label">
                <input type="checkbox" name="has_ac" id="has_ac" value="yes" required
                       <?php checked(!empty($form_data['has_ac']), true); ?>>
                <?php esc_html_e('I have a Central Air Conditioner or Heat Pump and I am a customer of this utility.', 'formflow'); ?>
                <span class="isf-required">*</span>
            </label>
        </div>

        <div class="isf-device-options">
            <label class="isf-device-option <?php echo $device_type === 'thermostat' ? 'selected' : ''; ?>">
                <input type="radio" name="device_type" value="thermostat" required
                       <?php checked($device_type, 'thermostat'); ?>>
                <div class="isf-device-card">
                    <div class="isf-device-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="4" y="2" width="16" height="20" rx="2"/>
                            <circle cx="12" cy="11" r="4"/>
                            <path d="M12 7v1M12 15v1M8 11h1M15 11h1"/>
                        </svg>
                    </div>
                    <div class="isf-device-content">
                        <h3><?php esc_html_e('Web-Programmable Thermostat', 'formflow'); ?></h3>
                        <p><?php esc_html_e('A smart thermostat that lets you control your home temperature from anywhere, helping you save energy and money.', 'formflow'); ?></p>
                    </div>
                    <a href="#" class="isf-device-info" data-popup="thermostat">
                        <?php esc_html_e('Learn More', 'formflow'); ?>
                    </a>
                </div>
            </label>

            <label class="isf-device-option <?php echo $device_type === 'dcu' ? 'selected' : ''; ?>">
                <input type="radio" name="device_type" value="dcu" required
                       <?php checked($device_type, 'dcu'); ?>>
                <div class="isf-device-card">
                    <div class="isf-device-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2"/>
                            <circle cx="12" cy="12" r="3"/>
                            <path d="M12 3v3M12 18v3M3 12h3M18 12h3"/>
                        </svg>
                    </div>
                    <div class="isf-device-content">
                        <h3><?php esc_html_e('Outdoor Switch', 'formflow'); ?></h3>
                        <p><?php esc_html_e('A simple device installed on your outdoor AC unit that helps reduce strain on the power grid during peak demand.', 'formflow'); ?></p>
                    </div>
                    <a href="#" class="isf-device-info" data-popup="dcu">
                        <?php esc_html_e('Learn More', 'formflow'); ?>
                    </a>
                </div>
            </label>
        </div>

        <?php if ($requires_wifi) : ?>
            <?php
            $wifi_question = isf_get_content($instance, 'wifi_question', __('Does your home have WiFi?', 'formflow'));
            $wifi_help     = isf_get_content($instance, 'wifi_help', __('A wireless internet connection from a router in your home.', 'formflow'));
            $wifi_heading  = isf_get_content($instance, 'wifi_callout_heading', __('Home WiFi is required for the thermostat', 'formflow'));
            $wifi_body     = isf_get_content($instance, 'wifi_callout_body', __('The Web-Programmable Thermostat connects to your home WiFi to receive schedule changes and take part in energy-saving events. Without it, it cannot be installed.', 'formflow'));
            $wifi_reassure = isf_get_content($instance, 'wifi_callout_reassurance', __('The Outdoor Switch gets you the same program. Same bill credits, same participation levels, same enrollment — it is simply a different device, installed outside on your AC unit instead of on your wall. No WiFi required.', 'formflow'));
            $wifi_convert  = isf_get_content($instance, 'wifi_convert_button', __('Yes, enroll me in the Outdoor Switch program', 'formflow'));
            ?>
            <!--
                Shown only once the thermostat is selected. Hidden on load so
                nobody is warned about ineligibility before they have answered.
            -->
            <fieldset class="isf-field isf-wifi-check" id="isf-wifi-check" hidden>
                <legend class="isf-label">
                    <?php echo esc_html($wifi_question); ?>
                    <span class="isf-required">*</span>
                </legend>

                <div class="isf-wifi-options">
                    <label class="isf-radio-option">
                        <input type="radio" name="has_wifi" value="yes"
                               <?php checked($has_wifi, 'yes'); ?>>
                        <span class="isf-radio-label"><?php esc_html_e('Yes', 'formflow'); ?></span>
                    </label>

                    <label class="isf-radio-option">
                        <input type="radio" name="has_wifi" value="no"
                               <?php checked($has_wifi, 'no'); ?>>
                        <span class="isf-radio-label"><?php esc_html_e('No', 'formflow'); ?></span>
                    </label>
                </div>

                <p class="isf-field-help" id="isf-wifi-help"><?php echo esc_html($wifi_help); ?></p>
            </fieldset>

            <!--
                role="alert" so the callout is announced rather than silently
                appearing. Meaning must not depend on the red treatment alone:
                the icon and the heading state the problem in words, per WCAG AA.
            -->
            <div class="isf-wifi-callout" id="isf-wifi-callout" role="alert" hidden>
                <div class="isf-wifi-callout-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <path d="M12 9v4M12 17h.01"/>
                    </svg>
                </div>

                <div class="isf-wifi-callout-content">
                    <h3 class="isf-wifi-callout-heading"><?php echo esc_html($wifi_heading); ?></h3>
                    <p><?php echo esc_html($wifi_body); ?></p>
                    <p class="isf-wifi-callout-reassurance"><?php echo esc_html($wifi_reassure); ?></p>

                    <div class="isf-wifi-callout-actions">
                        <button type="button" class="isf-btn isf-btn-primary isf-convert-to-dcu">
                            <?php echo esc_html($wifi_convert); ?>
                            <span class="isf-btn-arrow">&rarr;</span>
                        </button>
                        <a href="#" class="isf-device-info" data-popup="dcu">
                            <?php esc_html_e("What's the Outdoor Switch?", 'formflow'); ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="isf-step-actions">
            <button type="submit" class="isf-btn isf-btn-primary isf-btn-next">
                <?php echo esc_html($btn_next); ?>
                <span class="isf-btn-arrow">&rarr;</span>
            </button>
        </div>
    </form>
</div>

<!-- Device Info Popups -->
<div class="isf-popup" id="isf-popup-thermostat" style="display:none;">
    <div class="isf-popup-content">
        <button type="button" class="isf-popup-close">&times;</button>
        <h3><?php esc_html_e('Web-Programmable Thermostat', 'formflow'); ?></h3>
        <p><?php esc_html_e('The Energy Wise Rewards web-programmable thermostat allows you to:', 'formflow'); ?></p>
        <ul>
            <li><?php esc_html_e('Control your home temperature remotely via web or mobile app', 'formflow'); ?></li>
            <li><?php esc_html_e('Set schedules to automatically adjust temperature when you\'re away', 'formflow'); ?></li>
            <li><?php esc_html_e('Receive energy-saving tips and usage insights', 'formflow'); ?></li>
            <li><?php esc_html_e('Participate in demand response events to earn rewards', 'formflow'); ?></li>
        </ul>
        <p><?php esc_html_e('Installation is free and performed by a certified technician.', 'formflow'); ?></p>
    </div>
</div>

<div class="isf-popup" id="isf-popup-dcu" style="display:none;">
    <div class="isf-popup-content">
        <button type="button" class="isf-popup-close">&times;</button>
        <h3><?php esc_html_e('Outdoor Switch (Cycling Device)', 'formflow'); ?></h3>
        <p><?php esc_html_e('The outdoor switch is a simple device that:', 'formflow'); ?></p>
        <ul>
            <li><?php esc_html_e('Connects directly to your outdoor AC or heat pump unit', 'formflow'); ?></li>
            <li><?php esc_html_e('Briefly cycles your unit during peak demand periods', 'formflow'); ?></li>
            <li><?php esc_html_e('Operates automatically - no action required from you', 'formflow'); ?></li>
            <li><?php esc_html_e('Has minimal impact on your home comfort', 'formflow'); ?></li>
        </ul>
        <p><?php esc_html_e('Installation is free and typically takes less than 30 minutes.', 'formflow'); ?></p>
    </div>
</div>
