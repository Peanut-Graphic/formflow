# FormFlow Pro Changelog

## 2.9.4 — 2026-05-27

### Fixed — marketplace tables missing on pre-2.9.0 installs

Following the 2.9.2 fix that finally boots the Marketplace singleton on
admin requests, importing a template returned "Failed to import template."
because the `wp_isf_templates` and `wp_isf_marketplace_installed` tables
didn't exist on the site. Marketplace was never instantiated on any
install before 2.9.0, so `create_tables()` had never run.

Two-pronged fix:
- **Bootstrap (existing installs):** on first 2.9.4 admin page load, call
  `Marketplace::create_tables()` once, gated by a new option flag
  `isf_marketplace_tables_v1`. dbDelta is idempotent (CREATE TABLE IF
  NOT EXISTS), and `insert_default_templates()` is internally gated on
  COUNT > 0, so the operation is safe on installs that already have
  the tables — but the flag avoids touching dbDelta on every load.
- **Activator (fresh installs):** `create_tables()` now runs on plugin
  activation alongside the rest of the schema setup. Future fresh
  installs get the tables immediately.

## 2.9.3 — 2026-05-27

### Fixed — F5 / enterprise WAF compatibility

On Itron-hosted sites (e.g., dominionenergyptr.com), an F5 BIG-IP ASM
policy rejects any POST to `/wp-admin/admin-ajax.php` whose `action`
parameter starts with the literal substring `isf_`. Every FormFlow
admin-ajax handler (62 of them) registered under the historical
`wp_ajax_isf_*` prefix, so the entire admin UI returned `403 "Access
denied."` from the WAF: importing templates, saving instances, testing
connections, exporting data — all blocked.

This release aliases every `wp_ajax_isf_*` registration with a parallel
`wp_ajax_formflow_*` registration pointing at the same callback, and
rewrites every bundled JS POST to use the new `formflow_*` action names.

- `wp_ajax_isf_*` registrations preserved (backward-compat for any
  external integration that calls the old action names — on sites
  without an `isf_*` WAF rule, both names work).
- `wp_ajax_formflow_*` parallel registrations added (62 total across
  class-plugin / class-marketplace / class-security-hardening /
  class-business-intelligence / class-form-builder).
- All bundled JS now POSTs `action: 'formflow_*'`: 24 files touched
  including admin.js, form-builder.js, enrollment.js, auto-save.js,
  analytics-integration.js, plus every admin view that builds its own
  AJAX calls (marketplace, instance-editor, destinations-pod, etc.).
- Nonce action name `isf_admin_nonce` unchanged — nonce values never
  traverse the wire as `action=` parameters, so the WAF rule doesn't
  match them. No nonce regeneration needed on upgrade.
- PHP namespaces, class names, option keys, DB table names, file
  paths, and constants are all unchanged. Only wire-facing action
  identifiers were renamed.

Verified end-to-end against the live dominionenergyptr.com WAF:
`action=formflow_template_import` returns 400 "0" (passes WAF, reaches
WP, "no handler" during pre-install testing). After 2.9.3 install, the
new handlers fire normally.

## 2.9.2 — 2026-05-26

### Fixed

- **2.9.1 follow-up — the Templates submenu was still missing.** The
  Marketplace class registers its admin_menu hook inside `init()`, not
  the constructor. Calling `::instance()` alone (as 2.9.1 did) only ran
  the table-name setup; the menu never registered. 2.9.2 calls
  `::instance()->init()` from the bootstrap. Verified locally.

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
