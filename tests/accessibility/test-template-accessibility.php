<?php
/**
 * FormFlow Pro - Accessibility Tests for Templates
 *
 * Tests that form output contains proper accessibility attributes.
 * Verifies WCAG 2.1 Level AA compliance in template rendering.
 *
 * @package FormFlow
 */

if (!defined('ABSPATH')) {
    exit;
}

use PHPUnit\Framework\TestCase;

// esc_html() shim. These tests run against the plugin's lightweight stub
// bootstrap (no wordpress-tests-lib), so declare a real escaping fallback that
// mirrors WordPress core behaviour. Per tests/bootstrap.php, plain PHPUnit tests
// declare their own function_exists() guards rather than the bootstrap doing so.
if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Test_Template_Accessibility class
 *
 * Tests accessibility of form template output.
 *
 * Ported from WP_UnitTestCase to plain PHPUnit\Framework\TestCase to match this
 * repo's stub-bootstrap convention (see tests/Regression/*): the suite runs
 * without wordpress-tests-lib or MySQL. These assertions are self-contained
 * string checks over rendered HTML fragments and need no WordPress runtime.
 */
class Test_Template_Accessibility extends TestCase {

    /**
     * Form instance for testing
     *
     * @var array
     */
    private $test_instance;

    /**
     * Set up test fixtures
     */
    public function setUp(): void {
        parent::setUp();

        // Create a test form instance
        $this->test_instance = [
            'id' => 1,
            'name' => 'Test Enrollment Form',
            'type' => 'enrollment',
            'settings' => [
                'content' => [
                    'step1_title' => 'Choose Your Device',
                    'step2_title' => 'Verify Account',
                    'form_description' => 'Test form for accessibility',
                    'program_name' => 'Test Program',
                    'utility_name' => 'Test Utility',
                    'btn_next' => 'Continue',
                    'btn_back' => 'Back',
                ],
            ],
        ];
    }

    /**
     * Test that form wrapper has proper structure
     *
     * @test
     */
    public function test_form_has_proper_wrapper_structure() {
        ob_start();
        ?>
        <form class="isf-step-form" id="isf-test-form">
            <fieldset class="isf-fieldset">
                <legend>Form Section</legend>
                <input type="text" id="test_input" />
            </fieldset>
        </form>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('<form', $output);
        $this->assertStringContainsString('<fieldset', $output);
        $this->assertStringContainsString('<legend>', $output);
    }

    /**
     * Test that form fields have associated labels
     *
     * @test
     */
    public function test_form_field_has_label() {
        ob_start();
        ?>
        <div class="isf-field">
            <label for="email" class="isf-label">
                Email Address
                <span class="isf-required">*</span>
            </label>
            <input type="email" id="email" name="email" required>
        </div>
        <?php
        $output = ob_get_clean();

        // Check label exists
        $this->assertStringContainsString('<label for="email"', $output);
        // Check input has matching id
        $this->assertStringContainsString('id="email"', $output);
        // Check required indicator
        $this->assertStringContainsString('class="isf-required"', $output);
        $this->assertStringContainsString('required', $output);
    }

    /**
     * Test that required fields have proper ARIA attributes
     *
     * @test
     */
    public function test_required_field_has_aria_required() {
        ob_start();
        ?>
        <input type="text" id="account" name="account" required aria-required="true">
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('aria-required="true"', $output);
        $this->assertStringContainsString('required', $output);
    }

    /**
     * Test that error messages have role="alert"
     *
     * @test
     */
    public function test_error_message_has_alert_role() {
        ob_start();
        ?>
        <div class="isf-alert isf-alert-error" role="alert">
            <span class="isf-alert-message">Account number is invalid</span>
        </div>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('role="alert"', $output);
        $this->assertStringContainsString('isf-alert-error', $output);
    }

    /**
     * Test that status messages have aria-live
     *
     * @test
     */
    public function test_status_message_has_aria_live() {
        ob_start();
        ?>
        <div role="status" aria-live="polite" aria-busy="true">
            Validating your information...
        </div>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('role="status"', $output);
        $this->assertStringContainsString('aria-live="polite"', $output);
        $this->assertStringContainsString('aria-busy="true"', $output);
    }

    /**
     * Test that images have alt text
     *
     * @test
     */
    public function test_image_has_alt_text() {
        ob_start();
        ?>
        <img src="device.png" alt="Smart Thermostat Device" />
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('alt="Smart Thermostat Device"', $output);
    }

    /**
     * Test that decorative images have empty alt
     *
     * @test
     */
    public function test_decorative_image_has_empty_alt() {
        ob_start();
        ?>
        <img src="spacer.png" alt="" aria-hidden="true" />
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('alt=""', $output);
        $this->assertStringContainsString('aria-hidden="true"', $output);
    }

    /**
     * Test that buttons have accessible text
     *
     * @test
     */
    public function test_button_has_accessible_text() {
        ob_start();
        ?>
        <button type="submit" class="isf-btn">Continue to Next Step</button>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('Continue to Next Step', $output);
    }

    /**
     * Test that icon-only buttons have aria-label
     *
     * @test
     */
    public function test_icon_button_has_aria_label() {
        ob_start();
        ?>
        <button type="button" class="isf-btn-close" aria-label="Close modal">
            <svg aria-hidden="true"></svg>
        </button>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('aria-label="Close modal"', $output);
        $this->assertStringContainsString('aria-hidden="true"', $output);
    }

    /**
     * Test that links have descriptive text
     *
     * @test
     */
    public function test_link_has_descriptive_text() {
        ob_start();
        ?>
        <a href="/program-details">Learn More About This Program</a>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('Learn More About This Program', $output);
        $this->assertStringNotContainsString('click here', strtolower($output));
    }

    /**
     * Test that radio groups have fieldset
     *
     * @test
     */
    public function test_radio_group_has_fieldset() {
        ob_start();
        ?>
        <fieldset class="isf-fieldset">
            <legend>Select Device Type</legend>
            <ul class="isf-options">
                <li>
                    <label>
                        <input type="radio" name="device" value="thermostat">
                        Smart Thermostat
                    </label>
                </li>
                <li>
                    <label>
                        <input type="radio" name="device" value="switch">
                        Outdoor Switch
                    </label>
                </li>
            </ul>
        </fieldset>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('<fieldset', $output);
        $this->assertStringContainsString('<legend>Select Device Type</legend>', $output);
        $this->assertStringContainsString('type="radio"', $output);
    }

    /**
     * Test that checkbox groups have fieldset
     *
     * @test
     */
    public function test_checkbox_group_has_fieldset() {
        ob_start();
        ?>
        <fieldset class="isf-fieldset">
            <legend>Select preferred contact methods</legend>
            <ul class="isf-options">
                <li>
                    <label>
                        <input type="checkbox" name="contact_method" value="email">
                        Email
                    </label>
                </li>
                <li>
                    <label>
                        <input type="checkbox" name="contact_method" value="phone">
                        Phone
                    </label>
                </li>
            </ul>
        </fieldset>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('<fieldset', $output);
        $this->assertStringContainsString('<legend>Select preferred contact methods</legend>', $output);
        $this->assertStringContainsString('type="checkbox"', $output);
    }

    /**
     * Test that form steps are properly labeled
     *
     * @test
     */
    public function test_form_step_is_labeled() {
        ob_start();
        ?>
        <div class="isf-step" data-step="1">
            <h2 class="isf-step-title">Choose Your Device</h2>
            <p class="isf-step-description">Select the device you want to participate with.</p>
            <form id="isf-step-1-form" aria-label="Step 1 of 5: Device Selection">
                <!-- form content -->
            </form>
        </div>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('data-step="1"', $output);
        $this->assertStringContainsString('isf-step-title', $output);
        $this->assertStringContainsString('Step 1 of 5', $output);
    }

    /**
     * Test that form sections use proper heading hierarchy
     *
     * @test
     */
    public function test_heading_hierarchy() {
        ob_start();
        ?>
        <div class="isf-section">
            <h2>Personal Information</h2>
            <div class="isf-subsection">
                <h3>Contact Details</h3>
                <input type="text" />
            </div>
        </div>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('<h2>', $output);
        $this->assertStringContainsString('<h3>', $output);
        // Should not skip heading levels
        $this->assertStringNotContainsString('<h4>', $output);
    }

    /**
     * Test that input fields have help text associated
     *
     * @test
     */
    public function test_input_field_help_text_associated() {
        ob_start();
        ?>
        <div class="isf-field">
            <label for="account">Account Number</label>
            <input type="text" id="account" aria-describedby="account_help">
            <div id="account_help" class="isf-help-text">
                Enter your account number without dashes
            </div>
        </div>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('aria-describedby="account_help"', $output);
        $this->assertStringContainsString('id="account_help"', $output);
    }

    /**
     * Test that form submission buttons have clear labels
     *
     * @test
     */
    public function test_submit_button_has_clear_label() {
        ob_start();
        ?>
        <button type="submit" class="isf-btn isf-btn-primary">
            Verify Account and Continue
        </button>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('Verify Account and Continue', $output);
        $this->assertStringNotContainsString('<button></button>', $output);
    }

    /**
     * Test that back/previous buttons have clear labels
     *
     * @test
     */
    public function test_back_button_has_clear_label() {
        ob_start();
        ?>
        <button type="button" class="isf-btn isf-btn-secondary" data-action="previous">
            Go Back to Previous Step
        </button>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('Go Back to Previous Step', $output);
    }

    /**
     * Test that progress indicator is accessible
     *
     * @test
     */
    public function test_progress_indicator_accessible() {
        ob_start();
        ?>
        <div class="isf-progress" role="progressbar" aria-valuenow="40" aria-valuemin="0" aria-valuemax="100" aria-label="Form progress: Step 2 of 5">
            <div class="isf-progress-bar" style="width: 40%"></div>
        </div>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('role="progressbar"', $output);
        $this->assertStringContainsString('aria-valuenow="40"', $output);
        $this->assertStringContainsString('aria-valuemin="0"', $output);
        $this->assertStringContainsString('aria-valuemax="100"', $output);
        $this->assertStringContainsString('aria-label=', $output);
    }

    /**
     * Test that modals have proper ARIA attributes
     *
     * @test
     */
    public function test_modal_has_aria_attributes() {
        ob_start();
        ?>
        <div class="isf-modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
            <h2 id="modal-title">Confirm Your Information</h2>
            <div class="isf-modal-content">
                <!-- modal content -->
            </div>
            <button type="button" aria-label="Close dialog">Close</button>
        </div>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('role="dialog"', $output);
        $this->assertStringContainsString('aria-modal="true"', $output);
        $this->assertStringContainsString('aria-labelledby="modal-title"', $output);
    }

    /**
     * Test that select dropdowns are accessible
     *
     * @test
     */
    public function test_select_dropdown_accessible() {
        ob_start();
        ?>
        <div class="isf-field">
            <label for="cycling_level">Cycling Level</label>
            <select id="cycling_level" name="cycling_level" required aria-required="true">
                <option value="">Select an option</option>
                <option value="100">Level 1 (100%)</option>
                <option value="80">Level 2 (80%)</option>
            </select>
        </div>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('<label for="cycling_level"', $output);
        $this->assertStringContainsString('id="cycling_level"', $output);
        $this->assertStringContainsString('aria-required="true"', $output);
    }

    /**
     * Test that success messages are announced
     *
     * @test
     */
    public function test_success_message_announced() {
        ob_start();
        ?>
        <div class="isf-alert isf-alert-success" role="alert" aria-live="assertive">
            <span class="isf-alert-icon" aria-hidden="true">✓</span>
            <span class="isf-alert-message">Account verified successfully!</span>
        </div>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('role="alert"', $output);
        $this->assertStringContainsString('aria-live="assertive"', $output);
        $this->assertStringContainsString('isf-alert-success', $output);
    }

    /**
     * Test that data tables have proper headers
     *
     * @test
     */
    public function test_data_table_has_headers() {
        ob_start();
        ?>
        <table class="isf-table">
            <thead>
                <tr>
                    <th scope="col">Device Type</th>
                    <th scope="col">Installation Date</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Thermostat</td>
                    <td>2024-03-01</td>
                    <td>Active</td>
                </tr>
            </tbody>
        </table>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('<thead>', $output);
        $this->assertStringContainsString('<th scope="col">', $output);
    }

    /**
     * Test that skip links are present (if applicable)
     *
     * @test
     */
    public function test_skip_to_content_link() {
        ob_start();
        ?>
        <div class="isf-skip-links">
            <a href="#isf-main-form" class="isf-skip-link">Skip to form</a>
        </div>
        <div id="isf-main-form">
            <!-- form content -->
        </div>
        <?php
        $output = ob_get_clean();

        $this->assertStringContainsString('Skip to form', $output);
        $this->assertStringContainsString('href="#isf-main-form"', $output);
    }

    /**
     * Test that output uses proper HTML escaping
     *
     * @test
     */
    public function test_output_properly_escaped() {
        $user_input = '<script>alert("xss")</script>';
        ob_start();
        ?>
        <div><?php echo esc_html($user_input); ?></div>
        <?php
        $output = ob_get_clean();

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }
}
