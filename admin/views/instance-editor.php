<?php
/**
 * Admin Instance Editor View
 *
 * Multi-step wizard form for creating/editing form instances.
 * Features: Wizard navigation, Quick-edit mode, WYSIWYG editors
 */

if (!defined('ABSPATH')) {
    exit;
}

// Include help tooltip helper
require_once ISF_PLUGIN_DIR . 'admin/views/partials/help-tooltip.php';

$is_edit = !empty($instance);
$page_title = $is_edit
    ? __('Edit Form Instance', 'formflow')
    : __('Add New Form Instance', 'formflow');

// Get settings
$content = $instance['settings']['content'] ?? [];
$fields_config = $instance['settings']['fields'] ?? [];
$scheduling = $instance['settings']['scheduling'] ?? [];
$blocked_dates = $scheduling['blocked_dates'] ?? [];
$capacity_limits = $scheduling['capacity_limits'] ?? [];
$maintenance = $instance['settings']['maintenance'] ?? [];

// States for dropdown
$states = [
    '' => __('-- Select State --', 'formflow'),
    'DC' => 'District of Columbia',
    'DE' => 'Delaware',
    'MD' => 'Maryland',
];

// Available fields for form
$available_fields = [
    'phone' => __('Phone Number', 'formflow'),
    'email' => __('Email Address', 'formflow'),
    'street' => __('Street Address', 'formflow'),
    'city' => __('City', 'formflow'),
    'state' => __('State', 'formflow'),
    'zip' => __('ZIP Code (Customer Info)', 'formflow'),
    'promo_code' => __('Promo Code', 'formflow'),
];

// Get saved field order or use default
$field_order = $instance['settings']['field_order'] ?? array_keys($available_fields);
foreach (array_keys($available_fields) as $key) {
    if (!in_array($key, $field_order)) {
        $field_order[] = $key;
    }
}

// Wizard steps configuration
$wizard_steps = [
    'basics' => [
        'title' => __('Basics', 'formflow'),
        'icon' => 'admin-settings',
        'description' => __('Name, utility, and form type', 'formflow'),
    ],
    'api' => [
        'title' => __('API', 'formflow'),
        'icon' => 'admin-site',
        'description' => __('API endpoint and credentials', 'formflow'),
    ],
    'fields' => [
        'title' => __('Fields', 'formflow'),
        'icon' => 'forms',
        'description' => __('Form fields and validation', 'formflow'),
    ],
    'scheduling' => [
        'title' => __('Scheduling', 'formflow'),
        'icon' => 'calendar-alt',
        'description' => __('Blocked dates and capacity', 'formflow'),
    ],
    'content' => [
        'title' => __('Content', 'formflow'),
        'icon' => 'edit',
        'description' => __('Text, labels, and messages', 'formflow'),
    ],
    'email' => [
        'title' => __('Email', 'formflow'),
        'icon' => 'email',
        'description' => __('Email settings and templates', 'formflow'),
    ],
    'features' => [
        'title' => __('Features', 'formflow'),
        'icon' => 'admin-plugins',
        'description' => __('Advanced features', 'formflow'),
    ],
];
?>

<div class="wrap isf-admin-wrap isf-editor-wrap">
    <div class="isf-editor-header">
        <div class="isf-editor-header-left">
            <a href="<?php echo esc_url(admin_url('admin.php?page=isf-dashboard')); ?>" class="isf-back-link">
                <span class="dashicons dashicons-arrow-left-alt2"></span>
                <?php esc_html_e('Dashboard', 'formflow'); ?>
            </a>
            <h1><?php echo esc_html($page_title); ?></h1>
        </div>
        <div class="isf-editor-header-right">
            <div class="isf-editor-mode-toggle">
                <label class="isf-toggle-switch">
                    <input type="checkbox" id="isf-quick-edit-toggle" <?php checked(isset($_GET['mode']) && $_GET['mode'] === 'quick'); ?>>
                    <span class="isf-toggle-slider"></span>
                </label>
                <span class="isf-toggle-label"><?php esc_html_e('Quick Edit', 'formflow'); ?></span>
                <?php isf_help_tooltip(__('Quick Edit mode shows all settings on one page for power users.', 'formflow')); ?>
            </div>
        </div>
    </div>

    <form id="isf-instance-form" class="isf-form isf-wizard-form" method="post">
        <input type="hidden" name="id" value="<?php echo esc_attr($instance['id'] ?? 0); ?>">
        <input type="hidden" name="current_step" id="isf-current-step" value="basics">

        <div class="isf-editor-layout">
            <!-- Wizard Navigation (left sidebar) -->
            <div class="isf-wizard-nav" id="isf-wizard-nav">
                <ul class="isf-wizard-steps">
                    <?php foreach ($wizard_steps as $step_id => $step) : ?>
                    <li class="isf-wizard-step <?php echo $step_id === 'basics' ? 'active' : ''; ?>" data-step="<?php echo esc_attr($step_id); ?>">
                        <span class="isf-step-icon">
                            <span class="dashicons dashicons-<?php echo esc_attr($step['icon']); ?>"></span>
                        </span>
                        <span class="isf-step-content">
                            <span class="isf-step-title"><?php echo esc_html($step['title']); ?></span>
                            <span class="isf-step-desc"><?php echo esc_html($step['description']); ?></span>
                        </span>
                        <span class="isf-step-status">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Main Content Area -->
            <div class="isf-editor-main">

                <!-- In-page section nav (visible only in Quick Edit mode; CSS gated). -->
                <nav class="isf-quick-nav" aria-label="<?php esc_attr_e('Form sections', 'formflow'); ?>">
                    <?php foreach ($wizard_steps as $step_id => $step) : ?>
                        <a href="#isf-panel-<?php echo esc_attr($step_id); ?>"
                           class="isf-quick-nav-link"
                           data-target="<?php echo esc_attr($step_id); ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr($step['icon']); ?>"></span>
                            <?php echo esc_html($step['title']); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <!-- Wizard Panels -->
                <div class="isf-wizard-panels">

                    <!-- Step 1: Basics -->
                    <div class="isf-wizard-panel active" data-panel="basics">
                        <div class="isf-panel-header">
                            <h2><?php esc_html_e('Basic Settings', 'formflow'); ?></h2>
                            <p><?php esc_html_e('Configure the fundamental settings for this form instance.', 'formflow'); ?></p>
                        </div>

                        <div class="isf-panel-body">
                            <div class="isf-pods-grid">
                                <!-- Identity Pod -->
                                <div class="isf-pod isf-pod-primary">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-nametag"></span><?php esc_html_e('Identity', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <div class="isf-field-group">
                                            <label for="name" class="isf-field-label">
                                                <?php esc_html_e('Form Name', 'formflow'); ?>
                                                <span class="required">*</span>
                                            </label>
                                            <input type="text" id="name" name="name" class="isf-field-input"
                                                   value="<?php echo esc_attr($instance['name'] ?? ''); ?>" required
                                                   placeholder="<?php esc_attr_e('e.g., Delmarva MD Enrollment', 'formflow'); ?>">
                                            <p class="isf-field-help"><?php esc_html_e('A descriptive name for this form instance.', 'formflow'); ?></p>
                                        </div>

                                        <div class="isf-field-group">
                                            <label for="slug" class="isf-field-label">
                                                <?php esc_html_e('Slug', 'formflow'); ?>
                                                <span class="required">*</span>
                                            </label>
                                            <div class="isf-field-with-prefix">
                                                <span class="isf-field-prefix">[isf_form instance="</span>
                                                <input type="text" id="slug" name="slug" class="isf-field-input isf-slug-input"
                                                       value="<?php echo esc_attr($instance['slug'] ?? ''); ?>" required
                                                       pattern="[a-z0-9\-]+"
                                                       placeholder="delmarva-md"
                                                       <?php echo $is_edit ? 'readonly' : ''; ?>>
                                                <span class="isf-field-suffix">"]</span>
                                            </div>
                                            <p class="isf-field-help"><?php esc_html_e('URL-friendly identifier used in the shortcode.', 'formflow'); ?></p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Configuration Pod -->
                                <div class="isf-pod">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-admin-settings"></span><?php esc_html_e('Configuration', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <div class="isf-field-group">
                                            <label for="utility" class="isf-field-label">
                                                <?php esc_html_e('Utility', 'formflow'); ?>
                                                <span class="required">*</span>
                                            </label>
                                            <select id="utility" name="utility" class="isf-field-select" required>
                                                <option value=""><?php esc_html_e('Select a utility...', 'formflow'); ?></option>
                                                <?php foreach ($utilities as $key => $utility) : ?>
                                                    <option value="<?php echo esc_attr($key); ?>"
                                                            data-endpoint="<?php echo esc_attr($utility['api_endpoint']); ?>"
                                                            data-email-from="<?php echo esc_attr($utility['support_email_from']); ?>"
                                                            data-email-to="<?php echo esc_attr($utility['support_email_to']); ?>"
                                                            <?php selected($instance['utility'] ?? '', $key); ?>>
                                                        <?php echo esc_html($utility['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="isf-field-group">
                                            <label for="form_type" class="isf-field-label">
                                                <?php esc_html_e('Form Type', 'formflow'); ?>
                                                <?php isf_help_tooltip(__('Enrollment Form includes all steps. Scheduler Only skips enrollment. External Enrollment tracks handoffs to external platforms.', 'formflow')); ?>
                                            </label>
                                            <select id="form_type" name="form_type" class="isf-field-select">
                                                <option value="enrollment" <?php selected($instance['form_type'] ?? '', 'enrollment'); ?>>
                                                    <?php esc_html_e('Enrollment Form', 'formflow'); ?>
                                                </option>
                                                <option value="scheduler" <?php selected($instance['form_type'] ?? '', 'scheduler'); ?>>
                                                    <?php esc_html_e('Scheduler Only', 'formflow'); ?>
                                                </option>
                                                <option value="external" <?php selected($instance['form_type'] ?? '', 'external'); ?>>
                                                    <?php esc_html_e('External Enrollment', 'formflow'); ?>
                                                </option>
                                            </select>
                                        </div>

                                        <div class="isf-pod-fields">
                                            <div class="isf-field-group">
                                                <label for="default_state" class="isf-field-label">
                                                    <?php esc_html_e('Default State', 'formflow'); ?>
                                                </label>
                                                <select id="default_state" name="settings[default_state]" class="isf-field-select">
                                                    <?php foreach ($states as $abbr => $state_name) : ?>
                                                        <option value="<?php echo esc_attr($abbr); ?>" <?php selected($instance['settings']['default_state'] ?? '', $abbr); ?>>
                                                            <?php echo esc_html($state_name); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="isf-field-group">
                                                <label for="support_phone" class="isf-field-label">
                                                    <?php esc_html_e('Support Phone', 'formflow'); ?>
                                                </label>
                                                <input type="text" id="support_phone" name="settings[support_phone]" class="isf-field-input"
                                                       value="<?php echo esc_attr($instance['settings']['support_phone'] ?? ''); ?>"
                                                       placeholder="<?php esc_attr_e('e.g. 1-800-555-0100', 'formflow'); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- External URL Pod (shown only for external form type) -->
                                <div class="isf-pod isf-pod-full isf-external-settings" id="isf-external-settings" style="display: <?php echo ($instance['form_type'] ?? '') === 'external' ? 'block' : 'none'; ?>;">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-external"></span><?php esc_html_e('External Enrollment', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <div class="isf-pod-info-box">
                                            <p><span class="dashicons dashicons-info"></span>
                                            <?php esc_html_e('Users will be tracked for attribution, then redirected to the external platform.', 'formflow'); ?></p>
                                        </div>
                                        <div class="isf-pod-fields">
                                            <div class="isf-field-group isf-pod-field-full">
                                                <label for="external_url" class="isf-field-label">
                                                    <?php esc_html_e('External Enrollment URL', 'formflow'); ?>
                                                    <span class="required">*</span>
                                                </label>
                                                <input type="url" id="external_url" name="settings[external_url]" class="isf-field-input"
                                                       value="<?php echo esc_url($instance['settings']['external_url'] ?? ''); ?>"
                                                       placeholder="https://www.dominionenergyptr.com/ptr/residential/">
                                            </div>

                                            <div class="isf-field-group">
                                                <label for="external_button_text" class="isf-field-label">
                                                    <?php esc_html_e('Button Text', 'formflow'); ?>
                                                </label>
                                                <input type="text" id="external_button_text" name="settings[external_button_text]" class="isf-field-input"
                                                       value="<?php echo esc_attr($instance['settings']['external_button_text'] ?? ''); ?>"
                                                       placeholder="<?php esc_attr_e('Enroll Now', 'formflow'); ?>">
                                            </div>

                                            <div class="isf-field-group" style="display: flex; align-items: center; padding-top: 24px;">
                                                <label class="isf-checkbox-label">
                                                    <input type="checkbox" name="settings[external_new_tab]" value="1"
                                                           <?php checked($instance['settings']['external_new_tab'] ?? false); ?>>
                                                    <?php esc_html_e('Open in new tab', 'formflow'); ?>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: API Configuration -->
                    <div class="isf-wizard-panel" data-panel="api">
                        <div class="isf-panel-header">
                            <h2><?php esc_html_e('API Configuration', 'formflow'); ?></h2>
                            <p><?php esc_html_e('Configure the PowerPortal IntelliSOURCE API connection.', 'formflow'); ?></p>
                        </div>

                        <div class="isf-panel-body">
                            <div class="isf-pods-grid">
                                <!-- API Connection Pod -->
                                <div class="isf-pod isf-pod-primary">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-admin-site"></span><?php esc_html_e('API Connection', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <div class="isf-field-group">
                                            <label for="api_endpoint" class="isf-field-label">
                                                <?php esc_html_e('API Endpoint', 'formflow'); ?>
                                                <span class="required">*</span>
                                                <?php isf_help_tooltip(__('Automatically set when you select a utility.', 'formflow')); ?>
                                            </label>
                                            <input type="url" id="api_endpoint" name="api_endpoint" class="isf-field-input"
                                                   value="<?php echo esc_url($instance['api_endpoint'] ?? ''); ?>" required
                                                   placeholder="https://ph.powerportal.com/phiIntelliSOURCE/api/">
                                        </div>

                                        <div class="isf-field-group">
                                            <label for="api_password" class="isf-field-label">
                                                <?php esc_html_e('API Password', 'formflow'); ?>
                                                <?php echo !$is_edit ? '<span class="required">*</span>' : ''; ?>
                                                <?php isf_help_tooltip(__('Securely encrypted before storage.', 'formflow')); ?>
                                            </label>
                                            <input type="password" id="api_password" name="api_password" class="isf-field-input"
                                                   value="<?php echo esc_attr($instance['api_password'] ?? ''); ?>"
                                                   <?php echo $is_edit ? '' : 'required'; ?>>
                                            <?php if ($is_edit) : ?>
                                                <p class="isf-field-help"><?php esc_html_e('Leave blank to keep existing password.', 'formflow'); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="isf-pod-footer">
                                        <button type="button" id="isf-test-api" class="button button-secondary">
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            <?php esc_html_e('Test Connection', 'formflow'); ?>
                                        </button>
                                        <span id="isf-api-status" class="isf-api-status"></span>
                                    </div>
                                </div>

                                <!-- Mode Settings Pod -->
                                <div class="isf-pod isf-pod-modes">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-admin-tools"></span><?php esc_html_e('Mode Settings', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <?php if (($instance['test_mode'] ?? false) || ($instance['settings']['demo_mode'] ?? false)) : ?>
                                            <div class="notice notice-warning inline" style="margin: 0 0 12px 0;">
                                                <p>
                                                    <strong><?php esc_html_e('Heads up:', 'formflow'); ?></strong>
                                                    <?php esc_html_e('Test or Demo Mode is currently ON. Submissions will not enroll real customers. Turn both off before going live.', 'formflow'); ?>
                                                </p>
                                            </div>
                                        <?php endif; ?>

                                        <div class="isf-mode-cards">
                                            <div class="isf-mode-card">
                                                <label class="isf-mode-card-label">
                                                    <input type="checkbox" name="test_mode" value="1" class="isf-mode-toggle" data-mode="test" <?php checked($instance['test_mode'] ?? false); ?>>
                                                    <span class="isf-mode-card-content">
                                                        <span class="isf-mode-icon"><span class="dashicons dashicons-visibility"></span></span>
                                                        <span class="isf-mode-title"><?php esc_html_e('Test Mode', 'formflow'); ?></span>
                                                        <span class="isf-mode-desc"><?php esc_html_e('Submissions are flagged as test. Real API calls still made.', 'formflow'); ?></span>
                                                    </span>
                                                </label>
                                            </div>

                                            <div class="isf-mode-card isf-mode-card-danger">
                                                <label class="isf-mode-card-label">
                                                    <input type="checkbox" name="demo_mode" value="1" class="isf-mode-toggle isf-mode-toggle-demo" data-mode="demo" <?php checked($instance['settings']['demo_mode'] ?? false); ?>>
                                                    <span class="isf-mode-card-content">
                                                        <span class="isf-mode-icon"><span class="dashicons dashicons-warning"></span></span>
                                                        <span class="isf-mode-title"><?php esc_html_e('Demo Mode', 'formflow'); ?></span>
                                                        <span class="isf-mode-desc"><?php esc_html_e('Returns MOCK data — no real enrollment. Do NOT enable on a live form.', 'formflow'); ?></span>
                                                    </span>
                                                </label>
                                            </div>
                                        </div>
                                        <p class="description" style="margin-top: 10px;">
                                            <?php esc_html_e('Both modes default off and require explicit confirmation to turn on. Live forms must have both off.', 'formflow'); ?>
                                        </p>
                                    </div>
                                </div>

                                <!-- Destinations pod (2.9.0+): submission delivery (SFTP, etc.) -->
                                <?php
                                if (class_exists('\\ISF\\Destinations\\DestinationRegistry')) {
                                    include ISF_PLUGIN_DIR . 'admin/views/partials/destinations-pod.php';
                                }
                                ?>

                                <!-- Demo Accounts Pod (shown only in demo mode) -->
                                <?php $demo_accounts = \ISF\Api\MockApiClient::get_demo_accounts_info(); ?>
                                <div class="isf-pod isf-pod-full isf-demo-accounts" id="isf-demo-accounts" style="display: <?php echo ($instance['settings']['demo_mode'] ?? false) ? 'block' : 'none'; ?>;">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-clipboard"></span><?php esc_html_e('Demo Test Accounts', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <table class="isf-demo-table widefat striped">
                                            <thead>
                                                <tr>
                                                    <th><?php esc_html_e('Account #', 'formflow'); ?></th>
                                                    <th><?php esc_html_e('ZIP', 'formflow'); ?></th>
                                                    <th><?php esc_html_e('Note', 'formflow'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($demo_accounts as $account) : ?>
                                                <tr>
                                                    <td><code><?php echo esc_html($account['account']); ?></code></td>
                                                    <td><code><?php echo esc_html($account['zip']); ?></code></td>
                                                    <td><?php echo esc_html($account['description']); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Form Fields -->
                    <div class="isf-wizard-panel" data-panel="fields">
                        <div class="isf-panel-header">
                            <h2><?php esc_html_e('Form Fields', 'formflow'); ?></h2>
                            <p><?php esc_html_e('Configure which fields appear on the form and their order.', 'formflow'); ?></p>
                        </div>

                        <div class="isf-panel-body">
                            <div class="isf-pods-grid">
                                <!-- Customer Info Fields Pod -->
                                <div class="isf-pod isf-pod-primary isf-pod-full">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-forms"></span><?php esc_html_e('Customer Info Fields', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <div class="isf-fields-builder">
                                            <div class="isf-fields-header">
                                                <span class="isf-fields-header-drag"><?php esc_html_e('Order', 'formflow'); ?></span>
                                                <span class="isf-fields-header-field"><?php esc_html_e('Field', 'formflow'); ?></span>
                                                <span class="isf-fields-header-visible"><?php esc_html_e('Show', 'formflow'); ?></span>
                                                <span class="isf-fields-header-required"><?php esc_html_e('Required', 'formflow'); ?></span>
                                            </div>
                                            <div id="isf-sortable-fields" class="isf-sortable-fields">
                                                <?php foreach ($field_order as $field_key) :
                                                    if (!isset($available_fields[$field_key])) continue;
                                                    $field_label = $available_fields[$field_key];
                                                    $is_visible = $fields_config[$field_key]['visible'] ?? true;
                                                    $is_required = $fields_config[$field_key]['required'] ?? true;
                                                ?>
                                                <div class="isf-field-toggle-row isf-sortable-item" data-field="<?php echo esc_attr($field_key); ?>">
                                                    <span class="isf-drag-handle" title="<?php esc_attr_e('Drag to reorder', 'formflow'); ?>">
                                                        <span class="dashicons dashicons-menu"></span>
                                                    </span>
                                                    <input type="hidden" name="settings[field_order][]" value="<?php echo esc_attr($field_key); ?>">
                                                    <span class="isf-field-name"><?php echo esc_html($field_label); ?></span>
                                                    <label class="isf-toggle-mini">
                                                        <input type="checkbox" name="settings[fields][<?php echo esc_attr($field_key); ?>][visible]" value="1" <?php checked($is_visible); ?>>
                                                        <span class="isf-toggle-mini-slider"></span>
                                                    </label>
                                                    <label class="isf-toggle-mini">
                                                        <input type="checkbox" name="settings[fields][<?php echo esc_attr($field_key); ?>][required]" value="1" <?php checked($is_required); ?>>
                                                        <span class="isf-toggle-mini-slider"></span>
                                                    </label>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <p class="isf-field-help"><?php esc_html_e('Drag fields to reorder. Toggle visibility and required status using the switches.', 'formflow'); ?></p>
                                    </div>
                                </div>

                                <!-- Eligibility Pod -->
                                <div class="isf-pod isf-pod-full">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-shield"></span><?php esc_html_e('Eligibility', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <label class="isf-checkbox-row">
                                            <input type="checkbox" name="require_wifi" value="1" <?php checked($instance['settings']['require_wifi'] ?? false); ?>>
                                            <span>
                                                <strong><?php esc_html_e('Require WiFi for thermostat', 'formflow'); ?></strong>
                                            </span>
                                        </label>
                                        <p class="isf-field-help">
                                            <?php esc_html_e('Asks customers who choose the Web-Programmable Thermostat whether their home has WiFi. Answering "No" blocks the thermostat and offers to enroll them in the Outdoor Switch program instead. Leave off for utilities that have not requested this check.', 'formflow'); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 4: Scheduling -->
                    <div class="isf-wizard-panel" data-panel="scheduling">
                        <div class="isf-panel-header">
                            <h2><?php esc_html_e('Scheduling Settings', 'formflow'); ?></h2>
                            <p><?php esc_html_e('Configure scheduling restrictions and capacity limits.', 'formflow'); ?></p>
                        </div>

                        <div class="isf-panel-body">
                            <div class="isf-pods-grid">
                                <!-- Blocked Dates Pod -->
                                <div class="isf-pod isf-pod-primary">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-calendar-alt"></span><?php esc_html_e('Blocked Dates', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <p class="isf-field-help" style="margin-top: 0;"><?php esc_html_e('Block specific dates (holidays, closures) from scheduling.', 'formflow'); ?></p>

                                        <div class="isf-blocked-dates-container">
                                            <div id="isf-blocked-dates-list" class="isf-dates-list isf-sortable-dates">
                                                <?php if (!empty($blocked_dates)) : ?>
                                                    <?php foreach ($blocked_dates as $index => $blocked) : ?>
                                                        <div class="isf-blocked-date-row isf-sortable-item" data-index="<?php echo esc_attr($index); ?>">
                                                            <span class="isf-drag-handle" title="<?php esc_attr_e('Drag to reorder', 'formflow'); ?>">
                                                                <span class="dashicons dashicons-menu"></span>
                                                            </span>
                                                            <input type="date" name="settings[scheduling][blocked_dates][<?php echo esc_attr($index); ?>][date]"
                                                                   value="<?php echo esc_attr($blocked['date'] ?? ''); ?>"
                                                                   class="isf-date-input" required>
                                                            <input type="text" name="settings[scheduling][blocked_dates][<?php echo esc_attr($index); ?>][label]"
                                                                   value="<?php echo esc_attr($blocked['label'] ?? ''); ?>"
                                                                   placeholder="<?php esc_attr_e('e.g., Christmas Day', 'formflow'); ?>"
                                                                   class="regular-text">
                                                            <button type="button" class="button isf-remove-blocked-date" title="<?php esc_attr_e('Remove', 'formflow'); ?>">
                                                                <span class="dashicons dashicons-trash"></span>
                                                            </button>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="isf-pod-footer">
                                        <button type="button" id="isf-add-blocked-date" class="button">
                                            <span class="dashicons dashicons-plus-alt2"></span>
                                            <?php esc_html_e('Add Blocked Date', 'formflow'); ?>
                                        </button>
                                    </div>
                                </div>

                                <!-- Capacity Limits Pod -->
                                <div class="isf-pod">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-groups"></span><?php esc_html_e('Capacity Limits', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <div class="isf-capacity-limits">
                                            <label class="isf-checkbox-label" style="margin-bottom: 15px;">
                                                <input type="checkbox" name="settings[scheduling][capacity_limits][enabled]" value="1"
                                                    <?php checked($capacity_limits['enabled'] ?? false); ?>>
                                                <?php esc_html_e('Enable custom capacity limits (override API values)', 'formflow'); ?>
                                            </label>
                                            <div class="isf-capacity-inputs" style="<?php echo ($capacity_limits['enabled'] ?? false) ? '' : 'display: none;'; ?>">
                                                <p class="isf-field-help"><?php esc_html_e('Set to 0 to block a time slot. Leave blank to use API values.', 'formflow'); ?></p>
                                                <div class="isf-capacity-grid">
                                                    <div class="isf-capacity-item">
                                                        <label for="capacity_am"><?php esc_html_e('Morning', 'formflow'); ?></label>
                                                        <span class="isf-capacity-time">8-11 AM</span>
                                                        <input type="number" id="capacity_am" name="settings[scheduling][capacity_limits][am]"
                                                               value="<?php echo esc_attr($capacity_limits['am'] ?? ''); ?>"
                                                               min="0" max="99" class="small-text" placeholder="—">
                                                    </div>
                                                    <div class="isf-capacity-item">
                                                        <label for="capacity_md"><?php esc_html_e('Mid-Day', 'formflow'); ?></label>
                                                        <span class="isf-capacity-time">11 AM-2 PM</span>
                                                        <input type="number" id="capacity_md" name="settings[scheduling][capacity_limits][md]"
                                                               value="<?php echo esc_attr($capacity_limits['md'] ?? ''); ?>"
                                                               min="0" max="99" class="small-text" placeholder="—">
                                                    </div>
                                                    <div class="isf-capacity-item">
                                                        <label for="capacity_pm"><?php esc_html_e('Afternoon', 'formflow'); ?></label>
                                                        <span class="isf-capacity-time">2-5 PM</span>
                                                        <input type="number" id="capacity_pm" name="settings[scheduling][capacity_limits][pm]"
                                                               value="<?php echo esc_attr($capacity_limits['pm'] ?? ''); ?>"
                                                               min="0" max="99" class="small-text" placeholder="—">
                                                    </div>
                                                    <div class="isf-capacity-item">
                                                        <label for="capacity_ev"><?php esc_html_e('Evening', 'formflow'); ?></label>
                                                        <span class="isf-capacity-time">5-8 PM</span>
                                                        <input type="number" id="capacity_ev" name="settings[scheduling][capacity_limits][ev]"
                                                               value="<?php echo esc_attr($capacity_limits['ev'] ?? ''); ?>"
                                                               min="0" max="99" class="small-text" placeholder="—">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Scheduled Maintenance Pod -->
                                <div class="isf-pod isf-pod-full">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-hammer"></span><?php esc_html_e('Scheduled Maintenance', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <label class="isf-checkbox-label" style="margin-bottom: 15px;">
                                            <input type="checkbox" name="settings[maintenance][enabled]" value="1" <?php checked($maintenance['enabled'] ?? false); ?>>
                                            <?php esc_html_e('Schedule a maintenance window', 'formflow'); ?>
                                        </label>

                                        <div class="isf-maintenance-inputs" style="<?php echo ($maintenance['enabled'] ?? false) ? '' : 'display: none;'; ?>">
                                            <div class="isf-pod-fields">
                                                <div class="isf-field-group">
                                                    <label for="maintenance_start" class="isf-field-label"><?php esc_html_e('Start', 'formflow'); ?></label>
                                                    <input type="datetime-local" id="maintenance_start" name="settings[maintenance][start]" class="isf-field-input"
                                                           value="<?php echo esc_attr($maintenance['start'] ?? ''); ?>">
                                                </div>
                                                <div class="isf-field-group">
                                                    <label for="maintenance_end" class="isf-field-label"><?php esc_html_e('End', 'formflow'); ?></label>
                                                    <input type="datetime-local" id="maintenance_end" name="settings[maintenance][end]" class="isf-field-input"
                                                           value="<?php echo esc_attr($maintenance['end'] ?? ''); ?>">
                                                </div>
                                                <div class="isf-field-group isf-pod-field-full">
                                                    <label for="maintenance_message" class="isf-field-label"><?php esc_html_e('Message', 'formflow'); ?></label>
                                                    <textarea id="maintenance_message" name="settings[maintenance][message]" class="isf-field-textarea" rows="2"
                                                              placeholder="<?php esc_attr_e('This form is temporarily unavailable for scheduled maintenance.', 'formflow'); ?>"><?php echo esc_textarea($maintenance['message'] ?? ''); ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 5: Content -->
                    <div class="isf-wizard-panel" data-panel="content">
                        <div class="isf-panel-header">
                            <h2><?php esc_html_e('Form Content', 'formflow'); ?></h2>
                            <p><?php esc_html_e('Customize the text displayed on the form. Leave blank to use defaults.', 'formflow'); ?></p>
                        </div>

                        <div class="isf-panel-body">
                            <div class="isf-pods-grid">
                                <!-- General Content Pod -->
                                <div class="isf-pod isf-pod-primary">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-text"></span><?php esc_html_e('General Content', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <div class="isf-field-group">
                                            <label for="content_form_title" class="isf-field-label"><?php esc_html_e('Form Title', 'formflow'); ?></label>
                                            <input type="text" id="content_form_title" name="settings[content][form_title]" class="isf-field-input"
                                                   value="<?php echo esc_attr($content['form_title'] ?? ''); ?>"
                                                   placeholder="<?php esc_attr_e('Energy Wise Rewards Enrollment', 'formflow'); ?>">
                                        </div>
                                        <div class="isf-field-group">
                                            <label for="content_form_description" class="isf-field-label"><?php esc_html_e('Form Description', 'formflow'); ?></label>
                                            <textarea id="content_form_description" name="settings[content][form_description]" class="isf-field-textarea" rows="2"
                                                      placeholder="<?php esc_attr_e('Join the Energy Wise Rewards program and start saving today.', 'formflow'); ?>"><?php echo esc_textarea($content['form_description'] ?? ''); ?></textarea>
                                        </div>
                                        <div class="isf-field-group">
                                            <label for="content_program_name" class="isf-field-label"><?php esc_html_e('Program Name', 'formflow'); ?></label>
                                            <input type="text" id="content_program_name" name="settings[content][program_name]" class="isf-field-input"
                                                   value="<?php echo esc_attr($content['program_name'] ?? ''); ?>"
                                                   placeholder="<?php esc_attr_e('Energy Wise Rewards', 'formflow'); ?>">
                                        </div>
                                    </div>
                                </div>

                                <!-- Button Labels Pod -->
                                <div class="isf-pod">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-button"></span><?php esc_html_e('Button Labels', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <div class="isf-pod-fields">
                                            <div class="isf-field-group">
                                                <label for="content_btn_next" class="isf-field-label"><?php esc_html_e('Next', 'formflow'); ?></label>
                                                <input type="text" id="content_btn_next" name="settings[content][btn_next]" class="isf-field-input"
                                                       value="<?php echo esc_attr($content['btn_next'] ?? ''); ?>" placeholder="<?php esc_attr_e('Continue', 'formflow'); ?>">
                                            </div>
                                            <div class="isf-field-group">
                                                <label for="content_btn_back" class="isf-field-label"><?php esc_html_e('Back', 'formflow'); ?></label>
                                                <input type="text" id="content_btn_back" name="settings[content][btn_back]" class="isf-field-input"
                                                       value="<?php echo esc_attr($content['btn_back'] ?? ''); ?>" placeholder="<?php esc_attr_e('Back', 'formflow'); ?>">
                                            </div>
                                            <div class="isf-field-group">
                                                <label for="content_btn_submit" class="isf-field-label"><?php esc_html_e('Submit', 'formflow'); ?></label>
                                                <input type="text" id="content_btn_submit" name="settings[content][btn_submit]" class="isf-field-input"
                                                       value="<?php echo esc_attr($content['btn_submit'] ?? ''); ?>" placeholder="<?php esc_attr_e('Complete Enrollment', 'formflow'); ?>">
                                            </div>
                                            <div class="isf-field-group">
                                                <label for="content_btn_verify" class="isf-field-label"><?php esc_html_e('Verify', 'formflow'); ?></label>
                                                <input type="text" id="content_btn_verify" name="settings[content][btn_verify]" class="isf-field-input"
                                                       value="<?php echo esc_attr($content['btn_verify'] ?? ''); ?>" placeholder="<?php esc_attr_e('Verify Account', 'formflow'); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Step Titles Pod -->
                                <div class="isf-pod">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-editor-ol"></span><?php esc_html_e('Step Titles', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <?php for ($i = 1; $i <= 5; $i++) :
                                            $step_placeholders = [
                                                1 => __('Select Your Device', 'formflow'),
                                                2 => __('Verify Your Account', 'formflow'),
                                                3 => __('Your Information', 'formflow'),
                                                4 => __('Schedule Installation', 'formflow'),
                                                5 => __('Review & Confirm', 'formflow'),
                                            ];
                                        ?>
                                        <div class="isf-field-group isf-field-compact">
                                            <label for="content_step<?php echo $i; ?>_title" class="isf-field-label">
                                                <?php printf(__('Step %d', 'formflow'), $i); ?>
                                            </label>
                                            <input type="text" id="content_step<?php echo $i; ?>_title" name="settings[content][step<?php echo $i; ?>_title]" class="isf-field-input"
                                                   value="<?php echo esc_attr($content['step' . $i . '_title'] ?? ''); ?>"
                                                   placeholder="<?php echo esc_attr($step_placeholders[$i]); ?>">
                                        </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>

                                <!-- Help Text Pod -->
                                <div class="isf-pod">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-editor-help"></span><?php esc_html_e('Help Text', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <div class="isf-field-group">
                                            <label for="content_help_account" class="isf-field-label"><?php esc_html_e('Account Number Help', 'formflow'); ?></label>
                                            <textarea id="content_help_account" name="settings[content][help_account]" class="isf-field-textarea" rows="2"
                                                      placeholder="<?php esc_attr_e('Your account number can be found on your utility bill.', 'formflow'); ?>"><?php echo esc_textarea($content['help_account'] ?? ''); ?></textarea>
                                        </div>
                                        <div class="isf-field-group">
                                            <label for="content_help_zip" class="isf-field-label"><?php esc_html_e('ZIP Code Help', 'formflow'); ?></label>
                                            <textarea id="content_help_zip" name="settings[content][help_zip]" class="isf-field-textarea" rows="2"
                                                      placeholder="<?php esc_attr_e('Enter the 5-digit ZIP code for your service address.', 'formflow'); ?>"><?php echo esc_textarea($content['help_zip'] ?? ''); ?></textarea>
                                        </div>
                                        <div class="isf-field-group">
                                            <label for="content_help_scheduling" class="isf-field-label"><?php esc_html_e('Scheduling Help', 'formflow'); ?></label>
                                            <textarea id="content_help_scheduling" name="settings[content][help_scheduling]" class="isf-field-textarea" rows="2"
                                                      placeholder="<?php esc_attr_e('Select an available date and time for your installation.', 'formflow'); ?>"><?php echo esc_textarea($content['help_scheduling'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- Error Messages Pod -->
                                <div class="isf-pod isf-pod-full">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-warning"></span><?php esc_html_e('Error Messages', 'formflow'); ?></h3>
                                        <span class="isf-pod-badge"><?php esc_html_e('Use {phone} for support number', 'formflow'); ?></span>
                                    </div>
                                    <div class="isf-pod-body">
                                        <div class="isf-pod-fields">
                                            <div class="isf-field-group">
                                                <label for="content_error_validation" class="isf-field-label"><?php esc_html_e('Account Validation', 'formflow'); ?></label>
                                                <textarea id="content_error_validation" name="settings[content][error_validation]" class="isf-field-textarea" rows="2"
                                                          placeholder="<?php esc_attr_e('We could not verify your account. Please check your account number and ZIP code.', 'formflow'); ?>"><?php echo esc_textarea($content['error_validation'] ?? ''); ?></textarea>
                                            </div>
                                            <div class="isf-field-group">
                                                <label for="content_error_scheduling" class="isf-field-label"><?php esc_html_e('Scheduling', 'formflow'); ?></label>
                                                <textarea id="content_error_scheduling" name="settings[content][error_scheduling]" class="isf-field-textarea" rows="2"
                                                          placeholder="<?php esc_attr_e('Unable to load available appointments. Please try again.', 'formflow'); ?>"><?php echo esc_textarea($content['error_scheduling'] ?? ''); ?></textarea>
                                            </div>
                                            <div class="isf-field-group">
                                                <label for="content_error_submission" class="isf-field-label"><?php esc_html_e('Submission', 'formflow'); ?></label>
                                                <textarea id="content_error_submission" name="settings[content][error_submission]" class="isf-field-textarea" rows="2"
                                                          placeholder="<?php esc_attr_e('There was a problem submitting your enrollment.', 'formflow'); ?>"><?php echo esc_textarea($content['error_submission'] ?? ''); ?></textarea>
                                            </div>
                                            <div class="isf-field-group">
                                                <label for="content_error_general" class="isf-field-label"><?php esc_html_e('General Error', 'formflow'); ?></label>
                                                <textarea id="content_error_general" name="settings[content][error_general]" class="isf-field-textarea" rows="2"
                                                          placeholder="<?php esc_attr_e('An error occurred. Please try again.', 'formflow'); ?>"><?php echo esc_textarea($content['error_general'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Terms & Conditions Pod -->
                                <div class="isf-pod isf-pod-full">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-media-text"></span><?php esc_html_e('Terms & Conditions', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <div class="isf-pod-fields">
                                            <div class="isf-field-group">
                                                <label for="content_terms_title" class="isf-field-label"><?php esc_html_e('Title', 'formflow'); ?></label>
                                                <input type="text" id="content_terms_title" name="settings[content][terms_title]" class="isf-field-input"
                                                       value="<?php echo esc_attr($content['terms_title'] ?? ''); ?>"
                                                       placeholder="<?php esc_attr_e('Terms and Conditions', 'formflow'); ?>">
                                            </div>
                                            <div class="isf-field-group">
                                                <label for="content_terms_checkbox" class="isf-field-label"><?php esc_html_e('Checkbox Label', 'formflow'); ?></label>
                                                <input type="text" id="content_terms_checkbox" name="settings[content][terms_checkbox]" class="isf-field-input"
                                                       value="<?php echo esc_attr($content['terms_checkbox'] ?? ''); ?>"
                                                       placeholder="<?php esc_attr_e('I have read and agree to the Terms and Conditions', 'formflow'); ?>">
                                            </div>
                                            <div class="isf-field-group isf-pod-field-full">
                                                <label for="content_terms_intro" class="isf-field-label"><?php esc_html_e('Introduction', 'formflow'); ?></label>
                                                <textarea id="content_terms_intro" name="settings[content][terms_intro]" class="isf-field-textarea" rows="2"
                                                          placeholder="<?php esc_attr_e('By enrolling in the program, you agree to the following terms:', 'formflow'); ?>"><?php echo esc_textarea($content['terms_intro'] ?? ''); ?></textarea>
                                            </div>
                                            <div class="isf-field-group isf-pod-field-full">
                                                <label for="content_terms_content" class="isf-field-label">
                                                    <?php esc_html_e('Content', 'formflow'); ?>
                                                    <span class="isf-label-badge"><?php esc_html_e('HTML', 'formflow'); ?></span>
                                                </label>
                                                <?php
                                                $terms_editor_id = 'content_terms_content';
                                                $terms_content = $content['terms_content'] ?? '';
                                                wp_editor($terms_content, $terms_editor_id, [
                                                    'textarea_name' => 'settings[content][terms_content]',
                                                    'textarea_rows' => 8,
                                                    'media_buttons' => false,
                                                    'teeny' => true,
                                                    'quicktags' => ['buttons' => 'strong,em,ul,ol,li,link'],
                                                ]);
                                                ?>
                                            </div>
                                            <div class="isf-field-group isf-pod-field-full">
                                                <label for="content_terms_footer" class="isf-field-label"><?php esc_html_e('Footer', 'formflow'); ?></label>
                                                <textarea id="content_terms_footer" name="settings[content][terms_footer]" class="isf-field-textarea" rows="2"
                                                          placeholder="<?php esc_attr_e('For complete program details, please visit our website.', 'formflow'); ?>"><?php echo esc_textarea($content['terms_footer'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 6: Email Settings -->
                    <div class="isf-wizard-panel" data-panel="email">
                        <div class="isf-panel-header">
                            <h2><?php esc_html_e('Email Settings', 'formflow'); ?></h2>
                            <p><?php esc_html_e('Configure email notifications and templates.', 'formflow'); ?></p>
                        </div>

                        <div class="isf-panel-body">
                            <div class="isf-pods-grid">
                                <!-- Email Configuration Pod -->
                                <div class="isf-pod isf-pod-primary">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-email-alt"></span><?php esc_html_e('Email Configuration', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <div class="isf-field-group">
                                            <label class="isf-checkbox-label">
                                                <input type="checkbox" name="settings[send_confirmation_email]" value="1"
                                                       <?php checked($instance['settings']['send_confirmation_email'] ?? true); ?>>
                                                <?php esc_html_e('Send confirmation email from this site', 'formflow'); ?>
                                            </label>
                                            <p class="isf-field-help"><?php esc_html_e('Uncheck if IntelliSOURCE sends confirmation emails automatically.', 'formflow'); ?></p>
                                        </div>

                                        <div class="isf-field-group">
                                            <label for="support_email_from" class="isf-field-label"><?php esc_html_e('From Email', 'formflow'); ?></label>
                                            <input type="email" id="support_email_from" name="support_email_from" class="isf-field-input"
                                                   value="<?php echo esc_attr($instance['support_email_from'] ?? ''); ?>"
                                                   placeholder="noreply@example.com">
                                        </div>
                                        <div class="isf-field-group">
                                            <label for="support_email_to" class="isf-field-label"><?php esc_html_e('CC Emails', 'formflow'); ?></label>
                                            <input type="text" id="support_email_to" name="support_email_to" class="isf-field-input"
                                                   value="<?php echo esc_attr($instance['support_email_to'] ?? ''); ?>"
                                                   placeholder="admin@example.com, support@example.com">
                                        </div>
                                    </div>
                                </div>

                                <!-- Template Settings Pod -->
                                <div class="isf-pod">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-welcome-write-blog"></span><?php esc_html_e('Template Settings', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body">
                                        <div class="isf-field-group">
                                            <label for="content_email_subject" class="isf-field-label"><?php esc_html_e('Subject', 'formflow'); ?></label>
                                            <input type="text" id="content_email_subject" name="settings[content][email_subject]" class="isf-field-input"
                                                   value="<?php echo esc_attr($content['email_subject'] ?? ''); ?>"
                                                   placeholder="<?php esc_attr_e('Your {program_name} Enrollment Confirmation', 'formflow'); ?>">
                                        </div>

                                        <div class="isf-field-group">
                                            <label for="content_email_heading" class="isf-field-label"><?php esc_html_e('Heading', 'formflow'); ?></label>
                                            <input type="text" id="content_email_heading" name="settings[content][email_heading]" class="isf-field-input"
                                                   value="<?php echo esc_attr($content['email_heading'] ?? ''); ?>"
                                                   placeholder="<?php esc_attr_e('Thank You for Enrolling!', 'formflow'); ?>">
                                        </div>

                                        <div class="isf-field-group">
                                            <label for="content_email_footer" class="isf-field-label"><?php esc_html_e('Footer', 'formflow'); ?></label>
                                            <textarea id="content_email_footer" name="settings[content][email_footer]" class="isf-field-textarea" rows="2"
                                                      placeholder="<?php esc_attr_e('Thank you for helping us build a more reliable energy grid!', 'formflow'); ?>"><?php echo esc_textarea($content['email_footer'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- Email Body Pod -->
                                <div class="isf-pod isf-pod-full">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-text-page"></span><?php esc_html_e('Email Body', 'formflow'); ?></h3>
                                        <span class="isf-pod-badge"><?php esc_html_e('{name}, {email}, {phone}, {device}, {date}, {time}, {confirmation_number}', 'formflow'); ?></span>
                                    </div>
                                    <div class="isf-pod-body">
                                        <div class="isf-field-group">
                                            <label for="content_email_body" class="isf-field-label">
                                                <?php esc_html_e('Body Content', 'formflow'); ?>
                                                <span class="isf-label-badge"><?php esc_html_e('HTML', 'formflow'); ?></span>
                                            </label>
                                            <?php
                                            $email_editor_id = 'content_email_body';
                                            $email_content = $content['email_body'] ?? '';
                                            wp_editor($email_content, $email_editor_id, [
                                                'textarea_name' => 'settings[content][email_body]',
                                                'textarea_rows' => 10,
                                                'media_buttons' => false,
                                                'teeny' => true,
                                                'quicktags' => ['buttons' => 'strong,em,ul,ol,li,link'],
                                            ]);
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 7: Features -->
                    <div class="isf-wizard-panel" data-panel="features">
                        <div class="isf-panel-header">
                            <h2><?php esc_html_e('Advanced Features', 'formflow'); ?></h2>
                            <p><?php esc_html_e('Enable and configure additional features.', 'formflow'); ?></p>
                        </div>

                        <div class="isf-panel-body">
                            <div class="isf-pods-grid">
                                <div class="isf-pod isf-pod-full">
                                    <div class="isf-pod-header">
                                        <h3><span class="dashicons dashicons-admin-plugins"></span><?php esc_html_e('Feature Toggles', 'formflow'); ?></h3>
                                    </div>
                                    <div class="isf-pod-body isf-pod-body-features">
                                        <?php include ISF_PLUGIN_DIR . 'admin/views/partials/features-settings.php'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Wizard Navigation Buttons -->
                <div class="isf-wizard-footer">
                    <div class="isf-wizard-footer-left">
                        <button type="button" id="isf-wizard-prev" class="button button-secondary" style="display: none;">
                            <span class="dashicons dashicons-arrow-left-alt2"></span>
                            <?php esc_html_e('Previous', 'formflow'); ?>
                        </button>
                    </div>
                    <div class="isf-wizard-footer-center">
                        <span class="isf-wizard-progress">
                            <span id="isf-wizard-progress-text"><?php esc_html_e('Step 1 of 7', 'formflow'); ?></span>
                        </span>
                    </div>
                    <div class="isf-wizard-footer-right">
                        <button type="button" id="isf-wizard-next" class="button button-primary">
                            <?php esc_html_e('Next', 'formflow'); ?>
                            <span class="dashicons dashicons-arrow-right-alt2"></span>
                        </button>
                        <button type="submit" id="isf-wizard-save" class="button button-primary" style="display: none;">
                            <span class="dashicons dashicons-saved"></span>
                            <?php echo $is_edit ? esc_html__('Save Changes', 'formflow') : esc_html__('Create Form', 'formflow'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Right Sidebar -->
            <div class="isf-editor-sidebar">
                <div class="isf-sidebar-card isf-publish-card">
                    <h3><?php esc_html_e('Publish', 'formflow'); ?></h3>

                    <div class="isf-publish-status">
                        <label class="isf-status-toggle">
                            <input type="checkbox" name="is_active" value="1" <?php checked($instance['is_active'] ?? true); ?>>
                            <span class="isf-status-slider"></span>
                            <span class="isf-status-label"><?php esc_html_e('Active', 'formflow'); ?></span>
                        </label>
                    </div>

                    <div class="isf-publish-actions">
                        <button type="submit" class="button button-primary button-large isf-save-btn">
                            <?php echo $is_edit ? esc_html__('Save Changes', 'formflow') : esc_html__('Create Form', 'formflow'); ?>
                        </button>
                        <?php if ($is_edit) : ?>
                        <button type="button" class="button button-link-delete isf-delete-instance" data-id="<?php echo esc_attr($instance['id']); ?>">
                            <?php esc_html_e('Delete', 'formflow'); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($is_edit) : ?>
                <div class="isf-sidebar-card">
                    <h3><?php esc_html_e('Shortcode', 'formflow'); ?></h3>
                    <code class="isf-shortcode-display" onclick="navigator.clipboard.writeText(this.innerText)" title="<?php esc_attr_e('Click to copy', 'formflow'); ?>">
                        [isf_form instance="<?php echo esc_attr($instance['slug']); ?>"]
                    </code>
                    <p class="isf-card-help"><?php esc_html_e('Click to copy', 'formflow'); ?></p>
                </div>

                <div class="isf-sidebar-card">
                    <h3><?php esc_html_e('Quick Actions', 'formflow'); ?></h3>
                    <div class="isf-quick-actions">
                        <button type="button" id="isf-preview-form" class="button">
                            <span class="dashicons dashicons-visibility"></span>
                            <?php esc_html_e('Preview', 'formflow'); ?>
                        </button>
                        <button type="button" id="isf-duplicate-form" class="button" data-id="<?php echo esc_attr($instance['id']); ?>">
                            <span class="dashicons dashicons-admin-page"></span>
                            <?php esc_html_e('Duplicate', 'formflow'); ?>
                        </button>
                    </div>
                </div>

                <div class="isf-sidebar-card">
                    <h3><?php esc_html_e('Statistics', 'formflow'); ?></h3>
                    <?php
                    $db = new \ISF\Database\Database();
                    $instance_stats = $db->get_statistics($instance['id']);
                    ?>
                    <div class="isf-mini-stats">
                        <div class="isf-mini-stat">
                            <span class="isf-mini-stat-value"><?php echo esc_html($instance_stats['total']); ?></span>
                            <span class="isf-mini-stat-label"><?php esc_html_e('Total', 'formflow'); ?></span>
                        </div>
                        <div class="isf-mini-stat">
                            <span class="isf-mini-stat-value"><?php echo esc_html($instance_stats['completed']); ?></span>
                            <span class="isf-mini-stat-label"><?php esc_html_e('Completed', 'formflow'); ?></span>
                        </div>
                        <div class="isf-mini-stat">
                            <span class="isf-mini-stat-value"><?php echo esc_html($instance_stats['completion_rate']); ?>%</span>
                            <span class="isf-mini-stat-label"><?php esc_html_e('Rate', 'formflow'); ?></span>
                        </div>
                    </div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=isf-data&instance_id=' . $instance['id'])); ?>" class="isf-view-all-link">
                        <?php esc_html_e('View All Submissions', 'formflow'); ?> &rarr;
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    var wizardSteps = ['basics', 'api', 'fields', 'scheduling', 'content', 'email', 'features'];
    var currentStepIndex = 0;
    var isQuickEditMode = $('#isf-quick-edit-toggle').is(':checked');

    // Stamp each panel with an anchor id so the Quick Edit nav can deep-link.
    $('.isf-wizard-panel').each(function() {
        var panel = $(this).data('panel');
        if (panel && !this.id) {
            this.id = 'isf-panel-' + panel;
        }
    });

    // Quick Edit nav: smooth-scroll + active state.
    $('.isf-quick-nav').on('click', '.isf-quick-nav-link', function(e) {
        var target = $(this).attr('href');
        var $t = $(target);
        if ($t.length) {
            e.preventDefault();
            $('html, body').animate({ scrollTop: $t.offset().top - 80 }, 240);
            history.replaceState(null, '', target);
        }
    });

    // Highlight the nav link for whatever panel is currently in view.
    function updateQuickNavActive() {
        if (!$('.isf-wizard-form').hasClass('isf-quick-edit-mode')) return;
        var scrollY = window.pageYOffset + 120;
        var current = null;
        $('.isf-wizard-panel').each(function() {
            if ($(this).offset().top <= scrollY) current = $(this).data('panel');
        });
        if (current) {
            $('.isf-quick-nav-link').removeClass('is-active');
            $('.isf-quick-nav-link[data-target="' + current + '"]').addClass('is-active');
        }
    }
    $(window).on('scroll', updateQuickNavActive);

    // Initialize wizard
    function initWizard() {
        updateWizardUI();

        // Handle quick edit toggle
        $('#isf-quick-edit-toggle').on('change', function() {
            isQuickEditMode = $(this).is(':checked');
            $('.isf-wizard-form').toggleClass('isf-quick-edit-mode', isQuickEditMode);

            if (isQuickEditMode) {
                // Show all panels
                $('.isf-wizard-panel').addClass('active');
                $('.isf-wizard-nav').hide();
                $('.isf-wizard-footer').hide();
            } else {
                // Show only current panel
                $('.isf-wizard-panel').removeClass('active');
                $('.isf-wizard-panel[data-panel="' + wizardSteps[currentStepIndex] + '"]').addClass('active');
                $('.isf-wizard-nav').show();
                $('.isf-wizard-footer').show();
            }
            updateWizardUI();
        });

        // Initialize on load
        if (isQuickEditMode) {
            $('#isf-quick-edit-toggle').trigger('change');
        }
    }

    // Update wizard UI based on current step
    function updateWizardUI() {
        if (isQuickEditMode) return;

        var currentStep = wizardSteps[currentStepIndex];

        // Update step navigation
        $('.isf-wizard-step').removeClass('active completed');
        wizardSteps.forEach(function(step, index) {
            var $step = $('.isf-wizard-step[data-step="' + step + '"]');
            if (index < currentStepIndex) {
                $step.addClass('completed');
            } else if (index === currentStepIndex) {
                $step.addClass('active');
            }
        });

        // Update panels
        $('.isf-wizard-panel').removeClass('active');
        $('.isf-wizard-panel[data-panel="' + currentStep + '"]').addClass('active');

        // Update buttons
        $('#isf-wizard-prev').toggle(currentStepIndex > 0);
        $('#isf-wizard-next').toggle(currentStepIndex < wizardSteps.length - 1);
        $('#isf-wizard-save').toggle(currentStepIndex === wizardSteps.length - 1);

        // Update progress text
        $('#isf-wizard-progress-text').text('<?php esc_html_e('Step', 'formflow'); ?> ' + (currentStepIndex + 1) + ' <?php esc_html_e('of', 'formflow'); ?> ' + wizardSteps.length);

        // Update hidden input
        $('#isf-current-step').val(currentStep);
    }

    // Navigate to step
    function goToStep(stepIndex) {
        if (stepIndex >= 0 && stepIndex < wizardSteps.length) {
            currentStepIndex = stepIndex;
            updateWizardUI();

            // Scroll to top of editor
            $('.isf-editor-main').scrollTop(0);
        }
    }

    // Next button
    $('#isf-wizard-next').on('click', function() {
        // Validate current step before proceeding
        if (validateCurrentStep()) {
            goToStep(currentStepIndex + 1);
        }
    });

    // Previous button
    $('#isf-wizard-prev').on('click', function() {
        goToStep(currentStepIndex - 1);
    });

    // Click on step navigation
    $('.isf-wizard-step').on('click', function() {
        var stepId = $(this).data('step');
        var stepIndex = wizardSteps.indexOf(stepId);
        if (stepIndex !== -1) {
            goToStep(stepIndex);
        }
    });

    // Basic validation for current step
    function validateCurrentStep() {
        var currentStep = wizardSteps[currentStepIndex];
        var $panel = $('.isf-wizard-panel[data-panel="' + currentStep + '"]');
        var isValid = true;

        $panel.find('[required]').each(function() {
            if (!$(this).val()) {
                $(this).addClass('isf-field-error');
                isValid = false;
            } else {
                $(this).removeClass('isf-field-error');
            }
        });

        if (!isValid) {
            $panel.find('.isf-field-error').first().focus();
        }

        return isValid;
    }

    // Auto-fill settings when utility changes
    $('#utility').on('change', function() {
        var $selected = $(this).find(':selected');
        var endpoint = $selected.data('endpoint');
        var emailFrom = $selected.data('email-from');
        var emailTo = $selected.data('email-to');

        if (endpoint) $('#api_endpoint').val(endpoint);
        if (emailFrom) $('#support_email_from').val(emailFrom);
        if (emailTo) $('#support_email_to').val(emailTo);
    });

    // Auto-generate slug from name
    <?php if (!$is_edit) : ?>
    $('#name').on('input', function() {
        var slug = $(this).val()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
        $('#slug').val(slug);
    });
    <?php endif; ?>

    // Toggle demo accounts display
    $('input[name="demo_mode"]').on('change', function() {
        $('#isf-demo-accounts').toggle(this.checked);
    });

    // Toggle external settings based on form type
    $('#form_type').on('change', function() {
        var isExternal = $(this).val() === 'external';
        $('#isf-external-settings').toggle(isExternal);

        // Make external URL required only when external type is selected
        $('#external_url').prop('required', isExternal);

        // Hide API and Fields steps for external type (they're not needed)
        if (isExternal) {
            $('.isf-wizard-step[data-step="api"], .isf-wizard-step[data-step="fields"], .isf-wizard-step[data-step="scheduling"]').addClass('isf-step-disabled');
        } else {
            $('.isf-wizard-step[data-step="api"], .isf-wizard-step[data-step="fields"], .isf-wizard-step[data-step="scheduling"]').removeClass('isf-step-disabled');
        }
    }).trigger('change');

    // Blocked dates management
    var blockedDateIndex = <?php echo !empty($blocked_dates) ? max(array_keys($blocked_dates)) + 1 : 0; ?>;

    $('#isf-add-blocked-date').on('click', function() {
        var html = '<div class="isf-blocked-date-row isf-sortable-item" data-index="' + blockedDateIndex + '">' +
            '<span class="isf-drag-handle" title="<?php echo esc_js(__('Drag to reorder', 'formflow')); ?>">' +
            '<span class="dashicons dashicons-menu"></span></span>' +
            '<input type="date" name="settings[scheduling][blocked_dates][' + blockedDateIndex + '][date]" class="isf-date-input" required>' +
            '<input type="text" name="settings[scheduling][blocked_dates][' + blockedDateIndex + '][label]" placeholder="<?php echo esc_js(__('e.g., Christmas Day', 'formflow')); ?>" class="regular-text">' +
            '<button type="button" class="button isf-remove-blocked-date"><span class="dashicons dashicons-trash"></span></button>' +
            '</div>';
        $('#isf-blocked-dates-list').append(html);
        blockedDateIndex++;
    });

    $(document).on('click', '.isf-remove-blocked-date', function() {
        $(this).closest('.isf-blocked-date-row').remove();
    });

    // Toggle capacity limits inputs
    $('input[name="settings[scheduling][capacity_limits][enabled]"]').on('change', function() {
        $('.isf-capacity-inputs').toggle(this.checked);
    });

    // Toggle maintenance inputs
    $('input[name="settings[maintenance][enabled]"]').on('change', function() {
        $('.isf-maintenance-inputs').toggle(this.checked);
    });

    <?php if ($is_edit) : ?>
    // Duplicate form handler
    $('#isf-duplicate-form').on('click', function() {
        var $btn = $(this);
        var instanceId = $btn.data('id');

        if (!confirm('<?php echo esc_js(__('Create a copy of this form?', 'formflow')); ?>')) {
            return;
        }

        $btn.prop('disabled', true);

        $.ajax({
            url: isf_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_duplicate_instance',
                nonce: isf_admin.nonce,
                id: instanceId
            },
            success: function(response) {
                if (response.success) {
                    window.location.href = '<?php echo esc_url(admin_url('admin.php?page=isf-instance-editor&id=')); ?>' + response.data.new_id;
                } else {
                    alert(response.data.message || '<?php echo esc_js(__('Failed to duplicate form.', 'formflow')); ?>');
                    $btn.prop('disabled', false);
                }
            },
            error: function() {
                alert('<?php echo esc_js(__('Failed to duplicate form.', 'formflow')); ?>');
                $btn.prop('disabled', false);
            }
        });
    });

    // Preview form handler
    $('#isf-preview-form').on('click', function() {
        window.open('<?php echo esc_url(add_query_arg(['isf_preview' => '1', 'instance' => $instance['slug']], home_url('/'))); ?>', '_blank');
    });
    <?php endif; ?>

    // Initialize
    initWizard();
});
</script>
