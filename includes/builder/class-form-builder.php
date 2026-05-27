<?php
/**
 * Visual Form Builder
 *
 * Provides drag-and-drop form building capabilities with conditional logic.
 *
 * @package FormFlow
 * @since 2.6.0
 */

namespace ISF\Builder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class FormBuilder
 *
 * Core form builder functionality.
 */
class FormBuilder {

    /**
     * Singleton instance
     */
    private static ?FormBuilder $instance = null;

    /**
     * Available field types
     */
    private array $field_types = [];

    /**
     * Get singleton instance
     */
    public static function instance(): FormBuilder {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        $this->register_default_field_types();
    }

    /**
     * Initialize builder hooks
     */
    public function init(): void {
        // Register REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_builder_assets']);

        // AJAX handlers
        add_action('wp_ajax_isf_save_form_schema', [$this, 'ajax_save_form_schema']);
        add_action('wp_ajax_formflow_save_form_schema', [$this, 'ajax_save_form_schema']);
        add_action('wp_ajax_isf_load_form_schema', [$this, 'ajax_load_form_schema']);
        add_action('wp_ajax_formflow_load_form_schema', [$this, 'ajax_load_form_schema']);
        add_action('wp_ajax_isf_preview_form', [$this, 'ajax_preview_form']);
        add_action('wp_ajax_formflow_preview_form', [$this, 'ajax_preview_form']);
    }

    /**
     * Register default field types
     */
    private function register_default_field_types(): void {
        $this->field_types = [
            // Basic Fields
            'text' => [
                'label' => __('Text Input', 'formflow'),
                'icon' => 'dashicons-editor-textcolor',
                'category' => 'basic',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => ''],
                    'placeholder' => ['type' => 'text', 'label' => __('Placeholder', 'formflow'), 'default' => ''],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'help_text' => ['type' => 'textarea', 'label' => __('Help Text', 'formflow'), 'default' => ''],
                    'max_length' => ['type' => 'number', 'label' => __('Max Length', 'formflow'), 'default' => ''],
                    'pattern' => ['type' => 'text', 'label' => __('Validation Pattern (regex)', 'formflow'), 'default' => ''],
                ],
            ],
            'email' => [
                'label' => __('Email', 'formflow'),
                'icon' => 'dashicons-email',
                'category' => 'basic',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Email Address', 'formflow')],
                    'placeholder' => ['type' => 'text', 'label' => __('Placeholder', 'formflow'), 'default' => 'email@example.com'],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => true],
                    'confirm' => ['type' => 'checkbox', 'label' => __('Require Confirmation', 'formflow'), 'default' => false],
                ],
            ],
            'phone' => [
                'label' => __('Phone Number', 'formflow'),
                'icon' => 'dashicons-phone',
                'category' => 'basic',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Phone Number', 'formflow')],
                    'placeholder' => ['type' => 'text', 'label' => __('Placeholder', 'formflow'), 'default' => '(555) 555-5555'],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'format' => ['type' => 'select', 'label' => __('Format', 'formflow'), 'default' => 'us', 'options' => [
                        'us' => __('US Format', 'formflow'),
                        'international' => __('International', 'formflow'),
                    ]],
                ],
            ],
            'number' => [
                'label' => __('Number', 'formflow'),
                'icon' => 'dashicons-calculator',
                'category' => 'basic',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => ''],
                    'placeholder' => ['type' => 'text', 'label' => __('Placeholder', 'formflow'), 'default' => ''],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'min' => ['type' => 'number', 'label' => __('Minimum Value', 'formflow'), 'default' => ''],
                    'max' => ['type' => 'number', 'label' => __('Maximum Value', 'formflow'), 'default' => ''],
                    'step' => ['type' => 'number', 'label' => __('Step', 'formflow'), 'default' => '1'],
                ],
            ],
            'textarea' => [
                'label' => __('Text Area', 'formflow'),
                'icon' => 'dashicons-editor-paragraph',
                'category' => 'basic',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => ''],
                    'placeholder' => ['type' => 'text', 'label' => __('Placeholder', 'formflow'), 'default' => ''],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'rows' => ['type' => 'number', 'label' => __('Rows', 'formflow'), 'default' => 4],
                    'max_length' => ['type' => 'number', 'label' => __('Max Length', 'formflow'), 'default' => ''],
                ],
            ],

            // Selection Fields
            'select' => [
                'label' => __('Dropdown', 'formflow'),
                'icon' => 'dashicons-arrow-down-alt2',
                'category' => 'selection',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => ''],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'options' => ['type' => 'options', 'label' => __('Options', 'formflow'), 'default' => []],
                    'placeholder' => ['type' => 'text', 'label' => __('Placeholder', 'formflow'), 'default' => __('Select an option', 'formflow')],
                    'searchable' => ['type' => 'checkbox', 'label' => __('Searchable', 'formflow'), 'default' => false],
                ],
            ],
            'radio' => [
                'label' => __('Radio Buttons', 'formflow'),
                'icon' => 'dashicons-marker',
                'category' => 'selection',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => ''],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'options' => ['type' => 'options', 'label' => __('Options', 'formflow'), 'default' => []],
                    'layout' => ['type' => 'select', 'label' => __('Layout', 'formflow'), 'default' => 'vertical', 'options' => [
                        'vertical' => __('Vertical', 'formflow'),
                        'horizontal' => __('Horizontal', 'formflow'),
                    ]],
                ],
            ],
            'checkbox' => [
                'label' => __('Checkboxes', 'formflow'),
                'icon' => 'dashicons-yes',
                'category' => 'selection',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => ''],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'options' => ['type' => 'options', 'label' => __('Options', 'formflow'), 'default' => []],
                    'min_select' => ['type' => 'number', 'label' => __('Minimum Selections', 'formflow'), 'default' => ''],
                    'max_select' => ['type' => 'number', 'label' => __('Maximum Selections', 'formflow'), 'default' => ''],
                ],
            ],
            'toggle' => [
                'label' => __('Toggle Switch', 'formflow'),
                'icon' => 'dashicons-controls-repeat',
                'category' => 'selection',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => ''],
                    'default_value' => ['type' => 'checkbox', 'label' => __('Default On', 'formflow'), 'default' => false],
                    'on_label' => ['type' => 'text', 'label' => __('On Label', 'formflow'), 'default' => __('Yes', 'formflow')],
                    'off_label' => ['type' => 'text', 'label' => __('Off Label', 'formflow'), 'default' => __('No', 'formflow')],
                ],
            ],

            // Advanced Fields
            'date' => [
                'label' => __('Date Picker', 'formflow'),
                'icon' => 'dashicons-calendar-alt',
                'category' => 'advanced',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => ''],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'min_date' => ['type' => 'text', 'label' => __('Min Date (YYYY-MM-DD or "today")', 'formflow'), 'default' => ''],
                    'max_date' => ['type' => 'text', 'label' => __('Max Date', 'formflow'), 'default' => ''],
                    'disable_weekends' => ['type' => 'checkbox', 'label' => __('Disable Weekends', 'formflow'), 'default' => false],
                ],
            ],
            'time' => [
                'label' => __('Time Picker', 'formflow'),
                'icon' => 'dashicons-clock',
                'category' => 'advanced',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => ''],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'min_time' => ['type' => 'text', 'label' => __('Min Time (HH:MM)', 'formflow'), 'default' => ''],
                    'max_time' => ['type' => 'text', 'label' => __('Max Time', 'formflow'), 'default' => ''],
                    'interval' => ['type' => 'select', 'label' => __('Time Interval', 'formflow'), 'default' => '30', 'options' => [
                        '15' => __('15 minutes', 'formflow'),
                        '30' => __('30 minutes', 'formflow'),
                        '60' => __('1 hour', 'formflow'),
                    ]],
                ],
            ],
            'file' => [
                'label' => __('File Upload', 'formflow'),
                'icon' => 'dashicons-upload',
                'category' => 'advanced',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => ''],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'allowed_types' => ['type' => 'text', 'label' => __('Allowed File Types', 'formflow'), 'default' => 'jpg,jpeg,png,pdf'],
                    'max_size' => ['type' => 'number', 'label' => __('Max File Size (MB)', 'formflow'), 'default' => 5],
                    'multiple' => ['type' => 'checkbox', 'label' => __('Allow Multiple Files', 'formflow'), 'default' => false],
                ],
            ],
            'signature' => [
                'label' => __('Signature', 'formflow'),
                'icon' => 'dashicons-admin-customizer',
                'category' => 'advanced',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Signature', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => true],
                    'width' => ['type' => 'number', 'label' => __('Width (px)', 'formflow'), 'default' => 400],
                    'height' => ['type' => 'number', 'label' => __('Height (px)', 'formflow'), 'default' => 150],
                ],
            ],
            'likert_scale' => [
                'label' => __('Likert Scale', 'formflow'),
                'icon' => 'dashicons-star-filled',
                'category' => 'advanced',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Rate your satisfaction', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'scale_type' => ['type' => 'select', 'label' => __('Scale Type', 'formflow'), 'default' => '5', 'options' => [
                        '3' => __('3-point scale', 'formflow'),
                        '5' => __('5-point scale', 'formflow'),
                        '7' => __('7-point scale', 'formflow'),
                        '10' => __('10-point scale', 'formflow'),
                    ]],
                    'labels' => ['type' => 'text', 'label' => __('Custom Labels (comma-separated)', 'formflow'), 'default' => ''],
                    'show_labels' => ['type' => 'checkbox', 'label' => __('Show Labels', 'formflow'), 'default' => true],
                ],
            ],
            'slider' => [
                'label' => __('Slider', 'formflow'),
                'icon' => 'dashicons-leftright',
                'category' => 'advanced',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => ''],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'min' => ['type' => 'number', 'label' => __('Minimum Value', 'formflow'), 'default' => 0],
                    'max' => ['type' => 'number', 'label' => __('Maximum Value', 'formflow'), 'default' => 100],
                    'step' => ['type' => 'number', 'label' => __('Step', 'formflow'), 'default' => 1],
                    'default_value' => ['type' => 'number', 'label' => __('Default Value', 'formflow'), 'default' => 50],
                    'show_value' => ['type' => 'checkbox', 'label' => __('Show Current Value', 'formflow'), 'default' => true],
                    'prefix' => ['type' => 'text', 'label' => __('Value Prefix', 'formflow'), 'default' => ''],
                    'suffix' => ['type' => 'text', 'label' => __('Value Suffix', 'formflow'), 'default' => ''],
                ],
            ],
            'recaptcha_v3' => [
                'label' => __('reCAPTCHA v3', 'formflow'),
                'icon' => 'dashicons-shield',
                'category' => 'advanced',
                'settings' => [
                    'site_key' => ['type' => 'text', 'label' => __('Site Key', 'formflow'), 'default' => ''],
                    'secret_key' => ['type' => 'text', 'label' => __('Secret Key', 'formflow'), 'default' => ''],
                    'threshold' => ['type' => 'number', 'label' => __('Score Threshold (0.0-1.0)', 'formflow'), 'default' => 0.5],
                    'action' => ['type' => 'text', 'label' => __('Action Name', 'formflow'), 'default' => 'submit'],
                ],
            ],
            'repeater' => [
                'label' => __('Repeater', 'formflow'),
                'icon' => 'dashicons-plus-alt',
                'category' => 'advanced',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Add Item', 'formflow')],
                    'min_items' => ['type' => 'number', 'label' => __('Minimum Items', 'formflow'), 'default' => 1],
                    'max_items' => ['type' => 'number', 'label' => __('Maximum Items', 'formflow'), 'default' => 10],
                    'add_button_text' => ['type' => 'text', 'label' => __('Add Button Text', 'formflow'), 'default' => __('Add Item', 'formflow')],
                    'remove_button_text' => ['type' => 'text', 'label' => __('Remove Button Text', 'formflow'), 'default' => __('Remove', 'formflow')],
                ],
                'is_container' => true,
            ],
            'star_rating' => [
                'label' => __('Star Rating', 'formflow'),
                'icon' => 'dashicons-star-filled',
                'category' => 'advanced',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Rate this', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'max_stars' => ['type' => 'select', 'label' => __('Number of Stars', 'formflow'), 'default' => '5', 'options' => [
                        '3' => '3',
                        '5' => '5',
                        '7' => '7',
                        '10' => '10',
                    ]],
                    'star_size' => ['type' => 'select', 'label' => __('Star Size', 'formflow'), 'default' => 'medium', 'options' => [
                        'small' => __('Small', 'formflow'),
                        'medium' => __('Medium', 'formflow'),
                        'large' => __('Large', 'formflow'),
                    ]],
                    'show_labels' => ['type' => 'checkbox', 'label' => __('Show Rating Labels', 'formflow'), 'default' => false],
                ],
            ],
            'date_range' => [
                'label' => __('Date Range Picker', 'formflow'),
                'icon' => 'dashicons-calendar-alt',
                'category' => 'advanced',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Select Date Range', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'min_date' => ['type' => 'text', 'label' => __('Min Date (YYYY-MM-DD or "today")', 'formflow'), 'default' => ''],
                    'max_date' => ['type' => 'text', 'label' => __('Max Date', 'formflow'), 'default' => ''],
                    'preset_ranges' => ['type' => 'checkbox', 'label' => __('Show Preset Ranges', 'formflow'), 'default' => true],
                ],
            ],
            'address_autocomplete' => [
                'label' => __('Address Autocomplete', 'formflow'),
                'icon' => 'dashicons-location-alt',
                'category' => 'advanced',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Address', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => true],
                    'placeholder' => ['type' => 'text', 'label' => __('Placeholder', 'formflow'), 'default' => __('Start typing an address...', 'formflow')],
                    'api_key' => ['type' => 'text', 'label' => __('Google Places API Key', 'formflow'), 'default' => ''],
                    'countries' => ['type' => 'text', 'label' => __('Restrict to Countries (comma-separated)', 'formflow'), 'default' => 'us'],
                    'help_text' => ['type' => 'textarea', 'label' => __('Help Text', 'formflow'), 'default' => __('Google Places API integration. Configure API key in settings.', 'formflow')],
                ],
            ],
            'number_stepper' => [
                'label' => __('Number Stepper', 'formflow'),
                'icon' => 'dashicons-plus-alt2',
                'category' => 'advanced',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Quantity', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'min' => ['type' => 'number', 'label' => __('Minimum Value', 'formflow'), 'default' => 0],
                    'max' => ['type' => 'number', 'label' => __('Maximum Value', 'formflow'), 'default' => 100],
                    'step' => ['type' => 'number', 'label' => __('Step', 'formflow'), 'default' => 1],
                    'default_value' => ['type' => 'number', 'label' => __('Default Value', 'formflow'), 'default' => 1],
                    'size' => ['type' => 'select', 'label' => __('Size', 'formflow'), 'default' => 'medium', 'options' => [
                        'small' => __('Small', 'formflow'),
                        'medium' => __('Medium', 'formflow'),
                        'large' => __('Large', 'formflow'),
                    ]],
                ],
            ],
            'color_picker' => [
                'label' => __('Color Picker', 'formflow'),
                'icon' => 'dashicons-art',
                'category' => 'advanced',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Select Color', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                    'default_color' => ['type' => 'text', 'label' => __('Default Color', 'formflow'), 'default' => '#000000'],
                    'preset_colors' => ['type' => 'text', 'label' => __('Preset Colors (comma-separated hex)', 'formflow'), 'default' => '#FF0000,#00FF00,#0000FF,#FFFF00,#FF00FF,#00FFFF'],
                    'show_alpha' => ['type' => 'checkbox', 'label' => __('Enable Opacity/Alpha', 'formflow'), 'default' => false],
                ],
            ],

            // Address Fields
            'address' => [
                'label' => __('Address (Smart)', 'formflow'),
                'icon' => 'dashicons-location',
                'category' => 'address',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Service Address', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => true],
                    'autocomplete' => ['type' => 'checkbox', 'label' => __('Enable Autocomplete', 'formflow'), 'default' => true],
                    'validate_territory' => ['type' => 'checkbox', 'label' => __('Validate Service Territory', 'formflow'), 'default' => true],
                    'include_unit' => ['type' => 'checkbox', 'label' => __('Include Unit/Apt Field', 'formflow'), 'default' => true],
                ],
            ],
            'address_street' => [
                'label' => __('Street Address', 'formflow'),
                'icon' => 'dashicons-location',
                'category' => 'address',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Street Address', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => true],
                    'autocomplete' => ['type' => 'checkbox', 'label' => __('Enable Autocomplete', 'formflow'), 'default' => true],
                ],
            ],
            'address_city' => [
                'label' => __('City', 'formflow'),
                'icon' => 'dashicons-location',
                'category' => 'address',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('City', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => true],
                ],
            ],
            'address_state' => [
                'label' => __('State', 'formflow'),
                'icon' => 'dashicons-location',
                'category' => 'address',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('State', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => true],
                    'country' => ['type' => 'select', 'label' => __('Country', 'formflow'), 'default' => 'US', 'options' => [
                        'US' => __('United States', 'formflow'),
                        'CA' => __('Canada', 'formflow'),
                    ]],
                ],
            ],
            'address_zip' => [
                'label' => __('ZIP Code', 'formflow'),
                'icon' => 'dashicons-location',
                'category' => 'address',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('ZIP Code', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => true],
                    'validate_format' => ['type' => 'checkbox', 'label' => __('Validate Format', 'formflow'), 'default' => true],
                ],
            ],

            // Utility-Specific Fields
            'account_number' => [
                'label' => __('Account Number', 'formflow'),
                'icon' => 'dashicons-id',
                'category' => 'utility',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Account Number', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => true],
                    'help_text' => ['type' => 'textarea', 'label' => __('Help Text', 'formflow'), 'default' => __('Find this on your utility bill', 'formflow')],
                    'validate_api' => ['type' => 'checkbox', 'label' => __('Validate via API', 'formflow'), 'default' => true],
                    'mask' => ['type' => 'text', 'label' => __('Input Mask', 'formflow'), 'default' => ''],
                ],
            ],
            'meter_number' => [
                'label' => __('Meter Number', 'formflow'),
                'icon' => 'dashicons-dashboard',
                'category' => 'utility',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Meter Number', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => false],
                ],
            ],
            'device_type' => [
                'label' => __('Device Type Selector', 'formflow'),
                'icon' => 'dashicons-laptop',
                'category' => 'utility',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Select Your Device', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => true],
                    'device_options' => ['type' => 'select', 'label' => __('Device Category', 'formflow'), 'default' => 'thermostat', 'options' => [
                        'thermostat' => __('Smart Thermostats', 'formflow'),
                        'water_heater' => __('Water Heaters', 'formflow'),
                        'ev_charger' => __('EV Chargers', 'formflow'),
                        'pool_pump' => __('Pool Pumps', 'formflow'),
                        'custom' => __('Custom List', 'formflow'),
                    ]],
                ],
            ],
            'program_selector' => [
                'label' => __('Program Selector', 'formflow'),
                'icon' => 'dashicons-clipboard',
                'category' => 'utility',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => __('Label', 'formflow'), 'default' => __('Select Programs', 'formflow')],
                    'required' => ['type' => 'checkbox', 'label' => __('Required', 'formflow'), 'default' => true],
                    'allow_multiple' => ['type' => 'checkbox', 'label' => __('Allow Multiple Selections', 'formflow'), 'default' => true],
                    'show_descriptions' => ['type' => 'checkbox', 'label' => __('Show Program Descriptions', 'formflow'), 'default' => true],
                    'show_incentives' => ['type' => 'checkbox', 'label' => __('Show Incentive Amounts', 'formflow'), 'default' => true],
                ],
            ],

            // Layout Elements
            'heading' => [
                'label' => __('Heading', 'formflow'),
                'icon' => 'dashicons-heading',
                'category' => 'layout',
                'settings' => [
                    'text' => ['type' => 'text', 'label' => __('Heading Text', 'formflow'), 'default' => ''],
                    'level' => ['type' => 'select', 'label' => __('Heading Level', 'formflow'), 'default' => 'h3', 'options' => [
                        'h2' => 'H2',
                        'h3' => 'H3',
                        'h4' => 'H4',
                    ]],
                    'alignment' => ['type' => 'select', 'label' => __('Alignment', 'formflow'), 'default' => 'left', 'options' => [
                        'left' => __('Left', 'formflow'),
                        'center' => __('Center', 'formflow'),
                        'right' => __('Right', 'formflow'),
                    ]],
                ],
            ],
            'paragraph' => [
                'label' => __('Paragraph', 'formflow'),
                'icon' => 'dashicons-editor-paragraph',
                'category' => 'layout',
                'settings' => [
                    'content' => ['type' => 'wysiwyg', 'label' => __('Content', 'formflow'), 'default' => ''],
                ],
            ],
            'divider' => [
                'label' => __('Divider', 'formflow'),
                'icon' => 'dashicons-minus',
                'category' => 'layout',
                'settings' => [
                    'style' => ['type' => 'select', 'label' => __('Style', 'formflow'), 'default' => 'solid', 'options' => [
                        'solid' => __('Solid', 'formflow'),
                        'dashed' => __('Dashed', 'formflow'),
                        'dotted' => __('Dotted', 'formflow'),
                    ]],
                    'spacing' => ['type' => 'select', 'label' => __('Spacing', 'formflow'), 'default' => 'medium', 'options' => [
                        'small' => __('Small', 'formflow'),
                        'medium' => __('Medium', 'formflow'),
                        'large' => __('Large', 'formflow'),
                    ]],
                ],
            ],
            'spacer' => [
                'label' => __('Spacer', 'formflow'),
                'icon' => 'dashicons-image-flip-vertical',
                'category' => 'layout',
                'settings' => [
                    'height' => ['type' => 'number', 'label' => __('Height (px)', 'formflow'), 'default' => 20],
                ],
            ],
            'columns' => [
                'label' => __('Columns', 'formflow'),
                'icon' => 'dashicons-columns',
                'category' => 'layout',
                'settings' => [
                    'column_count' => ['type' => 'select', 'label' => __('Columns', 'formflow'), 'default' => '2', 'options' => [
                        '2' => __('2 Columns', 'formflow'),
                        '3' => __('3 Columns', 'formflow'),
                        '4' => __('4 Columns', 'formflow'),
                    ]],
                    'gap' => ['type' => 'select', 'label' => __('Gap', 'formflow'), 'default' => 'medium', 'options' => [
                        'small' => __('Small', 'formflow'),
                        'medium' => __('Medium', 'formflow'),
                        'large' => __('Large', 'formflow'),
                    ]],
                ],
                'is_container' => true,
            ],
            'section' => [
                'label' => __('Section', 'formflow'),
                'icon' => 'dashicons-editor-table',
                'category' => 'layout',
                'settings' => [
                    'title' => ['type' => 'text', 'label' => __('Section Title', 'formflow'), 'default' => ''],
                    'collapsible' => ['type' => 'checkbox', 'label' => __('Collapsible', 'formflow'), 'default' => false],
                    'collapsed_default' => ['type' => 'checkbox', 'label' => __('Collapsed by Default', 'formflow'), 'default' => false],
                ],
                'is_container' => true,
            ],
        ];

        // Allow extensions to add custom field types
        $this->field_types = apply_filters('isf_builder_field_types', $this->field_types);
    }

    /**
     * Get all field types
     */
    public function get_field_types(): array {
        return $this->field_types;
    }

    /**
     * Get field types by category
     */
    public function get_field_types_by_category(): array {
        $categories = [
            'basic' => ['label' => __('Basic Fields', 'formflow'), 'fields' => []],
            'selection' => ['label' => __('Selection Fields', 'formflow'), 'fields' => []],
            'advanced' => ['label' => __('Advanced Fields', 'formflow'), 'fields' => []],
            'address' => ['label' => __('Address Fields', 'formflow'), 'fields' => []],
            'utility' => ['label' => __('Utility Fields', 'formflow'), 'fields' => []],
            'layout' => ['label' => __('Layout Elements', 'formflow'), 'fields' => []],
        ];

        foreach ($this->field_types as $type => $config) {
            $category = $config['category'] ?? 'basic';
            if (isset($categories[$category])) {
                $categories[$category]['fields'][$type] = $config;
            }
        }

        return $categories;
    }

    /**
     * Register a custom field type
     */
    public function register_field_type(string $type, array $config): void {
        $this->field_types[$type] = $config;
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        register_rest_route('isf/v1', '/builder/schema/(?P<instance_id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'rest_get_schema'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'rest_save_schema'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        register_rest_route('isf/v1', '/builder/field-types', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_field_types'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        register_rest_route('isf/v1', '/builder/preview', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_preview_form'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);
    }

    /**
     * Check admin permission for REST API
     */
    public function check_admin_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * REST: Get form schema
     */
    public function rest_get_schema(\WP_REST_Request $request): \WP_REST_Response {
        $instance_id = intval($request->get_param('instance_id'));

        global $wpdb;
        $table = $wpdb->prefix . 'isf_instances';
        $instance = $wpdb->get_row($wpdb->prepare(
            "SELECT settings FROM {$table} WHERE id = %d",
            $instance_id
        ));

        if (!$instance) {
            return new \WP_REST_Response(['error' => 'Instance not found'], 404);
        }

        $settings = json_decode($instance->settings, true) ?: [];
        $schema = $settings['form_schema'] ?? $this->get_default_schema();

        return new \WP_REST_Response([
            'success' => true,
            'schema' => $schema,
        ]);
    }

    /**
     * REST: Save form schema
     */
    public function rest_save_schema(\WP_REST_Request $request): \WP_REST_Response {
        $instance_id = intval($request->get_param('instance_id'));
        $schema = $request->get_json_params();

        // Validate schema
        $validation = $this->validate_schema($schema);
        if (!$validation['valid']) {
            return new \WP_REST_Response([
                'success' => false,
                'errors' => $validation['errors'],
            ], 400);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'isf_instances';

        // Get current settings
        $instance = $wpdb->get_row($wpdb->prepare(
            "SELECT settings FROM {$table} WHERE id = %d",
            $instance_id
        ));

        if (!$instance) {
            return new \WP_REST_Response(['error' => 'Instance not found'], 404);
        }

        $settings = json_decode($instance->settings, true) ?: [];
        $settings['form_schema'] = $schema;

        // Save updated settings
        $wpdb->update(
            $table,
            ['settings' => wp_json_encode($settings)],
            ['id' => $instance_id]
        );

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Form schema saved successfully.', 'formflow'),
        ]);
    }

    /**
     * REST: Get field types
     */
    public function rest_get_field_types(): \WP_REST_Response {
        return new \WP_REST_Response([
            'success' => true,
            'field_types' => $this->get_field_types_by_category(),
        ]);
    }

    /**
     * REST: Preview form
     */
    public function rest_preview_form(\WP_REST_Request $request): \WP_REST_Response {
        $schema = $request->get_json_params();

        // Use service container if available, otherwise create directly
        try {
            $renderer = \ISF\ServiceContainer::instance()->has('form_renderer')
                ? \ISF\ServiceContainer::instance()->get('form_renderer')
                : new FormRenderer();
        } catch (\Throwable $e) {
            $renderer = new FormRenderer();
        }

        $html = $renderer->render_preview($schema);

        return new \WP_REST_Response([
            'success' => true,
            'html' => $html,
        ]);
    }

    /**
     * Validate form schema
     *
     * Validates the schema structure and applies customizable validation rules.
     */
    public function validate_schema(array $schema): array {
        $errors = [];
        $warnings = [];

        // Size validation to prevent excessive schema sizes
        $schema_json = wp_json_encode($schema);
        $schema_size = strlen($schema_json);
        $max_size = apply_filters('isf_schema_max_size', 1024 * 500); // 500KB default

        if ($schema_size > $max_size) {
            $errors[] = sprintf(
                __('Form schema exceeds maximum size (%s KB). Please reduce the number of fields.', 'formflow'),
                round($max_size / 1024)
            );
        }

        // Step count validation
        $max_steps = apply_filters('isf_schema_max_steps', 20);
        if (count($schema['steps'] ?? []) > $max_steps) {
            $errors[] = sprintf(
                __('Form exceeds maximum number of steps (%d).', 'formflow'),
                $max_steps
            );
        }

        if (empty($schema['steps']) || !is_array($schema['steps'])) {
            $errors[] = __('Form must have at least one step.', 'formflow');
        }

        $field_names = [];
        $nesting_depth = 0;
        $max_nesting = apply_filters('isf_schema_max_nesting', 3);
        $max_fields_per_step = apply_filters('isf_schema_max_fields_per_step', 50);

        foreach ($schema['steps'] ?? [] as $step_index => $step) {
            if (empty($step['fields']) || !is_array($step['fields'])) {
                continue;
            }

            // Field count per step
            if (count($step['fields']) > $max_fields_per_step) {
                $warnings[] = sprintf(
                    __('Step %d has many fields (%d). Consider splitting into multiple steps for better performance.', 'formflow'),
                    $step_index + 1,
                    count($step['fields'])
                );
            }

            foreach ($step['fields'] as $field_index => $field) {
                if (empty($field['type'])) {
                    $errors[] = sprintf(
                        __('Field %d in step %d is missing a type.', 'formflow'),
                        $field_index + 1,
                        $step_index + 1
                    );
                }

                $layout_fields = ['heading', 'paragraph', 'divider', 'spacer', 'columns', 'section'];
                if (empty($field['name']) && !in_array($field['type'] ?? '', $layout_fields)) {
                    $errors[] = sprintf(
                        __('Field %d in step %d is missing a name.', 'formflow'),
                        $field_index + 1,
                        $step_index + 1
                    );
                }

                // Check for duplicate field names
                if (!empty($field['name'])) {
                    if (in_array($field['name'], $field_names)) {
                        $errors[] = sprintf(
                            __('Duplicate field name "%s" found. Field names must be unique.', 'formflow'),
                            $field['name']
                        );
                    }
                    $field_names[] = $field['name'];
                }

                // Validate field name format
                if (!empty($field['name']) && !preg_match('/^[a-zA-Z][a-zA-Z0-9_\-]*$/', $field['name'])) {
                    $errors[] = sprintf(
                        __('Invalid field name "%s". Names must start with a letter and contain only letters, numbers, underscores, and hyphens.', 'formflow'),
                        $field['name']
                    );
                }

                // Check for container nesting depth
                if (in_array($field['type'] ?? '', ['columns', 'section'])) {
                    $nesting_depth++;
                    if ($nesting_depth > $max_nesting) {
                        $errors[] = sprintf(
                            __('Container nesting depth exceeds maximum (%d levels).', 'formflow'),
                            $max_nesting
                        );
                    }
                }
            }
        }

        /**
         * Filter schema validation result
         *
         * Allows custom validation rules to be added.
         *
         * @param array $result   Validation result with 'valid', 'errors', 'warnings' keys
         * @param array $schema   The form schema being validated
         */
        $result = apply_filters('isf_schema_validation', [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ], $schema);

        return $result;
    }

    /**
     * Get default schema for new forms
     */
    public function get_default_schema(): array {
        return [
            'version' => '1.0',
            'steps' => [
                [
                    'id' => 'step_1',
                    'title' => __('Step 1', 'formflow'),
                    'description' => '',
                    'fields' => [],
                ],
            ],
            'settings' => [
                'submit_button_text' => __('Submit', 'formflow'),
                'success_message' => __('Thank you for your submission!', 'formflow'),
            ],
        ];
    }

    /**
     * Enqueue builder assets
     */
    public function enqueue_builder_assets(string $hook): void {
        // Only load on form builder page
        if (strpos($hook, 'isf-') === false) {
            return;
        }

        // Check if we're on the builder page
        if (!isset($_GET['action']) || $_GET['action'] !== 'builder') {
            return;
        }

        // React app for builder
        wp_enqueue_script(
            'isf-form-builder',
            ISF_PLUGIN_URL . 'admin/assets/js/form-builder.js',
            ['wp-element', 'wp-components', 'wp-i18n', 'jquery-ui-sortable', 'jquery-ui-draggable', 'jquery-ui-droppable'],
            ISF_VERSION,
            true
        );

        wp_enqueue_style(
            'isf-form-builder',
            ISF_PLUGIN_URL . 'admin/assets/css/form-builder.css',
            ['wp-components'],
            ISF_VERSION
        );

        wp_localize_script('isf-form-builder', 'ISFBuilder', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url('isf/v1/builder/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'field_types' => $this->get_field_types_by_category(),
            'instance_id' => intval($_GET['instance_id'] ?? 0),
            'strings' => [
                'save' => __('Save', 'formflow'),
                'preview' => __('Preview', 'formflow'),
                'undo' => __('Undo', 'formflow'),
                'redo' => __('Redo', 'formflow'),
                'add_step' => __('Add Step', 'formflow'),
                'delete_step' => __('Delete Step', 'formflow'),
                'step_settings' => __('Step Settings', 'formflow'),
                'field_settings' => __('Field Settings', 'formflow'),
                'conditional_logic' => __('Conditional Logic', 'formflow'),
                'drag_field' => __('Drag a field here', 'formflow'),
                'confirm_delete' => __('Are you sure you want to delete this?', 'formflow'),
                'saved' => __('Changes saved!', 'formflow'),
                'error_saving' => __('Error saving changes.', 'formflow'),
            ],
        ]);
    }

    /**
     * AJAX: Save form schema
     */
    public function ajax_save_form_schema(): void {
        check_ajax_referer('isf_builder_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'formflow')]);
        }

        $instance_id = intval($_POST['instance_id'] ?? 0);
        $schema = json_decode(stripslashes($_POST['schema'] ?? ''), true);

        if (!$instance_id || !$schema) {
            wp_send_json_error(['message' => __('Invalid data.', 'formflow')]);
        }

        // Save schema...
        global $wpdb;
        $table = $wpdb->prefix . 'isf_instances';

        $instance = $wpdb->get_row($wpdb->prepare(
            "SELECT settings FROM {$table} WHERE id = %d",
            $instance_id
        ));

        if (!$instance) {
            wp_send_json_error(['message' => __('Instance not found.', 'formflow')]);
        }

        $settings = json_decode($instance->settings, true) ?: [];
        $settings['form_schema'] = $schema;

        $wpdb->update(
            $table,
            ['settings' => wp_json_encode($settings)],
            ['id' => $instance_id]
        );

        wp_send_json_success(['message' => __('Form saved successfully.', 'formflow')]);
    }

    /**
     * AJAX: Load form schema
     */
    public function ajax_load_form_schema(): void {
        check_ajax_referer('isf_builder_nonce', 'nonce');

        $instance_id = intval($_POST['instance_id'] ?? 0);

        if (!$instance_id) {
            wp_send_json_error(['message' => __('Invalid instance ID.', 'formflow')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'isf_instances';

        $instance = $wpdb->get_row($wpdb->prepare(
            "SELECT settings FROM {$table} WHERE id = %d",
            $instance_id
        ));

        if (!$instance) {
            wp_send_json_error(['message' => __('Instance not found.', 'formflow')]);
        }

        $settings = json_decode($instance->settings, true) ?: [];
        $schema = $settings['form_schema'] ?? $this->get_default_schema();

        wp_send_json_success(['schema' => $schema]);
    }

    /**
     * AJAX: Preview form
     */
    public function ajax_preview_form(): void {
        check_ajax_referer('isf_builder_nonce', 'nonce');

        $schema = json_decode(stripslashes($_POST['schema'] ?? ''), true);

        if (!$schema) {
            wp_send_json_error(['message' => __('Invalid schema.', 'formflow')]);
        }

        // Use service container if available
        try {
            $renderer = \ISF\ServiceContainer::instance()->has('form_renderer')
                ? \ISF\ServiceContainer::instance()->get('form_renderer')
                : new FormRenderer();
        } catch (\Throwable $e) {
            $renderer = new FormRenderer();
        }

        $html = $renderer->render_preview($schema);

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Log an error message
     *
     * @param string $message Error message
     * @param array  $context Additional context
     */
    private function log_error(string $message, array $context = []): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[FormFlow FormBuilder] %s | Context: %s',
                $message,
                wp_json_encode($context)
            ));
        }

        /**
         * Fires when a form builder error occurs
         *
         * @param string $message Error message
         * @param array  $context Additional context
         */
        do_action('isf_form_builder_error', $message, $context);
    }
}

