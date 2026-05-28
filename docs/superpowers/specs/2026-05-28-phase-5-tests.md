# Phase 5 — Targeted regression tests (spec)

> Five PHPUnit tests that, if they had existed, would have caught every
> production-facing bug shipped today (3.0.6 through 3.2.0). Together
> they cost roughly half a day to write and run forever in CI as the
> safety net for the dead-code purge and the structural split.

## The pattern these tests address

Every bug today shared one shape: a **callable contract drift** that the
linter and PHP itself didn't catch until runtime.

| Bug | Hidden contract |
| --- | --- |
| 3.0.6 | MySQL ENUM silent coerce: `form_type='custom'` not in allowed values |
| 3.0.7 | Field schema shape: `field.label` vs `field.settings.label` |
| 3.1.1 | Builder form's `<form>` POSTed to a page route that didn't listen |
| 3.1.4 | `Database::get_instance(int $id): ?array` — strict signature, extra arg fatal'd |
| 3.1.5 | `FORM_COMPLETED` hook expects single assoc-array, we passed three args |
| 3.1.6 | `is_plugin_page()` matched `is-forms_*` but menu label slugified to `ff-forms` |
| 3.2.0 | wp-cron `send_scheduled_reports` called `get_due_reports()` with no args; signature required `string $frequency` |

All five tests below verify hidden contracts the human eye misses on a
quick read.

---

## Test 1 — `FormTypeEnumRoundTripTest`

**File:** `tests/Unit/Database/FormTypeEnumRoundTripTest.php`

**Catches:** 3.0.6 class of bugs (ENUM silent coercion).

**Setup:** Brain/Monkey + a `wpdb` stub that simulates MySQL's
ENUM-coercion behavior — value not in allowed list ↦ stored as `''`,
affected-rows = 0.

**Assertions:**
- `Database::create_instance(['form_type' => 'enrollment', ...])` ↦
  reading back, `form_type === 'enrollment'`.
- Same for `'scheduler'`, `'external'`, `'custom'`.
- Same for the future ENUM values listed in
  `class-activator.php::create_tables()` — keep them in a constant or
  data-provider so adding a new value here flags any drift.
- `Database::create_instance(['form_type' => 'BOGUS'])` ↦ reads back
  as `''` (i.e., explicit failure mode is preserved — no silent rename).

**Why this test specifically:** the `Activator::create_tables()` and
`run_migrations()` chain is the source of truth for allowed values. The
test couples both to the application code so a future migration that
forgets to ALTER the ENUM fails CI immediately.

---

## Test 2 — `ShortcodeDispatchTest`

**File:** `tests/Unit/Frontend/ShortcodeDispatchTest.php`

**Catches:** 3.0.5 + 3.1.1 class (shortcode rendering the wrong path for
a given `form_type`).

**Setup:** Mock `Database::get_instance_by_slug()` to return canned
instances; assert which renderer branch fires.

**Assertions:**
- `form_type='external'` ↦ `render_external_form` is called, includes a
  redirect button, does NOT enqueue `isf-enrollment`.
- `form_type='custom'` ↦ `render_custom_form` is called, includes
  `isf-builder-form` wrapper, enqueues `isf-builder-form` JS, localizes
  the `isfBuilderForm` global with the four required keys.
- `form_type='enrollment'` ↦ legacy wizard path is taken, step-1 partial
  is included, `isf-enrollment` is enqueued.
- `form_type='scheduler'` ↦ scheduler partial is taken.
- `form_type=''` (empty) ↦ does NOT silently fall through to enrollment
  wizard; either rejects with admin notice or routes to a safe default
  (decide which — TBD before the test is written).

**Why this test specifically:** the empty/unknown form_type case is what
caused customers' submissions to land in the wrong renderer on
dominionenergyptr.com. Pinning the dispatch table prevents a future
refactor from accidentally re-introducing the silent fall-through.

---

## Test 3 — `AjaxSaveInstancePartialMergeTest`

**File:** `tests/Unit/Admin/AjaxSaveInstancePartialMergeTest.php`

**Catches:** 3.0.3 class (partial-update semantics for the form editor's
save-on-blur).

**Setup:** Seed an instance with a rich `settings` blob — `destinations`,
`branding`, `scheduling`, `form_schema`, custom keys. Fire
`ajax_save_instance` with a POST that only contains a single field
update.

**Assertions:**
- Updated key reflects the new value.
- ALL other top-level settings keys are unchanged (deep-equal to the
  pre-update snapshot).
- The `form_schema` sub-tree is untouched when the save targets a
  non-schema field.
- The audit log entry contains only the diff (key changed + old + new),
  not the whole settings blob.

**Why this test specifically:** the save handler is the load-bearing
write path. Any future refactor here risks silently clobbering a key
the test wouldn't catch unless we explicitly assert preservation.

---

## Test 4 — `AjaxSubmitBuilderFormTest`

**File:** `tests/Unit/Frontend/AjaxSubmitBuilderFormTest.php`

**Catches:** 3.1.1, 3.1.4, 3.1.5 — the entire submit handler signature
class.

**Setup:** Mock `Database::get_instance()`, `Database::create_submission()`,
`Security::generate_session_id()`, the `FORM_COMPLETED` and
`ENROLLMENT_COMPLETED` hooks. Build a valid POST.

**Assertions:**
- Calls `Database::get_instance(int $id)` with **one** integer argument
  (catches the 3.1.4 extra-bool-arg regression).
- Calls `Database::create_submission([...])` with the canonical
  submission-data shape (instance_id, session_id, customer_name,
  form_data, status='completed', step=1, ip_address, user_agent).
- Fires `ENROLLMENT_COMPLETED` with `(int $submission_id, int $instance_id,
  array $form_data)` — three positional args.
- Fires `FORM_COMPLETED` with a **single** associative-array argument
  matching `trait-ajax-handlers.php:547` shape (submission_id,
  instance_id, instance_slug, visitor_id, form_data, utm_data, form_type,
  status, session_id).
- Returns a JSON success payload with `submission_id` (int) and
  `success_message` (escaped string).
- On `Database::create_submission()` returning `false`, returns a JSON
  error with HTTP 500 — does not throw.
- The handler is registered for BOTH `wp_ajax_isf_submit_builder_form`
  AND `wp_ajax_formflow_submit_builder_form` (F5 ASM mirror).

**Why this test specifically:** four production crashes in 90 minutes
today all came from this method. The hook-signature check (single
assoc-array) and the `get_instance` arity check are the high-value asserts.

---

## Test 5 — `CronCallableSignaturesTest`

**File:** `tests/Unit/Plugin/CronCallableSignaturesTest.php`

**Catches:** 3.2.0 — wp-cron callbacks crashing because the registered
callable invokes a method with the wrong arg count.

**Setup:** Reflection. Iterate every `wp_schedule_event` /
`add_action('isf_*'/'formflow_*'/_anything cron-y_)` registration in
`class-plugin.php`. For each, locate the target callable and assert
the call-site arity matches the method's `ReflectionMethod` required
parameter count.

**Assertions (data-provider):**
- `isf_send_scheduled_reports` ↦ `send_scheduled_reports()` calls
  `Database::get_due_reports()` ↦ arity check.
- `isf_process_retry_queue` ↦ same shape check.
- Any other recurring wp-cron actions registered by the plugin.

**Why this test specifically:** the 3.2.0 fatal ran silently every
hour for who-knows-how-long. The plugin's audit logs and the WP cron
log are the only places it surfaced. A reflection-driven check on
every cron call site is cheap to write once and catches the entire
class of "method signature changed, caller didn't" bugs.

---

## File layout

```
tests/Unit/
├── Database/
│   └── FormTypeEnumRoundTripTest.php   (test 1)
├── Frontend/
│   ├── ShortcodeDispatchTest.php       (test 2)
│   └── AjaxSubmitBuilderFormTest.php   (test 4)
├── Admin/
│   └── AjaxSaveInstancePartialMergeTest.php  (test 3)
└── Plugin/
    └── CronCallableSignaturesTest.php  (test 5)
```

## Common helpers needed

- `tests/Helpers/WpdbStub.php` — a minimal wpdb mock that simulates
  ENUM-coercion behavior, prepare/query/insert/update.
- `tests/Helpers/HooksRecorder.php` — captures fired hook calls with
  their full argument lists so assertions can inspect them.
- `tests/Helpers/FactoryInstances.php` — builders for canonical
  instance settings shapes (custom, enrollment, external, scheduler).

## Sequencing

1. Helpers first (~1 hour) — they unblock the rest.
2. Test 4 second (highest payoff — protects the four hotfix patches).
3. Test 1 + Test 5 in parallel (~30 min each — small, focused).
4. Test 2 (medium — needs more mocking).
5. Test 3 last (highest setup cost — full settings-shape fixtures).

**Total estimate:** half a day for someone familiar with Brain/Monkey
+ PHPUnit. The four hotfix-protecting tests alone (1, 4, 5) are ~2 hours.

## Acceptance gate

PR cannot merge until all five tests pass locally AND in CI.
`composer test` runs the unit suite; the GH Actions workflow already
runs against the worktree branch (Accessibility Tests check).

## Out of scope for this spec

- Integration tests against a real WP/MySQL instance — Phase 5 is unit
  tests only.
- Browser-level tests against `/events/innsbrook-affordability-event/`
  — that's the smoke checklist in
  `docs/superpowers/specs/2026-05-28-form-builder-smoke.md` (yet to be
  written, separate doc).
- Coverage gates / mutation testing — Phase 5+, not now.

## Decisions needed before writing code

1. `Test 2` — what's the correct behavior for `form_type=''` (empty
   string)? Today it falls through to the enrollment wizard, which is
   the bug that bit us. Options:
   - (a) Reject with an admin-only notice "form_type not configured."
   - (b) Default to `'custom'` and render an empty builder.
   - (c) Default to `'enrollment'` for backwards-compat.
   - Recommend (a). Need Nat's call before writing the assert.

2. `Test 3` — should the partial-merge test cover deeply nested
   updates (e.g., `settings.scheduling.timezone`) or just top-level
   keys? Today's save handler only does shallow merge. Recommend:
   match current behavior in the test; add nested-merge as a separate
   feature with its own test if/when needed.

3. `Test 5` — should we assert no method-args drift at the BOUNDARIES
   of `class-plugin.php` (every `add_action`/`add_filter`), or just
   `wp_schedule_event` targets? Recommend: just cron for now, expand
   to all hooks in Phase 5.5 if Phase 5 reveals more drift.
