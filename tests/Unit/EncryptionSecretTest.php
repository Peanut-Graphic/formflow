<?php
/**
 * Behavioral tests for the tagged-secret helpers used to encrypt per-instance
 * feature secrets (Twilio auth token, CRM api_secret, Slack/Teams webhook) at
 * rest inside the settings JSON.
 *
 * decrypt() returns '' on plaintext, so the migration is prefix-tagged:
 * encrypt_secret() writes 'enc:'+ciphertext; decrypt_secret() decrypts tagged
 * values and passes legacy plaintext through unchanged.
 */

namespace ISF\Tests\Unit;

use ISF\Encryption;

final class EncryptionSecretTest extends TestCase
{
    private function enc(): Encryption
    {
        return new Encryption();
    }

    public function test_round_trip(): void
    {
        $enc = $this->enc();
        $stored = $enc->encrypt_secret('super-secret-token');

        $this->assertStringStartsWith('enc:', $stored, 'stored secret must be tagged');
        $this->assertNotSame('super-secret-token', $stored, 'the plaintext must not appear verbatim');
        $this->assertSame('super-secret-token', $enc->decrypt_secret($stored), 'round-trip must recover the value');
    }

    public function test_legacy_plaintext_is_returned_unchanged(): void
    {
        // An install that stored the secret before encryption existed has an
        // untagged plaintext value; it must keep working with no migration.
        $this->assertSame(
            'https://hooks.slack.com/services/T000/B000/xxxx',
            $this->enc()->decrypt_secret('https://hooks.slack.com/services/T000/B000/xxxx')
        );
    }

    public function test_empty_round_trips_to_empty(): void
    {
        $enc = $this->enc();
        $this->assertSame('', $enc->encrypt_secret(''));
        $this->assertSame('', $enc->decrypt_secret(''));
    }

    public function test_encrypt_secret_is_idempotent(): void
    {
        $enc = $this->enc();
        $once = $enc->encrypt_secret('token');
        $twice = $enc->encrypt_secret($once);

        $this->assertSame($once, $twice, 'an already-tagged value must not be double-encrypted');
        $this->assertSame('token', $enc->decrypt_secret($twice), 'and must still decrypt to the original');
    }

    public function test_distinct_secrets_do_not_collide(): void
    {
        $enc = $this->enc();
        $a = $enc->encrypt_secret('AC-token-A');
        $b = $enc->encrypt_secret('AC-token-B');

        $this->assertSame('AC-token-A', $enc->decrypt_secret($a));
        $this->assertSame('AC-token-B', $enc->decrypt_secret($b));
    }
}
