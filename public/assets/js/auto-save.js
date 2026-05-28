/**
 * IntelliSource Forms - Auto-Save Module
 *
 * Automatically saves form progress to server and sessionStorage (default) or localStorage.
 * Feature-togglable per instance via FeatureManager.
 *
 * Security note: By default uses sessionStorage which clears on tab close.
 * PII fields are excluded from browser storage to prevent data exposure.
 */

(function($, window) {
    'use strict';

    // Fields considered PII - excluded from browser storage
    var PII_FIELDS = [
        'account_number', 'utility_no', 'social_security',
        'email', 'phone', 'phone_number', 'telephone',
        'first_name', 'last_name', 'fname', 'lname', 'name',
        'street', 'address', 'city', 'zip', 'zip_code',
        'credit_card', 'card_number', 'cvv', 'expiry',
        'password', 'ssn', 'dob', 'date_of_birth'
    ];

    var ISFAutoSave = {
        // Configuration
        config: {
            enabled: true,
            intervalSeconds: 60,
            useLocalStorage: false,      // Default to sessionStorage for security
            useSessionStorage: true,     // Preferred - clears on tab close
            showSaveIndicator: true,
            excludePII: true             // Always exclude PII from browser storage
        },

        // State
        timer: null,
        lastSave: null,
        hasChanges: false,
        isSaving: false,
        storageKey: null,

        /**
         * Initialize auto-save
         */
        init: function(config) {
            // Merge config from PHP
            if (config) {
                this.config = $.extend({}, this.config, config);
            }

            if (!this.config.enabled) {
                return;
            }

            // Generate storage key from instance and session
            var $container = $('.isf-form-container');
            if ($container.length) {
                var instance = $container.data('instance');
                var session = $container.data('session');
                this.storageKey = 'isf_draft_' + instance + '_' + session;
            }

            this.bindEvents();
            this.startTimer();
            this.restoreFromStorage();
        },

        /**
         * Get the appropriate storage object (sessionStorage preferred)
         */
        getStorage: function() {
            try {
                // Prefer sessionStorage for security (clears on tab close)
                if (this.config.useSessionStorage && window.sessionStorage) {
                    return window.sessionStorage;
                }
                // Fall back to localStorage only if explicitly enabled
                if (this.config.useLocalStorage && window.localStorage) {
                    return window.localStorage;
                }
            } catch (e) {
                // Storage might be disabled
            }
            return null;
        },

        /**
         * Check if a field name contains PII
         */
        isPIIField: function(fieldName) {
            if (!this.config.excludePII) return false;

            var lowerName = fieldName.toLowerCase();
            for (var i = 0; i < PII_FIELDS.length; i++) {
                if (lowerName.indexOf(PII_FIELDS[i]) !== -1) {
                    return true;
                }
            }
            return false;
        },

        /**
         * Filter out PII fields from data
         */
        filterPII: function(data) {
            var self = this;
            var filtered = {};

            $.each(data, function(key, value) {
                if (!self.isPIIField(key)) {
                    filtered[key] = value;
                }
            });

            return filtered;
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            var self = this;

            // Track changes on form inputs
            $(document).on('input change', '.isf-step-form input, .isf-step-form select, .isf-step-form textarea', function() {
                self.hasChanges = true;
                self.saveToStorage();
            });

            // Save on step change
            $(document).on('isf:stepLoaded', function(e, step) {
                if (self.hasChanges) {
                    self.save();
                }
            });

            // Save before unload
            $(window).on('beforeunload', function() {
                if (self.hasChanges) {
                    self.saveToStorage();
                }
            });

            // Clear draft on successful completion
            $(document).on('isf:completed', function() {
                self.clearDraft();
            });
        },

        /**
         * Start the auto-save timer
         */
        startTimer: function() {
            var self = this;
            var intervalMs = this.config.intervalSeconds * 1000;

            // Clear any existing timer
            if (this.timer) {
                clearInterval(this.timer);
            }

            this.timer = setInterval(function() {
                if (self.hasChanges && !self.isSaving) {
                    self.save();
                }
            }, intervalMs);
        },

        /**
         * Stop the timer
         */
        stopTimer: function() {
            if (this.timer) {
                clearInterval(this.timer);
                this.timer = null;
            }
        },

        /**
         * Save progress to server
         */
        save: function() {
            var self = this;

            if (this.isSaving) {
                return;
            }

            var $container = $('.isf-form-container');
            if (!$container.length) {
                return;
            }

            // Collect current form data
            var formData = this.collectFormData();

            if (Object.keys(formData).length === 0) {
                return;
            }

            this.isSaving = true;

            $.ajax({
                url: isf_frontend.ajax_url,
                type: 'POST',
                data: {
                    action: 'formflow_save_progress',
                    nonce: isf_frontend.nonce,
                    instance: $container.data('instance'),
                    session_id: $container.data('session'),
                    step: $container.data('step') || 1,
                    form_data: JSON.stringify(formData)
                },
                success: function(response) {
                    if (response.success) {
                        self.hasChanges = false;
                        self.lastSave = new Date();

                        if (self.config.showSaveIndicator) {
                            self.showIndicator();
                        }
                    }
                },
                error: function() {
                    // Silent fail - localStorage backup exists
                },
                complete: function() {
                    self.isSaving = false;
                }
            });
        },

        /**
         * Collect form data from all visible forms
         */
        collectFormData: function() {
            var data = {};

            $('.isf-step-form:visible input, .isf-step-form:visible select, .isf-step-form:visible textarea').each(function() {
                var $field = $(this);
                var name = $field.attr('name');

                if (!name) return;

                if ($field.is(':checkbox')) {
                    data[name] = $field.is(':checked');
                } else if ($field.is(':radio')) {
                    if ($field.is(':checked')) {
                        data[name] = $field.val();
                    }
                } else {
                    data[name] = $field.val();
                }
            });

            return data;
        },

        /**
         * Save to browser storage as backup (sessionStorage preferred, PII excluded)
         */
        saveToStorage: function() {
            var storage = this.getStorage();
            if (!storage || !this.storageKey) {
                return;
            }

            try {
                var data = this.collectFormData();
                var $container = $('.isf-form-container');

                // Filter out PII fields for security
                var safeData = this.filterPII(data);

                var stored = {
                    timestamp: Date.now(),
                    step: $container.data('step') || 1,
                    data: safeData,
                    version: 2  // Schema version for future compatibility
                };

                storage.setItem(this.storageKey, JSON.stringify(stored));
            } catch (e) {
                // Storage might be full or disabled
            }
        },

        /**
         * Restore from browser storage if available
         */
        restoreFromStorage: function() {
            var storage = this.getStorage();
            if (!storage || !this.storageKey) {
                return;
            }

            try {
                var stored = storage.getItem(this.storageKey);
                if (!stored) return;

                var parsed = JSON.parse(stored);

                // Check if data is recent
                var age = Date.now() - parsed.timestamp;
                // Use shorter expiry for sessionStorage (1 day) vs localStorage (7 days)
                var maxAge = this.config.useSessionStorage
                    ? 24 * 60 * 60 * 1000      // 1 day for sessionStorage
                    : 7 * 24 * 60 * 60 * 1000; // 7 days for localStorage

                if (age > maxAge) {
                    storage.removeItem(this.storageKey);
                    return;
                }

                // Don't auto-restore - just show option
                if (Object.keys(parsed.data).length > 0) {
                    this.showRestorePrompt(parsed);
                }
            } catch (e) {
                // Invalid data - clear it
                try {
                    storage.removeItem(this.storageKey);
                } catch (e2) {
                    // Ignore
                }
            }
        },

        /**
         * Show prompt to restore saved data
         */
        showRestorePrompt: function(stored) {
            var self = this;
            var savedDate = new Date(stored.timestamp);
            var timeAgo = this.formatTimeAgo(savedDate);

            var $prompt = $('<div class="isf-restore-prompt">' +
                '<div class="isf-restore-content">' +
                '<span class="isf-restore-icon">' +
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">' +
                '<path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/>' +
                '</svg></span>' +
                '<span class="isf-restore-text">You have unsaved progress from ' + timeAgo + '</span>' +
                '</div>' +
                '<div class="isf-restore-actions">' +
                '<button type="button" class="isf-restore-btn isf-restore-yes">Restore</button>' +
                '<button type="button" class="isf-restore-btn isf-restore-no">Start Fresh</button>' +
                '</div>' +
                '</div>');

            // Bind actions
            $prompt.find('.isf-restore-yes').on('click', function() {
                self.restoreData(stored.data);
                $prompt.fadeOut(200, function() { $(this).remove(); });
            });

            $prompt.find('.isf-restore-no').on('click', function() {
                self.clearDraft();
                $prompt.fadeOut(200, function() { $(this).remove(); });
            });

            // Insert at top of form
            $('.isf-form-container').prepend($prompt);
        },

        /**
         * Restore data to form fields
         */
        restoreData: function(data) {
            $.each(data, function(name, value) {
                var $field = $('[name="' + name + '"]');
                if (!$field.length) return;

                if ($field.is(':checkbox')) {
                    $field.prop('checked', !!value);
                } else if ($field.is(':radio')) {
                    $field.filter('[value="' + value + '"]').prop('checked', true);
                } else {
                    $field.val(value);
                }

                $field.trigger('change');
            });

            // Show confirmation
            this.showNotification('Progress restored!', 'success');
        },

        /**
         * Clear draft from browser storage
         */
        clearDraft: function() {
            if (this.storageKey) {
                var storage = this.getStorage();
                if (storage) {
                    try {
                        storage.removeItem(this.storageKey);
                    } catch (e) {
                        // Ignore
                    }
                }
            }
        },

        /**
         * Show auto-save indicator
         */
        showIndicator: function() {
            var $indicator = $('#isf-autosave-indicator');

            if (!$indicator.length) {
                $indicator = $('<div id="isf-autosave-indicator" class="isf-autosave-indicator">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16">' +
                    '<path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>' +
                    '</svg> ' +
                    '<span class="isf-autosave-text">' + (isf_frontend.strings.autosaved || 'Auto-saved') + '</span>' +
                    '</div>');
                $('.isf-form-container').append($indicator);
            }

            $indicator.addClass('show');

            setTimeout(function() {
                $indicator.removeClass('show');
            }, 2000);
        },

        /**
         * Show notification message
         */
        showNotification: function(message, type) {
            var $notification = $('<div class="isf-notification isf-notification-' + type + '">' +
                '<span>' + message + '</span>' +
                '</div>');

            $('.isf-form-container').append($notification);

            setTimeout(function() {
                $notification.fadeOut(300, function() { $(this).remove(); });
            }, 3000);
        },

        /**
         * Format time ago string
         */
        formatTimeAgo: function(date) {
            var seconds = Math.floor((new Date() - date) / 1000);

            if (seconds < 60) return 'just now';
            if (seconds < 3600) return Math.floor(seconds / 60) + ' minutes ago';
            if (seconds < 86400) return Math.floor(seconds / 3600) + ' hours ago';
            if (seconds < 604800) return Math.floor(seconds / 86400) + ' days ago';

            return date.toLocaleDateString();
        },

        /**
         * Force save immediately
         */
        forceSave: function() {
            this.hasChanges = true;
            this.save();
        },

        /**
         * Get last save time
         */
        getLastSave: function() {
            return this.lastSave;
        },

        /**
         * Check if there are unsaved changes
         */
        hasUnsavedChanges: function() {
            return this.hasChanges;
        }
    };

    // Export to window
    window.ISFAutoSave = ISFAutoSave;

    // Auto-initialize when DOM ready if config available
    $(document).ready(function() {
        if (typeof isf_frontend !== 'undefined' && isf_frontend.features) {
            var autoSaveConfig = isf_frontend.features.auto_save || {};
            ISFAutoSave.init({
                enabled: autoSaveConfig.enabled !== false,
                intervalSeconds: autoSaveConfig.interval_seconds || 60,
                // Default to sessionStorage (more secure), only use localStorage if explicitly set
                useSessionStorage: autoSaveConfig.use_session_storage !== false,
                useLocalStorage: autoSaveConfig.use_local_storage === true,
                showSaveIndicator: autoSaveConfig.show_save_indicator !== false,
                excludePII: true  // Always exclude PII from browser storage
            });
        } else {
            // Default initialization - secure defaults
            ISFAutoSave.init();
        }
    });

})(jQuery, window);
