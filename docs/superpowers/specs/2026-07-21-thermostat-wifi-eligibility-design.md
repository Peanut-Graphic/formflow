# Thermostat WiFi Eligibility Gate — Design

**Date:** 2026-07-21
**Repos:** `FORMFLOW` (Pro) and `FORMFLOW-LITE` (Lite) — ships to both
**Applies to:** IntelliSource enrollment flow, PHI instances (Pepco MD, Pepco DC, Delmarva DE, Delmarva MD)
**Status:** Approved design, pending implementation plan

---

## 1. Origin

Client request, verbatim:

> "I do not see anything in there about wifi for the stat to ensure the customer has wifi to be eligible and if not then we can lead them to the switch option, is this something that can be added?"

Confirmed flow, verbatim:

> "They pick thermostat - go through the questions - there is one that asks if they have WiFi - if yes - they continue. If no - it asks them to convert over to a switch."

Client response to that restatement: "basically, yes."

The business problem underneath the request: a Web-Programmable Thermostat physically cannot be installed in a home without WiFi. Today the enrollment form does not ask, so those enrollments complete, get scheduled, and a technician rolls a truck to a home where the install will fail. The gate exists to stop that, and to recover the customer into the Outdoor Switch program rather than losing them.

## 2. Current state

The IntelliSource enrollment flow is five steps. Both plugins carry their own copy:

| | FormFlow Pro | FormFlow Lite |
|---|---|---|
| Templates | `includes/intellisource/templates/enrollment/` | `public/templates/enrollment/` |
| Step JS | `public/assets/js/enrollment.js` | equivalent bundle |
| Validation | `includes/forms/class-form-handler.php` | `includes/forms/class-form-handler.php` |
| Schema | `includes/class-activator.php` | equivalent |

**Step 1 (`step-1-program.php`)** is already an eligibility-plus-device-selection screen. It contains:

- A required checkbox `has_ac` — "I have a Central Air Conditioner or Heat Pump and I am a customer of this utility."
- Two radio cards binding `device_type`:
  - `thermostat` — "Web-Programmable Thermostat"
  - `dcu` — "Outdoor Switch"
- A "Learn More" link per card opening `#isf-popup-thermostat` / `#isf-popup-dcu`.

`device_type` is stored as `ENUM('thermostat','dcu')` on the submissions table and flows through `includes/api/class-field-mapper.php` to the IntelliSource API. Steps 3, 4, 5 and `success.php` all already branch on it. **Converting a customer from thermostat to switch is therefore a single value change, not a re-architecture.**

Two facts established by reading the code, both of which shaped this design:

1. **Participation levels are device-agnostic.** Step 2 offers 50% / 75% / 100% cycling and renders identically regardless of `device_type`, mapping to the same `level` API field. The claim "same participation levels" is true by construction and safe to print.
2. **`form_data` is encrypted at rest** (`class-database.php` → `encryption->encrypt_array`). It cannot be queried for reporting. Any field we need to report on must be a dedicated column.

## 3. Decisions taken

| Decision | Choice | Rationale |
|---|---|---|
| No-WiFi behaviour | Offer the switch, one click | Keeps the customer in the funnel; matches the client's described flow |
| Placement | Inline on Step 1, progressive disclosure | No new step in a 5-step funnel; reuses the existing `has_ac` eligibility pattern; the pivot happens in one view |
| Data capture | Record answer + track gate-caused conversions | Proves the feature's value with a number; `form_data` encryption forces dedicated columns |
| Reach | Per-instance toggle, enabled for the four PHI instances | Other Itron utilities (Central Hudson, Entergy, PNM, Fort Collins, Dominion) untouched until someone opts in; next request costs zero dev time |
| Plugins | Both Pro and Lite | Client decision. Deepens the known three-copies duplication; mitigated by building symmetrically |

### Rejected alternatives

- **Dedicated Step 1.5.** Adds a sixth screen to a five-step funnel for a question irrelevant to every switch-chooser. Every added step costs enrollments.
- **Pre-qualify before device selection.** Theoretically better UX, but contradicts the flow the client signed off on and front-loads an irrelevant question.
- **Warn but allow thermostat anyway.** Defeats the purpose — trucks still roll to homes that cannot be installed.
- **An "I'm not sure" third option.** Creates a branch with no good destination. A helper line defining WiFi does that work instead.

## 4. Design

### 4.1 Step 1 conditional fieldset

Selecting the Thermostat card reveals a fieldset beneath the device cards. Selecting the Outdoor Switch card, or no card, keeps it hidden and not required.

```
Does your home have WiFi?
  ( ) Yes    ( ) No
  A wireless internet connection from a router in your home.
```

- Bound to `has_wifi`, values `yes` / `no`.
- Required only when `device_type === 'thermostat'`.
- The helper line does the work an "I'm not sure" option would do badly.

### 4.2 The "Yes" path

Callout stays collapsed. Continue advances to Step 2 exactly as today. No behavioural change for the majority case.

### 4.3 The "No" path — conversion callout

The callout expands in place. It carries `role="alert"` and is announced to assistive technology.

**Accessibility requirement:** the callout must not signal its meaning through red alone — colour-only signalling fails WCAG AA, which is our baseline. It pairs the red treatment with a line-art warning icon (no 3D icons, no emoji, per house style) and an explicit text heading that states the problem in words.

Copy:

> **Home WiFi is required for the thermostat**
>
> The Web-Programmable Thermostat connects to your home WiFi to receive schedule changes and take part in energy-saving events. Without it, it cannot be installed.
>
> **The Outdoor Switch gets you the same program.** Same bill credits, same participation levels, same enrollment — it is simply a different device, installed outside on your AC unit instead of on your wall. No WiFi required.
>
> [ Yes, enroll me in the Outdoor Switch program → ]   *What's the Outdoor Switch?*

The secondary link reuses the existing `#isf-popup-dcu` modal. No new content to maintain.

> **⚠ Open item — client sign-off required on one sentence.**
> "Same bill credits" is a financial claim in a regulated utility enrollment form and **cannot be verified from the codebase** — there are no incentive figures anywhere in the flow (Step 5 states only that credits land on June–October bills). The incentive schedule lives in the utility's tariff.
> "Same participation levels" **is** verifiable and safe.
> Obtain written client confirmation of the bill-credit parity before this copy goes live. If it is not forthcoming, ship the softened variant: *"The Outdoor Switch enrolls you in the same program at the same participation levels — it is simply a different device…"* and drop the credit claim. Because the strings are instance-editable content (§4.6), softening is a settings change, not a code change.

### 4.4 The conversion action

Clicking the button:

1. Sets `device_type` to `dcu`.
2. Moves the visual `.selected` state to the Outdoor Switch card, so the customer sees what changed rather than being silently reassigned.
3. Collapses the WiFi fieldset.
4. Sets the conversion flag (§4.5).
5. Advances to Step 2.

Declining — simply not clicking — leaves them on Step 1. They cannot advance with `thermostat` + `has_wifi = no`. They remain free to select the Outdoor Switch card directly, or to leave and return once they have WiFi.

Steps 3 through 5 already branch correctly on `device_type`, and Step 5 is a confirmation screen, so the customer gets a second look at what they are enrolling in before submitting.

### 4.5 Server-side enforcement and data

**Enforcement.** `class-form-handler.php` already validates `device_type` against the allowed list. It gains a rule rejecting the combination `device_type === 'thermostat'` **and** `has_wifi === 'no'`.

This is not optional. A front-end-only gate is trivially bypassable, and the entire purpose of the feature is preventing failed installs. Naming the risk is not mitigating it — the check must exist on the server.

**Schema.** Two new columns on the submissions table, added via the plugin's existing versioned upgrade routine (never by editing the original `CREATE TABLE`):

| Column | Type | Meaning |
|---|---|---|
| `has_wifi` | `ENUM('yes','no') NULL` | Customer's answer. `NULL` for switch-first enrollments and pre-feature rows. |
| `device_converted` | `TINYINT(1) NOT NULL DEFAULT 0` | `1` when the gate caused a thermostat→switch conversion. |

Neither is PII, so both are plain columns. `NULL` on `has_wifi` is meaningful and must be preserved — it distinguishes "never asked" from "answered."

**Reporting.** `class-report-generator.php` gains three figures: how many were asked, how many answered No, and how many of those No answers converted. That third number is what tells the client the feature earned its keep.

### 4.6 Configuration

The instance editor (`admin/views/instance-editor.php`) gains a **Require WiFi for thermostat** toggle, default off. Enabled for the four PHI instances.

The question label, helper text, callout heading, callout body and button label all become instance-editable content strings via the existing `isf_get_content()` mechanism, matching how `step1_title` and `form_description` already work. This is what makes the §4.3 open item cheap to resolve either way.

### 4.7 Symmetry across plugins

Every element above lands in both Pro and Lite: template, JS handler, form handler rule, schema upgrade, report figures, instance-editor toggle. Built as a matched pair in one pass so the two copies do not drift further apart.

This deepens the duplication documented in the deferred FormFlow consolidation. That is an accepted cost of the client's decision, not an oversight, and is noted here so the consolidation project inherits an accurate picture.

## 5. Testing

- **Unit:** the form-handler rule rejects `thermostat` + `no`; accepts `thermostat` + `yes`; accepts `dcu` with `has_wifi` null; accepts `dcu` + `no`.
- **Unit:** schema upgrade is idempotent and preserves existing rows, with `has_wifi` NULL on pre-feature submissions.
- **Integration:** the conversion action produces a submission with `device_type = dcu`, `has_wifi = no`, `device_converted = 1`, and the IntelliSource field mapper emits the DCU equipment code.
- **Integration:** with the instance toggle off, Step 1 renders exactly as it does today and no WiFi field is required — verified against a non-PHI instance.
- **Accessibility:** callout is reachable and announced via `role="alert"`; meaning survives with colour removed; contrast meets AA; the whole path is keyboard-operable.
- **Regression:** the switch-first path and the thermostat-with-WiFi path both complete end to end, unchanged.

## 6. Open items

1. **Client sign-off on the "same bill credits" sentence.** Blocks the final copy, not the build. See §4.3.
2. **Confirm which plugin each live PHI site runs.** The decision to ship both makes this non-blocking, but the deploy order depends on it.
3. **Existing scheduled thermostat enrollments in homes without WiFi.** Out of scope for this change. Worth raising with the client separately — this gate is preventative and does nothing about installs already in the queue.
