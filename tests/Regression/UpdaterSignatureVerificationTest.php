<?php
/**
 * Regression guard: updater must verify our release package's Ed25519 signature.
 *
 * On top of the interim host-pin (see UpdaterPackageHostPinTest), the updater
 * now downloads our own release package, fetches the "<asset>.manifest.json"
 * shipped alongside it, and refuses to install unless the package's sha256 AND
 * detached Ed25519 signature both check out against the embedded Peanut signing
 * public key. Behaviour is FAIL-CLOSED: a missing manifest, a wrong hash, or a
 * bad/absent signature aborts the update.
 *
 * A live download is impractical to unit-test, so this proves the cryptographic
 * LOGIC in isolation via the pure, key-parameterised core
 * Updater::verify_bytes_with_key(). It exercises:
 *   (a) a correctly-signed payload verifies TRUE (round-trip with a throwaway
 *       keypair — the production private key is not in this repo),
 *   (b) a tampered payload / wrong sha256 verifies FALSE,
 *   (c) a missing signature (and missing sha256) verifies FALSE,
 * plus that the production-keyed verify_bytes() rejects anything not signed by
 * the real Peanut key (fail-closed).
 *
 * Self-contained: only global PHP (hash/hash_equals/libsodium) is used; no
 * booted WordPress or Brain Monkey required.
 */

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;
use ISF\Updater;

final class UpdaterSignatureVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            $this->markTestSkipped('libsodium not available.');
        }
    }

    /**
     * Build a manifest that correctly describes $bytes, signed by $secretKey.
     */
    private static function signedManifest(string $bytes, string $secretKey): array
    {
        return [
            'sha256'    => hash('sha256', $bytes),
            'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secretKey)),
        ];
    }

    /** (a) A correctly-signed payload verifies true. */
    public function test_correctly_signed_payload_verifies_true(): void
    {
        $keypair   = sodium_crypto_sign_keypair();
        $secret    = sodium_crypto_sign_secretkey($keypair);
        $publicB64 = base64_encode(sodium_crypto_sign_publickey($keypair));

        $bytes    = random_bytes(2048);
        $manifest = self::signedManifest($bytes, $secret);

        $this->assertTrue(
            Updater::verify_bytes_with_key($bytes, $manifest, $publicB64),
            'A payload signed by the trusted key must verify.'
        );
    }

    /** (b) A tampered payload (bytes changed after signing) verifies false. */
    public function test_tampered_payload_verifies_false(): void
    {
        $keypair   = sodium_crypto_sign_keypair();
        $secret    = sodium_crypto_sign_secretkey($keypair);
        $publicB64 = base64_encode(sodium_crypto_sign_publickey($keypair));

        $bytes    = random_bytes(2048);
        $manifest = self::signedManifest($bytes, $secret);

        // Flip one byte after signing: sha256 no longer matches (and neither
        // would the signature over the original bytes).
        $tampered = $bytes;
        $tampered[0] = $tampered[0] === "\x00" ? "\x01" : "\x00";

        $this->assertFalse(
            Updater::verify_bytes_with_key($tampered, $manifest, $publicB64),
            'A tampered payload must be rejected.'
        );
    }

    /** (b') A wrong sha256 (correct signature) verifies false. */
    public function test_wrong_sha256_verifies_false(): void
    {
        $keypair   = sodium_crypto_sign_keypair();
        $secret    = sodium_crypto_sign_secretkey($keypair);
        $publicB64 = base64_encode(sodium_crypto_sign_publickey($keypair));

        $bytes    = random_bytes(2048);
        $manifest = self::signedManifest($bytes, $secret);
        $manifest['sha256'] = hash('sha256', 'something-else');

        $this->assertFalse(
            Updater::verify_bytes_with_key($bytes, $manifest, $publicB64),
            'A manifest whose sha256 does not match the bytes must be rejected.'
        );
    }

    /** (b'') A signature made by a DIFFERENT key verifies false. */
    public function test_signature_from_wrong_key_verifies_false(): void
    {
        $signer = sodium_crypto_sign_keypair();
        $bytes  = random_bytes(2048);
        $manifest = self::signedManifest($bytes, sodium_crypto_sign_secretkey($signer));

        // Verify against an unrelated public key.
        $other      = sodium_crypto_sign_keypair();
        $otherPubB64 = base64_encode(sodium_crypto_sign_publickey($other));

        $this->assertFalse(
            Updater::verify_bytes_with_key($bytes, $manifest, $otherPubB64),
            'A signature by the wrong private key must be rejected.'
        );
    }

    /** (c) A missing signature verifies false. */
    public function test_missing_signature_verifies_false(): void
    {
        $bytes = random_bytes(64);

        $this->assertFalse(
            Updater::verify_bytes_with_key($bytes, ['sha256' => hash('sha256', $bytes)], base64_encode(random_bytes(32))),
            'A manifest with no signature must be rejected.'
        );
    }

    /** (c') A missing sha256 verifies false. */
    public function test_missing_sha256_verifies_false(): void
    {
        $bytes = random_bytes(64);

        $this->assertFalse(
            Updater::verify_bytes_with_key($bytes, ['signature' => base64_encode(random_bytes(64))], base64_encode(random_bytes(32))),
            'A manifest with no sha256 must be rejected.'
        );
    }

    /** Fail-closed: the production-keyed verify_bytes() rejects unsigned/foreign payloads. */
    public function test_production_key_rejects_unsigned_payload(): void
    {
        $updater = new Updater();
        $bytes   = random_bytes(1024);

        // Signed by a throwaway key, NOT the embedded production key.
        $rogue    = sodium_crypto_sign_keypair();
        $manifest = self::signedManifest($bytes, sodium_crypto_sign_secretkey($rogue));

        $this->assertFalse(
            $updater->verify_bytes($bytes, $manifest),
            'verify_bytes() must reject a package not signed by the Peanut key.'
        );
    }
}
