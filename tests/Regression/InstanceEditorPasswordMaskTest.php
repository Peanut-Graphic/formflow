<?php
/**
 * Regression guard: the instance editor must never render the API password.
 *
 * instance-editor.php echoed the stored IntelliSource API password straight
 * into the field's value attribute (`value="<?php echo esc_attr($instance
 * ['api_password'])`). The stored value is the decrypted secret, so anyone who
 * viewed source, shared their screen, or hit a cached/proxied copy of the admin
 * page saw the live API credential. The field must always render blank; the
 * save path already keeps the existing value when the field is left empty.
 *
 * Asserted at the source level: the template is a full admin-page fragment with
 * many render-time dependencies, so a value invariant on the source is the
 * proportionate guard.
 */

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class InstanceEditorPasswordMaskTest extends TestCase
{
    private function template(): string
    {
        $path = dirname(__DIR__, 2) . '/admin/views/instance-editor.php';
        $src = file_get_contents($path);
        $this->assertIsString($src, 'instance-editor.php must be readable');

        return $src;
    }

    public function test_template_never_outputs_the_stored_password(): void
    {
        $this->assertStringNotContainsString(
            "\$instance['api_password']",
            $this->template(),
            'The decrypted API password must never be echoed into the admin page.'
        );
    }

    public function test_password_field_renders_empty(): void
    {
        $src = $this->template();

        // Isolate the api_password <input ...> tag.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="api_password"[^>]*>/s',
            $src,
            'The api_password input must exist.'
        );
        preg_match('/<input[^>]*name="api_password"[^>]*>/s', $src, $m);
        $input = $m[0];

        $this->assertMatchesRegularExpression(
            '/value=""/',
            $input,
            'The api_password field must render with an empty value.'
        );
        $this->assertStringContainsString(
            'autocomplete="new-password"',
            $input,
            'The api_password field should opt out of browser autofill.'
        );
    }
}
