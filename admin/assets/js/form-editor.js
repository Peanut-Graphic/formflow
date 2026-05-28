/* FormFlow form-editor — save-on-blur, mode switcher, sub-rail nav */
(function ($) {
    'use strict';

    var DEBOUNCE_MS = 500;
    var inflight = null;
    var dirtyFields = {};
    var statusEl;

    function status(text, klass) {
        if (!statusEl) return;
        statusEl.removeClass('is-saving is-error').addClass(klass || '');
        statusEl.text(text);
    }

    function postSave(instanceId, payload) {
        if (inflight) inflight.abort();
        status(formflowEditor.strings.saving, 'is-saving');
        inflight = $.post(formflowEditor.ajax_url, $.extend({
            action: 'formflow_save_instance',
            nonce: formflowEditor.nonce,
            id: instanceId,
        }, payload));
        inflight.done(function (resp) {
            if (resp && resp.success) {
                dirtyFields = {};
                status(formflowEditor.strings.saved);
            } else {
                status((resp && resp.data && resp.data.message) || formflowEditor.strings.error, 'is-error');
            }
        }).fail(function () {
            status(formflowEditor.strings.error, 'is-error');
        });
    }

    function fieldChanged($field) {
        var key = $field.attr('name');
        if (!key) return;
        dirtyFields[key] = $field.is(':checkbox') ? ($field.is(':checked') ? 1 : 0) : $field.val();
    }

    function flushDirty() {
        var instanceId = $('.isf-form-editor').data('instance-id');
        if (!instanceId || Object.keys(dirtyFields).length === 0) return;
        postSave(instanceId, dirtyFields);
    }

    var debounced = (function () {
        var t;
        return function () { clearTimeout(t); t = setTimeout(flushDirty, DEBOUNCE_MS); };
    })();

    $(function () {
        statusEl = $('.isf-fe-save-status');

        // Save-on-blur for any [data-fe-autosave] field
        $(document).on('change blur', '.isf-form-editor [data-fe-autosave]', function () {
            fieldChanged($(this));
            debounced();
        });

        // Sticky save button — flush + post immediately
        $(document).on('click', '.isf-fe-action-bar .isf-fe-save', function (e) {
            e.preventDefault();
            flushDirty();
        });

        // Mode switcher
        $(document).on('change', '#isf-fe-mode-pref', function () {
            var mode = $(this).val();
            $.post(formflowEditor.ajax_url, {
                action: 'formflow_set_mode_preference',
                nonce: formflowEditor.nonce,
                mode: mode
            }, function () { window.location.reload(); });
        });

        // Sub-rail nav — anchor links handle themselves (history-friendly URLs)
    });
}(jQuery));
