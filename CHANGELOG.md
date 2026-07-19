# FormFlow Pro Changelog

## Unreleased (Dominion PTR)

### Added

- **Dominion Peak Time Rebates connector (`dominion-ptr`), ported from FormFlow Lite.** dominionenergyptr.com runs FormFlow **Pro**, but the Dominion connector shipped only in **Lite** (Lite PR #23) — the connector and the live site were in different plugins, so nothing Pro shipped could serve Dominion. This closes that gap. Speaks Dominion's IntelliSource **JSON** API (`prospect/validate`, `portal_user_emails`, `cep_configurations`) under `/ptr/residential/api`. The XML `intellisource` connector serving Energy Wise (Pepco/Delmarva) is untouched.
- **`ApiClient::is_safe_outbound_url()`** — the shared anti-SSRF guard from Lite, so connectors share one implementation instead of hand-rolling their own. Rejects non-http(s) schemes and any host resolving to loopback/private/link-local/reserved space; fails closed on unresolvable hosts. The IntelliSource connector's older private `assert_safe_request_url()` is equivalent and left in place.
- 45 tests covering the connector, its device-less step list, field normalisation, validate parsing (including a non-fatal portal-lookup outage), the test-mode demo path, the seeder row, and the SSRF guard.

### Changed

- **`EnrollmentResult` / `BookingResult`: added `get_error_code()` and `get_error_message()`.** Both already had populated public `$error_code`/`$error_message` properties but no getters, while Lite's identical DTOs had them — a real portability break for any connector moved between the plugins. Purely additive.
- **`SchedulingResult` now distinguishes a connector-reported status from a parse failure.** It previously treated *any* payload without a `message` key as `'Unexpected response format'`, so a connector saying "scheduling is not applicable to this program" was indistinguishable from malformed Energy Wise XML. It now honours an explicit `error_code`/`error_message` and exposes `get_error_code()` / `is_successful()`. **The Energy Wise XML parse path is unchanged.**

### Notes

- **PTR enrollment is not live.** `submit_enrollment()` returns `not_implemented` outside test mode. Stage 2 is blocked on Itron confirming the enroll endpoint, whether `prospect_verifications` is mandatory, and the IP allowlist for the marketing server (`10.29.84.71`). See `peanut-meta/itron-ptr-api-request-email.md`.
- PTR is a rate/bill-credit program with no device and no install, so `get_schedule_slots()` and `book_appointment()` report `unsupported` by design, not as pending work.
- Stage 2 (the `powerportal-json` base + verification/hand-off scaffold) follows in #60. It was ported here from Lite's PR #24, which is now closed; Lite's copy of the connector is removed in formflow-lite#29, so Dominion has exactly one home.

## 4.0.8 — 2026-07-05 (delivery fix)

### Fixed

- **Trust GitHub as an update-package host.** The 4.0.7 updater host-pin only allowed peanutgraphic.com, but the license server's unified update endpoint now serves the canonical GitHub-release package — so 4.0.7 would have refused its own updates. The updater now trusts both hosts.

### Security

- **Verify update-package signatures.** Downloads are now checked against an Ed25519 signature manifest (`<asset>.manifest.json`) before install; a tampered or unsigned package from our update host is refused.

## 4.0.7 — 2026-07-05 (security)

### Security (microscope remediation)

- Escaped every field in the admin submission-detail view (stored XSS) and neutralized CSV formula injection on the submissions and attribution exports.
- Validated handoff redirect destinations (block javascript:/malformed) and rate-limited the anonymous handoff writes.
- Trusted-proxy client-IP resolution so the public-form rate limit cannot be bypassed with spoofed headers.
- SSRF guard on IntelliSource endpoints + pinned the updater download host (interim supply-chain hardening).

### Removed

- **Removed the hardcoded `FFTEST-ADMIN-DEV-MODE` license-bypass key** — anyone who entered it unlocked all Pro/Agency features for free. The legitimate wp-config `FORMFLOW_ADMIN_KEY` operator escape hatch is kept.
- **Removed the tester-bridge test harness** (`includes/class-tester-bridge.php`, `tester/`) — it no longer ships to client installs.

## Unreleased (security)

### Security
- **Removed the hardcoded license-bypass key `FFTEST-ADMIN-DEV-MODE`.** `LicenseManager::ADMIN_TEST_KEY` shipped in client installs; anyone who entered that literal string as their license key had every Pro/Agency feature unlocked for free (`is_admin_testing_mode()` short-circuited to true, `is_pro()` rode on top, and `activate_license()` minted a local `agency` license without contacting the license server). Deleted the constant and every branch that special-cased it. The legitimate operator escape hatch — the wp-config-defined `FORMFLOW_ADMIN_KEY` constant — is kept. Also dropped the tools-settings UI hint that advertised the key and the `uninstall.php` special-case. Regression-guarded by `LicenseAdminBypassKeyTest`.

### Removed
- **Removed the dormant tester-bridge harness from the shipped plugin.** Deleted `includes/class-tester-bridge.php`, `includes/tester-bridge/`, and the top-level `tester/scenarios/` fixtures, plus the loader/init in `formflow.php`. This harness was development/QA scaffolding that should not ship to client installs. Its dedicated CI nets lost their subject: the now-empty `contract` testsuite and the tester-bridge-scoped coverage floor (`phpunit.coverage.xml`) were removed rather than repointed at a facade; the property + regression + security nets remain intact.

## 4.0.6 — 2026-06-16 (schema-drift + reliability)

### Fixed
- **P0 — corrupted `wp_isf_api_keys` schema destroyed the `api_key` column.** The CREATE TABLE column list was mangled in two files (`class-activator.php`, `platform/class-api-platform.php`): `api_PRIMARY KEY  (id),` fused two clauses together and left a stray `key VARCHAR(64) NOT NULL,` line, so the intended `api_key VARCHAR(64) NOT NULL` column was never created while `UNIQUE KEY api_key (api_key)` referenced a non-existent column. API-key authentication (`WHERE api_key = %s`) and key creation were broken on every install. The same corruption was present in the activator's `wp_isf_tenants` table. Restored `api_key VARCHAR(64) NOT NULL` and a standalone `PRIMARY KEY  (id)` line in both files, and added an idempotent v4.0.6 migration that `ALTER TABLE … ADD COLUMN api_key` (+ its unique index) on already-broken installs (guarded by `INFORMATION_SCHEMA` checks).
- **P0 — white-label and module tables never reached upgraded installs.** `WhiteLabel::create_tables()` and the program/appointment module table creators were only ever run lazily (or never), while module code queried those tables — a "Table doesn't exist" crash waiting to happen on auto-updated sites. Activation now centralizes all module schema in `Activator::create_module_tables()` (white-label, API platform, programs, appointment bundler), and the upgrade path runs it too.
- **Schema drift between the activator and white-label removed.** The activator carried its own, *different* (and partly corrupted) definition of `wp_isf_tenants` / `wp_isf_tenant_clients` / `wp_isf_tenant_usage` / `wp_isf_branding_profiles` than the code that queries them. `WhiteLabel::create_tables()` is now the single source of truth for those four tables; the activator no longer defines them. (See Notes for the residual on pre-4.0.6 installs.)
- **#4 — unbounded retention sweep.** The daily `apply_retention_policy()` cron loaded *every* old submission into memory at once (`SELECT … WHERE created_at < …` with no `LIMIT`) — an OOM/timeout risk on large tables. It now processes submissions in bounded batches (`Database::RETENTION_BATCH_SIZE = 500`) and loops until the table drains.
- **#4 — per-pageview visitor write.** `VisitorTracker` (hooked on `init@5`) issued an UPDATE for last-seen on every front-end pageview. Writes are now throttled to once per visitor per 15 minutes via a transient, and every visitor write short-circuits when the visitors table is missing/drifted so a schema problem can never become per-request error spam.

### Added
- **Migration-on-upgrade standardization (#2).** The version-drift path now runs `Activator::ensure_schema()` (re-runs every dbDelta — additive + idempotent) BEFORE the hand-written `run_migrations()` ALTERs, generalizing the pattern `wp_isf_deliveries` already used. Any table/column added to the schema now reaches existing installs on auto-update.
- **Schema-drift CI guard (#3).** `SchemaDriftGuardTest` asserts every column written via `$wpdb->insert/update` in a self-contained schema file exists in that file's CREATE TABLE — catching the api_key class of bug in CI. Plus `ApiKeysSchemaCorruptionTest` (regression), `RetentionSweepChunkingTest`, and `VisitorTrackerThrottleTest`.

### Notes
- **Residual on pre-4.0.6 installs (flagged follow-up):** the four white-label tables were historically created by the activator's divergent schema. dbDelta is additive, so on upgrade `WhiteLabel::create_tables()` *adds* the columns the code needs but cannot remove the old activator-era columns. Two of those legacy columns (`name`, `slug` on `wp_isf_tenants`/`wp_isf_tenant_clients`) were declared `NOT NULL` without a default; on a pre-4.0.6 install that already materialized them, a WhiteLabel insert that doesn't set them could fail. White-label is a Pro/Agency feature with effectively no production tenants yet, so this is left as a documented follow-up rather than a risky data migration. Fresh 4.0.6 installs are unaffected (the white-label schema is canonical from the start).

## 4.0.5 — 2026-06-02

### Fixed
- **Guarded a latent fatal in the appointment self-service renderer.** `Appointment_Self_Service::render_page()` unconditionally `include`d `public/templates/appointment-self-service.php`, a template that was never shipped (the feature is scaffolded but unfinished and not yet wired to a public route). The renderer now checks `file_exists()` first and returns a graceful error page instead of fatalling if the template is absent. Flagged by the fatal-references pre-ship sweep, which is now clean for FormFlow Pro. (The self-service feature remains unfinished — its config UI is present but the template + routing are still to be built.)

## 4.0.4 — 2026-06-02

### Fixed
- **Stray "1" step indicator on single-step forms.** The builder renderer was unconditionally calling `render_progress_bar()` even on forms with one step (Dominion PTR, every imported Gravity Form, every contact form). Result: a lonely "1" circle with a horizontal progress bar floating above an otherwise clean form. Now skipped when `count($steps) <= 1`; multi-step wizards still get the full progress UI.

## 4.0.3 — 2026-06-02 (schema cleanup)

### Fixed
- **dbDelta noise across every activation.** 38 CREATE TABLE statements across 9 schema files used inline `id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,` syntax. dbDelta accepts these on initial CREATE but then synthesizes `ALTER TABLE … CHANGE COLUMN id id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` on every subsequent activation — failing with `Multiple primary key defined` and flooding `debug.log`. Rewrote every schema to dbDelta-compliant form: `NOT NULL AUTO_INCREMENT` on the column line + a separate `PRIMARY KEY  (id)` line with the required two-space gap.
- **FOREIGN KEY constraints removed from two tables** (`wp_isf_submissions`, `wp_isf_analytics`). dbDelta does not understand FOREIGN KEY at all — it parses each as a column declaration and emits `ALTER TABLE … ADD COLUMN CONSTRAINT fk_… FOREIGN KEY …`, which is syntactically invalid SQL. Referential integrity is still enforced in application code on delete.
- **`INDEX name (...)` → `KEY name (...)`** in `class-document-upload.php` and `class-capacity-manager.php` so dbDelta stops re-synthesizing the same indexes on every load.

### Added
- **Phase 5 sentinel: `DbDeltaCompatibilityTest`** with 4 cases pinning every CREATE TABLE in the plugin to dbDelta-compliant syntax. Any new schema that reintroduces inline `AUTO_INCREMENT PRIMARY KEY`, a `FOREIGN KEY`, an `INDEX` keyword, or a single-space `PRIMARY KEY (` fails CI before ship.

### Notes
- This is internal cleanup only. No behavior change, no data migration, no schema change at the table level (existing rows + columns unchanged — dbDelta's idempotent `dbDelta()` simply now produces *zero* spurious ALTERs on activation instead of dozens).
- Recommended install path: overwrite-upload via Plugins → Add New → Upload Plugin → "Replace current with uploaded". Activation runs cleanly with no debug.log noise.

## 4.0.2 — 2026-06-02 (critical hotfix)

### Fixed
- **Critical: web requests fataled with `Class "WP_CLI" not found`** whenever the plugin was loaded outside the WP-CLI binary (i.e., every normal page load and admin request). The Gravity Forms importer's CLI registration in `formflow.php` relied on an early `return` inside `class-gf-import-cli.php` to skip class declaration in web context. That guard does not work: PHP processes top-level `class` declarations at file compile time, before the `return` runs. So the class was declared in every context; `class_exists()` returned true; `GfImportCli::register()` ran; and `\WP_CLI::add_command()` fataled because the WP_CLI *class* is only loaded under the `wp` binary.
- The fix gates the `require_once` **and** the `register()` call together on `defined('WP_CLI') && WP_CLI` in `formflow.php`. The CLI file is no longer required in web context at all.
- Added Phase 5 sentinel test (`test_cli_registration_is_gated_on_wp_cli_constant`) so any future refactor that drops the gate fails CI before ship.

### Impact
- Took down `dominionenergyptr.com` during the 4.0.0/4.0.1 install. The bug shipped in 4.0.0 (PR #28, GF importer) and was latent in the 4.0.0-beta1 desktop zip, hidden because the pilot smoke ran via `wp formflow import-gf` on a dev environment where `WP_CLI` *was* defined.

## 4.0.1 — 2026-06-01

### Added
- **Event Mode** for builder forms — designed for iPad/kiosk use at outreach events where multiple people enroll back-to-back on the same device. Configurable per form in the editor (Copy task):
  - **Reset button**: appends a large "Start another enrollment" button to the confirmation panel, full hard-refresh on tap.
  - **Auto-reset countdown** (default 5s, configurable 0–60s): counts down on the confirmation panel and reloads the form when it hits zero.
  - **Tap-to-cancel**: tapping anywhere on the confirmation panel pauses the auto-reset (in case the next person isn't ready) — the manual button still works.
  - Reload uses `window.location.reload()` so the visitor cookie, server-side state, and any half-saved progress all reset cleanly between submissions.
- Phase 5 sentinel test (`EventModeContractTest`) pins the contract: editor UI exposure, localized config keys, clamped seconds (0–60), JS branching, reload mechanism, cancel hook. Suite is now 65 tests / ~135 assertions.

### Why
- The Dominion PTR form ships on iPads at a tabling event. Without event mode the staff has to hard-refresh Safari between every enrollment, which is clunky and slow. With event mode enabled the form auto-resets a few seconds after the confirmation appears (or the staff taps the button) — the next person can step up immediately.

### Notes
- Disabled by default. Enable per-form via the **Copy** task → **Event Mode** section.
- Builder-form only. The IntelliSOURCE Wizard subsystem is unaffected (already has its own kiosk flow).

## 4.0.0 — 2026-05-30 (4.0 release line — beta)

The 4.0 release line establishes a structural split between the IntelliSOURCE Wizard subsystem (Pepco / Delmarva utility enrollment) and the generic FormFlow Builder (Dominion PTR, Gravity Forms migrations, every future non-utility client). Backed by the Phase 5 regression suite (59 tests / 125 assertions). **Shipped as a beta channel: install on the pilot site first, smoke the import + a real submission, then roll to the remaining 7 GF sites.**

### Why the major bump
- CAT flagged the IntelliSOURCE/Builder seam as the root cause of every regression class shipped on 2026-05-28 (3.0.5 / 3.0.7 / 3.1.1 / 3.1.4 / 3.1.5). The 4.0 spec (`docs/superpowers/specs/2026-05-29-4.0-two-products-split.md`) is the durable fix.
- The 8-site Gravity Forms migration needs the structural split + the new GF importer to land together.

### Added
- **IntelliSOURCE subsystem directory** (`includes/intellisource/`) with the wizard templates relocated under it. Architectural README documents the boundary and the form_type → subsystem dispatch table. Builder subsystem (`includes/builder/`) gets a mirror README. (PR #26)
- **`Frontend::classify($form_type)`** — every form_type value routes through one classifier: `'external'`, `'intellisource'`, `'builder'`. Unknown values default to `'builder'` (the safe default — replaces the pre-4.0 silent fallthrough to the IntelliSOURCE wizard that caused the 3.0.5 / 3.1.1 regressions). (PR #27)
- **`Frontend::canonicalize_form_type($form_type)`** — normalizes legacy aliases (`enrollment` → `intellisource_wizard`, `scheduler` → `intellisource_scheduler`, `custom` → `builder`). Used for intra-subsystem sub-shape checks. (PR #27)
- **v4.0.0 ENUM migration** in `class-activator.php::run_migrations` adds `intellisource_wizard`, `intellisource_scheduler`, `builder` to the form_type ENUM. Old values keep working forever via the classifier — no existing row gets rewritten. `create_tables` updated for fresh installs. (PR #27)
- **Gravity Forms importer** at `includes/builder/importers/`. WP-CLI command: `wp formflow import-gf <file.json> [--dry-run] [--activate]`. Reads a GF JSON export (Forms → Import/Export → Export Forms) and creates equivalent FormFlow instances on the canonical `form_type='builder'` track. Each imported form lands inactive by default so you can review before activating. Full field-type mapping (text/email/phone/textarea/number/date/time/select/multiselect/radio/checkbox/consent/name→first+last/address→5 fields/section→heading/html→paragraph/hidden/fileupload/signature/website). Conditional logic → `settings.show_when`. Unsupported fields (post-creation, commerce, calculation, captcha, page-break, unknown types) drop with per-field warnings on the import report. (PR #28)

### Changed
- `render_form_shortcode` dispatches via `classify()` — two top-level branches (`external`, `builder`); IntelliSOURCE wizard stays inline until a future PR extracts it. (PR #27)
- `trait-ajax-handlers` normalizes `$form_type` once via `canonicalize_form_type`; downstream comparisons use canonical values. (PR #27)

### Phase 5 regression suite (run on every PR)
- After 3.4.1: 27 tests / 56 assertions
- After PR #26 (file reorg): 27 / 56
- After PR #27 (classifier): 46 / 84 (new `FormTypeClassifierTest` with 18-case data provider)
- After PR #28 (GF importer): **59 / 125 — all green**

### Safety properties
- No existing `wp_isf_instances` row needs to be rewritten. Dominion's `form_type='custom'` keeps routing to the builder. Pepco/Delmarva's `form_type='enrollment'` keeps routing to the wizard.
- Schema migration is additive only.
- Imported GF forms land inactive (`is_active=0`); admin reviews before activating.
- Importer is pure: never touches Gravity Forms's own tables; GF can be uninstalled before or after.

### Known gaps (held for follow-up, none blocking)
- Admin UI for the GF importer (file-upload + preview) — CLI is enough for the migration; UI is convenience.
- Admin "create new form" subsystem-aware picker (IntelliSOURCE Wizard vs Custom Form Builder cards) — the new editor's existing dropdown still works.
- Legacy `admin/views/instance-editor.php` (1,300 LOC) — will be deleted after the new editor has soaked another release.
- Multi-step builder — GF page-break fields collapse with a warning on import. Multi-step support is a 4.1 target.
- Pending task #10: SFTP destination smoke against real Itron credentials.

## 3.4.1 — 2026-05-29 (Phase 4b — REST permission audit)

### Added
- **`\ISF\Security::rate_limit_public()`** — REST permission callback that combines `__return_true` with the existing per-IP rate limiter. Returns a `WP_Error` (HTTP 429) when the limit is exceeded. Used in place of `__return_true` for legitimately-public routes that touch expensive backends (Google Maps API, DB-heavy queries).
- **`\ISF\Security::nonce_or_logged_in()`** — REST permission callback that accepts either an authenticated user OR a valid `wp_rest` nonce. Keeps frontend form-submission flows working while rejecting CSRF/random POSTs. Used for state-changing endpoints.
- **`PublicRestRoutesDocumentedTest`** — new Phase 5 test that asserts every REST route using `__return_true` for its permission callback has a documenting comment ("Public:" or "Public by design") within 15 lines above explaining why public is the right call. Catches future routes added without articulating the auth model. Phase 5 suite now 27 tests / 56 assertions.

### Changed
Per-route audit of the 29 `__return_true` REST permission callbacks PHIL flagged. Verdicts:

- **`address-validator`, `geocoding`, `cross-sell-engine`** (8 routes) — kept public, swapped to `rate_limit_public` (Google Maps + DB-heavy backends).
- **`program-manager`** — `/programs/eligibility`, `/programs/recommendations` → `rate_limit_public`; `/programs/enroll` → `nonce_or_logged_in` (write endpoint).
- **`appointment-bundler`** — `/bundle-check`, `/bundled-slots` → `rate_limit_public`; `/bundle`, `/bundle/{id}/reschedule`, `/bundle/{id}/cancel` → `nonce_or_logged_in` (write endpoints).
- **`completion-receiver`, `handoff-endpoint`, `embed-handler`, `api-platform` (health + openapi), `program-manager` (catalog), `appointment-bundler` (bundle GET), `tester-bridge`** — kept `__return_true` because they're legitimately public by design (token-gated in URL, public catalogs, health probes, form-submission endpoints whose auth lives inside the callback). Each now has a `// Public:` comment explaining why.

PHIL flagged 29 `__return_true` instances. Verdict: 16 stay public with documentation, 8 add rate-limiting, 5 add nonce-or-auth. Zero routes "converted to admin-only" because doing so would break legitimate frontend integrations. The audit's real finding wasn't "lock everything down" — it was "every accept-all decision should be conscious + documented." That's now enforced at CI time.

## 3.4.0 — 2026-05-28 (Phase 4a — Security + GDPR foundation)

### Added
- **WP Privacy API integration.** The Database class has had `find_submissions_for_gdpr()`, `anonymize_submission()`, and `permanently_delete_submission()` methods for some time — but they were never registered with WordPress's built-in **Tools → Export Personal Data** / **Tools → Erase Personal Data** screens. Clicking those buttons in wp-admin did nothing for FormFlow submissions. New `ISF\Privacy` class registers the canonical `wp_privacy_personal_data_exporters` and `wp_privacy_personal_data_erasers` filters so a data subject's submissions are actually exported (one item per submission, with form fields as rows) or erased (anonymized by default; hard-deleted if the `anonymize_instead_of_delete` setting is off). Plugin-internal underscore-prefixed fields are hidden from the export the same way they're hidden from CSV. PHIL flagged the gap in the 2026-05-28 audit.

### Fixed
- **`api_password` was read raw from `$_POST`** in `ajax_test_api` without `sanitize_text_field` or `wp_unslash`. Low blast radius — the value is never echoed or logged — but the lint was correct. Now sanitized + unslash'd before use. MAX flagged in the 2026-05-28 audit.

### Notes
- This is Phase 4a of the post-audit cleanup plan. Phase 4b — the per-route audit of 29 `__return_true` REST permission callbacks — is held for a follow-up because each route needs an independent decision about whether it should be public, nonce-gated, or capability-gated.

## 3.3.1 — 2026-05-28 (HOTFIX — 3.3.0 site-fatal)

### Fixed
- **3.3.0 fatal'd on every page load.** When I deleted the ML phantom classes in 3.3.0, I grepped for class-name references (`PWAHandler`, `FormPrediction`, etc.) but not for path-based `require_once` calls. Two `require_once ISF_PLUGIN_DIR . 'includes/ml/class-form-prediction.php'` lines remained in `class-plugin.php::load_dependencies()`, both pointing at the deleted files. `require_once: failed to open stream` fatal on every page load. Removed both lines.
- **New regression test:** `RequireOnceTargetsExistTest` scans every active PHP file in `formflow.php` + `includes/` + `admin/` + `public/` for `require_once ISF_PLUGIN_DIR . '...'` patterns and asserts every target file exists. Catches future deletions that leave dead require_once'es behind, at CI time rather than at site-fatal time. Phase 5 suite now 26 tests / 55 assertions.

## 3.3.0 — 2026-05-28 (Phase 3 — Dead-code purge)

### Removed
Roughly **5,000 lines and 17 files** of unreachable code, flagged by CAT/MAX/PHIL in the 2026-05-28 audit. Each removal verified against the Phase 5 regression suite (25/25 green after every kill).

- **`ISF\ML\FormPrediction` + `ISF\ML\FormPredictionApi`** (≈460 LOC + REST routes + weekly cron) — phantom ML microservice that was never operational. Removed the classes, classmap entries, REST route registration (`register_analytics_rest_routes`), `train_ml_model()` cron callback, the `peanut_ml_formflow_train` `add_action`, and the `wp_schedule_event` registration in both `class-plugin.php::ensure_cron_events_scheduled` and `class-activator.php::schedule_cron_events`.
- **`ISF\PWAHandler`** (539 LOC + classmap + FeatureManager registration + feature-config-pwa_support.php) — class was never instantiated; users could toggle PWA support in the settings UI and configure phantom defaults that did nothing.
- **`ISF\ABTesting`** (543 LOC + classmap + FeatureManager registration + feature-config-ab_testing.php) — `get_variation()` was referenced in the settings UI but no application path applied variations.
- **`ISF\ChatbotAssistant`** (909 LOC + FeatureManager registration + feature-config-chatbot_assistant.php) — settings panel configured a chatbot that never rendered.
- **`ISF\FraudDetection`** (707 LOC + FeatureManager registration + feature-config-fraud_detection.php) — risk-score logic that was never invoked from any submission handler.
- **`ISF\Platform\BusinessIntelligence`** (1,464 LOC + `admin/views/business-intelligence.php`) — the 1.4kloc class was instantiated only from its own orphan stub view that had no menu registration pointing to it.
- **`includes/database/traits/trait-instances.php`** — duplicated methods already in `Database` class verbatim and was never `use`d by anything.
- **`admin/assets/js/conditional-logic-builder.js`** — orphan file, never appeared in any `wp_enqueue_script` call.

### Why this matters
Each of these classes had a runtime cost (autoloader hits, settings UI render) and a maintenance cost (every code review touches them, every audit re-asks "is this used?"). Each had a settings UI surface that promised features that never delivered — the "abandonware" pattern CAT called out. With them gone, the plugin's surface area now matches its real behavior.

## 3.2.2 — 2026-05-28 (Phase 1 — Form Builder unblocked)

### Fixed
- **Form Editor Beta "Form fields" task page rendered the schema but no field cards.** The page was loading `form-builder.js`, the `isf_builder` global was populated correctly (instance_id, schema, field_types), but the `FormBuilder` constructor threw `cannot call methods on sortable prior to initialization; attempted to call method 'refresh'` because `renderStep()` (called from `init()`) invoked `$content.sortable('refresh')` on an element that had never been initialized as a jQuery UI Sortable. The team migrated to HTML5 drag-and-drop (`initFieldSorting` uses native `dragstart`/`dragover`) but left the dead `.sortable('refresh')` call behind. Removed the call. `window.ISFFormBuilder.instance` now constructs cleanly and field cards render. Phase 1 of the post-audit cleanup plan unblocked. Diagnosed via Chrome MCP.

## 3.2.1 — 2026-05-28 (Phase 2 audit cleanup)

### Fixed
- **`wp_isf_deliveries` table missing on upgraded sites.** The destinations subsystem (2.9.0+) writes a row per submission to `wp_isf_deliveries`, but fresh-install `create_tables()` only created the table when `\ISF\Destinations\DeliveryLog` was already autoloaded — and `run_migrations()` had no migration for it at all. Sites that activated before the destinations subsystem existed have no such table; opening Submissions in the Form Editor crashes with "Table doesn't exist," and DeliveryDispatcher silently fails on every submission. New v3.2.1 migration in `run_migrations()` invokes `DeliveryLog::get_schema_sql()` via `dbDelta`, so the migration shares the canonical schema with the runtime accessor and can never drift. Flagged by CAT in the 2026-05-28 audit.
- **Multisite / HyperDB migration safety.** `run_migrations()` used `DB_NAME` (the WP-config constant) in four `INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s` queries. On multisite with external DB or HyperDB, that constant doesn't match the schema the per-blog tables actually live in, so migration guards silently skip and leave the column un-altered. Replaced with `$wpdb->dbname`, which reflects the current blog's DB connection. Flagged by PHIL.
- **Removed dead nested `formflow/` directory.** The plugin source tree contained a complete 3.5MB duplicate of itself at `formflow/` (separate `formflow.php` header, separate `uninstall.php` with raw SQL interpolation, full `admin/` `includes/` `public/` trees). Nothing referenced it; WordPress always loaded the outer entry point. Deleted. Flagged by PHIL.

## 3.2.0 — 2026-05-28

### Fixed
- **Hourly wp-cron fatal: `get_due_reports()` argument-count error.** `class-plugin.php::send_scheduled_reports()` (triggered by the `isf_send_scheduled_reports` hourly cron) calls `Database::get_due_reports()` with no arguments, but the method's signature was `string $frequency` — required, no default. Result: every hour at HH:55, wp-cron threw `Too few arguments to function ISF\Database\Database::get_due_reports(), 0 passed`, and scheduled reports never ran. Signature is now `?string $frequency = null`; passing null returns all active reports (matches the cron's intent).

## 3.1.9 — 2026-05-28

### Changed
- **CSV export hides plugin-internal tracking fields.** `isf_form_load_time`, `isf_interaction_score`, and other underscore-prefixed system fields are filtered out of the export. Still preserved in `form_data` for forensic / audit purposes, just not surfaced as user data columns.

## 3.1.8 — 2026-05-28

### Changed
- **CSV export: First Name + Last Name are now separate columns** instead of a combined Customer Name. They appear first in the column order so the export opens with the most human-identifiable columns, then the rest of the form fields follow in schema order.

## 3.1.7 — 2026-05-28

### Changed
- **CSV export columns are now derived from the actual form data.** Previously, every export hardcoded IntelliSOURCE-specific columns (Account Number, Device Type, Promo Code, Schedule Date/Time, Confirmation Number) plus IP Address — irrelevant noise for custom builder forms. The exporter now collects the union of `form_data` keys across the export and emits one column per key, with IntelliSOURCE columns appearing only if at least one row has them populated. IP Address removed from the default export. Step column removed from the default export. The header humanizes the underscored field name (e.g. `street_address` → "Street Address").

## 3.1.6 — 2026-05-28

### Fixed
- **Admin scripts were not loading on subpages — bulk actions, delete, and Export CSV all silently broken.** `Admin::is_plugin_page()` checked 16 hard-coded hook strings prefixed with `is-forms_`, but the actual menu label "FF Forms" slugifies to `ff-forms`. Only the top-level Dashboard hook matched; every other FormFlow admin page (Data, Logs, Reports, Scheduling, etc.) returned false from `is_plugin_page()` so `isf-admin.js`, the form-builder scripts, and the editor scripts never enqueued. Bulk Apply, delete buttons, and Export CSV all looked clickable but did nothing. Replaced the hard-coded list with `strpos($hook, 'isf-') || $_GET['page']` so the check is immune to menu-label changes. Flagged by MAX in the 2026-05-28 audit.

## 3.1.5 — 2026-05-28 (HOTFIX)

### Fixed
- **`isf_submit_builder_form` fired `FORM_COMPLETED` with the wrong signature.** It was passing `(int $submission_id, array $form_data, array $context)` — three args — but `PeanutIntegration::forward_form_completed()` expects exactly one argument: an associative array of submission data (the canonical shape used by `trait-ajax-handlers.php:563`). PHP fatal'd with a TypeError on every submit. Fixed by building the canonical `$submission_data` array (submission_id, instance_id, instance_slug, visitor_id, form_data, utm_data, form_type, status, session_id) and firing both `ENROLLMENT_COMPLETED` (with the legacy `(id, instance_id, form_data)` signature) and `FORM_COMPLETED` (with the single-array signature). UTM tracker call is try/catch'd since UTM is optional.

## 3.1.4 — 2026-05-28 (HOTFIX)

### Fixed
- **`isf_submit_builder_form` was calling `Database::get_instance($id, true)` with an extra argument.** The method's signature is strict `(int $id): ?array`, so PHP fatal'd on submit with a 500 on admin-ajax — meaning every builder-form submission since 3.1.1 was lost. Removed the extra arg; method takes the id only.

## 3.1.3 — 2026-05-28

### Added
- **Radio + checkbox label layout** in `forms.css`. Themes (Enfold in particular) apply `display: block` to `<label>` inside forms, which separated the input control from its text. `.isf-radio-label` / `.isf-checkbox-label` now force `inline-flex` with `align-items: center` and a small gap so the control and text always render together. `.isf-radio-group` is row-flex; `.isf-checkbox-group` is column-flex for stacked option lists.
- **Scroll-gate tooltip** on locked checkbox wrappers. When a wrapper has both `data-scroll-gate-selector` and `data-locked="1"` (set by `builder-form.js` until the linked content box is scrolled to bottom), hover shows a dark tooltip "You must scroll through the full terms before accepting." Mobile breakpoint wraps to multi-line. Cursor on the label flips to `not-allowed` while locked.

## 3.1.2 — 2026-05-28

### Added
- **Native single-step polish in `forms.css`.** When `builder-form.js` detects a single-step form it adds `.isf-single-step` to the wrapper; the stylesheet now hides the multi-step progress indicator, hides the Next button, and styles Submit as a full-width primary action. Eliminates the per-site CSS users were writing in WPCode for "this form only has one step, why is there a step indicator + a non-prominent Submit button."
- **Submit disabled-until-ready.** `.isf-form:has(:invalid)` and `.isf-form:has(input[type="checkbox"]:disabled)` grey out the Submit button until every required field validates AND any scroll-gated checkbox is enabled. Pure CSS — no JS required.
- **Help-text color override.** Some themes (Enfold in particular) style `<p>` elements inside forms with the link color, which made `.isf-help-text` render red. Plugin now anchors it to a neutral `#555` with `!important` to beat the theme.

## 3.1.1 — 2026-05-28 (CRITICAL HOTFIX)

### Fixed
- **Builder-form submissions were not being saved.** The `form_type='custom'` renderer output a `<form method="post">` with no action and no AJAX wiring; submissions POST'd back to the page URL where nothing was listening, so the data was dropped on the floor and no confirmation was shown. New AJAX handler `isf_submit_builder_form` / `formflow_submit_builder_form` persists the submission, fires `FORM_COMPLETED` (so destinations, webhooks, notifications, analytics all run as expected), and returns the success payload. `builder-form.js` intercepts the form submit, POSTs via fetch, replaces the form with a confirmation panel on success, and shows an inline error on failure. Affects every site running 3.0.5+ with at least one `form_type='custom'` instance. Customers reporting "I submitted but didn't see a confirmation" had their submissions lost.

## 3.1.0 — 2026-05-28

### Added
- **Per-field width layout.** `FormRenderer` now honors `field.width` (or `field.settings.width`) with values `half`, `third`, `two-thirds`, `full`. Wrappers get `isf-width-{value}` classes and the step-fields container is flex-wrap, so consecutive half-width fields lay out inline without requiring per-form CSS. Mobile breakpoint stacks them under 600px.
- **Conditional show/hide.** Fields can declare `settings.show_when = {field, equals}` (or `not_equals`). The wrapper is hidden and its inputs disabled — so hidden required fields don't block HTML5 validity — when the rule doesn't hold. Recomputed on form change. Eliminates per-site JS for conditional logic.
- **Scroll-to-bottom gate for checkbox groups.** `settings.scroll_gate = {box: '.selector'}` keeps the checkbox `disabled` until the named element is scrolled to the bottom. Used for terms-and-conditions acceptance: include a `.isf-terms-box` paragraph above the checkbox, then gate the accept checkbox on it.
- **Public behavior JS** (`public/assets/js/builder-form.js`) — single file that handles the three behaviors above plus a `.isf-single-step` class added when there's only one step (so CSS can swap Next for Submit without per-form rules). Registered as `isf-builder-form` and enqueued automatically by `render_custom_form()`.
- **Default form polish baked into `forms.css`:** `.isf-sr-only` rule for accessibility text (was missing), red `.isf-required-indicator`, `.isf-terms-box` scroll container, `.isf-terms-scroll-note` helper text styling. Means new custom forms look correct out of the box without per-site CSS.

### Fixed
- **Form Editor Beta "Form fields" task page was empty.** The form-builder JS/CSS bundle only enqueued on pages whose hook contained `isf-form-builder`, but the new editor's hook is `isf-form` (no `-builder`). `Admin::is_form_builder_page()` now matches both, and `tasks/fields.php` localizes the `isf_builder` global (instance ID, current schema, field-type catalog, REST nonce) so the builder bootstraps against `#isf-form-builder`.

## 3.0.7 — 2026-05-28

### Fixed
- `FormRenderer::render_field()` now normalizes legacy / hand-authored template field shape into the canonical `$field['settings']` structure before dispatching to type-specific renderers. Templates that store `label`, `required`, `helpText`, `defaultValue`, `options`, etc. at the top of each field (instead of nested under `settings`) previously produced label-less inputs because the per-type renderers only read from `$field['settings']`. Existing `settings` values always win — this is purely additive, no behavior change for already-canonical schemas.

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
