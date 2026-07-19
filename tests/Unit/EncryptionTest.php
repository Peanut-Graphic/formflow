<?php
/**
 * Characterization tests for ISF\Encryption.
 *
 * Written to close a real gap: FormFlow Pro — the PAID tier — had NO tests over
 * its encryption at all, while Lite (free) had twelve. That matters more than
 * usual here because this class guards DATA AT REST: a behaviour change makes
 * already-stored records permanently unreadable, and without tests there was
 * nothing to prove a change was safe.
 *
 * These pin the observable contract (round-trip, format, IV freshness,
 * fail-safe decode) so the crypto path can later be moved into
 * peanut/formflow-core with an equivalence proof instead of hope.
 *
 * @package FormFlow\Tests\Unit
 */

namespace ISF\Tests\Unit;

use Brain\Monkey\Functions;
use ISF\Encryption;

final class EncryptionTest extends TestCase
{
    private Encryption $enc;

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic key material so the round-trip is reproducible.
        Functions\when('wp_salt')->justReturn(str_repeat('k', 64));
        Functions\when('__')->returnArg(1);
        $this->enc = new Encryption();
    }

    public function test_round_trips_plain_text(): void
    {
        $this->assertSame('hello world', $this->enc->decrypt($this->enc->encrypt('hello world')));
    }

    /** @dataProvider payloads */
    public function test_round_trips_awkward_payloads(string $value): void
    {
        $this->assertSame($value, $this->enc->decrypt($this->enc->encrypt($value)));
    }

    public static function payloads(): array
    {
        return [
            'unicode'    => ['héllo wörld 日本語 🎉'],
            'multiline'  => ["line one\nline two\r\nline three"],
            'special'    => ['!@#$%^&*()_+-=[]{}|;:",.<>?/\\'],
            'long'       => [str_repeat('abcdefghij', 500)],
            'json-ish'   => ['{"key":"value","n":[1,2,3]}'],
            'whitespace' => ['   padded   '],
        ];
    }

    public function test_ciphertext_does_not_reveal_plaintext(): void
    {
        $cipher = $this->enc->encrypt('super-secret-value');
        $this->assertNotSame('super-secret-value', $cipher);
        $this->assertStringNotContainsString('super-secret-value', $cipher);
    }

    public function test_ciphertext_is_base64(): void
    {
        $cipher = $this->enc->encrypt('payload');
        $this->assertNotFalse(base64_decode($cipher, true), 'ciphertext must be strict-base64');
    }

    public function test_same_plaintext_encrypts_differently_each_time(): void
    {
        // A fresh IV per call — identical ciphertexts would leak equality of
        // stored values.
        $this->assertNotSame($this->enc->encrypt('same'), $this->enc->encrypt('same'));
    }

    public function test_empty_string_round_trips(): void
    {
        $this->assertSame('', $this->enc->encrypt(''));
        $this->assertSame('', $this->enc->decrypt(''));
    }

    public function test_decrypting_garbage_fails_safe_rather_than_throwing(): void
    {
        $this->assertSame('', $this->enc->decrypt('not-valid-ciphertext'));
        $this->assertSame('', $this->enc->decrypt(base64_encode('too-short')));
    }

    public function test_array_round_trip(): void
    {
        $data = ['name' => 'Ada', 'tags' => ['x', 'y'], 'n' => 42];
        $this->assertSame($data, $this->enc->decrypt_array($this->enc->encrypt_array($data)));
    }

    public function test_hash_and_verify(): void
    {
        $this->assertSame(hash('sha256', 'v'), Encryption::hash('v'));
        $this->assertTrue(Encryption::verify_hash('v', Encryption::hash('v')));
        $this->assertFalse(Encryption::verify_hash('v', Encryption::hash('w')));
    }

    public function test_mask_reveals_only_requested_windows(): void
    {
        $this->assertSame('****5678', Encryption::mask('12345678', 0, 4));
        $this->assertSame('12****78', Encryption::mask('12345678', 2, 2));
    }

    /**
     * Tier-parity guard. Pro must be a superset of Lite; these two capabilities
     * existed ONLY in Lite (free), so paying users got no warning that their
     * data-at-rest key was unconfigured. Regression guard against that
     * inversion returning.
     */
    public function test_pro_has_the_key_configuration_capabilities_lite_has(): void
    {
        $this->assertTrue(method_exists(Encryption::class, 'generate_key'));
        $this->assertTrue(method_exists(Encryption::class, 'register_admin_notices'));

        $key = Encryption::generate_key();
        $this->assertGreaterThanOrEqual(32, strlen($key), 'generated key must satisfy the AES-256 minimum');
    }

    public function test_notice_is_actually_hooked_not_dead_code(): void
    {
        // A registration method nobody calls is a facade. Pin the wiring.
        $src = file_get_contents(dirname(__DIR__, 2) . '/formflow.php');
        $this->assertStringContainsString('Encryption::register_admin_notices()', $src);
    }

    public function test_mask_with_zero_visible_end_reveals_nothing(): void
    {
        // Regression: substr($data, -0) returns the WHOLE string in PHP.
        $masked = Encryption::mask('4111111111111111', 0, 0);
        $this->assertSame(str_repeat('*', 16), $masked);
        $this->assertStringNotContainsString('4111', $masked);
    }
}
