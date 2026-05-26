# FormFlow Pro Changelog

## 2.9.1 — 2026-05-26

### Fixed

- **Templates submenu was orphaned since 2.7.x** — the `Marketplace` class
  registered `admin_menu` priority 25 in its constructor, but nothing in
  the bootstrap ever called `Marketplace::instance()`, so the constructor
  never ran and the submenu never rendered. The 2.9.0 rename to "Templates"
  was a label change on dead code; visible effect was zero. Booting the
  singleton explicitly in `formflow.php` fixes it. Templates → Import/Export
  is now reachable for the Dominion PTR template import.

## 2.9.0 — 2026-05-26

### New: Destinations subsystem (asynchronous submission delivery)

FormFlow Pro now has a parallel "Destinations" subsystem for fire-and-forget
delivery of completed submissions to external endpoints. Distinct from
Connectors (synchronous enrollment APIs) — a Destination is where finished
submissions get *delivered*, with retry semantics, after the user-facing
form flow is done.

- **SFTP destination** — full implementation. Password + SSH-key auth,
  encrypted credential storage (AES-256-CBC keyed off `ISF_ENCRYPTION_KEY`
  with auth-salt fallback), optional host-key fingerprint pin, CSV / JSON
  / XML output with RFC 4180 CSV defaults, configurable boolean
  representation (yes/no, Y/N, true/false, 1/0), filename templating
  (`{date:Ymd}`, `{slug}`, `{submission_id}`, `{ext}`, …), purge-after-export.
- **Async delivery with retry** — built on Action Scheduler. Exponential
  backoff (1m → 5m → 30m, max 4 attempts). Failure categories drive retry
  policy: transient/quota retried; auth/config/payload errors halt immediately
  and alert. Synchronous fallback when Action Scheduler is unavailable.
- **Per-submission delivery log** — new `wp_isf_deliveries` table tracks
  queued → succeeded/failed/retry status with payload hash + last error
  per attempt.
- **Admin UI** — "Destinations" pod in the instance editor. Fields rendered
  from the destination's `get_config_fields()` metadata, so future
  destination types plug in without view changes. Live "Test Connection"
  button (AJAX → `$destination->test_connection()`). Sensitive fields are
  type=password and never re-emit stored values to the DOM (paste-to-change
  pattern; blank submit preserves stored ciphertext).

### Other safety / IA improvements

- **Instance-editor IA polish**: sticky in-page section nav for Quick Edit
  mode, calmer panel dividers + tighter vertical rhythm. Editor screens are
  no longer interrupted by encryption-key / Action Scheduler admin notices
  (those notices remain on Dashboard / Tools, where they belong).
- **Mode Settings warning**: explicit banner + danger styling on the Demo
  Mode card so admins are unambiguously warned it returns mock data and
  must be off on live forms.
- **Default support phone scrubbed**: removed hardcoded Delmarva/Comverge
  `1-866-353-5799` fallback from all 9 locations. Admin now sees a neutral
  placeholder; empty default. Prevents non-Delmarva instances from
  inheriting the wrong support line.
- **Marketplace → Templates rename**: storefront-fixture cards now hidden by
  default behind `ISF_MARKETPLACE_STOREFRONT` constant. Default tab is
  Import/Export — the only marketplace surface backed by real functionality.
  Local + imported templates still render normally.

### Dependencies

- Adds runtime dependency: `phpseclib/phpseclib ^3.0` (pure-PHP SFTP — no
  PECL ssh2 required). Vendor footprint ≈ 3.1 MB (phpseclib3 + paragonie/
  constant_time_encoding + paragonie/random_compat). Release zip now
  includes `vendor/` (composer install --no-dev).

### Database

New `wp_isf_deliveries` table (created via `dbDelta` on activation / upgrade).
No data migrations required from 2.8.x.

### Notes

This release also retires the 2.8.7-only baseline commit set (IA + safety
patches) by folding all of it into 2.9.0. There is no 2.8.7 release zip.

### Hooks fired by the Destinations subsystem

For external integrations:
- `isf_register_destinations` ($registry)
- `isf_destination_registered` ($id, $destination)
- `isf_delivery_queued` ($delivery_id, $submission_id, $instance_id, $destination_id)
- `isf_delivery_succeeded` ($delivery_id, $submission_id, $instance_id, $destination_id)
- `isf_delivery_failed` ($delivery_id, $submission_id, $instance_id, $destination_id, $message, $failure_kind)
- `isf_delivery_retry_scheduled` ($delivery_id, $attempt, $delay, $failure_kind)
- `isf_submission_purged_after_export` ($submission_id, $instance_id)
