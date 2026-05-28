# FormFlow Pro — post-audit cleanup plan (2026-05-28)

> Synthesized from the CAT + MAX + PHIL three-agent audit run on 2026-05-28
> after the 3.1.1 hotfix. Goal: clear the live defects, make the Form Editor
> Beta form builder actually usable, and cut the dead weight that's making
> the plugin look like abandonware.

## Headline

The Form Editor Beta's "Form fields" task is the explicit priority — right
now it loads but doesn't actually let a user edit fields. 3.1.0 fixed the
script enqueue (the page is no longer empty); the underlying `form-builder.js`
UI itself has never been properly stress-tested in the new editor's chrome.
That's Phase 1.

Everything else in this plan is real defects + dead-code removal that the
audit surfaced. None of it is greenfield.

---

## Phase 1 — Form Builder actually usable (3.1.2)

**Outcome:** A user opens FF Forms → any instance → Form fields and can
add, reorder, edit, and delete fields. Save persists. Hard-reload preserves.

### 1.1 — Verify what currently works

- [ ] Load `https://www.dominionenergyptr.com/wp-admin/admin.php?page=isf-form&id=1&task=fields`
      and inspect the rendered DOM. Does `form-builder.js` actually
      bootstrap? Are the 15+ Dominion fields visible? Can they be
      clicked/dragged?
- [ ] If the page is still empty, debug script enqueue via the browser
      Network tab. Confirm `form-builder.js` loads and `isf_builder`
      global is populated. If anything is missing, fix in
      `admin/class-admin.php` and `admin/views/form-editor/tasks/fields.php`.

### 1.2 — Property panel + add-field UX

- [ ] Read `admin/assets/js/form-builder.js` end-to-end. Document what
      methods exist for: render-field-list, click-field-to-edit-properties,
      add-new-field, delete-field, reorder. Note gaps.
- [ ] Wire the click-to-edit property panel. When a field is clicked, a
      side panel (or modal) should show inputs for label, name, required,
      placeholder, help text, width, options (for select/radio/checkbox),
      show_when conditional, scroll_gate (for checkbox). Save back to
      `this.schema` and `markDirty()`.
- [ ] Wire the "add new field" UI. Display field-type cards grouped by
      category (basic / advanced / address / utility / layout) — already
      provided via `isf_builder.field_types`. Clicking a type appends a
      new field to the current step.
- [ ] Wire delete + duplicate buttons on each field card.

### 1.3 — Persistence

- [ ] When `save()` fires, POST the schema to
      `wp_ajax_isf_builder_save` (already registered at
      `class-plugin.php:178`). Verify the receiving handler
      `Admin::ajax_builder_save()` writes to `instance.settings.form_schema`
      atomically without clobbering other settings keys.
- [ ] Hard-reload the page after save. Confirm fields persist.

### 1.4 — Reorder

- [ ] Confirm jQuery UI Sortable is active on the field list. Test
      drag-and-drop reorder. Verify the new order survives save+reload.

### 1.5 — Two-pane Form Editor chrome polish

- [ ] The new editor's `layout.php` + `partials/` provide a two-pane
      layout. The fields task should sit inside it cleanly — fields list
      on the left, property panel on the right. Currently the partials
      are stubs in some places. Wire the sub-rail and inline status
      strip so the chrome feels like the other task views (delivery is
      the reference implementation).

**Estimate:** 1.5–2 days focused.

**Tests to add:**
- `tests/Unit/Builder/AjaxBuilderSaveTest.php` — partial-merge semantics
  (don't clobber `destinations`, `branding`, etc. when saving `form_schema`).
- Browser smoke checklist in
  `docs/superpowers/specs/2026-05-28-form-builder-smoke.md`.

---

## Phase 2 — Critical defects from the audit (3.1.2 or 3.1.3)

These are real bugs with file paths and evidence. None of them are
nice-to-haves.

### 2.1 — `wp_isf_deliveries` table never created (CAT)

- **Symptom:** `admin/views/form-editor/tasks/submissions.php:10,13`
  queries `$wpdb->prefix . 'isf_deliveries'`. `create_tables()` in
  `class-activator.php` never creates this table. Any new install
  → MySQL "Table doesn't exist" the moment Submissions opens.
- **Also:** the column queried (`instance_id`) doesn't match the
  destinations-subsystem spec (which uses `submission_id` FK).
- **Fix:**
  - Add `wp_isf_deliveries` to `create_tables()` per the spec at
    `docs/superpowers/plans/2026-05-26-destinations-subsystem.md`.
  - Add a v3.1.x migration in `run_migrations()` to create the table
    on existing sites.
  - Fix the submissions.php query to JOIN through `wp_isf_submissions`
    on `submission_id`.
  - Add a `count_failed_deliveries(int $instance_id)` method on the
    delivery log so the view doesn't reach into the DB directly.

**Estimate:** 2 hours.

### 2.2 — `ISF_VERSION` constant drift (PHIL)

- **Symptom:** `formflow.php` header reads 3.1.1 (correct), but PHIL
  reports the constant was 2.8.6 at audit time. This breaks the license
  server, auto-update mu-plugin, and the version-drift migration check
  in `isf_init()`.
- **Status:** 3.1.1 release bumped the constant correctly via
  `replace_all=true` on the string `3.1.0`. Verify with
  `grep "ISF_VERSION" formflow.php` — expect `3.1.1`.
- **Followup:** add a unit test that asserts the header version and the
  constant match. Catch future drift at CI time.

**Estimate:** 30 minutes.

### 2.3 — `is_plugin_page()` hook strings wrong on subpages (MAX)

- **Symptom:** Menu label `"FF Forms"` slugifies to `ff-forms`, but
  `is_plugin_page()` checks 16 entries against `is-forms_page_*`. Only
  the top-level page matches. Subpage scripts never load for the
  legacy editor. We patched one entry in 3.0.1; 15 remain broken.
- **Fix:** Replace the hard-coded list with
  `strpos($hook, 'isf-') !== false` or check `$_GET['page']` directly.
  Verify by loading every FF Forms subpage and confirming
  `isf-admin.js` + `isf-admin.css` load (Network tab).

**Estimate:** 1 hour.

### 2.4 — `form_type='custom'` ENUM migration may not run for some sites (PHIL)

- **Symptom:** Fresh installs get the correct ENUM via
  `create_tables()`. Existing 2.x sites rely on the v3.0.6 migration
  in `run_migrations()` to ALTER the column. PHIL flagged that the
  migration uses `INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DB_NAME`,
  which silently fails on multisite + external DB / HyperDB setups.
- **Fix:** Replace `DB_NAME` with `$wpdb->dbname` (which is correct
  for the current blog's DB connection). Audit lines 53, 73, 101 of
  `class-activator.php`.

**Estimate:** 30 minutes.

### 2.5 — Two `uninstall.php` files (PHIL)

- **Symptom:** `/FORMFLOW/uninstall.php` (outer, uses `%i` placeholder,
  safer) and `/FORMFLOW/formflow/uninstall.php` (inner, raw SQL
  interpolation) both exist. WordPress loads whichever is at the
  plugin's registered path.
- **Fix:** Delete the inner copy. The plugin loads from `formflow.php`
  at the root; the outer `uninstall.php` is the one WP runs.
- **Bonus:** Audit the table list in the kept `uninstall.php` against
  every table created in `create_tables()`. PHIL flagged 20+ tables;
  if any are missing, uninstall leaves orphan tables behind.

**Estimate:** 30 minutes.

---

## Phase 3 — Dead code purge (3.2.0)

CAT and MAX both flagged a long list of half-finished / never-wired
classes. Each one has a settings UI that configures nothing and runtime
code that's never called. The cumulative effect is a plugin that looks
like abandonware.

For each: **either wire it or kill it.** No "leave dormant in case
someone wants it." That's the trap that got us here.

### Recommended kills (no production value, definite removal)

| File / class | Audit ref | Notes |
| --- | --- | --- |
| `includes/ml/class-form-prediction.php` | MAX #6 | Phantom ML microservice. Required in `class-plugin.php:76-77`, never instantiated. |
| `includes/ml/class-form-prediction-api.php` | MAX #6 | Same. |
| `includes/class-chatbot-assistant.php` | CAT, MAX #7 | Settings panel exists, runtime path doesn't. |
| `includes/class-pwa-handler.php` | CAT, MAX #8 | `PWAHandler::init()` is never called. |
| `includes/class-fraud-detection.php` | CAT, MAX #9 | Settings panel exists, never instantiated. Wire OR kill — but pick one. |
| `includes/class-ab-testing.php` | MAX #10 | `get_variation()` referenced in settings UI only, never called. |
| `includes/class-business-intelligence.php` | CAT | Empty stub. Menu item renders blank page. |
| `includes/class-marketplace.php` | CAT | `Marketplace` view at `admin/views/marketplace.php` renders stub. (Templates submenu is separate and works.) |
| `admin/assets/js/conditional-logic-builder.js` | MAX P2 | Orphan, never enqueued. |
| `includes/database/traits/trait-instances.php` | CAT | Duplicates methods already in `Database` class; never `use`d. |

For every removal: also remove the corresponding settings UI partial
and feature-config card so we don't leave settings that configure
nothing. Grep `feature-config-{slug}.php` for each.

**Process:** one commit per class so any revert is surgical.

**Estimate:** 1 day for the whole purge + smoke test.

### Decisions needed (wire or kill — Nat's call)

- **`FraudDetection`** — has working risk-score logic. If we want it,
  wire it into `ajax_submit_builder_form` and the enrollment AJAX. If
  not, kill the panel.
- **`DocumentUpload`** — gated behind a feature flag, UI exists, code
  is real. Keep dormant or wire? Useful for utility programs that
  attach proof-of-residence docs.

---

## Phase 4 — Security + GDPR (3.2.0)

### 4.1 — `__return_true` REST permission audit (PHIL, 29 routes)

For each route, decide: legitimately public (form submissions, redirect
callbacks, address validation) or should require auth?

Files to audit (line numbers from PHIL):
- `class-completion-receiver.php:62`
- `class-address-validator.php:103, 121, 149`
- `class-geocoding-service.php:79, 107`
- `class-handoff-endpoint.php:56, 80, 94`
- `class-embed-handler.php:61, 75, 81, 87`
- `class-appointment-bundler.php:73-108` (6 routes)
- `class-api-platform.php:307, 314`
- `class-program-manager.php:69, 86, 93, 100`
- `class-cross-sell-engine.php:66, 72, 78`

For each: document the intended caller, then either tighten the
callback or add a comment explaining why it's open. No more silent
`__return_true`s.

**Estimate:** 1 day.

### 4.2 — WP Privacy API wiring (PHIL)

- **Symptom:** `trait-gdpr.php` has the methods (`find_submissions_for_gdpr`,
  `anonymize_submission`, `permanently_delete_submission`) but they're
  not registered with WordPress.
- **Fix:** Register hooks in `class-plugin.php`:
  ```php
  add_filter('wp_privacy_personal_data_exporters', [$this, 'register_privacy_exporter']);
  add_filter('wp_privacy_personal_data_erasers',   [$this, 'register_privacy_eraser']);
  ```
  Hook them to the existing trait methods.

**Estimate:** 2 hours.

### 4.3 — `api_password` sanitization (MAX #3)

- **Symptom:** `admin/class-admin.php:969` reads `$_POST['api_password']`
  raw with no `sanitize_text_field` or similar.
- **Fix:** Sanitize on save, never log, never echo.

**Estimate:** 15 minutes.

---

## Phase 5 — Test coverage for the bug class that hit us today (3.2.0)

Three targeted tests that would have caught today's bugs:

- [ ] `tests/Unit/Database/FormTypeEnumRoundTripTest.php` — assert
      `decode_instance` round-trips `form_type='custom'` without
      coercion. Will fail if anyone drops `'custom'` from the ENUM
      migration list again.
- [ ] `tests/Unit/Frontend/ShortcodeDispatchTest.php` — assert
      `render_form_shortcode` routes to the correct branch for
      `'enrollment'` / `'scheduler'` / `'external'` / `'custom'`.
- [ ] `tests/Unit/Admin/AjaxSaveInstancePartialMergeTest.php` —
      assert partial settings POSTs don't clobber unrelated keys
      (destinations, branding, etc.).

**Estimate:** 4 hours.

---

## Phase 6 — Structural follow-up (NOT this sprint — 3.3+ or 4.0)

CAT's bigger call: **the plugin is two products.**

The IntelliSOURCE wizard (`form_type='enrollment'`) and the FormRenderer
builder (`form_type='custom'`) share a row and a shortcode but almost no
code. Today's pain came from the seam between them.

If we ever sell the form builder to a non-utility client, they inherit
the IntelliSOURCE migrations, device_type column, api_endpoint
required-field, and wizard AJAX handlers on every page load.

**Long-term recommendation:**
- Rename `form_type='enrollment'` → `'intellisource'`,
  `form_type='custom'` → `'builder'` for honesty.
- Extract the IntelliSOURCE wizard into its own subsystem
  (`includes/intellisource/`) — it currently lives mixed into
  `class-public.php`. The wizard's submit handler, validator, schedule
  fetch, and template files all colocate there.
- Builder code (`includes/builder/`) stays as the generic surface.
- License tier becomes the boundary: Pro Utility (Wizard + Builder),
  Pro Generic (Builder only).

This is a multi-sprint architecture move. Don't do it now. Park as a
discussion item for the next planning cycle.

---

## Sequencing summary

| Phase | Estimate | Ships as |
| --- | --- | --- |
| 1. Form builder usable | 2 days | 3.1.2 |
| 2. Critical defects | 1 day | 3.1.2 or 3.1.3 |
| 3. Dead code purge | 1 day | 3.2.0 |
| 4. Security + GDPR | 1.5 days | 3.2.0 |
| 5. Test coverage | 0.5 day | 3.2.0 |
| 6. Two-products split | — | 3.3+ / 4.0 |

**Total to 3.2.0:** ~6 working days.

**Recommended order:** 1 → 2 → 5 → 4 → 3.

(Tests before purge so we have safety net for the deletes.)

---

## Out of scope for this plan

- Visual form-builder polish beyond "works correctly" (drag handles
  with custom icons, animations, sortable group dropdowns, etc.).
- New field types beyond what's already implemented.
- Multi-step wizard support in the new builder.
- Submission-detail view in Form Editor Beta (the `submissions.php`
  task is currently a count display; full table view is out of scope).
- The two-products structural split (Phase 6, deferred).

---

## Open questions for Nat

1. Phase 1 — should the property panel be a **side drawer** (slides in
   from the right) or a **modal**? Drawer feels more wp-admin native.
2. Phase 3 — for `FraudDetection` and `DocumentUpload`: wire or kill?
3. Should 3.1.2 bundle Phases 1 + 2, or split (3.1.2 = builder usable,
   3.1.3 = critical defects)? Bundling means one upload to dominion;
   splitting means we can ship the builder fix as soon as it's done
   without waiting on the rest.
