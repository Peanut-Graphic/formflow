# SFTP standard fingerprint compatibility — 2026-10-16

Separate from the 4.2.3 privacy release: correct host-key SHA-256 calculation and document exact accepted formats. Existing fail-closed behavior is retained, with key parsing before comparison. Locked phpseclib 4.0.2 and PHP 8.4: 24 SFTP tests / 101 assertions pass; five regression cases fail on old source. Public fixture verified with ssh-keygen; no network SFTP endpoint or version bump.

