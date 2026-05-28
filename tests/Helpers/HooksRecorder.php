<?php
/**
 * HooksRecorder — captures hook fire calls so tests can assert
 * that handlers fire with the right signatures.
 *
 * Usage: in setUp, call HooksRecorder::install() to mock do_action
 * + apply_filters. Then in your test, assert on
 * HooksRecorder::calls('isf_form_completed') to inspect what fired.
 */

namespace ISF\Tests\Helpers;

use Brain\Monkey\Functions;

final class HooksRecorder
{
    /** @var array<string, array<int, array>> */
    public static array $fired = [];

    public static function install(): void
    {
        self::$fired = [];

        Functions\when('do_action')->alias(function (string $hook, ...$args) {
            self::$fired[$hook][] = $args;
        });

        Functions\when('apply_filters')->alias(function (string $hook, $value, ...$args) {
            self::$fired[$hook][] = array_merge([$value], $args);
            return $value;
        });
    }

    public static function calls(string $hook): array
    {
        return self::$fired[$hook] ?? [];
    }

    public static function fired(string $hook): bool
    {
        return !empty(self::$fired[$hook]);
    }

    public static function reset(): void
    {
        self::$fired = [];
    }
}
