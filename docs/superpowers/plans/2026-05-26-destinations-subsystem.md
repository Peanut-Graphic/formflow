# FormFlow Pro 2.9.0 — Destinations subsystem + SFTP destination

**Status:** plan / pre-build
**Owner:** Nat (Peanut Graphic)
**Target release:** 2.9.0
**Driver:** Dominion Energy PTR form needs to live on dominionenergyptr.com (WP)
and deliver submissions to Itron via SFTP, without routing through
hub.peanutgraphic.com. Today FormFlow Pro has no SFTP delivery.

---

## Why this is a new subsystem, not a new "connector"

The existing `ApiConnectorInterface` is shaped around synchronous enrollment
APIs: `validate_account()`, `submit_enrollment()`, `get_schedule_slots()`,
`book_appointment()`. That contract is correct for IntelliSOURCE-style
"the form posts to enroll someone" flows — sync, returns a confirmation
number.

SFTP is fundamentally different: a **fire-and-forget delivery channel for
finished submissions**, async, with retry. Shoehorning it into
`ApiConnectorInterface` would force fake stub implementations of
`validate_account` / `book_appointment` on a thing that has nothing to do
with enrollment. That's the "bolted on" anti-pattern CAT flagged elsewhere
in the plugin during the 2026-05-26 audit.

So 2.9.0 introduces a parallel `Destinations` subsystem with its own
interface, registry, and lifecycle. SFTP is the first destination. Future
destinations (S3, email-attachment, webhook-with-retry, manual CSV
download) all share the subsystem cleanly.

A Connector is **where the form posts to enroll someone** (sync, returns a
confirmation, drives the user-facing flow).
A Destination is **where finished submissions get delivered** (async,
fire-and-forget with retry, never visible to the end user).

Most forms will use either one or the other, not both. Dominion PTR uses
only a Destination (call-center handoff via SFTP). IntelliSOURCE forms
use a Connector. Some forms could use both (e.g., enroll via Connector
AND archive a copy via Destination).

---

## Architecture

### New files

```
includes/destinations/
  interface-destination.php          # DestinationInterface contract
  class-destination-registry.php     # mirrors ConnectorRegistry pattern
  class-base-destination.php         # shared helpers: encrypted creds, logging
  class-delivery-result.php          # value object
  class-delivery-worker.php          # Action Scheduler worker
  class-delivery-log.php             # per-submission delivery history

connectors/sftp/                     # implementation lives alongside intellisource/
  loader.php                         # registers SFTP destination on isf_register_destinations
  class-sftp-destination.php         # implements DestinationInterface
  class-sftp-formatter.php           # CSV / JSON / XML serialization
```

### New DB table

```
wp_isf_deliveries
  id                  BIGINT PK
  submission_id       BIGINT FK → wp_isf_submissions
  instance_id         BIGINT FK → wp_isf_instances
  destination_id      VARCHAR(64)         # e.g. "sftp"
  destination_label   VARCHAR(255)        # admin-set name
  status              ENUM('queued','succeeded','failed','retry')
  attempt_count       TINYINT
  last_error          TEXT
  payload_hash        VARCHAR(64)         # sha256 of delivered bytes (for dedupe)
  delivered_at        DATETIME NULL
  created_at          DATETIME
  updated_at          DATETIME
  INDEX (instance_id, status)
  INDEX (submission_id)
```

### Interface (sketch)

```php
namespace ISF\Destinations;

interface DestinationInterface {
    public function get_id(): string;              // 'sftp'
    public function get_name(): string;            // 'SFTP'
    public function get_description(): string;
    public function get_version(): string;

    /** Field definitions for the per-instance admin UI. */
    public function get_config_fields(): array;

    /** Validate admin-submitted config. Returns array of error strings (empty = OK). */
    public function validate_config(array $config): array;

    /** Live connection test from the admin "Test Connection" button. */
    public function test_connection(array $config): array;

    /** The actual delivery. Synchronous; called from the AS worker. */
    public function deliver(array $submission, array $config): DeliveryResult;

    /** Supported features: ['retry', 'purge_after_export', 'batch']. */
    public function get_supported_features(): array;
}
```

### Submission → delivery flow

```
1. User submits form
2. Submission stored in wp_isf_submissions (existing behavior)
3. New hook: do_action('isf_submission_completed', $submission, $instance)
4. Destination dispatcher reads instance['settings']['destinations'][] array
5. For each active destination: insert wp_isf_deliveries row (status=queued),
   enqueue Action Scheduler job 'isf_deliver_submission' with delivery_id
6. AS worker pops the job → loads delivery row → loads destination + config
   → calls $destination->deliver($submission, $config)
7. Worker updates wp_isf_deliveries row with result (succeeded | failed)
8. On failure: schedule retry with exponential backoff (1m, 5m, 30m).
   Max 3 retries → permanent fail, fire isf_delivery_failed action for alerting.
9. On success: if instance has purge_after_export=true, delete submission row.
10. If Action Scheduler is unavailable: fall back to synchronous delivery
    in-request. (FormFlow Pro already has Action-Scheduler-or-fallback
    pattern in class-queue-manager.php — reuse it.)
```

---

## SFTP destination specifics

### Dependencies

- `phpseclib/phpseclib:^3.0` (pure PHP, no PECL ssh2 required, ~1.5MB
  vendored). First runtime composer dep for FormFlow Pro — verify
  `vendor/autoload.php` is loaded from `formflow.php` bootstrap and that
  vendor/ ships in the release zip (memory `feedback-wp-plugin-zip-excludes-vendor.md`
  is about excluding **dev** deps; runtime deps must ship).

### Config fields

| Field | Type | Notes |
|---|---|---|
| `host` | text | hostname or IP |
| `port` | number | default 22 |
| `username` | text | |
| `auth_mode` | select | `password` \| `key` |
| `password` | password | shown only when auth_mode=password. Stored encrypted (`ISF_ENCRYPTION_KEY`). Never re-emit value to DOM after save. |
| `private_key` | textarea | shown only when auth_mode=key. Stored encrypted. Same DOM rules. |
| `private_key_passphrase` | password | optional, encrypted |
| `remote_path` | text | default `/` |
| `filename_template` | text | tokens: `{date:Y-m-d}`, `{slug}`, `{submission_id}`, `{instance_name}`. Default: `{date:Ymd}_{slug}_{submission_id}.csv` |
| `format` | select | `csv` \| `json` \| `xml` (default csv) |
| `csv_include_header` | checkbox | default on |
| `purge_after_export` | checkbox | per-destination; matches HUB Dominion behavior |
| `host_key_fingerprint` | text | optional; if set, reject connections to a different fingerprint (MITM defense) |

### Security

- Creds encrypted at rest via `ISF\Encryption::encrypt()` (existing module,
  uses `ISF_ENCRYPTION_KEY`). The 2.8.7 notice telling admins to set
  `ISF_ENCRYPTION_KEY` becomes load-bearing for SFTP — surface that more
  prominently on the SFTP destination's config screen if the key falls
  back to AUTH_KEY.
- Password / private-key form fields: input type=password, autocomplete=off,
  never re-emit existing value on render (admin must paste again to change).
- Sanitize remote_path: must start with `/`, no `..`, no shell metacharacters.
- Filename template: tokens are whitelisted; literal `..`, `/`, null bytes
  rejected.
- Log delivery failures with redacted creds (no password / no key bytes).
- Optional `host_key_fingerprint` for stricter trust. Without it, accept
  on first connect and log the fingerprint.

### Errors

The delivery worker classifies failures into:
- Transient (network, timeout) → retry per backoff schedule
- Auth failure → no retry, alert immediately (creds are wrong)
- Path/permission error → no retry, alert (config is wrong)
- Quota / disk full → retry once, then alert

Each class maps to a specific `last_error` code in the deliveries table so
the admin UI can surface readable status.

---

## Admin UI

New "Destinations" pod in `admin/views/instance-editor.php`, placed in the
API panel for now (later may move to its own wizard step). Visible only
when Form Type = `custom` for 2.9.0 — the existing IntelliSOURCE Form
Types continue to use the Connector API. (Future: allow destinations on
any form type as an "archive copy" mechanism.)

Pod layout:

```
┌── Destinations ──────────────────────────────────────────────┐
│  [+] Add destination ▼                                       │
│      • SFTP                                                  │
│                                                              │
│  ┌── SFTP: Dominion Energy PTR ─────── [edit] [test] [×] ──┐│
│  │  host: sftp.itron.example.com  port: 22                 ││
│  │  user: dominion_ptr_intake                              ││
│  │  auth: SSH key   path: /incoming/                       ││
│  │  format: csv     purge after export: ✓                  ││
│  │  last delivery: 12 min ago — succeeded                  ││
│  └─────────────────────────────────────────────────────────┘│
└──────────────────────────────────────────────────────────────┘
```

"Test connection" hits an AJAX endpoint that calls
`$destination->test_connection($config)` (login + ls of remote_path; does
not upload a test file). Returns success or specific error.

A new "Deliveries" tab in Data & Analytics shows per-submission delivery
history with status, retry count, and last error.

---

## Dominion PTR template (`~/Desktop/dominion-ptr-form.formflow.json`)

The 2.9.0 release of the template adds:

```json
{
  "destinations": [
    {
      "type": "sftp",
      "name": "Dominion Energy PTR SFTP",
      "config": {
        "host": "",
        "port": 22,
        "username": "",
        "auth_mode": "password",
        "password": "",
        "remote_path": "/",
        "filename_template": "dominion_ptr_{date:Ymd}_{date:His}.csv",
        "format": "csv",
        "csv_delimiter": ",",
        "csv_quote_mode": "rfc4180",
        "csv_line_ending": "crlf",
        "csv_include_header": true,
        "csv_encoding": "utf-8",
        "boolean_representation": "yes_no",
        "purge_after_export": true
      }
    }
  ]
}
```

Credentials left blank intentionally — admin fills them in on install.
Importer creates the destination row with creds empty and `is_active=false`
until admin saves valid config (matches HUB behavior).

---

## Smoke-test plan (against Itron sandbox before flipping prod)

1. Install FormFlow Pro 2.9.0 zip on dominionenergyptr.com (overwrite-install,
   never Delete-then-reupload per `feedback-wp-plugin-never-delete-then-reupload`).
2. Import `dominion-ptr-form.formflow.json` via Templates → Import/Export.
3. Install the template as a live form instance.
4. In the Destinations pod, fill in real Itron sandbox SFTP creds.
5. Click Test Connection → expect success.
6. Submit a full test enrollment from the public form.
7. Verify the file lands at Itron's sandbox path with correct filename
   pattern, correct CSV contents (all 15 fields present, correct quoting,
   correct line endings).
8. Confirm `wp_isf_deliveries` row recorded `status=succeeded`.
9. Confirm submission row was purged (purge_after_export was on).
10. Negative test: submit with deliberately wrong creds → confirm
    `status=failed` + readable error in admin UI.
11. Negative test: simulate network timeout (block port 22 briefly) →
    confirm retry occurs and eventually succeeds on retry.
12. Once Itron prod creds are wired and a sandbox dry-run passes:
    cut page 792 over (replace HUB iframe with `[isf_form id="…"]`
    shortcode, give the page a real slug like `/enroll/`, publish).
    Deactivate the HUB-side `peak-time-rebates` / `dominion` form.

---

## Out of scope for 2.9.0 (defer to follow-ups)

- Other destination types (S3, email-attachment, webhook-with-retry,
  manual CSV download from admin)
- Move destinations to their own wizard step (currently nested under API
  panel)
- Batched/scheduled delivery (e.g., daily roll-up CSV instead of
  per-submission). 2.9.0 ships per-submission only.
- Field-level redaction / PII tokenization on the way out
- The deeper IntelliSOURCE rebrand (CAT's C3): namespace, slug, preset
  list, copy. Real work, separate release.
- Dashboard "Device" column conditional rendering (CAT's C4)
- Content-step placeholder copy scrub (CAT's C5)

---

## Open questions

- ~~**Itron's actual SFTP auth mode**~~ — **resolved 2026-05-26:** Itron
  themselves are not sure which auth mode they want. Build both
  password + SSH-key auth in the SFTP destination from day one (not a
  defensive hedge — the actual answer is "either"). Admin picks at
  setup time per Itron's eventual direction.
- **Itron's expected file format / column spec** — Nat is getting this
  from Itron tonight (2026-05-26). Assumed CSV based on HUB's current
  Dominion connector behavior, but column order, delimiter, quoting,
  line endings, header row presence, filename pattern, and any required
  envelope all need to match Itron's intake spec exactly. Update this
  doc + `class-sftp-formatter.php` config defaults when the spec lands.
- **`ISF_ENCRYPTION_KEY` on dominionenergyptr.com** — must be set in
  wp-config.php before SFTP destination creds are entered, otherwise
  encryption falls back to AUTH_KEY (the 2.8.7 notice flagged this).
  Surface this dependency in the SFTP destination admin UI if the key
  is unset.

## Blocked-on-spec checklist (resolve before smoke-testing prod)

Once Itron's spec arrives, confirm or adjust:

- [x] Auth mode: **either accepted** (Itron undecided; we support both)
- [ ] Host + port + username + remote path — pending from Itron
- [x] File format: **CSV** (confirmed 2026-05-26)
- [x] CSV delimiter: **comma** (confirmed 2026-05-26)
- [x] CSV quoting rules: **RFC 4180** (default — double-quote only when
      value contains comma, double-quote, CR, or LF; escape embedded
      double-quotes by doubling. Tunable in SFTP destination config if
      Itron later specifies otherwise.)
- [x] Line endings: **CRLF** (RFC 4180 default; tunable in config)
- [x] Header row present? **yes** (confirmed 2026-05-26)
- [x] Exact column order: **as defined in the 15-field schema** —
      first_name, last_name, street_address, address_line_2, city,
      state, zip, email, confirm_email, phone, consent_to_call,
      terms_accepted, submission_id, submitted_at, instance_slug.
      (Order matches `ConfigureDominionForm.php` field array + appended
      metadata.)
- [x] Exact column names: **as defined** — snake_case header row
      matching field `name` attributes.
- [x] Filename pattern: **`dominion_ptr_YYYYMMDD_HHMMSS.csv`**
      (confirmed 2026-05-26). Template:
      `dominion_ptr_{date:Ymd}_{date:His}.csv`.
- [x] Required envelope/wrapper: **none** (confirmed 2026-05-26)
- [x] Encoding: **UTF-8** (no BOM; confirmed 2026-05-26)
- [x] Boolean rep (consent_to_call, terms_accepted): **yes / no**
      (lowercase, confirmed 2026-05-26)
- [ ] Any field needed that's NOT in the current 15-field form? —
      pending from Itron
- [ ] Any field in the form to OMIT from the export? — pending from
      Itron (default: include all)
- [ ] Test sandbox available? — **not yet** (2026-05-26). Will need a
      sandbox endpoint before flipping prod page 792 to the FormFlow
      shortcode. Until then, smoke-test #10 from the task list runs
      against a self-hosted SFTP server (`docker run atmoz/sftp`) to
      validate the formatter and delivery worker end-to-end.

---

## Estimate

~2 working days end-to-end:
- 0.5d: scaffolding (interface, registry, base class, DB table, plan)
- 0.5d: SFTP implementation + phpseclib3 + formatter
- 0.5d: delivery worker, retry, admin UI, AJAX test-connection
- 0.5d: importer template wiring, packaging, smoke-test against sandbox

CAT + MAX reviewed the upstream 2.8.7 patches; want another review pass
on this build before 2.9.0 ships.
