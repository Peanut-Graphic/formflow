<?php
/**
 * Enrollment Step 3: Customer Information
 *
 * Collects customer contact, address, property, and equipment details.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Import content helper function
use function ISF\Frontend\isf_get_content;
use function ISF\Frontend\isf_get_default_state;

// Pre-fill from validation if available
$first_name = $form_data['first_name'] ?? ($form_data['validated_first_name'] ?? '');
$last_name = $form_data['last_name'] ?? ($form_data['validated_last_name'] ?? '');
$email = $form_data['email'] ?? '';
$email_confirm = $form_data['email_confirm'] ?? '';
$phone = $form_data['phone'] ?? '';
$phone_type = $form_data['phone_type'] ?? 'mobile';
$alt_phone = $form_data['alt_phone'] ?? '';
$alt_phone_type = $form_data['alt_phone_type'] ?? 'home';

// Address fields - pre-fill from validation, then from instance default
$street = $form_data['street'] ?? ($form_data['validated_street'] ?? '');
$street2 = $form_data['street2'] ?? '';
$city = $form_data['city'] ?? ($form_data['validated_city'] ?? '');
$state = $form_data['state'] ?? ($form_data['validated_state'] ?? isf_get_default_state($instance));
$zip = $form_data['zip'] ?? ($form_data['validated_zip'] ?? '');

// Property ownership fields
$ownership = $form_data['ownership'] ?? '';
$owner_approval = $form_data['owner_approval'] ?? false;

// Equipment fields
$thermostat_count = $form_data['thermostat_count'] ?? '1';
$device_type = $form_data['device_type'] ?? 'thermostat';
$is_dcu = ($device_type === 'dcu');

// DCU-specific fields
$easy_access = $form_data['easy_access'] ?? 'Yes';
$install_time = $form_data['install_time'] ?? 'Anytime';

// Additional fields
$special_instructions = $form_data['special_instructions'] ?? '';
$promo_code = $form_data['promo_code'] ?? '';

// Get customizable content
$step_title = isf_get_content($instance, 'step3_title', __('Your Information', 'formflow'));
$btn_back = isf_get_content($instance, 'btn_back', __('Back', 'formflow'));
$btn_next = isf_get_content($instance, 'btn_next', __('Continue to Scheduling', 'formflow'));
?>

<div class="isf-step" data-step="3">
    <h2 class="isf-step-title"><?php echo esc_html($step_title); ?></h2>
    <p class="isf-step-description">
        <?php esc_html_e('Please provide your contact information for scheduling and installation.', 'formflow'); ?>
    </p>

    <form class="isf-step-form" id="isf-step-3-form">
        <!-- Contact Information -->
        <fieldset class="isf-fieldset">
            <legend class="isf-legend"><?php esc_html_e('Contact Information', 'formflow'); ?></legend>

            <div class="isf-form-grid isf-form-grid-2">
                <div class="isf-field isf-field-required">
                    <label for="first_name" class="isf-label">
                        <?php esc_html_e('First Name', 'formflow'); ?>
                        <span class="isf-required">*</span>
                    </label>
                    <input type="text"
                           name="first_name"
                           id="first_name"
                           class="isf-input"
                           value="<?php echo esc_attr($first_name); ?>"
                           required
                           autocomplete="given-name">
                </div>

                <div class="isf-field isf-field-required">
                    <label for="last_name" class="isf-label">
                        <?php esc_html_e('Last Name', 'formflow'); ?>
                        <span class="isf-required">*</span>
                    </label>
                    <input type="text"
                           name="last_name"
                           id="last_name"
                           class="isf-input"
                           value="<?php echo esc_attr($last_name); ?>"
                           required
                           autocomplete="family-name">
                </div>
            </div>

            <div class="isf-form-grid isf-form-grid-2">
                <div class="isf-field isf-field-required">
                    <label for="email" class="isf-label">
                        <?php esc_html_e('Email Address', 'formflow'); ?>
                        <span class="isf-required">*</span>
                    </label>
                    <input type="email"
                           name="email"
                           id="email"
                           class="isf-input"
                           value="<?php echo esc_attr($email); ?>"
                           required
                           autocomplete="email">
                </div>

                <div class="isf-field isf-field-required">
                    <label for="email_confirm" class="isf-label">
                        <?php esc_html_e('Confirm Email', 'formflow'); ?>
                        <span class="isf-required">*</span>
                    </label>
                    <input type="email"
                           name="email_confirm"
                           id="email_confirm"
                           class="isf-input"
                           value="<?php echo esc_attr($email_confirm); ?>"
                           required
                           autocomplete="email">
                </div>
            </div>

            <div class="isf-form-grid isf-form-grid-2">
                <div class="isf-field isf-field-required">
                    <label for="phone" class="isf-label">
                        <?php esc_html_e('Primary Phone', 'formflow'); ?>
                        <span class="isf-required">*</span>
                    </label>
                    <div class="isf-input-group">
                        <input type="tel"
                               name="phone"
                               id="phone"
                               class="isf-input"
                               value="<?php echo esc_attr($phone); ?>"
                               placeholder="(555) 555-5555"
                               required
                               autocomplete="tel">
                        <select name="phone_type" id="phone_type" class="isf-select isf-select-small">
                            <option value="mobile" <?php selected($phone_type, 'mobile'); ?>><?php esc_html_e('Mobile', 'formflow'); ?></option>
                            <option value="home" <?php selected($phone_type, 'home'); ?>><?php esc_html_e('Home', 'formflow'); ?></option>
                            <option value="work" <?php selected($phone_type, 'work'); ?>><?php esc_html_e('Work', 'formflow'); ?></option>
                        </select>
                    </div>
                </div>

                <div class="isf-field">
                    <label for="alt_phone" class="isf-label">
                        <?php esc_html_e('Alternate Phone', 'formflow'); ?>
                    </label>
                    <div class="isf-input-group">
                        <input type="tel"
                               name="alt_phone"
                               id="alt_phone"
                               class="isf-input"
                               value="<?php echo esc_attr($alt_phone); ?>"
                               placeholder="(555) 555-5555"
                               autocomplete="tel">
                        <select name="alt_phone_type" id="alt_phone_type" class="isf-select isf-select-small">
                            <option value="home" <?php selected($alt_phone_type, 'home'); ?>><?php esc_html_e('Home', 'formflow'); ?></option>
                            <option value="mobile" <?php selected($alt_phone_type, 'mobile'); ?>><?php esc_html_e('Mobile', 'formflow'); ?></option>
                            <option value="work" <?php selected($alt_phone_type, 'work'); ?>><?php esc_html_e('Work', 'formflow'); ?></option>
                        </select>
                    </div>
                </div>
            </div>
        </fieldset>

        <!-- Service Address -->
        <fieldset class="isf-fieldset">
            <legend class="isf-legend"><?php esc_html_e('Service Address', 'formflow'); ?></legend>
            <p class="isf-fieldset-description">
                <?php esc_html_e('This should be the address where the device will be installed.', 'formflow'); ?>
            </p>

            <div class="isf-field isf-field-required">
                <label for="street" class="isf-label">
                    <?php esc_html_e('Street Address', 'formflow'); ?>
                    <span class="isf-required">*</span>
                </label>
                <input type="text"
                       name="street"
                       id="street"
                       class="isf-input"
                       value="<?php echo esc_attr($street); ?>"
                       required
                       autocomplete="street-address">
            </div>

            <div class="isf-field">
                <label for="street2" class="isf-label">
                    <?php esc_html_e('Address 2', 'formflow'); ?>
                </label>
                <input type="text"
                       name="street2"
                       id="street2"
                       class="isf-input"
                       value="<?php echo esc_attr($street2); ?>"
                       placeholder="<?php esc_attr_e('Apt, Suite, Unit, Building, Floor, etc.', 'formflow'); ?>"
                       autocomplete="address-line2">
            </div>

            <div class="isf-form-grid isf-form-grid-3">
                <div class="isf-field isf-field-required">
                    <label for="city" class="isf-label">
                        <?php esc_html_e('City', 'formflow'); ?>
                        <span class="isf-required">*</span>
                    </label>
                    <input type="text"
                           name="city"
                           id="city"
                           class="isf-input"
                           value="<?php echo esc_attr($city); ?>"
                           required
                           autocomplete="address-level2">
                </div>

                <div class="isf-field isf-field-required">
                    <label for="state" class="isf-label">
                        <?php esc_html_e('State', 'formflow'); ?>
                        <span class="isf-required">*</span>
                    </label>
                    <select name="state" id="state" class="isf-select" required autocomplete="address-level1">
                        <option value=""><?php esc_html_e('Select State', 'formflow'); ?></option>
                        <option value="DC" <?php selected($state, 'DC'); ?>>District of Columbia</option>
                        <option value="DE" <?php selected($state, 'DE'); ?>>Delaware</option>
                        <option value="MD" <?php selected($state, 'MD'); ?>>Maryland</option>
                    </select>
                </div>

                <div class="isf-field isf-field-required">
                    <label for="zip_confirm" class="isf-label">
                        <?php esc_html_e('ZIP Code', 'formflow'); ?>
                        <span class="isf-required">*</span>
                    </label>
                    <input type="text"
                           name="zip_confirm"
                           id="zip_confirm"
                           class="isf-input"
                           value="<?php echo esc_attr($zip); ?>"
                           pattern="[0-9]{5}"
                           maxlength="5"
                           required
                           autocomplete="postal-code">
                </div>
            </div>
        </fieldset>

        <!-- Property Information -->
        <fieldset class="isf-fieldset">
            <legend class="isf-legend"><?php esc_html_e('Property Information', 'formflow'); ?></legend>

            <div class="isf-form-grid isf-form-grid-2">
                <div class="isf-field isf-field-required">
                    <label for="ownership" class="isf-label">
                        <?php esc_html_e('Lease or Own', 'formflow'); ?>
                        <span class="isf-required">*</span>
                    </label>
                    <select name="ownership" id="ownership" class="isf-select" required>
                        <option value=""><?php esc_html_e('Select One', 'formflow'); ?></option>
                        <option value="own" <?php selected($ownership, 'own'); ?>><?php esc_html_e('Own', 'formflow'); ?></option>
                        <option value="lease" <?php selected($ownership, 'lease'); ?>><?php esc_html_e('Lease/Rent', 'formflow'); ?></option>
                    </select>
                </div>

                <?php if ($is_dcu) : ?>
                <!-- DCU outdoor switch - no thermostat count needed -->
                <div class="isf-field">
                    <label class="isf-label"><?php esc_html_e('Device Type', 'formflow'); ?></label>
                    <div class="isf-static-value"><?php esc_html_e('Outdoor Switch (DCU)', 'formflow'); ?></div>
                </div>
                <?php else : ?>
                <div class="isf-field isf-field-required">
                    <label for="thermostat_count" class="isf-label">
                        <?php esc_html_e('Number of thermostats controlling AC/heat pumps', 'formflow'); ?>
                        <span class="isf-required">*</span>
                    </label>
                    <select name="thermostat_count" id="thermostat_count" class="isf-select" required>
                        <?php for ($i = 0; $i <= 5; $i++) : ?>
                            <option value="<?php echo $i; ?>" <?php selected($thermostat_count, (string)$i); ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($is_dcu) : ?>
            <!-- DCU-specific installation fields -->
            <div class="isf-dcu-fields isf-form-grid isf-form-grid-2">
                <div class="isf-field isf-field-required">
                    <label for="easy_access" class="isf-label">
                        <?php esc_html_e('Easy Access to Outdoor Unit', 'formflow'); ?>
                        <span class="isf-required">*</span>
                    </label>
                    <select name="easy_access" id="easy_access" class="isf-select" required>
                        <option value="Yes" <?php selected($easy_access, 'Yes'); ?>><?php esc_html_e('Yes - Unit is easily accessible', 'formflow'); ?></option>
                        <option value="No" <?php selected($easy_access, 'No'); ?>><?php esc_html_e('No - Scheduling may be required', 'formflow'); ?></option>
                    </select>
                    <p class="isf-field-hint"><?php esc_html_e('Can the technician access your outdoor AC/heat pump unit without someone being home?', 'formflow'); ?></p>
                </div>

                <div class="isf-field isf-field-required">
                    <label for="install_time" class="isf-label">
                        <?php esc_html_e('Preferred Installation Time', 'formflow'); ?>
                        <span class="isf-required">*</span>
                    </label>
                    <select name="install_time" id="install_time" class="isf-select" required>
                        <option value="Anytime" <?php selected($install_time, 'Anytime'); ?>><?php esc_html_e('Anytime - Install whenever convenient', 'formflow'); ?></option>
                        <option value="Appointment" <?php selected($install_time, 'Appointment'); ?>><?php esc_html_e('Appointment - I need to schedule a specific time', 'formflow'); ?></option>
                    </select>
                    <p class="isf-field-hint"><?php esc_html_e('Choose "Appointment" if you need to be home during installation.', 'formflow'); ?></p>
                </div>
            </div>
            <?php endif; ?>

            <div class="isf-owner-approval-wrap" id="isf-owner-approval-wrap" style="<?php echo $ownership === 'lease' ? '' : 'display:none;'; ?>">
                <label class="isf-checkbox-option">
                    <input type="checkbox"
                           name="owner_approval"
                           id="owner_approval"
                           value="1"
                           <?php checked($owner_approval, true); ?>>
                    <span class="isf-checkbox-label">
                        <?php esc_html_e('I do not own the property for this account and certify that I have received approval from the owner to install the web-programmable thermostat or outdoor switch.', 'formflow'); ?>
                    </span>
                </label>
            </div>

            <!-- Equipment Exclusions Notice -->
            <div class="isf-notice isf-notice-info">
                <div class="isf-notice-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="isf-notice-content">
                    <strong><?php esc_html_e('Before you enroll, please note:', 'formflow'); ?></strong>
                    <p><?php esc_html_e('The following equipment and residential units do not qualify for Energy Wise Rewards and will not be connected to the energy saving device:', 'formflow'); ?></p>
                    <ul>
                        <li><?php esc_html_e('Geo Thermal heat pumps (also known as GeoExchange, earth-coupled, ground-source, or water-source heat pumps)', 'formflow'); ?></li>
                        <li><?php esc_html_e('Evaporative coolers (also known as swamp, desert, or air coolers)', 'formflow'); ?></li>
                        <li><?php esc_html_e('Chillers', 'formflow'); ?></li>
                        <li><?php esc_html_e('High-rise Condominiums in which the units do not have their own HVAC system', 'formflow'); ?></li>
                        <li><?php esc_html_e('Thermostats controlled by home alarm or home automation systems', 'formflow'); ?></li>
                    </ul>
                </div>
            </div>
        </fieldset>

        <!-- Additional Information -->
        <fieldset class="isf-fieldset">
            <legend class="isf-legend"><?php esc_html_e('Additional Information', 'formflow'); ?></legend>

            <div class="isf-field">
                <label for="special_instructions" class="isf-label">
                    <?php esc_html_e('Special Instructions for Technician', 'formflow'); ?>
                </label>
                <textarea name="special_instructions"
                          id="special_instructions"
                          class="isf-textarea"
                          rows="3"
                          placeholder="<?php esc_attr_e('Gate code, parking instructions, pet information, etc.', 'formflow'); ?>"><?php echo esc_textarea($special_instructions); ?></textarea>
            </div>

            <div class="isf-field">
                <label for="promo_code" class="isf-label">
                    <?php esc_html_e('How did you hear about this program?', 'formflow'); ?>
                </label>
                <?php if (!empty($promo_codes)) : ?>
                    <select name="promo_code" id="promo_code" class="isf-select">
                        <option value=""><?php esc_html_e('-- Select an option --', 'formflow'); ?></option>
                        <?php foreach ($promo_codes as $code) : ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($promo_code, $code); ?>>
                                <?php echo esc_html($code); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="other" <?php selected($promo_code, 'other'); ?>><?php esc_html_e('Other', 'formflow'); ?></option>
                    </select>
                    <div class="isf-promo-other-wrap" id="isf-promo-other-wrap" style="display: none; margin-top: 10px;">
                        <input type="text"
                               name="promo_code_other"
                               id="promo_code_other"
                               class="isf-input"
                               value="<?php echo esc_attr($form_data['promo_code_other'] ?? ''); ?>"
                               placeholder="<?php esc_attr_e('Please specify...', 'formflow'); ?>">
                    </div>
                <?php else : ?>
                    <input type="text"
                           name="promo_code"
                           id="promo_code"
                           class="isf-input isf-input-short"
                           value="<?php echo esc_attr($promo_code); ?>"
                           placeholder="<?php esc_attr_e('Optional', 'formflow'); ?>">
                <?php endif; ?>
            </div>
        </fieldset>

        <div class="isf-step-actions">
            <button type="button" class="isf-btn isf-btn-secondary isf-btn-prev">
                <span class="isf-btn-arrow">&larr;</span>
                <?php echo esc_html($btn_back); ?>
            </button>
            <button type="submit" class="isf-btn isf-btn-primary isf-btn-next">
                <?php echo esc_html($btn_next); ?>
                <span class="isf-btn-arrow">&rarr;</span>
            </button>
        </div>
    </form>
</div>
