/**
 * FormFlow Accessibility Module
 *
 * Implements WCAG 2.1 AA compliance features including:
 * - Keyboard navigation
 * - Screen reader announcements
 * - Focus management
 * - Error handling
 *
 * @package FormFlow
 */

(function($) {
    'use strict';

    if (typeof ISFAccessibility === 'undefined') {
        return;
    }

    const A11y = {
        config: ISFAccessibility,
        liveRegion: null,
        alertRegion: null,
        currentStep: 1,
        totalSteps: 1,

        /**
         * Initialize accessibility features
         */
        init: function() {
            if (!this.config.enabled) {
                return;
            }

            this.setupLiveRegions();
            this.setupKeyboardNavigation();
            this.setupFocusManagement();
            this.setupErrorAnnouncements();
            this.setupProgressAnnouncements();
            this.enhanceFormFields();
            this.setupReducedMotion();

            // Announce page load
            this.announcePolite(this.config.strings.loading);
        },

        /**
         * Setup ARIA live regions for announcements
         */
        setupLiveRegions: function() {
            // Find existing live regions or create new ones
            this.liveRegion = $('[role="status"][aria-live="polite"]').first();
            this.alertRegion = $('[role="alert"][aria-live="assertive"]').first();

            if (!this.liveRegion.length) {
                this.liveRegion = $('<div/>', {
                    'class': 'isf-sr-only',
                    'role': 'status',
                    'aria-live': 'polite',
                    'aria-atomic': 'true'
                }).appendTo('body');
            }

            if (!this.alertRegion.length) {
                this.alertRegion = $('<div/>', {
                    'class': 'isf-sr-only',
                    'role': 'alert',
                    'aria-live': 'assertive',
                    'aria-atomic': 'true'
                }).appendTo('body');
            }
        },

        /**
         * Announce message politely (queued)
         */
        announcePolite: function(message) {
            if (!this.liveRegion || !message) return;

            // Clear and set to trigger announcement
            this.liveRegion.text('');
            setTimeout(() => {
                this.liveRegion.text(message);
            }, 100);
        },

        /**
         * Announce message assertively (interrupts)
         */
        announceAssertive: function(message) {
            if (!this.alertRegion || !message) return;

            this.alertRegion.text('');
            setTimeout(() => {
                this.alertRegion.text(message);
            }, 100);
        },

        /**
         * Setup keyboard navigation
         */
        setupKeyboardNavigation: function() {
            if (!this.config.settings.keyboard_navigation) {
                return;
            }

            const self = this;

            // Handle Enter key on form fields (don't submit, go to next field)
            $(document).on('keydown', '.isf-form-container input:not([type="submit"])', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    self.focusNextField($(this));
                }
            });

            // Handle Escape to clear field
            $(document).on('keydown', '.isf-form-container input, .isf-form-container select, .isf-form-container textarea', function(e) {
                if (e.key === 'Escape') {
                    $(this).blur();
                }
            });

            // Arrow key navigation between steps
            $(document).on('keydown', '.isf-form-container', function(e) {
                if (e.altKey) {
                    if (e.key === 'ArrowLeft') {
                        e.preventDefault();
                        self.navigateToPreviousStep();
                    } else if (e.key === 'ArrowRight') {
                        e.preventDefault();
                        self.navigateToNextStep();
                    }
                }
            });

            // Tab trap within modal dialogs
            $(document).on('keydown', '.isf-modal', function(e) {
                if (e.key === 'Tab') {
                    self.trapFocus($(this), e);
                }
            });

            // Keyboard shortcuts help
            $(document).on('keydown', function(e) {
                // Alt + H for help
                if (e.altKey && e.key === 'h') {
                    e.preventDefault();
                    self.showKeyboardHelp();
                }
            });
        },

        /**
         * Focus next form field
         */
        focusNextField: function($current) {
            const $container = $current.closest('.isf-form-container');
            const $fields = $container.find('input:visible, select:visible, textarea:visible, button:visible');
            const currentIndex = $fields.index($current);

            if (currentIndex < $fields.length - 1) {
                $fields.eq(currentIndex + 1).focus();
            }
        },

        /**
         * Navigate to previous step
         */
        navigateToPreviousStep: function() {
            const $prevBtn = $('.isf-btn-prev:visible');
            if ($prevBtn.length) {
                $prevBtn.click();
                this.announcePolite(this.config.strings.navigating_to_step.replace('%d', this.currentStep - 1));
            }
        },

        /**
         * Navigate to next step
         */
        navigateToNextStep: function() {
            const $nextBtn = $('.isf-btn-next:visible');
            if ($nextBtn.length) {
                $nextBtn.click();
            }
        },

        /**
         * Trap focus within element (for modals)
         */
        trapFocus: function($container, e) {
            const $focusable = $container.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible');
            const $first = $focusable.first();
            const $last = $focusable.last();

            if (e.shiftKey) {
                if (document.activeElement === $first[0]) {
                    e.preventDefault();
                    $last.focus();
                }
            } else {
                if (document.activeElement === $last[0]) {
                    e.preventDefault();
                    $first.focus();
                }
            }
        },

        /**
         * Setup focus management
         */
        setupFocusManagement: function() {
            const self = this;

            // Move focus to first error on validation failure
            $(document).on('isf:validation_error', function(e, data) {
                const $firstError = $('.isf-field-error:visible, [aria-invalid="true"]:visible').first();
                if ($firstError.length) {
                    $firstError.focus();
                }
            });

            // Move focus to new step content
            $(document).on('isf:step_changed', function(e, data) {
                self.currentStep = data.step;
                self.totalSteps = data.totalSteps;

                // Focus on step heading or first field
                setTimeout(function() {
                    const $step = $('.isf-step.active');
                    const $heading = $step.find('h2, h3, .isf-step-title').first();

                    if ($heading.length) {
                        $heading.attr('tabindex', '-1').focus();
                    } else {
                        $step.find('input, select, textarea').first().focus();
                    }

                    // Announce step change
                    if (self.config.settings.progress_announcements) {
                        const stepName = $heading.text() || 'Step ' + data.step;
                        const message = self.config.strings.step_progress
                            .replace('%1$d', data.step)
                            .replace('%2$d', data.totalSteps)
                            .replace('%3$s', stepName);
                        self.announcePolite(message);
                    }
                }, 100);
            });

            // Focus on success message after form submission
            $(document).on('isf:form_submitted', function() {
                const $success = $('.isf-success-message');
                if ($success.length) {
                    $success.attr('tabindex', '-1').focus();
                    self.announcePolite(self.config.strings.form_submitted);
                }
            });
        },

        /**
         * Setup error announcements
         */
        setupErrorAnnouncements: function() {
            if (!this.config.settings.error_announcements) {
                return;
            }

            const self = this;

            // Announce field errors on blur
            $(document).on('blur', '.isf-form-container input, .isf-form-container select, .isf-form-container textarea', function() {
                const $field = $(this);
                const $wrapper = $field.closest('.isf-field-wrapper');
                const $error = $wrapper.find('.isf-field-error');

                if ($error.length && $error.text()) {
                    const fieldName = $wrapper.find('label').text().replace('*', '').trim();
                    const errorMsg = $error.text();
                    const message = self.config.strings.field_error
                        .replace('%s', fieldName)
                        .replace('%s', errorMsg);
                    self.announceAssertive(message);
                }
            });

            // Announce form-level errors
            $(document).on('isf:form_error', function(e, data) {
                self.announceAssertive(self.config.strings.form_error);
            });

            // Announce field validation success
            $(document).on('isf:field_valid', function(e, data) {
                if (data.fieldName) {
                    self.announcePolite(self.config.strings.field_valid.replace('%s', data.fieldName));
                }
            });
        },

        /**
         * Setup progress announcements
         */
        setupProgressAnnouncements: function() {
            if (!this.config.settings.progress_announcements) {
                return;
            }

            const self = this;

            // Update progress bar accessibility
            $(document).on('isf:progress_updated', function(e, data) {
                const $progressBar = $('.isf-progress-bar');
                if ($progressBar.length) {
                    $progressBar.attr({
                        'role': 'progressbar',
                        'aria-valuenow': data.percent,
                        'aria-valuemin': 0,
                        'aria-valuemax': 100,
                        'aria-label': data.percent + '% complete'
                    });
                }
            });
        },

        /**
         * Enhance form fields with accessibility attributes
         */
        enhanceFormFields: function() {
            const self = this;

            // Add aria-describedby for help text
            $('.isf-form-container .isf-field-wrapper').each(function() {
                const $wrapper = $(this);
                const $input = $wrapper.find('input, select, textarea');
                const $help = $wrapper.find('.isf-help-text, .description');
                const $error = $wrapper.find('.isf-field-error');

                const describedby = [];

                if ($help.length) {
                    const helpId = $help.attr('id') || $input.attr('id') + '_help';
                    $help.attr('id', helpId);
                    describedby.push(helpId);
                }

                if ($error.length) {
                    const errorId = $error.attr('id') || $input.attr('id') + '_error';
                    $error.attr('id', errorId);
                    describedby.push(errorId);

                    // Mark field as invalid
                    $input.attr('aria-invalid', 'true');
                }

                if (describedby.length) {
                    $input.attr('aria-describedby', describedby.join(' '));
                }
            });

            // Add required indicator accessibility
            $('.isf-form-container .isf-required').each(function() {
                $(this).attr('aria-hidden', 'true');
            });

            // Ensure all form fields have labels
            $('.isf-form-container input, .isf-form-container select, .isf-form-container textarea').each(function() {
                const $input = $(this);
                const id = $input.attr('id');

                if (id) {
                    const $label = $('label[for="' + id + '"]');
                    if (!$label.length && !$input.attr('aria-label')) {
                        // Try to find nearby label
                        const $nearLabel = $input.closest('.isf-field-wrapper').find('label').first();
                        if ($nearLabel.length) {
                            $input.attr('aria-label', $nearLabel.text().replace('*', '').trim());
                        }
                    }
                }
            });

            // Add role and aria to progress steps
            $('.isf-progress-steps').attr('role', 'navigation').attr('aria-label', 'Form progress');
            $('.isf-progress-step').each(function(index) {
                $(this).attr({
                    'role': 'listitem',
                    'aria-current': $(this).hasClass('active') ? 'step' : null
                });
            });
        },

        /**
         * Setup reduced motion support
         */
        setupReducedMotion: function() {
            // Check for user preference
            const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (prefersReducedMotion || this.config.settings.reduce_motion) {
                document.documentElement.classList.add('isf-reduce-motion');
            }

            // Listen for changes
            window.matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', function(e) {
                if (e.matches) {
                    document.documentElement.classList.add('isf-reduce-motion');
                } else if (!this.config.settings.reduce_motion) {
                    document.documentElement.classList.remove('isf-reduce-motion');
                }
            });
        },

        /**
         * Show keyboard shortcuts help
         */
        showKeyboardHelp: function() {
            if ($('.isf-keyboard-help').length) return;
            const previousFocus = document.activeElement;
            const shortcuts = [
                { key: 'Tab', action: 'Move to next field' },
                { key: 'Shift + Tab', action: 'Move to previous field' },
                { key: 'Enter', action: 'Move to next field (in text inputs)' },
                { key: 'Alt + Left Arrow', action: 'Go to previous step' },
                { key: 'Alt + Right Arrow', action: 'Go to next step' },
                { key: 'Escape', action: 'Exit current field' },
                { key: 'Alt + H', action: 'Show this help' }
            ];

            let html = '<div class="isf-keyboard-help" role="dialog" aria-labelledby="isf-help-title" aria-modal="true">';
            html += '<h2 id="isf-help-title">Keyboard Shortcuts</h2>';
            html += '<table><tbody>';

            shortcuts.forEach(function(s) {
                html += '<tr><td><kbd>' + s.key + '</kbd></td><td>' + s.action + '</td></tr>';
            });

            html += '</tbody></table>';
            html += '<button type="button" class="isf-btn isf-btn-secondary isf-close-help">Close</button>';
            html += '</div>';
            html += '<div class="isf-overlay"></div>';

            const $help = $(html).appendTo('body');

            // Focus trap
            $help.find('.isf-close-help').focus();

            const closeHelp = function() {
                $help.remove();
                $(document).off('keydown.isfhelp');
                if (previousFocus && previousFocus.isConnected) previousFocus.focus();
            };

            $help.filter('.isf-overlay').on('click', closeHelp);
            $help.find('.isf-close-help').on('click', closeHelp);

            $(document).on('keydown.isfhelp', function(e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    closeHelp();
                } else if (e.key === 'Tab') {
                    e.preventDefault();
                    $help.find('.isf-close-help').focus();
                }
            });
        },

        /**
         * Announce loading state
         */
        announceLoading: function(isLoading) {
            if (isLoading) {
                this.announcePolite(this.config.strings.loading);
            }
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        A11y.init();
    });

    // Expose for external use
    window.ISFa11y = A11y;

})(jQuery);
