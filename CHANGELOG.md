# FormFlow Pro Changelog

## 3.0.6 — 2026-05-28

### Fixed
- `form_type` column ENUM did not include `'custom'`, so inserting or updating with that value was silently coerced to `''` by MySQL. This is why imported custom-template instances landed with empty `form_type` and the public renderer's `=== 'custom'` branch never fired — the page fell through to the IntelliSOURCE enrollment wizard. Schema migration adds `'custom'` to the ENUM, and the CREATE TABLE definition is updated for fresh installs. The `run_migrations()` routine is invoked on activation; for sites upgrading via the version-drift check added in 3.0.5, the schema migration is now part of the same code path that runs on plugins_loaded.

## 3.0.5 — 2026-05-28

### Added
- Public renderer now branches on `form_type === 'custom'` and renders the form via `FormRenderer` from the saved builder schema. Previously, custom forms fell through to the IntelliSOURCE enrollment wizard, which is why the Dominion PTR form rendered as the wrong UI on `/events/innsbrook-affordability-event/`.

### Fixed
- `isf_dev_mode` capability is now granted on plugin **upgrade**, not only on fresh activation. WP's upgrade-install does not always fire `register_activation_hook`, so admins upgrading from 2.9.x or 3.0.0–3.0.4 weren't getting the cap. A version-drift check in `isf_init()` calls `Capabilities::register_on_activate()` whenever the stored `isf_version` is older than `ISF_VERSION`.

## 3.0.4 — 2026-05-27

### Fixed

- **`ajax_save_instance` fatal after 3.0.3 partial-update.** 3.0.3 made
  `$data` partial (only POSTed columns) but post-construction code
  (duplicate-slug check + audit log) still dereferenced `$data['slug']`,
  `$data['name']`, `$data['utility']`, `$data['form_type']`,
  `$data['is_active']`, `$data['test_mode']` unconditionally. PHP 8
  fatals on undefined-index. Fix: gate duplicate-slug check on
  isset($data['slug']) && slug not empty; audit log falls back to the
  existing row's values for any column $data didn't include via
  `$logged = $data + $existing`.

## 3.0.3 — 2026-05-27

### Fixed

- **`ajax_save_instance` required-field validation broke partial updates.**
  The new form-editor's per-field save-on-blur POSTs only the changed
  field (e.g., `settings[email][from_name]=X`), but the legacy save
  handler validated name+slug+api_endpoint as REQUIRED on every call.
  Save-on-blur returned `{"success":false,"data":{"message":"Please fill in Name and Slug fields."}}`.
  Fix: skip required-field validation when `$id > 0` (update path);
  omit columns from `$data` when not present in `$_POST` so the DB row
  retains its current value. Required-field validation still applies
  on creates (`$id === 0`).

## 3.0.2 — 2026-05-27

### Fixed

- **`formflow_save_instance` fatal on form-editor saves.** The new
  form-editor.js POSTs `settings[a][b]=value` (PHP form-array notation)
  which PHP parses into `$_POST['settings']` as a nested array. Both
  `ajax_save_instance` and `FieldGate::strip_blocked_fields` called
  `json_decode($_POST['settings'])` expecting the legacy JSON-string
  shape. PHP 8 fatals on `json_decode(array)`. Caught during T22 smoke
  test on dominionenergyptr.com — save-on-blur returned HTTP 500.
  Fix: branch on `is_array()` at both sites, preserve original shape
  in the FieldGate output.

## 3.0.1 — 2026-05-27

### Fixed

- **Form editor assets not loading.** `admin/class-admin.php::is_plugin_page()`
  gated `enqueue_styles()` + `enqueue_scripts()` against a hardcoded allowlist
  of known WP admin page hooks. The new `isf-form` hook
  (`ff-forms_page_isf-form`) added in 3.0.0 wasn't in the allowlist, so
  neither the scoped `form-editor.css` nor `form-editor.js` loaded on the
  new editor screen. Result: editor rendered structurally but unstyled,
  and save-on-blur was dead. Caught during T22 smoke test on
  dominionenergyptr.com. Fix: add `ff-forms_page_isf-form` (and the
  legacy `is-forms_page_isf-form` prefix variant defensively) to the
  `$plugin_pages` allowlist.

## 3.0.0 — 2026-05-27

### New — task-oriented form editor

Replaces the 1,300-line instance editor with a task-overview + two-pane
editor pattern, behind an `ISF_NEW_EDITOR` feature flag. Same instance
row, same admin-ajax handlers — only the admin UI is new.

- **Dev mode (9 tasks):** Setup / Form fields / Connector / Delivery /
  Scheduling / Copy / Notifications / Tracking / Advanced. Connector +
  Scheduling auto-grey-out on `form_type=custom`.
- **Client mode (4 tasks):** Delivery / Copy / Notifications / Submissions.
  Granted by *absence* of the new `isf_dev_mode` capability.
- **Mode switcher** in the editor header — users with both caps can flip
  between dev and client views. Persists in user-meta.
- **Status badges** per task: ✓ complete / ⚠ needs attention / ⊙ defaults
  / — not applicable. Driven by per-task validators.
- **Two-pane pattern** with breadcrumb-up navigation, list/detail
  sub-rail, inline status strips (no global notice strips on editor
  screens), sticky action bar with auto-save status.
- **Save-on-blur** per field; debounced 500 ms; sticky Save button
  flushes immediately.
- **Server-side field gate** strips dev-only POST keys for client-mode
  users (defense in depth — UI also hides them).

### Flag + cutover

- `ISF_NEW_EDITOR` constant in wp-config.php or `isf_new_editor` option
  (Tools → New Editor Beta toggle). Default OFF in 3.0.
- 3.1 flips default to ON; old editor remains mounted, redirects to new
  editor when flag is on.
- 3.2 deletes the old editor (`admin/views/instance-editor.php`, its
  inline JS, and the `isf-instance-editor` menu slug).

### Capabilities

- Adds `isf_dev_mode` custom capability. Granted to the Administrator
  role on plugin activation. Revoke per-user via any role-editor plugin
  to lock a client admin into client mode.

### Deferred to 3.0.x

- Scheduling task is a placeholder that links to the legacy editor — full
  port lands in 3.0.1.
- Advanced task is a placeholder for features that aren't yet ported.
- "Editing as: Client" preview banner inside dev mode (so a dev can
  see exactly what a client admin sees) deferred to 3.1.

See: `docs/superpowers/specs/2026-05-27-form-editor-redesign-design.md`

## 2.9.6 — 2026-05-27

### Fixed

- **Post-install redirect landed at "not allowed to access this page".**
  After installing a template, marketplace.php JS redirected to
  `admin.php?page=isf-instances&action=edit&id=N`, but the actual
  registered menu slug is `isf-instance-editor`. The install itself
  worked (instance row created), but the user saw a WP capability
  failure page. Redirect URL corrected. Existing instances are
  reachable normally via FF Forms → Dashboard.

- **Public-side critical error on event pages with form embeds.**
  `includes/analytics/class-gtm-helper.php::get_js_config()` called
  `json_decode($instance['settings'] ?? '{}', true)` without first
  checking whether `$instance['settings']` was already an array.
  Upstream callers sometimes pass the settings pre-decoded (e.g., when
  the renderer reads a freshly inserted instance row whose settings
  were just round-tripped through array handlers). PHP 8 fatals on
  json_decode(array) with `Uncaught TypeError`. Caught live on
  /events/innsbrook-affordability-event/ after the Dominion template
  install. Fix: branch on `is_array()` and only json_decode strings.

## 2.9.5 — 2026-05-27

### Fixed — wp_isf_templates CREATE TABLE silently failed on MariaDB 10.3+

2.9.4 added a bootstrap that runs Marketplace::create_tables() on first
load, gated by an option flag. The bootstrap path executed and set the
flag, but the table never appeared — because `schema` is a reserved
word in MariaDB 10.2+ / MySQL 8+ and the CREATE TABLE declared
`schema JSON NOT NULL` without backticks. dbDelta swallowed the parse
error silently, the flag got set anyway, and subsequent admin loads
short-circuited the create attempt.

Verified live against dominionenergyptr.com (MariaDB 10.3.39):
post-2.9.4, `wpdb->last_error` on the failing import read
`Table 'domengptr_db.wp_isf_templates' doesn't exist`.

Fix:
- Backtick `schema` in the CREATE TABLE in
  `includes/platform/class-marketplace.php`.
- Bump the bootstrap option flag `isf_marketplace_tables_v1 →
  _v2` so existing installs that got the broken v1 flag re-attempt
  create_tables() once with the corrected SQL.
- Activator also updated to set the v2 flag.

`wpdb->insert(['schema' => ...])` already worked because wpdb backticks
column names automatically. Only the dbDelta-parsed CREATE TABLE was
affected by the reserved-word collision.

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
