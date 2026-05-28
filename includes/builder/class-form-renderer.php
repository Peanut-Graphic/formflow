<?php
/**
 * Form Renderer
 *
 * Renders forms from schema to HTML.
 *
 * @package FormFlow
 * @since 2.6.0
 */

namespace ISF\Builder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class FormRenderer
 *
 * Converts form schema to HTML output.
 */
class FormRenderer {

    /**
     * Render a complete form from schema
     */
    public function render(array $schema, array $instance = [], array $form_data = []): string {
        $steps = $schema['steps'] ?? [];
        $settings = $schema['settings'] ?? [];

        if (empty($steps)) {
            return '';
        }

        // Process conditional logic
        $conditional = new ConditionalLogic();
        $visibility = $conditional->process_schema($schema, $form_data);

        ob_start();
        ?>
        <div class="isf-form-container isf-builder-form" data-instance="<?php echo esc_attr($instance['id'] ?? 0); ?>">
            <?php $this->render_progress_bar($steps, 1); ?>

            <form class="isf-form" method="post" novalidate>
                <?php wp_nonce_field('isf_form_submit', 'isf_nonce'); ?>
                <input type="hidden" name="instance_id" value="<?php echo esc_attr($instance['id'] ?? 0); ?>">
                <input type="hidden" name="current_step" value="1">

                <?php foreach ($steps as $index => $step) : ?>
                    <?php
                    $step_id = $step['id'] ?? "step_{$index}";
                    $is_visible = in_array($step_id, $visibility['visible_steps']);
                    $is_first = $index === 0;
                    ?>
                    <div class="isf-step <?php echo $is_first ? 'active' : ''; ?> <?php echo !$is_visible ? 'isf-conditional-hidden' : ''; ?>"
                         data-step="<?php echo $index + 1; ?>"
                         data-step-id="<?php echo esc_attr($step_id); ?>">

                        <?php if (!empty($step['title'])) : ?>
                            <h2 class="isf-step-title"><?php echo esc_html($step['title']); ?></h2>
                        <?php endif; ?>

                        <?php if (!empty($step['description'])) : ?>
                            <p class="isf-step-description"><?php echo esc_html($step['description']); ?></p>
                        <?php endif; ?>

                        <div class="isf-step-fields">
                            <?php foreach ($step['fields'] ?? [] as $field) : ?>
                                <?php echo $this->render_field($field, $form_data, $visibility); ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="isf-form-actions">
                    <button type="button" class="isf-btn isf-btn-secondary isf-btn-prev" style="display: none;">
                        <?php echo esc_html($settings['prev_button_text'] ?? __('Previous', 'formflow')); ?>
                    </button>
                    <button type="button" class="isf-btn isf-btn-primary isf-btn-next">
                        <?php echo esc_html($settings['next_button_text'] ?? __('Next', 'formflow')); ?>
                    </button>
                    <button type="submit" class="isf-btn isf-btn-primary isf-btn-submit" style="display: none;">
                        <?php echo esc_html($settings['submit_button_text'] ?? __('Submit', 'formflow')); ?>
                    </button>
                </div>
            </form>
        </div>

        <?php
        // Add conditional logic script
        $script = $conditional->generate_client_script($schema);
        if ($script) {
            echo '<script>' . $script . '</script>';
        }

        return ob_get_clean();
    }

    /**
     * Render preview (for builder)
     */
    public function render_preview(array $schema): string {
        return $this->render($schema, [], []);
    }

    /**
     * Render progress bar
     */
    private function render_progress_bar(array $steps, int $current_step): void {
        $total_steps = count($steps);
        $progress_percent = (($current_step - 1) / max($total_steps - 1, 1)) * 100;
        ?>
        <div class="isf-progress-container">
            <div class="isf-progress-bar" role="progressbar" aria-valuenow="<?php echo $progress_percent; ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="isf-progress-fill" style="width: <?php echo $progress_percent; ?>%;"></div>
            </div>
            <div class="isf-progress-steps" role="navigation" aria-label="<?php esc_attr_e('Form progress', 'formflow'); ?>">
                <?php foreach ($steps as $index => $step) : ?>
                    <?php
                    $step_num = $index + 1;
                    $is_active = $step_num === $current_step;
                    $is_completed = $step_num < $current_step;
                    ?>
                    <div class="isf-progress-step <?php echo $is_active ? 'active' : ''; ?> <?php echo $is_completed ? 'completed' : ''; ?>"
                         data-step="<?php echo $step_num; ?>"
                         aria-current="<?php echo $is_active ? 'step' : 'false'; ?>">
                        <span class="isf-step-number"><?php echo $step_num; ?></span>
                        <span class="isf-step-label"><?php echo esc_html($step['title'] ?? "Step {$step_num}"); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single field
     */
    public function render_field(array $field, array $form_data = [], array $visibility = []): string {
        // Normalize legacy / hand-authored template shape where label,
        // required, helpText, defaultValue, options, etc. live at the
        // top of the field instead of under `settings`. The type-specific
        // render_*_field() methods read everything from $field['settings'],
        // so we mirror top-level keys into settings here.
        $field = $this->normalize_field_shape($field);

        $type = $field['type'] ?? 'text';
        $name = $field['name'] ?? '';
        $label = $field['settings']['label'] ?? $field['label'] ?? '';
        $required = $field['settings']['required'] ?? $field['required'] ?? false;
        $value = $form_data[$name] ?? $field['settings']['default_value'] ?? '';
        $help_text = $field['settings']['help_text'] ?? '';
        $placeholder = $field['settings']['placeholder'] ?? '';

        // Check visibility
        $is_hidden = $name && in_array($name, $visibility['hidden_fields'] ?? []);
        $is_disabled = $name && in_array($name, $visibility['disabled_fields'] ?? []);

        // Dynamic required based on conditional logic
        if (in_array($name, $visibility['required_fields'] ?? [])) {
            $required = true;
        } elseif (in_array($name, $visibility['optional_fields'] ?? [])) {
            $required = false;
        }

        // Set value from conditional logic
        if (isset($visibility['field_values'][$name])) {
            $value = $visibility['field_values'][$name];
        }

        // Generate unique ID with proper for/id association
        $id = 'isf_' . ($name ?: uniqid('field_'));
        $help_id = $help_text ? $id . '_help' : '';
        $error_id = $id . '_error';

        // Wrapper classes
        $wrapper_classes = ['isf-field-wrapper', "isf-field-type-{$type}"];
        if ($required) {
            $wrapper_classes[] = 'isf-required';
        }
        if ($is_hidden) {
            $wrapper_classes[] = 'isf-conditional-hidden';
        }

        ob_start();
        ?>
        <div class="<?php echo esc_attr(implode(' ', $wrapper_classes)); ?>"
             data-field="<?php echo esc_attr($name); ?>"
             role="group"
             <?php echo $label ? 'aria-labelledby="' . esc_attr($id) . '_label"' : ''; ?>
             <?php echo $is_hidden ? 'style="display: none;" aria-hidden="true"' : ''; ?>>

            <?php
            // Render field based on type
            switch ($type) {
                case 'text':
                case 'email':
                case 'phone':
                case 'number':
                    $this->render_input_field($field, $id, $name, $value, $is_disabled);
                    break;

                case 'textarea':
                    $this->render_textarea_field($field, $id, $name, $value, $is_disabled);
                    break;

                case 'select':
                    $this->render_select_field($field, $id, $name, $value, $is_disabled);
                    break;

                case 'radio':
                    $this->render_radio_field($field, $id, $name, $value, $is_disabled);
                    break;

                case 'checkbox':
                    $this->render_checkbox_field($field, $id, $name, $value, $is_disabled);
                    break;

                case 'toggle':
                    $this->render_toggle_field($field, $id, $name, $value, $is_disabled);
                    break;

                case 'date':
                    $this->render_date_field($field, $id, $name, $value, $is_disabled);
                    break;

                case 'time':
                    $this->render_time_field($field, $id, $name, $value, $is_disabled);
                    break;

                case 'file':
                    $this->render_file_field($field, $id, $name, $is_disabled);
                    break;

                case 'signature':
                    $this->render_signature_field($field, $id, $name, $is_disabled);
                    break;

                case 'address':
                    $this->render_address_field($field, $id, $name, $form_data, $is_disabled);
                    break;

                case 'account_number':
                    $this->render_account_number_field($field, $id, $name, $value, $is_disabled);
                    break;

                case 'program_selector':
                    $this->render_program_selector($field, $id, $name, $value, $is_disabled);
                    break;

                case 'heading':
                    $this->render_heading($field);
                    break;

                case 'paragraph':
                    $this->render_paragraph($field);
                    break;

                case 'divider':
                    $this->render_divider($field);
                    break;

                case 'spacer':
                    $this->render_spacer($field);
                    break;

                case 'columns':
                    $this->render_columns($field, $form_data, $visibility);
                    break;

                case 'section':
                    $this->render_section($field, $form_data, $visibility);
                    break;

                case 'likert_scale':
                    $this->render_likert_scale_field($field, $id, $name, $value, $is_disabled);
                    break;

                case 'slider':
                    $this->render_slider_field($field, $id, $name, $value, $is_disabled);
                    break;

                case 'recaptcha_v3':
                    $this->render_recaptcha_v3_field($field, $id, $name, $is_disabled);
                    break;

                case 'repeater':
                    $this->render_repeater_field($field, $id, $name, $form_data, $visibility, $is_disabled);
                    break;

                case 'star_rating':
                    $this->render_star_rating_field($field, $id, $name, $value, $is_disabled);
                    break;

                case 'date_range':
                    $this->render_date_range_field($field, $id, $name, $value, $is_disabled);
                    break;

                case 'address_autocomplete':
                    $this->render_address_autocomplete_field($field, $id, $name, $value, $is_disabled);
                    break;

                case 'number_stepper':
                    $this->render_number_stepper_field($field, $id, $name, $value, $is_disabled);
                    break;

                case 'color_picker':
                    $this->render_color_picker_field($field, $id, $name, $value, $is_disabled);
                    break;

                default:
                    // Allow custom field type rendering
                    do_action("isf_render_field_type_{$type}", $field, $id, $name, $value, $is_disabled);
                    break;
            }
            ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Mirror legacy top-level field keys into $field['settings'] so the
     * type-specific renderers find them. Existing $field['settings']
     * values always win over top-level fallbacks.
     */
    private function normalize_field_shape(array $field): array {
        $settings = is_array($field['settings'] ?? null) ? $field['settings'] : [];

        $map = [
            'label'         => 'label',
            'required'      => 'required',
            'placeholder'   => 'placeholder',
            'helpText'      => 'help_text',
            'help_text'     => 'help_text',
            'defaultValue'  => 'default_value',
            'default_value' => 'default_value',
            'pattern'       => 'pattern',
            'min'           => 'min',
            'max'           => 'max',
            'step'          => 'step',
            'maxLength'     => 'max_length',
            'max_length'    => 'max_length',
            'options'       => 'options',
            'rows'          => 'rows',
            'accept'        => 'accept',
            'description'   => 'description',
        ];

        foreach ($map as $top_key => $settings_key) {
            if (array_key_exists($top_key, $field) && !array_key_exists($settings_key, $settings)) {
                $settings[$settings_key] = $field[$top_key];
            }
        }

        $field['settings'] = $settings;
        return $field;
    }

    /**
     * Render standard input field (text, email, phone, number)
     */
    private function render_input_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $type = $field['type'] ?? 'text';
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? false;
        $label = $settings['label'] ?? '';
        $placeholder = $settings['placeholder'] ?? '';
        $help_text = $settings['help_text'] ?? '';
        $max_length = $settings['max_length'] ?? '';
        $pattern = $settings['pattern'] ?? '';
        $min = $settings['min'] ?? '';
        $max = $settings['max'] ?? '';
        $step = $settings['step'] ?? '';

        // Map type for HTML input
        $input_type = $type;
        if ($type === 'phone') {
            $input_type = 'tel';
        }
        ?>
        <?php if ($label) : ?>
            <label for="<?php echo esc_attr($id); ?>" id="<?php echo esc_attr($id); ?>_label" class="isf-label">
                <?php echo esc_html($label); ?>
                <?php if ($required) : ?>
                    <span class="isf-required-indicator" aria-hidden="true">*</span>
                    <span class="isf-sr-only"><?php esc_html_e('(required)', 'formflow'); ?></span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <input type="<?php echo esc_attr($input_type); ?>"
               id="<?php echo esc_attr($id); ?>"
               name="<?php echo esc_attr($name); ?>"
               value="<?php echo esc_attr($value); ?>"
               class="isf-input"
               <?php echo $placeholder ? 'placeholder="' . esc_attr($placeholder) . '"' : ''; ?>
               <?php echo $required ? 'required aria-required="true"' : ''; ?>
               <?php echo $disabled ? 'disabled aria-disabled="true"' : ''; ?>
               <?php echo $max_length ? 'maxlength="' . esc_attr($max_length) . '"' : ''; ?>
               <?php echo $pattern ? 'pattern="' . esc_attr($pattern) . '"' : ''; ?>
               <?php echo $type === 'number' && $min !== '' ? 'min="' . esc_attr($min) . '"' : ''; ?>
               <?php echo $type === 'number' && $max !== '' ? 'max="' . esc_attr($max) . '"' : ''; ?>
               <?php echo $type === 'number' && $step ? 'step="' . esc_attr($step) . '"' : ''; ?>
               <?php echo $help_text ? 'aria-describedby="' . esc_attr($id) . '_help ' . esc_attr($id) . '_error"' : 'aria-describedby="' . esc_attr($id) . '_error"'; ?>
               aria-invalid="false">

        <?php if ($help_text) : ?>
            <p id="<?php echo esc_attr($id); ?>_help" class="isf-help-text"><?php echo esc_html($help_text); ?></p>
        <?php endif; ?>

        <div id="<?php echo esc_attr($id); ?>_error" class="isf-field-error" role="alert" aria-live="polite"></div>
        <?php
    }

    /**
     * Render textarea field
     */
    private function render_textarea_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? false;
        $label = $settings['label'] ?? '';
        $placeholder = $settings['placeholder'] ?? '';
        $help_text = $settings['help_text'] ?? '';
        $rows = $settings['rows'] ?? 4;
        $max_length = $settings['max_length'] ?? '';
        ?>
        <?php if ($label) : ?>
            <label for="<?php echo esc_attr($id); ?>" class="isf-label">
                <?php echo esc_html($label); ?>
                <?php if ($required) : ?>
                    <span class="isf-required-indicator" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <textarea id="<?php echo esc_attr($id); ?>"
                  name="<?php echo esc_attr($name); ?>"
                  class="isf-textarea"
                  rows="<?php echo esc_attr($rows); ?>"
                  <?php echo $placeholder ? 'placeholder="' . esc_attr($placeholder) . '"' : ''; ?>
                  <?php echo $required ? 'required aria-required="true"' : ''; ?>
                  <?php echo $disabled ? 'disabled' : ''; ?>
                  <?php echo $max_length ? 'maxlength="' . esc_attr($max_length) . '"' : ''; ?>
                  <?php echo $help_text ? 'aria-describedby="' . esc_attr($id) . '_help"' : ''; ?>><?php echo esc_textarea($value); ?></textarea>

        <?php if ($help_text) : ?>
            <p id="<?php echo esc_attr($id); ?>_help" class="isf-help-text"><?php echo esc_html($help_text); ?></p>
        <?php endif; ?>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render select/dropdown field
     */
    private function render_select_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? false;
        $label = $settings['label'] ?? '';
        $placeholder = $settings['placeholder'] ?? __('Select an option', 'formflow');
        $options = $settings['options'] ?? [];
        $searchable = $settings['searchable'] ?? false;
        ?>
        <?php if ($label) : ?>
            <label for="<?php echo esc_attr($id); ?>" class="isf-label">
                <?php echo esc_html($label); ?>
                <?php if ($required) : ?>
                    <span class="isf-required-indicator" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <select id="<?php echo esc_attr($id); ?>"
                name="<?php echo esc_attr($name); ?>"
                class="isf-select <?php echo $searchable ? 'isf-searchable' : ''; ?>"
                <?php echo $required ? 'required aria-required="true"' : ''; ?>
                <?php echo $disabled ? 'disabled' : ''; ?>>
            <option value=""><?php echo esc_html($placeholder); ?></option>
            <?php foreach ($options as $option) : ?>
                <?php
                $opt_value = is_array($option) ? ($option['value'] ?? '') : $option;
                $opt_label = is_array($option) ? ($option['label'] ?? $opt_value) : $option;
                ?>
                <option value="<?php echo esc_attr($opt_value); ?>" <?php selected($value, $opt_value); ?>>
                    <?php echo esc_html($opt_label); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render radio button group
     */
    private function render_radio_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? false;
        $label = $settings['label'] ?? '';
        $options = $settings['options'] ?? [];
        $layout = $settings['layout'] ?? 'vertical';
        ?>
        <fieldset class="isf-fieldset">
            <?php if ($label) : ?>
                <legend class="isf-legend">
                    <?php echo esc_html($label); ?>
                    <?php if ($required) : ?>
                        <span class="isf-required-indicator" aria-hidden="true">*</span>
                    <?php endif; ?>
                </legend>
            <?php endif; ?>

            <div class="isf-radio-group isf-layout-<?php echo esc_attr($layout); ?>">
                <?php foreach ($options as $index => $option) : ?>
                    <?php
                    $opt_value = is_array($option) ? ($option['value'] ?? '') : $option;
                    $opt_label = is_array($option) ? ($option['label'] ?? $opt_value) : $option;
                    $opt_id = $id . '_' . $index;
                    ?>
                    <label class="isf-radio-label" for="<?php echo esc_attr($opt_id); ?>">
                        <input type="radio"
                               id="<?php echo esc_attr($opt_id); ?>"
                               name="<?php echo esc_attr($name); ?>"
                               value="<?php echo esc_attr($opt_value); ?>"
                               class="isf-radio"
                               <?php checked($value, $opt_value); ?>
                               <?php echo $required && $index === 0 ? 'required' : ''; ?>
                               <?php echo $disabled ? 'disabled' : ''; ?>>
                        <span class="isf-radio-text"><?php echo esc_html($opt_label); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render checkbox group
     */
    private function render_checkbox_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? false;
        $label = $settings['label'] ?? '';
        $options = $settings['options'] ?? [];

        $selected = is_array($value) ? $value : [$value];
        ?>
        <fieldset class="isf-fieldset">
            <?php if ($label) : ?>
                <legend class="isf-legend">
                    <?php echo esc_html($label); ?>
                    <?php if ($required) : ?>
                        <span class="isf-required-indicator" aria-hidden="true">*</span>
                    <?php endif; ?>
                </legend>
            <?php endif; ?>

            <div class="isf-checkbox-group">
                <?php foreach ($options as $index => $option) : ?>
                    <?php
                    $opt_value = is_array($option) ? ($option['value'] ?? '') : $option;
                    $opt_label = is_array($option) ? ($option['label'] ?? $opt_value) : $option;
                    $opt_id = $id . '_' . $index;
                    ?>
                    <label class="isf-checkbox-label" for="<?php echo esc_attr($opt_id); ?>">
                        <input type="checkbox"
                               id="<?php echo esc_attr($opt_id); ?>"
                               name="<?php echo esc_attr($name); ?>[]"
                               value="<?php echo esc_attr($opt_value); ?>"
                               class="isf-checkbox"
                               <?php checked(in_array($opt_value, $selected)); ?>
                               <?php echo $disabled ? 'disabled' : ''; ?>>
                        <span class="isf-checkbox-text"><?php echo esc_html($opt_label); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render toggle switch
     */
    private function render_toggle_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $label = $settings['label'] ?? '';
        $on_label = $settings['on_label'] ?? __('Yes', 'formflow');
        $off_label = $settings['off_label'] ?? __('No', 'formflow');
        $is_on = $value === true || $value === '1' || $value === 'yes' || $value === 'on';
        ?>
        <div class="isf-toggle-wrapper">
            <?php if ($label) : ?>
                <span class="isf-label"><?php echo esc_html($label); ?></span>
            <?php endif; ?>

            <label class="isf-toggle" for="<?php echo esc_attr($id); ?>">
                <input type="checkbox"
                       id="<?php echo esc_attr($id); ?>"
                       name="<?php echo esc_attr($name); ?>"
                       value="1"
                       class="isf-toggle-input"
                       <?php checked($is_on); ?>
                       <?php echo $disabled ? 'disabled' : ''; ?>>
                <span class="isf-toggle-slider"></span>
                <span class="isf-toggle-labels">
                    <span class="isf-toggle-on"><?php echo esc_html($on_label); ?></span>
                    <span class="isf-toggle-off"><?php echo esc_html($off_label); ?></span>
                </span>
            </label>
        </div>
        <?php
    }

    /**
     * Render date picker
     */
    private function render_date_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? false;
        $label = $settings['label'] ?? '';
        $min_date = $settings['min_date'] ?? '';
        $max_date = $settings['max_date'] ?? '';

        // Handle "today" placeholder
        if ($min_date === 'today') {
            $min_date = date('Y-m-d');
        }
        if ($max_date === 'today') {
            $max_date = date('Y-m-d');
        }
        ?>
        <?php if ($label) : ?>
            <label for="<?php echo esc_attr($id); ?>" class="isf-label">
                <?php echo esc_html($label); ?>
                <?php if ($required) : ?>
                    <span class="isf-required-indicator" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <input type="date"
               id="<?php echo esc_attr($id); ?>"
               name="<?php echo esc_attr($name); ?>"
               value="<?php echo esc_attr($value); ?>"
               class="isf-input isf-date-input"
               <?php echo $required ? 'required aria-required="true"' : ''; ?>
               <?php echo $disabled ? 'disabled' : ''; ?>
               <?php echo $min_date ? 'min="' . esc_attr($min_date) . '"' : ''; ?>
               <?php echo $max_date ? 'max="' . esc_attr($max_date) . '"' : ''; ?>>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render time picker
     */
    private function render_time_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? false;
        $label = $settings['label'] ?? '';
        $min_time = $settings['min_time'] ?? '';
        $max_time = $settings['max_time'] ?? '';
        ?>
        <?php if ($label) : ?>
            <label for="<?php echo esc_attr($id); ?>" class="isf-label">
                <?php echo esc_html($label); ?>
                <?php if ($required) : ?>
                    <span class="isf-required-indicator" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <input type="time"
               id="<?php echo esc_attr($id); ?>"
               name="<?php echo esc_attr($name); ?>"
               value="<?php echo esc_attr($value); ?>"
               class="isf-input isf-time-input"
               <?php echo $required ? 'required aria-required="true"' : ''; ?>
               <?php echo $disabled ? 'disabled' : ''; ?>
               <?php echo $min_time ? 'min="' . esc_attr($min_time) . '"' : ''; ?>
               <?php echo $max_time ? 'max="' . esc_attr($max_time) . '"' : ''; ?>>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render file upload field
     */
    private function render_file_field(array $field, string $id, string $name, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? false;
        $label = $settings['label'] ?? '';
        $allowed_types = $settings['allowed_types'] ?? 'jpg,jpeg,png,pdf';
        $max_size = $settings['max_size'] ?? 5;
        $multiple = $settings['multiple'] ?? false;

        $accept = implode(',', array_map(function($ext) {
            return '.' . trim($ext);
        }, explode(',', $allowed_types)));
        ?>
        <?php if ($label) : ?>
            <label for="<?php echo esc_attr($id); ?>" class="isf-label">
                <?php echo esc_html($label); ?>
                <?php if ($required) : ?>
                    <span class="isf-required-indicator" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <div class="isf-file-upload">
            <input type="file"
                   id="<?php echo esc_attr($id); ?>"
                   name="<?php echo esc_attr($name); ?><?php echo $multiple ? '[]' : ''; ?>"
                   class="isf-file-input"
                   accept="<?php echo esc_attr($accept); ?>"
                   <?php echo $required ? 'required aria-required="true"' : ''; ?>
                   <?php echo $disabled ? 'disabled' : ''; ?>
                   <?php echo $multiple ? 'multiple' : ''; ?>
                   data-max-size="<?php echo esc_attr($max_size); ?>">

            <div class="isf-file-dropzone">
                <span class="dashicons dashicons-upload"></span>
                <span class="isf-file-text"><?php esc_html_e('Drag files here or click to browse', 'formflow'); ?></span>
                <span class="isf-file-types"><?php echo esc_html(sprintf(__('Allowed: %s (Max: %dMB)', 'formflow'), strtoupper($allowed_types), $max_size)); ?></span>
            </div>

            <div class="isf-file-list"></div>
        </div>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render signature field
     */
    private function render_signature_field(array $field, string $id, string $name, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? true;
        $label = $settings['label'] ?? __('Signature', 'formflow');
        $width = $settings['width'] ?? 400;
        $height = $settings['height'] ?? 150;
        ?>
        <?php if ($label) : ?>
            <label class="isf-label">
                <?php echo esc_html($label); ?>
                <?php if ($required) : ?>
                    <span class="isf-required-indicator" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <div class="isf-signature-wrapper">
            <canvas id="<?php echo esc_attr($id); ?>_canvas"
                    class="isf-signature-canvas"
                    width="<?php echo esc_attr($width); ?>"
                    height="<?php echo esc_attr($height); ?>"
                    <?php echo $disabled ? 'data-disabled="true"' : ''; ?>></canvas>
            <input type="hidden" name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($id); ?>" <?php echo $required ? 'required' : ''; ?>>
            <button type="button" class="isf-btn isf-btn-link isf-signature-clear"><?php esc_html_e('Clear', 'formflow'); ?></button>
        </div>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render smart address field with autocomplete
     */
    private function render_address_field(array $field, string $id, string $name, array $form_data, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? true;
        $label = $settings['label'] ?? __('Service Address', 'formflow');
        $autocomplete = $settings['autocomplete'] ?? true;
        $validate_territory = $settings['validate_territory'] ?? true;
        $include_unit = $settings['include_unit'] ?? true;
        ?>
        <fieldset class="isf-fieldset isf-address-fieldset">
            <?php if ($label) : ?>
                <legend class="isf-legend">
                    <?php echo esc_html($label); ?>
                    <?php if ($required) : ?>
                        <span class="isf-required-indicator" aria-hidden="true">*</span>
                    <?php endif; ?>
                </legend>
            <?php endif; ?>

            <div class="isf-address-fields">
                <div class="isf-field-row">
                    <div class="isf-field-col <?php echo $include_unit ? 'isf-col-8' : 'isf-col-12'; ?>">
                        <label for="<?php echo esc_attr($id); ?>_street" class="isf-label isf-label-sm"><?php esc_html_e('Street Address', 'formflow'); ?></label>
                        <input type="text"
                               id="<?php echo esc_attr($id); ?>_street"
                               name="<?php echo esc_attr($name); ?>[street]"
                               value="<?php echo esc_attr($form_data[$name]['street'] ?? ''); ?>"
                               class="isf-input isf-address-street <?php echo $autocomplete ? 'isf-address-autocomplete' : ''; ?>"
                               placeholder="<?php esc_attr_e('123 Main Street', 'formflow'); ?>"
                               <?php echo $required ? 'required aria-required="true"' : ''; ?>
                               <?php echo $disabled ? 'disabled' : ''; ?>
                               autocomplete="street-address"
                               data-validate-territory="<?php echo $validate_territory ? 'true' : 'false'; ?>">
                    </div>

                    <?php if ($include_unit) : ?>
                    <div class="isf-field-col isf-col-4">
                        <label for="<?php echo esc_attr($id); ?>_unit" class="isf-label isf-label-sm"><?php esc_html_e('Unit/Apt', 'formflow'); ?></label>
                        <input type="text"
                               id="<?php echo esc_attr($id); ?>_unit"
                               name="<?php echo esc_attr($name); ?>[unit]"
                               value="<?php echo esc_attr($form_data[$name]['unit'] ?? ''); ?>"
                               class="isf-input"
                               placeholder="<?php esc_attr_e('Apt 4B', 'formflow'); ?>"
                               <?php echo $disabled ? 'disabled' : ''; ?>
                               autocomplete="address-line2">
                    </div>
                    <?php endif; ?>
                </div>

                <div class="isf-field-row">
                    <div class="isf-field-col isf-col-5">
                        <label for="<?php echo esc_attr($id); ?>_city" class="isf-label isf-label-sm"><?php esc_html_e('City', 'formflow'); ?></label>
                        <input type="text"
                               id="<?php echo esc_attr($id); ?>_city"
                               name="<?php echo esc_attr($name); ?>[city]"
                               value="<?php echo esc_attr($form_data[$name]['city'] ?? ''); ?>"
                               class="isf-input isf-address-city"
                               <?php echo $required ? 'required' : ''; ?>
                               <?php echo $disabled ? 'disabled' : ''; ?>
                               autocomplete="address-level2">
                    </div>
                    <div class="isf-field-col isf-col-3">
                        <label for="<?php echo esc_attr($id); ?>_state" class="isf-label isf-label-sm"><?php esc_html_e('State', 'formflow'); ?></label>
                        <select id="<?php echo esc_attr($id); ?>_state"
                                name="<?php echo esc_attr($name); ?>[state]"
                                class="isf-select isf-address-state"
                                <?php echo $required ? 'required' : ''; ?>
                                <?php echo $disabled ? 'disabled' : ''; ?>
                                autocomplete="address-level1">
                            <option value=""><?php esc_html_e('Select', 'formflow'); ?></option>
                            <?php echo $this->get_us_states_options($form_data[$name]['state'] ?? ''); ?>
                        </select>
                    </div>
                    <div class="isf-field-col isf-col-4">
                        <label for="<?php echo esc_attr($id); ?>_zip" class="isf-label isf-label-sm"><?php esc_html_e('ZIP Code', 'formflow'); ?></label>
                        <input type="text"
                               id="<?php echo esc_attr($id); ?>_zip"
                               name="<?php echo esc_attr($name); ?>[zip]"
                               value="<?php echo esc_attr($form_data[$name]['zip'] ?? ''); ?>"
                               class="isf-input isf-address-zip"
                               pattern="[0-9]{5}(-[0-9]{4})?"
                               <?php echo $required ? 'required' : ''; ?>
                               <?php echo $disabled ? 'disabled' : ''; ?>
                               autocomplete="postal-code">
                    </div>
                </div>

                <!-- Hidden fields for geocoding data -->
                <input type="hidden" name="<?php echo esc_attr($name); ?>[lat]" class="isf-address-lat" value="<?php echo esc_attr($form_data[$name]['lat'] ?? ''); ?>">
                <input type="hidden" name="<?php echo esc_attr($name); ?>[lng]" class="isf-address-lng" value="<?php echo esc_attr($form_data[$name]['lng'] ?? ''); ?>">
                <input type="hidden" name="<?php echo esc_attr($name); ?>[place_id]" class="isf-address-place-id" value="<?php echo esc_attr($form_data[$name]['place_id'] ?? ''); ?>">
            </div>

            <div class="isf-territory-status" style="display: none;">
                <span class="isf-territory-checking"><?php esc_html_e('Checking service area...', 'formflow'); ?></span>
                <span class="isf-territory-valid" style="display: none; color: #28a745;">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php esc_html_e('Address is in service area', 'formflow'); ?>
                </span>
                <span class="isf-territory-invalid" style="display: none; color: #dc3545;">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e('Address is outside service area', 'formflow'); ?>
                </span>
            </div>
        </fieldset>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Get US states as select options
     */
    private function get_us_states_options(string $selected = ''): string {
        $states = [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
            'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
            'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
            'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
            'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
            'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
            'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
            'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
            'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
            'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
            'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
            'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
        ];

        $html = '';
        foreach ($states as $code => $name) {
            $sel = $selected === $code ? ' selected' : '';
            $html .= '<option value="' . esc_attr($code) . '"' . $sel . '>' . esc_html($name) . '</option>';
        }

        return $html;
    }

    /**
     * Render account number field with API validation
     */
    private function render_account_number_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? true;
        $label = $settings['label'] ?? __('Account Number', 'formflow');
        $help_text = $settings['help_text'] ?? __('Find this on your utility bill', 'formflow');
        $validate_api = $settings['validate_api'] ?? true;
        $mask = $settings['mask'] ?? '';
        ?>
        <?php if ($label) : ?>
            <label for="<?php echo esc_attr($id); ?>" class="isf-label">
                <?php echo esc_html($label); ?>
                <?php if ($required) : ?>
                    <span class="isf-required-indicator" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <div class="isf-account-input-wrapper">
            <input type="text"
                   id="<?php echo esc_attr($id); ?>"
                   name="<?php echo esc_attr($name); ?>"
                   value="<?php echo esc_attr($value); ?>"
                   class="isf-input isf-account-input"
                   <?php echo $required ? 'required aria-required="true"' : ''; ?>
                   <?php echo $disabled ? 'disabled' : ''; ?>
                   <?php echo $mask ? 'data-mask="' . esc_attr($mask) . '"' : ''; ?>
                   data-validate-api="<?php echo $validate_api ? 'true' : 'false'; ?>"
                   autocomplete="off">

            <span class="isf-account-status">
                <span class="isf-status-checking" style="display: none;">
                    <span class="isf-spinner"></span>
                </span>
                <span class="isf-status-valid" style="display: none;">
                    <span class="dashicons dashicons-yes-alt" style="color: #28a745;"></span>
                </span>
                <span class="isf-status-invalid" style="display: none;">
                    <span class="dashicons dashicons-warning" style="color: #dc3545;"></span>
                </span>
            </span>
        </div>

        <?php if ($help_text) : ?>
            <p class="isf-help-text"><?php echo esc_html($help_text); ?></p>
        <?php endif; ?>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render program selector for multi-program enrollment
     */
    private function render_program_selector(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? true;
        $label = $settings['label'] ?? __('Select Programs', 'formflow');
        $allow_multiple = $settings['allow_multiple'] ?? true;
        $show_descriptions = $settings['show_descriptions'] ?? true;
        $show_incentives = $settings['show_incentives'] ?? true;

        // Get available programs (would normally come from database)
        $programs = apply_filters('isf_available_programs', [
            [
                'id' => 'smart_thermostat',
                'name' => __('Smart Thermostat Program', 'formflow'),
                'description' => __('Earn rewards by allowing brief AC adjustments during peak demand.', 'formflow'),
                'incentive' => '$75 annual credit',
                'icon' => 'dashicons-superhero',
            ],
            [
                'id' => 'peak_time_rebates',
                'name' => __('Peak Time Rebates', 'formflow'),
                'description' => __('Reduce energy during peak events and earn bill credits.', 'formflow'),
                'incentive' => 'Up to $2/kWh saved',
                'icon' => 'dashicons-clock',
            ],
            [
                'id' => 'ev_charging',
                'name' => __('EV Managed Charging', 'formflow'),
                'description' => __('Optimize your EV charging to save money and support the grid.', 'formflow'),
                'incentive' => '$50 monthly credit',
                'icon' => 'dashicons-car',
            ],
        ]);

        $selected = is_array($value) ? $value : [$value];
        ?>
        <fieldset class="isf-fieldset isf-program-selector">
            <?php if ($label) : ?>
                <legend class="isf-legend">
                    <?php echo esc_html($label); ?>
                    <?php if ($required) : ?>
                        <span class="isf-required-indicator" aria-hidden="true">*</span>
                    <?php endif; ?>
                </legend>
            <?php endif; ?>

            <div class="isf-program-grid">
                <?php foreach ($programs as $program) : ?>
                    <?php $prog_id = $id . '_' . $program['id']; ?>
                    <label class="isf-program-card <?php echo in_array($program['id'], $selected) ? 'selected' : ''; ?>"
                           for="<?php echo esc_attr($prog_id); ?>">
                        <input type="<?php echo $allow_multiple ? 'checkbox' : 'radio'; ?>"
                               id="<?php echo esc_attr($prog_id); ?>"
                               name="<?php echo esc_attr($name); ?><?php echo $allow_multiple ? '[]' : ''; ?>"
                               value="<?php echo esc_attr($program['id']); ?>"
                               class="isf-program-input"
                               <?php checked(in_array($program['id'], $selected)); ?>
                               <?php echo $disabled ? 'disabled' : ''; ?>>

                        <div class="isf-program-card-content">
                            <?php if (!empty($program['icon'])) : ?>
                                <span class="isf-program-icon dashicons <?php echo esc_attr($program['icon']); ?>"></span>
                            <?php endif; ?>

                            <span class="isf-program-name"><?php echo esc_html($program['name']); ?></span>

                            <?php if ($show_descriptions && !empty($program['description'])) : ?>
                                <span class="isf-program-description"><?php echo esc_html($program['description']); ?></span>
                            <?php endif; ?>

                            <?php if ($show_incentives && !empty($program['incentive'])) : ?>
                                <span class="isf-program-incentive">
                                    <span class="dashicons dashicons-awards"></span>
                                    <?php echo esc_html($program['incentive']); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <span class="isf-program-checkmark">
                            <span class="dashicons dashicons-yes"></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render heading element
     */
    private function render_heading(array $field): void {
        $settings = $field['settings'] ?? [];
        $text = $settings['text'] ?? '';
        $level = $settings['level'] ?? 'h3';
        $alignment = $settings['alignment'] ?? 'left';

        if (empty($text)) {
            return;
        }

        $allowed_levels = ['h2', 'h3', 'h4', 'h5', 'h6'];
        $level = in_array($level, $allowed_levels) ? $level : 'h3';

        printf(
            '<%1$s class="isf-heading" style="text-align: %2$s;">%3$s</%1$s>',
            $level,
            esc_attr($alignment),
            esc_html($text)
        );
    }

    /**
     * Render paragraph element
     */
    private function render_paragraph(array $field): void {
        $settings = $field['settings'] ?? [];
        $content = $settings['content'] ?? '';

        if (empty($content)) {
            return;
        }

        echo '<div class="isf-paragraph">' . wp_kses_post($content) . '</div>';
    }

    /**
     * Render divider element
     */
    private function render_divider(array $field): void {
        $settings = $field['settings'] ?? [];
        $style = $settings['style'] ?? 'solid';
        $spacing = $settings['spacing'] ?? 'medium';

        $spacing_map = ['small' => '10px', 'medium' => '20px', 'large' => '40px'];
        $margin = $spacing_map[$spacing] ?? '20px';

        echo '<hr class="isf-divider" style="border-style: ' . esc_attr($style) . '; margin: ' . esc_attr($margin) . ' 0;">';
    }

    /**
     * Render spacer element
     */
    private function render_spacer(array $field): void {
        $settings = $field['settings'] ?? [];
        $height = intval($settings['height'] ?? 20);

        echo '<div class="isf-spacer" style="height: ' . esc_attr($height) . 'px;"></div>';
    }

    /**
     * Render columns container
     */
    private function render_columns(array $field, array $form_data, array $visibility): void {
        $settings = $field['settings'] ?? [];
        $column_count = intval($settings['column_count'] ?? 2);
        $gap = $settings['gap'] ?? 'medium';
        $children = $field['children'] ?? [];

        $gap_map = ['small' => '10px', 'medium' => '20px', 'large' => '30px'];
        $gap_value = $gap_map[$gap] ?? '20px';

        echo '<div class="isf-columns" style="display: grid; grid-template-columns: repeat(' . $column_count . ', 1fr); gap: ' . esc_attr($gap_value) . ';">';

        foreach ($children as $child) {
            echo '<div class="isf-column">';
            echo $this->render_field($child, $form_data, $visibility);
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Render section container
     */
    private function render_section(array $field, array $form_data, array $visibility): void {
        $settings = $field['settings'] ?? [];
        $title = $settings['title'] ?? '';
        $collapsible = $settings['collapsible'] ?? false;
        $collapsed_default = $settings['collapsed_default'] ?? false;
        $children = $field['children'] ?? [];

        $section_classes = ['isf-section'];
        if ($collapsible) {
            $section_classes[] = 'isf-collapsible';
        }
        if ($collapsed_default) {
            $section_classes[] = 'isf-collapsed';
        }
        ?>
        <div class="<?php echo esc_attr(implode(' ', $section_classes)); ?>">
            <?php if ($title) : ?>
                <div class="isf-section-header" <?php echo $collapsible ? 'role="button" tabindex="0"' : ''; ?>>
                    <h4 class="isf-section-title"><?php echo esc_html($title); ?></h4>
                    <?php if ($collapsible) : ?>
                        <span class="isf-section-toggle dashicons dashicons-arrow-down-alt2"></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="isf-section-content">
                <?php foreach ($children as $child) : ?>
                    <?php echo $this->render_field($child, $form_data, $visibility); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Likert scale field
     */
    private function render_likert_scale_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? false;
        $label = $settings['label'] ?? '';
        $scale_type = intval($settings['scale_type'] ?? 5);
        $custom_labels = $settings['labels'] ?? '';
        $show_labels = $settings['show_labels'] ?? true;

        $labels = [];
        if ($custom_labels) {
            $labels = array_map('trim', explode(',', $custom_labels));
        } else {
            for ($i = 1; $i <= $scale_type; $i++) {
                $labels[] = (string)$i;
            }
        }
        ?>
        <?php if ($label) : ?>
            <label class="isf-label">
                <?php echo esc_html($label); ?>
                <?php if ($required) : ?>
                    <span class="isf-required-indicator" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <div class="isf-likert-scale" data-scale="<?php echo esc_attr($scale_type); ?>">
            <?php for ($i = 1; $i <= $scale_type; $i++) : ?>
                <label class="isf-likert-option" for="<?php echo esc_attr($id . '_' . $i); ?>">
                    <input type="radio"
                           id="<?php echo esc_attr($id . '_' . $i); ?>"
                           name="<?php echo esc_attr($name); ?>"
                           value="<?php echo esc_attr($i); ?>"
                           class="isf-likert-radio"
                           <?php checked($value, $i); ?>
                           <?php echo $required && $i === 1 ? 'required aria-required="true"' : ''; ?>
                           <?php echo $disabled ? 'disabled' : ''; ?>>
                    <span class="isf-likert-box">
                        <span class="isf-likert-value"><?php echo esc_html($i); ?></span>
                    </span>
                    <?php if ($show_labels && isset($labels[$i - 1])) : ?>
                        <span class="isf-likert-label"><?php echo esc_html($labels[$i - 1]); ?></span>
                    <?php endif; ?>
                </label>
            <?php endfor; ?>
        </div>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render slider field
     */
    private function render_slider_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? false;
        $label = $settings['label'] ?? '';
        $min = $settings['min'] ?? 0;
        $max = $settings['max'] ?? 100;
        $step = $settings['step'] ?? 1;
        $default_value = $settings['default_value'] ?? 50;
        $show_value = $settings['show_value'] ?? true;
        $prefix = $settings['prefix'] ?? '';
        $suffix = $settings['suffix'] ?? '';

        $current_value = $value ?: $default_value;
        ?>
        <?php if ($label) : ?>
            <label for="<?php echo esc_attr($id); ?>" class="isf-label">
                <?php echo esc_html($label); ?>
                <?php if ($required) : ?>
                    <span class="isf-required-indicator" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <div class="isf-slider-wrapper">
            <input type="range"
                   id="<?php echo esc_attr($id); ?>"
                   name="<?php echo esc_attr($name); ?>"
                   value="<?php echo esc_attr($current_value); ?>"
                   min="<?php echo esc_attr($min); ?>"
                   max="<?php echo esc_attr($max); ?>"
                   step="<?php echo esc_attr($step); ?>"
                   class="isf-slider"
                   aria-label="<?php echo esc_attr($label); ?>"
                   <?php echo $required ? 'required aria-required="true"' : ''; ?>
                   <?php echo $disabled ? 'disabled' : ''; ?>
                   data-prefix="<?php echo esc_attr($prefix); ?>"
                   data-suffix="<?php echo esc_attr($suffix); ?>">

            <?php if ($show_value) : ?>
                <output class="isf-slider-value" for="<?php echo esc_attr($id); ?>">
                    <?php echo esc_html($prefix . $current_value . $suffix); ?>
                </output>
            <?php endif; ?>
        </div>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render reCAPTCHA v3 field
     */
    private function render_recaptcha_v3_field(array $field, string $id, string $name, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $site_key = $settings['site_key'] ?? '';
        $action = $settings['action'] ?? 'submit';

        if (empty($site_key)) {
            ?>
            <div class="isf-recaptcha-notice notice notice-warning">
                <p><?php esc_html_e('reCAPTCHA v3: Please configure Site Key in field settings.', 'formflow'); ?></p>
            </div>
            <?php
            return;
        }
        ?>
        <input type="hidden" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" class="isf-recaptcha-token">
        <div class="isf-recaptcha-badge"
             data-sitekey="<?php echo esc_attr($site_key); ?>"
             data-action="<?php echo esc_attr($action); ?>">
        </div>
        <?php
    }

    /**
     * Render repeater field
     */
    private function render_repeater_field(array $field, string $id, string $name, array $form_data, array $visibility, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $label = $settings['label'] ?? '';
        $min_items = intval($settings['min_items'] ?? 1);
        $max_items = intval($settings['max_items'] ?? 10);
        $add_button_text = $settings['add_button_text'] ?? __('Add Item', 'formflow');
        $remove_button_text = $settings['remove_button_text'] ?? __('Remove', 'formflow');
        $children = $field['children'] ?? [];

        $items = $form_data[$name] ?? [];
        if (empty($items) && $min_items > 0) {
            $items = array_fill(0, $min_items, []);
        }
        ?>
        <?php if ($label) : ?>
            <label class="isf-label"><?php echo esc_html($label); ?></label>
        <?php endif; ?>

        <div class="isf-repeater"
             data-name="<?php echo esc_attr($name); ?>"
             data-min="<?php echo esc_attr($min_items); ?>"
             data-max="<?php echo esc_attr($max_items); ?>"
             role="group"
             aria-label="<?php echo esc_attr($label ?: __('Repeater Field', 'formflow')); ?>">

            <div class="isf-repeater-items">
                <?php foreach ($items as $index => $item) : ?>
                    <div class="isf-repeater-item" data-index="<?php echo esc_attr($index); ?>">
                        <div class="isf-repeater-item-fields">
                            <?php foreach ($children as $child) : ?>
                                <?php
                                $child_name = $name . '[' . $index . '][' . ($child['name'] ?? '') . ']';
                                $child_value = $item[$child['name'] ?? ''] ?? '';
                                echo $this->render_field(array_merge($child, ['name' => $child_name]), ['value' => $child_value], $visibility);
                                ?>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="isf-btn isf-btn-link isf-repeater-remove" <?php echo $disabled ? 'disabled' : ''; ?>>
                            <?php echo esc_html($remove_button_text); ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="isf-btn isf-btn-secondary isf-repeater-add" <?php echo $disabled ? 'disabled' : ''; ?>>
                <?php echo esc_html($add_button_text); ?>
            </button>
        </div>

        <template class="isf-repeater-template">
            <div class="isf-repeater-item" data-index="__INDEX__">
                <div class="isf-repeater-item-fields">
                    <?php foreach ($children as $child) : ?>
                        <?php
                        $child_name = $name . '[__INDEX__][' . ($child['name'] ?? '') . ']';
                        echo $this->render_field(array_merge($child, ['name' => $child_name]), [], $visibility);
                        ?>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="isf-btn isf-btn-link isf-repeater-remove">
                    <?php echo esc_html($remove_button_text); ?>
                </button>
            </div>
        </template>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render star rating field
     */
    private function render_star_rating_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? false;
        $label = $settings['label'] ?? '';
        $max_stars = intval($settings['max_stars'] ?? 5);
        $star_size = $settings['star_size'] ?? 'medium';
        $show_labels = $settings['show_labels'] ?? false;
        ?>
        <?php if ($label) : ?>
            <label class="isf-label">
                <?php echo esc_html($label); ?>
                <?php if ($required) : ?>
                    <span class="isf-required-indicator" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <div class="isf-star-rating isf-star-size-<?php echo esc_attr($star_size); ?>"
             data-max="<?php echo esc_attr($max_stars); ?>"
             role="radiogroup"
             aria-label="<?php echo esc_attr($label ?: __('Star Rating', 'formflow')); ?>">
            <?php for ($i = 1; $i <= $max_stars; $i++) : ?>
                <label class="isf-star-label" for="<?php echo esc_attr($id . '_' . $i); ?>">
                    <input type="radio"
                           id="<?php echo esc_attr($id . '_' . $i); ?>"
                           name="<?php echo esc_attr($name); ?>"
                           value="<?php echo esc_attr($i); ?>"
                           class="isf-star-input"
                           <?php checked($value, $i); ?>
                           <?php echo $required && $i === 1 ? 'required aria-required="true"' : ''; ?>
                           <?php echo $disabled ? 'disabled' : ''; ?>>
                    <span class="isf-star" data-value="<?php echo esc_attr($i); ?>">
                        <span class="dashicons dashicons-star-filled"></span>
                    </span>
                    <?php if ($show_labels) : ?>
                        <span class="isf-sr-only"><?php echo esc_html($i . ' ' . _n('star', 'stars', $i, 'formflow')); ?></span>
                    <?php endif; ?>
                </label>
            <?php endfor; ?>
        </div>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render date range picker
     */
    private function render_date_range_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? false;
        $label = $settings['label'] ?? '';
        $min_date = $settings['min_date'] ?? '';
        $max_date = $settings['max_date'] ?? '';
        $preset_ranges = $settings['preset_ranges'] ?? true;

        if ($min_date === 'today') {
            $min_date = date('Y-m-d');
        }
        if ($max_date === 'today') {
            $max_date = date('Y-m-d');
        }

        $start_value = is_array($value) ? ($value['start'] ?? '') : '';
        $end_value = is_array($value) ? ($value['end'] ?? '') : '';
        ?>
        <?php if ($label) : ?>
            <label class="isf-label">
                <?php echo esc_html($label); ?>
                <?php if ($required) : ?>
                    <span class="isf-required-indicator" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <div class="isf-date-range-wrapper" role="group" aria-label="<?php echo esc_attr($label); ?>">
            <div class="isf-date-range-inputs">
                <input type="date"
                       id="<?php echo esc_attr($id . '_start'); ?>"
                       name="<?php echo esc_attr($name); ?>[start]"
                       value="<?php echo esc_attr($start_value); ?>"
                       class="isf-input isf-date-range-start"
                       placeholder="<?php esc_attr_e('Start Date', 'formflow'); ?>"
                       aria-label="<?php esc_attr_e('Start Date', 'formflow'); ?>"
                       <?php echo $required ? 'required aria-required="true"' : ''; ?>
                       <?php echo $disabled ? 'disabled' : ''; ?>
                       <?php echo $min_date ? 'min="' . esc_attr($min_date) . '"' : ''; ?>
                       <?php echo $max_date ? 'max="' . esc_attr($max_date) . '"' : ''; ?>>

                <span class="isf-date-range-separator" aria-hidden="true"><?php esc_html_e('to', 'formflow'); ?></span>

                <input type="date"
                       id="<?php echo esc_attr($id . '_end'); ?>"
                       name="<?php echo esc_attr($name); ?>[end]"
                       value="<?php echo esc_attr($end_value); ?>"
                       class="isf-input isf-date-range-end"
                       placeholder="<?php esc_attr_e('End Date', 'formflow'); ?>"
                       aria-label="<?php esc_attr_e('End Date', 'formflow'); ?>"
                       <?php echo $required ? 'required aria-required="true"' : ''; ?>
                       <?php echo $disabled ? 'disabled' : ''; ?>
                       <?php echo $min_date ? 'min="' . esc_attr($min_date) . '"' : ''; ?>
                       <?php echo $max_date ? 'max="' . esc_attr($max_date) . '"' : ''; ?>>
            </div>

            <?php if ($preset_ranges) : ?>
                <div class="isf-date-range-presets">
                    <button type="button" class="isf-btn isf-btn-link isf-date-preset" data-preset="today"><?php esc_html_e('Today', 'formflow'); ?></button>
                    <button type="button" class="isf-btn isf-btn-link isf-date-preset" data-preset="yesterday"><?php esc_html_e('Yesterday', 'formflow'); ?></button>
                    <button type="button" class="isf-btn isf-btn-link isf-date-preset" data-preset="last7"><?php esc_html_e('Last 7 Days', 'formflow'); ?></button>
                    <button type="button" class="isf-btn isf-btn-link isf-date-preset" data-preset="last30"><?php esc_html_e('Last 30 Days', 'formflow'); ?></button>
                    <button type="button" class="isf-btn isf-btn-link isf-date-preset" data-preset="thismonth"><?php esc_html_e('This Month', 'formflow'); ?></button>
                </div>
            <?php endif; ?>
        </div>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render address autocomplete field (Google Places ready)
     */
    private function render_address_autocomplete_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? true;
        $label = $settings['label'] ?? '';
        $placeholder = $settings['placeholder'] ?? __('Start typing an address...', 'formflow');
        $help_text = $settings['help_text'] ?? '';
        $api_key = $settings['api_key'] ?? '';
        $countries = $settings['countries'] ?? 'us';
        ?>
        <?php if ($label) : ?>
            <label for="<?php echo esc_attr($id); ?>" class="isf-label">
                <?php echo esc_html($label); ?>
                <?php if ($required) : ?>
                    <span class="isf-required-indicator" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <input type="text"
               id="<?php echo esc_attr($id); ?>"
               name="<?php echo esc_attr($name); ?>"
               value="<?php echo esc_attr($value); ?>"
               class="isf-input isf-address-autocomplete"
               placeholder="<?php echo esc_attr($placeholder); ?>"
               <?php echo $required ? 'required aria-required="true"' : ''; ?>
               <?php echo $disabled ? 'disabled' : ''; ?>
               <?php echo $help_text ? 'aria-describedby="' . esc_attr($id) . '_help"' : ''; ?>
               data-api-key="<?php echo esc_attr($api_key); ?>"
               data-countries="<?php echo esc_attr($countries); ?>"
               autocomplete="off">

        <?php if ($help_text) : ?>
            <p id="<?php echo esc_attr($id); ?>_help" class="isf-help-text"><?php echo esc_html($help_text); ?></p>
        <?php endif; ?>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render number stepper field
     */
    private function render_number_stepper_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? false;
        $label = $settings['label'] ?? '';
        $min = $settings['min'] ?? 0;
        $max = $settings['max'] ?? 100;
        $step = $settings['step'] ?? 1;
        $default_value = $settings['default_value'] ?? 1;
        $size = $settings['size'] ?? 'medium';

        $current_value = $value ?: $default_value;
        ?>
        <?php if ($label) : ?>
            <label for="<?php echo esc_attr($id); ?>" class="isf-label">
                <?php echo esc_html($label); ?>
                <?php if ($required) : ?>
                    <span class="isf-required-indicator" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <div class="isf-number-stepper isf-stepper-size-<?php echo esc_attr($size); ?>" role="group" aria-label="<?php echo esc_attr($label); ?>">
            <button type="button"
                    class="isf-stepper-btn isf-stepper-decrease"
                    <?php echo $disabled ? 'disabled' : ''; ?>
                    aria-label="<?php esc_attr_e('Decrease', 'formflow'); ?>">
                <span class="dashicons dashicons-minus"></span>
            </button>

            <input type="number"
                   id="<?php echo esc_attr($id); ?>"
                   name="<?php echo esc_attr($name); ?>"
                   value="<?php echo esc_attr($current_value); ?>"
                   min="<?php echo esc_attr($min); ?>"
                   max="<?php echo esc_attr($max); ?>"
                   step="<?php echo esc_attr($step); ?>"
                   class="isf-stepper-input"
                   <?php echo $required ? 'required aria-required="true"' : ''; ?>
                   <?php echo $disabled ? 'disabled' : ''; ?>>

            <button type="button"
                    class="isf-stepper-btn isf-stepper-increase"
                    <?php echo $disabled ? 'disabled' : ''; ?>
                    aria-label="<?php esc_attr_e('Increase', 'formflow'); ?>">
                <span class="dashicons dashicons-plus"></span>
            </button>
        </div>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }

    /**
     * Render color picker field
     */
    private function render_color_picker_field(array $field, string $id, string $name, $value, bool $disabled): void {
        $settings = $field['settings'] ?? [];
        $required = $settings['required'] ?? false;
        $label = $settings['label'] ?? '';
        $default_color = $settings['default_color'] ?? '#000000';
        $preset_colors = $settings['preset_colors'] ?? '';
        $show_alpha = $settings['show_alpha'] ?? false;

        $current_value = $value ?: $default_color;
        $presets = $preset_colors ? array_map('trim', explode(',', $preset_colors)) : [];
        ?>
        <?php if ($label) : ?>
            <label for="<?php echo esc_attr($id); ?>" class="isf-label">
                <?php echo esc_html($label); ?>
                <?php if ($required) : ?>
                    <span class="isf-required-indicator" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <div class="isf-color-picker-wrapper">
            <div class="isf-color-input-group">
                <input type="color"
                       id="<?php echo esc_attr($id); ?>"
                       name="<?php echo esc_attr($name); ?>"
                       value="<?php echo esc_attr($current_value); ?>"
                       class="isf-color-input"
                       aria-label="<?php echo esc_attr($label ?: __('Select Color', 'formflow')); ?>"
                       <?php echo $required ? 'required aria-required="true"' : ''; ?>
                       <?php echo $disabled ? 'disabled' : ''; ?>>

                <input type="text"
                       id="<?php echo esc_attr($id . '_text'); ?>"
                       value="<?php echo esc_attr($current_value); ?>"
                       class="isf-input isf-color-text"
                       pattern="^#[0-9A-Fa-f]{6}$"
                       placeholder="#000000"
                       aria-label="<?php esc_attr_e('Hex Color Value', 'formflow'); ?>"
                       <?php echo $disabled ? 'disabled' : ''; ?>>
            </div>

            <?php if (!empty($presets)) : ?>
                <div class="isf-color-presets">
                    <span class="isf-color-presets-label"><?php esc_html_e('Presets:', 'formflow'); ?></span>
                    <?php foreach ($presets as $preset) : ?>
                        <button type="button"
                                class="isf-color-preset-btn"
                                style="background-color: <?php echo esc_attr($preset); ?>;"
                                data-color="<?php echo esc_attr($preset); ?>"
                                title="<?php echo esc_attr($preset); ?>"
                                aria-label="<?php echo esc_attr(sprintf(__('Select color %s', 'formflow'), $preset)); ?>"
                                <?php echo $disabled ? 'disabled' : ''; ?>>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="isf-field-error" role="alert"></div>
        <?php
    }
}
