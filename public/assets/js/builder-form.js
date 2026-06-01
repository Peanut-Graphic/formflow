/**
 * Behavior layer for builder-rendered (form_type=custom) forms.
 *
 * - Conditional show/hide: a field can declare
 *     settings.show_when = { field: 'is_best_phone', equals: 'no' }
 *   and the wrapper is shown only while that condition holds. The field
 *   carries data-show-when='{"field":"...","equals":"..."}' on its wrapper.
 *
 * - Scroll-to-bottom gate: any checkbox group can declare
 *     settings.scroll_gate = { box: '.isf-terms-box' }
 *   The checkbox is `disabled` until the named element is scrolled to
 *   the bottom. The wrapper carries data-scroll-gate-selector="...".
 *
 * - Single-step submit: the Next button is hidden and Submit is shown
 *   when there is exactly one step.
 *
 * Read attributes from the DOM rather than recomputing from JSON so
 * server-rendered markup stays the source of truth.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function initForm(form) {
        // --- Single-step submit swap ---
        var steps = form.querySelectorAll('.isf-step');
        if (steps.length <= 1) {
            form.classList.add('isf-single-step');
        }

        // --- AJAX submit + success confirmation ---
        var formEl = form.querySelector('form.isf-form');
        var cfg = window.isfBuilderForm || null;
        if (formEl && cfg && cfg.ajax_url) {
            formEl.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var btn = formEl.querySelector('.isf-btn-submit, button[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.setAttribute('data-original-text', btn.textContent || '');
                    btn.textContent = cfg.strings && cfg.strings.submitting || 'Submitting…';
                }

                var data = new FormData(formEl);
                data.append('action', cfg.action);
                data.append('instance_id', String(cfg.instance_id || ''));
                if (cfg.nonce) { data.append('isf_nonce', cfg.nonce); }

                fetch(cfg.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: data
                }).then(function (r) {
                    return r.json().catch(function () { return null; });
                }).then(function (resp) {
                    if (resp && resp.success) {
                        renderSuccess(form, resp.data && resp.data.success_message);
                    } else {
                        showError(form,
                            (resp && resp.data && resp.data.message)
                            || (cfg.strings && cfg.strings.error)
                            || 'Something went wrong.');
                        if (btn) {
                            btn.disabled = false;
                            btn.textContent = btn.getAttribute('data-original-text') || 'Submit';
                        }
                    }
                }).catch(function () {
                    showError(form, cfg.strings && cfg.strings.network || 'Network error.');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = btn.getAttribute('data-original-text') || 'Submit';
                    }
                });
            });
        }

        // --- Conditional show/hide ---
        var conditionalWrappers = form.querySelectorAll('[data-show-when]');
        function applyConditionals() {
            conditionalWrappers.forEach(function (wrap) {
                var raw = wrap.getAttribute('data-show-when');
                if (!raw) { return; }
                var rule;
                try { rule = JSON.parse(raw); } catch (e) { return; }
                if (!rule || !rule.field) { return; }

                var checked = form.querySelector(
                    '[data-field="' + rule.field + '"] input[type="radio"]:checked, ' +
                    '[data-field="' + rule.field + '"] input[type="checkbox"]:checked, ' +
                    '[data-field="' + rule.field + '"] select'
                );
                var current = checked ? (checked.value || '') : '';
                var match = ('equals' in rule)
                    ? current === String(rule.equals)
                    : ('not_equals' in rule
                        ? current !== String(rule.not_equals)
                        : false);

                wrap.style.display = match ? '' : 'none';

                // Disabled fields don't participate in HTML5 validity so a
                // hidden required field doesn't block submit.
                wrap.querySelectorAll('input, select, textarea').forEach(function (el) {
                    el.disabled = !match;
                });
            });
        }
        if (conditionalWrappers.length) {
            form.addEventListener('change', applyConditionals);
            applyConditionals();
        }

        // --- Scroll-to-bottom gate ---
        var gateWrappers = form.querySelectorAll('[data-scroll-gate-selector]');
        gateWrappers.forEach(function (wrap) {
            var selector = wrap.getAttribute('data-scroll-gate-selector');
            if (!selector) { return; }
            var box = form.querySelector(selector) || document.querySelector(selector);
            if (!box) { return; }
            var checks = wrap.querySelectorAll('input[type="checkbox"]');
            if (!checks.length) { return; }

            function lock() {
                checks.forEach(function (c) { c.disabled = true; });
                wrap.setAttribute('data-locked', '1');
            }
            function unlock() {
                checks.forEach(function (c) { c.disabled = false; });
                wrap.removeAttribute('data-locked');
            }
            lock();

            function maybeUnlock() {
                if (box.scrollTop + box.clientHeight >= box.scrollHeight - 6) {
                    unlock();
                    box.removeEventListener('scroll', maybeUnlock);
                }
            }
            box.addEventListener('scroll', maybeUnlock, { passive: true });
            // If the box is short enough no scroll is needed, unlock soon.
            setTimeout(maybeUnlock, 80);
        });
    }

    function renderSuccess(form, message) {
        var cfg = window.isfBuilderForm || {};
        var msg = message || 'Thank you! Your submission has been received.';

        var html = '<div class="isf-success" role="status" aria-live="polite">'
            + msg + '</div>';

        // Event mode (iPad / kiosk at an event): show a manual reset
        // button and optionally an auto-reset countdown so the next
        // person can submit without a hard browser refresh.
        if (cfg.event_mode) {
            var btnLabel = (cfg.strings && cfg.strings.event_button_label) || 'Start another enrollment';
            html += '<button type="button" class="isf-btn-submit isf-event-reset" '
                  + 'data-action="event-reset">' + escapeHtml(btnLabel) + '</button>';

            var secs = parseInt(cfg.event_mode_auto_reset_seconds, 10);
            if (secs > 0) {
                var prefix = (cfg.strings && cfg.strings.event_countdown_prefix) || 'Starting next enrollment in';
                var suffix = (cfg.strings && cfg.strings.event_countdown_suffix) || 'Tap anywhere to cancel.';
                html += '<div class="isf-event-countdown" aria-live="polite">'
                      + '<span class="isf-event-countdown-text">'
                      + escapeHtml(prefix) + ' <span class="isf-event-countdown-num" data-seconds-left="' + secs + '">' + secs + '</span>…'
                      + '</span><br><span class="isf-event-countdown-hint">' + escapeHtml(suffix) + '</span>'
                      + '</div>';
            }
        }

        form.innerHTML = html;

        if (cfg.event_mode) {
            wireEventResetControls(form, cfg);
        }

        // Scroll the confirmation into view.
        try { form.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
    }

    function wireEventResetControls(form, cfg) {
        var resetBtn = form.querySelector('.isf-event-reset');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                window.location.reload();
            });
        }

        var num = form.querySelector('.isf-event-countdown-num');
        if (!num) { return; }

        var secs = parseInt(num.getAttribute('data-seconds-left'), 10) || 0;
        var countdownEl = form.querySelector('.isf-event-countdown');
        var cancelled = false;

        // Tap anywhere inside the form / success panel cancels the
        // auto-reset (in case the user wants to read the message
        // longer). The manual reset button still works after cancel.
        function cancel() {
            if (cancelled) { return; }
            cancelled = true;
            if (countdownEl) {
                countdownEl.classList.add('isf-event-countdown-cancelled');
                countdownEl.setAttribute('aria-live', 'off');
                var text = countdownEl.querySelector('.isf-event-countdown-text');
                if (text) { text.textContent = 'Auto-reset cancelled. Tap the button when ready.'; }
                var hint = countdownEl.querySelector('.isf-event-countdown-hint');
                if (hint) { hint.remove(); }
            }
        }
        form.addEventListener('click', function (e) {
            // Don't treat the reset button as a cancel — let its own
            // click handler fire the reload.
            if (e.target.closest('.isf-event-reset')) { return; }
            cancel();
        }, true);

        var tick = function () {
            if (cancelled) { return; }
            secs -= 1;
            if (secs <= 0) {
                window.location.reload();
                return;
            }
            num.setAttribute('data-seconds-left', String(secs));
            num.textContent = String(secs);
            setTimeout(tick, 1000);
        };
        setTimeout(tick, 1000);
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    function showError(form, message) {
        var existing = form.querySelector('.isf-form-error');
        if (existing) { existing.remove(); }
        var div = document.createElement('div');
        div.className = 'isf-form-error';
        div.setAttribute('role', 'alert');
        div.textContent = message;
        var actions = form.querySelector('.isf-form-actions');
        if (actions && actions.parentNode) {
            actions.parentNode.insertBefore(div, actions);
        } else {
            form.appendChild(div);
        }
    }

    ready(function () {
        document.querySelectorAll('.isf-builder-form').forEach(initForm);
    });
})();
