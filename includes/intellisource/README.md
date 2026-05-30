# IntelliSOURCE subsystem

> **Boundary owner:** the IntelliSOURCE Wizard pipeline.
> Everything in this directory is specific to the hardcoded 5-step
> utility-enrollment flow that powers Pepco / Delmarva / similar
> Itron-integrated deployments.

## What lives here

- `templates/enrollment/` — step-1-program through step-5-confirm + success
- `templates/scheduler/` — the scheduling variant of the wizard
- `templates/partials/` — wizard-shared bits (progress bar)
- (future) `class-wizard-renderer.php` — the dispatch target for
  `form_type IN ('intellisource_wizard','intellisource_scheduler')`
- (future) `class-account-validator.php` — Itron account-number checks
- (future) `class-scheduler-api.php` — appointment-slot fetch + booking

## What does NOT live here

- The generic FormFlow Builder lives at `includes/builder/`. If you're
  building a form for a non-utility client (Dominion PTR, anything
  imported from Gravity Forms, anything in the new "Custom Form Builder"
  flow), that's the builder path — never touch this directory.
- Database, Encryption, Security, FeatureManager, Destinations, and
  every other cross-cutting service stay where they are at the top of
  `includes/`. They're shared between subsystems.

## How code routes to this subsystem

`Frontend::render_form_shortcode()` reads the instance's `form_type`
and dispatches based on `Frontend::classify()`:

| `form_type` value | Routes to |
|---|---|
| `'enrollment'` | this subsystem (legacy alias) |
| `'scheduler'` | this subsystem (legacy alias) |
| `'intellisource_wizard'` | this subsystem (canonical) |
| `'intellisource_scheduler'` | this subsystem (canonical) |
| `'custom'` | builder (`includes/builder/`) |
| `'builder'` | builder |
| `'external'` | redirect-only path |

## Why this directory exists

The 4.0 release splits IntelliSOURCE-specific code from generic builder
code so a non-utility deployment doesn't have to inherit Smart Meter
vocabulary, account_number columns, or device_type enums in its admin
UI. CAT flagged the seam in the 2026-05-28 audit; the spec is at
`docs/superpowers/specs/2026-05-29-4.0-two-products-split.md`.

This directory is the IntelliSOURCE side of that split. Everything
inside should be reviewable by an IntelliSOURCE-program engineer
without needing to read builder code, and vice versa.
