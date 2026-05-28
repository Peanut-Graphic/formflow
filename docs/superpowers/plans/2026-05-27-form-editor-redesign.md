# Form Editor Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the 1,300-line FormFlow Pro instance editor with a task-oriented overview + two-pane editor pattern, behind an `ISF_NEW_EDITOR` feature flag, with role-based dev/client mode switching. Ships as v3.0.0.

**Architecture:** New menu slug `isf-form` with a router that dispatches by `?task=` URL param. Task overview = grid of cards with per-task status badges (computed by validators). Each task screen = breadcrumb + optional sub-rail (list/detail pattern) + detail pane + sticky action bar. Mode (dev / client) resolved per-user via `isf_dev_mode` capability + user-meta preference. Both editors read/write the same `wp_isf_instances` row; no DB schema change.

**Tech Stack:** PHP 8.0+, WordPress 6.0+, PHPUnit 9.6 (with Brain/Monkey for WP function mocking), existing FormFlow admin-ajax infrastructure (`formflow_*` actions from 2.9.3), existing form-builder.js for the Fields task. No new vendored runtime deps. New CSS scoped to `.isf-form-editor`.

**Reference spec:** `docs/superpowers/specs/2026-05-27-form-editor-redesign-design.md`

---

## File Structure

### Created

```
includes/form-editor/
  class-feature-flag.php              # isf_new_editor_enabled() resolver
  class-mode-resolver.php             # capability + user-meta → 'dev'|'client'
  class-task-registry.php             # task definitions + capability gates
  class-task-validator.php            # per-task status computation
  class-router.php                    # URL → task screen dispatcher
  class-field-gate.php                # client-mode field write gate
  class-capabilities.php              # caps registration on activation

admin/views/form-editor/
  layout.php                          # chrome: breadcrumb, mode switcher, sticky bar
  overview.php                        # task grid landing
  no-task.php                         # 404 fallback when ?task= is unknown
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
    submissions.php
  partials/
    task-card.php                     # one card in the overview grid
    sub-rail.php                      # left list wrapper (list/detail pattern)
    inline-status-strip.php           # red/yellow/green inline issue surface
    sticky-action-bar.php             # bottom Save / Test / status line

admin/assets/
  css/form-editor.css                 # scoped to .isf-form-editor
  js/form-editor.js                   # save-on-blur, sub-rail nav, mode switcher

admin/views/tabs/
  tools-new-editor.php                # toggle UI for ISF_NEW_EDITOR option

tests/Unit/FormEditor/
  FeatureFlagTest.php
  ModeResolverTest.php
  TaskRegistryTest.php
  TaskValidatorTest.php
  RouterTest.php
  FieldGateTest.php
  CapabilitiesTest.php
```

### Modified

- `formflow.php` — register new menu, bootstrap form-editor classes, hook activator for caps
- `includes/class-activator.php` — call `Capabilities::register_on_activate()`
- `includes/class-plugin.php` — gate the existing `isf-instance-editor` menu when flag ON; register redirect
- `admin/class-admin.php` — extend `ajax_save_instance` to pass through `FieldGate::strip_blocked_fields()` for client-mode users
- `admin/views/tabs/tools-settings.php` — add new section linking to Tools → New Editor toggle (or surface inline)
- `CHANGELOG.md` — 3.0.0 entry
- `formflow.php` + `formflow/formflow.php` — version bump

### Deleted (in 3.2 cycle, not this plan)

- `admin/views/instance-editor.php`
- Inline JS for Quick Edit + wizard panel switching
- CSS blocks scoped to `.isf-wizard-*`

---

## Task 1: Feature flag resolver

**Files:**
- Create: `includes/form-editor/class-feature-flag.php`
- Test: `tests/Unit/FormEditor/FeatureFlagTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/FormEditor/FeatureFlagTest.php`:
```php
<?php
namespace ISF\Tests\Unit\FormEditor;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ISF\FormEditor\FeatureFlag;

class FeatureFlagTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }
    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_constant_true_enables(): void {
        if (!defined('ISF_NEW_EDITOR')) define('ISF_NEW_EDITOR', true);
        $this->assertTrue(FeatureFlag::is_enabled());
    }

    public function test_option_fallback_when_constant_undefined(): void {
        // Cannot un-define ISF_NEW_EDITOR; use a separate process boundary.
        // Validate option fallback via a second flag name for testability.
        Functions\when('get_option')->justReturn('1');
        $this->assertTrue(FeatureFlag::is_enabled_via_option_for_test('isf_new_editor'));
    }

    public function test_option_off_returns_false(): void {
        Functions\when('get_option')->justReturn('0');
        $this->assertFalse(FeatureFlag::is_enabled_via_option_for_test('isf_new_editor'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/FormEditor/FeatureFlagTest.php`
Expected: FAIL with "class ISF\FormEditor\FeatureFlag not found"

- [ ] **Step 3: Write minimal implementation**

`includes/form-editor/class-feature-flag.php`:
```php
<?php
namespace ISF\FormEditor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the ISF_NEW_EDITOR feature flag.
 *
 * Resolution order:
 *   1. `define('ISF_NEW_EDITOR', true|false)` in wp-config.php (wins if defined)
 *   2. `update_option('isf_new_editor', '1'|'0')` admin toggle
 *   3. Default: OFF
 */
class FeatureFlag {

    public const CONSTANT = 'ISF_NEW_EDITOR';
    public const OPTION   = 'isf_new_editor';

    public static function is_enabled(): bool {
        if (defined(self::CONSTANT)) {
            return (bool) constant(self::CONSTANT);
        }
        return self::is_enabled_via_option_for_test(self::OPTION);
    }

    // Separate method so unit tests can validate the option path
    // without colliding with the (one-shot) ISF_NEW_EDITOR define().
    public static function is_enabled_via_option_for_test(string $option_name): bool {
        return get_option($option_name, '0') === '1';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/FormEditor/FeatureFlagTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add includes/form-editor/class-feature-flag.php tests/Unit/FormEditor/FeatureFlagTest.php
git commit -m "feat(form-editor): feature flag resolver — ISF_NEW_EDITOR constant + option fallback"
```

---

## Task 2: Capabilities registration

**Files:**
- Create: `includes/form-editor/class-capabilities.php`
- Modify: `includes/class-activator.php` (add `Capabilities::register_on_activate()`)
- Test: `tests/Unit/FormEditor/CapabilitiesTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/FormEditor/CapabilitiesTest.php`:
```php
<?php
namespace ISF\Tests\Unit\FormEditor;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ISF\FormEditor\Capabilities;
use Mockery;

class CapabilitiesTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }
    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_register_on_activate_adds_dev_cap_to_admin_role(): void {
        $admin_role = Mockery::mock('WP_Role');
        $admin_role->shouldReceive('add_cap')->once()->with(Capabilities::DEV_MODE);
        Functions\when('get_role')->justReturn($admin_role);

        Capabilities::register_on_activate();

        $this->assertTrue(true); // assertion is in the Mockery expectation above
    }

    public function test_dev_mode_cap_name(): void {
        $this->assertSame('isf_dev_mode', Capabilities::DEV_MODE);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/FormEditor/CapabilitiesTest.php`
Expected: FAIL with "class ISF\FormEditor\Capabilities not found"

- [ ] **Step 3: Implement**

`includes/form-editor/class-capabilities.php`:
```php
<?php
namespace ISF\FormEditor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers + resolves the custom capability that drives dev/client mode.
 *
 * `isf_dev_mode` is granted to the Administrator role on activation.
 * Site owners revoke per-user via any role-editor plugin to lock down
 * client admins to client mode. Deactivation does NOT remove the cap —
 * preserves grants across deactivate/reactivate cycles.
 */
class Capabilities {

    public const DEV_MODE = 'isf_dev_mode';

    /**
     * Called from the plugin activator. Idempotent.
     */
    public static function register_on_activate(): void {
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(self::DEV_MODE);
        }
    }
}
```

- [ ] **Step 4: Wire into activator**

Modify `includes/class-activator.php` — add inside the `activate()` method, after the dbDelta calls:

```php
// Form-editor capabilities (3.0.0+)
require_once ISF_PLUGIN_DIR . 'includes/form-editor/class-capabilities.php';
\ISF\FormEditor\Capabilities::register_on_activate();
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit tests/Unit/FormEditor/CapabilitiesTest.php`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add includes/form-editor/class-capabilities.php includes/class-activator.php tests/Unit/FormEditor/CapabilitiesTest.php
git commit -m "feat(form-editor): register isf_dev_mode capability on activation"
```

---

## Task 3: Mode resolver

**Files:**
- Create: `includes/form-editor/class-mode-resolver.php`
- Test: `tests/Unit/FormEditor/ModeResolverTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/FormEditor/ModeResolverTest.php`:
```php
<?php
namespace ISF\Tests\Unit\FormEditor;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ISF\FormEditor\ModeResolver;

class ModeResolverTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }
    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_user_with_dev_cap_defaults_to_dev_mode(): void {
        Functions\when('current_user_can')->alias(fn ($cap) => $cap === 'isf_dev_mode');
        Functions\when('get_user_meta')->justReturn('');
        $this->assertSame(ModeResolver::MODE_DEV, ModeResolver::effective_mode());
    }

    public function test_user_without_dev_cap_gets_client_mode(): void {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_user_meta')->justReturn('');
        $this->assertSame(ModeResolver::MODE_CLIENT, ModeResolver::effective_mode());
    }

    public function test_user_with_dev_cap_can_prefer_client_mode(): void {
        Functions\when('current_user_can')->alias(fn ($cap) => $cap === 'isf_dev_mode');
        Functions\when('get_user_meta')->justReturn('client');
        Functions\when('get_current_user_id')->justReturn(1);
        $this->assertSame(ModeResolver::MODE_CLIENT, ModeResolver::effective_mode());
    }

    public function test_user_without_dev_cap_cannot_force_dev_via_meta(): void {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_user_meta')->justReturn('dev');
        Functions\when('get_current_user_id')->justReturn(1);
        $this->assertSame(ModeResolver::MODE_CLIENT, ModeResolver::effective_mode());
    }

    public function test_has_both_modes_true_when_user_has_dev_cap(): void {
        Functions\when('current_user_can')->alias(fn ($cap) => $cap === 'isf_dev_mode');
        $this->assertTrue(ModeResolver::has_both_modes());
    }

    public function test_has_both_modes_false_without_dev_cap(): void {
        Functions\when('current_user_can')->justReturn(false);
        $this->assertFalse(ModeResolver::has_both_modes());
    }
}
```

- [ ] **Step 2: Run — expect failure**

Run: `vendor/bin/phpunit tests/Unit/FormEditor/ModeResolverTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement**

`includes/form-editor/class-mode-resolver.php`:
```php
<?php
namespace ISF\FormEditor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the effective editing mode for the current user.
 *
 * - Users without `isf_dev_mode` always get client mode (no override possible).
 * - Users with `isf_dev_mode` default to dev mode, but may opt into client
 *   mode via the user-meta preference. (This is the "Editing as: Client"
 *   switcher in the editor header.)
 */
class ModeResolver {

    public const MODE_DEV    = 'dev';
    public const MODE_CLIENT = 'client';

    public const PREF_META_KEY = 'isf_editor_mode_preference';

    public static function effective_mode(): string {
        if (!self::has_both_modes()) {
            return self::MODE_CLIENT;
        }
        $pref = get_user_meta(get_current_user_id(), self::PREF_META_KEY, true);
        return $pref === self::MODE_CLIENT ? self::MODE_CLIENT : self::MODE_DEV;
    }

    public static function has_both_modes(): bool {
        return (bool) current_user_can(Capabilities::DEV_MODE);
    }

    public static function set_preference(string $mode): bool {
        if (!in_array($mode, [self::MODE_DEV, self::MODE_CLIENT], true)) {
            return false;
        }
        return (bool) update_user_meta(get_current_user_id(), self::PREF_META_KEY, $mode);
    }
}
```

- [ ] **Step 4: Run — expect pass**

Run: `vendor/bin/phpunit tests/Unit/FormEditor/ModeResolverTest.php`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add includes/form-editor/class-mode-resolver.php tests/Unit/FormEditor/ModeResolverTest.php
git commit -m "feat(form-editor): mode resolver — capability + user-meta → effective mode"
```

---

## Task 4: Task registry

**Files:**
- Create: `includes/form-editor/class-task-registry.php`
- Test: `tests/Unit/FormEditor/TaskRegistryTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/FormEditor/TaskRegistryTest.php`:
```php
<?php
namespace ISF\Tests\Unit\FormEditor;

use PHPUnit\Framework\TestCase;
use ISF\FormEditor\TaskRegistry;
use ISF\FormEditor\ModeResolver;

class TaskRegistryTest extends TestCase {
    public function test_dev_mode_lists_nine_tasks(): void {
        $tasks = TaskRegistry::tasks_for_mode(ModeResolver::MODE_DEV);
        $slugs = array_keys($tasks);
        $this->assertCount(9, $slugs);
        $this->assertSame(
            ['setup','fields','connector','delivery','scheduling','copy','notifications','tracking','advanced'],
            $slugs
        );
    }

    public function test_client_mode_lists_four_tasks(): void {
        $tasks = TaskRegistry::tasks_for_mode(ModeResolver::MODE_CLIENT);
        $slugs = array_keys($tasks);
        $this->assertCount(4, $slugs);
        $this->assertSame(
            ['delivery','copy','notifications','submissions'],
            $slugs
        );
    }

    public function test_each_task_has_required_keys(): void {
        $tasks = TaskRegistry::tasks_for_mode(ModeResolver::MODE_DEV);
        foreach ($tasks as $slug => $def) {
            $this->assertIsString($def['title'] ?? null, "$slug missing title");
            $this->assertIsString($def['icon'] ?? null, "$slug missing icon");
            $this->assertIsString($def['view'] ?? null, "$slug missing view path");
        }
    }

    public function test_task_visible_for_form_type_respects_conditional(): void {
        // connector is hidden when form_type === 'custom'
        $this->assertFalse(
            TaskRegistry::is_visible_for_form_type('connector', 'custom'),
            'connector should hide on custom forms'
        );
        $this->assertTrue(
            TaskRegistry::is_visible_for_form_type('connector', 'enrollment'),
            'connector should show on enrollment forms'
        );
        $this->assertTrue(
            TaskRegistry::is_visible_for_form_type('copy', 'custom'),
            'copy is always visible'
        );
    }
}
```

- [ ] **Step 2: Run — fail**

Run: `vendor/bin/phpunit tests/Unit/FormEditor/TaskRegistryTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement**

`includes/form-editor/class-task-registry.php`:
```php
<?php
namespace ISF\FormEditor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Defines the editor task list. Each task = a card on the overview
 * + a single screen in the editor.
 *
 * Tasks declared here are pure data; rendering happens in
 * admin/views/form-editor/tasks/{slug}.php.
 */
class TaskRegistry {

    /** Tasks that hide when the instance's form_type matches this list. */
    private const HIDE_ON_FORM_TYPES = [
        'connector'  => ['custom'],
        'scheduling' => ['custom'],
    ];

    /**
     * Full dev-mode task list.
     *
     * @return array<string, array{title:string, icon:string, view:string, description:string}>
     */
    public static function all_tasks(): array {
        return [
            'setup' => [
                'title'       => __('Setup', 'formflow'),
                'icon'        => 'admin-settings',
                'view'        => 'tasks/setup.php',
                'description' => __('Name, slug, type, utility.', 'formflow'),
            ],
            'fields' => [
                'title'       => __('Form fields', 'formflow'),
                'icon'        => 'forms',
                'view'        => 'tasks/fields.php',
                'description' => __('Schema, validation, layout.', 'formflow'),
            ],
            'connector' => [
                'title'       => __('Connector', 'formflow'),
                'icon'        => 'admin-plugins',
                'view'        => 'tasks/connector.php',
                'description' => __('API auth, presets, test connection.', 'formflow'),
            ],
            'delivery' => [
                'title'       => __('Delivery', 'formflow'),
                'icon'        => 'upload',
                'view'        => 'tasks/delivery.php',
                'description' => __('SFTP, email, webhooks, manual export.', 'formflow'),
            ],
            'scheduling' => [
                'title'       => __('Scheduling', 'formflow'),
                'icon'        => 'calendar-alt',
                'view'        => 'tasks/scheduling.php',
                'description' => __('Slots, blocked dates, capacity, maintenance.', 'formflow'),
            ],
            'copy' => [
                'title'       => __('Copy', 'formflow'),
                'icon'        => 'edit',
                'view'        => 'tasks/copy.php',
                'description' => __('Title, description, buttons, T&Cs.', 'formflow'),
            ],
            'notifications' => [
                'title'       => __('Notifications', 'formflow'),
                'icon'        => 'email',
                'view'        => 'tasks/notifications.php',
                'description' => __('Confirmation email, team alerts.', 'formflow'),
            ],
            'tracking' => [
                'title'       => __('Tracking', 'formflow'),
                'icon'        => 'chart-line',
                'view'        => 'tasks/tracking.php',
                'description' => __('UTM, GA4, attribution.', 'formflow'),
            ],
            'advanced' => [
                'title'       => __('Advanced', 'formflow'),
                'icon'        => 'admin-tools',
                'view'        => 'tasks/advanced.php',
                'description' => __('Features, modes, debug.', 'formflow'),
            ],
            'submissions' => [
                'title'       => __('Submissions', 'formflow'),
                'icon'        => 'list-view',
                'view'        => 'tasks/submissions.php',
                'description' => __('Recent submissions and delivery status.', 'formflow'),
            ],
        ];
    }

    /** Subset of {@see all_tasks()} visible to the given mode. */
    public static function tasks_for_mode(string $mode): array {
        $all = self::all_tasks();
        $visible_slugs = $mode === ModeResolver::MODE_CLIENT
            ? ['delivery','copy','notifications','submissions']
            : ['setup','fields','connector','delivery','scheduling','copy','notifications','tracking','advanced'];
        $ordered = [];
        foreach ($visible_slugs as $slug) {
            if (isset($all[$slug])) {
                $ordered[$slug] = $all[$slug];
            }
        }
        return $ordered;
    }

    /** Whether the task card is shown (not greyed out) for this form_type. */
    public static function is_visible_for_form_type(string $task_slug, string $form_type): bool {
        $hidden_on = self::HIDE_ON_FORM_TYPES[$task_slug] ?? [];
        return !in_array($form_type, $hidden_on, true);
    }
}
```

- [ ] **Step 4: Run — pass**

Run: `vendor/bin/phpunit tests/Unit/FormEditor/TaskRegistryTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add includes/form-editor/class-task-registry.php tests/Unit/FormEditor/TaskRegistryTest.php
git commit -m "feat(form-editor): task registry — 9 dev tasks + 4 client tasks + form-type gating"
```

---

## Task 5: Task validator

**Files:**
- Create: `includes/form-editor/class-task-validator.php`
- Test: `tests/Unit/FormEditor/TaskValidatorTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/FormEditor/TaskValidatorTest.php`:
```php
<?php
namespace ISF\Tests\Unit\FormEditor;

use PHPUnit\Framework\TestCase;
use ISF\FormEditor\TaskValidator;

class TaskValidatorTest extends TestCase {

    public function test_setup_ok_when_all_required_set(): void {
        $instance = ['name'=>'Dominion PTR', 'slug'=>'dominion', 'form_type'=>'custom', 'settings'=>[]];
        $this->assertSame(TaskValidator::STATUS_OK, TaskValidator::status_for('setup', $instance));
    }

    public function test_setup_attention_when_name_missing(): void {
        $instance = ['name'=>'', 'slug'=>'dominion', 'form_type'=>'custom', 'settings'=>[]];
        $this->assertSame(TaskValidator::STATUS_ATTENTION, TaskValidator::status_for('setup', $instance));
    }

    public function test_delivery_attention_when_active_destination_missing_required(): void {
        $instance = ['form_type'=>'custom', 'settings'=>['destinations'=>[[
            'type'=>'sftp','is_active'=>true,'config'=>['host'=>'','username'=>'']
        ]]]];
        $this->assertSame(TaskValidator::STATUS_ATTENTION, TaskValidator::status_for('delivery', $instance));
    }

    public function test_delivery_attention_when_no_destinations(): void {
        $instance = ['form_type'=>'custom', 'settings'=>[]];
        $this->assertSame(TaskValidator::STATUS_ATTENTION, TaskValidator::status_for('delivery', $instance));
    }

    public function test_connector_na_on_custom_form(): void {
        $instance = ['form_type'=>'custom', 'settings'=>[]];
        $this->assertSame(TaskValidator::STATUS_NA, TaskValidator::status_for('connector', $instance));
    }

    public function test_fields_ok_when_step_has_fields(): void {
        $instance = ['settings'=>['form_schema'=>[
            'steps'=>[['id'=>'s1','fields'=>[['type'=>'text','name'=>'first']]]]
        ]]];
        $this->assertSame(TaskValidator::STATUS_OK, TaskValidator::status_for('fields', $instance));
    }

    public function test_fields_attention_when_no_fields(): void {
        $instance = ['settings'=>['form_schema'=>['steps'=>[]]]];
        $this->assertSame(TaskValidator::STATUS_ATTENTION, TaskValidator::status_for('fields', $instance));
    }

    public function test_tracking_defaults_when_neither_enabled(): void {
        $instance = ['settings'=>[]];
        $this->assertSame(TaskValidator::STATUS_DEFAULTS, TaskValidator::status_for('tracking', $instance));
    }
}
```

- [ ] **Step 2: Run — fail**

Run: `vendor/bin/phpunit tests/Unit/FormEditor/TaskValidatorTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement**

`includes/form-editor/class-task-validator.php`:
```php
<?php
namespace ISF\FormEditor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-task status computation. Pure functions over instance data —
 * no DB calls, no WP function calls beyond what's already in $instance.
 *
 * Used by the overview to render status badges and by detail pages
 * to render the inline status strip.
 */
class TaskValidator {

    public const STATUS_OK        = 'ok';        // green ✓
    public const STATUS_ATTENTION = 'attention'; // red ⚠
    public const STATUS_DEFAULTS  = 'defaults';  // yellow ⊙
    public const STATUS_NA        = 'na';        // grey —

    public static function status_for(string $task_slug, array $instance): string {
        // Form-type conditionals come first.
        if (!TaskRegistry::is_visible_for_form_type($task_slug, $instance['form_type'] ?? '')) {
            return self::STATUS_NA;
        }

        $settings = $instance['settings'] ?? [];

        switch ($task_slug) {
            case 'setup':
                return ($instance['name'] ?? '') && ($instance['slug'] ?? '') && ($instance['form_type'] ?? '')
                    ? self::STATUS_OK : self::STATUS_ATTENTION;

            case 'fields':
                $steps = $settings['form_schema']['steps'] ?? [];
                $count = 0;
                foreach ($steps as $s) { $count += count($s['fields'] ?? []); }
                return $count > 0 ? self::STATUS_OK : self::STATUS_ATTENTION;

            case 'connector':
                // Visible only when form_type !== 'custom' (na-guard above already covered).
                return ($instance['api_endpoint'] ?? '') !== ''
                    ? self::STATUS_OK : self::STATUS_ATTENTION;

            case 'delivery':
                $destinations = $settings['destinations'] ?? [];
                $active = array_filter($destinations, fn($d) => !empty($d['is_active']));
                if (count($active) === 0) {
                    return self::STATUS_ATTENTION;
                }
                foreach ($active as $d) {
                    foreach (['host','username'] as $req) {
                        if (empty($d['config'][$req] ?? '')) {
                            return self::STATUS_ATTENTION;
                        }
                    }
                }
                return self::STATUS_OK;

            case 'scheduling':
                return !empty($settings['scheduling']) ? self::STATUS_OK : self::STATUS_DEFAULTS;

            case 'copy':
                return !empty($settings['content']['form_title']) ? self::STATUS_OK : self::STATUS_DEFAULTS;

            case 'notifications':
                return !empty($settings['email']['send_confirmation']) && !empty($settings['email']['from_address'])
                    ? self::STATUS_OK : self::STATUS_DEFAULTS;

            case 'tracking':
                $gtm = !empty($settings['gtm']['enabled']);
                $analytics = !empty($settings['analytics']['enabled']);
                return ($gtm || $analytics) ? self::STATUS_OK : self::STATUS_DEFAULTS;

            case 'advanced':
            case 'submissions':
                return self::STATUS_OK;
        }

        return self::STATUS_DEFAULTS;
    }
}
```

- [ ] **Step 4: Run — pass**

Run: `vendor/bin/phpunit tests/Unit/FormEditor/TaskValidatorTest.php`
Expected: PASS (8 tests)

- [ ] **Step 5: Commit**

```bash
git add includes/form-editor/class-task-validator.php tests/Unit/FormEditor/TaskValidatorTest.php
git commit -m "feat(form-editor): per-task status validator (ok/attention/defaults/na)"
```

---

## Task 6: Router

**Files:**
- Create: `includes/form-editor/class-router.php`
- Test: `tests/Unit/FormEditor/RouterTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/FormEditor/RouterTest.php`:
```php
<?php
namespace ISF\Tests\Unit\FormEditor;

use PHPUnit\Framework\TestCase;
use ISF\FormEditor\Router;
use ISF\FormEditor\ModeResolver;

class RouterTest extends TestCase {

    public function test_no_task_returns_overview(): void {
        $r = new Router(['id'=>5], ModeResolver::MODE_DEV);
        $this->assertSame('overview', $r->resolved_view());
    }

    public function test_known_task_returns_task_slug(): void {
        $r = new Router(['id'=>5, 'task'=>'delivery'], ModeResolver::MODE_DEV);
        $this->assertSame('delivery', $r->resolved_view());
    }

    public function test_unknown_task_returns_no_task_view(): void {
        $r = new Router(['id'=>5, 'task'=>'banana'], ModeResolver::MODE_DEV);
        $this->assertSame('no-task', $r->resolved_view());
    }

    public function test_task_blocked_by_mode_returns_no_task(): void {
        // 'advanced' is dev-only; client mode shouldn't reach it
        $r = new Router(['id'=>5, 'task'=>'advanced'], ModeResolver::MODE_CLIENT);
        $this->assertSame('no-task', $r->resolved_view());
    }

    public function test_instance_id(): void {
        $r = new Router(['id'=>'42'], ModeResolver::MODE_DEV);
        $this->assertSame(42, $r->instance_id());
    }

    public function test_zero_instance_id_when_missing(): void {
        $r = new Router([], ModeResolver::MODE_DEV);
        $this->assertSame(0, $r->instance_id());
    }
}
```

- [ ] **Step 2: Run — fail**

Run: `vendor/bin/phpunit tests/Unit/FormEditor/RouterTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement**

`includes/form-editor/class-router.php`:
```php
<?php
namespace ISF\FormEditor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parses ?page=isf-form&id=N&task=… URL params into a resolved view name
 * and instance id, enforcing mode-based task visibility.
 *
 * Output `resolved_view()` returns one of:
 *   - 'overview'   — render the task grid
 *   - '<task-slug>' — render that task's view
 *   - 'no-task'    — render a 404-ish stub
 */
class Router {

    private array $query;
    private string $mode;

    public function __construct(array $query_params, string $mode) {
        $this->query = $query_params;
        $this->mode  = $mode;
    }

    public function instance_id(): int {
        return (int) ($this->query['id'] ?? 0);
    }

    public function resolved_view(): string {
        $task = (string) ($this->query['task'] ?? '');
        if ($task === '') {
            return 'overview';
        }
        $allowed = TaskRegistry::tasks_for_mode($this->mode);
        if (!isset($allowed[$task])) {
            return 'no-task';
        }
        return $task;
    }

    public function sub_item(): string {
        // Two-pane sub-rail selection — e.g., &dest=sftp-1 or &field=f3
        // The active key depends on the task. We just return the first match.
        foreach (['dest','field','slot','date','rule'] as $k) {
            if (isset($this->query[$k])) {
                return (string) $this->query[$k];
            }
        }
        return '';
    }
}
```

- [ ] **Step 4: Run — pass**

Run: `vendor/bin/phpunit tests/Unit/FormEditor/RouterTest.php`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add includes/form-editor/class-router.php tests/Unit/FormEditor/RouterTest.php
git commit -m "feat(form-editor): URL router — page/id/task/sub-item resolution + mode gating"
```

---

## Task 7: Field gate (client-mode write protection)

**Files:**
- Create: `includes/form-editor/class-field-gate.php`
- Test: `tests/Unit/FormEditor/FieldGateTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/FormEditor/FieldGateTest.php`:
```php
<?php
namespace ISF\Tests\Unit\FormEditor;

use PHPUnit\Framework\TestCase;
use ISF\FormEditor\FieldGate;
use ISF\FormEditor\ModeResolver;

class FieldGateTest extends TestCase {

    public function test_dev_mode_passes_all_fields_through(): void {
        $posted = ['name'=>'X', 'slug'=>'x', 'form_type'=>'enrollment', 'api_password'=>'secret'];
        $filtered = FieldGate::strip_blocked_fields($posted, ModeResolver::MODE_DEV);
        $this->assertSame($posted, $filtered);
    }

    public function test_client_mode_strips_dev_only_top_level_fields(): void {
        $posted = ['name'=>'X', 'slug'=>'x', 'form_type'=>'enrollment', 'api_password'=>'secret'];
        $filtered = FieldGate::strip_blocked_fields($posted, ModeResolver::MODE_CLIENT);
        $this->assertArrayNotHasKey('name', $filtered);
        $this->assertArrayNotHasKey('slug', $filtered);
        $this->assertArrayNotHasKey('form_type', $filtered);
        $this->assertArrayNotHasKey('api_password', $filtered);
    }

    public function test_client_mode_keeps_copy_and_notification_fields(): void {
        $posted = [
            'name'=>'X',
            'settings'=>wp_json_encode([
                'content'=>['form_title'=>'New title'],
                'email'=>['from_address'=>'a@b.c'],
                'destinations'=>[[ 'type'=>'sftp','config'=>['host'=>'new.host','password'=>'new'] ]],
            ]),
        ];
        $filtered = FieldGate::strip_blocked_fields($posted, ModeResolver::MODE_CLIENT);
        $this->assertArrayNotHasKey('name', $filtered);
        $decoded = json_decode($filtered['settings'], true);
        $this->assertSame('New title', $decoded['content']['form_title']);
        $this->assertSame('a@b.c', $decoded['email']['from_address']);
        // Destination creds allowed in client mode
        $this->assertSame('new.host', $decoded['destinations'][0]['config']['host']);
    }

    public function test_client_mode_strips_dev_only_settings_sections(): void {
        $posted = [
            'settings'=>wp_json_encode([
                'content'=>['form_title'=>'X'],
                'form_schema'=>['steps'=>[]],   // dev-only
                'gtm'=>['enabled'=>true],       // dev-only
                'features'=>['ab_testing'=>true], // dev-only
            ]),
        ];
        $filtered = FieldGate::strip_blocked_fields($posted, ModeResolver::MODE_CLIENT);
        $decoded = json_decode($filtered['settings'], true);
        $this->assertArrayHasKey('content', $decoded);
        $this->assertArrayNotHasKey('form_schema', $decoded);
        $this->assertArrayNotHasKey('gtm', $decoded);
        $this->assertArrayNotHasKey('features', $decoded);
    }
}
```

(Note: `wp_json_encode` and `json_decode` are real PHP functions — `wp_json_encode` is WP's wrapper; we add a Brain/Monkey shim in the test bootstrap so it falls through.)

- [ ] **Step 2: Add wp_json_encode shim to test bootstrap**

Modify `tests/bootstrap.php` — add after the existing function shims (around the bottom):

```php
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512): string|false {
        return json_encode($data, $options, $depth);
    }
}
```

- [ ] **Step 3: Run — fail**

Run: `vendor/bin/phpunit tests/Unit/FormEditor/FieldGateTest.php`
Expected: FAIL — class not found

- [ ] **Step 4: Implement**

`includes/form-editor/class-field-gate.php`:
```php
<?php
namespace ISF\FormEditor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server-side field gate for client-mode submissions.
 *
 * Client admins should only be able to write Copy, Notifications, and
 * destination credentials. Even though the client-mode UI hides
 * everything else, a malicious client could craft a direct POST. This
 * class strips disallowed keys before the save handler reaches the DB.
 */
class FieldGate {

    /** Top-level POST keys clients may not write. */
    private const CLIENT_BLOCKED_TOP_LEVEL = [
        'name','slug','form_type','utility',
        'api_endpoint','api_password',
        'is_active','test_mode','demo_mode',
    ];

    /** Settings JSON sub-sections clients may not write. */
    private const CLIENT_BLOCKED_SETTINGS_SECTIONS = [
        'form_schema','basics','validation','styling',
        'gtm','analytics','features','scheduling','maintenance',
        'tracking','fraud',
    ];

    public static function strip_blocked_fields(array $posted, string $mode): array {
        if ($mode === ModeResolver::MODE_DEV) {
            return $posted;
        }

        foreach (self::CLIENT_BLOCKED_TOP_LEVEL as $k) {
            unset($posted[$k]);
        }

        if (!empty($posted['settings'])) {
            $settings = json_decode(stripslashes_or_passthrough($posted['settings']), true);
            if (is_array($settings)) {
                foreach (self::CLIENT_BLOCKED_SETTINGS_SECTIONS as $section) {
                    unset($settings[$section]);
                }
                $posted['settings'] = wp_json_encode($settings);
            }
        }
        return $posted;
    }
}

/**
 * Tolerates being called from unit tests where stripslashes() may not
 * be desired. Production POST data is always slashed by WP.
 */
function stripslashes_or_passthrough(string $s): string {
    return function_exists('stripslashes') ? stripslashes($s) : $s;
}
```

- [ ] **Step 5: Run — pass**

Run: `vendor/bin/phpunit tests/Unit/FormEditor/FieldGateTest.php`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add includes/form-editor/class-field-gate.php tests/Unit/FormEditor/FieldGateTest.php tests/bootstrap.php
git commit -m "feat(form-editor): client-mode field gate strips dev-only fields server-side"
```

---

## Task 8: Bootstrap form-editor classes + register new menu

**Files:**
- Modify: `formflow.php` (require new classes, register menu)
- Modify: `includes/class-plugin.php` (gate old menu when flag ON)

- [ ] **Step 1: Add require_once for form-editor classes in formflow.php**

In `formflow.php`, find the block where destinations classes are loaded (around line 167-173) and append:

```php
    require_once ISF_PLUGIN_DIR . 'includes/form-editor/class-feature-flag.php';
    require_once ISF_PLUGIN_DIR . 'includes/form-editor/class-capabilities.php';
    require_once ISF_PLUGIN_DIR . 'includes/form-editor/class-mode-resolver.php';
    require_once ISF_PLUGIN_DIR . 'includes/form-editor/class-task-registry.php';
    require_once ISF_PLUGIN_DIR . 'includes/form-editor/class-task-validator.php';
    require_once ISF_PLUGIN_DIR . 'includes/form-editor/class-router.php';
    require_once ISF_PLUGIN_DIR . 'includes/form-editor/class-field-gate.php';
```

- [ ] **Step 2: Add new menu registration**

In `includes/class-plugin.php`, find `define_admin_hooks()`, and add this near the other `add_action('admin_menu', ...)` calls:

```php
        // Form-editor (3.0.0+): new task-overview-based editor, behind ISF_NEW_EDITOR flag
        add_action('admin_menu', [$this, 'register_form_editor_menu'], 30);
```

Then add the method to the same class:

```php
    public function register_form_editor_menu(): void {
        if (!\ISF\FormEditor\FeatureFlag::is_enabled()) {
            return;
        }
        add_submenu_page(
            'isf-dashboard',
            __('Form Editor', 'formflow'),
            __('Form Editor', 'formflow') . ' <span class="isf-badge-new">Beta</span>',
            'manage_options',
            'isf-form',
            [$this, 'render_form_editor']
        );
    }

    public function render_form_editor(): void {
        require ISF_PLUGIN_DIR . 'admin/views/form-editor/layout.php';
    }
```

- [ ] **Step 3: Gate old editor when flag ON**

In `includes/class-plugin.php` add a method:

```php
    /**
     * When the new editor is on, redirect any direct hits to the old
     * isf-instance-editor admin URL → new editor.
     */
    public function redirect_old_editor_when_flag_on(): void {
        if (!\ISF\FormEditor\FeatureFlag::is_enabled()) {
            return;
        }
        if (!isset($_GET['page']) || $_GET['page'] !== 'isf-instance-editor') {
            return;
        }
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $url = admin_url('admin.php?page=isf-form' . ($id ? "&id={$id}" : ''));
        wp_safe_redirect($url);
        exit;
    }
```

And hook it in `define_admin_hooks()`:

```php
        add_action('admin_init', [$this, 'redirect_old_editor_when_flag_on'], 1);
```

- [ ] **Step 4: Lint everything**

Run: `php -l formflow.php && php -l includes/class-plugin.php`
Expected: "No syntax errors detected" for both

- [ ] **Step 5: Commit**

```bash
git add formflow.php includes/class-plugin.php
git commit -m "feat(form-editor): bootstrap new menu + redirect old editor when ISF_NEW_EDITOR on"
```

---

## Task 9: Shared layout chrome

**Files:**
- Create: `admin/views/form-editor/layout.php`
- Create: `admin/views/form-editor/no-task.php`

- [ ] **Step 1: Write the layout**

`admin/views/form-editor/layout.php`:
```php
<?php
/**
 * Form Editor — top-level layout.
 *
 * Resolves URL → mode → router → view, then renders chrome
 * (breadcrumb, mode switcher) around the resolved view.
 */
if (!defined('ABSPATH')) { exit; }

use ISF\FormEditor\Router;
use ISF\FormEditor\ModeResolver;
use ISF\FormEditor\TaskRegistry;

$mode = ModeResolver::effective_mode();
$query = $_GET ?? [];
$router = new Router($query, $mode);
$view = $router->resolved_view();
$instance_id = $router->instance_id();

// Load the instance once for child views.
$instance = null;
if ($instance_id > 0) {
    global $wpdb;
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}isf_instances WHERE id = %d", $instance_id),
        ARRAY_A
    );
    if ($row && !empty($row['settings'])) {
        $row['settings'] = json_decode($row['settings'], true) ?: [];
    }
    $instance = $row ?: null;
}

$tasks = TaskRegistry::tasks_for_mode($mode);
$current_task_def = ($view !== 'overview' && $view !== 'no-task') ? ($tasks[$view] ?? null) : null;
?>
<div class="wrap isf-form-editor" data-mode="<?php echo esc_attr($mode); ?>">

    <header class="isf-fe-header">
        <nav class="isf-fe-breadcrumb">
            <a href="<?php echo esc_url(admin_url('admin.php?page=isf-dashboard')); ?>">
                <?php esc_html_e('Forms', 'formflow'); ?>
            </a>
            <?php if ($instance) : ?>
                <span class="isf-fe-sep">/</span>
                <a href="<?php echo esc_url(admin_url('admin.php?page=isf-form&id=' . $instance_id)); ?>">
                    <?php echo esc_html($instance['name'] ?? __('(unnamed)', 'formflow')); ?>
                </a>
            <?php endif; ?>
            <?php if ($current_task_def) : ?>
                <span class="isf-fe-sep">/</span>
                <strong>
                    <span class="dashicons dashicons-<?php echo esc_attr($current_task_def['icon']); ?>"></span>
                    <?php echo esc_html($current_task_def['title']); ?>
                </strong>
            <?php endif; ?>
        </nav>

        <?php if (ModeResolver::has_both_modes()) : ?>
            <div class="isf-fe-mode-switcher">
                <label for="isf-fe-mode-pref">
                    <?php esc_html_e('Editing as:', 'formflow'); ?>
                </label>
                <select id="isf-fe-mode-pref" data-current="<?php echo esc_attr($mode); ?>">
                    <option value="dev" <?php selected($mode, ModeResolver::MODE_DEV); ?>>
                        <?php esc_html_e('Dev (all tasks)', 'formflow'); ?>
                    </option>
                    <option value="client" <?php selected($mode, ModeResolver::MODE_CLIENT); ?>>
                        <?php esc_html_e('Client (limited view)', 'formflow'); ?>
                    </option>
                </select>
            </div>
        <?php endif; ?>
    </header>

    <main class="isf-fe-main">
        <?php
        if (!$instance) {
            require ISF_PLUGIN_DIR . 'admin/views/form-editor/no-task.php';
        } elseif ($view === 'overview') {
            require ISF_PLUGIN_DIR . 'admin/views/form-editor/overview.php';
        } elseif ($view === 'no-task') {
            require ISF_PLUGIN_DIR . 'admin/views/form-editor/no-task.php';
        } else {
            $task_def = $tasks[$view];
            $view_path = ISF_PLUGIN_DIR . 'admin/views/form-editor/' . $task_def['view'];
            if (file_exists($view_path)) {
                require $view_path;
            } else {
                require ISF_PLUGIN_DIR . 'admin/views/form-editor/no-task.php';
            }
        }
        ?>
    </main>
</div>
```

- [ ] **Step 2: Write the no-task fallback**

`admin/views/form-editor/no-task.php`:
```php
<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="isf-fe-no-task">
    <h2><?php esc_html_e('No form selected', 'formflow'); ?></h2>
    <p>
        <?php esc_html_e('Choose a form from the dashboard, or check the URL.', 'formflow'); ?>
    </p>
    <p>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=isf-dashboard')); ?>">
            <?php esc_html_e('Back to dashboard', 'formflow'); ?>
        </a>
    </p>
</div>
```

- [ ] **Step 3: Lint**

Run: `php -l admin/views/form-editor/layout.php admin/views/form-editor/no-task.php`
Expected: "No syntax errors detected"

- [ ] **Step 4: Commit**

```bash
git add admin/views/form-editor/layout.php admin/views/form-editor/no-task.php
git commit -m "feat(form-editor): top-level layout chrome — breadcrumb, mode switcher, view dispatch"
```

---

## Task 10: Overview page (task grid)

**Files:**
- Create: `admin/views/form-editor/overview.php`
- Create: `admin/views/form-editor/partials/task-card.php`

- [ ] **Step 1: Write the overview view**

`admin/views/form-editor/overview.php`:
```php
<?php
/**
 * Overview — grid of task cards for an instance.
 * Expects $instance, $tasks, $mode in scope (from layout.php).
 */
if (!defined('ABSPATH')) { exit; }

use ISF\FormEditor\TaskValidator;

$form_type = $instance['form_type'] ?? '';
?>
<section class="isf-fe-overview">
    <p class="isf-fe-subtitle">
        <?php esc_html_e('Pick a task to edit. Status badges show what needs your attention.', 'formflow'); ?>
    </p>

    <div class="isf-fe-task-grid">
        <?php foreach ($tasks as $slug => $def):
            $status = TaskValidator::status_for($slug, $instance);
            include __DIR__ . '/partials/task-card.php';
        endforeach; ?>
    </div>
</section>
```

- [ ] **Step 2: Write the task card partial**

`admin/views/form-editor/partials/task-card.php`:
```php
<?php
/**
 * One card in the overview grid.
 * Expects $slug, $def, $status, $instance, $instance_id in scope.
 */
if (!defined('ABSPATH')) { exit; }

use ISF\FormEditor\TaskValidator;

$status_labels = [
    TaskValidator::STATUS_OK        => __('Complete', 'formflow'),
    TaskValidator::STATUS_ATTENTION => __('Needs attention', 'formflow'),
    TaskValidator::STATUS_DEFAULTS  => __('Defaults', 'formflow'),
    TaskValidator::STATUS_NA        => __('Not applicable', 'formflow'),
];

$status_symbols = [
    TaskValidator::STATUS_OK        => '✓',
    TaskValidator::STATUS_ATTENTION => '⚠',
    TaskValidator::STATUS_DEFAULTS  => '⊙',
    TaskValidator::STATUS_NA        => '—',
];

$is_clickable = $status !== TaskValidator::STATUS_NA;
$href = $is_clickable
    ? admin_url('admin.php?page=isf-form&id=' . (int) $instance_id . '&task=' . $slug)
    : '#';

$tag = $is_clickable ? 'a' : 'div';
?>
<<?php echo $tag; ?> class="isf-fe-task-card isf-fe-status-<?php echo esc_attr($status); ?>"
    <?php if ($is_clickable): ?>href="<?php echo esc_url($href); ?>"<?php endif; ?>
    aria-disabled="<?php echo $is_clickable ? 'false' : 'true'; ?>">
    <div class="isf-fe-task-card-head">
        <span class="dashicons dashicons-<?php echo esc_attr($def['icon']); ?>"></span>
        <h3><?php echo esc_html($def['title']); ?></h3>
    </div>
    <p class="isf-fe-task-card-desc"><?php echo esc_html($def['description']); ?></p>
    <div class="isf-fe-task-card-status">
        <span class="isf-fe-status-symbol"><?php echo esc_html($status_symbols[$status]); ?></span>
        <span class="isf-fe-status-label"><?php echo esc_html($status_labels[$status]); ?></span>
    </div>
</<?php echo $tag; ?>>
```

- [ ] **Step 3: Lint**

Run: `php -l admin/views/form-editor/overview.php admin/views/form-editor/partials/task-card.php`
Expected: "No syntax errors detected"

- [ ] **Step 4: Commit**

```bash
git add admin/views/form-editor/overview.php admin/views/form-editor/partials/task-card.php
git commit -m "feat(form-editor): overview page — task grid with status badges"
```

---

## Task 11: CSS for editor (scoped)

**Files:**
- Create: `admin/assets/css/form-editor.css`
- Modify: `admin/class-admin.php` (enqueue on isf-form screen)

- [ ] **Step 1: Write the CSS**

`admin/assets/css/form-editor.css`:
```css
/* FormFlow form-editor — scoped to .isf-form-editor */

.isf-form-editor {
    --fe-pad: 20px;
    --fe-gap: 16px;
    --fe-radius: 6px;
    --fe-border: #c3c4c7;
    --fe-bg: #f6f7f7;
    --fe-card: #fff;
    --fe-blue: #2271b1;
    --fe-red: #d63638;
    --fe-green: #00a32a;
    --fe-yellow: #dba617;
    --fe-grey: #c3c4c7;
}

/* ---- Header ---- */
.isf-form-editor .isf-fe-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 0;
    border-bottom: 1px solid var(--fe-border);
    margin-bottom: 20px;
}
.isf-form-editor .isf-fe-breadcrumb {
    font-size: 14px;
    color: #50575e;
}
.isf-form-editor .isf-fe-breadcrumb a {
    color: var(--fe-blue);
    text-decoration: none;
}
.isf-form-editor .isf-fe-breadcrumb a:hover { text-decoration: underline; }
.isf-form-editor .isf-fe-sep { margin: 0 8px; color: #a7aaad; }
.isf-form-editor .isf-fe-mode-switcher label { font-size: 12px; color: #50575e; margin-right: 6px; }
.isf-form-editor .isf-fe-mode-switcher select { font-size: 12px; }

/* ---- Overview ---- */
.isf-form-editor .isf-fe-subtitle { color: #646970; font-size: 13px; margin-bottom: 18px; }
.isf-form-editor .isf-fe-task-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: var(--fe-gap);
}
.isf-form-editor .isf-fe-task-card {
    display: block;
    background: var(--fe-card);
    border: 1px solid var(--fe-border);
    border-radius: var(--fe-radius);
    padding: var(--fe-pad);
    text-decoration: none;
    color: inherit;
    transition: box-shadow 120ms ease, border-color 120ms ease;
}
.isf-form-editor .isf-fe-task-card[href]:hover {
    border-color: var(--fe-blue);
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
}
.isf-form-editor .isf-fe-task-card-head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}
.isf-form-editor .isf-fe-task-card-head h3 { margin: 0; font-size: 15px; font-weight: 500; }
.isf-form-editor .isf-fe-task-card-head .dashicons { color: var(--fe-blue); }
.isf-form-editor .isf-fe-task-card-desc { color: #646970; font-size: 12px; margin: 0 0 10px; }
.isf-form-editor .isf-fe-task-card-status { font-size: 11px; }
.isf-form-editor .isf-fe-task-card-status .isf-fe-status-symbol { font-weight: 600; margin-right: 4px; }

.isf-form-editor .isf-fe-status-ok        .isf-fe-status-symbol { color: var(--fe-green); }
.isf-form-editor .isf-fe-status-attention .isf-fe-status-symbol { color: var(--fe-red); }
.isf-form-editor .isf-fe-status-attention { border-left: 3px solid var(--fe-red); }
.isf-form-editor .isf-fe-status-defaults  .isf-fe-status-symbol { color: var(--fe-yellow); }
.isf-form-editor .isf-fe-status-na { opacity: 0.55; cursor: not-allowed; }
.isf-form-editor .isf-fe-status-na .isf-fe-status-symbol { color: var(--fe-grey); }

/* ---- Task chrome (two-pane) ---- */
.isf-form-editor .isf-fe-task-panel {
    background: var(--fe-card);
    border: 1px solid var(--fe-border);
    border-radius: var(--fe-radius);
    overflow: hidden;
}
.isf-form-editor .isf-fe-task-two-pane {
    display: grid;
    grid-template-columns: minmax(220px, 320px) 1fr;
}
@media (max-width: 700px) {
    .isf-form-editor .isf-fe-task-two-pane {
        grid-template-columns: 1fr;
    }
}
.isf-form-editor .isf-fe-sub-rail {
    border-right: 1px solid var(--fe-border);
    background: #fafafa;
    padding: 8px 0;
}
.isf-form-editor .isf-fe-sub-rail .isf-fe-rail-item {
    padding: 10px 14px;
    cursor: pointer;
    border-left: 3px solid transparent;
}
.isf-form-editor .isf-fe-sub-rail .isf-fe-rail-item.is-active {
    border-left-color: var(--fe-blue);
    background: #f0f6fc;
    font-weight: 500;
}
.isf-form-editor .isf-fe-detail { padding: 20px 24px; }

/* ---- Inline status strip ---- */
.isf-form-editor .isf-fe-inline-strip {
    padding: 10px 14px;
    border-left: 3px solid;
    border-radius: 0 var(--fe-radius) var(--fe-radius) 0;
    font-size: 12px;
    margin-bottom: 16px;
}
.isf-form-editor .isf-fe-inline-strip.is-error { background: #fcf0f1; border-left-color: var(--fe-red); }
.isf-form-editor .isf-fe-inline-strip.is-success { background: #edfaef; border-left-color: var(--fe-green); }

/* ---- Sticky action bar ---- */
.isf-form-editor .isf-fe-action-bar {
    position: sticky;
    bottom: 0;
    background: #fff;
    border-top: 1px solid var(--fe-border);
    padding: 10px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    z-index: 10;
}
.isf-form-editor .isf-fe-save-status { color: #646970; }
.isf-form-editor .isf-fe-save-status.is-saving { color: var(--fe-blue); }
.isf-form-editor .isf-fe-save-status.is-error { color: var(--fe-red); }
```

- [ ] **Step 2: Enqueue the CSS on isf-form screens**

In `admin/class-admin.php`, find `enqueue_styles()` and append:

```php
        if (strpos($screen->id ?? '', 'isf-form') !== false) {
            wp_enqueue_style(
                'isf-form-editor',
                ISF_PLUGIN_URL . 'admin/assets/css/form-editor.css',
                [],
                ISF_VERSION
            );
        }
```

- [ ] **Step 3: Lint**

Run: `php -l admin/class-admin.php`
Expected: "No syntax errors detected"

- [ ] **Step 4: Commit**

```bash
git add admin/assets/css/form-editor.css admin/class-admin.php
git commit -m "feat(form-editor): scoped CSS — overview grid, two-pane, sticky bar"
```

---

## Task 12: JS shim (save-on-blur + mode switcher + sub-rail nav)

**Files:**
- Create: `admin/assets/js/form-editor.js`
- Modify: `admin/class-admin.php` (enqueue on isf-form screen)
- Modify: `includes/class-plugin.php` (AJAX handler `formflow_set_mode_preference`)

- [ ] **Step 1: Write the JS**

`admin/assets/js/form-editor.js`:
```js
/* FormFlow form-editor — save-on-blur, mode switcher, sub-rail nav */
(function ($) {
    'use strict';

    var DEBOUNCE_MS = 500;
    var inflight = null;
    var dirtyFields = {};
    var statusEl;

    function status(text, klass) {
        if (!statusEl) return;
        statusEl.removeClass('is-saving is-error').addClass(klass || '');
        statusEl.text(text);
    }

    function postSave(instanceId, payload) {
        if (inflight) inflight.abort();
        status(formflowEditor.strings.saving, 'is-saving');
        inflight = $.post(formflowEditor.ajax_url, $.extend({
            action: 'formflow_save_instance',
            nonce: formflowEditor.nonce,
            id: instanceId,
        }, payload));
        inflight.done(function (resp) {
            if (resp && resp.success) {
                dirtyFields = {};
                status(formflowEditor.strings.saved);
            } else {
                status((resp && resp.data && resp.data.message) || formflowEditor.strings.error, 'is-error');
            }
        }).fail(function () {
            status(formflowEditor.strings.error, 'is-error');
        });
    }

    function fieldChanged($field) {
        var key = $field.attr('name');
        if (!key) return;
        dirtyFields[key] = $field.is(':checkbox') ? ($field.is(':checked') ? 1 : 0) : $field.val();
    }

    function flushDirty() {
        var instanceId = $('.isf-form-editor').data('instance-id');
        if (!instanceId || Object.keys(dirtyFields).length === 0) return;
        postSave(instanceId, dirtyFields);
    }

    var debounced = (function () {
        var t;
        return function () { clearTimeout(t); t = setTimeout(flushDirty, DEBOUNCE_MS); };
    })();

    $(function () {
        statusEl = $('.isf-fe-save-status');

        // Save-on-blur for any [data-fe-autosave] field
        $(document).on('change blur', '.isf-form-editor [data-fe-autosave]', function () {
            fieldChanged($(this));
            debounced();
        });

        // Sticky save button — flush + post immediately
        $(document).on('click', '.isf-fe-action-bar .isf-fe-save', function (e) {
            e.preventDefault();
            flushDirty();
        });

        // Mode switcher
        $(document).on('change', '#isf-fe-mode-pref', function () {
            var mode = $(this).val();
            $.post(formflowEditor.ajax_url, {
                action: 'formflow_set_mode_preference',
                nonce: formflowEditor.nonce,
                mode: mode
            }, function () { window.location.reload(); });
        });

        // Sub-rail nav — anchor links handle themselves (history-friendly URLs)
    });
}(jQuery));
```

- [ ] **Step 2: Enqueue + localize**

In `admin/class-admin.php` `enqueue_scripts()`, append for the isf-form screen:

```php
        if (strpos($screen->id ?? '', 'isf-form') !== false) {
            wp_enqueue_script(
                'isf-form-editor',
                ISF_PLUGIN_URL . 'admin/assets/js/form-editor.js',
                ['jquery'],
                ISF_VERSION,
                true
            );
            wp_localize_script('isf-form-editor', 'formflowEditor', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('isf_admin_nonce'),
                'strings'  => [
                    'saving' => __('Saving…', 'formflow'),
                    'saved'  => __('Saved', 'formflow'),
                    'error'  => __('Save failed', 'formflow'),
                ],
            ]);
        }
```

- [ ] **Step 3: Add AJAX handler for mode preference**

In `includes/class-plugin.php` add:

```php
        add_action('wp_ajax_formflow_set_mode_preference', [$this, 'ajax_set_mode_preference']);
```

And the method:

```php
    public function ajax_set_mode_preference(): void {
        if (!\ISF\Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }
        $mode = sanitize_text_field($_POST['mode'] ?? '');
        $ok = \ISF\FormEditor\ModeResolver::set_preference($mode);
        if ($ok) {
            wp_send_json_success(['mode' => $mode]);
        }
        wp_send_json_error(['message' => __('Invalid mode.', 'formflow')]);
    }
```

- [ ] **Step 4: Wire FieldGate into existing save handler**

In `admin/class-admin.php` find `ajax_save_instance()` and add at the top, immediately after the security verification:

```php
        // Client-mode write gate — strip dev-only fields before processing
        $_POST = \ISF\FormEditor\FieldGate::strip_blocked_fields(
            $_POST,
            \ISF\FormEditor\ModeResolver::effective_mode()
        );
```

- [ ] **Step 5: Lint**

Run: `php -l admin/class-admin.php includes/class-plugin.php`
Expected: "No syntax errors detected"

- [ ] **Step 6: Commit**

```bash
git add admin/assets/js/form-editor.js admin/class-admin.php includes/class-plugin.php
git commit -m "feat(form-editor): JS shim (save-on-blur, mode switcher) + AJAX handlers + FieldGate wiring"
```

---

## Task 13: Inline status strip + sticky action bar partials

**Files:**
- Create: `admin/views/form-editor/partials/inline-status-strip.php`
- Create: `admin/views/form-editor/partials/sticky-action-bar.php`

- [ ] **Step 1: Write inline-status-strip.php**

`admin/views/form-editor/partials/inline-status-strip.php`:
```php
<?php
/**
 * Inline status strip — appears at the top of a task's detail pane.
 *
 * Expects $strip_status ('error'|'success'), $strip_message in scope.
 */
if (!defined('ABSPATH')) { exit; }
$class = ($strip_status ?? '') === 'success' ? 'is-success' : 'is-error';
?>
<div class="isf-fe-inline-strip <?php echo esc_attr($class); ?>" role="status">
    <strong><?php echo esc_html($strip_status === 'success' ? '✓' : '⚠'); ?></strong>
    <?php echo esc_html($strip_message ?? ''); ?>
</div>
```

- [ ] **Step 2: Write sticky-action-bar.php**

`admin/views/form-editor/partials/sticky-action-bar.php`:
```php
<?php
/**
 * Sticky action bar — appears at the bottom of any task's detail pane.
 *
 * Expects optional $extra_buttons array of <button> HTML in scope.
 */
if (!defined('ABSPATH')) { exit; }
?>
<div class="isf-fe-action-bar">
    <span class="isf-fe-save-status"><?php esc_html_e('Unsaved changes', 'formflow'); ?></span>
    <div class="isf-fe-action-bar-buttons">
        <?php if (!empty($extra_buttons) && is_array($extra_buttons)) : ?>
            <?php foreach ($extra_buttons as $btn_html) : ?>
                <?php echo $btn_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — caller-built ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <button type="button" class="button button-primary isf-fe-save">
            <?php esc_html_e('Save', 'formflow'); ?>
        </button>
    </div>
</div>
```

- [ ] **Step 3: Lint**

Run: `php -l admin/views/form-editor/partials/inline-status-strip.php admin/views/form-editor/partials/sticky-action-bar.php`
Expected: "No syntax errors detected"

- [ ] **Step 4: Commit**

```bash
git add admin/views/form-editor/partials/inline-status-strip.php admin/views/form-editor/partials/sticky-action-bar.php
git commit -m "feat(form-editor): inline status strip + sticky action bar partials"
```

---

## Task 14: Setup task (proves single-pane pattern)

**Files:**
- Create: `admin/views/form-editor/tasks/setup.php`

- [ ] **Step 1: Write the view**

`admin/views/form-editor/tasks/setup.php`:
```php
<?php
/**
 * Setup task — simplest task. Single-pane (no sub-rail).
 *
 * Expects $instance, $mode in scope.
 */
if (!defined('ABSPATH')) { exit; }

use ISF\FormEditor\ModeResolver;

$is_dev = $mode === ModeResolver::MODE_DEV;
?>
<div class="isf-fe-task-panel" data-task="setup">
    <div class="isf-form-editor"></div>
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Form setup', 'formflow'); ?></h2>
        <p class="description">
            <?php esc_html_e('Identity, type, and utility. These rarely change after initial setup.', 'formflow'); ?>
        </p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="name"><?php esc_html_e('Form name', 'formflow'); ?> <span class="required">*</span></label></th>
                <td>
                    <input type="text" id="name" name="name" class="regular-text"
                        data-fe-autosave
                        value="<?php echo esc_attr($instance['name'] ?? ''); ?>"
                        <?php disabled(!$is_dev); ?>>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="slug"><?php esc_html_e('Slug', 'formflow'); ?> <span class="required">*</span></label></th>
                <td>
                    <code>[isf_form instance="</code><input type="text" id="slug" name="slug"
                        data-fe-autosave
                        value="<?php echo esc_attr($instance['slug'] ?? ''); ?>"
                        <?php disabled(!$is_dev); ?>><code>"]</code>
                    <p class="description"><?php esc_html_e('URL-friendly identifier used in the shortcode.', 'formflow'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="form_type"><?php esc_html_e('Form type', 'formflow'); ?></label></th>
                <td>
                    <select id="form_type" name="form_type" data-fe-autosave <?php disabled(!$is_dev); ?>>
                        <option value="custom"      <?php selected($instance['form_type'] ?? '', 'custom'); ?>><?php esc_html_e('Custom (call-center handoff)', 'formflow'); ?></option>
                        <option value="enrollment"  <?php selected($instance['form_type'] ?? '', 'enrollment'); ?>><?php esc_html_e('Enrollment (IntelliSOURCE)', 'formflow'); ?></option>
                        <option value="scheduler"   <?php selected($instance['form_type'] ?? '', 'scheduler'); ?>><?php esc_html_e('Scheduler only', 'formflow'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="utility"><?php esc_html_e('Utility', 'formflow'); ?></label></th>
                <td>
                    <input type="text" id="utility" name="utility" class="regular-text"
                        data-fe-autosave
                        value="<?php echo esc_attr($instance['utility'] ?? ''); ?>"
                        <?php disabled(!$is_dev); ?>>
                </td>
            </tr>
        </table>

        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php'; ?>
    </div>
</div>
```

- [ ] **Step 2: Lint**

Run: `php -l admin/views/form-editor/tasks/setup.php`
Expected: "No syntax errors detected"

- [ ] **Step 3: Commit**

```bash
git add admin/views/form-editor/tasks/setup.php
git commit -m "feat(form-editor): Setup task (single-pane, name/slug/type/utility)"
```

---

## Task 15: Delivery task (proves two-pane + list/detail)

**Files:**
- Create: `admin/views/form-editor/tasks/delivery.php`
- Create: `admin/views/form-editor/partials/sub-rail.php`

- [ ] **Step 1: Write sub-rail partial**

`admin/views/form-editor/partials/sub-rail.php`:
```php
<?php
/**
 * Reusable sub-rail (left list in two-pane layouts).
 * Expects $rail_items array of [['label'=>'…','href'=>'…','status'=>'ok|attention|defaults','active'=>true|false,'sublabel'=>'…']],
 * plus optional $rail_footer string of HTML.
 */
if (!defined('ABSPATH')) { exit; }
?>
<aside class="isf-fe-sub-rail">
    <?php foreach (($rail_items ?? []) as $item) :
        $cls = 'isf-fe-rail-item';
        if (!empty($item['active'])) $cls .= ' is-active';
        if (!empty($item['status'])) $cls .= ' isf-fe-status-' . sanitize_html_class($item['status']);
    ?>
        <a class="<?php echo esc_attr($cls); ?>" href="<?php echo esc_url($item['href'] ?? '#'); ?>">
            <span class="isf-fe-rail-label"><?php echo esc_html($item['label'] ?? ''); ?></span>
            <?php if (!empty($item['sublabel'])) : ?>
                <span class="isf-fe-rail-sublabel" style="display:block;font-size:11px;color:#646970"><?php echo esc_html($item['sublabel']); ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
    <?php if (!empty($rail_footer)) : ?>
        <div class="isf-fe-rail-footer" style="padding:12px 14px;border-top:1px solid #f0f0f1;font-size:11px;color:#646970">
            <?php echo $rail_footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — caller-built ?>
        </div>
    <?php endif; ?>
</aside>
```

- [ ] **Step 2: Write delivery.php**

`admin/views/form-editor/tasks/delivery.php`:
```php
<?php
/**
 * Delivery task — list (left) + per-destination editor (right).
 * Expects $instance, $instance_id, $mode, $router in scope.
 */
if (!defined('ABSPATH')) { exit; }

use ISF\FormEditor\ModeResolver;
use ISF\FormEditor\TaskValidator;

$destinations = $instance['settings']['destinations'] ?? [];
$selected_key = $router->sub_item();
if ($selected_key === '' && count($destinations) > 0) {
    $selected_key = '0';
}
$selected_idx = is_numeric($selected_key) ? (int) $selected_key : 0;
$selected = $destinations[$selected_idx] ?? null;

$registry = \ISF\Destinations\DestinationRegistry::instance();

// Build rail items
$rail_items = [];
foreach ($destinations as $idx => $d) {
    $d_status = empty($d['is_active']) ? TaskValidator::STATUS_DEFAULTS : (
        (empty($d['config']['host']) || empty($d['config']['username']))
            ? TaskValidator::STATUS_ATTENTION
            : TaskValidator::STATUS_OK
    );
    $rail_items[] = [
        'label'    => $d['name'] ?? ($d['type'] ?? 'destination'),
        'sublabel' => ($d['type'] ?? '') . ' · ' . (empty($d['is_active']) ? __('Paused', 'formflow') : __('Active', 'formflow')),
        'href'     => admin_url('admin.php?page=isf-form&id=' . (int) $instance_id . '&task=delivery&dest=' . $idx),
        'active'   => $idx === $selected_idx,
        'status'   => $d_status,
    ];
}

$rail_footer = '';
if (count($destinations) === 0) {
    $rail_footer = '<em>' . esc_html__('No destinations yet.', 'formflow') . '</em>';
}
?>
<div class="isf-fe-task-panel" data-task="delivery">
    <div class="isf-fe-task-two-pane">
        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sub-rail.php'; ?>

        <div class="isf-fe-detail">
            <?php if (!$selected) : ?>
                <h2><?php esc_html_e('No destination selected', 'formflow'); ?></h2>
                <p><?php esc_html_e('Add a destination from the list on the left, or import a form template that includes one.', 'formflow'); ?></p>
            <?php else :
                $type = $selected['type'] ?? '';
                $destination = $registry->get($type);
            ?>
                <h2><?php echo esc_html($selected['name'] ?? $type); ?></h2>
                <p class="description">
                    <?php echo esc_html($destination ? $destination->get_description() : __('Unknown destination type.', 'formflow')); ?>
                </p>

                <?php
                $missing = (empty($selected['config']['host']) || empty($selected['config']['username']));
                if ($missing) :
                    $strip_status = 'error';
                    $strip_message = __('Two required fields are empty. Fill in host and credentials, then test the connection before activating.', 'formflow');
                    require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/inline-status-strip.php';
                endif;
                ?>

                <?php
                // Render the existing destinations-pod for the selected destination.
                // We pass a one-item destinations array so the existing partial only renders one editor.
                $original_instance = $instance;
                $instance['settings']['destinations'] = [$selected];
                require ISF_PLUGIN_DIR . 'admin/views/partials/destinations-pod.php';
                $instance = $original_instance;
                ?>

                <?php
                $extra_buttons = [
                    '<button type="button" class="button button-secondary isf-destination-test" data-destination-index="' . esc_attr($selected_idx) . '">' .
                    '<span class="dashicons dashicons-admin-network"></span> ' . esc_html__('Test connection', 'formflow') .
                    '</button>',
                ];
                require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php';
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>
```

- [ ] **Step 3: Lint**

Run: `php -l admin/views/form-editor/partials/sub-rail.php admin/views/form-editor/tasks/delivery.php`
Expected: "No syntax errors detected"

- [ ] **Step 4: Commit**

```bash
git add admin/views/form-editor/partials/sub-rail.php admin/views/form-editor/tasks/delivery.php
git commit -m "feat(form-editor): Delivery task (two-pane, reuses destinations pod)"
```

---

## Task 16: Copy + Notifications tasks (simple single-pane ports)

**Files:**
- Create: `admin/views/form-editor/tasks/copy.php`
- Create: `admin/views/form-editor/tasks/notifications.php`

- [ ] **Step 1: Write copy.php**

`admin/views/form-editor/tasks/copy.php`:
```php
<?php
/**
 * Copy task — text content (title, description, buttons, T&Cs).
 * Expects $instance, $mode in scope.
 */
if (!defined('ABSPATH')) { exit; }
$content = $instance['settings']['content'] ?? [];
?>
<div class="isf-fe-task-panel" data-task="copy">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Copy & messages', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('Public-facing text on the form: title, description, buttons, success message, terms.', 'formflow'); ?></p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="content_form_title"><?php esc_html_e('Form title', 'formflow'); ?></label></th>
                <td><input type="text" id="content_form_title" name="settings[content][form_title]" class="large-text" data-fe-autosave value="<?php echo esc_attr($content['form_title'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="content_form_description"><?php esc_html_e('Form description', 'formflow'); ?></label></th>
                <td><textarea id="content_form_description" name="settings[content][form_description]" rows="3" class="large-text" data-fe-autosave><?php echo esc_textarea($content['form_description'] ?? ''); ?></textarea></td>
            </tr>
            <tr>
                <th scope="row"><label for="content_submit_label"><?php esc_html_e('Submit button label', 'formflow'); ?></label></th>
                <td><input type="text" id="content_submit_label" name="settings[content][button_labels][submit]" class="regular-text" data-fe-autosave value="<?php echo esc_attr($content['button_labels']['submit'] ?? __('Submit', 'formflow')); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="content_success_message"><?php esc_html_e('Success message', 'formflow'); ?></label></th>
                <td><textarea id="content_success_message" name="settings[content][success_message]" rows="3" class="large-text" data-fe-autosave><?php echo esc_textarea($content['success_message'] ?? ''); ?></textarea></td>
            </tr>
        </table>

        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php'; ?>
    </div>
</div>
```

- [ ] **Step 2: Write notifications.php**

`admin/views/form-editor/tasks/notifications.php`:
```php
<?php
/**
 * Notifications task — confirmation email + team alerts.
 * Expects $instance, $mode in scope.
 */
if (!defined('ABSPATH')) { exit; }
$email = $instance['settings']['email'] ?? [];
?>
<div class="isf-fe-task-panel" data-task="notifications">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Notifications', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('Confirmation emails to submitters and notifications to your team.', 'formflow'); ?></p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="email_send_confirmation"><?php esc_html_e('Send confirmation email', 'formflow'); ?></label></th>
                <td><label><input type="checkbox" id="email_send_confirmation" name="settings[email][send_confirmation]" value="1" data-fe-autosave <?php checked(!empty($email['send_confirmation'])); ?>> <?php esc_html_e('Send a confirmation to the submitter', 'formflow'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><label for="email_from_name"><?php esc_html_e('From name', 'formflow'); ?></label></th>
                <td><input type="text" id="email_from_name" name="settings[email][from_name]" class="regular-text" data-fe-autosave value="<?php echo esc_attr($email['from_name'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="email_from_address"><?php esc_html_e('From address', 'formflow'); ?></label></th>
                <td><input type="email" id="email_from_address" name="settings[email][from_address]" class="regular-text" data-fe-autosave value="<?php echo esc_attr($email['from_address'] ?? ''); ?>"></td>
            </tr>
        </table>

        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php'; ?>
    </div>
</div>
```

- [ ] **Step 3: Lint + commit**

Run: `php -l admin/views/form-editor/tasks/copy.php admin/views/form-editor/tasks/notifications.php`
Expected: "No syntax errors detected"

```bash
git add admin/views/form-editor/tasks/copy.php admin/views/form-editor/tasks/notifications.php
git commit -m "feat(form-editor): Copy + Notifications tasks (single-pane)"
```

---

## Task 17: Form Fields task (composes existing form-builder.js)

**Files:**
- Create: `admin/views/form-editor/tasks/fields.php`

- [ ] **Step 1: Write the view**

`admin/views/form-editor/tasks/fields.php`:
```php
<?php
/**
 * Form Fields task — reuses the existing form-builder.js drag-reorder
 * + property panel inside the new editor's two-pane chrome.
 *
 * Expects $instance, $instance_id in scope.
 */
if (!defined('ABSPATH')) { exit; }

$schema = $instance['settings']['form_schema'] ?? ['version' => '1.0', 'steps' => []];
$schema_json = wp_json_encode($schema);
?>
<div class="isf-fe-task-panel" data-task="fields">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Form fields', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('Drag to reorder; click a field to edit its properties.', 'formflow'); ?></p>

        <!-- Reuse existing form-builder container -->
        <div id="isf-form-builder"
             data-instance-id="<?php echo esc_attr($instance_id); ?>"
             data-schema='<?php echo esc_attr($schema_json); ?>'>
            <noscript><?php esc_html_e('Form builder requires JavaScript.', 'formflow'); ?></noscript>
        </div>

        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php'; ?>
    </div>
</div>

<?php
// The existing form-builder.js bootstraps itself against #isf-form-builder.
// Enqueue it explicitly for this task screen:
wp_enqueue_script('isf-form-builder');
wp_enqueue_style('isf-form-builder');
?>
```

- [ ] **Step 2: Lint + commit**

Run: `php -l admin/views/form-editor/tasks/fields.php`
Expected: "No syntax errors detected"

```bash
git add admin/views/form-editor/tasks/fields.php
git commit -m "feat(form-editor): Form fields task — composes existing form-builder.js"
```

---

## Task 18: Connector / Scheduling / Tracking / Advanced (placeholder ports)

**Files:**
- Create: `admin/views/form-editor/tasks/connector.php`
- Create: `admin/views/form-editor/tasks/scheduling.php`
- Create: `admin/views/form-editor/tasks/tracking.php`
- Create: `admin/views/form-editor/tasks/advanced.php`

For each of these tasks, the v3.0 deliverable is a placeholder that surfaces the existing pod fields under the new chrome. Full per-task UI polish is iterative across 3.0.x patch releases.

- [ ] **Step 1: Write connector.php**

`admin/views/form-editor/tasks/connector.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }
?>
<div class="isf-fe-task-panel" data-task="connector">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Connector', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('API endpoint and credentials for the IntelliSOURCE enrollment connector.', 'formflow'); ?></p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="api_endpoint"><?php esc_html_e('API endpoint', 'formflow'); ?></label></th>
                <td><input type="url" id="api_endpoint" name="api_endpoint" class="large-text" data-fe-autosave value="<?php echo esc_attr($instance['api_endpoint'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="api_password"><?php esc_html_e('API password', 'formflow'); ?></label></th>
                <td><input type="password" id="api_password" name="api_password" class="regular-text" data-fe-autosave value="" autocomplete="new-password" placeholder="<?php esc_attr_e('(saved — leave blank to keep)', 'formflow'); ?>"></td>
            </tr>
        </table>

        <?php
        $extra_buttons = [
            '<button type="button" class="button" id="isf-test-api"><span class="dashicons dashicons-admin-network"></span> ' . esc_html__('Test connection', 'formflow') . '</button>'
        ];
        require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php';
        ?>
    </div>
</div>
```

- [ ] **Step 2: Write scheduling.php**

`admin/views/form-editor/tasks/scheduling.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }
$scheduling = $instance['settings']['scheduling'] ?? [];
?>
<div class="isf-fe-task-panel" data-task="scheduling">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Scheduling', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('Available slots, blocked dates, capacity limits, maintenance windows.', 'formflow'); ?></p>

        <p>
            <em><?php esc_html_e('The scheduling editor is being rebuilt — for now, use the legacy editor at:', 'formflow'); ?></em><br>
            <a href="<?php echo esc_url(admin_url('admin.php?page=isf-instance-editor&id=' . (int) $instance_id)); ?>"><?php esc_html_e('Open legacy instance editor', 'formflow'); ?></a>
        </p>

        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php'; ?>
    </div>
</div>
```

- [ ] **Step 3: Write tracking.php**

`admin/views/form-editor/tasks/tracking.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }
$gtm = $instance['settings']['gtm'] ?? [];
$analytics = $instance['settings']['analytics'] ?? [];
?>
<div class="isf-fe-task-panel" data-task="tracking">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Tracking', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('UTM, GA4, attribution, fraud detection.', 'formflow'); ?></p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="gtm_enabled"><?php esc_html_e('Google Tag Manager', 'formflow'); ?></label></th>
                <td>
                    <label><input type="checkbox" id="gtm_enabled" name="settings[gtm][enabled]" value="1" data-fe-autosave <?php checked(!empty($gtm['enabled'])); ?>> <?php esc_html_e('Enable GTM/GA4 instrumentation', 'formflow'); ?></label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gtm_container_id"><?php esc_html_e('GTM container id', 'formflow'); ?></label></th>
                <td><input type="text" id="gtm_container_id" name="settings[gtm][container_id]" class="regular-text" data-fe-autosave value="<?php echo esc_attr($gtm['container_id'] ?? ''); ?>" placeholder="GTM-XXXXXXX"></td>
            </tr>
            <tr>
                <th scope="row"><label for="ga4_measurement_id"><?php esc_html_e('GA4 measurement id', 'formflow'); ?></label></th>
                <td><input type="text" id="ga4_measurement_id" name="settings[gtm][ga4_measurement_id]" class="regular-text" data-fe-autosave value="<?php echo esc_attr($gtm['ga4_measurement_id'] ?? ''); ?>" placeholder="G-XXXXXXXXXX"></td>
            </tr>
        </table>

        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php'; ?>
    </div>
</div>
```

- [ ] **Step 4: Write advanced.php**

`admin/views/form-editor/tasks/advanced.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }
?>
<div class="isf-fe-task-panel" data-task="advanced">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Advanced', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('Features, modes, debug, raw settings.', 'formflow'); ?></p>

        <h3><?php esc_html_e('Mode flags', 'formflow'); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="test_mode"><?php esc_html_e('Test Mode', 'formflow'); ?></label></th>
                <td><label><input type="checkbox" id="test_mode" name="test_mode" value="1" data-fe-autosave <?php checked(!empty($instance['test_mode'])); ?>> <?php esc_html_e('Mark submissions as test', 'formflow'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><label for="demo_mode"><?php esc_html_e('Demo Mode', 'formflow'); ?> <span style="color:#d63638"><?php esc_html_e('(dangerous)', 'formflow'); ?></span></label></th>
                <td><label><input type="checkbox" id="demo_mode" name="demo_mode" value="1" data-fe-autosave <?php checked(!empty($instance['settings']['demo_mode'])); ?>> <?php esc_html_e('Return mock data — no real enrollment. NEVER enable on a live form.', 'formflow'); ?></label></td>
            </tr>
        </table>

        <h3><?php esc_html_e('Legacy editor', 'formflow'); ?></h3>
        <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=isf-instance-editor&id=' . (int) $instance_id . '&bypass=1')); ?>"><?php esc_html_e('Open legacy editor (for advanced settings not yet ported)', 'formflow'); ?></a></p>

        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php'; ?>
    </div>
</div>
```

- [ ] **Step 5: Make the legacy redirect honor `bypass=1`**

In `includes/class-plugin.php` `redirect_old_editor_when_flag_on()`, add an early return:
```php
        if (isset($_GET['bypass']) && $_GET['bypass'] === '1') {
            return;
        }
```

- [ ] **Step 6: Lint + commit**

Run: `php -l admin/views/form-editor/tasks/connector.php admin/views/form-editor/tasks/scheduling.php admin/views/form-editor/tasks/tracking.php admin/views/form-editor/tasks/advanced.php includes/class-plugin.php`
Expected: "No syntax errors detected"

```bash
git add admin/views/form-editor/tasks/connector.php admin/views/form-editor/tasks/scheduling.php admin/views/form-editor/tasks/tracking.php admin/views/form-editor/tasks/advanced.php includes/class-plugin.php
git commit -m "feat(form-editor): Connector + Scheduling + Tracking + Advanced (placeholder ports, bypass to legacy)"
```

---

## Task 19: Submissions task (client-mode, read-only)

**Files:**
- Create: `admin/views/form-editor/tasks/submissions.php`

- [ ] **Step 1: Write the view**

`admin/views/form-editor/tasks/submissions.php`:
```php
<?php
/**
 * Submissions task — client-mode read-only summary.
 * Expects $instance, $instance_id in scope.
 */
if (!defined('ABSPATH')) { exit; }

global $wpdb;
$submissions_table = $wpdb->prefix . 'isf_submissions';
$deliveries_table  = $wpdb->prefix . 'isf_deliveries';

$total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$submissions_table} WHERE instance_id = %d", $instance_id));
$failed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$deliveries_table} WHERE instance_id = %d AND status = 'failed'", $instance_id));
$recent = $wpdb->get_results($wpdb->prepare(
    "SELECT id, created_at, status FROM {$submissions_table} WHERE instance_id = %d ORDER BY id DESC LIMIT 10",
    $instance_id
));
?>
<div class="isf-fe-task-panel" data-task="submissions">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Submissions', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('Recent submissions and delivery status. Read-only — to manage submissions, ask your administrator.', 'formflow'); ?></p>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:20px 0">
            <div class="isf-fe-task-card">
                <div class="isf-fe-task-card-head"><h3><?php echo esc_html(number_format($total)); ?></h3></div>
                <p class="isf-fe-task-card-desc"><?php esc_html_e('Total submissions', 'formflow'); ?></p>
            </div>
            <div class="isf-fe-task-card <?php echo $failed > 0 ? 'isf-fe-status-attention' : ''; ?>">
                <div class="isf-fe-task-card-head"><h3><?php echo esc_html(number_format($failed)); ?></h3></div>
                <p class="isf-fe-task-card-desc"><?php esc_html_e('Failed deliveries', 'formflow'); ?></p>
            </div>
        </div>

        <h3><?php esc_html_e('Recent submissions', 'formflow'); ?></h3>
        <?php if (!$recent) : ?>
            <p><em><?php esc_html_e('No submissions yet.', 'formflow'); ?></em></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th><?php esc_html_e('Submitted', 'formflow'); ?></th><th><?php esc_html_e('Status', 'formflow'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($recent as $row) : ?>
                        <tr>
                            <td><code><?php echo esc_html($row->id); ?></code></td>
                            <td><?php echo esc_html($row->created_at); ?></td>
                            <td><?php echo esc_html($row->status ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
```

- [ ] **Step 2: Lint + commit**

Run: `php -l admin/views/form-editor/tasks/submissions.php`
Expected: "No syntax errors detected"

```bash
git add admin/views/form-editor/tasks/submissions.php
git commit -m "feat(form-editor): Submissions task — client-mode read-only summary"
```

---

## Task 20: Tools page — new-editor toggle UI

**Files:**
- Create: `admin/views/tabs/tools-new-editor.php`
- Modify: `admin/views/tabs/tools-settings.php` (link to new tab)
- Modify: `includes/class-plugin.php` (AJAX handler `formflow_set_new_editor_flag`)

- [ ] **Step 1: Write the toggle UI**

`admin/views/tabs/tools-new-editor.php`:
```php
<?php
/**
 * Tools → New Editor (Beta) — toggle UI for the ISF_NEW_EDITOR option.
 * If the constant is defined in wp-config.php, show that takes precedence
 * and the UI is informational only.
 */
if (!defined('ABSPATH')) { exit; }

use ISF\FormEditor\FeatureFlag;

$constant_defined = defined(FeatureFlag::CONSTANT);
$current = FeatureFlag::is_enabled();
?>
<div class="isf-tools-section">
    <h2><?php esc_html_e('New Form Editor (Beta)', 'formflow'); ?></h2>
    <p class="description">
        <?php esc_html_e('The new task-oriented editor lives alongside the classic editor. Both read/write the same form data — toggle on to try it, toggle off for the old editor.', 'formflow'); ?>
    </p>

    <?php if ($constant_defined) : ?>
        <div class="notice notice-info inline">
            <p>
                <strong><?php esc_html_e('Constant override active.', 'formflow'); ?></strong>
                <?php
                printf(
                    /* translators: %1$s: constant name, %2$s: bool */
                    esc_html__('%1$s is set in wp-config.php to %2$s. Edit wp-config.php to change.', 'formflow'),
                    '<code>' . esc_html(FeatureFlag::CONSTANT) . '</code>',
                    '<code>' . ($current ? 'true' : 'false') . '</code>'
                );
                ?>
            </p>
        </div>
    <?php else : ?>
        <label style="display:inline-flex;align-items:center;gap:8px">
            <input type="checkbox" id="isf-new-editor-toggle" <?php checked($current); ?>>
            <span><?php esc_html_e('Use the new form editor for everyone on this site', 'formflow'); ?></span>
        </label>
        <p class="description"><?php esc_html_e('Affects all admins immediately. The old editor stays mounted as a fallback and can be reached via Advanced → Open legacy editor.', 'formflow'); ?></p>
        <script>
        jQuery(function ($) {
            $('#isf-new-editor-toggle').on('change', function () {
                var enabled = $(this).is(':checked') ? '1' : '0';
                $.post(ajaxurl, {
                    action: 'formflow_set_new_editor_flag',
                    nonce: '<?php echo esc_js(wp_create_nonce('isf_admin_nonce')); ?>',
                    value: enabled
                }, function () { window.location.reload(); });
            });
        });
        </script>
    <?php endif; ?>
</div>
```

- [ ] **Step 2: Add AJAX handler**

In `includes/class-plugin.php` `define_admin_hooks()` add:

```php
        add_action('wp_ajax_formflow_set_new_editor_flag', [$this, 'ajax_set_new_editor_flag']);
```

And the method:

```php
    public function ajax_set_new_editor_flag(): void {
        if (!\ISF\Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }
        $value = ($_POST['value'] ?? '0') === '1' ? '1' : '0';
        update_option(\ISF\FormEditor\FeatureFlag::OPTION, $value);
        wp_send_json_success(['value' => $value]);
    }
```

- [ ] **Step 3: Wire the new tab into the existing Tools page**

In `admin/views/tools.php` (or wherever Tools tabs are rendered), find the tab list and add a "New Editor" tab linking to `?page=isf-tools&tab=new-editor`. If `$active_tab === 'new-editor'`, include the new partial:

```php
        case 'new-editor':
            include ISF_PLUGIN_DIR . 'admin/views/tabs/tools-new-editor.php';
            break;
```

- [ ] **Step 4: Lint + commit**

Run: `php -l admin/views/tabs/tools-new-editor.php includes/class-plugin.php`
Expected: "No syntax errors detected"

```bash
git add admin/views/tabs/tools-new-editor.php includes/class-plugin.php admin/views/tools.php
git commit -m "feat(form-editor): Tools → New Editor toggle UI (option-backed flag)"
```

---

## Task 21: Demote admin notices on the new editor screen

**Files:**
- Modify: `includes/class-plugin.php` (extend `display_admin_notices` gate)
- Modify: `includes/class-queue-manager.php` (extend `show_as_notice` gate)

- [ ] **Step 1: Extend the gate in class-plugin.php**

In `includes/class-plugin.php` `display_admin_notices()`, find the existing gate:

```php
        if (strpos($screen->id ?? '', 'isf-instance-editor') !== false) {
            return;
        }
```

Replace with:

```php
        if (strpos($screen->id ?? '', 'isf-instance-editor') !== false
            || strpos($screen->id ?? '', 'isf-form') !== false) {
            return;
        }
```

- [ ] **Step 2: Extend the gate in class-queue-manager.php**

Same replacement in `includes/class-queue-manager.php::show_as_notice()`.

- [ ] **Step 3: Lint + commit**

Run: `php -l includes/class-plugin.php includes/class-queue-manager.php`
Expected: "No syntax errors detected"

```bash
git add includes/class-plugin.php includes/class-queue-manager.php
git commit -m "feat(form-editor): suppress global admin notices on isf-form screens too"
```

---

## Task 22: End-to-end smoke pass

This task is manual verification. The plan documents what to test; no code changes.

- [ ] **Step 1: Install plan**

```bash
# In the worktree:
composer install --no-dev --optimize-autoloader
```

- [ ] **Step 2: Build the zip + install on a test WP site**

```bash
# Adapt the existing zip recipe used in 2.9.x:
rm -rf /tmp/formflow-stage && mkdir -p /tmp/formflow-stage
rsync -a \
  --exclude='.git' --exclude='.github' --exclude='.claude' \
  --exclude='node_modules' --exclude='tests' --exclude='logs' \
  --exclude='.DS_Store' --exclude='.phpunit*' --exclude='phpunit.xml' \
  --exclude='formflow/' --exclude='*.bak' \
  ./ /tmp/formflow-stage/FORMFLOW/
( cd /tmp/formflow-stage && zip -rq ~/Desktop/formflow-3.0.0.zip FORMFLOW )
```

- [ ] **Step 3: Smoke checklist**

Verify each (on a real WP install — Docker or a sandbox):
- [ ] Fresh install with flag OFF — old editor unchanged
- [ ] Toggle flag ON via Tools → New Editor — FF Forms → Form Editor (Beta) appears
- [ ] Open a form in the new editor — overview renders with 9 task cards
- [ ] Status badges reflect reality: missing destination creds → red ⚠ on Delivery card
- [ ] Click Delivery → two-pane editor renders with destination list + detail
- [ ] Edit a non-sensitive field (e.g., from_name in Notifications) → "Saved Xs ago" appears in sticky bar
- [ ] Test Connection button on a destination — inline strip shows result
- [ ] Mode switcher: switch to Client → only 4 tasks visible
- [ ] As a user without `isf_dev_mode` cap (create a second admin, revoke via wp-cli) → only 4 tasks visible, no switcher
- [ ] Open form_type=custom form — Connector + Scheduling cards greyed out
- [ ] Open form_type=enrollment form — all 9 cards active
- [ ] Old editor URL still reachable when flag ON via Advanced → bypass link
- [ ] Flag OFF — Form Editor menu disappears; old editor URL works normally
- [ ] Browser back/forward through tasks works (URLs are bookmarkable)
- [ ] Keyboard nav: Tab through task cards, Enter to enter, all focus rings visible

- [ ] **Step 4: Commit nothing — just confirm checklist passes**

If any item fails, fix inline and re-run that item. No commit needed for smoke pass itself.

---

## Task 23: CHANGELOG entry + version bump 3.0.0

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `formflow.php` (Version header + ISF_VERSION constant)
- Modify: `formflow/formflow.php` (same)

- [ ] **Step 1: Bump version**

```bash
# Sed safely — there's only one "Version:" line in each file
sed -i.bak 's/Version: 2\.9\.[0-9]*/Version: 3.0.0/' formflow.php formflow/formflow.php
sed -i.bak "s/define('ISF_VERSION', '2\\.9\\.[0-9]*')/define('ISF_VERSION', '3.0.0')/" formflow.php formflow/formflow.php
rm -f formflow.php.bak formflow/formflow.php.bak
grep -n "Version:\|ISF_VERSION" formflow.php | head -2
```

Expected output: `Version: 3.0.0` and `define('ISF_VERSION', '3.0.0');`

- [ ] **Step 2: Add CHANGELOG entry**

Prepend to `CHANGELOG.md` above the existing `## 2.9.x` entries:

```markdown
## 3.0.0 — 2026-MM-DD

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
```

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md formflow.php formflow/formflow.php
git commit -m "chore(release): 3.0.0 — task-oriented form editor + feature flag + capability mode"
```

- [ ] **Step 4: Push branch**

```bash
git push
```

---

## Task 24: Run full PHPUnit suite

- [ ] **Step 1: Run unit tests**

```bash
vendor/bin/phpunit --testsuite=unit
```

Expected: all green. If any pre-existing test fails, investigate before shipping (the redesign should not have broken anything).

- [ ] **Step 2: Run regression suite (if it exists / passes locally before this work)**

```bash
vendor/bin/phpunit --testsuite=regression
```

If regression suite is fragile / known-broken on this branch, document that fact in the CHANGELOG instead of fixing it in this plan.

- [ ] **Step 3: Commit any test-only fixes**

If any pre-existing tests broke due to ISF_VERSION bump or new file references, fix and commit separately:

```bash
git add tests/...
git commit -m "test: update fixtures for 3.0.0"
```

---

## Self-review notes

- ✅ Spec §3 (User types & capabilities) — implemented in Tasks 2, 3 (Capabilities + ModeResolver).
- ✅ Spec §4.1 (Routes) — implemented in Task 6 (Router) + Task 8 (menu registration).
- ✅ Spec §4.2 (Task list) — Task 4 (TaskRegistry).
- ✅ Spec §4.3 (Status indicators) — Task 5 (TaskValidator) + Task 10 (task-card rendering).
- ✅ Spec §4.4 (Two-pane editor pattern) — Task 11 (CSS) + Task 15 (Delivery task as canonical example) + Task 13 (partials).
- ✅ Spec §4.5 (Inline issue surfacing) — Task 13 (strip partial) + Task 21 (notice gate).
- ✅ Spec §4.6 (Auto-save + sticky bar) — Task 12 (JS) + Task 13 (sticky bar partial).
- ✅ Spec §4.7 (Mode switcher) — Task 9 (layout) + Task 12 (JS handler).
- ✅ Spec §5 (Feature flag + cutover) — Task 1 (resolver) + Task 8 (gating) + Task 20 (Tools UI).
- ✅ Spec §6 (File layout) — every created file appears in a task.
- ✅ Spec §8 (Backward compatibility) — Task 12 (FieldGate composes the existing save handler; no schema changes).
- ✅ Spec §12 (Validation smoke tests) — Task 22.
- Open question 1 (Form Fields task UX with 30+ fields) — Task 17 composes existing `form-builder.js`; if scaling issues emerge during Task 22 smoke, addressed in 3.0.1.
- Open question 2 (concurrent edit conflict) — explicitly deferred to 3.1 per spec §10.
- Open question 3 (mobile fallback) — Task 11 CSS includes `@media (max-width: 700px)` collapse to single-pane.
- Open question 4 ("Editing as" switcher visual) — Task 9 implements the dropdown variant; toggle-pill explicitly cut.

---

**Plan complete and saved to `docs/superpowers/plans/2026-05-27-form-editor-redesign.md`.**
