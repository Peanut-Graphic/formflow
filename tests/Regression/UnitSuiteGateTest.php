<?php

use PHPUnit\Framework\TestCase;

final class UnitSuiteGateTest extends TestCase
{
    public function test_unit_suite_is_blocking_in_ci(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/tests.yml');

        $this->assertIsString($workflow);
        $this->assertMatchesRegularExpression(
            '/- name: Unit tests\s+run: vendor\/bin\/phpunit --testsuite=unit/',
            $workflow
        );
        $this->assertDoesNotMatchRegularExpression(
            '/- name: Unit tests[^\n]*\n\s+continue-on-error:\s*true/',
            $workflow
        );
    }
}
