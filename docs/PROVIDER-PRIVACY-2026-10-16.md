# IntelliSource provider contact privacy — 2026-10-16

PR #118 masks the initial account-validation JSON, but its stored provider surname, email and street were still available in subsequent step HTML and the complete resume JSON. Account/ZIP validation and a session/resume token establish session continuity, not independent utility-account ownership.

`ISF\PublicFormData` separates raw provider validation and normalized contact into `_provider_validation`, legacy ambiguous data into `_legacy_form_data`, and explicit caller input into a versioned `_public_input` envelope. The envelope is HMAC-signed using the WordPress auth salt, a Pro-specific domain, session ID, instance ID and field JSON. Caller input and nested address keys are allowlisted; caller-supplied private buckets and provider identifiers are excluded. Existing legacy sessions preserve their data privately but must re-enter contact details to display/recover them.

Every IntelliSource wizard/scheduler HTML render and resume response uses the public projection. Saves and final enrollment merge only signed prior caller input plus explicit sanitized new input. Server account identifiers remain authoritative for provider APIs. Successful revalidation clears prior appointment references and pending schedule/consent/contact input; booking requires fresh server-obtained availability. Completed sessions cannot rebind their account.

Enrollment confirmation mail and SMS retain explicit caller contact. Scheduler confirmation mail may use normalized provider contact only in a temporary notification payload addressed to the provider mailbox; this payload is never persisted, projected publicly or used for caller resume mail. Existing configured support CC recipients remain the administrator's configuration. Internal hooks/integrations retain server-side submission state; this patch does not change generic-builder or Dominion registration/routing, licensing, the deliberate masked summary or medical-condition flag.

## Verification

- Exact parent #118 replay: two handler privacy tests fail, reproducing disclosure in later render/resume responses with synthetic provider records.
- Patched PHP 8.4 / PHPUnit 9.6.36 full local suite: **596 tests, 2,613 assertions, no skips or risky tests**. Includes eight real-handler privacy flows / 208 assertions and six helper provenance/equipment tests.
- Covers every enrollment/scheduler render, attacker-selected resume mailbox, explicit caller navigation/save/resume/mapping, full simulated enrollment and caller mail recipient, legacy re-entry, forged provenance, signature tampering/cross-session denial, bounded nested addresses, retained Pro equipment input, private scheduler mail recipient, stale scheduling reset and completed-session rebind rejection.
- PHP syntax and whitespace checks pass. Earlier PHP 8.5 run produced 46 existing Reflection `setAccessible` deprecation-related risky tests; no suppression was added. All changed behavior was requalified on PHP 8.4.

Tests use mocked WordPress/provider/mail boundaries and synthetic fixtures. They do not prove live vendor acceptance, real WordPress/package staging, hosted CI, or production qualification. No live provider calls, customer mail/SMS sends, merge, deployment or licensing/Dominion changes occurred. This follow-up targets #118's branch first.
