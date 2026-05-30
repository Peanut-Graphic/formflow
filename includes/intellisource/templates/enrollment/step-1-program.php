<?php
/**
 * Enrollment Step 1: Program Selection
 *
 * User selects their device type (thermostat or outdoor switch).
 */

if (!defined('ABSPATH')) {
    exit;
}

// Import content helper function
use function ISF\Frontend\isf_get_content;

$device_type = $form_data['device_type'] ?? '';

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
