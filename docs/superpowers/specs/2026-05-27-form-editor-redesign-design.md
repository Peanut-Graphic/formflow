# FormFlow Pro — Form Editor Redesign

**Status:** spec / pre-implementation
**Owner:** Nat (Peanut Graphic)
**Target release:** 3.0.0
**Driver:** The current FormFlow Pro instance editor is an 1,300-line PHP
view that stacks 7 wizard panels — each with 3–7 nested pods — into one
overwhelming page. Quick Edit mode flattens all of it into a single
30-pod vertical scroll. Pain points stated by Nat 2026-05-27:
> "this whole plugin needs layout and spacing ux/ui work — but
> especially this page"

Top pains, per Nat:
- **A — IA/structure:** too many sections at once; no way to find anything
- **E — Two-mode missing:** no way to hand a Dominion-style client admin a
  "you can only touch these five things" view

This spec replaces the existing instance editor with a task-overview +
two-pane editor pattern, behind a feature flag, with role-based mode
switching baked in from day one.

---

## 1. Goals

1. **Task-oriented IA.** The editor's landing screen is a grid of tasks
   ("Setup", "Delivery", "Copy", …) with status badges, not a wall of
   form fields.
2. **Two-mode by capability.** Site admins see the full 9-task dev mode;
   client admins (Dominion / Itron operators) see a 4-task client mode.
   Mode is granted via a capability, not a global flag — a user with both
   capabilities sees a "Editing as: Dev / Client" switcher.
3. **Inline issue surfacing.** Required-but-empty fields, missing creds,
   inactive destinations are flagged inline next to the field and on the
   relevant task card on the overview. No more global notice strips
   competing with the form.
4. **Sticky, predictable save.** Save-on-blur per field, sticky action
   bar with "Saved 8s ago" status, no "did I lose my work?" anxiety.
5. **Feature-flagged cutover.** Old editor stays mounted during cutover.
   No data migration. ~30-day soak before old editor is deleted.

## 2. Non-goals

- **Not** a visual redesign of the form-renderer (the public-facing form).
- **Not** a rewrite of the field-renderer JS (`form-builder.js`) — the
  drag-reorder + per-field property panel stays; only the wrapper around
  it changes.
- **Not** a database schema migration. The instance row keeps its
  current columns; only the admin UI changes.
- **Not** the IntelliSOURCE rebrand (separate, larger effort — see
  [[2026-05-26-destinations-subsystem]] for context). This redesign
  works regardless of the rebrand outcome.
- **Not** a Submissions or Data tab redesign (those keep their existing
  URLs and behavior). The client-mode "Submissions" task in this design
  is a read-only summary card that deep-links to the existing Data tab
  in dev mode.

---

## 3. User types & capabilities

| User | WP role | Custom cap | Mode |
|---|---|---|---|
| Peanut Graphic dev | Administrator | `isf_dev_mode` (auto-granted to Admin on plugin activate) | Dev mode (9 tasks) |
| Client admin (Dominion dem-noc, etc.) | Administrator | `isf_dev_mode` revoked manually | Client mode (4 tasks) |
| Multi-role user | Administrator + manual `isf_dev_mode` grant + `isf_client_mode` test cap | Sees a "Editing as: Dev ▾" switcher in the editor header |

`isf_dev_mode` is added on plugin activation, granted to every role
that has `manage_options`. Site owner can revoke from specific users
via any user-role plugin (User Role Editor, Members, etc.) or via a
small CLI command we'll ship: `wp formflow user-mode <user> dev|client`.

The mode preference (when the user has both caps) persists in
user-meta `isf_editor_mode_preference`.

---

## 4. Architecture: task overview + two-pane editor

### 4.1 Routes

| URL | Purpose |
|---|---|
| `admin.php?page=isf-form&id=N` | Task overview (grid of cards) for instance N |
| `admin.php?page=isf-form&id=N&task=delivery` | Single-task editor (single-pane if no sub-rail) |
| `admin.php?page=isf-form&id=N&task=delivery&dest=sftp-1` | Two-pane editor: sub-rail = destinations list, detail = sftp-1 |
| `admin.php?page=isf-form&id=N&task=fields&field=f3` | Two-pane: sub-rail = field list, detail = field f3 |
| `admin.php?page=isf-form` (no id) | Forms list (replaces existing Dashboard form table when flag on) |

The single `isf-form` menu slug + `task=` parameter gives bookmarkable,
back-button-friendly URLs without burning seven separate WP admin pages.
The router lives in `admin/class-form-editor-router.php`.

### 4.2 Task list

**Dev mode (9 tasks).** Connector + Scheduling auto-grey-out when
`instance->form_type === 'custom'`.

| Slug | Icon | Default status | Validator |
|---|---|---|---|
| `setup` | ⚙ | ✓ if name+slug+type present | `name && slug && form_type` |
| `fields` | 📝 | ✓ if at least one field | `count(form_schema.steps[].fields) > 0` |
| `connector` | 🔌 | greyed if custom; else ⚠ if endpoint blank | `form_type==='custom' ? null : api_endpoint != ''` |
| `delivery` | 📤 | ⚠ if zero active destinations OR any active has missing required | per-destination `validate_config()` |
| `scheduling` | 📅 | greyed if custom; else ⊙ defaults / ✓ customized | form_type-dependent |
| `copy` | 💬 | ⊙ if defaults / ✓ if customized | `settings.content.form_title` or similar non-default |
| `notifications` | ✉️ | ⊙ defaults / ✓ customized | `settings.email.send_confirmation && from_address != ''` |
| `tracking` | 📈 | ⊙ off / ✓ enabled | `settings.gtm.enabled || settings.analytics.enabled` |
| `advanced` | 🛠 | no badge (always ⊙ informational) | n/a |

**Client mode (4 tasks).** Same data, restricted task list:

| Slug | What client admin can do |
|---|---|
| `delivery` | Update destination credentials only — no add/remove destinations, no remote-path edit |
| `copy` | Update title, description, button labels, T&Cs |
| `notifications` | Update confirmation email content + "from" name |
| `submissions` | View-only count + recent table + status indicators |

Permission gating is per-field, not per-form-element. The dev/client cap
check happens server-side in the AJAX save handler — any blocked field
in the POST is dropped before update. JS hides the input; server
enforces.

### 4.3 Status indicator system

Four states, computed by per-task validators:

| Color | Symbol | State | Trigger |
|---|---|---|---|
| 🟢 green | ✓ | Complete | All required fields set, last save successful |
| 🔴 red | ⚠ | Needs attention | Missing required config (e.g., active destination with empty host) |
| 🟡 yellow | ⊙ | Defaults | Working with default values — admin hasn't customized yet |
| ⚪ grey | — | Not applicable | Conditional task irrelevant for this form_type |

Validators implemented in `includes/form-editor/class-task-validator.php`
as static methods, one per task slug:

```php
TaskValidator::status_for('delivery', $instance) // returns 'ok'|'attention'|'default'|'na'
```

Overview cards call this on render. The detail pages also call it for
the inline strip ("⚠ Two required fields are empty" pattern).

### 4.4 Two-pane editor pattern

Every task that has a list of items (destinations, fields, blocked
dates, etc.) uses the same layout:

```
┌────────────────────────────────────────────────────────────────┐
│ Forms / Dominion PTR / 📤 Delivery     ⚠ Action needed         │  ← top breadcrumb + status pill
├──────────────────┬─────────────────────────────────────────────┤
│ Destinations     │  Dominion PTR SFTP             [ ] Active   │
│                  │  SFTP destination · v1.0.0                  │
│ → ⚠ SFTP (1)     │                                             │
│                  │  ⚠ Two required fields are empty…           │  ← inline status strip
│ ＋ Add           │                                             │
│   destination    │  Host *                                     │
│                  │  ┌────────────────────────────────────┐     │
│ Recent           │  └────────────────────────────────────┘     │
│ deliveries       │                                             │
│ No submissions   │  Port            Username *                 │
│ yet              │  ┌────┐          ┌────────────────┐         │
│                  │  └────┘          └────────────────┘         │
│                  │                                             │
│                  │  ▸ Advanced (path, format, filename…)       │  ← collapsed advanced
│                  │                                             │
├──────────────────┴─────────────────────────────────────────────┤
│ Auto-saved 8s ago      [Test connection]   [Save]              │  ← sticky action bar
└────────────────────────────────────────────────────────────────┘
```

Tasks WITHOUT a list (Setup, Notifications, Advanced) render single-pane
— the sub-rail is omitted and the detail area takes full width.

### 4.5 Inline issue surfacing

The current editor relies on global admin notices at the top of the page
for "ISF_ENCRYPTION_KEY not defined", "Action Scheduler not found", etc.
These are demoted on the new editor pages:

- `Plugin::display_admin_notices()` already gates against
  `isf-instance-editor` screen id (added in 2.8.7-baseline). Extend the
  gate to `isf-form` as well.
- In-context warnings render inside the relevant task's detail pane
  (e.g., the SFTP destination's encryption-key warning lives on the
  Delivery task's destination card, not at the top of the page).

### 4.6 Auto-save + sticky action bar

- **Save-on-blur** for individual text/select/checkbox fields. The
  existing `formflow_save_instance` AJAX handler is reused; the JS
  layer debounces (500ms) and submits only the changed field.
- **Sticky action bar** at the bottom of the detail pane shows save
  status: "Saving…" / "Saved 8s ago" / "Unsaved changes ⚠".
- Explicit **Save** button still present for users who prefer it (and
  for batched changes like editing multiple fields in a destination
  config).
- **Test Connection** (per-destination) sits next to Save in the action
  bar — result renders as an inline strip immediately above the action
  bar, not as a toast/modal.

### 4.7 Mode switcher

Visible only to users who have BOTH `isf_dev_mode` and `manage_options`.
Renders as a small dropdown in the editor header:

```
Forms / Dominion PTR     [Editing as: Dev ▾]
                                      └─ Dev (9 tasks)
                                         Client (4 tasks)
```

Selection persists in user-meta `isf_editor_mode_preference`. On task
overview render, the visible-tasks set is filtered by the user's
effective mode.

---

## 5. Feature flag + cutover

### 5.1 Flag mechanism

Constant in wp-config.php OR option, whichever is set first:

```php
define('ISF_NEW_EDITOR', true);  // wp-config override (preferred)
// OR
update_option('isf_new_editor', '1'); // per-site DB flag (admin toggle)
```

Resolution:

```php
function isf_new_editor_enabled(): bool {
    if (defined('ISF_NEW_EDITOR')) return (bool) ISF_NEW_EDITOR;
    return get_option('isf_new_editor', '0') === '1';
}
```

A simple admin toggle in **FF Forms → Tools → New Editor (Beta)** lets
site owners flip the flag without editing wp-config.

### 5.2 Cutover behavior

| Flag state | Old editor (`isf-instance-editor`) | New editor (`isf-form`) |
|---|---|---|
| OFF (default) | Active, mounted in menu | Mounted but inaccessible from menu — only via direct URL |
| ON | Mounted but redirects to new editor when accessed | Active, mounted in menu |

Both editors read/write the same `wp_isf_instances` row. No data
migration. Switching the flag is reversible at any time.

### 5.3 Sunset timeline

| Release | Behavior |
|---|---|
| 3.0.0 | New editor ships behind `ISF_NEW_EDITOR` flag (OFF by default). Old editor unchanged. Tools page exposes toggle. |
| 3.1.0 | Flag defaults flip to ON. Old editor still mounted, redirects when flag on. Sticky admin notice "old editor will be removed in N days" once 3.2 cycle starts. |
| 3.2.0 | Old editor deleted: `admin/views/instance-editor.php`, its inline JS, the `isf-instance-editor` menu slug, the Quick Edit toggle, all associated CSS. ~30 days of soak between 3.1 ship and 3.2 ship is the cutover window. |

### 5.4 Quick Edit fate

Quick Edit toggle stays in the old editor for the duration of its life.
It is **not** carried into the new editor — the task overview IS the new
"see everything at a glance" surface. Any user who needs the old
flat-stack view can flip the flag off temporarily.

---

## 6. File layout

```
admin/views/form-editor/
  router.php                  # entry point; dispatches to overview or task by URL
  overview.php                # task grid (the landing screen)
  layout.php                  # shared chrome — breadcrumb, mode switcher, sticky bar wrapper
  tasks/
    setup.php
    fields.php
    connector.php
    delivery.php
    scheduling.php
    copy.php
    notifications.php
    tracking.php
    advanced.php
    submissions.php           # client-mode only
admin/assets/
  css/form-editor.css         # scoped to .isf-form-editor — won't bleed
  js/form-editor.js           # router shim + save-on-blur + sub-rail nav
includes/form-editor/
  class-form-editor-router.php
  class-task-registry.php     # task definitions, capability gates, validators
  class-task-validator.php    # per-task status logic
  class-mode-resolver.php     # capability + user-meta → effective mode
```

Existing `admin/views/instance-editor.php` and its inline 250-line JS
block stay intact until 3.2 deletion.

Pods that the existing editor defines inline get **extracted** into
small per-pod partials in `admin/views/form-editor/tasks/_pods/` —
allowing the new editor to compose them without copy-paste. The old
editor keeps using inline markup until deletion; it doesn't depend on
the new pod partials.

---

## 7. Visual design notes

- **Default to WordPress admin styles.** Don't fight the WP admin theme.
  The redesign is structural (IA, spacing, grouping), not visual chrome.
- **Spacing rhythm:** 8/16/24 base scale. Pods get 16px internal
  padding, 24px between pods.
- **Type:** WP default body 13px, section headers 15px medium, page
  title 23px regular. No custom font.
- **Icons:** dashicons everywhere. Flat, no 3D, no AI-generated glyphs.
  (Per the Peanut design rule.)
- **Colors:** WP admin palette — `#2271b1` blue for primary, `#d63638`
  for errors, `#00a32a` for success, `#dba617` for warning, `#646970`
  for secondary text.
- **WCAG AA baseline:** all interactive elements ≥ 44×44 touch target,
  contrast ≥ 4.5:1 for body text, focus rings preserved (don't
  outline:none).
- **Sticky elements** (top breadcrumb, bottom action bar) use
  `position: sticky` with appropriate top/bottom anchors so they don't
  break WP admin's own sticky header.
- **Dark mode:** out of scope for this redesign. WP admin doesn't have a
  first-class dark mode, and a half-implementation would create more
  problems than it solves.

---

## 8. Backward compatibility

- **No DB schema changes.** All new behavior reads/writes the existing
  instance row's columns + settings JSON.
- **No AJAX action changes.** `formflow_save_instance`,
  `formflow_test_destination`, etc., are reused as-is. New editor JS
  POSTs to the same endpoints.
- **Old shortcode URLs work.** `[isf_form instance="dominion"]` keeps
  rendering via existing public-side code.
- **External integrations** that hooked `do_action('isf_*')` actions
  continue to fire — those are server-side hooks, not affected by admin
  UI changes.
- **Capability registration** on plugin activation is additive
  (`add_cap('isf_dev_mode')`). Plugin deactivate does NOT remove the cap
  (preserves admin grants across deactivate/reactivate cycles).

---

## 9. Out of scope (explicit cuts)

| Item | Why deferred |
|---|---|
| IntelliSOURCE rebrand (namespace, slug, etc.) | Separate, larger effort; new editor works with or without it |
| Visual form-renderer redesign (public-facing form) | Different surface, different audience |
| Field-builder JS rewrite | Existing `form-builder.js` drag-reorder works; only its wrapper changes |
| Submissions / Data tab redesign | Keeps existing routes; client-mode "Submissions" task is a thin read-only summary |
| Multi-instance dashboards / "all forms at a glance" | A separate "forms list" redesign — not blocked by this work but not in scope here |
| Dark mode | WP admin doesn't have a first-class dark mode |
| Mobile/tablet optimization | WP admin in general is desktop-first; address as separate pass if needed |
| Internationalization beyond existing i18n strings | All new strings get `__()` calls; no new translation infra |

---

## 10. Open questions (resolve during implementation plan)

1. **Form Fields task UX.** The list/detail pattern works for destinations
   (a handful of items). The form may have 30+ fields. Need to confirm:
   does the field list scroll independently of the detail pane, or do
   we add a search/filter at the top of the field list?
2. **Auto-save conflict resolution.** If two admin sessions edit the
   same instance concurrently, last-write-wins is the current behavior.
   Should the new editor surface a "this was just edited by …" warning
   when revisions diverge? (Probably 3.1, not 3.0.)
3. **Mobile fallback.** If the editor is opened on a phone, the
   sub-rail + detail two-pane is going to be cramped. Auto-collapse to
   stacked single-pane below ~700px? (Likely yes — defer specifics to
   the implementation plan.)
4. **The "Editing as" switcher visual** — header dropdown vs a toggle
   pill in the page chrome. Mock both during implementation.

---

## 11. Sequencing (overview — full plan in writing-plans output)

Two-week build at one engineer's pace:

**Week 1 — foundation**
1. Router + capability/mode resolver + task registry
2. Overview page (task grid + status badges)
3. Shared layout (breadcrumb / mode switcher / sticky action bar)
4. Setup task (simplest — proves the single-pane pattern)
5. Feature flag + Tools toggle UI

**Week 2 — port tasks + cutover**
1. Delivery task (proves two-pane + Test Connection pattern)
2. Copy + Notifications tasks (simple, port existing pods)
3. Form Fields task (composes existing form-builder.js)
4. Connector + Scheduling + Tracking + Advanced tasks
5. Submissions task (client-mode read-only)
6. End-to-end testing both modes, both flag states
7. CHANGELOG + version bump 2.9.x → 3.0.0
8. Soak

---

## 12. Validation / smoke tests before 3.0 ship

- [ ] Fresh install with flag OFF — old editor unchanged, no regressions
- [ ] Fresh install with flag ON — full 9-task overview renders for admin
- [ ] User without `isf_dev_mode` — only 4-task client overview renders
- [ ] User with both — mode switcher visible, switching persists
- [ ] Dominion-style custom form — Connector + Scheduling cards grey out
- [ ] IntelliSOURCE-style enrollment form — all 9 tasks active
- [ ] Save-on-blur fires on field changes; sticky bar updates "Saved Xs ago"
- [ ] Test Connection on SFTP destination returns inline result strip
- [ ] Flag flip mid-session — admin can still reach old editor at its slug for emergency fallback
- [ ] WP admin accessibility scan (Axe / keyboard nav / focus rings)

---

## 13. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Feature flag drift — sites stuck on old editor indefinitely | Medium | 3.1 flips default to ON + nag notice; 3.2 deletes old code. ~30-day soak. |
| Auto-save races overwrite a user's typing | Low | Debounce 500ms, save only changed field, last-write-wins per field (not per row) |
| Sub-rail pattern breaks on field lists with 50+ items | Medium | Search/filter at top of rail; virtual scroll if needed (defer to plan) |
| Client-mode field gating bypassed via direct AJAX POST | Low | Server-side gate in `formflow_save_instance` strips fields the user can't write |
| Cap-driven mode confuses multi-site admins | Low | Mode switcher in header makes effective mode always visible |

---

## References

- Sister spec: `2026-05-26-destinations-subsystem.md` (the Delivery task's
  destination concept came from that work).
- Live artifact: the existing editor at
  `admin/views/instance-editor.php` (1,300 lines).
- 2.8.7 baseline IA fixes (sticky Quick Edit nav, demoted notices,
  Mode Settings warning, scrubbed Delmarva phone, gated Marketplace
  fixture cards) ship in this same 2.9.x line and are preserved.

CAT + MAX cross-reviewed the 2.8.7 baseline + Destinations design and
flagged most of the same pain points this redesign addresses
(IntelliSOURCE coupling, IA flatness, hardcoded defaults). Wanting
another review pass on this design before implementation begins.
