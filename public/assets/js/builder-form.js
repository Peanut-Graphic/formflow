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

    ready(function () {
        document.querySelectorAll('.isf-builder-form').forEach(initForm);
    });
})();
