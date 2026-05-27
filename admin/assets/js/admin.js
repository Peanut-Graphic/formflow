/**
 * IntelliSource Forms - Admin JavaScript
 */

(function($) {
    'use strict';

    /**
     * Escape HTML to prevent XSS attacks
     * @param {string} str - String to escape
     * @returns {string} - Escaped string safe for HTML insertion
     */
    function escapeHtml(str) {
        if (str === null || str === undefined) {
            return '';
        }
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Escape string for use in HTML attributes
     * @param {string} str - String to escape
     * @returns {string} - Escaped string safe for attribute values
     */
    function escapeAttr(str) {
        if (str === null || str === undefined) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // Initialize when DOM is ready
    $(document).ready(function() {
        initFormSave();
        initDeleteInstance();
        initApiTest();
        initApiHealthCheck();
        initApiUsage();
        initSortable();
    });

    /**
     * Initialize drag-and-drop sortable functionality
     */
    function initSortable() {
        // Form fields sortable in instance editor
        if ($('#isf-sortable-fields').length) {
            $('#isf-sortable-fields').sortable({
                handle: '.isf-drag-handle',
                placeholder: 'isf-sortable-placeholder',
                axis: 'y',
                tolerance: 'pointer',
                cursor: 'grabbing',
                opacity: 0.8,
                update: function(event, ui) {
                    // Hidden inputs with field_order[] already update automatically
                    // Just trigger form change indicator if needed
                    $(this).trigger('isf:fields-reordered');
                }
            });
        }

        // Blocked dates sortable in instance editor
        if ($('#isf-blocked-dates-list').length) {
            $('#isf-blocked-dates-list').sortable({
                handle: '.isf-drag-handle',
                placeholder: 'isf-sortable-placeholder',
                axis: 'y',
                tolerance: 'pointer',
                cursor: 'grabbing',
                opacity: 0.8,
                update: function(event, ui) {
                    // Reindex the blocked date inputs after sorting
                    reindexBlockedDates();
                }
            });
        }

        // Dashboard instances table sortable
        if ($('#isf-instances-sortable').length) {
            $('#isf-instances-sortable').sortable({
                handle: '.isf-drag-handle',
                placeholder: 'isf-sortable-placeholder',
                axis: 'y',
                tolerance: 'pointer',
                cursor: 'grabbing',
                opacity: 0.8,
                helper: function(e, tr) {
                    // Preserve cell widths while dragging
                    var $originals = tr.children();
                    var $helper = tr.clone();
                    $helper.children().each(function(index) {
                        $(this).width($originals.eq(index).width());
                    });
                    return $helper;
                },
                update: function(event, ui) {
                    // Save the new order via AJAX
                    saveInstanceOrder();
                }
            });
        }

        // Features list sortable (if present)
        if ($('#isf-features-list').length) {
            $('#isf-features-list').sortable({
                handle: '.isf-drag-handle',
                placeholder: 'isf-sortable-placeholder',
                axis: 'y',
                tolerance: 'pointer',
                cursor: 'grabbing',
                opacity: 0.8,
                items: '.isf-feature-item',
                update: function(event, ui) {
                    // Update feature order inputs
                    reindexFeatures();
                }
            });
        }
    }

    /**
     * Reindex blocked date inputs after sorting
     */
    function reindexBlockedDates() {
        $('#isf-blocked-dates-list .isf-blocked-date-row').each(function(index) {
            $(this).find('input[name^="settings[blocked_dates]"]').each(function() {
                var name = $(this).attr('name');
                // Replace the index in the name
                var newName = name.replace(/\[blocked_dates\]\[\d+\]/, '[blocked_dates][' + index + ']');
                $(this).attr('name', newName);
            });
        });
    }

    /**
     * Reindex feature order inputs after sorting
     */
    function reindexFeatures() {
        $('#isf-features-list .isf-feature-item').each(function(index) {
            $(this).find('input[name="settings[feature_order][]"]').val($(this).data('feature'));
        });
    }

    /**
     * Save instance order to database via AJAX
     */
    function saveInstanceOrder() {
        var order = [];
        $('#isf-instances-sortable .isf-sortable-row').each(function() {
            order.push($(this).data('instance-id'));
        });

        if (order.length === 0) return;

        $.ajax({
            url: isf_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_save_instance_order',
                nonce: isf_admin.nonce,
                order: order
            },
            success: function(response) {
                if (response.success) {
                    // Show brief success indicator
                    showSortSaved();
                }
            },
            error: function() {
                console.error('Failed to save instance order');
            }
        });
    }

    /**
     * Show brief "Saved" indicator after reordering
     */
    function showSortSaved() {
        // Remove any existing indicator
        $('.isf-order-saved').remove();

        var $indicator = $('<span class="isf-order-saved">Order saved</span>');
        $('.isf-card-header h2').first().after($indicator);

        setTimeout(function() {
            $indicator.fadeOut(400, function() {
                $(this).remove();
            });
        }, 2000);
    }

    /**
     * Initialize form save functionality
     */
    function initFormSave() {
        $('#isf-instance-form').on('submit', function(e) {
            e.preventDefault();

            var $form = $(this);
            var $button = $form.find('button[type="submit"]');
            var originalText = $button.text();

            // Disable button and show loading state
            $button.prop('disabled', true).text(isf_admin.strings.saving);

            // Build settings object with content
            var settings = {
                default_state: $form.find('select[name="settings[default_state]"]').val(),
                support_phone: $form.find('input[name="settings[support_phone]"]').val(),
                content: {}
            };

            // Collect all content fields
            $form.find('[name^="settings[content]"]').each(function() {
                var name = $(this).attr('name');
                var match = name.match(/settings\[content\]\[([^\]]+)\]/);
                if (match) {
                    settings.content[match[1]] = $(this).val();
                }
            });

            $.ajax({
                url: isf_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'formflow_save_instance',
                    nonce: isf_admin.nonce,
                    id: $form.find('input[name="id"]').val(),
                    name: $form.find('input[name="name"]').val(),
                    slug: $form.find('input[name="slug"]').val(),
                    utility: $form.find('select[name="utility"]').val(),
                    form_type: $form.find('select[name="form_type"]').val(),
                    api_endpoint: $form.find('input[name="api_endpoint"]').val(),
                    api_password: $form.find('input[name="api_password"]').val(),
                    support_email_from: $form.find('input[name="support_email_from"]').val(),
                    support_email_to: $form.find('textarea[name="support_email_to"]').val(),
                    is_active: $form.find('input[name="is_active"]').is(':checked') ? 1 : 0,
                    test_mode: $form.find('input[name="test_mode"]').is(':checked') ? 1 : 0,
                    demo_mode: $form.find('input[name="demo_mode"]').is(':checked') ? 1 : 0,
                    settings: JSON.stringify(settings)
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $button.text(isf_admin.strings.saved);

                        // Redirect to edit page if this was a new form
                        if (response.data.id && !$form.find('input[name="id"]').val()) {
                            window.location.href = 'admin.php?page=isf-instance-editor&id=' + response.data.id;
                        } else {
                            setTimeout(function() {
                                $button.prop('disabled', false).text(originalText);
                            }, 2000);
                        }
                    } else {
                        alert(response.data.message || isf_admin.strings.error);
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert(isf_admin.strings.error);
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
    }

    /**
     * Initialize delete instance functionality
     */
    function initDeleteInstance() {
        $(document).on('click', '.isf-delete-instance', function(e) {
            e.preventDefault();

            if (!confirm(isf_admin.strings.confirm_delete)) {
                return;
            }

            var $button = $(this);
            var instanceId = $button.data('id');

            $button.prop('disabled', true);

            $.ajax({
                url: isf_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'formflow_delete_instance',
                    nonce: isf_admin.nonce,
                    id: instanceId
                },
                success: function(response) {
                    if (response.success) {
                        // Redirect to dashboard
                        window.location.href = 'admin.php?page=isf-dashboard';
                    } else {
                        alert(response.data.message || isf_admin.strings.error);
                        $button.prop('disabled', false);
                    }
                },
                error: function() {
                    alert(isf_admin.strings.error);
                    $button.prop('disabled', false);
                }
            });
        });
    }

    /**
     * Initialize API test functionality
     */
    function initApiTest() {
        $('#isf-test-api').on('click', function() {
            var $button = $(this);
            var $status = $('#isf-api-status');
            var endpoint = $('#api_endpoint').val();
            var password = $('#api_password').val();

            if (!endpoint || !password) {
                alert('Please enter API endpoint and password.');
                return;
            }

            $button.prop('disabled', true);
            $status.removeClass('success error').text(isf_admin.strings.testing_api);

            $.ajax({
                url: isf_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'formflow_test_api',
                    nonce: isf_admin.nonce,
                    api_endpoint: endpoint,
                    api_password: password
                },
                success: function(response) {
                    if (response.success) {
                        $status.addClass('success').text(isf_admin.strings.api_success);
                    } else {
                        $status.addClass('error').text(response.data.message || isf_admin.strings.api_failed);
                    }
                    $button.prop('disabled', false);
                },
                error: function() {
                    $status.addClass('error').text(isf_admin.strings.api_failed);
                    $button.prop('disabled', false);
                }
            });
        });
    }

    /**
     * Initialize API health check functionality
     */
    function initApiHealthCheck() {
        $('#isf-refresh-health').on('click', function() {
            var $button = $(this);
            var $grid = $('#isf-api-health-status');

            $button.prop('disabled', true).addClass('isf-loading');

            $.ajax({
                url: isf_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'formflow_check_api_health',
                    nonce: isf_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        updateHealthDisplay($grid, response.data.results);
                    } else {
                        alert(response.data.message || 'Failed to check API health');
                    }
                    $button.prop('disabled', false).removeClass('isf-loading');
                },
                error: function() {
                    alert('Error checking API health');
                    $button.prop('disabled', false).removeClass('isf-loading');
                }
            });
        });
    }

    /**
     * Update the health display grid with new results
     */
    function updateHealthDisplay($grid, results) {
        $grid.find('.isf-api-health-item').each(function() {
            var $item = $(this);
            var instanceId = $item.data('instance-id');
            var health = results[instanceId];

            if (!health) return;

            // Update indicator
            var $indicator = $item.find('.isf-api-health-indicator');
            $indicator.removeClass('isf-health-healthy isf-health-degraded isf-health-slow isf-health-error isf-health-demo isf-health-unconfigured isf-health-unknown');
            $indicator.addClass('isf-health-' + health.status);

            // Update status text
            var statusLabels = {
                'healthy': 'Healthy',
                'degraded': 'Degraded',
                'slow': 'Slow',
                'error': 'Error',
                'demo': 'Demo Mode',
                'unconfigured': 'Not Configured',
                'unknown': 'Unknown'
            };
            $item.find('.isf-api-health-status').text(statusLabels[health.status] || health.status);

            // Update latency
            var $latency = $item.find('.isf-api-health-latency');
            if (health.latency_ms) {
                if ($latency.length) {
                    $latency.text(health.latency_ms + 'ms');
                } else {
                    $item.find('.isf-api-health-details').append('<span class="isf-api-health-latency">' + health.latency_ms + 'ms</span>');
                }
            } else {
                $latency.remove();
            }

            // Update error indicator
            var $error = $item.find('.isf-api-health-error');
            if (health.error) {
                if ($error.length) {
                    $error.attr('title', health.error);
                } else {
                    $item.append('<div class="isf-api-health-error" title="' + escapeAttr(health.error) + '"><span class="dashicons dashicons-warning"></span></div>');
                }
            } else {
                $error.remove();
            }
        });

        // Add footer if not present
        if (!$grid.siblings('.isf-api-health-footer').length && Object.keys(results).length > 0) {
            $grid.after(
                '<div class="isf-api-health-footer">' +
                    '<span class="isf-api-health-legend">' +
                        '<span class="isf-health-dot isf-health-healthy"></span> Healthy ' +
                        '<span class="isf-health-dot isf-health-degraded"></span> Degraded ' +
                        '<span class="isf-health-dot isf-health-error"></span> Error ' +
                        '<span class="isf-health-dot isf-health-demo"></span> Demo' +
                    '</span>' +
                '</div>'
            );
        }
    }

    /**
     * Initialize API usage monitoring
     */
    function initApiUsage() {
        var $content = $('#isf-api-usage-content');
        var $period = $('#isf-api-usage-period');
        var $refresh = $('#isf-refresh-api-usage');

        if (!$content.length) return;

        // Load initial data
        loadApiUsage();

        // Period change handler
        $period.on('change', loadApiUsage);

        // Refresh button handler
        $refresh.on('click', function() {
            var $btn = $(this);
            $btn.find('.dashicons').addClass('isf-spin');
            loadApiUsage(function() {
                $btn.find('.dashicons').removeClass('isf-spin');
            });
        });

        function loadApiUsage(callback) {
            $.ajax({
                url: isf_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'formflow_get_api_usage',
                    nonce: isf_admin.nonce,
                    period: $period.val()
                },
                success: function(response) {
                    if (response.success) {
                        renderApiUsage(response.data);
                    } else {
                        $content.html('<div class="isf-api-usage-error">Failed to load API usage data.</div>');
                    }
                    if (callback) callback();
                },
                error: function() {
                    $content.html('<div class="isf-api-usage-error">Error loading API usage data.</div>');
                    if (callback) callback();
                }
            });
        }

        function renderApiUsage(data) {
            var stats = data.stats;
            var rateLimits = data.rate_limits;

            var html = '<div class="isf-api-usage-stats">';

            // Summary stats
            html += '<div class="isf-api-usage-summary">';
            html += '<div class="isf-usage-stat">';
            html += '<div class="isf-usage-number">' + stats.total_calls + '</div>';
            html += '<div class="isf-usage-label">Total Calls</div>';
            html += '</div>';
            html += '<div class="isf-usage-stat isf-usage-success">';
            html += '<div class="isf-usage-number">' + stats.successful_calls + '</div>';
            html += '<div class="isf-usage-label">Successful</div>';
            html += '</div>';
            html += '<div class="isf-usage-stat isf-usage-failed">';
            html += '<div class="isf-usage-number">' + stats.failed_calls + '</div>';
            html += '<div class="isf-usage-label">Failed</div>';
            html += '</div>';
            html += '<div class="isf-usage-stat">';
            html += '<div class="isf-usage-number">' + stats.success_rate + '%</div>';
            html += '<div class="isf-usage-label">Success Rate</div>';
            html += '</div>';
            html += '<div class="isf-usage-stat">';
            html += '<div class="isf-usage-number">' + (stats.avg_response_ms || 0) + '<small>ms</small></div>';
            html += '<div class="isf-usage-label">Avg Response</div>';
            html += '</div>';
            html += '</div>';

            // Rate limit status
            if (Object.keys(rateLimits).length > 0) {
                html += '<div class="isf-rate-limits">';
                html += '<h4>Rate Limit Status</h4>';
                html += '<div class="isf-rate-limit-grid">';
                for (var id in rateLimits) {
                    var rl = rateLimits[id];
                    var statusClass = rl.status === 'ok' ? 'ok' : (rl.status === 'warning' ? 'warning' : 'exceeded');
                    html += '<div class="isf-rate-limit-item isf-rl-' + escapeAttr(statusClass) + '">';
                    html += '<div class="isf-rate-limit-name">' + escapeHtml(rl.name || 'Instance ' + id) + '</div>';
                    html += '<div class="isf-rate-limit-bar">';
                    html += '<div class="isf-rate-limit-fill" style="width: ' + Math.min(rl.usage_percent, 100) + '%"></div>';
                    html += '</div>';
                    html += '<div class="isf-rate-limit-info">';
                    html += '<span>' + parseInt(rl.calls_per_minute, 10) + '/' + parseInt(rl.limit, 10) + ' calls/min</span>';
                    html += '<span class="isf-rl-status">' + escapeHtml(String(rl.status).toUpperCase()) + '</span>';
                    html += '</div>';
                    html += '</div>';
                }
                html += '</div>';
                html += '</div>';
            }

            // Endpoint breakdown
            if (stats.by_endpoint && stats.by_endpoint.length > 0) {
                html += '<div class="isf-endpoint-breakdown">';
                html += '<h4>Calls by Endpoint</h4>';
                html += '<table class="isf-endpoint-table">';
                html += '<thead><tr><th>Endpoint</th><th>Calls</th><th>Success</th><th>Avg Response</th></tr></thead>';
                html += '<tbody>';
                for (var i = 0; i < stats.by_endpoint.length; i++) {
                    var ep = stats.by_endpoint[i];
                    html += '<tr>';
                    html += '<td><code>' + escapeHtml(ep.endpoint) + '</code></td>';
                    html += '<td>' + parseInt(ep.count, 10) + '</td>';
                    html += '<td>' + parseInt(ep.success_count, 10) + '</td>';
                    html += '<td>' + Math.round(ep.avg_response || 0) + 'ms</td>';
                    html += '</tr>';
                }
                html += '</tbody></table>';
                html += '</div>';
            }

            html += '</div>';

            $content.html(html);
        }
    }

    // =========================================================================
    // Feature Toggles
    // =========================================================================

    // Toggle feature enabled state
    $(document).on('change', '.isf-feature-checkbox', function() {
        var $checkbox = $(this);
        var $item = $checkbox.closest('.isf-feature-item');
        var $configBtn = $item.find('.isf-configure-feature');
        var featureKey = $checkbox.data('feature');

        if ($checkbox.is(':checked')) {
            $item.addClass('isf-feature-enabled');
            $configBtn.prop('disabled', false);
        } else {
            $item.removeClass('isf-feature-enabled');
            $configBtn.prop('disabled', true);
            // Hide config panel if open
            $('#isf-config-' + featureKey).slideUp(200);
        }
    });

    // Toggle feature configuration panel
    $(document).on('click', '.isf-configure-feature', function() {
        var $btn = $(this);
        var featureKey = $btn.data('feature');
        var $panel = $('#isf-config-' + featureKey);
        var $item = $btn.closest('.isf-feature-item');

        if ($panel.is(':visible')) {
            $panel.slideUp(200);
            $item.css('border-radius', '6px');
        } else {
            // Close other panels first
            $('.isf-feature-config:visible').slideUp(200);
            $('.isf-feature-item').css('border-radius', '6px');

            $panel.slideDown(200);
            $item.css('border-radius', '6px 6px 0 0');
        }
    });

    // Team provider toggle (show/hide help text)
    $(document).on('change', '.isf-team-provider', function() {
        var provider = $(this).val();
        if (provider === 'slack') {
            $('.isf-slack-help').show();
            $('.isf-teams-help').hide();
        } else {
            $('.isf-slack-help').hide();
            $('.isf-teams-help').show();
        }
    });

    // Test SMS button
    $(document).on('click', '#isf-test-sms', function() {
        var $btn = $(this);
        var $result = $('#isf-sms-test-result');
        var testNumber = $('#sms_test_number').val();

        if (!testNumber) {
            $result.removeClass('success').addClass('error').text('Please enter a phone number');
            return;
        }

        $btn.prop('disabled', true).text('Sending...');
        $result.removeClass('success error').text('');

        $.ajax({
            url: isf_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_test_sms',
                nonce: isf_admin.nonce,
                test_number: testNumber,
                account_sid: $('#sms_account_sid').val(),
                auth_token: $('#sms_auth_token').val(),
                from_number: $('#sms_from_number').val()
            },
            success: function(response) {
                if (response.success) {
                    $result.removeClass('error').addClass('success').text(response.data.message);
                } else {
                    $result.removeClass('success').addClass('error').text(response.data.message);
                }
            },
            error: function() {
                $result.removeClass('success').addClass('error').text('Request failed');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Send Test SMS');
            }
        });
    });

    // Test Webhook button
    $(document).on('click', '#isf-test-webhook', function() {
        var $btn = $(this);
        var $result = $('#isf-webhook-test-result');
        var webhookUrl = $('#team_webhook_url').val();
        var provider = $('#team_provider').val();

        if (!webhookUrl) {
            $result.removeClass('success').addClass('error').text('Please enter a webhook URL');
            return;
        }

        $btn.prop('disabled', true).text('Sending...');
        $result.removeClass('success error').text('');

        $.ajax({
            url: isf_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_test_team_webhook',
                nonce: isf_admin.nonce,
                webhook_url: webhookUrl,
                provider: provider
            },
            success: function(response) {
                if (response.success) {
                    $result.removeClass('error').addClass('success').text(response.data.message);
                } else {
                    $result.removeClass('success').addClass('error').text(response.data.message);
                }
            },
            error: function() {
                $result.removeClass('success').addClass('error').text('Request failed');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Send Test Notification');
            }
        });
    });

    // Test Digest button
    $(document).on('click', '#isf-test-digest', function() {
        var $btn = $(this);
        var $result = $('#isf-digest-test-result');
        var testEmail = $('#digest_test_email').val();
        var instanceId = $('input[name="id"]').val();

        if (!testEmail) {
            $result.removeClass('success').addClass('error').text('Please enter an email address');
            return;
        }

        $btn.prop('disabled', true).text('Sending...');
        $result.removeClass('success error').text('');

        $.ajax({
            url: isf_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_test_digest',
                nonce: isf_admin.nonce,
                instance_id: instanceId,
                recipient: testEmail
            },
            success: function(response) {
                if (response.success) {
                    $result.removeClass('error').addClass('success').text(response.data.message);
                } else {
                    $result.removeClass('success').addClass('error').text(response.data.message);
                }
            },
            error: function() {
                $result.removeClass('success').addClass('error').text('Request failed');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Send Test Digest');
            }
        });
    });

})(jQuery);
