# SFTP standard fingerprint compatibility — 2026-10-16

Separate PR included in the 4.2.3 privacy release: correct host-key SHA-256 calculation and document exact accepted formats. Existing fail-closed behavior is retained, with key parsing before comparison. Locked phpseclib 4.0.2 and PHP 8.4: 24 SFTP tests / 101 assertions pass; five regression cases fail on old source. Public fixture verified with ssh-keygen; no network SFTP endpoint or version bump. After merging main at 3076b62470310e8535f08c0f23417b7ec31d9b23, exact-lock PHP 8.4 full suite passes (608 tests / 2,661 assertions), and focused SFTP remains 24 tests / 101 assertions.

