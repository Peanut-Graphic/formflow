<?php
/**
 * Destinations Pod (instance editor)
 *
 * Renders a per-destination config form for each entry in
 * $instance['settings']['destinations']. Field definitions come from the
 * destination's get_config_fields() metadata, so adding new destination
 * types in the future does not require touching this view.
 *
 * Scope for 2.9.0:
 *   - Show only when at least one destination is configured on the instance
 *     (template import creates the slot).
 *   - No add/remove UI — admin manages the array via template import for now.
 *   - Sensitive fields render as type=password / textarea and never re-emit
 *     the stored encrypted value to the DOM. Submitting blank preserves the
 *     existing stored value (handled server-side by
 *     BaseDestination::encrypt_sensitive_fields).
 *
 * @package FormFlow
 * @subpackage Admin
 * @since 2.9.0
 *
 * Expects $instance to be defined in caller scope.
 */

if (!defined('ABSPATH')) {
    exit;
}

$destinations = $instance['settings']['destinations'] ?? [];
if (!is_array($destinations) || count($destinations) === 0) {
    return; // Pod hidden until template provides destinations.
}

$registry = \ISF\Destinations\DestinationRegistry::instance();
?>
<div class="isf-pod isf-pod-destinations" id="isf-destinations-pod">
    <div class="isf-pod-header">
        <h3>
            <span class="dashicons dashicons-upload"></span>
            <?php esc_html_e('Destinations', 'formflow'); ?>
        </h3>
        <p class="isf-pod-subtitle">
            <?php esc_html_e('Where finished submissions are delivered. Each destination is delivered asynchronously after submission, with retry on failure.', 'formflow'); ?>
        </p>
    </div>

    <div class="isf-pod-body">
        <?php foreach ($destinations as $idx => $dest) :
            $type = $dest['type'] ?? '';
            $name = $dest['name'] ?? $type;
            $is_active = !empty($dest['is_active']);
            $config = $dest['config'] ?? [];
            $destination = $registry->get($type);
            if (!$destination) {
                ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: destination type id */
                            esc_html__('Destination type "%s" is not registered on this site. Install the providing plugin or remove this destination from the template.', 'formflow'),
                            esc_html((string) $type)
                        );
                        ?>
                    </p>
                </div>
                <?php
                continue;
            }
            $field_defs = $destination->get_config_fields();
        ?>
            <div class="isf-destination-block"
                 data-destination-index="<?php echo esc_attr((string) $idx); ?>"
                 data-destination-type="<?php echo esc_attr($type); ?>">

                <div class="isf-destination-header">
                    <strong>
                        <span class="dashicons dashicons-<?php echo $is_active ? 'yes-alt' : 'minus'; ?>"></span>
                        <?php echo esc_html($name . ' (' . $destination->get_name() . ')'); ?>
                    </strong>

                    <label class="isf-destination-active-toggle">
                        <input type="checkbox"
                               class="isf-destination-field"
                               data-destination-key="is_active"
                               <?php checked($is_active); ?>>
                        <?php esc_html_e('Active', 'formflow'); ?>
                    </label>

                    <button type="button"
                            class="button button-secondary isf-destination-test"
                            data-destination-index="<?php echo esc_attr((string) $idx); ?>">
                        <span class="dashicons dashicons-admin-network"></span>
                        <?php esc_html_e('Test Connection', 'formflow'); ?>
                    </button>
                    <span class="isf-destination-test-result" aria-live="polite"></span>
                </div>

                <table class="form-table isf-destination-fields">
                    <tbody>
                        <?php foreach ($field_defs as $field_name => $def) :
                            $label    = (string) ($def['label'] ?? $field_name);
                            $type_in  = (string) ($def['type'] ?? 'text');
                            $required = !empty($def['required']);
                            $sensitive = !empty($def['sensitive']);
                            $default  = $def['default'] ?? '';
                            $help     = (string) ($def['description'] ?? '');
                            $show_if  = $def['show_if'] ?? null;

                            // Sensitive fields never echo the stored value — admin pastes again to change.
                            if ($sensitive) {
                                $value = '';
                                $has_stored = !empty($config[$field_name]);
                            } else {
                                $value = $config[$field_name] ?? $default;
                                $has_stored = false;
                            }

                            $input_id = "isf-dest-{$idx}-{$field_name}";
                            $row_attrs = ' data-destination-key="' . esc_attr($field_name) . '"';
                            if ($show_if && is_array($show_if)) {
                                $row_attrs .= ' data-show-if=\'' . esc_attr(wp_json_encode($show_if)) . '\'';
                            }
                        ?>
                            <tr class="isf-destination-field-row"<?php echo $row_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — built above with esc_attr ?>>
                                <th scope="row">
                                    <label for="<?php echo esc_attr($input_id); ?>">
                                        <?php echo esc_html($label); ?>
                                        <?php if ($required) : ?><span class="required">*</span><?php endif; ?>
                                    </label>
                                </th>
                                <td>
                                    <?php if ($type_in === 'select') : ?>
                                        <select id="<?php echo esc_attr($input_id); ?>"
                                                class="isf-destination-field regular-text"
                                                data-destination-key="<?php echo esc_attr($field_name); ?>">
                                            <?php foreach (($def['options'] ?? []) as $opt_val => $opt_label) : ?>
                                                <option value="<?php echo esc_attr((string) $opt_val); ?>" <?php selected((string) $value, (string) $opt_val); ?>>
                                                    <?php echo esc_html((string) $opt_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($type_in === 'textarea') : ?>
                                        <textarea id="<?php echo esc_attr($input_id); ?>"
                                                  class="isf-destination-field large-text code"
                                                  data-destination-key="<?php echo esc_attr($field_name); ?>"
                                                  rows="6"
                                                  <?php if ($sensitive) : ?>autocomplete="off"<?php endif; ?>
                                                  placeholder="<?php echo $has_stored ? esc_attr__('(saved — leave blank to keep)', 'formflow') : ''; ?>"><?php
                                            echo esc_textarea((string) $value);
                                        ?></textarea>
                                    <?php elseif ($type_in === 'checkbox') : ?>
                                        <label>
                                            <input type="checkbox"
                                                   id="<?php echo esc_attr($input_id); ?>"
                                                   class="isf-destination-field"
                                                   data-destination-key="<?php echo esc_attr($field_name); ?>"
                                                   <?php checked((bool) $value); ?>>
                                            <?php echo esc_html($help); ?>
                                        </label>
                                    <?php else :
                                        $html_type = $type_in === 'password' ? 'password'
                                                   : ($type_in === 'number' ? 'number' : 'text');
                                    ?>
                                        <input type="<?php echo esc_attr($html_type); ?>"
                                               id="<?php echo esc_attr($input_id); ?>"
                                               class="isf-destination-field regular-text"
                                               data-destination-key="<?php echo esc_attr($field_name); ?>"
                                               value="<?php echo esc_attr((string) $value); ?>"
                                               <?php if ($sensitive) : ?>
                                                   autocomplete="new-password"
                                                   placeholder="<?php echo $has_stored ? esc_attr__('(saved — leave blank to keep)', 'formflow') : ''; ?>"
                                               <?php endif; ?>>
                                    <?php endif; ?>

                                    <?php if ($type_in !== 'checkbox' && $help !== '') : ?>
                                        <p class="description"><?php echo esc_html($help); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>

    <?php
    // Hidden JSON sync field. JS keeps this updated as fields change;
    // ajax_save_instance reads $_POST['destinations_json'] server-side,
    // encrypts sensitive fields, and merges into settings.destinations.
    ?>
    <input type="hidden" name="destinations_json" id="isf-destinations-json"
           value="<?php echo esc_attr(wp_json_encode($destinations)); ?>">
</div>

<script>
(function ($) {
    'use strict';

    // Build the destinations JSON from current form state and write it
    // into the hidden sync field. Run on every field change + once on
    // load (defensive).
    function syncDestinationsJson() {
        var $hidden = $('#isf-destinations-json');
        if (!$hidden.length) return;

        // Start from the existing JSON so we preserve fields we don't
        // render (e.g. type/name set at template-import time).
        var existing;
        try { existing = JSON.parse($hidden.val() || '[]'); }
        catch (e) { existing = []; }
        if (!Array.isArray(existing)) existing = [];

        $('.isf-destination-block').each(function (idx) {
            if (!existing[idx]) existing[idx] = {};
            var $block = $(this);
            existing[idx].type = $block.data('destination-type') || existing[idx].type || '';
            existing[idx].config = existing[idx].config || {};

            $block.find('.isf-destination-field').each(function () {
                var $f = $(this);
                var key = $f.data('destination-key');
                if (!key) return;

                var val;
                if ($f.is(':checkbox')) {
                    val = $f.is(':checked');
                } else {
                    val = $f.val();
                }

                if (key === 'is_active') {
                    existing[idx].is_active = !!val;
                    return;
                }

                // Sensitive fields submitted blank: omit from JSON so
                // the server-side preserve-on-empty logic kicks in
                // (encrypt_sensitive_fields keeps the stored value).
                var $rowDef = $f.closest('[data-show-if]');
                if ((typeof val === 'string') && val === '') {
                    var $row = $f.closest('tr');
                    // crude sensitivity sniff: password / textarea with placeholder "(saved...)"
                    var isSensitive = $f.is('input[type=password]') ||
                                      ($f.is('textarea') && $f.attr('placeholder') && $f.attr('placeholder').indexOf('saved') !== -1);
                    if (isSensitive) {
                        // omit
                        delete existing[idx].config[key];
                        return;
                    }
                }

                existing[idx].config[key] = val;
            });
        });

        $hidden.val(JSON.stringify(existing));
    }

    // Honor show_if visibility on field rows.
    function applyShowIf() {
        $('.isf-destination-block').each(function () {
            var $block = $(this);
            var values = {};
            $block.find('.isf-destination-field').each(function () {
                var $f = $(this);
                var key = $f.data('destination-key');
                if (!key) return;
                values[key] = $f.is(':checkbox') ? $f.is(':checked') : $f.val();
            });

            $block.find('[data-show-if]').each(function () {
                var raw = $(this).attr('data-show-if');
                if (!raw) return;
                try {
                    var rule = JSON.parse(raw);
                    var visible = Object.keys(rule).every(function (k) {
                        var expected = rule[k];
                        var actual = values[k];
                        if (Array.isArray(expected)) return expected.indexOf(actual) !== -1;
                        return actual === expected;
                    });
                    $(this).toggle(visible);
                } catch (e) { /* ignore */ }
            });
        });
    }

    $(document).on('change input', '.isf-destination-field', function () {
        applyShowIf();
        syncDestinationsJson();
    });

    // Test Connection — posts the current config snapshot for this
    // destination to a dedicated AJAX endpoint; renders pass/fail inline.
    $(document).on('click', '.isf-destination-test', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var idx = $btn.data('destination-index');
        var $result = $btn.siblings('.isf-destination-test-result');

        // Make sure the hidden JSON reflects the current form state.
        syncDestinationsJson();

        var raw;
        try { raw = JSON.parse($('#isf-destinations-json').val() || '[]'); }
        catch (e) { raw = []; }
        var slot = raw[idx];
        if (!slot) {
            $result.text('No destination data.');
            return;
        }

        $result.html('<span class="dashicons dashicons-update"></span> ' + (window.isf_admin && isf_admin.strings && isf_admin.strings.testing ? isf_admin.strings.testing : 'Testing…'));
        $btn.prop('disabled', true);

        $.post(
            (window.isf_admin && isf_admin.ajax_url) || ajaxurl,
            {
                action: 'isf_test_destination',
                nonce: (window.isf_admin && isf_admin.nonce) || '',
                destination: JSON.stringify(slot)
            }
        ).done(function (resp) {
            if (resp && resp.success) {
                $result.html('<span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span> ' + (resp.data && resp.data.message ? resp.data.message : 'OK'));
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Test failed.';
                $result.html('<span class="dashicons dashicons-warning" style="color:#d63638"></span> ' + msg);
            }
        }).fail(function () {
            $result.html('<span class="dashicons dashicons-warning" style="color:#d63638"></span> Network error.');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    // Initial sync on load.
    applyShowIf();
    syncDestinationsJson();
}(jQuery));
</script>
