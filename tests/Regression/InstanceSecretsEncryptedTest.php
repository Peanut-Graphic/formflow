<?php
/**
 * Regression guard: per-instance feature secrets stay encrypted at rest.
 *
 * The Twilio auth token, CRM api_secret, and Slack/Teams webhook URL live in
 * plaintext inside the settings JSON (unlike api_password, which has its own
 * encrypted column). This pins the three halves of the fix so a later refactor
 * can't silently regress it:
 *   1. every read site decrypts via decrypt_secret() (tolerant of legacy plaintext);
 *   2. the save path encrypts them via encrypt_secret();
 *   3. the admin fields never echo the stored value back into the page.
 * The helper behaviour itself is covered by tests/Unit/EncryptionSecretTest.
 */

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class InstanceSecretsEncryptedTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function test_sms_token_is_read_via_decrypt_secret(): void
    {
        $s = $this->src('includes/class-sms-handler.php');
        $this->assertStringContainsString("decrypt_secret(\$sms_config['auth_token']", $s, 'live SMS send must decrypt the token');
        $this->assertStringNotContainsString("substr(\$auth_token, 4)", $s, 'the ad-hoc enc: slicing should be gone, replaced by decrypt_secret');
    }

    public function test_crm_secret_is_read_via_decrypt_secret(): void
    {
        $s = $this->src('includes/class-crm-integration.php');
        // Both OAuth paths must use the tolerant reader, not a bare decrypt().
        $this->assertSame(
            2,
            substr_count($s, "decrypt_secret(\$config['api_secret']"),
            'both CRM token paths must read api_secret via decrypt_secret'
        );
        $this->assertStringNotContainsString("decrypt(\$config['api_secret'])", $s, 'no bare decrypt() left (it breaks on the stored plaintext)');
    }

    public function test_team_webhook_is_read_via_decrypt_secret(): void
    {
        $s = $this->src('includes/class-team-notifications.php');
        $this->assertSame(
            2,
            substr_count($s, "decrypt_secret(\$config['webhook_url']"),
            'both team-notification paths must read the webhook via decrypt_secret'
        );
    }

    public function test_save_path_encrypts_feature_secrets(): void
    {
        $s = $this->src('admin/class-admin.php');
        // The real encryption assignment, not just a mention of the method.
        $this->assertMatchesRegularExpression(
            '/\$settings\[.features.\]\[\$feature\]\[\$key\]\s*=\s*\$secret_encryptor->encrypt_secret\(\$new_val\)/',
            $s,
            'the save path must actually encrypt a newly entered feature secret'
        );
        foreach (["'sms_notifications'", "'crm_integration'", "'team_notifications'"] as $feature) {
            $this->assertStringContainsString($feature, $s, "save path must cover {$feature}");
        }
    }

    public function test_admin_fields_do_not_echo_the_stored_secret(): void
    {
        $partials = [
            'admin/views/partials/feature-config-sms_notifications.php'  => 'auth_token',
            'admin/views/partials/feature-config-crm_integration.php'    => 'api_secret',
            'admin/views/partials/feature-config-team_notifications.php' => 'webhook_url',
        ];
        foreach ($partials as $rel => $key) {
            $s = $this->src($rel);
            $this->assertStringNotContainsString(
                "value=\"<?php echo esc_attr(\$settings['{$key}']",
                $s,
                "{$rel} must not render the stored {$key} into the field value"
            );
        }
    }
}
