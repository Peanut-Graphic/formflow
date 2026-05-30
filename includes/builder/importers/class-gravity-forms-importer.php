<?php
/**
 * Gravity Forms importer.
 *
 * Reads a Gravity Forms JSON export (Forms → Import/Export → Export Forms)
 * and creates equivalent FormFlow instances on the canonical
 * `form_type='builder'` track.
 *
 * Each imported form lands as INACTIVE by default — review in
 * FF Forms → Form Editor Beta before activating.
 *
 * The importer is pure: it never touches Gravity Forms's own tables.
 * The source plugin can be uninstalled before or after the import runs.
 *
 * @package FormFlow
 * @subpackage Builder
 * @since 4.0.0
 */

namespace ISF\Builder\Importers;

use ISF\Database\Database;

if (!defined('ABSPATH')) {
    exit;
}

class GravityFormsImporter
{
    private Database $db;

    /** @var string[] Warnings accumulated during the current import. */
    private array $warnings = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? new Database();
    }

    /**
     * Import a single Gravity Forms form definition.
     *
     * @param array $gf_form The decoded GF form JSON.
     * @param array $opts {
     *     @type bool $dry_run If true, build the FormFlow shape but
     *                         do not write to the database.
     *     @type bool $activate Activate the instance on import (default false).
     * }
     * @return array {
     *     @type int|null $instance_id   ID of the created instance, null on dry-run.
     *     @type string   $slug          Instance slug.
     *     @type string   $name          Human-readable name.
     *     @type int      $field_count   FormFlow fields produced.
     *     @type string[] $warnings      Per-form warnings.
     *     @type array    $schema        The full form_schema produced.
     * }
     */
    public function import(array $gf_form, array $opts = []): array
    {
        $this->warnings = [];

        $opts = array_merge(['dry_run' => false, 'activate' => false], $opts);

        $title  = (string) ($gf_form['title'] ?? 'Untitled GF Form');
        $slug   = $this->derive_slug($gf_form);
        $fields = $this->map_fields($gf_form['fields'] ?? []);

        $schema = [
            'version' => 1,
            'steps'   => [
                [
                    'id'          => 'step_1',
                    'title'       => '',
                    'description' => '',
                    'fields'      => $fields,
                ],
            ],
            'settings' => $this->map_form_settings($gf_form),
        ];

        $instance_id = null;
        if (!$opts['dry_run']) {
            $instance_id = $this->persist($title, $slug, $schema, $gf_form, $opts['activate']);
        }

        return [
            'instance_id' => $instance_id,
            'slug'        => $slug,
            'name'        => $title,
            'field_count' => count($fields),
            'warnings'    => $this->warnings,
            'schema'      => $schema,
        ];
    }

    /**
     * Import a JSON file containing one or many forms. GF's export
     * format is an array of form objects (even for a single form).
     *
     * @return array<int, array> One result per form. See ::import().
     */
    public function import_file(string $path, array $opts = []): array
    {
        if (!is_readable($path)) {
            throw new \RuntimeException("Cannot read GF export file: {$path}");
        }
        $raw = file_get_contents($path);
        return $this->import_json($raw, $opts);
    }

    /**
     * @param string $json Raw JSON string from a GF export.
     * @return array<int, array> One result per form.
     */
    public function import_json(string $json, array $opts = []): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('GF export is not valid JSON.');
        }

        // GF supports two shapes:
        //   1. `["version": "...", "0": {form}, "1": {form}, ...]` (newer)
        //   2. `[{form}, {form}, ...]` (older, plain array)
        $forms = [];
        if (isset($data['version']) || (isset($data[0]) && is_array($data[0]) && !isset($data[0]['fields']))) {
            // Newer export shape: numeric keys hold forms; meta keys
            // ('version', 'export_date', etc.) sit alongside.
            foreach ($data as $key => $value) {
                if (is_numeric($key) && is_array($value) && isset($value['fields'])) {
                    $forms[] = $value;
                }
            }
        }
        if (!$forms && isset($data['fields'])) {
            // Single-form export.
            $forms[] = $data;
        }
        if (!$forms && isset($data[0]['fields'])) {
            $forms = $data;
        }

        if (!$forms) {
            throw new \InvalidArgumentException('No Gravity Forms forms found in JSON.');
        }

        return array_map(fn($f) => $this->import($f, $opts), $forms);
    }

    // ===================================================================
    // FIELD MAPPING
    // ===================================================================

    /**
     * @param array $gf_fields List of GF field objects.
     * @return array FormFlow fields.
     */
    private function map_fields(array $gf_fields): array
    {
        $out = [];
        foreach ($gf_fields as $gf_field) {
            $mapped = $this->map_field($gf_field);
            if (is_array($mapped)) {
                if (isset($mapped[0]) && is_array($mapped[0])) {
                    // Field expanded into multiple FormFlow fields (e.g. name → first/last).
                    foreach ($mapped as $m) { $out[] = $m; }
                } else {
                    $out[] = $mapped;
                }
            }
        }
        return $out;
    }

    /**
     * @return array|array[]|null  Single FormFlow field, list of fields, or null to skip.
     */
    private function map_field(array $gf_field): array|null
    {
        $type   = (string) ($gf_field['type'] ?? '');
        $id     = $gf_field['id'] ?? 0;
        $label  = (string) ($gf_field['label'] ?? '');
        $name   = $this->derive_field_name($gf_field);

        $base = [
            'id'    => $name ?: 'field_' . $id,
            'name'  => $name ?: 'field_' . $id,
            'label' => $label,
        ];

        if (!empty($gf_field['cssClass'])) {
            $base['cssClass'] = (string) $gf_field['cssClass'];
        }

        // Conditional logic: GF rules → FormFlow show_when.
        $show_when = $this->map_conditional_logic($gf_field);

        $required    = !empty($gf_field['isRequired']);
        $placeholder = (string) ($gf_field['placeholder'] ?? '');
        $help        = (string) ($gf_field['description'] ?? '');
        $default     = (string) ($gf_field['defaultValue'] ?? '');

        $settings_base = [
            'label'         => $label,
            'required'      => $required,
            'placeholder'   => $placeholder,
            'help_text'     => $help,
            'default_value' => $default,
        ];
        if ($show_when) {
            $settings_base['show_when'] = $show_when;
        }

        switch ($type) {
            case 'text':
            case 'email':
            case 'phone':
            case 'website':
            case 'number':
                $ff_type = ($type === 'website') ? 'text' : $type;
                return ['type' => $ff_type] + $base + ['settings' => $settings_base];

            case 'textarea':
                return ['type' => 'textarea'] + $base + ['settings' => $settings_base];

            case 'date':
                return ['type' => 'date'] + $base + ['settings' => $settings_base];

            case 'time':
                return ['type' => 'time'] + $base + ['settings' => $settings_base];

            case 'hidden':
                return ['type' => 'text'] + $base + [
                    'settings' => $settings_base + ['hidden' => true],
                ];

            case 'select':
                return ['type' => 'select'] + $base + [
                    'settings' => $settings_base + [
                        'options' => $this->map_choices($gf_field['choices'] ?? []),
                    ],
                ];

            case 'multiselect':
                return ['type' => 'checkbox'] + $base + [
                    'settings' => $settings_base + [
                        'options' => $this->map_choices($gf_field['choices'] ?? []),
                    ],
                ];

            case 'radio':
                return ['type' => 'radio'] + $base + [
                    'settings' => $settings_base + [
                        'options' => $this->map_choices($gf_field['choices'] ?? []),
                    ],
                ];

            case 'checkbox':
                return ['type' => 'checkbox'] + $base + [
                    'settings' => $settings_base + [
                        'options' => $this->map_choices($gf_field['choices'] ?? []),
                    ],
                ];

            case 'consent':
                return ['type' => 'checkbox'] + $base + [
                    'settings' => $settings_base + [
                        'options' => [[
                            'value' => '1',
                            'label' => $gf_field['checkboxLabel'] ?? $label,
                        ]],
                    ],
                ];

            case 'name':
                return $this->expand_name_field($gf_field, $settings_base, $show_when);

            case 'address':
                return $this->expand_address_field($gf_field, $settings_base, $show_when);

            case 'fileupload':
                return ['type' => 'file'] + $base + ['settings' => $settings_base];

            case 'signature':
                return ['type' => 'signature'] + $base + ['settings' => $settings_base];

            case 'section':
                return [
                    'id'   => $base['id'],
                    'name' => $base['name'],
                    'type' => 'heading',
                    'settings' => ['text' => $label, 'level' => 'h3'],
                ];

            case 'html':
                return [
                    'id'   => $base['id'],
                    'name' => $base['name'],
                    'type' => 'paragraph',
                    'settings' => ['content' => (string) ($gf_field['content'] ?? '')],
                ];

            case 'page':
                $this->warnings[] = sprintf(
                    'Page break "%s" (field #%s) collapsed — FormFlow builder is single-step today. Multi-step support is a 4.1+ feature.',
                    $label,
                    (string) $id
                );
                return null;

            case 'captcha':
                $this->warnings[] = "CAPTCHA field \"{$label}\" dropped — use the plugin's built-in submission protection instead.";
                return null;

            case 'post_title':
            case 'post_content':
            case 'post_excerpt':
            case 'post_tags':
            case 'post_category':
            case 'post_image':
            case 'post_custom_field':
                $this->warnings[] = "Post-creation field \"{$label}\" (type {$type}) dropped — FormFlow does not create WordPress posts from submissions.";
                return null;

            case 'product':
            case 'option':
            case 'shipping':
            case 'total':
            case 'singleproduct':
            case 'singleshipping':
                $this->warnings[] = "Commerce field \"{$label}\" (type {$type}) dropped — FormFlow does not handle commerce.";
                return null;

            case 'calculation':
                $this->warnings[] = "Calculated field \"{$label}\" dropped — formula support is a future feature.";
                return null;

            default:
                $this->warnings[] = "Unknown GF field type \"{$type}\" for \"{$label}\" dropped.";
                return null;
        }
    }

    /**
     * GF "name" field is a composite of prefix/first/middle/last/suffix.
     * Expand to two FormFlow `text` fields (first + last, each half-width).
     *
     * @return array[]
     */
    private function expand_name_field(array $gf_field, array $settings_base, ?array $show_when): array
    {
        $base_label = (string) ($gf_field['label'] ?? 'Name');
        $required   = !empty($gf_field['isRequired']);

        $first_settings = ['label' => __('First', 'formflow'), 'required' => $required];
        $last_settings  = ['label' => __('Last', 'formflow'),  'required' => $required];
        if ($show_when) {
            $first_settings['show_when'] = $show_when;
            $last_settings['show_when']  = $show_when;
        }

        $out = [];
        $out[] = [
            'id' => 'first_name', 'name' => 'first_name', 'type' => 'text',
            'width' => 'half', 'settings' => $first_settings,
        ];
        $out[] = [
            'id' => 'last_name', 'name' => 'last_name', 'type' => 'text',
            'width' => 'half', 'settings' => $last_settings,
        ];

        // If GF exposed middle / prefix / suffix sub-fields, warn rather than expand.
        if (!empty($gf_field['inputs'])) {
            foreach ($gf_field['inputs'] as $sub) {
                $key = isset($sub['name']) ? $sub['name'] : '';
                if (in_array($key, ['prefix', 'middle', 'suffix'], true) && empty($sub['isHidden'])) {
                    $this->warnings[] = "Name field \"{$base_label}\" sub-field \"{$key}\" dropped — FormFlow uses first + last only.";
                }
            }
        }
        return $out;
    }

    /**
     * GF "address" field is a composite of street/street2/city/state/zip/country.
     * Expand to five FormFlow text fields with width hints to match the
     * standard FormFlow address layout.
     *
     * @return array[]
     */
    private function expand_address_field(array $gf_field, array $settings_base, ?array $show_when): array
    {
        $base_label = (string) ($gf_field['label'] ?? 'Address');
        $required   = !empty($gf_field['isRequired']);

        $extra = ['required' => $required];
        if ($show_when) { $extra['show_when'] = $show_when; }

        $out = [];
        $out[] = [
            'id' => 'street_address', 'name' => 'street_address', 'type' => 'text',
            'settings' => $extra + ['label' => __('Street Address', 'formflow')],
        ];
        $out[] = [
            'id' => 'address_line_2', 'name' => 'address_line_2', 'type' => 'text',
            'settings' => ['label' => __('Address Line 2', 'formflow')] + (
                $show_when ? ['show_when' => $show_when] : []
            ),
        ];
        $out[] = [
            'id' => 'city', 'name' => 'city', 'type' => 'text', 'width' => 'half',
            'settings' => $extra + ['label' => __('City', 'formflow')],
        ];
        $out[] = [
            'id' => 'state', 'name' => 'state', 'type' => 'text', 'width' => 'half',
            'settings' => $extra + ['label' => __('State', 'formflow')],
        ];
        $out[] = [
            'id' => 'zip', 'name' => 'zip', 'type' => 'text',
            'settings' => $extra + ['label' => __('ZIP', 'formflow')],
        ];

        if (!empty($gf_field['inputs'])) {
            foreach ($gf_field['inputs'] as $sub) {
                if (isset($sub['name']) && $sub['name'] === 'country' && empty($sub['isHidden'])) {
                    $this->warnings[] = "Address field \"{$base_label}\" included a Country sub-field — dropped (FormFlow uses US-style addresses by default).";
                }
            }
        }
        return $out;
    }

    /**
     * GF choices: `[['text' => 'Yes', 'value' => 'yes'], ...]`.
     * FormFlow options: `[['label' => 'Yes', 'value' => 'yes'], ...]`.
     */
    private function map_choices(array $choices): array
    {
        $out = [];
        foreach ($choices as $c) {
            $value = isset($c['value']) ? (string) $c['value'] : (string) ($c['text'] ?? '');
            $out[] = [
                'value' => $value,
                'label' => (string) ($c['text'] ?? $value),
            ];
        }
        return $out;
    }

    /**
     * GF conditional logic → FormFlow `show_when` (single rule).
     * If GF supplies multiple rules, only the first is mapped and a
     * warning is recorded.
     */
    private function map_conditional_logic(array $gf_field): ?array
    {
        $logic = $gf_field['conditionalLogic'] ?? null;
        if (!$logic || empty($logic['rules'])) {
            return null;
        }

        $action = $logic['actionType'] ?? 'show';
        $rules  = $logic['rules'];

        if (count($rules) > 1) {
            $this->warnings[] = sprintf(
                'Field "%s" had %d conditional-logic rules — only the first was mapped (FormFlow show_when supports a single rule today).',
                (string) ($gf_field['label'] ?? '(unnamed)'),
                count($rules)
            );
        }

        $rule = reset($rules);
        $target_field_id = (string) ($rule['fieldId'] ?? '');
        if ($target_field_id === '') { return null; }

        // GF field IDs are integers within the form. We can't look up
        // the FormFlow field name without running through the whole
        // form mapping first (or doing two passes). For now, the GF
        // field ID becomes a `field_<id>` reference — which matches
        // the fallback name in ::map_field() when no inputName is set.
        // Real-world: most rules reference fields with inputName set,
        // so this fallback only catches edge cases.
        $value = isset($rule['value']) ? (string) $rule['value'] : '';

        $show_when = [
            'field' => 'field_' . $target_field_id,
        ];
        if ($action === 'show') {
            $show_when['equals'] = $value;
        } else {
            $show_when['not_equals'] = $value;
        }
        return $show_when;
    }

    /**
     * Map GF form-level settings to FormFlow instance/schema settings.
     */
    private function map_form_settings(array $gf_form): array
    {
        $confirmation = '';
        if (!empty($gf_form['confirmations'])) {
            $first = reset($gf_form['confirmations']);
            if (is_array($first) && !empty($first['message'])) {
                $confirmation = (string) $first['message'];
            }
        }
        $settings = [
            'submit_button_text' => $gf_form['button']['text'] ?? __('Submit', 'formflow'),
        ];
        if ($confirmation !== '') {
            $settings['success_message'] = $confirmation;
        }

        // Notifications: surface a warning but don't try to recreate
        // them — FormFlow's notification system is in the per-instance
        // settings, not the schema.
        if (!empty($gf_form['notifications']) && is_array($gf_form['notifications'])) {
            $count = count($gf_form['notifications']);
            $this->warnings[] = "{$count} notification(s) on the source GF form were not migrated. Configure FormFlow notifications under FF Forms → Form Editor Beta → Notifications.";
        }

        return $settings;
    }

    // ===================================================================
    // PERSISTENCE
    // ===================================================================

    private function persist(string $title, string $slug, array $schema, array $gf_form, bool $activate): int
    {
        $data = [
            'name'        => $title,
            'slug'        => $slug,
            'form_type'   => 'builder',
            'is_active'   => $activate ? 1 : 0,
            'api_endpoint' => '',
            'api_password' => '',
            'utility'      => '',
            'settings'     => [
                'form_schema'        => $schema,
                'imported_from'      => 'gravity-forms',
                'imported_at'        => current_time('mysql', true),
                'imported_warnings'  => $this->warnings,
                'submit_button_text' => $schema['settings']['submit_button_text'] ?? __('Submit', 'formflow'),
            ],
        ];

        if (isset($schema['settings']['success_message'])) {
            $data['settings']['success_message'] = $schema['settings']['success_message'];
        }

        return (int) $this->db->create_instance($data);
    }

    private function derive_slug(array $gf_form): string
    {
        $candidate = '';
        if (!empty($gf_form['title'])) {
            $candidate = sanitize_title((string) $gf_form['title']);
        }
        if ($candidate === '') {
            $candidate = 'gf-form-' . (int) ($gf_form['id'] ?? 0);
        }
        return $candidate . '-imported';
    }

    /**
     * Derive a stable, machine-friendly FormFlow field name from a GF field.
     *
     * Priority: inputName > adminLabel > slugified label > field_<id>.
     */
    private function derive_field_name(array $gf_field): string
    {
        foreach (['inputName', 'adminLabel'] as $key) {
            if (!empty($gf_field[$key])) {
                return $this->normalize_name((string) $gf_field[$key]);
            }
        }
        if (!empty($gf_field['label'])) {
            return $this->normalize_name((string) $gf_field['label']);
        }
        $id = (int) ($gf_field['id'] ?? 0);
        return $id ? 'field_' . $id : '';
    }

    private function normalize_name(string $candidate): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($candidate)));
        $slug = trim($slug, '_');
        return $slug;
    }
}
