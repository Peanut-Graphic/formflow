<?php
/**
 * Conditional Logic Engine
 *
 * Handles show/hide logic, field calculations, and dynamic form behavior.
 *
 * @package FormFlow
 * @since 2.6.0
 */

namespace ISF\Builder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class ConditionalLogic
 *
 * Processes conditional rules for form fields.
 */
class ConditionalLogic {

    /**
     * Available operators
     */
    const OPERATORS = [
        'equals' => 'Equals',
        'not_equals' => 'Does not equal',
        'contains' => 'Contains',
        'not_contains' => 'Does not contain',
        'starts_with' => 'Starts with',
        'ends_with' => 'Ends with',
        'greater_than' => 'Greater than',
        'less_than' => 'Less than',
        'greater_equal' => 'Greater than or equal',
        'less_equal' => 'Less than or equal',
        'is_empty' => 'Is empty',
        'is_not_empty' => 'Is not empty',
        'is_checked' => 'Is checked',
        'is_not_checked' => 'Is not checked',
        'in_list' => 'Is one of',
        'not_in_list' => 'Is not one of',
    ];

    /**
     * Available actions
     */
    const ACTIONS = [
        'show' => 'Show field',
        'hide' => 'Hide field',
        'enable' => 'Enable field',
        'disable' => 'Disable field',
        'require' => 'Make required',
        'unrequire' => 'Make optional',
        'set_value' => 'Set value',
        'clear_value' => 'Clear value',
        'show_step' => 'Show step',
        'hide_step' => 'Hide step',
        'skip_step' => 'Skip to step',
    ];

    /**
     * Evaluate a condition against form data
     */
    public function evaluate_condition(array $condition, array $form_data): bool {
        $field_name = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? '';
        $field_value = $form_data[$field_name] ?? '';

        switch ($operator) {
            case 'equals':
                return $this->compare_values($field_value, $value);

            case 'not_equals':
                return !$this->compare_values($field_value, $value);

            case 'contains':
                return stripos((string) $field_value, (string) $value) !== false;

            case 'not_contains':
                return stripos((string) $field_value, (string) $value) === false;

            case 'starts_with':
                return strpos((string) $field_value, (string) $value) === 0;

            case 'ends_with':
                $needle_len = strlen((string) $value);
                // Every string ends with the empty string; guard against substr($s, -0)
                // returning the whole string and producing a false negative.
                if ($needle_len === 0) {
                    return true;
                }
                // nosemgrep: peanut-negative-length-substr -- $needle_len guaranteed > 0 by the guard above
                return substr((string) $field_value, -$needle_len) === (string) $value;

            case 'greater_than':
                return floatval($field_value) > floatval($value);

            case 'less_than':
                return floatval($field_value) < floatval($value);

            case 'greater_equal':
                return floatval($field_value) >= floatval($value);

            case 'less_equal':
                return floatval($field_value) <= floatval($value);

            case 'is_empty':
                return empty($field_value);

            case 'is_not_empty':
                return !empty($field_value);

            case 'is_checked':
                return $field_value === true || $field_value === '1' || $field_value === 'yes' || $field_value === 'on';

            case 'is_not_checked':
                return $field_value === false || $field_value === '0' || $field_value === 'no' || $field_value === '' || $field_value === null;

            case 'in_list':
                $list = is_array($value) ? $value : explode(',', $value);
                $list = array_map('trim', $list);
                return in_array($field_value, $list, true);

            case 'not_in_list':
                $list = is_array($value) ? $value : explode(',', $value);
                $list = array_map('trim', $list);
                return !in_array($field_value, $list, true);

            default:
                return false;
        }
    }

    /**
     * Compare values with type coercion
     */
    private function compare_values($a, $b): bool {
        // Handle arrays (checkboxes, multi-select)
        if (is_array($a)) {
            return in_array($b, $a, true);
        }

        // Handle booleans
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        // Handle numeric comparison
        if (is_numeric($a) && is_numeric($b)) {
            return floatval($a) === floatval($b);
        }

        // String comparison (case-insensitive)
        return strtolower((string) $a) === strtolower((string) $b);
    }

    /**
     * Evaluate a rule set (multiple conditions with AND/OR logic)
     */
    public function evaluate_rule(array $rule, array $form_data): bool {
        $conditions = $rule['conditions'] ?? [];
        $logic = $rule['logic'] ?? 'and'; // 'and' or 'or'

        if (empty($conditions)) {
            return true; // No conditions = always true
        }

        if ($logic === 'and') {
            // All conditions must be true
            foreach ($conditions as $condition) {
                if (!$this->evaluate_condition($condition, $form_data)) {
                    return false;
                }
            }
            return true;
        } else {
            // At least one condition must be true
            foreach ($conditions as $condition) {
                if ($this->evaluate_condition($condition, $form_data)) {
                    return true;
                }
            }
            return false;
        }
    }

    /**
     * Process all conditional rules for a form schema
     */
    public function process_schema(array $schema, array $form_data): array {
        $result = [
            'visible_fields' => [],
            'hidden_fields' => [],
            'enabled_fields' => [],
            'disabled_fields' => [],
            'required_fields' => [],
            'optional_fields' => [],
            'visible_steps' => [],
            'hidden_steps' => [],
            'field_values' => [],
            'skip_to_step' => null,
        ];

        foreach ($schema['steps'] ?? [] as $step_index => $step) {
            $step_id = $step['id'] ?? "step_{$step_index}";

            // Check step-level conditions
            if (!empty($step['conditions'])) {
                $step_visible = $this->evaluate_rule([
                    'conditions' => $step['conditions'],
                    'logic' => $step['condition_logic'] ?? 'and',
                ], $form_data);

                if ($step_visible) {
                    $result['visible_steps'][] = $step_id;
                } else {
                    $result['hidden_steps'][] = $step_id;
                    continue; // Skip processing fields in hidden steps
                }
            } else {
                $result['visible_steps'][] = $step_id;
            }

            // Process field conditions
            foreach ($step['fields'] ?? [] as $field) {
                $field_name = $field['name'] ?? '';
                if (empty($field_name)) {
                    continue;
                }

                $field_rules = $field['conditional_logic'] ?? [];

                foreach ($field_rules as $rule) {
                    $rule_matches = $this->evaluate_rule($rule, $form_data);

                    if ($rule_matches) {
                        $action = $rule['action'] ?? 'show';

                        switch ($action) {
                            case 'show':
                                $result['visible_fields'][] = $field_name;
                                break;
                            case 'hide':
                                $result['hidden_fields'][] = $field_name;
                                break;
                            case 'enable':
                                $result['enabled_fields'][] = $field_name;
                                break;
                            case 'disable':
                                $result['disabled_fields'][] = $field_name;
                                break;
                            case 'require':
                                $result['required_fields'][] = $field_name;
                                break;
                            case 'unrequire':
                                $result['optional_fields'][] = $field_name;
                                break;
                            case 'set_value':
                                $result['field_values'][$field_name] = $rule['set_value'] ?? '';
                                break;
                            case 'clear_value':
                                $result['field_values'][$field_name] = '';
                                break;
                            case 'skip_step':
                                $result['skip_to_step'] = $rule['skip_to_step'] ?? null;
                                break;
                        }
                    }
                }

                // If no rules matched or no hide rule, field is visible by default
                if (!in_array($field_name, $result['hidden_fields'])) {
                    $result['visible_fields'][] = $field_name;
                }
            }
        }

        // Remove duplicates
        $result['visible_fields'] = array_unique($result['visible_fields']);
        $result['hidden_fields'] = array_unique($result['hidden_fields']);
        $result['enabled_fields'] = array_unique($result['enabled_fields']);
        $result['disabled_fields'] = array_unique($result['disabled_fields']);
        $result['required_fields'] = array_unique($result['required_fields']);
        $result['optional_fields'] = array_unique($result['optional_fields']);

        // Hidden fields should not be in visible list
        $result['visible_fields'] = array_diff($result['visible_fields'], $result['hidden_fields']);

        return $result;
    }

    /**
     * Generate JavaScript for client-side conditional logic
     *
     * Uses safe JSON encoding to prevent XSS attacks from malicious
     * field names or condition values.
     */
    public function generate_client_script(array $schema): string {
        $rules = $this->extract_rules_for_js($schema);

        if (empty($rules)) {
            return '';
        }

        // Sanitize all string values in the rules to prevent XSS
        $rules = $this->sanitize_rules_for_js($rules);

        // Use JSON_HEX_TAG, JSON_HEX_APOS, JSON_HEX_QUOT, JSON_HEX_AMP to prevent script injection
        $rules_json = wp_json_encode($rules, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        if ($rules_json === false) {
            // JSON encoding failed - log error and return empty
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[FormFlow ConditionalLogic] Failed to encode rules to JSON');
            }
            return '';
        }

        return <<<JS
(function($) {
    'use strict';

    const conditionalRules = {$rules_json};

    const ConditionalLogic = {
        rules: conditionalRules,
        formContainer: null,

        init: function(container) {
            this.formContainer = $(container);
            this.bindEvents();
            this.evaluateAll();
        },

        bindEvents: function() {
            const self = this;

            // Listen to all input changes
            this.formContainer.on('change input', 'input, select, textarea', function() {
                self.evaluateAll();
            });

            // Listen to checkbox/radio changes
            this.formContainer.on('click', 'input[type="checkbox"], input[type="radio"]', function() {
                setTimeout(function() {
                    self.evaluateAll();
                }, 10);
            });
        },

        getFormData: function() {
            const data = {};
            const formElements = this.formContainer.find('input, select, textarea');

            formElements.each(function() {
                const el = $(this);
                const name = el.attr('name');
                if (!name) return;

                const type = el.attr('type');

                if (type === 'checkbox') {
                    if (el.is(':checked')) {
                        if (data[name]) {
                            if (!Array.isArray(data[name])) {
                                data[name] = [data[name]];
                            }
                            data[name].push(el.val());
                        } else {
                            data[name] = el.val();
                        }
                    }
                } else if (type === 'radio') {
                    if (el.is(':checked')) {
                        data[name] = el.val();
                    }
                } else {
                    data[name] = el.val();
                }
            });

            return data;
        },

        evaluateCondition: function(condition, formData) {
            const fieldValue = formData[condition.field] || '';
            const compareValue = condition.value;
            const operator = condition.operator;

            switch (operator) {
                case 'equals':
                    return this.compareValues(fieldValue, compareValue);
                case 'not_equals':
                    return !this.compareValues(fieldValue, compareValue);
                case 'contains':
                    return String(fieldValue).toLowerCase().includes(String(compareValue).toLowerCase());
                case 'not_contains':
                    return !String(fieldValue).toLowerCase().includes(String(compareValue).toLowerCase());
                case 'greater_than':
                    return parseFloat(fieldValue) > parseFloat(compareValue);
                case 'less_than':
                    return parseFloat(fieldValue) < parseFloat(compareValue);
                case 'greater_equal':
                    return parseFloat(fieldValue) >= parseFloat(compareValue);
                case 'less_equal':
                    return parseFloat(fieldValue) <= parseFloat(compareValue);
                case 'is_empty':
                    return !fieldValue || fieldValue === '';
                case 'is_not_empty':
                    return fieldValue && fieldValue !== '';
                case 'is_checked':
                    return fieldValue === true || fieldValue === '1' || fieldValue === 'yes' || fieldValue === 'on';
                case 'is_not_checked':
                    return !fieldValue || fieldValue === '0' || fieldValue === 'no' || fieldValue === '';
                case 'in_list':
                    const list = Array.isArray(compareValue) ? compareValue : String(compareValue).split(',').map(s => s.trim());
                    return list.includes(fieldValue);
                case 'not_in_list':
                    const listNot = Array.isArray(compareValue) ? compareValue : String(compareValue).split(',').map(s => s.trim());
                    return !listNot.includes(fieldValue);
                default:
                    return false;
            }
        },

        compareValues: function(a, b) {
            if (Array.isArray(a)) {
                return a.includes(b);
            }
            return String(a).toLowerCase() === String(b).toLowerCase();
        },

        evaluateRule: function(rule, formData) {
            const conditions = rule.conditions || [];
            const logic = rule.logic || 'and';

            if (conditions.length === 0) return true;

            if (logic === 'and') {
                return conditions.every(c => this.evaluateCondition(c, formData));
            } else {
                return conditions.some(c => this.evaluateCondition(c, formData));
            }
        },

        evaluateAll: function() {
            const formData = this.getFormData();
            const self = this;

            // Process each field's rules
            Object.keys(this.rules).forEach(function(fieldName) {
                const fieldRules = self.rules[fieldName];
                const fieldWrapper = self.formContainer.find('[data-field="' + fieldName + '"]');
                const fieldInput = fieldWrapper.find('input, select, textarea');

                fieldRules.forEach(function(rule) {
                    const matches = self.evaluateRule(rule, formData);

                    if (matches) {
                        self.applyAction(rule.action, fieldWrapper, fieldInput, rule);
                    } else {
                        self.revertAction(rule.action, fieldWrapper, fieldInput, rule);
                    }
                });
            });

            // Trigger event for other scripts
            this.formContainer.trigger('isf:conditional_evaluated', [formData]);
        },

        applyAction: function(action, wrapper, input, rule) {
            switch (action) {
                case 'show':
                    wrapper.slideDown(200).removeClass('isf-conditional-hidden');
                    break;
                case 'hide':
                    wrapper.slideUp(200).addClass('isf-conditional-hidden');
                    input.val(''); // Clear value when hidden
                    break;
                case 'enable':
                    input.prop('disabled', false).removeClass('isf-disabled');
                    break;
                case 'disable':
                    input.prop('disabled', true).addClass('isf-disabled');
                    break;
                case 'require':
                    input.prop('required', true);
                    wrapper.addClass('isf-required');
                    break;
                case 'unrequire':
                    input.prop('required', false);
                    wrapper.removeClass('isf-required');
                    break;
                case 'set_value':
                    if (rule.set_value !== undefined) {
                        input.val(rule.set_value).trigger('change');
                    }
                    break;
                case 'clear_value':
                    input.val('').trigger('change');
                    break;
            }
        },

        revertAction: function(action, wrapper, input, rule) {
            // Revert actions when condition no longer matches
            switch (action) {
                case 'show':
                    // Don't automatically hide - let hide rules handle it
                    break;
                case 'hide':
                    wrapper.slideDown(200).removeClass('isf-conditional-hidden');
                    break;
                case 'enable':
                    // Don't automatically disable
                    break;
                case 'disable':
                    input.prop('disabled', false).removeClass('isf-disabled');
                    break;
                case 'require':
                    // Don't automatically unrequire
                    break;
                case 'unrequire':
                    // Restore original required state if needed
                    break;
            }
        }
    };

    // Initialize when form is ready
    $(document).ready(function() {
        $('.isf-form-container').each(function() {
            ConditionalLogic.init(this);
        });
    });

    // Expose for external use
    window.ISFConditionalLogic = ConditionalLogic;

})(jQuery);
JS;
    }

    /**
     * Extract rules from schema for JavaScript
     */
    private function extract_rules_for_js(array $schema): array {
        $rules = [];

        foreach ($schema['steps'] ?? [] as $step) {
            foreach ($step['fields'] ?? [] as $field) {
                $field_name = $field['name'] ?? '';
                if (empty($field_name)) {
                    continue;
                }

                // Validate field name format (alphanumeric, underscores, hyphens only)
                if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_\-]*$/', $field_name)) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[FormFlow ConditionalLogic] Invalid field name skipped: ' . $field_name);
                    }
                    continue;
                }

                $field_rules = $field['conditional_logic'] ?? [];
                if (!empty($field_rules)) {
                    $rules[$field_name] = $field_rules;
                }
            }
        }

        return $rules;
    }

    /**
     * Sanitize rules for safe JavaScript embedding
     *
     * Recursively sanitizes all string values to prevent XSS.
     */
    private function sanitize_rules_for_js(array $rules): array {
        $sanitized = [];

        foreach ($rules as $key => $value) {
            // Sanitize the key (field name)
            $safe_key = preg_replace('/[^a-zA-Z0-9_\-]/', '', $key);

            if (is_array($value)) {
                $sanitized[$safe_key] = $this->sanitize_rules_for_js($value);
            } elseif (is_string($value)) {
                // Remove any script tags or event handlers
                $sanitized[$safe_key] = $this->sanitize_js_string($value);
            } else {
                // Numbers, booleans, null - pass through
                $sanitized[$safe_key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize a string value for JavaScript embedding
     */
    private function sanitize_js_string(string $value): string {
        // Remove script tags
        $value = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $value);

        // Remove event handlers (onclick, onerror, etc.)
        $value = preg_replace('/\bon\w+\s*=/i', '', $value);

        // Remove javascript: protocol
        $value = preg_replace('/javascript\s*:/i', '', $value);

        // Escape HTML entities for safety
        return esc_html($value);
    }

    /**
     * Get available operators for UI
     */
    public static function get_operators(): array {
        return array_map(function($label) {
            return __($label, 'formflow');
        }, self::OPERATORS);
    }

    /**
     * Get available actions for UI
     */
    public static function get_actions(): array {
        return array_map(function($label) {
            return __($label, 'formflow');
        }, self::ACTIONS);
    }
}
