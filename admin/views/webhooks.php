<?php
/**
 * Webhooks Management View
 *
 * @package IntelliSource_Forms
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap isf-admin-page">
    <h1 class="wp-heading-inline"><?php esc_html_e('Webhooks', 'formflow'); ?></h1>
    <button type="button" class="page-title-action" id="isf-add-webhook">
        <?php esc_html_e('Add New Webhook', 'formflow'); ?>
    </button>
    <hr class="wp-header-end">

    <div class="isf-webhooks-intro">
        <p><?php esc_html_e('Webhooks allow you to receive real-time notifications when enrollment events occur. Configure endpoints to integrate with CRM systems, email services, or custom applications.', 'formflow'); ?></p>
    </div>

    <!-- Webhook List -->
    <div class="isf-card">
        <div class="isf-card-header">
            <h2><?php esc_html_e('Configured Webhooks', 'formflow'); ?></h2>
            <div class="isf-card-actions">
                <select id="isf-webhook-filter-instance">
                    <option value=""><?php esc_html_e('All Form Instances', 'formflow'); ?></option>
                    <?php foreach ($instances as $inst): ?>
                        <option value="<?php echo esc_attr($inst['id']); ?>">
                            <?php echo esc_html($inst['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped isf-webhooks-table">
            <thead>
                <tr>
                    <th class="column-status" style="width: 60px;"><?php esc_html_e('Status', 'formflow'); ?></th>
                    <th class="column-name"><?php esc_html_e('Name', 'formflow'); ?></th>
                    <th class="column-url"><?php esc_html_e('URL', 'formflow'); ?></th>
                    <th class="column-instance"><?php esc_html_e('Instance', 'formflow'); ?></th>
                    <th class="column-events"><?php esc_html_e('Events', 'formflow'); ?></th>
                    <th class="column-stats" style="width: 120px;"><?php esc_html_e('Stats', 'formflow'); ?></th>
                    <th class="column-actions" style="width: 120px;"><?php esc_html_e('Actions', 'formflow'); ?></th>
                </tr>
            </thead>
            <tbody id="isf-webhooks-list">
                <?php if (empty($webhooks)): ?>
                    <tr class="isf-no-webhooks">
                        <td colspan="7">
                            <div class="isf-empty-state">
                                <span class="dashicons dashicons-rest-api"></span>
                                <p><?php esc_html_e('No webhooks configured yet.', 'formflow'); ?></p>
                                <button type="button" class="button button-primary" id="isf-add-webhook-empty">
                                    <?php esc_html_e('Add Your First Webhook', 'formflow'); ?>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($webhooks as $webhook): ?>
                        <tr data-webhook-id="<?php echo esc_attr($webhook['id']); ?>">
                            <td class="column-status">
                                <span class="isf-status-indicator isf-status-<?php echo $webhook['is_active'] ? 'active' : 'inactive'; ?>"></span>
                            </td>
                            <td class="column-name">
                                <strong><?php echo esc_html($webhook['name']); ?></strong>
                                <?php if (!empty($webhook['secret'])): ?>
                                    <span class="dashicons dashicons-lock" title="<?php esc_attr_e('Signature enabled', 'formflow'); ?>"></span>
                                <?php endif; ?>
                            </td>
                            <td class="column-url">
                                <code><?php echo esc_html($webhook['url']); ?></code>
                            </td>
                            <td class="column-instance">
                                <?php
                                if ($webhook['instance_id']) {
                                    $inst_name = 'Unknown';
                                    foreach ($instances as $inst) {
                                        if ($inst['id'] == $webhook['instance_id']) {
                                            $inst_name = $inst['name'];
                                            break;
                                        }
                                    }
                                    echo esc_html($inst_name);
                                } else {
                                    echo '<em>' . esc_html__('All instances', 'formflow') . '</em>';
                                }
                                ?>
                            </td>
                            <td class="column-events">
                                <?php
                                $event_labels = \ISF\WebhookHandler::get_available_events();
                                $events = $webhook['events'] ?? [];
                                $event_names = [];
                                foreach ($events as $event) {
                                    $event_names[] = $event_labels[$event] ?? $event;
                                }
                                echo esc_html(implode(', ', $event_names));
                                ?>
                            </td>
                            <td class="column-stats">
                                <span class="isf-webhook-stat" title="<?php esc_attr_e('Successful deliveries', 'formflow'); ?>">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <?php echo esc_html($webhook['success_count'] ?? 0); ?>
                                </span>
                                <span class="isf-webhook-stat isf-stat-failures" title="<?php esc_attr_e('Failed deliveries', 'formflow'); ?>">
                                    <span class="dashicons dashicons-dismiss"></span>
                                    <?php echo esc_html($webhook['failure_count'] ?? 0); ?>
                                </span>
                            </td>
                            <td class="column-actions">
                                <button type="button" class="button button-small isf-test-webhook" data-id="<?php echo esc_attr($webhook['id']); ?>" title="<?php esc_attr_e('Test webhook', 'formflow'); ?>">
                                    <span class="dashicons dashicons-update"></span>
                                </button>
                                <button type="button" class="button button-small isf-edit-webhook" data-id="<?php echo esc_attr($webhook['id']); ?>" title="<?php esc_attr_e('Edit', 'formflow'); ?>">
                                    <span class="dashicons dashicons-edit"></span>
                                </button>
                                <button type="button" class="button button-small isf-delete-webhook" data-id="<?php echo esc_attr($webhook['id']); ?>" title="<?php esc_attr_e('Delete', 'formflow'); ?>">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Event Documentation -->
    <div class="isf-card isf-webhook-docs">
        <div class="isf-card-header">
            <h2><?php esc_html_e('Available Events', 'formflow'); ?></h2>
        </div>
        <div class="isf-card-body">
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 200px;"><?php esc_html_e('Event', 'formflow'); ?></th>
                        <th><?php esc_html_e('Description', 'formflow'); ?></th>
                        <th style="width: 300px;"><?php esc_html_e('Payload Fields', 'formflow'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>enrollment.completed</code></td>
                        <td><?php esc_html_e('Fired when an enrollment is successfully submitted to the API.', 'formflow'); ?></td>
                        <td><code>submission_id, account_number, customer_name, device_type, confirmation_number</code></td>
                    </tr>
                    <tr>
                        <td><code>enrollment.failed</code></td>
                        <td><?php esc_html_e('Fired when an enrollment fails after all retries are exhausted.', 'formflow'); ?></td>
                        <td><code>submission_id, account_number, customer_name, error, retry_count</code></td>
                    </tr>
                    <tr>
                        <td><code>appointment.scheduled</code></td>
                        <td><?php esc_html_e('Fired when an installation appointment is successfully booked.', 'formflow'); ?></td>
                        <td><code>submission_id, account_number, customer_name, schedule_date, schedule_time</code></td>
                    </tr>
                    <tr>
                        <td><code>account.validated</code></td>
                        <td><?php esc_html_e('Fired when a customer account is validated against the API.', 'formflow'); ?></td>
                        <td><code>account_number, customer_name, premise_address, is_valid</code></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Webhook Payload Example -->
    <div class="isf-card">
        <div class="isf-card-header">
            <h2><?php esc_html_e('Payload Format', 'formflow'); ?></h2>
        </div>
        <div class="isf-card-body">
            <p><?php esc_html_e('Webhooks are sent as HTTP POST requests with a JSON payload. If a secret is configured, an HMAC-SHA256 signature is included in the X-ISF-Signature header.', 'formflow'); ?></p>
            <pre class="isf-code-block">{
  "event": "enrollment.completed",
  "timestamp": "2024-01-15T14:30:00+00:00",
  "data": {
    "submission_id": 123,
    "instance_id": 1,
    "form_data": {
      "account_number": "1234567890",
      "customer_name": "John Doe",
      "device_type": "thermostat"
    },
    "confirmation_number": "CNF-12345"
  }
}</pre>
            <h4><?php esc_html_e('HTTP Headers', 'formflow'); ?></h4>
            <pre class="isf-code-block">Content-Type: application/json
X-ISF-Event: enrollment.completed
X-ISF-Timestamp: 1705329000
X-ISF-Signature: a1b2c3d4e5f6... (if secret configured)</pre>
        </div>
    </div>
</div>

<!-- Add/Edit Webhook Modal -->
<div id="isf-webhook-modal" class="isf-modal" style="display: none;">
    <div class="isf-modal-content">
        <div class="isf-modal-header">
            <h2 id="isf-webhook-modal-title"><?php esc_html_e('Add Webhook', 'formflow'); ?></h2>
            <button type="button" class="isf-modal-close">&times;</button>
        </div>
        <form id="isf-webhook-form">
            <input type="hidden" name="webhook_id" id="isf-webhook-id" value="">

            <div class="isf-modal-body">
                <div class="isf-form-row">
                    <label for="isf-webhook-name"><?php esc_html_e('Name', 'formflow'); ?> <span class="required">*</span></label>
                    <input type="text" id="isf-webhook-name" name="name" required placeholder="<?php esc_attr_e('e.g., CRM Integration', 'formflow'); ?>">
                </div>

                <div class="isf-form-row">
                    <label for="isf-webhook-url"><?php esc_html_e('Endpoint URL', 'formflow'); ?> <span class="required">*</span></label>
                    <input type="url" id="isf-webhook-url" name="url" required placeholder="https://example.com/webhook">
                    <p class="description"><?php esc_html_e('The URL where webhook payloads will be sent.', 'formflow'); ?></p>
                </div>

                <div class="isf-form-row">
                    <label for="isf-webhook-instance"><?php esc_html_e('Form Instance', 'formflow'); ?></label>
                    <select id="isf-webhook-instance" name="instance_id">
                        <option value=""><?php esc_html_e('All instances (global)', 'formflow'); ?></option>
                        <?php foreach ($instances as $inst): ?>
                            <option value="<?php echo esc_attr($inst['id']); ?>">
                                <?php echo esc_html($inst['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('Select a specific form instance or leave as "All" to receive events from all forms.', 'formflow'); ?></p>
                </div>

                <div class="isf-form-row">
                    <label><?php esc_html_e('Events', 'formflow'); ?> <span class="required">*</span></label>
                    <div class="isf-checkbox-group">
                        <?php foreach (\ISF\WebhookHandler::get_available_events() as $event => $label): ?>
                            <label class="isf-checkbox-label">
                                <input type="checkbox" name="events[]" value="<?php echo esc_attr($event); ?>">
                                <?php echo esc_html($label); ?>
                                <code><?php echo esc_html($event); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description"><?php esc_html_e('Select which events should trigger this webhook.', 'formflow'); ?></p>
                </div>

                <div class="isf-form-row">
                    <label for="isf-webhook-secret"><?php esc_html_e('Secret Key', 'formflow'); ?></label>
                    <div class="isf-input-group">
                        <input type="text" id="isf-webhook-secret" name="secret" placeholder="<?php esc_attr_e('Optional signing secret', 'formflow'); ?>">
                        <button type="button" class="button" id="isf-generate-secret"><?php esc_html_e('Generate', 'formflow'); ?></button>
                    </div>
                    <p class="description"><?php esc_html_e('If provided, payloads will be signed with HMAC-SHA256 using this secret.', 'formflow'); ?></p>
                </div>

                <div class="isf-form-row">
                    <label class="isf-checkbox-label">
                        <input type="checkbox" name="is_active" id="isf-webhook-active" value="1" checked>
                        <?php esc_html_e('Active', 'formflow'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Inactive webhooks will not receive any events.', 'formflow'); ?></p>
                </div>
            </div>

            <div class="isf-modal-footer">
                <button type="button" class="button isf-modal-cancel"><?php esc_html_e('Cancel', 'formflow'); ?></button>
                <button type="submit" class="button button-primary" id="isf-webhook-save"><?php esc_html_e('Save Webhook', 'formflow'); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var $modal = $('#isf-webhook-modal');
    var $form = $('#isf-webhook-form');

    // Open modal for new webhook
    $('#isf-add-webhook, #isf-add-webhook-empty').on('click', function() {
        openModal();
    });

    // Edit webhook
    $(document).on('click', '.isf-edit-webhook', function() {
        var webhookId = $(this).data('id');
        loadWebhook(webhookId);
    });

    // Delete webhook
    $(document).on('click', '.isf-delete-webhook', function() {
        var webhookId = $(this).data('id');
        if (confirm('<?php echo esc_js(__('Are you sure you want to delete this webhook?', 'formflow')); ?>')) {
            deleteWebhook(webhookId);
        }
    });

    // Test webhook
    $(document).on('click', '.isf-test-webhook', function() {
        var $btn = $(this);
        var webhookId = $btn.data('id');
        $btn.find('.dashicons').addClass('isf-spin');
        testWebhook(webhookId, function() {
            $btn.find('.dashicons').removeClass('isf-spin');
        });
    });

    // Close modal
    $('.isf-modal-close, .isf-modal-cancel').on('click', function() {
        closeModal();
    });

    // Close modal on backdrop click
    $modal.on('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });

    // Generate secret
    $('#isf-generate-secret').on('click', function() {
        var secret = generateSecret(32);
        $('#isf-webhook-secret').val(secret);
    });

    // Submit form
    $form.on('submit', function(e) {
        e.preventDefault();
        saveWebhook();
    });

    function openModal(webhook) {
        $form[0].reset();
        $('#isf-webhook-id').val('');
        $('#isf-webhook-modal-title').text('<?php echo esc_js(__('Add Webhook', 'formflow')); ?>');

        if (webhook) {
            $('#isf-webhook-id').val(webhook.id);
            $('#isf-webhook-name').val(webhook.name);
            $('#isf-webhook-url').val(webhook.url);
            $('#isf-webhook-instance').val(webhook.instance_id || '');
            $('#isf-webhook-secret').val(webhook.secret || '');
            $('#isf-webhook-active').prop('checked', webhook.is_active);
            $('#isf-webhook-modal-title').text('<?php echo esc_js(__('Edit Webhook', 'formflow')); ?>');

            // Check event boxes
            $('input[name="events[]"]').prop('checked', false);
            if (webhook.events) {
                webhook.events.forEach(function(event) {
                    $('input[name="events[]"][value="' + event + '"]').prop('checked', true);
                });
            }
        }

        $modal.fadeIn(200);
    }

    function closeModal() {
        $modal.fadeOut(200);
    }

    function loadWebhook(webhookId) {
        $.post(isf_admin.ajax_url, {
            action: 'formflow_get_webhook',
            nonce: isf_admin.nonce,
            webhook_id: webhookId
        }, function(response) {
            if (response.success) {
                openModal(response.data.webhook);
            } else {
                alert(response.data.message || '<?php echo esc_js(__('Failed to load webhook.', 'formflow')); ?>');
            }
        });
    }

    function saveWebhook() {
        var $saveBtn = $('#isf-webhook-save');
        var originalText = $saveBtn.text();
        $saveBtn.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'formflow')); ?>');

        var events = [];
        $('input[name="events[]"]:checked').each(function() {
            events.push($(this).val());
        });

        $.post(isf_admin.ajax_url, {
            action: 'formflow_save_webhook',
            nonce: isf_admin.nonce,
            webhook_id: $('#isf-webhook-id').val(),
            name: $('#isf-webhook-name').val(),
            url: $('#isf-webhook-url').val(),
            instance_id: $('#isf-webhook-instance').val(),
            events: events,
            secret: $('#isf-webhook-secret').val(),
            is_active: $('#isf-webhook-active').is(':checked') ? 1 : 0
        }, function(response) {
            $saveBtn.prop('disabled', false).text(originalText);
            if (response.success) {
                closeModal();
                location.reload();
            } else {
                alert(response.data.message || '<?php echo esc_js(__('Failed to save webhook.', 'formflow')); ?>');
            }
        });
    }

    function deleteWebhook(webhookId) {
        $.post(isf_admin.ajax_url, {
            action: 'formflow_delete_webhook',
            nonce: isf_admin.nonce,
            webhook_id: webhookId
        }, function(response) {
            if (response.success) {
                $('tr[data-webhook-id="' + webhookId + '"]').fadeOut(300, function() {
                    $(this).remove();
                    if ($('#isf-webhooks-list tr').length === 0) {
                        location.reload();
                    }
                });
            } else {
                alert(response.data.message || '<?php echo esc_js(__('Failed to delete webhook.', 'formflow')); ?>');
            }
        });
    }

    function testWebhook(webhookId, callback) {
        $.post(isf_admin.ajax_url, {
            action: 'formflow_test_webhook',
            nonce: isf_admin.nonce,
            webhook_id: webhookId
        }, function(response) {
            callback();
            if (response.success) {
                alert('<?php echo esc_js(__('Test webhook sent successfully! Status: ', 'formflow')); ?>' + response.data.status_code);
            } else {
                alert('<?php echo esc_js(__('Test failed: ', 'formflow')); ?>' + (response.data.message || response.data.error));
            }
        });
    }

    function generateSecret(length) {
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        var secret = '';
        for (var i = 0; i < length; i++) {
            secret += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return secret;
    }
});
</script>
