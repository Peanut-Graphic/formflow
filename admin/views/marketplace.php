<?php
/**
 * Marketplace Admin View
 *
 * Browse and install templates, connectors, and add-ons.
 *
 * @package FormFlow
 * @since 2.7.0
 * @status upcoming
 */

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

$marketplace = \ISF\Platform\Marketplace::instance();
// Default tab is Import/Export — that's the only surface backed by real
// (non-fixture) functionality until the marketplace storefront ships.
$active_tab = sanitize_text_field($_GET['tab'] ?? 'import');
$templates = $marketplace->get_templates();
$connectors = $marketplace->get_connectors();

// Storefront templates and connectors are placeholder fixtures until the
// real marketplace ships. By default we hide premium/marketplace-sourced
// cards and show only local + imported templates. To restore the full
// storefront preview: define('ISF_MARKETPLACE_STOREFRONT', true).
$storefront_enabled = defined('ISF_MARKETPLACE_STOREFRONT') && ISF_MARKETPLACE_STOREFRONT;
if (!$storefront_enabled) {
    $templates = array_values(array_filter($templates, function ($t) {
        $source = $t['source'] ?? 'local';
        return in_array($source, ['local', 'imported'], true);
    }));
}

// Group templates by category
$categories = [];
foreach ($templates as $template) {
    $cat = $template['category'] ?: 'general';
    if (!isset($categories[$cat])) {
        $categories[$cat] = [];
    }
    $categories[$cat][] = $template;
}
?>

<div class="wrap isf-admin-wrap">
    <h1>
        <span class="dashicons dashicons-media-document" style="font-size: 28px; width: 28px; height: 28px; margin-right: 8px;"></span>
        <?php esc_html_e('Templates', 'formflow'); ?>
    </h1>

    <p class="description" style="font-size: 14px; margin-bottom: 20px;">
        <?php esc_html_e('Import a form template from a JSON file, or pick a built-in template to start from. (Storefront browsing coming later.)', 'formflow'); ?>
    </p>

    <nav class="nav-tab-wrapper">
        <a href="<?php echo esc_url(add_query_arg('tab', 'templates')); ?>"
           class="nav-tab <?php echo $active_tab === 'templates' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-media-document"></span>
            <?php esc_html_e('Templates', 'formflow'); ?>
            <span class="isf-count"><?php echo count($templates); ?></span>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'connectors')); ?>"
           class="nav-tab <?php echo $active_tab === 'connectors' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-admin-plugins"></span>
            <?php esc_html_e('Connectors', 'formflow'); ?>
            <span class="isf-count"><?php echo count($connectors); ?></span>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'import')); ?>"
           class="nav-tab <?php echo $active_tab === 'import' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-upload"></span>
            <?php esc_html_e('Import/Export', 'formflow'); ?>
        </a>
    </nav>

    <?php if ($active_tab === 'templates') : ?>
        <!-- Templates Tab -->
        <div class="isf-marketplace-header">
            <div class="isf-search-box">
                <input type="text" id="isf-template-search" placeholder="<?php esc_attr_e('Search templates...', 'formflow'); ?>">
            </div>
            <div class="isf-filter-buttons">
                <button type="button" class="button isf-filter-btn active" data-category="all">
                    <?php esc_html_e('All', 'formflow'); ?>
                </button>
                <button type="button" class="button isf-filter-btn" data-category="utility">
                    <?php esc_html_e('Utility', 'formflow'); ?>
                </button>
                <button type="button" class="button isf-filter-btn" data-category="general">
                    <?php esc_html_e('General', 'formflow'); ?>
                </button>
            </div>
        </div>

        <div class="isf-template-grid" id="isf-template-grid">
            <?php foreach ($templates as $template) : ?>
                <div class="isf-template-card" data-category="<?php echo esc_attr($template['category']); ?>" data-slug="<?php echo esc_attr($template['slug']); ?>">
                    <div class="isf-template-header">
                        <?php if ($template['is_premium']) : ?>
                            <span class="isf-badge isf-badge-premium"><?php esc_html_e('Premium', 'formflow'); ?></span>
                        <?php endif; ?>
                        <?php if ($template['source'] === 'imported') : ?>
                            <span class="isf-badge isf-badge-imported"><?php esc_html_e('Imported', 'formflow'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="isf-template-icon">
                        <span class="dashicons dashicons-media-document"></span>
                    </div>
                    <h3><?php echo esc_html($template['name']); ?></h3>
                    <p class="description"><?php echo esc_html($template['description']); ?></p>
                    <div class="isf-template-meta">
                        <span class="isf-template-author">
                            <span class="dashicons dashicons-admin-users"></span>
                            <?php echo esc_html($template['author']); ?>
                        </span>
                        <span class="isf-template-installs">
                            <span class="dashicons dashicons-download"></span>
                            <?php echo esc_html(number_format($template['install_count'])); ?>
                        </span>
                    </div>
                    <div class="isf-template-tags">
                        <?php if (!empty($template['tags'])) : ?>
                            <?php foreach (array_slice($template['tags'], 0, 3) as $tag) : ?>
                                <span class="isf-tag"><?php echo esc_html($tag); ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="isf-template-actions">
                        <button type="button" class="button button-primary isf-install-template" data-slug="<?php echo esc_attr($template['slug']); ?>" data-name="<?php echo esc_attr($template['name']); ?>">
                            <span class="dashicons dashicons-plus-alt2"></span>
                            <?php esc_html_e('Use Template', 'formflow'); ?>
                        </button>
                        <button type="button" class="button isf-preview-template" data-slug="<?php echo esc_attr($template['slug']); ?>">
                            <span class="dashicons dashicons-visibility"></span>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php elseif ($active_tab === 'connectors') : ?>
        <!-- Connectors Tab -->
        <div class="isf-marketplace-header">
            <div class="isf-search-box">
                <input type="text" id="isf-connector-search" placeholder="<?php esc_attr_e('Search connectors...', 'formflow'); ?>">
            </div>
            <div class="isf-filter-buttons">
                <button type="button" class="button isf-filter-btn active" data-category="all">
                    <?php esc_html_e('All', 'formflow'); ?>
                </button>
                <button type="button" class="button isf-filter-btn" data-category="crm">
                    <?php esc_html_e('CRM', 'formflow'); ?>
                </button>
                <button type="button" class="button isf-filter-btn" data-category="marketing">
                    <?php esc_html_e('Marketing', 'formflow'); ?>
                </button>
                <button type="button" class="button isf-filter-btn" data-category="communication">
                    <?php esc_html_e('Communication', 'formflow'); ?>
                </button>
                <button type="button" class="button isf-filter-btn" data-category="automation">
                    <?php esc_html_e('Automation', 'formflow'); ?>
                </button>
            </div>
        </div>

        <div class="isf-connector-grid" id="isf-connector-grid">
            <?php foreach ($connectors as $connector) : ?>
                <div class="isf-connector-card" data-category="<?php echo esc_attr($connector['category']); ?>">
                    <div class="isf-connector-icon">
                        <span class="dashicons dashicons-<?php echo esc_attr($connector['icon']); ?>"></span>
                    </div>
                    <div class="isf-connector-info">
                        <h3>
                            <?php echo esc_html($connector['name']); ?>
                            <?php if ($connector['status'] === 'built-in') : ?>
                                <span class="isf-badge isf-badge-builtin"><?php esc_html_e('Built-in', 'formflow'); ?></span>
                            <?php elseif ($connector['status'] === 'coming-soon') : ?>
                                <span class="isf-badge isf-badge-upcoming"><?php esc_html_e('Coming Soon', 'formflow'); ?></span>
                            <?php endif; ?>
                        </h3>
                        <p class="description"><?php echo esc_html($connector['description']); ?></p>
                        <span class="isf-connector-category"><?php echo esc_html(ucfirst($connector['category'])); ?></span>
                    </div>
                    <div class="isf-connector-actions">
                        <?php if ($connector['status'] === 'built-in') : ?>
                            <span class="isf-status isf-status-active"><?php esc_html_e('Active', 'formflow'); ?></span>
                        <?php elseif ($connector['status'] === 'available') : ?>
                            <button type="button" class="button button-primary isf-install-connector" data-id="<?php echo esc_attr($connector['id']); ?>">
                                <?php esc_html_e('Configure', 'formflow'); ?>
                            </button>
                        <?php else : ?>
                            <button type="button" class="button" disabled>
                                <?php esc_html_e('Coming Soon', 'formflow'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php elseif ($active_tab === 'import') : ?>
        <!-- Import/Export Tab -->
        <div class="isf-card">
            <h2><?php esc_html_e('Import Template', 'formflow'); ?></h2>
            <p class="description">
                <?php esc_html_e('Import a template from a JSON file.', 'formflow'); ?>
            </p>

            <form id="isf-import-form">
                <?php wp_nonce_field('isf_admin_nonce', 'nonce'); ?>
                <div class="isf-import-area">
                    <input type="file" id="isf-import-file" accept=".json" style="display: none;">
                    <div class="isf-dropzone" id="isf-dropzone">
                        <span class="dashicons dashicons-upload"></span>
                        <p><?php esc_html_e('Drag & drop a template file here, or click to browse', 'formflow'); ?></p>
                        <button type="button" class="button" id="isf-browse-file">
                            <?php esc_html_e('Browse Files', 'formflow'); ?>
                        </button>
                    </div>
                </div>
                <div id="isf-import-preview" style="display: none;">
                    <h3><?php esc_html_e('Template Preview', 'formflow'); ?></h3>
                    <div id="isf-import-preview-content"></div>
                    <p>
                        <button type="submit" class="button button-primary" id="isf-confirm-import">
                            <?php esc_html_e('Import Template', 'formflow'); ?>
                        </button>
                        <button type="button" class="button" id="isf-cancel-import">
                            <?php esc_html_e('Cancel', 'formflow'); ?>
                        </button>
                    </p>
                </div>
            </form>
        </div>

        <div class="isf-card">
            <h2><?php esc_html_e('Export Instance as Template', 'formflow'); ?></h2>
            <p class="description">
                <?php esc_html_e('Export an existing form instance as a reusable template.', 'formflow'); ?>
            </p>

            <?php
            global $wpdb;
            $instances = $wpdb->get_results(
                "SELECT id, name FROM {$wpdb->prefix}isf_instances WHERE is_active = 1 ORDER BY name",
                ARRAY_A
            );
            ?>

            <form id="isf-export-form">
                <?php wp_nonce_field('isf_admin_nonce', 'nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="export_instance"><?php esc_html_e('Select Instance', 'formflow'); ?></label>
                        </th>
                        <td>
                            <select id="export_instance" name="instance_id" class="regular-text">
                                <option value=""><?php esc_html_e('Choose an instance...', 'formflow'); ?></option>
                                <?php foreach ($instances as $instance) : ?>
                                    <option value="<?php echo esc_attr($instance['id']); ?>">
                                        <?php echo esc_html($instance['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e('Export Template', 'formflow'); ?>
                    </button>
                </p>
            </form>
        </div>
    <?php endif; ?>
</div>

<!-- Install Template Modal -->
<div id="isf-install-modal" class="isf-modal" style="display: none;">
    <div class="isf-modal-content">
        <div class="isf-modal-header">
            <h2><?php esc_html_e('Create Form from Template', 'formflow'); ?></h2>
            <button type="button" class="isf-modal-close">&times;</button>
        </div>
        <div class="isf-modal-body">
            <form id="isf-install-form">
                <?php wp_nonce_field('isf_admin_nonce', 'nonce'); ?>
                <input type="hidden" name="slug" id="install-template-slug">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="install-name"><?php esc_html_e('Form Name', 'formflow'); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="install-name" name="name" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="install-utility"><?php esc_html_e('Utility/Brand', 'formflow'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="install-utility" name="utility" class="regular-text" placeholder="<?php esc_attr_e('e.g., pepco, delmarva', 'formflow'); ?>">
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Create Form', 'formflow'); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>
</div>

<style>
.isf-badge-upcoming {
    background: #f0f6fc;
    color: #0366d6;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
}
.isf-badge-premium {
    background: #ffd700;
    color: #333;
}
.isf-badge-imported {
    background: #e1e4e8;
    color: #586069;
}
.isf-badge-builtin {
    background: #28a745;
    color: #fff;
    font-size: 10px;
    padding: 2px 6px;
    vertical-align: middle;
}
.isf-count {
    background: #e1e4e8;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    margin-left: 5px;
}
.isf-marketplace-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 20px 0;
    padding: 15px;
    background: #f6f8fa;
    border-radius: 6px;
}
.isf-search-box input {
    width: 300px;
    padding: 8px 12px;
}
.isf-filter-buttons .button {
    margin-right: 5px;
}
.isf-filter-buttons .button.active {
    background: #0073aa;
    color: #fff;
    border-color: #0073aa;
}
.isf-template-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.isf-template-card {
    background: #fff;
    border: 1px solid #e1e4e8;
    border-radius: 8px;
    padding: 20px;
    transition: box-shadow 0.2s, transform 0.2s;
}
.isf-template-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}
.isf-template-header {
    display: flex;
    justify-content: flex-end;
    min-height: 24px;
}
.isf-template-icon {
    text-align: center;
    margin: 15px 0;
}
.isf-template-icon .dashicons {
    font-size: 48px;
    width: 48px;
    height: 48px;
    color: #0073aa;
}
.isf-template-card h3 {
    margin: 0 0 10px;
    text-align: center;
}
.isf-template-card .description {
    font-size: 13px;
    color: #666;
    text-align: center;
    min-height: 40px;
}
.isf-template-meta {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin: 15px 0;
    font-size: 12px;
    color: #666;
}
.isf-template-meta .dashicons {
    font-size: 14px;
    width: 14px;
    height: 14px;
    vertical-align: middle;
}
.isf-template-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    justify-content: center;
    margin: 10px 0;
}
.isf-tag {
    background: #f0f6fc;
    color: #0366d6;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
}
.isf-template-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-top: 15px;
}
.isf-template-actions .button .dashicons {
    vertical-align: middle;
    margin-right: 3px;
}
.isf-connector-grid {
    display: flex;
    flex-direction: column;
    gap: 15px;
    margin-top: 20px;
}
.isf-connector-card {
    display: flex;
    align-items: center;
    background: #fff;
    border: 1px solid #e1e4e8;
    border-radius: 8px;
    padding: 20px;
}
.isf-connector-icon {
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f6f8fa;
    border-radius: 8px;
    margin-right: 20px;
}
.isf-connector-icon .dashicons {
    font-size: 32px;
    width: 32px;
    height: 32px;
    color: #0073aa;
}
.isf-connector-info {
    flex: 1;
}
.isf-connector-info h3 {
    margin: 0 0 8px;
}
.isf-connector-info .description {
    margin: 0 0 8px;
}
.isf-connector-category {
    font-size: 11px;
    color: #666;
    background: #f0f0f0;
    padding: 2px 8px;
    border-radius: 3px;
}
.isf-connector-actions {
    margin-left: 20px;
}
.isf-dropzone {
    border: 2px dashed #c3c4c7;
    border-radius: 8px;
    padding: 40px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
}
.isf-dropzone:hover,
.isf-dropzone.dragover {
    border-color: #0073aa;
    background: #f0f6fc;
}
.isf-dropzone .dashicons {
    font-size: 48px;
    width: 48px;
    height: 48px;
    color: #c3c4c7;
}
.isf-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100000;
}
.isf-modal-content {
    background: #fff;
    border-radius: 8px;
    width: 500px;
    max-width: 90%;
    max-height: 80vh;
    overflow: auto;
}
.isf-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #e1e4e8;
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
.isf-modal-body {
    padding: 20px;
}
</style>

<script>
jQuery(document).ready(function($) {
    var pendingImport = null;

    // Filter buttons
    $('.isf-filter-btn').on('click', function() {
        var category = $(this).data('category');
        $('.isf-filter-btn').removeClass('active');
        $(this).addClass('active');

        if (category === 'all') {
            $('.isf-template-card, .isf-connector-card').show();
        } else {
            $('.isf-template-card, .isf-connector-card').hide();
            $('[data-category="' + category + '"]').show();
        }
    });

    // Template search
    $('#isf-template-search').on('keyup', function() {
        var search = $(this).val().toLowerCase();
        $('.isf-template-card').each(function() {
            var text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(search) > -1);
        });
    });

    // Connector search
    $('#isf-connector-search').on('keyup', function() {
        var search = $(this).val().toLowerCase();
        $('.isf-connector-card').each(function() {
            var text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(search) > -1);
        });
    });

    // Install template button
    $('.isf-install-template').on('click', function() {
        var slug = $(this).data('slug');
        var name = $(this).data('name');
        $('#install-template-slug').val(slug);
        $('#install-name').val(name);
        $('#isf-install-modal').show();
    });

    // Close modal
    $('.isf-modal-close, .isf-modal').on('click', function(e) {
        if (e.target === this || $(this).hasClass('isf-modal-close')) {
            $('.isf-modal').hide();
        }
    });
    $('.isf-modal-content').on('click', function(e) {
        e.stopPropagation();
    });

    // Install form submit
    $('#isf-install-form').on('submit', function(e) {
        e.preventDefault();

        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('<?php echo esc_js(__('Creating...', 'formflow')); ?>');

        $.post(ajaxurl, {
            action: 'formflow_marketplace_install',
            nonce: $(this).find('[name="nonce"]').val(),
            slug: $('#install-template-slug').val(),
            name: $('#install-name').val(),
            utility: $('#install-utility').val()
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
                window.location.href = '<?php echo esc_js(admin_url('admin.php?page=isf-instance-editor&id=')); ?>' + response.data.instance_id;
            } else {
                alert(response.data.message || '<?php echo esc_js(__('Installation failed.', 'formflow')); ?>');
                $btn.prop('disabled', false).text('<?php echo esc_js(__('Create Form', 'formflow')); ?>');
            }
        });
    });

    // Export form
    $('#isf-export-form').on('submit', function(e) {
        e.preventDefault();

        var instanceId = $('#export_instance').val();
        if (!instanceId) {
            alert('<?php echo esc_js(__('Please select an instance.', 'formflow')); ?>');
            return;
        }

        $.post(ajaxurl, {
            action: 'formflow_template_export',
            nonce: $(this).find('[name="nonce"]').val(),
            instance_id: instanceId
        }, function(response) {
            if (response.success) {
                var dataStr = JSON.stringify(response.data.export, null, 2);
                var dataUri = 'data:application/json;charset=utf-8,' + encodeURIComponent(dataStr);
                var filename = 'formflow-template-' + new Date().toISOString().split('T')[0] + '.json';

                var link = document.createElement('a');
                link.setAttribute('href', dataUri);
                link.setAttribute('download', filename);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            } else {
                alert(response.data.message || '<?php echo esc_js(__('Export failed.', 'formflow')); ?>');
            }
        });
    });

    // Import file handling
    $('#isf-browse-file').on('click', function() {
        $('#isf-import-file').click();
    });

    $('#isf-import-file').on('change', function(e) {
        handleFile(e.target.files[0]);
    });

    // Drag and drop
    var $dropzone = $('#isf-dropzone');
    $dropzone.on('dragover', function(e) {
        e.preventDefault();
        $(this).addClass('dragover');
    }).on('dragleave', function() {
        $(this).removeClass('dragover');
    }).on('drop', function(e) {
        e.preventDefault();
        $(this).removeClass('dragover');
        handleFile(e.originalEvent.dataTransfer.files[0]);
    });

    function handleFile(file) {
        if (!file || !file.name.endsWith('.json')) {
            alert('<?php echo esc_js(__('Please upload a JSON file.', 'formflow')); ?>');
            return;
        }

        var reader = new FileReader();
        reader.onload = function(e) {
            try {
                pendingImport = JSON.parse(e.target.result);
                if (!pendingImport.formflow_template) {
                    throw new Error('Invalid format');
                }

                var preview = '<table class="widefat">';
                preview += '<tr><th><?php echo esc_js(__('Name', 'formflow')); ?></th><td>' + pendingImport.template.name + '</td></tr>';
                preview += '<tr><th><?php echo esc_js(__('Category', 'formflow')); ?></th><td>' + pendingImport.template.category + '</td></tr>';
                preview += '<tr><th><?php echo esc_js(__('Steps', 'formflow')); ?></th><td>' + (pendingImport.template.schema.steps || []).length + '</td></tr>';
                preview += '</table>';

                $('#isf-import-preview-content').html(preview);
                $('#isf-import-preview').show();
                $('#isf-dropzone').hide();
            } catch (err) {
                alert('<?php echo esc_js(__('Invalid template file format.', 'formflow')); ?>');
            }
        };
        reader.readAsText(file);
    }

    $('#isf-cancel-import').on('click', function() {
        pendingImport = null;
        $('#isf-import-preview').hide();
        $('#isf-dropzone').show();
    });

    $('#isf-import-form').on('submit', function(e) {
        e.preventDefault();

        if (!pendingImport) return;

        $.post(ajaxurl, {
            action: 'formflow_template_import',
            nonce: $(this).find('[name="nonce"]').val(),
            template: JSON.stringify(pendingImport)
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert(response.data.message || '<?php echo esc_js(__('Import failed.', 'formflow')); ?>');
            }
        });
    });
});
</script>
