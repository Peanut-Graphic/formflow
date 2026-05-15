<?php
/**
 * White-Label SaaS Admin View
 *
 * Multi-tenant white-label management interface
 *
 * @package FormFlow
 * @since 2.0.0
 */

namespace ISF\Admin\Views;

if (!defined('ABSPATH')) {
    exit;
}

// SECURITY: Check user capabilities
if (!current_user_can('manage_options')) {
    wp_die(
        esc_html__('You do not have sufficient permissions to access this page.', 'formflow'),
        esc_html__('Permission Denied', 'formflow'),
        ['response' => 403]
    );
}

// Get white-label instance
$white_label = new \ISF\Platform\WhiteLabel();

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'isf_white_label')) {
    $action = sanitize_text_field($_POST['wl_action'] ?? '');

    switch ($action) {
        case 'create_tenant':
            $result = $white_label->create_tenant([
                'name' => sanitize_text_field($_POST['tenant_name'] ?? ''),
                'slug' => sanitize_title($_POST['tenant_slug'] ?? ''),
                'domain' => sanitize_text_field($_POST['tenant_domain'] ?? ''),
                'contact_email' => sanitize_email($_POST['tenant_email'] ?? ''),
                'tier' => sanitize_text_field($_POST['tenant_tier'] ?? 'starter')
            ]);

            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $message_type = 'error';
            } else {
                $message = __('Tenant created successfully.', 'formflow');
                $message_type = 'success';
            }
            break;

        case 'create_branding':
            $tenant_id = intval($_POST['branding_tenant_id'] ?? 0);
            $result = $white_label->create_branding_profile([
                'tenant_id' => $tenant_id,
                'name' => sanitize_text_field($_POST['branding_name'] ?? ''),
                'primary_color' => sanitize_hex_color($_POST['primary_color'] ?? '#0073aa'),
                'secondary_color' => sanitize_hex_color($_POST['secondary_color'] ?? '#23282d'),
                'accent_color' => sanitize_hex_color($_POST['accent_color'] ?? '#00a0d2'),
                'logo_url' => esc_url_raw($_POST['logo_url'] ?? ''),
                'favicon_url' => esc_url_raw($_POST['favicon_url'] ?? ''),
                'custom_css' => wp_strip_all_tags($_POST['custom_css'] ?? '')
            ]);

            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $message_type = 'error';
            } else {
                $message = __('Branding profile created successfully.', 'formflow');
                $message_type = 'success';
            }
            break;

        case 'update_tenant_status':
            $tenant_id = intval($_POST['tenant_id'] ?? 0);
            $new_status = sanitize_text_field($_POST['new_status'] ?? '');

            global $wpdb;
            $table = $wpdb->prefix . 'isf_tenants';
            $updated = $wpdb->update(
                $table,
                ['status' => $new_status, 'updated_at' => current_time('mysql')],
                ['id' => $tenant_id],
                ['%s', '%s'],
                ['%d']
            );

            if ($updated !== false) {
                $message = __('Tenant status updated.', 'formflow');
                $message_type = 'success';
            } else {
                $message = __('Failed to update tenant status.', 'formflow');
                $message_type = 'error';
            }
            break;
    }
}

// Get current tab
$current_tab = sanitize_text_field($_GET['tab'] ?? 'tenants');

// Get data based on tab
global $wpdb;
$tenants = [];
$branding_profiles = [];
$usage_stats = [];

if ($current_tab === 'tenants' || $current_tab === 'overview') {
    $tenants = $wpdb->get_results(
        "SELECT t.*,
                (SELECT COUNT(*) FROM {$wpdb->prefix}isf_tenant_clients WHERE tenant_id = t.id) as client_count,
                (SELECT COUNT(*) FROM {$wpdb->prefix}isf_branding_profiles WHERE tenant_id = t.id) as branding_count
         FROM {$wpdb->prefix}isf_tenants t
         ORDER BY t.created_at DESC"
    );
}

if ($current_tab === 'branding') {
    $branding_profiles = $wpdb->get_results(
        "SELECT bp.*, t.name as tenant_name
         FROM {$wpdb->prefix}isf_branding_profiles bp
         LEFT JOIN {$wpdb->prefix}isf_tenants t ON bp.tenant_id = t.id
         ORDER BY bp.created_at DESC"
    );
}

if ($current_tab === 'usage') {
    $usage_stats = $wpdb->get_results(
        "SELECT tu.*, t.name as tenant_name, t.tier
         FROM {$wpdb->prefix}isf_tenant_usage tu
         JOIN {$wpdb->prefix}isf_tenants t ON tu.tenant_id = t.id
         WHERE tu.period_start >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
         ORDER BY tu.period_start DESC"
    );
}

$tabs = [
    'overview' => __('Overview', 'formflow'),
    'tenants' => __('Tenants', 'formflow'),
    'branding' => __('Branding', 'formflow'),
    'usage' => __('Usage & Billing', 'formflow'),
    'settings' => __('Settings', 'formflow')
];
?>

<div class="wrap isf-white-label">
    <h1><?php esc_html_e('White-Label Management', 'formflow'); ?></h1>

    <?php if ($message): ?>
        <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper">
        <?php foreach ($tabs as $tab_id => $tab_label): ?>
            <a href="<?php echo esc_url(add_query_arg('tab', $tab_id)); ?>"
               class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($tab_label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="tab-content" style="margin-top: 20px;">

        <?php if ($current_tab === 'overview'): ?>
        <!-- Overview Tab -->
        <div class="isf-dashboard-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
            <div class="isf-stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 10px; color: #666; font-size: 14px; text-transform: uppercase;"><?php esc_html_e('Total Tenants', 'formflow'); ?></h3>
                <div style="font-size: 36px; font-weight: bold; color: #0073aa;"><?php echo count($tenants); ?></div>
                <div style="color: #666; font-size: 12px; margin-top: 5px;">
                    <?php
                    $active = array_filter($tenants, fn($t) => $t->status === 'active');
                    echo sprintf(__('%d active', 'formflow'), count($active));
                    ?>
                </div>
            </div>

            <div class="isf-stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 10px; color: #666; font-size: 14px; text-transform: uppercase;"><?php esc_html_e('Total Clients', 'formflow'); ?></h3>
                <div style="font-size: 36px; font-weight: bold; color: #00a32a;">
                    <?php echo array_sum(array_column($tenants, 'client_count')); ?>
                </div>
                <div style="color: #666; font-size: 12px; margin-top: 5px;">
                    <?php esc_html_e('Across all tenants', 'formflow'); ?>
                </div>
            </div>

            <div class="isf-stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 10px; color: #666; font-size: 14px; text-transform: uppercase;"><?php esc_html_e('Branding Profiles', 'formflow'); ?></h3>
                <div style="font-size: 36px; font-weight: bold; color: #9b59b6;">
                    <?php echo array_sum(array_column($tenants, 'branding_count')); ?>
                </div>
                <div style="color: #666; font-size: 12px; margin-top: 5px;">
                    <?php esc_html_e('Custom themes', 'formflow'); ?>
                </div>
            </div>

            <div class="isf-stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 10px; color: #666; font-size: 14px; text-transform: uppercase;"><?php esc_html_e('Tier Distribution', 'formflow'); ?></h3>
                <?php
                $tiers = array_count_values(array_column($tenants, 'tier'));
                foreach (['enterprise' => 'Enterprise', 'professional' => 'Professional', 'starter' => 'Starter'] as $tier_key => $tier_label):
                    $count = $tiers[$tier_key] ?? 0;
                ?>
                <div style="display: flex; justify-content: space-between; margin: 5px 0;">
                    <span style="font-size: 12px;"><?php echo esc_html($tier_label); ?></span>
                    <span style="font-weight: bold;"><?php echo intval($count); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="isf-recent-activity" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin: 0 0 15px;"><?php esc_html_e('Recent Tenants', 'formflow'); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Tenant', 'formflow'); ?></th>
                        <th><?php esc_html_e('Tier', 'formflow'); ?></th>
                        <th><?php esc_html_e('Status', 'formflow'); ?></th>
                        <th><?php esc_html_e('Clients', 'formflow'); ?></th>
                        <th><?php esc_html_e('Created', 'formflow'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($tenants, 0, 5) as $tenant): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($tenant->name); ?></strong>
                            <?php if ($tenant->domain): ?>
                            <br><small style="color: #666;"><?php echo esc_html($tenant->domain); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="isf-tier-badge isf-tier-<?php echo esc_attr($tenant->tier); ?>">
                                <?php echo esc_html(ucfirst($tenant->tier)); ?>
                            </span>
                        </td>
                        <td>
                            <span class="isf-status-badge isf-status-<?php echo esc_attr($tenant->status); ?>">
                                <?php echo esc_html(ucfirst($tenant->status)); ?>
                            </span>
                        </td>
                        <td><?php echo intval($tenant->client_count); ?></td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($tenant->created_at))); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($tenants)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px;">
                            <?php esc_html_e('No tenants yet. Create your first tenant to get started.', 'formflow'); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($current_tab === 'tenants'): ?>
        <!-- Tenants Tab -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0;"><?php esc_html_e('Manage Tenants', 'formflow'); ?></h2>
            <button type="button" class="button button-primary" id="add-tenant-btn">
                <span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span>
                <?php esc_html_e('Add Tenant', 'formflow'); ?>
            </button>
        </div>

        <!-- Tenant List -->
        <div class="isf-tenant-list" style="background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 25%;"><?php esc_html_e('Tenant', 'formflow'); ?></th>
                        <th style="width: 15%;"><?php esc_html_e('Tier', 'formflow'); ?></th>
                        <th style="width: 10%;"><?php esc_html_e('Status', 'formflow'); ?></th>
                        <th style="width: 10%;"><?php esc_html_e('Clients', 'formflow'); ?></th>
                        <th style="width: 20%;"><?php esc_html_e('API Key', 'formflow'); ?></th>
                        <th style="width: 20%;"><?php esc_html_e('Actions', 'formflow'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tenants as $tenant): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($tenant->name); ?></strong>
                            <br><code style="font-size: 11px;"><?php echo esc_html($tenant->slug); ?></code>
                            <?php if ($tenant->domain): ?>
                            <br><small style="color: #666;"><?php echo esc_html($tenant->domain); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="isf-tier-badge isf-tier-<?php echo esc_attr($tenant->tier); ?>">
                                <?php echo esc_html(ucfirst($tenant->tier)); ?>
                            </span>
                        </td>
                        <td>
                            <span class="isf-status-badge isf-status-<?php echo esc_attr($tenant->status); ?>">
                                <?php echo esc_html(ucfirst($tenant->status)); ?>
                            </span>
                        </td>
                        <td><?php echo intval($tenant->client_count); ?></td>
                        <td>
                            <code style="font-size: 11px; word-break: break-all;">
                                <?php echo esc_html(substr($tenant->api_key, 0, 15) . '...'); ?>
                            </code>
                            <button type="button" class="button button-small copy-api-key"
                                    data-key="<?php echo esc_attr($tenant->api_key); ?>"
                                    title="<?php esc_attr_e('Copy API Key', 'formflow'); ?>">
                                <span class="dashicons dashicons-clipboard" style="font-size: 14px;"></span>
                            </button>
                        </td>
                        <td>
                            <div class="row-actions visible">
                                <button type="button" class="button button-small edit-tenant"
                                        data-tenant='<?php echo esc_attr(wp_json_encode($tenant)); ?>'>
                                    <?php esc_html_e('Edit', 'formflow'); ?>
                                </button>
                                <button type="button" class="button button-small view-clients"
                                        data-tenant-id="<?php echo intval($tenant->id); ?>">
                                    <?php esc_html_e('Clients', 'formflow'); ?>
                                </button>
                                <?php if ($tenant->status === 'active'): ?>
                                <form method="post" style="display: inline;">
                                    <?php wp_nonce_field('isf_white_label'); ?>
                                    <input type="hidden" name="wl_action" value="update_tenant_status">
                                    <input type="hidden" name="tenant_id" value="<?php echo intval($tenant->id); ?>">
                                    <input type="hidden" name="new_status" value="suspended">
                                    <button type="submit" class="button button-small"
                                            onclick="return confirm('<?php esc_attr_e('Suspend this tenant?', 'formflow'); ?>')">
                                        <?php esc_html_e('Suspend', 'formflow'); ?>
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="post" style="display: inline;">
                                    <?php wp_nonce_field('isf_white_label'); ?>
                                    <input type="hidden" name="wl_action" value="update_tenant_status">
                                    <input type="hidden" name="tenant_id" value="<?php echo intval($tenant->id); ?>">
                                    <input type="hidden" name="new_status" value="active">
                                    <button type="submit" class="button button-small button-primary">
                                        <?php esc_html_e('Activate', 'formflow'); ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($tenants)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 60px;">
                            <div style="color: #666;">
                                <span class="dashicons dashicons-building" style="font-size: 48px; width: 48px; height: 48px;"></span>
                                <h3><?php esc_html_e('No Tenants Yet', 'formflow'); ?></h3>
                                <p><?php esc_html_e('Create your first tenant to start white-labeling.', 'formflow'); ?></p>
                                <button type="button" class="button button-primary" id="add-tenant-btn-empty">
                                    <?php esc_html_e('Add First Tenant', 'formflow'); ?>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Add Tenant Modal -->
        <div id="add-tenant-modal" class="isf-modal" style="display: none;">
            <div class="isf-modal-content" style="max-width: 600px;">
                <div class="isf-modal-header">
                    <h2><?php esc_html_e('Add New Tenant', 'formflow'); ?></h2>
                    <button type="button" class="isf-modal-close">&times;</button>
                </div>
                <form method="post">
                    <?php wp_nonce_field('isf_white_label'); ?>
                    <input type="hidden" name="wl_action" value="create_tenant">

                    <div class="isf-modal-body" style="padding: 20px;">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="tenant_name"><?php esc_html_e('Tenant Name', 'formflow'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="text" name="tenant_name" id="tenant_name" class="regular-text" required>
                                    <p class="description"><?php esc_html_e('Company or organization name', 'formflow'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="tenant_slug"><?php esc_html_e('Slug', 'formflow'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="text" name="tenant_slug" id="tenant_slug" class="regular-text" required pattern="[a-z0-9-]+">
                                    <p class="description"><?php esc_html_e('Lowercase, alphanumeric with dashes only', 'formflow'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="tenant_domain"><?php esc_html_e('Custom Domain', 'formflow'); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="tenant_domain" id="tenant_domain" class="regular-text" placeholder="forms.example.com">
                                    <p class="description"><?php esc_html_e('Optional custom domain for white-label access', 'formflow'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="tenant_email"><?php esc_html_e('Contact Email', 'formflow'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="email" name="tenant_email" id="tenant_email" class="regular-text" required>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="tenant_tier"><?php esc_html_e('Subscription Tier', 'formflow'); ?></label>
                                </th>
                                <td>
                                    <select name="tenant_tier" id="tenant_tier" class="regular-text">
                                        <option value="starter"><?php esc_html_e('Starter - 1,000 submissions/mo, 5 clients', 'formflow'); ?></option>
                                        <option value="professional"><?php esc_html_e('Professional - 10,000 submissions/mo, 25 clients', 'formflow'); ?></option>
                                        <option value="enterprise"><?php esc_html_e('Enterprise - Unlimited', 'formflow'); ?></option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="isf-modal-footer" style="padding: 15px 20px; border-top: 1px solid #ddd; text-align: right;">
                        <button type="button" class="button isf-modal-cancel"><?php esc_html_e('Cancel', 'formflow'); ?></button>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Create Tenant', 'formflow'); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <?php elseif ($current_tab === 'branding'): ?>
        <!-- Branding Tab -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0;"><?php esc_html_e('Branding Profiles', 'formflow'); ?></h2>
            <button type="button" class="button button-primary" id="add-branding-btn">
                <span class="dashicons dashicons-art" style="vertical-align: middle;"></span>
                <?php esc_html_e('Create Branding Profile', 'formflow'); ?>
            </button>
        </div>

        <div class="isf-branding-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px;">
            <?php foreach ($branding_profiles as $profile):
                $settings = json_decode($profile->settings, true) ?: [];
            ?>
            <div class="isf-branding-card" style="background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden;">
                <!-- Preview Header -->
                <div class="branding-preview" style="background: linear-gradient(135deg, <?php echo esc_attr($settings['primary_color'] ?? '#0073aa'); ?>, <?php echo esc_attr($settings['secondary_color'] ?? '#23282d'); ?>); padding: 30px; color: #fff; text-align: center;">
                    <?php if (!empty($settings['logo_url'])): ?>
                    <img src="<?php echo esc_url($settings['logo_url']); ?>" alt="Logo" style="max-height: 40px; margin-bottom: 10px;">
                    <?php endif; ?>
                    <h3 style="margin: 0; color: #fff;"><?php echo esc_html($profile->name); ?></h3>
                    <small style="opacity: 0.8;"><?php echo esc_html($profile->tenant_name ?? 'Global'); ?></small>
                </div>

                <!-- Color Swatches -->
                <div style="display: flex; padding: 15px; gap: 10px; border-bottom: 1px solid #eee;">
                    <div style="flex: 1; text-align: center;">
                        <div style="width: 40px; height: 40px; border-radius: 50%; background: <?php echo esc_attr($settings['primary_color'] ?? '#0073aa'); ?>; margin: 0 auto 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"></div>
                        <small style="color: #666;">Primary</small>
                    </div>
                    <div style="flex: 1; text-align: center;">
                        <div style="width: 40px; height: 40px; border-radius: 50%; background: <?php echo esc_attr($settings['secondary_color'] ?? '#23282d'); ?>; margin: 0 auto 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"></div>
                        <small style="color: #666;">Secondary</small>
                    </div>
                    <div style="flex: 1; text-align: center;">
                        <div style="width: 40px; height: 40px; border-radius: 50%; background: <?php echo esc_attr($settings['accent_color'] ?? '#00a0d2'); ?>; margin: 0 auto 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"></div>
                        <small style="color: #666;">Accent</small>
                    </div>
                </div>

                <!-- Card Footer -->
                <div style="padding: 15px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <?php if ($profile->is_default): ?>
                        <span class="isf-badge" style="background: #00a32a; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px;">
                            <?php esc_html_e('Default', 'formflow'); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <button type="button" class="button button-small edit-branding"
                                data-profile='<?php echo esc_attr(wp_json_encode($profile)); ?>'>
                            <?php esc_html_e('Edit', 'formflow'); ?>
                        </button>
                        <button type="button" class="button button-small preview-branding"
                                data-profile-id="<?php echo intval($profile->id); ?>">
                            <?php esc_html_e('Preview', 'formflow'); ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (empty($branding_profiles)): ?>
            <div style="grid-column: 1 / -1; text-align: center; padding: 60px; background: #fff; border-radius: 8px;">
                <span class="dashicons dashicons-admin-appearance" style="font-size: 48px; width: 48px; height: 48px; color: #666;"></span>
                <h3><?php esc_html_e('No Branding Profiles', 'formflow'); ?></h3>
                <p style="color: #666;"><?php esc_html_e('Create custom branding profiles for your tenants.', 'formflow'); ?></p>
                <button type="button" class="button button-primary" id="add-branding-btn-empty">
                    <?php esc_html_e('Create First Profile', 'formflow'); ?>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Add Branding Modal -->
        <div id="add-branding-modal" class="isf-modal" style="display: none;">
            <div class="isf-modal-content" style="max-width: 700px;">
                <div class="isf-modal-header">
                    <h2><?php esc_html_e('Create Branding Profile', 'formflow'); ?></h2>
                    <button type="button" class="isf-modal-close">&times;</button>
                </div>
                <form method="post">
                    <?php wp_nonce_field('isf_white_label'); ?>
                    <input type="hidden" name="wl_action" value="create_branding">

                    <div class="isf-modal-body" style="padding: 20px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div>
                                <h4 style="margin-top: 0;"><?php esc_html_e('Basic Info', 'formflow'); ?></h4>

                                <p>
                                    <label for="branding_name"><?php esc_html_e('Profile Name', 'formflow'); ?> <span class="required">*</span></label>
                                    <input type="text" name="branding_name" id="branding_name" class="widefat" required>
                                </p>

                                <p>
                                    <label for="branding_tenant_id"><?php esc_html_e('Tenant', 'formflow'); ?></label>
                                    <select name="branding_tenant_id" id="branding_tenant_id" class="widefat">
                                        <option value=""><?php esc_html_e('Global (All Tenants)', 'formflow'); ?></option>
                                        <?php foreach ($tenants as $tenant): ?>
                                        <option value="<?php echo intval($tenant->id); ?>">
                                            <?php echo esc_html($tenant->name); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </p>

                                <p>
                                    <label for="logo_url"><?php esc_html_e('Logo URL', 'formflow'); ?></label>
                                    <input type="url" name="logo_url" id="logo_url" class="widefat" placeholder="https://">
                                    <button type="button" class="button upload-logo" style="margin-top: 5px;">
                                        <?php esc_html_e('Upload Logo', 'formflow'); ?>
                                    </button>
                                </p>

                                <p>
                                    <label for="favicon_url"><?php esc_html_e('Favicon URL', 'formflow'); ?></label>
                                    <input type="url" name="favicon_url" id="favicon_url" class="widefat" placeholder="https://">
                                </p>
                            </div>

                            <div>
                                <h4 style="margin-top: 0;"><?php esc_html_e('Colors', 'formflow'); ?></h4>

                                <p>
                                    <label for="primary_color"><?php esc_html_e('Primary Color', 'formflow'); ?></label>
                                    <input type="color" name="primary_color" id="primary_color" value="#0073aa" style="width: 100%; height: 40px; cursor: pointer;">
                                </p>

                                <p>
                                    <label for="secondary_color"><?php esc_html_e('Secondary Color', 'formflow'); ?></label>
                                    <input type="color" name="secondary_color" id="secondary_color" value="#23282d" style="width: 100%; height: 40px; cursor: pointer;">
                                </p>

                                <p>
                                    <label for="accent_color"><?php esc_html_e('Accent Color', 'formflow'); ?></label>
                                    <input type="color" name="accent_color" id="accent_color" value="#00a0d2" style="width: 100%; height: 40px; cursor: pointer;">
                                </p>
                            </div>
                        </div>

                        <div style="margin-top: 20px;">
                            <h4><?php esc_html_e('Custom CSS', 'formflow'); ?></h4>
                            <textarea name="custom_css" id="custom_css" rows="6" class="widefat" style="font-family: monospace;" placeholder="/* Custom CSS overrides */"></textarea>
                        </div>
                    </div>

                    <div class="isf-modal-footer" style="padding: 15px 20px; border-top: 1px solid #ddd; text-align: right;">
                        <button type="button" class="button isf-modal-cancel"><?php esc_html_e('Cancel', 'formflow'); ?></button>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Create Profile', 'formflow'); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <?php elseif ($current_tab === 'usage'): ?>
        <!-- Usage & Billing Tab -->
        <div style="margin-bottom: 20px;">
            <h2 style="margin: 0 0 10px;"><?php esc_html_e('Usage & Billing', 'formflow'); ?></h2>
            <p style="color: #666; margin: 0;"><?php esc_html_e('Track resource usage across all tenants for billing purposes.', 'formflow'); ?></p>
        </div>

        <!-- Usage Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px;">
            <?php
            // Calculate totals for current period
            $current_period = date('Y-m-01');
            $current_usage = array_filter($usage_stats, fn($u) => $u->period_start === $current_period);
            $total_submissions = array_sum(array_column($current_usage, 'submissions'));
            $total_api_calls = array_sum(array_column($current_usage, 'api_calls'));
            $total_storage = array_sum(array_column($current_usage, 'storage_mb'));
            ?>
            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e('Submissions This Month', 'formflow'); ?></div>
                <div style="font-size: 28px; font-weight: bold; color: #0073aa;"><?php echo number_format($total_submissions); ?></div>
            </div>
            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e('API Calls This Month', 'formflow'); ?></div>
                <div style="font-size: 28px; font-weight: bold; color: #00a32a;"><?php echo number_format($total_api_calls); ?></div>
            </div>
            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e('Storage Used', 'formflow'); ?></div>
                <div style="font-size: 28px; font-weight: bold; color: #9b59b6;"><?php echo number_format($total_storage / 1024, 1); ?> GB</div>
            </div>
        </div>

        <!-- Usage Table -->
        <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Tenant', 'formflow'); ?></th>
                        <th><?php esc_html_e('Tier', 'formflow'); ?></th>
                        <th><?php esc_html_e('Period', 'formflow'); ?></th>
                        <th><?php esc_html_e('Submissions', 'formflow'); ?></th>
                        <th><?php esc_html_e('API Calls', 'formflow'); ?></th>
                        <th><?php esc_html_e('Storage', 'formflow'); ?></th>
                        <th><?php esc_html_e('Status', 'formflow'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usage_stats as $usage):
                        $limits = $white_label->get_tier_limits($usage->tier);
                        $submission_pct = $limits['submissions_per_month'] > 0
                            ? ($usage->submissions / $limits['submissions_per_month']) * 100
                            : 0;
                        $api_pct = $limits['api_calls_per_month'] > 0
                            ? ($usage->api_calls / $limits['api_calls_per_month']) * 100
                            : 0;
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($usage->tenant_name); ?></strong></td>
                        <td>
                            <span class="isf-tier-badge isf-tier-<?php echo esc_attr($usage->tier); ?>">
                                <?php echo esc_html(ucfirst($usage->tier)); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html(date_i18n('M Y', strtotime($usage->period_start))); ?></td>
                        <td>
                            <?php echo number_format($usage->submissions); ?>
                            <?php if ($limits['submissions_per_month'] > 0): ?>
                            <div style="background: #eee; border-radius: 3px; height: 4px; margin-top: 5px;">
                                <div style="background: <?php echo $submission_pct > 90 ? '#dc3545' : ($submission_pct > 70 ? '#ffc107' : '#28a745'); ?>; width: <?php echo min(100, $submission_pct); ?>%; height: 100%; border-radius: 3px;"></div>
                            </div>
                            <small style="color: #666;"><?php echo round($submission_pct, 1); ?>% of <?php echo number_format($limits['submissions_per_month']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo number_format($usage->api_calls); ?>
                            <?php if ($limits['api_calls_per_month'] > 0): ?>
                            <div style="background: #eee; border-radius: 3px; height: 4px; margin-top: 5px;">
                                <div style="background: <?php echo $api_pct > 90 ? '#dc3545' : ($api_pct > 70 ? '#ffc107' : '#28a745'); ?>; width: <?php echo min(100, $api_pct); ?>%; height: 100%; border-radius: 3px;"></div>
                            </div>
                            <small style="color: #666;"><?php echo round($api_pct, 1); ?>% of <?php echo number_format($limits['api_calls_per_month']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo number_format($usage->storage_mb); ?> MB
                            <?php if ($limits['storage_gb'] > 0): ?>
                            <br><small style="color: #666;">of <?php echo $limits['storage_gb']; ?> GB</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($submission_pct > 100 || $api_pct > 100): ?>
                            <span style="color: #dc3545; font-weight: bold;">
                                <span class="dashicons dashicons-warning" style="font-size: 14px;"></span>
                                <?php esc_html_e('Over Limit', 'formflow'); ?>
                            </span>
                            <?php elseif ($submission_pct > 90 || $api_pct > 90): ?>
                            <span style="color: #ffc107;">
                                <span class="dashicons dashicons-info" style="font-size: 14px;"></span>
                                <?php esc_html_e('Near Limit', 'formflow'); ?>
                            </span>
                            <?php else: ?>
                            <span style="color: #28a745;">
                                <span class="dashicons dashicons-yes" style="font-size: 14px;"></span>
                                <?php esc_html_e('OK', 'formflow'); ?>
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($usage_stats)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: #666;">
                            <?php esc_html_e('No usage data available yet.', 'formflow'); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($current_tab === 'settings'): ?>
        <!-- Settings Tab -->
        <div style="max-width: 800px;">
            <h2><?php esc_html_e('White-Label Settings', 'formflow'); ?></h2>

            <form method="post" action="options.php">
                <?php settings_fields('isf_white_label_settings'); ?>

                <div class="card" style="max-width: none; padding: 20px; margin-bottom: 20px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('General Settings', 'formflow'); ?></h3>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="wl_enabled"><?php esc_html_e('Enable White-Label Mode', 'formflow'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="isf_wl_enabled" id="wl_enabled" value="1"
                                           <?php checked(get_option('isf_wl_enabled', false)); ?>>
                                    <?php esc_html_e('Enable multi-tenant white-label functionality', 'formflow'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="wl_platform_name"><?php esc_html_e('Platform Name', 'formflow'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="isf_wl_platform_name" id="wl_platform_name" class="regular-text"
                                       value="<?php echo esc_attr(get_option('isf_wl_platform_name', 'FormFlow')); ?>">
                                <p class="description"><?php esc_html_e('Your branded platform name shown to tenants', 'formflow'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="wl_support_email"><?php esc_html_e('Support Email', 'formflow'); ?></label>
                            </th>
                            <td>
                                <input type="email" name="isf_wl_support_email" id="wl_support_email" class="regular-text"
                                       value="<?php echo esc_attr(get_option('isf_wl_support_email', '')); ?>">
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="card" style="max-width: none; padding: 20px; margin-bottom: 20px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Billing Integration', 'formflow'); ?></h3>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="wl_billing_provider"><?php esc_html_e('Billing Provider', 'formflow'); ?></label>
                            </th>
                            <td>
                                <select name="isf_wl_billing_provider" id="wl_billing_provider" class="regular-text">
                                    <option value=""><?php esc_html_e('Manual / None', 'formflow'); ?></option>
                                    <option value="stripe" <?php selected(get_option('isf_wl_billing_provider'), 'stripe'); ?>><?php esc_html_e('Stripe', 'formflow'); ?></option>
                                    <option value="paddle" <?php selected(get_option('isf_wl_billing_provider'), 'paddle'); ?>><?php esc_html_e('Paddle', 'formflow'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="wl_stripe_key"><?php esc_html_e('Stripe API Key', 'formflow'); ?></label>
                            </th>
                            <td>
                                <input type="password" name="isf_wl_stripe_key" id="wl_stripe_key" class="regular-text"
                                       value="<?php echo esc_attr(get_option('isf_wl_stripe_key', '')); ?>">
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="card" style="max-width: none; padding: 20px; margin-bottom: 20px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Custom Tier Configuration', 'formflow'); ?></h3>
                    <p style="color: #666;"><?php esc_html_e('Customize the limits for each subscription tier.', 'formflow'); ?></p>

                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
                        <?php foreach (['starter', 'professional', 'enterprise'] as $tier):
                            $limits = $white_label->get_tier_limits($tier);
                        ?>
                        <div style="border: 1px solid #ddd; border-radius: 8px; padding: 15px;">
                            <h4 style="margin: 0 0 15px; text-transform: capitalize;"><?php echo esc_html($tier); ?></h4>

                            <p>
                                <label style="font-size: 12px; color: #666;"><?php esc_html_e('Submissions/Month', 'formflow'); ?></label>
                                <input type="number" name="isf_wl_tier_<?php echo $tier; ?>_submissions" class="widefat"
                                       value="<?php echo intval($limits['submissions_per_month']); ?>">
                            </p>
                            <p>
                                <label style="font-size: 12px; color: #666;"><?php esc_html_e('API Calls/Month', 'formflow'); ?></label>
                                <input type="number" name="isf_wl_tier_<?php echo $tier; ?>_api" class="widefat"
                                       value="<?php echo intval($limits['api_calls_per_month']); ?>">
                            </p>
                            <p>
                                <label style="font-size: 12px; color: #666;"><?php esc_html_e('Storage (GB)', 'formflow'); ?></label>
                                <input type="number" name="isf_wl_tier_<?php echo $tier; ?>_storage" class="widefat"
                                       value="<?php echo intval($limits['storage_gb']); ?>">
                            </p>
                            <p>
                                <label style="font-size: 12px; color: #666;"><?php esc_html_e('Max Clients', 'formflow'); ?></label>
                                <input type="number" name="isf_wl_tier_<?php echo $tier; ?>_clients" class="widefat"
                                       value="<?php echo intval($limits['max_clients']); ?>">
                            </p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php submit_button(__('Save Settings', 'formflow')); ?>
            </form>
        </div>

        <?php endif; ?>

    </div>
</div>

<style>
.isf-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.isf-modal-content {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 5px 30px rgba(0,0,0,0.3);
    max-height: 90vh;
    overflow-y: auto;
}

.isf-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #ddd;
}

.isf-modal-header h2 {
    margin: 0;
}

.isf-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}

.isf-modal-close:hover {
    color: #000;
}

.isf-tier-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.isf-tier-starter {
    background: #e3f2fd;
    color: #1565c0;
}

.isf-tier-professional {
    background: #fff3e0;
    color: #ef6c00;
}

.isf-tier-enterprise {
    background: #f3e5f5;
    color: #7b1fa2;
}

.isf-tier-custom {
    background: #fce4ec;
    color: #c2185b;
}

.isf-status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
}

.isf-status-active {
    background: #e8f5e9;
    color: #2e7d32;
}

.isf-status-suspended {
    background: #fff3e0;
    color: #e65100;
}

.isf-status-inactive {
    background: #f5f5f5;
    color: #616161;
}

.required {
    color: #dc3545;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Modal handling
    function openModal(modalId) {
        $('#' + modalId).fadeIn(200);
    }

    function closeModal() {
        $('.isf-modal').fadeOut(200);
    }

    // Add tenant button
    $('#add-tenant-btn, #add-tenant-btn-empty').on('click', function() {
        openModal('add-tenant-modal');
    });

    // Add branding button
    $('#add-branding-btn, #add-branding-btn-empty').on('click', function() {
        openModal('add-branding-modal');
    });

    // Close modal buttons
    $('.isf-modal-close, .isf-modal-cancel').on('click', closeModal);

    // Close on overlay click
    $('.isf-modal').on('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });

    // Auto-generate slug from name
    $('#tenant_name').on('input', function() {
        var slug = $(this).val()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
        $('#tenant_slug').val(slug);
    });

    // Copy API key
    $('.copy-api-key').on('click', function() {
        var key = $(this).data('key');
        navigator.clipboard.writeText(key).then(function() {
            alert('<?php esc_html_e('API key copied to clipboard', 'formflow'); ?>');
        });
    });

    // Media uploader for logo
    $('.upload-logo').on('click', function(e) {
        e.preventDefault();

        var frame = wp.media({
            title: '<?php esc_html_e('Select Logo', 'formflow'); ?>',
            button: { text: '<?php esc_html_e('Use this image', 'formflow'); ?>' },
            multiple: false
        });

        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#logo_url').val(attachment.url);
        });

        frame.open();
    });
});
</script>
