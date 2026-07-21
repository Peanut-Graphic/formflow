<?php
/**
 * WifiGateAdminSettingTest — the toggle has to be reachable and persistable.
 *
 * The gate defaults to off, which is the safe default but also the failure
 * mode nobody notices: if the admin screen never renders the checkbox, or the
 * save path drops it, the feature is shipped, tested, green, and permanently
 * inert on every instance. Only a support ticket would reveal it.
 *
 * @package FormFlow
 */

namespace ISF\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class WifiGateAdminSettingTest extends TestCase
{
    public function test_instance_editor_renders_the_toggle(): void
    {
        $editor = (string) file_get_contents(ISF_PLUGIN_DIR . 'admin/views/instance-editor.php');

        $this->assertMatchesRegularExpression(
            '/name=["\']require_wifi["\']/',
            $editor,
            'There is no way to turn the gate on from the admin screen, so it can '
            . 'never be enabled for Pepco or Delmarva.'
        );
    }

    public function test_toggle_reflects_the_stored_value(): void
    {
        $editor = (string) file_get_contents(ISF_PLUGIN_DIR . 'admin/views/instance-editor.php');

        $this->assertMatchesRegularExpression(
            "/checked\(\s*\\\$instance\['settings'\]\['require_wifi'\]/",
            $editor,
            'The checkbox does not reflect the saved value, so it reads as off every '
            . 'time the page loads and an admin cannot tell whether it is on.'
        );
    }

    public function test_save_path_persists_the_toggle(): void
    {
        $admin = (string) file_get_contents(ISF_PLUGIN_DIR . 'admin/class-admin.php');

        $this->assertMatchesRegularExpression(
            "/'require_wifi'\s*=>/",
            $admin,
            'The save handler never writes require_wifi, so ticking the box does nothing.'
        );
    }

    /**
     * An unchecked HTML checkbox submits nothing at all, and some flows post
     * "0". Both must persist as off, exactly as demo_mode already handles.
     */
    public function test_save_path_treats_absent_and_zero_as_off(): void
    {
        $admin = (string) file_get_contents(ISF_PLUGIN_DIR . 'admin/class-admin.php');

        $found = preg_match("/'require_wifi'\s*=>\s*([^\n]+)/", $admin, $m);
        $this->assertSame(1, $found, 'Could not locate the require_wifi save expression.');

        $this->assertStringContainsString(
            '!empty($_POST[\'require_wifi\'])',
            $m[1],
            'An unchecked checkbox posts nothing; the save must treat absence as off.'
        );
        $this->assertStringContainsString(
            "!== '0'",
            $m[1],
            'A posted "0" is truthy as a PHP string and must be treated as off, the '
            . 'same way demo_mode already does.'
        );
    }
}
