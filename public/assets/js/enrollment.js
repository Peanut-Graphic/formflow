/**
 * IntelliSource Forms - Enrollment JavaScript
 *
 * Multi-step enrollment form functionality
 */

(function($) {
    'use strict';

    // Form state
    var ISFEnrollment = {
        currentStep: 1,
        totalSteps: 5,
        formData: {},
        sessionId: '',
        instanceSlug: '',
        formType: 'enrollment',
        isSubmitting: false,
        scheduleData: null,
        // Analytics tracking
        stepStartTime: null,
        // Auto-save
        autoSaveTimer: null,
        lastAutoSave: null,
        hasUnsavedChanges: false,
        // Resume token
        resumeToken: null,
        stepNames: {
            enrollment: {
                1: 'Program Selection',
                2: 'Account Validation',
                3: 'Customer Information',
                4: 'Schedule Appointment',
                5: 'Confirmation'
            },
            scheduler: {
                1: 'Account Verification',
                2: 'Select Appointment'
            }
        }
    };

    /**
     * Initialize the enrollment form
     */
    function init() {
        var $container = $('.isf-form-container');
        if (!$container.length) return;

        ISFEnrollment.instanceSlug = $container.data('instance');
        ISFEnrollment.sessionId = $container.data('session');
        ISFEnrollment.currentStep = parseInt($container.data('step')) || 1;
        ISFEnrollment.formType = $container.data('form-type') || 'enrollment';
        ISFEnrollment.totalSteps = ISFEnrollment.formType === 'scheduler' ? 2 : 5;

        // Check for resume token in URL
        var urlParams = new URLSearchParams(window.location.search);
        ISFEnrollment.resumeToken = urlParams.get('isf_resume');

        bindEvents();

        // Only update progress bar for enrollment forms
        if (ISFEnrollment.formType === 'enrollment') {
            updateProgressBar();
        }

        // If there's a resume token, try to restore session
        if (ISFEnrollment.resumeToken) {
            resumeFromToken();
        } else {
            // Track initial step entry
            trackStepEvent('enter', ISFEnrollment.currentStep);
        }

        // Start auto-save timer
        startAutoSave();

        // Initialize Google Places if available
        initGooglePlaces();

        // Track abandonment on page unload
        $(window).on('beforeunload', function() {
            if (ISFEnrollment.currentStep > 0 && ISFEnrollment.currentStep <= ISFEnrollment.totalSteps) {
                // Use sendBeacon for reliable tracking on page close
                trackStepEvent('abandon', ISFEnrollment.currentStep, true);
            }
        });
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Device selection (Step 1 - enrollment only)
        $(document).on('change', '.isf-device-option input', handleDeviceSelection);
        $(document).on('click', '.isf-device-info', handleDeviceInfoPopup);

        // WiFi eligibility gate (only rendered on instances that enabled it)
        $(document).on('change', 'input[name="has_wifi"]', handleWifiAnswer);
        $(document).on('click', '.isf-convert-to-dcu', handleConvertToDcu);

        // Enrollment form submissions
        $(document).on('submit', '#isf-step-1-form', handleStep1Submit);
        $(document).on('submit', '#isf-step-2-form', handleStep2Submit);
        $(document).on('submit', '#isf-step-3-form', handleStep3Submit);
        $(document).on('submit', '#isf-step-4-form', handleStep4Submit);
        $(document).on('submit', '#isf-step-5-form', handleStep5Submit);

        // Scheduler form submissions
        $(document).on('submit', '#isf-scheduler-step-1-form', handleSchedulerStep1Submit);
        $(document).on('submit', '#isf-scheduler-step-2-form', handleSchedulerStep2Submit);

        // Navigation
        $(document).on('click', '.isf-btn-prev', handlePrevious);
        $(document).on('click', '.isf-edit-link', handleEditLink);

        // Help toggles
        $(document).on('click', '#isf-account-help-toggle', toggleAccountHelp);

        // Alert dismissal
        $(document).on('click', '.isf-alert-close', dismissAlert);

        // Popup handling
        $(document).on('click', '.isf-popup', handlePopupBackdropClick);
        $(document).on('click', '.isf-popup-close', closePopup);
        $(document).on('click', '[data-popup]', openPopup);

        // Schedule selection
        $(document).on('click', '.isf-calendar-day.available', handleDateSelection);
        $(document).on('click', '.isf-timeslot', handleTimeSelection);
        $(document).on('click', '.isf-calendar-prev', navigateCalendarPrev);
        $(document).on('click', '.isf-calendar-next', navigateCalendarNext);

        // Phone formatting
        $(document).on('input', '#phone, #alt_phone', formatPhoneNumber);

        // Email confirmation
        $(document).on('blur', '#email_confirm', validateEmailMatch);

        // Promo code "Other" toggle
        $(document).on('change', '#promo_code', handlePromoCodeChange);

        // Ownership "Lease" toggle for owner approval checkbox
        $(document).on('change', '#ownership', handleOwnershipChange);

        // Cycling level radio selection styling
        $(document).on('change', '.isf-cycling-options input[type="radio"]', handleCyclingSelection);

        // Save and continue later
        $(document).on('click', '.isf-save-later-btn', showSaveAndContinueModal);
        $(document).on('submit', '#isf-save-later-form', handleSaveAndContinue);

        // Form field changes for auto-save
        $(document).on('input change', '.isf-step-form input, .isf-step-form select, .isf-step-form textarea', function() {
            ISFEnrollment.hasUnsavedChanges = true;
        });

        // Real-time validation
        $(document).on('blur', '.isf-input[required], .isf-select[required]', validateFieldOnBlur);
        $(document).on('input', '#email', validateEmailFormat);
        $(document).on('input', '#zip, #zip_confirm', validateZipFormat);
    }

    /**
     * Handle promo code dropdown change
     */
    function handlePromoCodeChange() {
        var $select = $(this);
        var $otherWrap = $('#isf-promo-other-wrap');

        if ($select.val() === 'other') {
            $otherWrap.slideDown(200);
            $otherWrap.find('input').focus();
        } else {
            $otherWrap.slideUp(200);
            $otherWrap.find('input').val('');
        }
    }

    /**
     * Handle ownership dropdown change - show/hide owner approval checkbox
     */
    function handleOwnershipChange() {
        var $select = $(this);
        var $approvalWrap = $('#isf-owner-approval-wrap');

        if ($select.val() === 'lease') {
            $approvalWrap.slideDown(200);
        } else {
            $approvalWrap.slideUp(200);
            $approvalWrap.find('input').prop('checked', false);
        }
    }

    /**
     * Handle cycling level radio selection - update visual styling
     */
    function handleCyclingSelection() {
        var $radio = $(this);
        var $option = $radio.closest('.isf-radio-option');

        // Remove selected from all options
        $('.isf-cycling-options .isf-radio-option').removeClass('selected');

        // Add selected to current option
        $option.addClass('selected');
    }

    /**
     * Handle device selection visual feedback
     */
    function handleDeviceSelection() {
        var $option = $(this).closest('.isf-device-option');
        $('.isf-device-option').removeClass('selected');
        $option.addClass('selected');

        syncWifiVisibility($(this).val());
    }

    /**
     * The WiFi question only applies to the thermostat.
     *
     * Selecting the switch must clear the answer as well as hide the field:
     * a stale "no" left behind by a customer who changed their mind would sit
     * in the submission and misreport why they chose the switch.
     */
    function syncWifiVisibility(deviceType) {
        var $check = $('#isf-wifi-check');

        if (!$check.length) {
            return; // Instance has not enabled the gate.
        }

        if (deviceType === 'thermostat') {
            $check.prop('hidden', false);
            return;
        }

        $check.prop('hidden', true);
        $('#isf-wifi-callout').prop('hidden', true);
        $('input[name="has_wifi"]').prop('checked', false);
    }

    /**
     * Reveal the conversion offer when the customer says they have no WiFi.
     */
    function handleWifiAnswer() {
        $('#isf-wifi-callout').prop('hidden', $(this).val() !== 'no');
    }

    /**
     * Convert a WiFi-ineligible thermostat enrollment into a switch enrollment.
     *
     * Moves the visible selection too, so the customer sees what changed rather
     * than being silently reassigned to a different device.
     */
    function handleConvertToDcu(e) {
        e.preventDefault();

        var $dcu = $('.isf-device-option input[value="dcu"]');
        $dcu.prop('checked', true);
        $('.isf-device-option').removeClass('selected');
        $dcu.closest('.isf-device-option').addClass('selected');

        ISFEnrollment.formData.device_type = 'dcu';
        ISFEnrollment.formData.has_wifi = 'no';
        // Distinguishes a gate-driven conversion from someone who chose the
        // switch outright. Without it the feature cannot be measured.
        ISFEnrollment.formData.device_converted = 1;

        $('#isf-wifi-callout').prop('hidden', true);
        $('#isf-wifi-check').prop('hidden', true);

        goToStep(2);
    }

    /**
     * Handle device info popup links
     */
    function handleDeviceInfoPopup(e) {
        e.preventDefault();
        e.stopPropagation();
        var popupId = $(this).data('popup');
        openPopupById(popupId);
    }

    /**
     * Step 1: Program Selection
     */
    function handleStep1Submit(e) {
        e.preventDefault();

        var $form = $(this);
        var hasAc = $form.find('#has_ac').is(':checked');
        var deviceType = $form.find('input[name="device_type"]:checked').val();

        if (!hasAc) {
            showAlert('Please confirm that you have a Central Air Conditioner or Heat Pump.', 'error');
            return;
        }

        if (!deviceType) {
            showAlert('Please select a device type.', 'error');
            return;
        }

        // WiFi eligibility gate. Only present on instances that enabled it, so
        // its absence must never block anyone.
        var $wifiCheck = $('#isf-wifi-check');
        var hasWifi = $form.find('input[name="has_wifi"]:checked').val();

        if ($wifiCheck.length && deviceType === 'thermostat') {
            if (!hasWifi) {
                showAlert('Please let us know whether your home has WiFi.', 'error');
                return;
            }

            if (hasWifi !== 'yes') {
                // Do not advance. The callout is already open and carries the
                // offer to switch, which is where this customer should go.
                $('#isf-wifi-callout').prop('hidden', false);
                return;
            }
        }

        ISFEnrollment.formData.has_ac = hasAc;
        ISFEnrollment.formData.device_type = deviceType;

        if ($wifiCheck.length) {
            ISFEnrollment.formData.has_wifi = deviceType === 'thermostat' ? hasWifi : null;
        }

        goToStep(2);
    }

    /**
     * Step 2: Account Validation
     */
    function handleStep2Submit(e) {
        e.preventDefault();

        if (ISFEnrollment.isSubmitting) return;

        var $form = $(this);
        var $button = $form.find('.isf-btn-next');
        var utilityNo = $form.find('#utility_no').val().trim();
        var zip = $form.find('#zip').val().trim();
        var cyclingLevel = $form.find('input[name="cycling_level"]:checked').val() || '100';

        if (!utilityNo || !zip) {
            showAlert('Please enter both your account number and ZIP code.', 'error');
            return;
        }

        if (!/^\d{5}$/.test(zip)) {
            showAlert('Please enter a valid 5-digit ZIP code.', 'error');
            return;
        }

        // Store cycling level before API call
        ISFEnrollment.formData.cycling_level = cyclingLevel;

        setButtonLoading($button, true);
        ISFEnrollment.isSubmitting = true;

        $.ajax({
            url: isf_frontend.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_validate_account',
                nonce: isf_frontend.nonce,
                instance: ISFEnrollment.instanceSlug,
                session_id: ISFEnrollment.sessionId,
                utility_no: utilityNo,
                zip: zip
            },
            success: function(response) {
                if (response.success) {
                    ISFEnrollment.formData.utility_no = utilityNo;
                    ISFEnrollment.formData.zip = zip;

                    // Store validated data
                    if (response.data.customer) {
                        ISFEnrollment.formData.validated_first_name = response.data.customer.first_name || '';
                        ISFEnrollment.formData.validated_last_name = response.data.customer.last_name || '';
                        ISFEnrollment.formData.validated_street = response.data.customer.street || '';
                        ISFEnrollment.formData.validated_city = response.data.customer.city || '';
                        ISFEnrollment.formData.validated_state = response.data.customer.state || '';
                        ISFEnrollment.formData.validated_zip = response.data.customer.zip || zip;
                        ISFEnrollment.formData.account_number = response.data.customer.ca_no || '';
                    }

                    // Check if medical acknowledgment is required
                    if (response.data.requires_medical_acknowledgment) {
                        showMedicalAcknowledgmentModal(response.data.medical_message, function() {
                            ISFEnrollment.formData.medical_acknowledgment = true;
                            goToStep(3);
                        });
                    } else {
                        goToStep(3);
                    }
                } else {
                    showAlert(response.data.message || isf_frontend.strings.validation_error, 'error');
                }
            },
            error: function() {
                showAlert(isf_frontend.strings.network_error, 'error');
            },
            complete: function() {
                setButtonLoading($button, false);
                ISFEnrollment.isSubmitting = false;
            }
        });
    }

    /**
     * Step 3: Customer Information
     */
    function handleStep3Submit(e) {
        e.preventDefault();

        var $form = $(this);
        var formValid = validateStep3Form($form);

        if (!formValid) return;

        // Collect all form data
        ISFEnrollment.formData.first_name = $form.find('#first_name').val().trim();
        ISFEnrollment.formData.last_name = $form.find('#last_name').val().trim();
        ISFEnrollment.formData.email = $form.find('#email').val().trim();
        ISFEnrollment.formData.phone = $form.find('#phone').val().trim();
        ISFEnrollment.formData.phone_type = $form.find('#phone_type').val();
        ISFEnrollment.formData.alt_phone = $form.find('#alt_phone').val().trim();
        ISFEnrollment.formData.alt_phone_type = $form.find('#alt_phone_type').val();
        ISFEnrollment.formData.street = $form.find('#street').val().trim();
        ISFEnrollment.formData.street2 = $form.find('#street2').val().trim();
        ISFEnrollment.formData.city = $form.find('#city').val().trim();
        ISFEnrollment.formData.state = $form.find('#state').val();
        ISFEnrollment.formData.zip_confirm = $form.find('#zip_confirm').val().trim();

        // Property information
        ISFEnrollment.formData.ownership = $form.find('#ownership').val();
        ISFEnrollment.formData.owner_approval = $form.find('#owner_approval').is(':checked');
        ISFEnrollment.formData.thermostat_count = $form.find('#thermostat_count').val() || '1';

        // DCU-specific fields
        if ($form.find('#easy_access').length) {
            ISFEnrollment.formData.easy_access = $form.find('#easy_access').val();
        }
        if ($form.find('#install_time').length) {
            ISFEnrollment.formData.install_time = $form.find('#install_time').val();
        }

        // Additional info
        ISFEnrollment.formData.special_instructions = $form.find('#special_instructions').val().trim();

        // Handle promo code - check for "other" option
        var promoCode = $form.find('#promo_code').val();
        if (promoCode === 'other') {
            ISFEnrollment.formData.promo_code = $form.find('#promo_code_other').val().trim() || 'Other';
            ISFEnrollment.formData.promo_code_other = $form.find('#promo_code_other').val().trim();
        } else {
            ISFEnrollment.formData.promo_code = promoCode ? promoCode.trim() : '';
            ISFEnrollment.formData.promo_code_other = '';
        }

        goToStep(4);
    }

    /**
     * Validate Step 3 form
     */
    function validateStep3Form($form) {
        var email = $form.find('#email').val().trim();
        var emailConfirm = $form.find('#email_confirm').val().trim();

        if (email !== emailConfirm) {
            showAlert('Email addresses do not match.', 'error');
            $form.find('#email_confirm').focus();
            return false;
        }

        if (!isValidEmail(email)) {
            showAlert('Please enter a valid email address.', 'error');
            $form.find('#email').focus();
            return false;
        }

        var phone = $form.find('#phone').val().trim();
        if (!isValidPhone(phone)) {
            showAlert('Please enter a valid phone number.', 'error');
            $form.find('#phone').focus();
            return false;
        }

        // Validate ownership
        var ownership = $form.find('#ownership').val();
        if (!ownership) {
            showAlert('Please select whether you lease or own the property.', 'error');
            $form.find('#ownership').focus();
            return false;
        }

        // If lease, must confirm owner approval
        if (ownership === 'lease' && !$form.find('#owner_approval').is(':checked')) {
            showAlert('You must confirm owner approval to install the device.', 'error');
            $form.find('#owner_approval').focus();
            return false;
        }

        // Validate thermostat count
        var thermostatCount = $form.find('#thermostat_count').val();
        if (thermostatCount === '' || thermostatCount === null) {
            showAlert('Please select the number of thermostats.', 'error');
            $form.find('#thermostat_count').focus();
            return false;
        }

        return true;
    }

    /**
     * Step 4: Schedule Selection
     * Scheduling is optional - users can skip and schedule later
     */
    function handleStep4Submit(e) {
        e.preventDefault();

        var scheduleDate = $('#schedule_date').val();
        var scheduleTime = $('#schedule_time').val();

        // Scheduling is optional - if they selected a date/time, save it
        if (scheduleDate && scheduleTime) {
            ISFEnrollment.formData.schedule_date = scheduleDate;
            ISFEnrollment.formData.schedule_time = scheduleTime;
            ISFEnrollment.formData.schedule_fsr = $('#schedule_fsr').val();
            ISFEnrollment.formData.schedule_later = false;
        } else {
            // Mark as "schedule later"
            ISFEnrollment.formData.schedule_date = '';
            ISFEnrollment.formData.schedule_time = '';
            ISFEnrollment.formData.schedule_fsr = '';
            ISFEnrollment.formData.schedule_later = true;
        }

        goToStep(5);
    }

    /**
     * Step 5: Confirmation/Submission
     */
    function handleStep5Submit(e) {
        e.preventDefault();

        if (ISFEnrollment.isSubmitting) return;

        var $form = $(this);
        var $button = $form.find('.isf-btn-submit');

        if (!$form.find('#agree_terms').is(':checked')) {
            showAlert('Please agree to the Terms and Conditions.', 'error');
            return;
        }

        if (!$form.find('#agree_adult').is(':checked')) {
            showAlert('Please confirm that you are at least 18 years old.', 'error');
            return;
        }

        ISFEnrollment.formData.agree_terms = true;
        ISFEnrollment.formData.agree_adult = true;
        ISFEnrollment.formData.agree_contact = $form.find('#agree_contact').is(':checked');

        setButtonLoading($button, true);
        ISFEnrollment.isSubmitting = true;

        $.ajax({
            url: isf_frontend.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_submit_enrollment',
                nonce: isf_frontend.nonce,
                instance: ISFEnrollment.instanceSlug,
                session_id: ISFEnrollment.sessionId,
                form_data: JSON.stringify(ISFEnrollment.formData)
            },
            success: function(response) {
                if (response.success) {
                    // Track completion before showing success
                    trackCompletion();

                    // Show success page
                    ISFEnrollment.formData.confirmation_number = response.data.confirmation_number || '';
                    loadStep('success');
                    updateProgressBar(5, true);
                } else {
                    showAlert(response.data.message || isf_frontend.strings.submission_error, 'error');
                }
            },
            error: function() {
                showAlert(isf_frontend.strings.network_error, 'error');
            },
            complete: function() {
                setButtonLoading($button, false);
                ISFEnrollment.isSubmitting = false;
            }
        });
    }

    /**
     * Navigate to previous step
     */
    function handlePrevious(e) {
        e.preventDefault();
        if (ISFEnrollment.currentStep > 1) {
            goToStep(ISFEnrollment.currentStep - 1);
        }
    }

    /**
     * Handle edit link clicks on confirmation page
     */
    function handleEditLink(e) {
        e.preventDefault();
        var targetStep = $(this).data('goto-step');
        if (targetStep) {
            goToStep(targetStep);
        }
    }

    /**
     * Go to a specific step
     */
    function goToStep(step) {
        var previousStep = ISFEnrollment.currentStep;

        // Track exit from current step
        if (previousStep !== step) {
            trackStepEvent('exit', previousStep);
        }

        ISFEnrollment.currentStep = step;
        loadStep(step);
        updateProgressBar();
        saveProgress();
        scrollToTop();

        // Track entry to new step
        trackStepEvent('enter', step);
    }

    /**
     * Load step content via AJAX
     */
    function loadStep(step) {
        var $content = $('.isf-form-content');

        $content.addClass('isf-loading');

        $.ajax({
            url: isf_frontend.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_load_step',
                nonce: isf_frontend.nonce,
                instance: ISFEnrollment.instanceSlug,
                session_id: ISFEnrollment.sessionId,
                step: step,
                form_data: JSON.stringify(ISFEnrollment.formData)
            },
            success: function(response) {
                if (response.success) {
                    $content.html(response.data.html);

                    // Initialize step-specific functionality
                    if (step === 4) {
                        initScheduleCalendar();
                    }

                    // Pre-fill form data
                    prefillFormData();
                } else {
                    console.error('[ISF] loadStep error:', response.data);
                    showAlert(response.data.message || 'Failed to load step.', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('[ISF] loadStep AJAX error:', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText,
                    step: step
                });
                showAlert(isf_frontend.strings.network_error, 'error');
            },
            complete: function() {
                $content.removeClass('isf-loading');
            }
        });
    }

    /**
     * Update progress bar
     */
    function updateProgressBar(step, completed) {
        step = step || ISFEnrollment.currentStep;
        var percentage = ((step - 1) / (ISFEnrollment.totalSteps - 1)) * 100;

        if (completed) {
            percentage = 100;
        }

        $('.isf-progress-fill').css('width', percentage + '%');

        $('.isf-progress-step').each(function() {
            var stepNum = $(this).data('step');
            $(this).removeClass('active completed');

            if (stepNum < step || completed) {
                $(this).addClass('completed');
            } else if (stepNum === step && !completed) {
                $(this).addClass('active');
            }
        });
    }

    /**
     * Save progress to server
     */
    function saveProgress() {
        $.ajax({
            url: isf_frontend.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_save_progress',
                nonce: isf_frontend.nonce,
                instance: ISFEnrollment.instanceSlug,
                session_id: ISFEnrollment.sessionId,
                step: ISFEnrollment.currentStep,
                form_data: JSON.stringify(ISFEnrollment.formData)
            }
        });
    }

    /**
     * Pre-fill form fields from stored data
     */
    function prefillFormData() {
        var data = ISFEnrollment.formData;

        $.each(data, function(key, value) {
            var $field = $('[name="' + key + '"]');
            if ($field.length) {
                if ($field.is(':checkbox')) {
                    $field.prop('checked', !!value);
                } else if ($field.is(':radio')) {
                    $field.filter('[value="' + value + '"]').prop('checked', true).trigger('change');
                } else {
                    $field.val(value);
                }
            }
        });
    }

    /**
     * Initialize schedule calendar
     */
    function initScheduleCalendar() {
        loadScheduleSlots();
    }

    /**
     * Load available schedule slots from API
     */
    function loadScheduleSlots() {
        var $grid = $('#isf-calendar-grid');

        $grid.html('<div class="isf-calendar-loading"><svg class="isf-spinner" viewBox="0 0 24 24" width="32" height="32"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.4 31.4" /></svg><span>' + isf_frontend.strings.loading_dates + '</span></div>');

        $.ajax({
            url: isf_frontend.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_get_schedule_slots',
                nonce: isf_frontend.nonce,
                instance: ISFEnrollment.instanceSlug,
                session_id: ISFEnrollment.sessionId,
                account_number: ISFEnrollment.formData.account_number || ISFEnrollment.formData.utility_no,
                device_type: ISFEnrollment.formData.device_type
            },
            success: function(response) {
                if (response.success) {
                    ISFEnrollment.scheduleData = response.data;
                    renderCalendar(response.data);
                } else {
                    console.error('[ISF] loadScheduleSlots error:', response.data);
                    $grid.html('<div class="isf-calendar-error">' + (response.data.message || isf_frontend.strings.schedule_error) + '</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('[ISF] loadScheduleSlots AJAX error:', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText
                });
                $grid.html('<div class="isf-calendar-error">' + isf_frontend.strings.network_error + '</div>');
            }
        });
    }

    /**
     * Render calendar with available dates
     */
    function renderCalendar(data) {
        var $grid = $('#isf-calendar-grid');
        var $month = $('#isf-calendar-month');

        // Convert slots array to dates lookup object
        var availableDates = {};
        var slots = data.slots || [];

        slots.forEach(function(slot) {
            // Parse date from MM/DD/YYYY format to YYYY-MM-DD
            var dateParts = slot.date.split('/');
            var dateKey;
            if (dateParts.length === 3) {
                // MM/DD/YYYY format
                dateKey = dateParts[2] + '-' + dateParts[0].padStart(2, '0') + '-' + dateParts[1].padStart(2, '0');
            } else {
                // Already in proper format or use timestamp
                dateKey = slot.date;
            }
            availableDates[dateKey] = slot.times;
        });

        // Store processed dates for time slot loading
        data.dates = availableDates;

        // Get current month context - derive from first available slot or use today
        var today = new Date();
        var currentMonth, currentYear;

        if (data.currentMonth !== undefined && data.currentYear !== undefined) {
            currentMonth = data.currentMonth;
            currentYear = data.currentYear;
        } else if (slots.length > 0) {
            // Parse first slot date to get the month
            var firstSlot = slots[0];
            var firstDateParts = firstSlot.date.split('/');
            if (firstDateParts.length === 3) {
                currentMonth = parseInt(firstDateParts[0], 10) - 1; // JS months are 0-indexed
                currentYear = parseInt(firstDateParts[2], 10);
            } else if (firstSlot.timestamp) {
                var firstDate = new Date(firstSlot.timestamp * 1000);
                currentMonth = firstDate.getMonth();
                currentYear = firstDate.getFullYear();
            } else {
                currentMonth = today.getMonth();
                currentYear = today.getFullYear();
            }
        } else {
            currentMonth = today.getMonth();
            currentYear = today.getFullYear();
        }

        // Store for navigation
        data.currentMonth = currentMonth;
        data.currentYear = currentYear;

        // Month name
        var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        $month.text(monthNames[currentMonth] + ' ' + currentYear);

        // Day headers
        var dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        var html = '';

        dayNames.forEach(function(day) {
            html += '<div class="isf-calendar-day-header">' + day + '</div>';
        });

        // Get first day and days in month
        var firstDay = new Date(currentYear, currentMonth, 1).getDay();
        var daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();

        // Empty cells before first day
        for (var i = 0; i < firstDay; i++) {
            html += '<div class="isf-calendar-day disabled"></div>';
        }

        // Days in month
        for (var day = 1; day <= daysInMonth; day++) {
            var dateStr = currentYear + '-' + String(currentMonth + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
            var isAvailable = availableDates[dateStr] !== undefined;
            var isPast = new Date(dateStr) < new Date(today.toDateString());

            var classes = ['isf-calendar-day'];
            if (isPast) {
                classes.push('disabled');
            } else if (isAvailable) {
                classes.push('available');
            } else {
                classes.push('unavailable');
            }

            html += '<div class="' + classes.join(' ') + '" data-date="' + dateStr + '">' + day + '</div>';
        }

        $grid.html(html);

        // Store month context for navigation
        $grid.data('month', currentMonth);
        $grid.data('year', currentYear);
    }

    /**
     * Handle date selection
     */
    function handleDateSelection() {
        var $day = $(this);
        var date = $day.data('date');

        $('.isf-calendar-day').removeClass('selected');
        $day.addClass('selected');

        $('#schedule_date').val(date);

        // Format date for display
        var dateObj = new Date(date + 'T12:00:00');
        var options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        var formattedDate = dateObj.toLocaleDateString('en-US', options);
        $('#isf-summary-date').text(formattedDate);

        // Load time slots for this date
        loadTimeSlots(date);
    }

    /**
     * Load time slots for selected date
     */
    function loadTimeSlots(date) {
        var $grid = $('#isf-timeslots-grid');
        var $loading = $('#isf-timeslots-loading');
        var $empty = $('#isf-timeslots-empty');
        var $instruction = $('#isf-timeslots-instruction');

        $instruction.hide();
        $grid.hide();
        $empty.hide();
        $loading.show();

        // Get time slots from stored data
        if (ISFEnrollment.scheduleData && ISFEnrollment.scheduleData.dates && ISFEnrollment.scheduleData.dates[date]) {
            var times = ISFEnrollment.scheduleData.dates[date];

            // Times is an object like: {am: {available: true, label: '8:00 AM...'}, md: {...}, ...}
            var availableSlots = [];

            // Time slot order and codes
            var timeOrder = ['am', 'md', 'pm', 'ev'];
            var timeCodes = {
                'am': 'AM',
                'md': 'MD',
                'pm': 'PM',
                'ev': 'EV'
            };

            timeOrder.forEach(function(key) {
                var slot = times[key];
                if (slot && slot.available) {
                    availableSlots.push({
                        code: timeCodes[key],
                        label: slot.label
                    });
                }
            });

            if (availableSlots.length === 0) {
                $loading.hide();
                $empty.show();
                return;
            }

            var fsr = ISFEnrollment.scheduleData.fsr_no || '';
            var html = '';
            availableSlots.forEach(function(slot) {
                html += '<label class="isf-timeslot">';
                html += '<input type="radio" name="time_slot" value="' + slot.code + '" data-fsr="' + fsr + '">';
                html += '<span class="isf-timeslot-label">' + slot.label + '</span>';
                html += '</label>';
            });

            $loading.hide();
            $grid.html(html).show();
        } else {
            $loading.hide();
            $empty.show();
        }

        // Reset time selection
        $('#schedule_time').val('');
        $('#schedule_fsr').val('');
        $('#isf-appointment-summary').hide();
        // Reset button text to "Skip"
        $('#isf-schedule-continue .isf-btn-text-skip').show();
        $('#isf-schedule-continue .isf-btn-text-confirm').hide();
    }

    /**
     * Handle time slot selection
     */
    function handleTimeSelection() {
        var $slot = $(this);
        var $input = $slot.find('input');

        $('.isf-timeslot').removeClass('selected');
        $slot.addClass('selected');
        $input.prop('checked', true);

        var timeCode = $input.val();
        var fsr = $input.data('fsr');
        var timeLabel = $slot.find('.isf-timeslot-label').text();

        $('#schedule_time').val(timeCode);
        $('#schedule_fsr').val(fsr);
        $('#isf-summary-time').text(timeLabel);

        // Show summary and change button text
        $('#isf-appointment-summary').show();
        $('#isf-schedule-continue .isf-btn-text-skip').hide();
        $('#isf-schedule-continue .isf-btn-text-confirm').show();
    }

    /**
     * Navigate calendar to previous month
     */
    function navigateCalendarPrev() {
        navigateCalendar(-1);
    }

    /**
     * Navigate calendar to next month
     */
    function navigateCalendarNext() {
        navigateCalendar(1);
    }

    /**
     * Navigate calendar by offset
     */
    function navigateCalendar(offset) {
        var $grid = $('#isf-calendar-grid');
        var month = $grid.data('month') || new Date().getMonth();
        var year = $grid.data('year') || new Date().getFullYear();

        month += offset;

        if (month > 11) {
            month = 0;
            year++;
        } else if (month < 0) {
            month = 11;
            year--;
        }

        // Update and re-render
        if (ISFEnrollment.scheduleData) {
            ISFEnrollment.scheduleData.currentMonth = month;
            ISFEnrollment.scheduleData.currentYear = year;
            renderCalendar(ISFEnrollment.scheduleData);
        }
    }

    /**
     * Toggle account help section
     */
    function toggleAccountHelp() {
        $('#isf-account-help-content').slideToggle(200);
    }

    /**
     * Show alert message
     */
    function showAlert(message, type) {
        type = type || 'error';

        // Remove existing alerts
        $('.isf-step-form .isf-alert').remove();

        var html = '<div class="isf-alert isf-alert-' + type + '">';
        html += '<span class="isf-alert-icon">';

        if (type === 'error') {
            html += '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" /></svg>';
        } else if (type === 'warning') {
            html += '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>';
        } else {
            html += '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" /></svg>';
        }

        html += '</span>';
        html += '<span class="isf-alert-message">' + escapeHtml(message) + '</span>';
        html += '<button type="button" class="isf-alert-close" aria-label="Dismiss">&times;</button>';
        html += '</div>';

        $('.isf-step-form').first().prepend(html);
        scrollToTop();
    }

    /**
     * Dismiss alert message
     */
    function dismissAlert() {
        $(this).closest('.isf-alert').fadeOut(200, function() {
            $(this).remove();
        });
    }

    /**
     * Open popup by ID
     */
    function openPopupById(id) {
        var $popup = $('#isf-popup-' + id);
        if ($popup.length) {
            $popup.fadeIn(200);
            $('body').addClass('isf-popup-open');
        }
    }

    /**
     * Open popup from link
     */
    function openPopup(e) {
        e.preventDefault();
        var popupId = $(this).data('popup');
        openPopupById(popupId);
    }

    /**
     * Close popup
     */
    function closePopup() {
        $(this).closest('.isf-popup').fadeOut(200);
        $('body').removeClass('isf-popup-open');
    }

    /**
     * Handle popup backdrop click
     */
    function handlePopupBackdropClick(e) {
        if ($(e.target).hasClass('isf-popup')) {
            $(this).fadeOut(200);
            $('body').removeClass('isf-popup-open');
        }
    }

    /**
     * Set button loading state
     */
    function setButtonLoading($button, loading) {
        if (loading) {
            $button.prop('disabled', true);
            $button.find('.isf-btn-text').hide();
            $button.find('.isf-btn-loading').show();
        } else {
            $button.prop('disabled', false);
            $button.find('.isf-btn-text').show();
            $button.find('.isf-btn-loading').hide();
        }
    }

    /**
     * Scroll to top of form
     */
    function scrollToTop() {
        var $container = $('.isf-form-container');
        if ($container.length) {
            $('html, body').animate({
                scrollTop: $container.offset().top - 50
            }, 300);
        }
    }

    /**
     * Format phone number as user types
     */
    function formatPhoneNumber() {
        var $input = $(this);
        var value = $input.val().replace(/\D/g, '');
        var formatted = '';

        if (value.length > 0) {
            formatted = '(' + value.substring(0, 3);
        }
        if (value.length >= 3) {
            formatted += ') ' + value.substring(3, 6);
        }
        if (value.length >= 6) {
            formatted += '-' + value.substring(6, 10);
        }

        $input.val(formatted);
    }

    /**
     * Validate email match
     */
    function validateEmailMatch() {
        var email = $('#email').val().trim();
        var confirm = $(this).val().trim();

        if (confirm && email !== confirm) {
            $(this).addClass('isf-input-error');
        } else {
            $(this).removeClass('isf-input-error');
        }
    }

    /**
     * Validate email format
     */
    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    /**
     * Validate phone format
     */
    function isValidPhone(phone) {
        var digits = phone.replace(/\D/g, '');
        return digits.length === 10;
    }

    /**
     * Escape HTML entities
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // =========================================================================
    // Analytics Tracking
    // =========================================================================

    /**
     * Track a step event (enter, exit, complete, abandon)
     *
     * @param {string} action - The action type ('enter', 'exit', 'complete', 'abandon')
     * @param {number} step - The step number
     * @param {boolean} useBeacon - Whether to use sendBeacon for reliable delivery on page close
     */
    function trackStepEvent(action, step, useBeacon) {
        var timeOnStep = 0;

        // Calculate time spent on step
        if (action === 'exit' || action === 'abandon' || action === 'complete') {
            if (ISFEnrollment.stepStartTime) {
                timeOnStep = Math.round((Date.now() - ISFEnrollment.stepStartTime) / 1000);
            }
        }

        // Reset timer on entry
        if (action === 'enter') {
            ISFEnrollment.stepStartTime = Date.now();
        }

        // Get step name
        var stepNames = ISFEnrollment.stepNames[ISFEnrollment.formType] || {};
        var stepName = stepNames[step] || 'Step ' + step;

        // Detect browser and device
        var browserInfo = detectBrowser();

        var data = {
            action: 'formflow_track_step',
            nonce: isf_frontend.nonce,
            instance: ISFEnrollment.instanceSlug,
            session_id: ISFEnrollment.sessionId,
            step: step,
            step_name: stepName,
            event_action: action,
            time_on_step: timeOnStep,
            browser: browserInfo.browser,
            is_mobile: browserInfo.isMobile ? 1 : 0,
            referrer: document.referrer || ''
        };

        // Push to GTM dataLayer for analytics integration
        pushToDataLayer(action, step, stepName, timeOnStep);

        // Use sendBeacon for page close events (more reliable)
        if (useBeacon && navigator.sendBeacon) {
            var formData = new FormData();
            for (var key in data) {
                formData.append(key, data[key]);
            }
            navigator.sendBeacon(isf_frontend.ajax_url, formData);
            return;
        }

        // Regular AJAX for normal tracking
        $.ajax({
            url: isf_frontend.ajax_url,
            type: 'POST',
            data: data,
            // Don't wait for response - fire and forget for analytics
            async: true
        });
    }

    /**
     * Push event to GTM dataLayer
     *
     * @param {string} action - The action type
     * @param {number} step - The step number
     * @param {string} stepName - Human-readable step name
     * @param {number} timeOnStep - Time spent on step in seconds
     */
    function pushToDataLayer(action, step, stepName, timeOnStep) {
        // Ensure dataLayer exists
        window.dataLayer = window.dataLayer || [];

        var eventName = 'isf_form_step';
        var eventData = {
            event: eventName,
            isf_instance: ISFEnrollment.instanceSlug,
            isf_session_id: ISFEnrollment.sessionId,
            isf_step: step,
            isf_step_name: stepName,
            isf_action: action,
            isf_time_on_step: timeOnStep,
            isf_form_type: ISFEnrollment.formType
        };

        // Map action to specific events
        switch (action) {
            case 'enter':
                if (step === 1) {
                    // First step entry = form start
                    window.dataLayer.push({
                        event: 'isf_form_start',
                        isf_instance: ISFEnrollment.instanceSlug,
                        isf_form_type: ISFEnrollment.formType
                    });
                }
                break;

            case 'complete':
                eventData.event = 'isf_form_complete';
                eventData.isf_device_type = ISFEnrollment.formData.device_type || '';
                break;

            case 'abandon':
                eventData.event = 'isf_form_abandon';
                break;
        }

        window.dataLayer.push(eventData);

        // Trigger custom event for ISFAnalytics if loaded
        if (window.ISFAnalytics && typeof window.ISFAnalytics.trackFormStep === 'function') {
            window.ISFAnalytics.trackFormStep(step, stepName);
        }
    }

    /**
     * Detect browser and device type
     */
    function detectBrowser() {
        var ua = navigator.userAgent;
        var browser = 'Unknown';
        var isMobile = /Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(ua);

        if (ua.indexOf('Chrome') > -1 && ua.indexOf('Edg') === -1 && ua.indexOf('OPR') === -1) {
            browser = 'Chrome';
        } else if (ua.indexOf('Safari') > -1 && ua.indexOf('Chrome') === -1) {
            browser = 'Safari';
        } else if (ua.indexOf('Firefox') > -1) {
            browser = 'Firefox';
        } else if (ua.indexOf('Edg') > -1) {
            browser = 'Edge';
        } else if (ua.indexOf('OPR') > -1 || ua.indexOf('Opera') > -1) {
            browser = 'Opera';
        } else if (ua.indexOf('MSIE') > -1 || ua.indexOf('Trident') > -1) {
            browser = 'Internet Explorer';
        }

        return {
            browser: browser,
            isMobile: isMobile
        };
    }

    /**
     * Track form completion
     */
    function trackCompletion() {
        trackStepEvent('complete', ISFEnrollment.currentStep);
    }

    // =========================================================================
    // Auto-Save & Save and Continue Later
    // =========================================================================

    /**
     * Start the auto-save timer
     */
    function startAutoSave() {
        var interval = isf_frontend.autosave_interval || 15000; // 15 seconds default

        // Clear any existing timer
        if (ISFEnrollment.autoSaveTimer) {
            clearInterval(ISFEnrollment.autoSaveTimer);
        }

        ISFEnrollment.autoSaveTimer = setInterval(function() {
            if (ISFEnrollment.hasUnsavedChanges && !ISFEnrollment.isSubmitting) {
                autoSaveProgress();
            }
        }, interval);
    }

    /**
     * Auto-save current progress
     */
    function autoSaveProgress() {
        // Collect current form data
        collectCurrentFormData();

        $.ajax({
            url: isf_frontend.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_save_progress',
                nonce: isf_frontend.nonce,
                instance: ISFEnrollment.instanceSlug,
                session_id: ISFEnrollment.sessionId,
                step: ISFEnrollment.currentStep,
                form_data: JSON.stringify(ISFEnrollment.formData)
            },
            success: function(response) {
                if (response.success) {
                    ISFEnrollment.hasUnsavedChanges = false;
                    ISFEnrollment.lastAutoSave = new Date();
                    showAutoSaveIndicator();
                }
            }
        });
    }

    /**
     * Show auto-save indicator briefly
     */
    function showAutoSaveIndicator() {
        var $indicator = $('#isf-autosave-indicator');
        if (!$indicator.length) {
            $indicator = $('<div id="isf-autosave-indicator" class="isf-autosave-indicator">' +
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16">' +
                '<path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>' +
                '</svg> ' + isf_frontend.strings.autosaved + '</div>');
            $('.isf-form-container').append($indicator);
        }
        $indicator.addClass('show');
        setTimeout(function() {
            $indicator.removeClass('show');
        }, 2000);
    }

    /**
     * Collect form data from current step's form fields
     */
    function collectCurrentFormData() {
        var $form = $('.isf-step-form:visible');
        if (!$form.length) return;

        $form.find('input, select, textarea').each(function() {
            var $field = $(this);
            var name = $field.attr('name');
            if (!name) return;

            if ($field.is(':checkbox')) {
                ISFEnrollment.formData[name] = $field.is(':checked');
            } else if ($field.is(':radio')) {
                if ($field.is(':checked')) {
                    ISFEnrollment.formData[name] = $field.val();
                }
            } else {
                ISFEnrollment.formData[name] = $field.val();
            }
        });
    }

    /**
     * Show medical acknowledgment modal
     * Required when the API returns error code 21 indicating a medical condition
     */
    function showMedicalAcknowledgmentModal(message, onAcknowledge) {
        var $modal = $('#isf-medical-modal');
        if (!$modal.length) {
            $modal = $('<div id="isf-medical-modal" class="isf-popup">' +
                '<div class="isf-popup-content isf-popup-warning">' +
                '<div class="isf-popup-header">' +
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="24" height="24" class="isf-warning-icon">' +
                '<path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>' +
                '</svg>' +
                '<h3>Important Notice</h3>' +
                '</div>' +
                '<div class="isf-popup-body">' +
                '<p id="isf-medical-message"></p>' +
                '</div>' +
                '<div class="isf-popup-actions">' +
                '<button type="button" class="isf-btn isf-btn-secondary isf-medical-cancel">Cancel Enrollment</button>' +
                '<button type="button" class="isf-btn isf-btn-primary isf-medical-acknowledge">I Acknowledge &amp; Continue</button>' +
                '</div>' +
                '</div>' +
                '</div>');
            $('body').append($modal);
        }

        // Set the message
        $modal.find('#isf-medical-message').text(message);

        // Bind event handlers
        $modal.find('.isf-medical-cancel').off('click').on('click', function() {
            $modal.fadeOut(200);
            $('body').removeClass('isf-popup-open');
        });

        $modal.find('.isf-medical-acknowledge').off('click').on('click', function() {
            $modal.fadeOut(200);
            $('body').removeClass('isf-popup-open');
            if (typeof onAcknowledge === 'function') {
                onAcknowledge();
            }
        });

        $modal.fadeIn(200);
        $('body').addClass('isf-popup-open');
    }

    /**
     * Show save and continue modal
     */
    function showSaveAndContinueModal(e) {
        e.preventDefault();

        var $modal = $('#isf-save-later-modal');
        if (!$modal.length) {
            $modal = $('<div id="isf-save-later-modal" class="isf-popup">' +
                '<div class="isf-popup-content">' +
                '<button type="button" class="isf-popup-close">&times;</button>' +
                '<h3>' + isf_frontend.strings.save_progress + '</h3>' +
                '<p>Enter your email address to receive a link to continue your enrollment later.</p>' +
                '<form id="isf-save-later-form">' +
                '<div class="isf-field">' +
                '<label for="save_later_email" class="isf-label">Email Address</label>' +
                '<input type="email" id="save_later_email" class="isf-input" required placeholder="your@email.com">' +
                '</div>' +
                '<div class="isf-save-later-actions">' +
                '<button type="submit" class="isf-btn isf-btn-primary">' +
                '<span class="isf-btn-text">Send Link</span>' +
                '<span class="isf-btn-loading" style="display:none;"><svg class="isf-spinner" viewBox="0 0 24 24" width="18" height="18"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.4 31.4"/></svg> Sending...</span>' +
                '</button>' +
                '</div>' +
                '</form>' +
                '</div>' +
                '</div>');
            $('body').append($modal);

            // Pre-fill email if available
            var currentEmail = ISFEnrollment.formData.email || $('#email').val();
            if (currentEmail) {
                $modal.find('#save_later_email').val(currentEmail);
            }
        }

        $modal.fadeIn(200);
        $('body').addClass('isf-popup-open');
    }

    /**
     * Handle save and continue form submission
     */
    function handleSaveAndContinue(e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $form.find('.isf-btn-primary');
        var email = $('#save_later_email').val().trim();

        if (!isValidEmail(email)) {
            showAlert(isf_frontend.strings.invalid_email, 'error');
            return;
        }

        // Collect current form data first
        collectCurrentFormData();

        setButtonLoading($btn, true);

        $.ajax({
            url: isf_frontend.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_save_and_email',
                nonce: isf_frontend.nonce,
                instance: ISFEnrollment.instanceSlug,
                session_id: ISFEnrollment.sessionId,
                step: ISFEnrollment.currentStep,
                email: email,
                form_data: JSON.stringify(ISFEnrollment.formData)
            },
            success: function(response) {
                if (response.success) {
                    // Close modal and show success (use response message if available)
                    $('#isf-save-later-modal').fadeOut(200);
                    $('body').removeClass('isf-popup-open');
                    var msg = response.data.message || isf_frontend.strings.progress_saved;
                    showAlert(msg, response.data.email_sent === false ? 'warning' : 'info');
                } else {
                    console.error('[ISF] saveAndEmail error:', response.data);
                    showAlert(response.data.message || 'Failed to save progress.', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('[ISF] saveAndEmail AJAX error:', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText
                });
                showAlert(isf_frontend.strings.network_error, 'error');
            },
            complete: function() {
                setButtonLoading($btn, false);
            }
        });
    }

    /**
     * Resume form from token
     */
    function resumeFromToken() {
        var $content = $('.isf-form-content');
        $content.addClass('isf-loading');

        $.ajax({
            url: isf_frontend.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_resume_form',
                nonce: isf_frontend.nonce,
                instance: ISFEnrollment.instanceSlug,
                resume_token: ISFEnrollment.resumeToken
            },
            success: function(response) {
                if (response.success) {
                    // Restore session and form data
                    ISFEnrollment.sessionId = response.data.session_id;
                    ISFEnrollment.formData = response.data.form_data || {};
                    ISFEnrollment.currentStep = response.data.step || 1;

                    // Update container data
                    $('.isf-form-container').data('session', ISFEnrollment.sessionId);

                    // Show restored message
                    showAlert('Welcome back! Your progress has been restored.', 'info');

                    // Load the step they were on
                    loadStep(ISFEnrollment.currentStep);
                    updateProgressBar();

                    // Clean up URL
                    if (window.history && window.history.replaceState) {
                        var cleanUrl = window.location.href.split('?')[0];
                        window.history.replaceState({}, document.title, cleanUrl);
                    }
                } else {
                    showAlert(response.data.message || 'Unable to restore your progress.', 'error');
                }
            },
            error: function() {
                showAlert(isf_frontend.strings.network_error, 'error');
            },
            complete: function() {
                $content.removeClass('isf-loading');
            }
        });
    }

    // =========================================================================
    // Real-Time Validation
    // =========================================================================

    /**
     * Validate field on blur
     */
    function validateFieldOnBlur() {
        var $field = $(this);
        var value = $field.val().trim();

        clearFieldError($field);

        if ($field.prop('required') && !value) {
            showFieldError($field, isf_frontend.strings.required_field);
            return false;
        }

        return true;
    }

    /**
     * Validate email format as user types
     */
    function validateEmailFormat() {
        var $field = $(this);
        var value = $field.val().trim();

        clearFieldError($field);

        if (value && !isValidEmail(value)) {
            showFieldError($field, isf_frontend.strings.invalid_email);
        }
    }

    /**
     * Validate ZIP format as user types
     */
    function validateZipFormat() {
        var $field = $(this);
        var value = $field.val().trim();

        clearFieldError($field);

        if (value && !/^\d{5}$/.test(value)) {
            showFieldError($field, isf_frontend.strings.invalid_zip);
        }
    }

    /**
     * Show field-level error
     */
    function showFieldError($field, message) {
        $field.addClass('isf-input-error');
        var $error = $field.siblings('.isf-field-error');
        if (!$error.length) {
            $error = $('<span class="isf-field-error"></span>');
            $field.after($error);
        }
        $error.text(message).show();
    }

    /**
     * Clear field-level error
     */
    function clearFieldError($field) {
        $field.removeClass('isf-input-error');
        $field.siblings('.isf-field-error').hide();
    }

    // =========================================================================
    // Google Places Autocomplete
    // =========================================================================

    /**
     * Initialize Google Places autocomplete
     */
    function initGooglePlaces() {
        var apiKey = isf_frontend.google_places_key;
        if (!apiKey) return;

        // Load Google Places script if not already loaded
        if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
            var script = document.createElement('script');
            script.src = 'https://maps.googleapis.com/maps/api/js?key=' + apiKey + '&libraries=places&callback=isfGooglePlacesReady';
            script.async = true;
            document.head.appendChild(script);
        } else {
            setupGooglePlacesAutocomplete();
        }
    }

    // Global callback for Google Places script load
    window.isfGooglePlacesReady = function() {
        setupGooglePlacesAutocomplete();
    };

    /**
     * Set up autocomplete on street address field
     */
    function setupGooglePlacesAutocomplete() {
        var $streetField = $('#street');
        if (!$streetField.length || $streetField.data('autocomplete-initialized')) return;

        var autocomplete = new google.maps.places.Autocomplete($streetField[0], {
            componentRestrictions: { country: 'us' },
            types: ['address'],
            fields: ['address_components', 'formatted_address']
        });

        autocomplete.addListener('place_changed', function() {
            var place = autocomplete.getPlace();
            if (!place.address_components) return;

            // Parse address components
            var streetNumber = '';
            var streetName = '';
            var city = '';
            var state = '';
            var zip = '';

            place.address_components.forEach(function(component) {
                var types = component.types;
                if (types.includes('street_number')) {
                    streetNumber = component.long_name;
                } else if (types.includes('route')) {
                    streetName = component.long_name;
                } else if (types.includes('locality')) {
                    city = component.long_name;
                } else if (types.includes('administrative_area_level_1')) {
                    state = component.short_name;
                } else if (types.includes('postal_code')) {
                    zip = component.long_name;
                }
            });

            // Fill in the fields
            $streetField.val((streetNumber + ' ' + streetName).trim());
            if (city) $('#city').val(city);
            if (state) $('#state').val(state);
            if (zip) $('#zip_confirm').val(zip);

            // Trigger change events for validation
            $('#city, #state, #zip_confirm').trigger('change');
        });

        $streetField.data('autocomplete-initialized', true);
    }

    // Re-initialize Google Places when step 3 loads
    $(document).on('isf:stepLoaded', function(e, step) {
        if (step === 3) {
            setupGooglePlacesAutocomplete();
        }
    });

    // =========================================================================
    // Scheduler Form Handlers
    // =========================================================================

    /**
     * Scheduler Step 1: Account Verification
     */
    function handleSchedulerStep1Submit(e) {
        e.preventDefault();

        if (ISFEnrollment.isSubmitting) return;

        var $form = $(this);
        var $button = $form.find('.isf-btn-next');
        var utilityNo = $form.find('#utility_no').val().trim();
        var zip = $form.find('#zip').val().trim();

        if (!utilityNo || !zip) {
            showAlert('Please enter both your account number and ZIP code.', 'error');
            return;
        }

        if (!/^\d{5}$/.test(zip)) {
            showAlert('Please enter a valid 5-digit ZIP code.', 'error');
            return;
        }

        setButtonLoading($button, true);
        ISFEnrollment.isSubmitting = true;

        $.ajax({
            url: isf_frontend.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_validate_account',
                nonce: isf_frontend.nonce,
                instance: ISFEnrollment.instanceSlug,
                session_id: ISFEnrollment.sessionId,
                utility_no: utilityNo,
                zip: zip
            },
            success: function(response) {
                if (response.success) {
                    ISFEnrollment.formData.utility_no = utilityNo;
                    ISFEnrollment.formData.zip = zip;

                    // Store validated data
                    if (response.data.customer) {
                        ISFEnrollment.formData.first_name = response.data.customer.first_name || '';
                        ISFEnrollment.formData.last_name = response.data.customer.last_name || '';
                        ISFEnrollment.formData.address = response.data.customer.address || {};
                        ISFEnrollment.formData.account_number = response.data.customer.ca_no || '';
                    }

                    goToStep(2);
                } else {
                    showAlert(response.data.message || isf_frontend.strings.validation_error, 'error');
                }
            },
            error: function() {
                showAlert(isf_frontend.strings.network_error, 'error');
            },
            complete: function() {
                setButtonLoading($button, false);
                ISFEnrollment.isSubmitting = false;
            }
        });
    }

    /**
     * Scheduler Step 2: Appointment Selection
     */
    function handleSchedulerStep2Submit(e) {
        e.preventDefault();

        if (ISFEnrollment.isSubmitting) return;

        var $form = $(this);
        var $button = $form.find('.isf-btn-next');
        var scheduleDate = $('#schedule_date').val();
        var scheduleTime = $('#schedule_time').val();
        var scheduleFsr = $('#schedule_fsr').val();

        if (!scheduleDate || !scheduleTime) {
            showAlert('Please select both a date and time for your appointment.', 'error');
            return;
        }

        setButtonLoading($button, true);
        ISFEnrollment.isSubmitting = true;

        $.ajax({
            url: isf_frontend.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_book_appointment',
                nonce: isf_frontend.nonce,
                instance: ISFEnrollment.instanceSlug,
                session_id: ISFEnrollment.sessionId,
                schedule_date: scheduleDate,
                schedule_time: scheduleTime,
                schedule_fsr: scheduleFsr
            },
            success: function(response) {
                if (response.success) {
                    // Track completion before showing success
                    trackCompletion();

                    ISFEnrollment.formData.schedule_date = scheduleDate;
                    ISFEnrollment.formData.schedule_time = scheduleTime;
                    ISFEnrollment.formData.confirmation_number = response.data.confirmation_number || '';

                    // Show success page
                    loadStep('success');
                } else {
                    showAlert(response.data.message || isf_frontend.strings.submission_error, 'error');
                }
            },
            error: function() {
                showAlert(isf_frontend.strings.network_error, 'error');
            },
            complete: function() {
                setButtonLoading($button, false);
                ISFEnrollment.isSubmitting = false;
            }
        });
    }

    // Initialize when DOM is ready
    $(document).ready(init);

})(jQuery);
