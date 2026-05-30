# Builder subsystem

> **Boundary owner:** the generic FormFlow Form Builder.
> Everything in this directory powers the `form_type='builder'` (and
> legacy `form_type='custom'`) rendering pipeline. Used by Dominion PTR,
> Gravity-Forms-migrated forms, and every future non-utility client.

## What lives here

- `class-form-renderer.php` — `FormRenderer::render($schema, $instance,
  $form_data)`. Dispatched from
  `Frontend::render_custom_form()`.
- `class-form-builder.php` — admin-side builder (drag-reorder field
  list, property panel, persistence). Mounted at FF Forms → Form
  Editor Beta → Form fields task.
- `class-conditional-logic.php` — evaluates `settings.show_when` rules
  per submission to derive which fields are visible / required at
  render time.
- (companion) `public/assets/js/builder-form.js` — public behavior
  layer (single-step submit detection, scroll-gate, conditional show/hide).
  Lives under `public/assets/` because that's where WP wp_register_script
  expects asset paths; logically part of this subsystem.

## What does NOT live here

- The IntelliSOURCE Wizard pipeline lives at `includes/intellisource/`.
  Hardcoded 5-step utility-enrollment flow, account_number validation,
  scheduler-slot API, step-1-program through step-5-confirm templates.
  Never touch the builder code from an IntelliSOURCE feature, and vice
  versa.
- Database, Encryption, Security, FeatureManager, Destinations, and
  every other cross-cutting service stay where they are at the top of
  `includes/`. They're shared between subsystems.

## How code routes to this subsystem

`Frontend::render_form_shortcode()` reads the instance's `form_type`
and dispatches based on `Frontend::classify()`:

| `form_type` value | Routes to |
|---|---|
| `'builder'` | this subsystem (canonical) |
| `'custom'` | this subsystem (legacy alias) |
| `'enrollment'`, `'scheduler'` | IntelliSOURCE (`includes/intellisource/`) |
| `'intellisource_wizard'`, `'intellisource_scheduler'` | IntelliSOURCE |
| `'external'` | redirect-only path |

## Why this directory exists

The 4.0 release splits IntelliSOURCE-specific code from generic builder
code so a non-utility deployment doesn't have to inherit Smart Meter
vocabulary, account_number columns, or device_type enums in its admin
UI. CAT flagged the seam in the 2026-05-28 audit; the spec is at
`docs/superpowers/specs/2026-05-29-4.0-two-products-split.md`.

This directory is the builder side of that split. Everything inside
should be reviewable by a generic-form-builder engineer without
needing to read IntelliSOURCE code, and vice versa.
