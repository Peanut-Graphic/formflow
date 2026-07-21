<?php
/**
 * WifiSubmissionPersistenceTest — the WiFi answer and the conversion flag have
 * to reach their own columns.
 *
 * They cannot ride along inside form_data: that column is encrypted at rest,
 * so anything stored there is invisible to the reporting queries that justify
 * the feature ("how many people did the gate catch, and how many did it
 * save?"). These tests pin the write path to the real columns.
 *
 * @package FormFlow
 */

namespace ISF\Tests\Unit\Database;

use ISF\Tests\Unit\TestCase;
use ISF\Database\Database;

final class WifiSubmissionPersistenceTest extends TestCase
{
    /**
     * Capture whatever create_submission() hands to $wpdb->insert().
     */
    private function captureInsert(array $data): array
    {
        $captured = [];

        $wpdb = $this->mockWpdb([
            'insert' => function ($table, $insert_data) use (&$captured) {
                $captured = $insert_data;
                return 1;
            },
        ]);
        $wpdb->insert_id = 42;

        (new Database())->create_submission($data);

        return $captured;
    }

    /**
     * Capture whatever update_submission() hands to $wpdb->update().
     */
    private function captureUpdate(array $data): array
    {
        $captured = [];

        $this->mockWpdb([
            'update' => function ($table, $update_data) use (&$captured) {
                $captured = $update_data;
                return 1;
            },
        ]);

        (new Database())->update_submission(42, $data);

        return $captured;
    }

    public function test_create_submission_persists_the_wifi_answer(): void
    {
        $insert = $this->captureInsert([
            'instance_id' => 1,
            'session_id'  => 'abc',
            'device_type' => 'thermostat',
            'has_wifi'    => 'yes',
        ]);

        $this->assertArrayHasKey('has_wifi', $insert, 'has_wifi never reaches the insert.');
        $this->assertSame('yes', $insert['has_wifi']);
    }

    public function test_create_submission_persists_the_conversion_flag(): void
    {
        $insert = $this->captureInsert([
            'instance_id'      => 1,
            'session_id'       => 'abc',
            'device_type'      => 'dcu',
            'has_wifi'         => 'no',
            'device_converted' => 1,
        ]);

        $this->assertArrayHasKey('device_converted', $insert);
        $this->assertSame(1, $insert['device_converted']);
    }

    /**
     * NULL is load-bearing: it is how "never asked" (a switch-first enrollment,
     * or a utility that never enabled the gate) stays distinguishable from a
     * customer who actually answered.
     */
    public function test_unanswered_wifi_persists_as_null_not_empty_string(): void
    {
        $insert = $this->captureInsert([
            'instance_id' => 1,
            'session_id'  => 'abc',
            'device_type' => 'dcu',
        ]);

        $this->assertArrayHasKey('has_wifi', $insert);
        $this->assertNull($insert['has_wifi'], '"Never asked" must be NULL, not an empty string.');
    }

    /**
     * An unconverted enrollment must read as 0, not NULL — otherwise the
     * conversion count is computed over a mix of 0 and NULL and understates.
     */
    public function test_conversion_flag_defaults_to_zero(): void
    {
        $insert = $this->captureInsert([
            'instance_id' => 1,
            'session_id'  => 'abc',
            'device_type' => 'thermostat',
            'has_wifi'    => 'yes',
        ]);

        $this->assertSame(0, $insert['device_converted']);
    }

    /**
     * The conversion happens on Step 1 but the submission row may already
     * exist, so the update path has to carry both fields too.
     */
    public function test_update_submission_persists_wifi_answer_and_conversion(): void
    {
        $update = $this->captureUpdate([
            'device_type'      => 'dcu',
            'has_wifi'         => 'no',
            'device_converted' => 1,
        ]);

        $this->assertSame('no', $update['has_wifi'] ?? null, 'has_wifi never reaches the update.');
        $this->assertSame(1, $update['device_converted'] ?? null, 'device_converted never reaches the update.');
    }

    /**
     * update_submission() is a partial update — untouched fields must stay
     * untouched rather than being clobbered with defaults.
     */
    public function test_update_submission_leaves_wifi_fields_alone_when_not_supplied(): void
    {
        $update = $this->captureUpdate(['status' => 'completed']);

        $this->assertArrayNotHasKey('has_wifi', $update);
        $this->assertArrayNotHasKey('device_converted', $update);
    }

    /**
     * Database::update_submission can carry the fields, but the completion
     * handler has to actually hand them over. Miss this and both columns stay
     * at their defaults on every completed enrollment — the write path works
     * perfectly and never runs.
     *
     * Source-contract guard, in the style of AjaxSubmitBuilderFormTest: this
     * repo's AJAX layer is not directly invocable under the stub bootstrap.
     */
    public function test_completion_handler_writes_the_wifi_columns(): void
    {
        $source = (string) file_get_contents(
            ISF_PLUGIN_DIR . 'public/traits/trait-ajax-handlers.php'
        );

        $found = preg_match(
            "/'status'\s*=>\s*'completed'.*?\]\s*\)/s",
            $source,
            $m
        );
        $this->assertSame(1, $found, 'Could not locate the completion update.');

        // Search backwards from the completion marker to the start of its array.
        $offset = strpos($source, $m[0]);
        $window = substr($source, max(0, $offset - 600), 600 + strlen($m[0]));

        $this->assertStringContainsString(
            "'has_wifi'",
            $window,
            'The completed-enrollment write omits has_wifi, so the column stays NULL '
            . 'on every real enrollment.'
        );
        $this->assertStringContainsString(
            "'device_converted'",
            $window,
            'The completed-enrollment write omits device_converted, so every '
            . 'gate-driven conversion is recorded as 0.'
        );
    }
}
